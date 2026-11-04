<?php

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\Entry;
use OCA\Timesheet\Db\EntryMapper;
use OCP\IUserSession;

class EntryService {
	public function __construct(
		private EntryMapper $mapper,
		private IUserSession $userSession,
		private MonthlyBalanceService $monthlyBalanceService,
		private HistoryWriteCoordinator $writes,
	) {
	}

	public function create(array $data, ?string $forceUserId = null): Entry {
		$userId = $forceUserId ?? $this->userSession->getUser()->getUID();
		return $this->writes->run($userId, function () use ($userId, $data): array {
			$workDate = (string)$data['workDate'];
			if ($this->mapper->findByUserAndDate($userId, $workDate)) {
				throw new \DomainException('Entry already exists', 409);
			}
			$entry = new Entry();
			$entry->setUserId($userId);
			$entry->setWorkDate($workDate);
			$entry->setStartMin($data['startMin'] ?? null);
			$entry->setEndMin($data['endMin'] ?? null);
			$entry->setBreakMinutes((int)($data['breakMinutes'] ?? 0));
			$entry->setComment($data['comment'] ?? null);
			$entry->setCreatedAt(time());
			$entry->setUpdatedAt(time());
			$createdEntry = $this->mapper->insert($entry);
			$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
			return $this->outcome($createdEntry, 'created', null, HistoryChangeSet::entry($createdEntry));
		});
	}

	public function upsertCommentOnly(string $userId, string $workDate, string $comment): Entry {
		return $this->writes->run($userId, function () use ($userId, $workDate, $comment): array {
			$existingEntry = $this->mapper->findByUserAndDate($userId, $workDate);
			$before = $existingEntry ? HistoryChangeSet::entry($existingEntry) : null;
			if ($existingEntry && (string)($existingEntry->getComment() ?? '') === $comment) {
				return $this->outcome($existingEntry, 'updated', $before, $before);
			}
			// This legacy POST path changes only a comment on an existing entry.
			$entry = $this->mapper->upsertCommentOnly($userId, $workDate, $comment);
			$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
			return $this->outcome($entry, $before === null ? 'created' : 'updated', $before, HistoryChangeSet::entry($entry));
		});
	}

	public function update(int $id, array $data, bool $isHr = false): Entry {
		$userId = $this->mapper->findById($id)->getUserId();
		return $this->writes->run($userId, function () use ($userId, $id, $data): array {
			$entry = $this->mapper->findById($id);
			if ($entry->getUserId() !== $userId) {
				throw new \OC\ForbiddenException();
			}
			$before = HistoryChangeSet::entry($entry);
			$oldWorkDate = (string)$entry->getWorkDate();
			if (array_key_exists('workDate', $data)) {
				$newWorkDate = (string)$data['workDate'];
				if ($newWorkDate !== '' && $newWorkDate !== $oldWorkDate) {
					$conflictingEntry = $this->mapper->findByUserAndDate($userId, $newWorkDate);
					if ($conflictingEntry && $conflictingEntry->getId() !== $id) {
						throw new \DomainException('Entry already exists', 409);
					}
				}
			}
			foreach (['workDate', 'startMin', 'endMin', 'breakMinutes', 'comment'] as $fieldName) {
				if (array_key_exists($fieldName, $data)) {
					$setterMethod = 'set' . ucfirst($fieldName);
					$entry->$setterMethod($data[$fieldName]);
				}
			}
			$after = HistoryChangeSet::entry($entry);
			if ($before === $after) {
				return $this->outcome($entry, 'updated', $before, $after);
			}
			$entry->setUpdatedAt(time());
			$updatedEntry = $this->mapper->update($entry);
			$newWorkDate = (string)$updatedEntry->getWorkDate();
			$recalculateFromDate = ($newWorkDate !== '' && $newWorkDate < $oldWorkDate) ? $newWorkDate : $oldWorkDate;
			$this->monthlyBalanceService->markDirtyFromDate($userId, $recalculateFromDate);
			return $this->outcome($updatedEntry, 'updated', $before, HistoryChangeSet::entry($updatedEntry));
		});
	}

	public function delete(int $id, bool $isHr = false): void {
		$userId = $this->mapper->findById($id)->getUserId();
		$this->writes->run($userId, function () use ($userId, $id): array {
			$entry = $this->mapper->findById($id);
			if ($entry->getUserId() !== $userId) {
				throw new \OC\ForbiddenException();
			}
			$before = HistoryChangeSet::entry($entry);
			$this->mapper->delete($entry);
			$this->monthlyBalanceService->markDirtyFromDate($userId, $before['workDate']);
			return $this->outcome($entry, 'deleted', $before, null, null);
		});
	}

	private function outcome(Entry $entry, string $kind, ?array $before, ?array $after, mixed $result = true): array {
		return [
			'result' => $result === true ? $entry : $result,
			'before' => $before, 'after' => $after, 'kind' => $kind,
			'entryId' => $entry->getId(), 'workDate' => (string)$entry->getWorkDate(),
		];
	}
}
