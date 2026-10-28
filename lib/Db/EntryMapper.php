<?php

namespace OCA\Timesheet\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class EntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_entries', Entry::class);
	}

	/** @return Entry */
	public function findById(int $id): Entry {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $this->findEntity($queryBuilder);
	}

	/** @return ?Entry */
	public function findByUserAndDate(string $userId, string $workDate): ?Entry {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->eq('work_date', $queryBuilder->createNamedParameter($workDate)))
			->setMaxResults(1);

		try {
			return $this->findEntity($queryBuilder);
		} catch (DoesNotExistException $missingEntry) {
			return null;
		}
	}

	public function upsertCommentOnly(string $userId, string $workDate, string $comment): Entry {
		$existingEntry = $this->findByUserAndDate($userId, $workDate);
		$now = time();

		if ($existingEntry) {
			$existingEntry->setComment($comment);
			$existingEntry->setUpdatedAt($now);
			return $this->update($existingEntry);
		}

		$newEntry = new Entry();
		$newEntry->setUserId($userId);
		$newEntry->setWorkDate($workDate);
		$newEntry->setStartMin(null);
		$newEntry->setEndMin(null);
		$newEntry->setBreakMinutes(0);
		$newEntry->setComment($comment);
		$newEntry->setCreatedAt($now);
		$newEntry->setUpdatedAt($now);

		return $this->insert($newEntry);
	}

	/** @return Entry[] */
	public function findByUserAndRange(string $userId, string $fromYmd, string $toYmd): array {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->gte('work_date', $queryBuilder->createNamedParameter($fromYmd)))
			->andWhere($queryBuilder->expr()->lte('work_date', $queryBuilder->createNamedParameter($toYmd)))
			->orderBy('work_date', 'DESC')
			->addOrderBy('start_min', 'DESC');

		return $this->findEntities($queryBuilder);
	}

	/** @return Entry[] */
	public function findAllInRange(string $fromYmd, string $toYmd): array {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->gte('work_date', $queryBuilder->createNamedParameter($fromYmd)))
			->andWhere($queryBuilder->expr()->lte('work_date', $queryBuilder->createNamedParameter($toYmd)))
			->orderBy('work_date', 'DESC')
			->addOrderBy('start_min', 'DESC');

		return $this->findEntities($queryBuilder);
	}

	public function findDistinctUserIds(): array {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->selectDistinct('user_id')
			->from($this->getTableName())
			->orderBy('user_id', 'ASC');
		return $queryBuilder->executeQuery()->fetchAll();
	}

	public function calculateOvertimeAggregate(string $userId, bool $excludeSpecialDays = false): ?array {
		return $this->calculateWorkAggregateForRange($userId, null, null, $excludeSpecialDays);
	}

	public function calculateWorkAggregateForRange(string $userId, ?string $fromYmd, ?string $toYmd, bool $excludeSpecialDays = false): ?array {
		$queryBuilder = $this->db->getQueryBuilder();

		// Account for overnight entries and clamp negative durations to zero.
		$durationExpression = '((CASE WHEN end_min < start_min THEN end_min + 1440 ELSE end_min END) - start_min - COALESCE(break_minutes, 0))';
		$totalMinutesExpression = 'SUM(CASE WHEN start_min IS NULL OR end_min IS NULL THEN 0 ELSE CASE WHEN '
			. $durationExpression
			. ' < 0 THEN 0 ELSE '
			. $durationExpression
			. ' END END)';
		$workdaysExpression = 'SUM(CASE WHEN start_min IS NULL OR end_min IS NULL THEN 0 ELSE 1 END)';

		if ($excludeSpecialDays) {
			$workdaysExpression = 'SUM(CASE WHEN start_min IS NULL OR end_min IS NULL THEN 0 WHEN WEEKDAY(`work_date`) >= 5 THEN 0 ELSE 1 END)';
		}

		$queryBuilder->select('user_id')
			->selectAlias($queryBuilder->createFunction('MIN(`work_date`)'), 'min_date')
			->selectAlias($queryBuilder->createFunction('MAX(`work_date`)'), 'max_date')
			->selectAlias($queryBuilder->createFunction($totalMinutesExpression), 'total_minutes')
			->selectAlias($queryBuilder->createFunction($workdaysExpression), 'total_workdays')
			->from('ts_entries')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->isNotNull('start_min'))
			->andWhere($queryBuilder->expr()->isNotNull('end_min'));

		if ($fromYmd !== null) {
			$queryBuilder->andWhere($queryBuilder->expr()->gte('work_date', $queryBuilder->createNamedParameter($fromYmd)));
		}
		if ($toYmd !== null) {
			$queryBuilder->andWhere($queryBuilder->expr()->lte('work_date', $queryBuilder->createNamedParameter($toYmd)));
		}

		$queryBuilder->groupBy('user_id');

		$aggregateRow = $queryBuilder->executeQuery()->fetch();
		if (!$aggregateRow || !$aggregateRow['min_date']) {
			return null;
		}

		return [
			'from'          => $aggregateRow['min_date'],
			'to'            => $aggregateRow['max_date'],
			'totalMinutes'  => (int)$aggregateRow['total_minutes'],
			'totalWorkdays' => (int)$aggregateRow['total_workdays'],
		];
	}

	/**
	 * @param string[] $userIds
	 * @return array<string, array{from:string,to:string,totalMinutes:int,totalWorkdays:int}>
	 */
	public function calculateOvertimeAggregatesForUsers(array $userIds, bool $excludeSpecialDays): array {
		if ($userIds === []) {
			return [];
		}

		$queryBuilder = $this->db->getQueryBuilder();

		// Account for overnight entries and clamp negative durations to zero.
		$durationExpression = '((CASE WHEN end_min < start_min THEN end_min + 1440 ELSE end_min END) - start_min - COALESCE(break_minutes, 0))';
		$totalMinutesExpression = 'SUM(CASE WHEN start_min IS NULL OR end_min IS NULL THEN 0 ELSE CASE WHEN '
			. $durationExpression
			. ' < 0 THEN 0 ELSE '
			. $durationExpression
			. ' END END)';
		$workdaysExpression = 'SUM(CASE WHEN start_min IS NULL OR end_min IS NULL THEN 0 ELSE 1 END)';

		if ($excludeSpecialDays) {
			$workdaysExpression = 'SUM(CASE WHEN start_min IS NULL OR end_min IS NULL THEN 0 WHEN WEEKDAY(`work_date`) >= 5 THEN 0 ELSE 1 END)';
		}

		$queryBuilder->select('user_id')
			->selectAlias($queryBuilder->createFunction('MIN(`work_date`)'), 'min_date')
			->selectAlias($queryBuilder->createFunction('MAX(`work_date`)'), 'max_date')
			->selectAlias($queryBuilder->createFunction($totalMinutesExpression), 'total_minutes')
			->selectAlias($queryBuilder->createFunction($workdaysExpression), 'total_workdays')
			->from('ts_entries')
			->where($queryBuilder->expr()->in('user_id', $queryBuilder->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($queryBuilder->expr()->isNotNull('start_min'))
			->andWhere($queryBuilder->expr()->isNotNull('end_min'))
			->groupBy('user_id');

		$aggregateRows = $queryBuilder->executeQuery()->fetchAll();
		if (!$aggregateRows) {
			return [];
		}

		$aggregatesByUser = [];
		foreach ($aggregateRows as $aggregateRow) {
			$userId = (string)($aggregateRow['user_id'] ?? '');
			if ($userId === '' || !$aggregateRow['min_date']) {
				continue;
			}
			$aggregatesByUser[$userId] = [
				'from' => (string)$aggregateRow['min_date'],
				'to' => (string)$aggregateRow['max_date'],
				'totalMinutes' => (int)$aggregateRow['total_minutes'],
				'totalWorkdays' => (int)$aggregateRow['total_workdays'],
			];
		}

		return $aggregatesByUser;
	}

	/**
	 * @param string[] $userIds
	 * @return array<string, string>
	 */
	public function getLastEntryDatesForUsers(array $userIds, string $fromYmd, string $toYmd): array {
		if ($userIds === []) {
			return [];
		}

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('user_id')
			->selectAlias($queryBuilder->createFunction('MAX(`work_date`)'), 'last_date')
			->from($this->getTableName())
			->where($queryBuilder->expr()->in('user_id', $queryBuilder->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($queryBuilder->expr()->gte('work_date', $queryBuilder->createNamedParameter($fromYmd)))
			->andWhere($queryBuilder->expr()->lte('work_date', $queryBuilder->createNamedParameter($toYmd)))
			->andWhere($queryBuilder->expr()->isNotNull('start_min'))
			->andWhere($queryBuilder->expr()->isNotNull('end_min'))
			->groupBy('user_id');

		$dateRows = $queryBuilder->executeQuery()->fetchAll();
		if (!$dateRows) {
			return [];
		}

		$lastEntryDates = [];
		foreach ($dateRows as $dateRow) {
			$userId = (string)($dateRow['user_id'] ?? '');
			$lastEntryDate = (string)($dateRow['last_date'] ?? '');
			if ($userId === '' || $lastEntryDate === '') {
				continue;
			}
			$lastEntryDates[$userId] = $lastEntryDate;
		}
		return $lastEntryDates;
	}

	public function getLastEntryDateForUser(string $userId): ?string {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->selectAlias($queryBuilder->createFunction('MAX(`work_date`)'), 'last_date')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->isNotNull('start_min'))
			->andWhere($queryBuilder->expr()->isNotNull('end_min'));

		$dateRow = $queryBuilder->executeQuery()->fetch();
		$lastEntryDate = is_array($dateRow) ? (string)($dateRow['last_date'] ?? '') : '';
		return $lastEntryDate !== '' ? $lastEntryDate : null;
	}

	public function countWorkdaysOnDates(string $userId, array $ymdDates): int {
		if ($ymdDates === []) {
			return 0;
		}

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->in('work_date', $queryBuilder->createNamedParameter(array_values($ymdDates), IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($queryBuilder->expr()->isNotNull('start_min'))
			->andWhere($queryBuilder->expr()->isNotNull('end_min'))
			->andWhere('WEEKDAY(`work_date`) < 5');

		$countRow = $queryBuilder->executeQuery()->fetch();
		return (int) $countRow['cnt'] ?? 0;
	}
}
