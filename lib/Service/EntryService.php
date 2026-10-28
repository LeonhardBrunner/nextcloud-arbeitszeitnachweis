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
	) {
	}

	public function create(array $data, ?string $forceUserId = null): Entry {
		$userId = $forceUserId ?? $this->userSession->getUser()->getUID();
		$workDate = (string)$data['workDate'];

		$existingEntry = $this->mapper->findByUserAndDate($userId, $workDate);
		if ($existingEntry) {
			throw new \DomainException('Entry already exists', 409);
		}

		$entry = new Entry();

		$entry->setUserId($userId);
		$entry->setWorkDate($workDate);
		$entry->setStartMin(array_key_exists('startMin', $data) ? $data['startMin'] : null);
		$entry->setEndMin(array_key_exists('endMin', $data) ? $data['endMin'] : null);
		$entry->setBreakMinutes((int)($data['breakMinutes'] ?? 0));
		$entry->setComment($data['comment'] ?? null);
		$entry->setCreatedAt(time());
		$entry->setUpdatedAt(time());

		$createdEntry = $this->mapper->insert($entry);
		$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
		return $createdEntry;
	}

	public function upsertCommentOnly(string $userId, string $workDate, string $comment): Entry {
		$entry = $this->mapper->upsertCommentOnly($userId, $workDate, $comment);
		$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
		return $entry;
	}

	public function update(int $id, array $data, bool $isHr = false): Entry {
		/** @var Entry $entry */
		$entry = $this->mapper->findById($id);
		$oldWorkDate = (string)$entry->getWorkDate();

		$currentUserId = $this->userSession->getUser()->getUID();
		if (!$isHr && $entry->getUserId() !== $currentUserId) {
			throw new \RuntimeException('Not allowed');
		}

		if (array_key_exists('workDate', $data)) {
			$newWorkDate = (string)$data['workDate'];
			if ($newWorkDate !== '' && $newWorkDate !== $entry->getWorkDate()) {
				$conflictingEntry = $this->mapper->findByUserAndDate($entry->getUserId(), $newWorkDate);
				if ($conflictingEntry && $conflictingEntry->getId() !== $entry->getId()) {
					throw new \DomainException('Entry already exists', 409);
				}
			}
		}

		foreach (['workDate', 'startMin', 'endMin', 'breakMinutes', 'comment'] as $fieldName) {
			if (array_key_exists($fieldName, $data)) {
				$setterMethod = 'set'.ucfirst($fieldName);
				$entry->$setterMethod($data[$fieldName]);
			}
		}
		$entry->setUpdatedAt(time());

		$updatedEntry = $this->mapper->update($entry);
		$newWorkDate = (string)$updatedEntry->getWorkDate();
		$recalculateFromDate = ($newWorkDate !== '' && $newWorkDate < $oldWorkDate) ? $newWorkDate : $oldWorkDate;
		$this->monthlyBalanceService->markDirtyFromDate($updatedEntry->getUserId(), $recalculateFromDate);

		return $updatedEntry;
	}

	public function delete(int $id, bool $isHr = false): void {
		/** @var Entry $entry */
		$entry = $this->mapper->findById($id);
		$currentUserId = $this->userSession->getUser()->getUID();
		if (!$isHr && $entry->getUserId() !== $currentUserId) {
			throw new \RuntimeException('Not allowed');
		}

		$userId = $entry->getUserId();
		$workDate = (string)$entry->getWorkDate();
		$this->mapper->delete($entry);
		$this->monthlyBalanceService->markDirtyFromDate($userId, $workDate);
	}
}
