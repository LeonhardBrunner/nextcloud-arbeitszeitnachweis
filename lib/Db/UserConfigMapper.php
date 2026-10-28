<?php

namespace OCA\Timesheet\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class UserConfigMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_user_config', UserConfig::class);
	}

	/**
	 * @return array{dailyMin: int|null, state: string|null}
	 */
	public function getConfigData(string $userId): array {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('work_minutes', 'state')
			->from('ts_user_config')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		$configRow = $queryBuilder->executeQuery()->fetch();

		if ($configRow === false) {
			return ['dailyMin' => null, 'state' => null];
		}

		return [
			'dailyMin' => (int)$configRow['work_minutes'],
			'state'    => $configRow['state'],
		];
	}

	public function upsertConfigData(string $userId, int $dailyMin, string $state): array {
		// Update the existing configuration first.
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->update('ts_user_config')
			->set('work_minutes', $queryBuilder->createNamedParameter($dailyMin, IQueryBuilder::PARAM_INT))
			->set('state', $queryBuilder->createNamedParameter($state, IQueryBuilder::PARAM_STR))
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		$affectedRows = $queryBuilder->executeStatement();

		// Insert a configuration when the update affected no rows.
		if ($affectedRows === 0) {
			$insertQueryBuilder = $this->db->getQueryBuilder();
			$insertQueryBuilder->insert('ts_user_config')
				->values([
					'user_id'      => $insertQueryBuilder->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
					'work_minutes' => $insertQueryBuilder->createNamedParameter($dailyMin, IQueryBuilder::PARAM_INT),
					'state'        => $insertQueryBuilder->createNamedParameter($state, IQueryBuilder::PARAM_STR),
				]);

			$insertQueryBuilder->executeStatement();
		}

		return ['dailyMin' => $dailyMin, 'state' => $state];
	}

	/**
	 * @param string[] $userIds
	 * @return array<string, array{dailyMin: int|null, state: string|null}>
	 */
	public function getConfigDataForUsers(array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('user_id', 'work_minutes', 'state')
			->from('ts_user_config')
			->where($queryBuilder->expr()->in('user_id', $queryBuilder->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));

		$configRows = $queryBuilder->executeQuery()->fetchAll();
		if (!$configRows) {
			return [];
		}

		$configByUser = [];
		foreach ($configRows as $configRow) {
			$userId = (string)($configRow['user_id'] ?? '');
			if ($userId === '') {
				continue;
			}
			$configByUser[$userId] = [
				'dailyMin' => isset($configRow['work_minutes']) ? (int)$configRow['work_minutes'] : null,
				'state' => $configRow['state'] ?? null,
			];
		}
		return $configByUser;
	}

	/**
	 * Find the config entry for a given user.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 */
	public function findByUser(string $userId): UserConfig {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from('ts_user_config')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)));
		return $this->findEntity($queryBuilder);
	}

	/**
	 * Create or update a user's config (upsert style).
	 */
	public function save(UserConfig $config): UserConfig {
		if ($config->getId() !== null) {
			// Update an existing configuration.
			return $this->update($config);
		} else {
			// Insert a new configuration.
			return $this->insert($config);
		}
	}
}
