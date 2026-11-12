<?php

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Db\HrPreferencesStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class HrNotificationService {
	private array $overtimeCache = [];

	public function __construct(
		private HrPreferencesStore $preferences,
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
			// HR activity groups do not restrict delivery, but freelancer accounts receive no warnings.
			try {
				$hrUser = $this->userManager->get($hrUserId);
				if (!$hrUser || !$hrUser->isEnabled()) {
					continue;
				}
				$recipientStatus = $this->loadEmploymentStatus($hrUserId);
				if ($recipientStatus === null || $recipientStatus->freelancerMode) {
					continue;
				}
			} catch (\Throwable $recipientError) {
				$this->logger->error('HR warnings skipped because the recipient could not be loaded.', ['userId' => $hrUserId, 'exception' => $recipientError]);
				continue;
			}
			$settings = $this->preferences->getNotificationSettings($hrUserId);
			$noEntryEnabled = $settings['noEntryEnabled'];
			$overtimeEnabled = $settings['overtimeEnabled'];
			$negativeEnabled = $settings['negativeOvertimeEnabled'];

			if (!$noEntryEnabled && !$overtimeEnabled && !$negativeEnabled) {
				continue;
			}

			$thresholds = $settings['warningThresholds'];
			$noEntryDays = $thresholds['noEntryDays'];
			$overtimeThresholdMin = $thresholds['overtimeThresholdMinutes'];
			$negativeThresholdMin = $thresholds['negativeOvertimeThresholdMinutes'];

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
				if ($employmentStatus === null || !$employmentStatus->isActive || $employmentStatus->freelancerMode) {
					continue;
				}

				$displayName = (string)($userInfo['name'] ?? $userId);

				if (!array_key_exists($userId, $this->overtimeCache)) {
					$this->overtimeCache[$userId] = $this->monthlyBalanceService->calculateCurrentBalance($userId);
				}
				$balanceSummary = $this->overtimeCache[$userId];

				if ($noEntryEnabled) {
					$referenceDate = $employmentStatus->getMissingEntryReferenceDate($balanceSummary['lastEntryDate'] ?? null);
					if ($referenceDate !== null) {
						$reference = \DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate, $today->getTimezone());
						$daysSinceLastEntry = $reference > $today ? 0 : (int)$reference->diff($today)->format('%a');
						if ($daysSinceLastEntry >= $noEntryDays) {
							$hrNotifications['noEntry'][] = [
								'user' => $displayName,
								'days' => $daysSinceLastEntry,
							];
						}
					}
				}

				if (!$balanceSummary) {
					continue;
				}

				$overtimeMinutes = (int)($balanceSummary['overtimeMinutes'] ?? 0);

				if ($overtimeEnabled && $overtimeMinutes > 0 && $overtimeMinutes >= $overtimeThresholdMin) {
					$hrNotifications['overtime'][] = [
						'user' => $displayName,
						'overtime' => $this->formatHours($overtimeMinutes, true),
					];
				}

				if ($negativeEnabled && $overtimeMinutes < 0 && $overtimeMinutes <= -$negativeThresholdMin) {
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
