<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCA\Timesheet\Service\UpdateNotesCatalog;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Serializes update-note state for each account without adding an application table. */
class UpdateNotesStore {
	private const APP_ID = 'timesheet';
	private const STATE_KEY = 'update_notes_state';
	private const NEW_ACCOUNT_KEY = 'update_notes_new_account';

	public function __construct(private IConfig $userConfig, private ILockingProvider $lockingProvider) {
	}

	public function read(string $userId): ?array {
		return $this->decode($this->readRaw($userId));
	}

	public function markNewAccount(string $userId): void {
		if ($userId === '') {
			throw new \InvalidArgumentException('An account is required.');
		}
		$this->userConfig->setUserValue($userId, self::APP_ID, self::NEW_ACCOUNT_KEY, '1');
	}

	public function isNewAccount(string $userId): bool {
		$values = $this->userConfig->getUserValueForUsers(self::APP_ID, self::NEW_ACCOUNT_KEY, [$userId]);
		return ($values[$userId] ?? '') === '1';
	}

	/** The operation returns ['state' => account state, 'result' => its public result]. */
	public function update(string $userId, callable $operation): mixed {
		$lockKey = hash('sha256', 'timesheet:update-notes:' . $userId);
		$this->acquireAccountLock($lockKey);
		try {
			$rawValue = $this->readRaw($userId);
			$previousState = $this->decode($rawValue);
			$outcome = $operation($previousState);
			$state = $outcome['state'];
			if ($state !== $previousState) {
				$newValue = json_encode($state, JSON_THROW_ON_ERROR);
				$this->decode($newValue);
				$this->userConfig->setUserValue($userId, self::APP_ID, self::STATE_KEY, $newValue, $rawValue);
			}
			return $outcome['result'];
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function acquireAccountLock(string $lockKey): void {
		$deadline = microtime(true) + 1.0;
		while (true) {
			try {
				$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException $busyAccount) {
				if (microtime(true) >= $deadline) {
					throw $busyAccount;
				}
				// Another tab normally finishes the short account update within a few milliseconds.
				usleep(25000);
			}
		}
	}

	private function readRaw(string $userId): string {
		if ($userId === '') {
			throw new \InvalidArgumentException('An account is required.');
		}
		$values = $this->userConfig->getUserValueForUsers(self::APP_ID, self::STATE_KEY, [$userId]);
		$rawValue = $values[$userId] ?? '';
		if (!is_string($rawValue)) {
			throw new \UnexpectedValueException('Invalid update notes state.');
		}
		return $rawValue;
	}

	private function decode(string $rawValue): ?array {
		if ($rawValue === '') {
			return null;
		}
		$state = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
		$keys = ['version', 'floorVersion', 'shownVersion', 'dismissedVersion', 'publishedVersion'];
		if (!is_array($state) || count($state) !== count($keys) || array_diff($keys, array_keys($state)) !== []
			|| $state['version'] !== 1 || !UpdateNotesCatalog::isVersion($state['floorVersion'])) {
			throw new \UnexpectedValueException('Invalid update notes state.');
		}
		foreach (['shownVersion', 'dismissedVersion', 'publishedVersion'] as $key) {
			if ($state[$key] !== null && !UpdateNotesCatalog::isVersion($state[$key])) {
				throw new \UnexpectedValueException('Invalid update notes version.');
			}
		}
		return $state;
	}
}
