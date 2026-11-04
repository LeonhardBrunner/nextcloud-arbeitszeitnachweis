<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\UserConfigMapper;

/** Work settings share the same employee lock and transaction as time entries. */
class WorkSettingsService {
	public function __construct(
		private UserConfigMapper $configMapper,
		private MonthlyBalanceService $balances,
		private HistoryWriteCoordinator $writes,
	) {
	}

	public function save(string $employeeId, int $dailyMinutes, string $state): array {
		return $this->writes->run($employeeId, function () use ($employeeId, $dailyMinutes, $state): array {
			$before = HistoryChangeSet::settings($this->configMapper->getConfigData($employeeId));
			$after = HistoryChangeSet::settings(['dailyMin' => $dailyMinutes, 'state' => $state]);
			if ($before !== $after) {
				$result = $this->configMapper->upsertConfigData($employeeId, $dailyMinutes, $state);
				$this->balances->markAllDirtyForUser($employeeId);
			} else {
				$result = ['dailyMin' => $dailyMinutes, 'state' => $state];
			}
			return ['result' => $result, 'before' => $before, 'after' => $after, 'kind' => 'settings', 'entryId' => null, 'workDate' => null];
		});
	}
}
