<?php

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\UserConfigMapper;
use OCA\Timesheet\Service\HrService;
use OCP\AppFramework\Db\DoesNotExistException;

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
		private MonthlyBalanceService $monthlyBalanceService
	) {
	}

	public function doCron(): array {
		$notificationsByHrUser = [];

		$hrUserIds = $this->hrService->getAllHrUserIds();
		if (!$hrUserIds) {
			return $notificationsByHrUser;
		}

		$todayDate = date('Y-m-d');

		foreach ($hrUserIds as $hrUserId) {
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

			$accessibleUsers = $this->hrService->getAccessibleUsers($hrUserId);
			if (!$accessibleUsers) {
				continue;
			}

			foreach ($accessibleUsers as $userInfo) {
				$userId = (string)($userInfo['id'] ?? '');
				if ($userId === '') {
					continue;
				}

				$displayName = (string)($userInfo['name'] ?? $userId);

				if (!array_key_exists($userId, $this->overtimeCache)) {
					$this->overtimeCache[$userId] = $this->monthlyBalanceService->calculateCurrentBalance($userId);
				}
				$balanceSummary = $this->overtimeCache[$userId];

				$lastEntryDate = ($balanceSummary && isset($balanceSummary['lastEntryDate']))
					? (string)$balanceSummary['lastEntryDate']
					: (($balanceSummary && isset($balanceSummary['to'])) ? (string)$balanceSummary['to'] : null);

				$daysSinceLastEntry = null;
				if ($lastEntryDate) {
					$secondsSinceLastEntry = strtotime($todayDate) - strtotime($lastEntryDate);
					$daysSinceLastEntry = ($secondsSinceLastEntry < 0) ? 0 : (int)floor($secondsSinceLastEntry / 86400);
				}

				if ($noEntryEnabled) {
					if ($lastEntryDate === null || ($daysSinceLastEntry !== null && $daysSinceLastEntry >= $noEntryDays)) {
						$hrNotifications['noEntry'][] = [
							'user' => $displayName,
							'days' => ($lastEntryDate && $daysSinceLastEntry !== null) ? (int)$daysSinceLastEntry : null,
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
