<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCA\Timesheet\Service\EmploymentStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\PreConditionNotMetException;

/** Keeps both independently owned flags and their date histories in one atomic record. */
class EmploymentStatusStore {
	private const APP_ID = 'timesheet';
	private const STATUS_KEY = 'employment_status';

	public function __construct(
		private IConfig $userConfig,
		private ILockingProvider $lockingProvider,
		private ITimeFactory $timeFactory,
	) {
	}

	public function getStatus(string $userId): EmploymentStatus {
		return $this->getStatusesForUsers([$userId])[$userId];
	}

	/** @param string[] $userIds
	 * @return array<string,EmploymentStatus>
	 */
	public function getStatusesForUsers(array $userIds): array {
		if ($userIds === []) {
			return [];
		}
		foreach ($userIds as $userId) {
			$this->validateUserId($userId);
		}
		$userIds = array_values(array_unique($userIds));
		// Read fresh values rather than the preference cache populated during authentication.
		$rawValues = $this->userConfig->getUserValueForUsers(self::APP_ID, self::STATUS_KEY, $userIds);
		$today = $this->today();
		$statuses = [];
		foreach ($userIds as $userId) {
			$rawValue = array_key_exists($userId, $rawValues) ? $rawValues[$userId] : '';
			$state = $this->decodeState($rawValue, $today);
			$statuses[$userId] = $this->toStatus($state, $rawValue);
		}
		return $statuses;
	}

	public function setFreelancerMode(string $userId, bool $enabled, string $expectedRevision): EmploymentStatus {
		return $this->updateFlag($userId, 'freelancerMode', $enabled, $expectedRevision);
	}

	public function setActive(string $userId, bool $isActive, string $expectedRevision): EmploymentStatus {
		return $this->updateFlag($userId, 'isActive', $isActive, $expectedRevision);
	}

	private function updateFlag(string $userId, string $flag, bool $value, string $expectedRevision): EmploymentStatus {
		$this->validateUserId($userId);
		if (preg_match('/\A[0-9a-f]{64}\z/i', $expectedRevision) !== 1) {
			throw new \InvalidArgumentException('An employment status revision is required.');
		}
		$lockKey = hash('sha256', 'timesheet:employment-status:' . $userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$rawValues = $this->userConfig->getUserValueForUsers(self::APP_ID, self::STATUS_KEY, [$userId]);
			$rawValue = array_key_exists($userId, $rawValues) ? $rawValues[$userId] : '';
			$today = $this->today();
			$state = $this->decodeState($rawValue, $today);
			if (!hash_equals(hash('sha256', $rawValue), strtolower($expectedRevision))) {
				throw new PreConditionNotMetException();
			}
			if ($state[$flag] === $value) {
				return $this->toStatus($state, $rawValue);
			}

			$periodKey = $flag === 'isActive' ? 'inactivePeriods' : 'freelancerPeriods';
			$isExcluded = $flag === 'isActive' ? !$value : $value;
			if ($isExcluded) {
				$state[$periodKey][] = ['from' => $today, 'to' => null];
			} else {
				$lastIndex = count($state[$periodKey]) - 1;
				$state[$periodKey][$lastIndex]['to'] = $today;
				if ($state[$periodKey][$lastIndex]['from'] === $today) {
					array_pop($state[$periodKey]);
				}
			}
			$state[$flag] = $value;
			$state['nonce'] = bin2hex(random_bytes(16));
			$newRawValue = json_encode($state, JSON_THROW_ON_ERROR);
			// The shared revision and database precondition reject concurrent HR/self updates.
			$this->userConfig->setUserValue($userId, self::APP_ID, self::STATUS_KEY, $newRawValue, $rawValue);
			return $this->toStatus($state, $newRawValue);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function decodeState(mixed $rawValue, string $today): array {
		if (!is_string($rawValue)) {
			throw new \UnexpectedValueException('Invalid employment status data.');
		}
		if ($rawValue === '') {
			return ['version' => 1, 'nonce' => '', 'isActive' => true, 'freelancerMode' => false, 'freelancerPeriods' => [], 'inactivePeriods' => []];
		}
		$state = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
		$expectedKeys = ['version', 'nonce', 'isActive', 'freelancerMode', 'freelancerPeriods', 'inactivePeriods'];
		if (!is_array($state) || count($state) !== count($expectedKeys) || array_diff($expectedKeys, array_keys($state)) !== []
			|| $state['version'] !== 1 || !is_string($state['nonce']) || preg_match('/\A[0-9a-f]{32}\z/', $state['nonce']) !== 1
			|| !is_bool($state['isActive']) || !is_bool($state['freelancerMode'])
			|| !is_array($state['freelancerPeriods']) || !is_array($state['inactivePeriods'])) {
			throw new \UnexpectedValueException('Invalid employment status data.');
		}
		// Policy construction validates dates before chronology and flag consistency are checked.
		$this->toStatus($state, $rawValue);
		$this->validateHistory($state['freelancerPeriods'], $state['freelancerMode'], $today);
		$this->validateHistory($state['inactivePeriods'], !$state['isActive'], $today);
		return $state;
	}

	private function validateHistory(array $periods, bool $hasOpenPeriod, string $today): void {
		$previousEnd = null;
		$openPeriods = 0;
		foreach ($periods as $index => $period) {
			if ($period['from'] > $today || ($period['to'] !== null && ($period['to'] > $today || $period['to'] <= $period['from']))
				|| ($index > 0 && ($previousEnd === null || $period['from'] < $previousEnd))) {
				throw new \UnexpectedValueException('Invalid employment status history.');
			}
			if ($period['to'] === null) {
				$openPeriods++;
			}
			$previousEnd = $period['to'];
		}
		if ($openPeriods !== ($hasOpenPeriod ? 1 : 0)) {
			throw new \UnexpectedValueException('Employment status and history do not match.');
		}
	}

	private function toStatus(array $state, string $rawValue): EmploymentStatus {
		return new EmploymentStatus($state['isActive'], $state['freelancerMode'], hash('sha256', $rawValue), $state['freelancerPeriods'], $state['inactivePeriods']);
	}

	private function validateUserId(mixed $userId): void {
		if (!is_string($userId) || $userId === '') {
			throw new \InvalidArgumentException('An account identifier is required.');
		}
	}

	private function today(): string {
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
	}
}
