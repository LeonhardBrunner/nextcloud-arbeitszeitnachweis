<?php

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Db\UserConfigMapper;
use OCA\Timesheet\Service\HrService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class HrNotificationService {
	private const DEFAULT_NO_ENTRY_DAYS = 14;
	private const DEFAULT_OVERTIME_THRESHOLD_MIN = 600; // 10h
	private const DEFAULT_NEGATIVE_THRESHOLD_MIN = 600; // 10h
	private const DEFAULT_WORK_MINUTES = 480; // 8h

	private array $overtimeCache = [];
	private array $dailyMinCache = [];

	public function __construct(
		private EntryMapper $entryMapper,
		private UserConfigMapper $userConfigMapper,
		private HrService $hrService,
		private MonthlyBalanceService $monthlyBalanceService,
		private EmploymentStatusStore $employmentStatusStore,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private IUserManager $userManager,
	) {
	}

	public function doCron(): array {
		$notificationsByHrUser = [];

		$hrUserIds = $this->hrService->getMailRecipientUserIds();
		if (!$hrUserIds) {
			return $notificationsByHrUser;
		}

		$today = $this->timeFactory->getDateTime('today', new \DateTimeZone('Europe/Berlin'));

		foreach ($hrUserIds as $hrUserId) {
			// HR recipients use account enablement, independently of employee activity groups.
			try {
				$hrUser = $this->userManager->get($hrUserId);
				if (!$hrUser || !$hrUser->isEnabled()) {
					continue;
				}
			} catch (\Throwable $recipientError) {
				$this->logger->error('HR warnings skipped because the recipient could not be loaded.', ['userId' => $hrUserId, 'exception' => $recipientError]);
				continue;
			}
			$hrConfig = null;
			try {
				$hrConfig = $this->userConfigMapper->findByUser($hrUserId);
			} catch (DoesNotExistException) {
				// Keep defaults when no user configuration exists.
			}

			$noEntryEnabled = (bool)($hrConfig?->getMailNoEntryEnabled() ?? false);
			$overtimeEnabled = (bool)($hrConfig?->getMailOvertimeEnabled() ?? false);
			$negativeEnabled = (bool)($hrConfig?->getMailNegativeEnabled() ?? false);

			if (!$noEntryEnabled && !$overtimeEnabled && !$negativeEnabled) {
				continue;
			}

			$noEntryDays = (int)($hrConfig?->getMailNoEntryDays() ?? self::DEFAULT_NO_ENTRY_DAYS);
			$noEntryDays = max(1, min(365, $noEntryDays));

			$overtimeThresholdMin = (int)($hrConfig?->getMailOvertimeThresholdMin() ?? self::DEFAULT_OVERTIME_THRESHOLD_MIN);
			$overtimeThresholdMin = max(0, $overtimeThresholdMin);

			$negativeThresholdMin = (int)($hrConfig?->getMailNegativeThresholdMin() ?? self::DEFAULT_NEGATIVE_THRESHOLD_MIN);
			$negativeThresholdMin = max(0, $negativeThresholdMin);

			$hrNotifications = [
				'noEntry' => [],
				'noEntryDays' => $noEntryDays,

				'overtime' => [],
				'negative' => [],
			];

			$accessibleUsers = $this->hrService->getMailAccessibleUsers($hrUserId);
			if (!$accessibleUsers) {
				continue;
			}

			foreach ($accessibleUsers as $userInfo) {
				$userId = (string)($userInfo['id'] ?? '');
				if ($userId === '') {
					continue;
				}
				$employmentStatus = $this->loadEmploymentStatus($userId);
				if ($employmentStatus === null || !$employmentStatus->isActive) {
					continue;
				}

				$displayName = (string)($userInfo['name'] ?? $userId);

				if (!array_key_exists($userId, $this->overtimeCache)) {
					$this->overtimeCache[$userId] = $this->monthlyBalanceService->calculateCurrentBalance($userId);
				}
				$balanceSummary = $this->overtimeCache[$userId];

				$lastEntryDate = ($balanceSummary && isset($balanceSummary['lastEntryDate']))
					? (string)$balanceSummary['lastEntryDate']
					: null;

				// The older aggregate reminder starts a fresh full delay after an exemption.
				$referenceDate = $employmentStatus->getMissingEntryReferenceDate($lastEntryDate);
				if ($referenceDate === null && $balanceSummary && isset($balanceSummary['to'])) {
					// Preserve the old range fallback only when no actual entry or return exists.
					$referenceDate = $employmentStatus->getMissingEntryReferenceDate((string)$balanceSummary['to']);
				}
				$daysSinceLastEntry = null;
				if ($referenceDate !== null) {
					$reference = \DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate, new \DateTimeZone('Europe/Berlin'));
					$daysSinceLastEntry = $reference > $today ? 0 : (int)$reference->diff($today)->format('%a');
				}

				if ($noEntryEnabled && !$employmentStatus->freelancerMode) {
					if ($referenceDate === null || ($daysSinceLastEntry !== null && $daysSinceLastEntry >= $noEntryDays)) {
						$hrNotifications['noEntry'][] = [
							'user' => $displayName,
							'days' => $daysSinceLastEntry,
						];
					}
				}

				if (!$balanceSummary) {
					continue;
				}

				$overtimeMinutes = (int)($balanceSummary['overtimeMinutes'] ?? 0);

				if ($overtimeEnabled && $overtimeMinutes > $overtimeThresholdMin) {
					$hrNotifications['overtime'][] = [
						'user' => $displayName,
						'overtime' => $this->formatHours($overtimeMinutes, true),
					];
				}

				if ($negativeEnabled && $overtimeMinutes < -$negativeThresholdMin) {
					$hrNotifications['negative'][] = [
						'user' => $displayName,
						'deficit' => $this->formatHours($overtimeMinutes, true),
					];
				}
			}

			if (!empty($hrNotifications['noEntry']) || !empty($hrNotifications['overtime']) || !empty($hrNotifications['negative'])) {
				$notificationsByHrUser[$hrUserId] = $hrNotifications;
			}
		}

		return $notificationsByHrUser;
	}

	private function loadEmploymentStatus(string $userId): ?EmploymentStatus {
		try {
			return $this->employmentStatusStore->getStatus($userId);
		} catch (\Throwable $loadError) {
			$this->logger->error('HR warnings skipped because employment status could not be read.', ['userId' => $userId, 'exception' => $loadError]);
			return null;
		}
	}

	private function formatHours(int $minutes, bool $forceSign = false): string {
		$sign = '';
		if ($minutes < 0) {
			$sign = '-';
			$minutes = -$minutes;
		} elseif ($forceSign && $minutes > 0) {
			$sign = '+';
		}
		$hours = intdiv($minutes, 60);
		$remainingMinutes = $minutes % 60;
		return $sign . sprintf("%02d:%02d", $hours, $remainingMinutes);
	}
}
