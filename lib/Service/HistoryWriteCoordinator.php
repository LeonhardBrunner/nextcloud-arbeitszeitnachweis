<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OC\ForbiddenException;
use OCA\Timesheet\Db\ChangeHistoryStore;
use OCA\Timesheet\Db\ChangeHistoryAccountQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;

/** Serializes each employee's writes and commits data, balance flags and HR history together. */
class HistoryWriteCoordinator {
	public function __construct(
		private IDBConnection $database,
		private ILockingProvider $lockingProvider,
		private IUserSession $userSession,
		private HrService $hrService,
		private IUserManager $users,
		private ChangeHistoryStore $history,
		private ITimeFactory $timeFactory,
		private ChangeHistoryAccountQueue $accountQueue,
	) {
	}

	/** The callback reads fresh values and returns result, before/after, kind, entryId and workDate. */
	public function run(string $employeeId, callable $write): mixed {
		$actor = $this->userSession->getUser();
		if (!$actor) {
			throw new ForbiddenException();
		}
		return $this->withAccountLocks([$employeeId, $actor->getUID()], function () use ($employeeId, $write, $actor): mixed {
			$transactionStarted = false;
			try {
				if ($this->accountQueue->contains($employeeId) || $this->accountQueue->contains($actor->getUID())) {
					throw new \RuntimeException('Account history cleanup is pending.');
				}
				$currentActor = $this->users->get($actor->getUID());
				if (!$currentActor || !$currentActor->isEnabled() || !$this->hrService->canAccessUser($employeeId, $actor->getUID())) {
					throw new ForbiddenException();
				}
				$employee = $this->users->get($employeeId);
				if (!$employee) {
					throw new \DomainException('Employee not found', 404);
				}
				$this->database->beginTransaction();
				$transactionStarted = true;
				$outcome = $write();
				$changes = HistoryChangeSet::between($outcome['before'], $outcome['after']);
				if ($changes !== [] && $actor->getUID() !== $employeeId && $this->hrService->isHr($actor->getUID())) {
					$this->history->append([
						'occurredAt' => $this->timeFactory->getTime(),
						'actor' => ['id' => $actor->getUID(), 'name' => $actor->getDisplayName()],
						'employee' => ['id' => $employeeId, 'name' => $employee->getDisplayName()],
						'kind' => $outcome['kind'], 'entryId' => $outcome['entryId'],
						'workDate' => $outcome['workDate'], 'changes' => $changes,
					]);
				}
				$this->database->commit();
				$transactionStarted = false;
				return $outcome['result'];
			} catch (\Throwable $writeError) {
				if ($transactionStarted) {
					$this->database->rollBack();
				}
				throw $writeError;
			}
		});
	}

	/** Only positive lifecycle events call this method; directory outages are never deletion evidence. */
	public function forgetAccount(string $userId): void {
		$this->withAccountLocks([$userId], function () use ($userId): void {
			$this->accountQueue->mark($userId);
			$this->clearAccountHistory($userId);
		});
	}

	/** A stale job snapshot must not delete records written by a recreated account. */
	public function retryAccountCleanup(string $userId): void {
		$this->withAccountLocks([$userId], function () use ($userId): void {
			if ($this->accountQueue->contains($userId)) {
				$this->clearAccountHistory($userId);
			}
		});
	}

	public function pendingAccountCleanups(): array {
		return $this->accountQueue->pendingAccountIds();
	}

	private function clearAccountHistory(string $userId): void {
		$this->database->beginTransaction();
		try {
			$this->history->removeAccount($userId);
			$this->accountQueue->remove($userId);
			$this->database->commit();
		} catch (\Throwable $cleanupError) {
			$this->database->rollBack();
			throw $cleanupError;
		}
	}

	private function withAccountLocks(array $userIds, callable $operation): mixed {
		$userIds = array_values(array_unique($userIds));
		sort($userIds, SORT_STRING);
		$acquired = [];
		try {
			foreach ($userIds as $userId) {
				// Nextcloud's database lock keys are limited to 64 characters.
				$key = hash('sha256', 'timesheet:employee-write:' . $userId);
				$deadline = microtime(true) + 5;
				while (true) {
					try {
						$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
						break;
					} catch (\OCP\Lock\LockedException $locked) {
						if (microtime(true) >= $deadline) {
							throw $locked;
						}
						usleep(50000);
					}
				}
				$acquired[] = $key;
			}
			return $operation();
		} finally {
			foreach (array_reverse($acquired) as $key) {
				$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}
}
