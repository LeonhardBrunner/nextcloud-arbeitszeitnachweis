<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCP\IConfig;
use OCP\IDBConnection;

/** Keeps positive account-deletion/reset evidence until history cleanup commits successfully. */
class ChangeHistoryAccountQueue {
	private const APP_ID = 'timesheet';
	private const KEY_PREFIX = 'history_deleted_';

	public function __construct(private IDBConnection $database, private IConfig $config) {
	}

	public function mark(string $userId): void {
		if (!$this->contains($userId)) {
			// Discard a cached value from an earlier generation before creating fresh evidence.
			$this->config->deleteAppValue(self::APP_ID, $this->key($userId));
		}
		$this->config->setAppValue(self::APP_ID, $this->key($userId), $userId);
		if (!$this->contains($userId)) {
			throw new \RuntimeException('The account history cleanup marker could not be stored.');
		}
	}

	public function remove(string $userId): void {
		$this->config->deleteAppValue(self::APP_ID, $this->key($userId));
	}

	/** Fresh database reads avoid cached absence when another request just queued a reset. */
	public function contains(string $userId): bool {
		$query = $this->database->getQueryBuilder();
		$query->select('configvalue')->from('appconfig')
			->where($query->expr()->eq('appid', $query->createNamedParameter(self::APP_ID)))
			->andWhere($query->expr()->eq('configkey', $query->createNamedParameter($this->key($userId))));
		$result = $query->executeQuery();
		try {
			return $result->fetchOne() === $userId;
		} finally {
			$result->closeCursor();
		}
	}

	public function pendingAccountIds(): array {
		$query = $this->database->getQueryBuilder();
		$query->select(['configkey', 'configvalue'])->from('appconfig')
			->where($query->expr()->eq('appid', $query->createNamedParameter(self::APP_ID)));
		$result = $query->executeQuery();
		try {
			$userIds = [];
			foreach ($result->fetchAll() as $row) {
				$userId = (string)$row['configvalue'];
				if (str_starts_with((string)$row['configkey'], self::KEY_PREFIX)
					&& $userId !== '' && $row['configkey'] === $this->key($userId)) {
					$userIds[] = $userId;
				}
			}
			return $userIds;
		} finally {
			$result->closeCursor();
		}
	}

	private function key(string $userId): string {
		// Both older and newer Nextcloud app-config tables accept this 56-character key.
		return self::KEY_PREFIX . substr(hash('sha256', $userId), 0, 40);
	}
}
