<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/** Initializes the recording start after the schema and installed version are both ready. */
class ChangeHistoryRecordingStore {
	private const APP_ID = 'timesheet';
	private const START_KEY = 'change_history_started_at';

	public function __construct(
		private IDBConnection $database,
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private ILockingProvider $lockingProvider,
	) {
	}

	public function initialize(): void {
		$version = (string)$this->config->getAppValue(self::APP_ID, 'installed_version', '0');
		if (version_compare($version, '1.4.0', '<') || !$this->database->tableExists('ts_change_history')) {
			return;
		}
		// Existing installations avoid a shared startup lock and always read their persisted marker.
		if ($this->getStartedAt() > 0) {
			return;
		}
		$key = hash('sha256', 'timesheet:change-history-recording-start');
		$deadline = microtime(true) + 5;
		while (true) {
			try {
				$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (\OCP\Lock\LockedException $locked) {
				if ($this->getStartedAt() > 0) {
					return;
				}
				if (microtime(true) >= $deadline) {
					throw $locked;
				}
				usleep(50000);
			}
		}
		try {
			if ($this->getStartedAt() === 0) {
				$this->config->setAppValue(self::APP_ID, self::START_KEY, (string)$this->timeFactory->getTime());
			}
		} finally {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** A different startup request may have initialized the value after app-config was cached. */
	public function getStartedAt(): int {
		$query = $this->database->getQueryBuilder();
		$query->select('configvalue')->from('appconfig')
			->where($query->expr()->eq('appid', $query->createNamedParameter(self::APP_ID)))
			->andWhere($query->expr()->eq('configkey', $query->createNamedParameter(self::START_KEY)));
		$result = $query->executeQuery();
		try {
			$value = $result->fetchOne();
			return is_string($value) && ctype_digit($value) && (int)$value > 0 ? (int)$value : 0;
		} finally {
			$result->closeCursor();
		}
	}
}
