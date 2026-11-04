<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCA\Timesheet\Service\HistoryChangeSet;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Stores immutable HR change records; all reads receive the caller's employee scope. */
class ChangeHistoryStore {
	public function __construct(private IDBConnection $database) {
	}

	public function append(array $record): void {
		$query = $this->database->getQueryBuilder();
		$query->insert('ts_change_history')->values([
			'occurred_at' => $query->createNamedParameter($record['occurredAt'], IQueryBuilder::PARAM_INT),
			'actor_id' => $query->createNamedParameter($record['actor']['id']),
			'actor_name' => $query->createNamedParameter($record['actor']['name']),
			'employee_id' => $query->createNamedParameter($record['employee']['id']),
			'employee_name' => $query->createNamedParameter($record['employee']['name']),
			'kind' => $query->createNamedParameter($record['kind']),
			'entry_id' => $query->createNamedParameter($record['entryId'], $record['entryId'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'work_date' => $query->createNamedParameter($record['workDate'], $record['workDate'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'changes' => $query->createNamedParameter(json_encode($record['changes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
		]);
		$query->executeStatement();
	}

	/** @return array<int,array> */
	public function find(array $employeeIds, int $cutoff, array $filters, int $limit): array {
		if ($filters['kind'] !== 'comment') {
			return $this->findBatch($employeeIds, $cutoff, $filters, $limit);
		}
		// JSON is evaluated in bounded batches so all supported databases use the same comparison.
		$matches = [];
		$batchSize = 100;
		do {
			$batch = $this->findBatch($employeeIds, $cutoff, $filters, $batchSize);
			foreach ($batch as $record) {
				if (HistoryChangeSet::hasCommentChange($record)) {
					$matches[] = $record;
					if (count($matches) === $limit) {
						return $matches;
					}
				}
				$filters['beforeId'] = $record['id'];
			}
		} while (count($batch) === $batchSize);
		return $matches;
	}

	private function findBatch(array $employeeIds, int $cutoff, array $filters, int $limit): array {
		if ($employeeIds === []) {
			return [];
		}
		$query = $this->scopedQuery($employeeIds, $cutoff);
		$query->select('*')->andWhere($query->expr()->gte('occurred_at', $query->createNamedParameter($filters['from'], IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->lt('occurred_at', $query->createNamedParameter($filters['through'], IQueryBuilder::PARAM_INT)));
		if ($filters['employee'] !== '') {
			$query->andWhere($query->expr()->eq('employee_id', $query->createNamedParameter($filters['employee'])));
		}
		if ($filters['actorDeleted']) {
			$query->andWhere($query->expr()->isNull('actor_id'));
		} elseif ($filters['actor'] !== '') {
			$query->andWhere($query->expr()->eq('actor_id', $query->createNamedParameter($filters['actor'])));
		}
		if ($filters['kind'] === 'comment') {
			$query->andWhere($query->expr()->in('kind', $query->createNamedParameter(['created', 'updated'], IQueryBuilder::PARAM_STR_ARRAY)));
		} elseif ($filters['kind'] !== '') {
			$query->andWhere($query->expr()->eq('kind', $query->createNamedParameter($filters['kind'])));
		}
		if ($filters['beforeId'] !== null) {
			$query->andWhere($query->expr()->lt('id', $query->createNamedParameter($filters['beforeId'], IQueryBuilder::PARAM_INT)));
		}
		$query->orderBy('id', 'DESC')->setMaxResults($limit);
		$result = $query->executeQuery();
		try {
			return array_map([$this, 'toPublicArray'], $result->fetchAll());
		} finally {
			$result->closeCursor();
		}
	}

	/** Actor choices come only from retained events in the same employee scope. */
	public function actors(array $employeeIds, int $cutoff): array {
		if ($employeeIds === []) {
			return [];
		}
		$query = $this->scopedQuery($employeeIds, $cutoff);
		$query->selectDistinct(['actor_id', 'actor_name'])->orderBy('actor_name', 'ASC');
		$result = $query->executeQuery();
		try {
			$actors = [];
			foreach ($result->fetchAll() as $row) {
				$id = $row['actor_id'];
				$actors[$id === null ? 'deleted:' : 'user:' . $id] = ['id' => $id, 'name' => (string)$row['actor_name']];
			}
			return array_values($actors);
		} finally {
			$result->closeCursor();
		}
	}

	public function deleteExpired(int $cutoff): void {
		$query = $this->database->getQueryBuilder();
		$query->delete('ts_change_history')->where($query->expr()->lt('occurred_at', $query->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** Deletion of a subject removes its history; deletion of an editor removes its identity. */
	public function removeAccount(string $userId): void {
		$query = $this->database->getQueryBuilder();
		$query->delete('ts_change_history')->where($query->expr()->eq('employee_id', $query->createNamedParameter($userId)))->executeStatement();
		$query = $this->database->getQueryBuilder();
		$query->update('ts_change_history')->set('actor_id', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('actor_name', $query->createNamedParameter(''))
			->where($query->expr()->eq('actor_id', $query->createNamedParameter($userId)))->executeStatement();
	}

	private function scopedQuery(array $employeeIds, int $cutoff): IQueryBuilder {
		$query = $this->database->getQueryBuilder();
		$query->from('ts_change_history')
			->where($query->expr()->in('employee_id', $query->createNamedParameter($employeeIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->gte('occurred_at', $query->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		return $query;
	}

	private function toPublicArray(array $row): array {
		return [
			'id' => (int)$row['id'], 'occurredAt' => (int)$row['occurred_at'],
			'actor' => ['id' => $row['actor_id'], 'name' => (string)$row['actor_name']],
			'employee' => ['id' => (string)$row['employee_id'], 'name' => (string)$row['employee_name']],
			'kind' => (string)$row['kind'],
			'entryId' => $row['entry_id'] === null ? null : (int)$row['entry_id'],
			'workDate' => $row['work_date'],
			'changes' => json_decode((string)$row['changes'], true, 512, JSON_THROW_ON_ERROR),
		];
	}
}
