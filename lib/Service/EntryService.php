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
			$comment = $data['comment'] ?? null;
			$entry->setComment($comment);
			$entry->setCommentType($this->resolveCommentType($data['commentType'] ?? null, $comment));
			$entry->setCreatedAt(time());
			$entry->setUpdatedAt(time());
			$createdEntry = $this->mapper->insert($entry);
			$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
			return $this->outcome($createdEntry, 'created', null, HistoryChangeSet::entry($createdEntry));
		});
	}

	public function upsertCommentOnly(string $userId, string $workDate, string $comment, mixed $commentType = null): Entry {
		return $this->writes->run($userId, function () use ($userId, $workDate, $comment, $commentType): array {
			$existingEntry = $this->mapper->findByUserAndDate($userId, $workDate);
			$before = $existingEntry ? HistoryChangeSet::entry($existingEntry) : null;
			$resolvedType = $this->resolveCommentType($commentType, $comment, $existingEntry);
			if ($existingEntry && $before['comment'] === $comment && $existingEntry->getCommentType() === $resolvedType) {
				return $this->outcome($existingEntry, 'updated', $before, $before);
			}
			// This legacy POST path changes only a comment on an existing entry.
			$entry = $this->mapper->upsertCommentOnly($userId, $workDate, $comment, $resolvedType);
			if ($before === null || $before['comment'] !== $comment) {
				$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
			}
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
			foreach (['workDate', 'startMin', 'endMin', 'breakMinutes'] as $fieldName) {
				if (array_key_exists($fieldName, $data)) {
					$setterMethod = 'set' . ucfirst($fieldName);
					$entry->$setterMethod($data[$fieldName]);
				}
			}
			if (array_key_exists('comment', $data) || isset($data['commentType'])) {
				$comment = array_key_exists('comment', $data) ? $data['comment'] : $entry->getComment();
				$commentType = $this->resolveCommentType($data['commentType'] ?? null, $comment, $entry);
				$entry->setComment($comment);
				$entry->setCommentType($commentType);
			}
			$after = HistoryChangeSet::entry($entry);
			if ($before === $after) {
				return $this->outcome($entry, 'updated', $before, $after);
			}
			$entry->setUpdatedAt(time());
			$updatedEntry = $this->mapper->update($entry);
			$newWorkDate = (string)$updatedEntry->getWorkDate();
			$recalculateFromDate = ($newWorkDate !== '' && $newWorkDate < $oldWorkDate) ? $newWorkDate : $oldWorkDate;
			if (array_diff_key($before, ['commentType' => true]) !== array_diff_key($after, ['commentType' => true])) {
				$this->monthlyBalanceService->markDirtyFromDate($userId, $recalculateFromDate);
			}
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

	private function resolveCommentType(mixed $requestedType, ?string $comment, ?Entry $existing = null): ?string {
		$trimmedComment = $this->trimComment($comment);
		if ($requestedType === null) {
			// Older forms may trim a submitted note even when only the times changed.
			if ($existing !== null && $this->trimComment($existing->getComment()) === $trimmedComment) {
				return $existing->getCommentType();
			}
			return $existing !== null || $trimmedComment !== '' ? EntryCommentType::Custom->value : null;
		}

		$type = is_string($requestedType) ? EntryCommentType::tryFrom($requestedType) : null;
		if ($type === null || ($type->isAbsence() && $trimmedComment === '')) {
			throw new \DomainException('Invalid comment type or label', 400);
		}
		return $type->value;
	}

	private function trimComment(?string $comment): string {
		$text = $comment ?? '';
		return preg_replace('/\A[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+\z/u', '', $text) ?? $text;
	}

	private function outcome(Entry $entry, string $kind, ?array $before, ?array $after, mixed $result = true): array {
		return [
			'result' => $result === true ? $entry : $result,
			'before' => $before, 'after' => $after, 'kind' => $kind,
			'entryId' => $entry->getId(), 'workDate' => (string)$entry->getWorkDate(),
		];
	}
}
