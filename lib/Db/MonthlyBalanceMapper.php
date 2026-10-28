<?php

namespace OCA\Timesheet\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MonthlyBalanceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_monthly_balances', MonthlyBalance::class);
	}

	public function findByUserMonth(string $userId, int $year, int $month): ?MonthlyBalance {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->eq('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($queryBuilder->expr()->eq('month', $queryBuilder->createNamedParameter($month, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($queryBuilder);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findLatestCleanBefore(string $userId, int $year, int $month): ?MonthlyBalance {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->eq('needs_recalculation', $queryBuilder->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($queryBuilder->expr()->orX(
				$queryBuilder->expr()->lt('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
				$queryBuilder->expr()->andX(
					$queryBuilder->expr()->eq('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
					$queryBuilder->expr()->lt('month', $queryBuilder->createNamedParameter($month, IQueryBuilder::PARAM_INT))
				)
			))
			->orderBy('year', 'DESC')
			->addOrderBy('month', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($queryBuilder);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return MonthlyBalance[] */
	public function findDirtyForUserUpTo(string $userId, int $year, int $month): array {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select('*')
			->from($this->getTableName())
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->eq('needs_recalculation', $queryBuilder->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($queryBuilder->expr()->orX(
				$queryBuilder->expr()->lt('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
				$queryBuilder->expr()->andX(
					$queryBuilder->expr()->eq('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
					$queryBuilder->expr()->lte('month', $queryBuilder->createNamedParameter($month, IQueryBuilder::PARAM_INT))
				)
			))
			->orderBy('year', 'ASC')
			->addOrderBy('month', 'ASC');

		return $this->findEntities($queryBuilder);
	}

	public function markDirtyFromMonth(string $userId, int $year, int $month): void {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->update($this->getTableName())
			->set('needs_recalculation', $queryBuilder->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $queryBuilder->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->andWhere($queryBuilder->expr()->orX(
				$queryBuilder->expr()->gt('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
				$queryBuilder->expr()->andX(
					$queryBuilder->expr()->eq('year', $queryBuilder->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
					$queryBuilder->expr()->gte('month', $queryBuilder->createNamedParameter($month, IQueryBuilder::PARAM_INT))
				)
			));
		$queryBuilder->executeStatement();
	}

	public function markAllDirtyForUser(string $userId): void {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->update($this->getTableName())
			->set('needs_recalculation', $queryBuilder->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $queryBuilder->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)));
		$queryBuilder->executeStatement();
	}

	public function markAllDirty(): void {
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->update($this->getTableName())
			->set('needs_recalculation', $queryBuilder->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $queryBuilder->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
		$queryBuilder->executeStatement();
	}

	public function saveCalculated(MonthlyBalance $balance): MonthlyBalance {
		if ($balance->getId() !== null) {
			return $this->update($balance);
		}
		return $this->insert($balance);
	}
}
