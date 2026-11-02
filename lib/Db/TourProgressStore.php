<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCP\IConfig;
use OCP\Lock\ILockingProvider;

/** Keeps introduction completion separate from recorded times and display preferences. */
class TourProgressStore {
	public const VERSION = 1;
	private const APP_ID = 'timesheet';
	private const EMPLOYEE_VERSION_KEY = 'tour_employee_version';

	public function __construct(private IConfig $userConfig, private ILockingProvider $lockingProvider) {
	}

	/** @return array{version:int,employeeCompleted:bool} */
	public function getProgress(string $userId): array {
		return [
			'version' => self::VERSION,
			'employeeCompleted' => $this->readVersion($userId, self::EMPLOYEE_VERSION_KEY) >= self::VERSION,
		];
	}

	public function markCompleted(string $userId): void {
		// A short, namespaced key fits Nextcloud's database locking provider.
		$lockKey = hash('sha256', 'timesheet:tour-progress:' . $userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->writeCompletedVersion($userId, self::EMPLOYEE_VERSION_KEY);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function writeCompletedVersion(string $userId, string $key): void {
		if ($this->readVersion($userId, $key) < self::VERSION) {
			$this->userConfig->setUserValue($userId, self::APP_ID, $key, (string)self::VERSION);
		}
	}

	private function readVersion(string $userId, string $key): int {
		// Read current values even when authentication already filled the user-config cache.
		$values = $this->userConfig->getUserValueForUsers(self::APP_ID, $key, [$userId]);
		$version = filter_var($values[$userId] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		return $version === false ? 0 : $version;
	}
}
