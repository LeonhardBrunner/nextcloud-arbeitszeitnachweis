<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\PreConditionNotMetException;

/** Stores display choices for the signed-in account, independently of timesheet entries. */
class UserPreferencesStore {
	private const APP_ID = 'timesheet';
	private const BREAK_INPUT_MODE_KEY = 'break_input_mode';
	private const BREAK_INPUT_MODES = ['minutes', 'hours'];
	private const HISTORY_VIEW_KEY = 'change_history_view';
	private const HISTORY_VIEWS = ['cards', 'table'];

	public function __construct(private IConfig $userConfig, private ILockingProvider $lockingProvider) {
	}

	public function getBreakInputMode(string $userId): string {
		return $this->getBreakInputPreference($userId)['breakInputMode'];
	}

	/** @return array{breakInputMode: string, revision: string} */
	public function getBreakInputPreference(string $userId): array {
		return $this->decodePreference($this->readRawPreference($userId));
	}

	/** @return array{breakInputMode: string, revision: string} */
	public function setBreakInputMode(string $userId, string $mode, string $expectedRevision): array {
		if (!in_array($mode, self::BREAK_INPUT_MODES, true)) {
			throw new \InvalidArgumentException('Break input mode must be minutes or hours.');
		}
		if (preg_match('/\A[0-9a-f]{64}\z/i', $expectedRevision) !== 1) {
			throw new \InvalidArgumentException('A preference revision is required.');
		}

		// Serialize saves because older Nextcloud versions check preconditions before writing.
		$lockKey = hash('sha256', 'timesheet:break-input-mode:' . $userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$rawValue = $this->readRawPreference($userId);
			if (!hash_equals(hash('sha256', $rawValue), strtolower($expectedRevision))) {
				throw new PreConditionNotMetException();
			}
			// A fresh nonce also rejects stale requests when the selected mode has not changed.
			$newValue = json_encode(['mode' => $mode, 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
			$this->userConfig->setUserValue($userId, self::APP_ID, self::BREAK_INPUT_MODE_KEY, $newValue, $rawValue);
			return $this->decodePreference($newValue);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array{historyView: string, revision: string} */
	public function getHistoryViewPreference(string $userId): array {
		return $this->decodeHistoryViewPreference($this->readRawPreference($userId, self::HISTORY_VIEW_KEY));
	}

	/** @return array{historyView: string, revision: string} */
	public function setHistoryView(string $userId, string $view, string $expectedRevision): array {
		if (!in_array($view, self::HISTORY_VIEWS, true)) {
			throw new \InvalidArgumentException('History view must be cards or table.');
		}
		if (preg_match('/\A[0-9a-f]{64}\z/i', $expectedRevision) !== 1) {
			throw new \InvalidArgumentException('A preference revision is required.');
		}

		// Keep this account choice independent of timesheet data and the break display.
		$lockKey = hash('sha256', 'timesheet:change-history-view:' . $userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$rawValue = $this->readRawPreference($userId, self::HISTORY_VIEW_KEY);
			if (!hash_equals(hash('sha256', $rawValue), strtolower($expectedRevision))) {
				throw new PreConditionNotMetException();
			}
			$newValue = json_encode(['view' => $view, 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
			$this->userConfig->setUserValue($userId, self::APP_ID, self::HISTORY_VIEW_KEY, $newValue, $rawValue);
			return $this->decodeHistoryViewPreference($newValue);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function readRawPreference(string $userId, string $key = self::BREAK_INPUT_MODE_KEY): string {
		// This public API bypasses preference caches already populated by authentication.
		$values = $this->userConfig->getUserValueForUsers(self::APP_ID, $key, [$userId]);
		return $values[$userId] ?? '';
	}

	/** @return array{breakInputMode: string, revision: string} */
	private function decodePreference(string $rawValue): array {
		// Plain mode strings were stored before revision tracking was introduced.
		$storedValue = json_decode($rawValue, true);
		$mode = is_array($storedValue) ? ($storedValue['mode'] ?? null) : $rawValue;
		return [
			'breakInputMode' => in_array($mode, self::BREAK_INPUT_MODES, true) ? $mode : 'minutes',
			'revision' => hash('sha256', $rawValue),
		];
	}

	/** @return array{historyView: string, revision: string} */
	private function decodeHistoryViewPreference(string $rawValue): array {
		$storedValue = json_decode($rawValue, true);
		$view = is_array($storedValue) ? ($storedValue['view'] ?? null) : null;
		return [
			'historyView' => in_array($view, self::HISTORY_VIEWS, true) ? $view : 'cards',
			'revision' => hash('sha256', $rawValue),
		];
	}
}
