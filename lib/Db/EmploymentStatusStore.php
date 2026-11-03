<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use OCA\Timesheet\Service\EmploymentStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Lock\ILockingProvider;
use OCP\PreConditionNotMetException;

/** Combines group-derived activity and the personal freelancer preference with their histories. */
class EmploymentStatusStore {
	public const ACTIVE_GROUP_ID = 'Zeitnachweis';
	private const APP_ID = 'timesheet';
	private const STATUS_KEY = 'employment_status';

	public function __construct(
		private IConfig $userConfig,
		private ILockingProvider $lockingProvider,
		private ITimeFactory $timeFactory,
		private IGroupManager $groupManager,
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
			$statuses[$userId] = $state['isActive'] === $this->isActiveGroupMember($userId)
				? $this->toStatus($state, $rawValue) : $this->syncActivity($userId);
		}
		return $statuses;
	}

	public function setFreelancerMode(string $userId, bool $enabled, string $expectedRevision): EmploymentStatus {
		return $this->updateFreelancerMode($userId, $enabled, $expectedRevision);
	}

	/** Native events precede Nextcloud's membership cache invalidation. */
	public function recordMembershipEvent(UserAddedEvent|UserRemovedEvent $event): void {
		if ($event->getGroup()->getGID() !== self::ACTIVE_GROUP_ID) {
			return;
		}
		$userId = $event->getUser()->getUID();
		$this->validateUserId($userId);
		$lockKey = $this->lockKey($userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			[$state, $rawValue] = $this->readCurrentState($userId);
			$isActive = $event instanceof UserAddedEvent;
			if ($state['isActive'] !== $isActive) {
				$state = $this->changeFlag($state, 'isActive', $isActive, $this->today());
				$this->saveState($userId, $state, $rawValue);
			}
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Fresh reads repair missed events using the same date history and account lock. */
	public function syncActivity(string $userId): EmploymentStatus {
		$this->validateUserId($userId);
		$lockKey = $this->lockKey($userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			[$state, $rawValue] = $this->readCurrentState($userId);
			[$state, $rawValue] = $this->synchronizeMembership($userId, $state, $rawValue);
			return $this->toStatus($state, $rawValue);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function updateFreelancerMode(string $userId, bool $enabled, string $expectedRevision): EmploymentStatus {
		$this->validateUserId($userId);
		if (preg_match('/\A[0-9a-f]{64}\z/i', $expectedRevision) !== 1) {
			throw new \InvalidArgumentException('An employment status revision is required.');
		}
		$lockKey = $this->lockKey($userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			[$state, $rawValue] = $this->readCurrentState($userId);
			// A group change is authoritative even when the freelancer request is stale.
			[$state, $rawValue] = $this->synchronizeMembership($userId, $state, $rawValue);
			if (!hash_equals(hash('sha256', $rawValue), strtolower($expectedRevision))) {
				throw new PreConditionNotMetException();
			}
			if ($state['freelancerMode'] === $enabled) {
				return $this->toStatus($state, $rawValue);
			}

			$state = $this->changeFlag($state, 'freelancerMode', $enabled, $this->today());
			$newRawValue = $this->saveState($userId, $state, $rawValue);
			return $this->toStatus($state, $newRawValue);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array{array,string} */
	private function readCurrentState(string $userId): array {
		$rawValues = $this->userConfig->getUserValueForUsers(self::APP_ID, self::STATUS_KEY, [$userId]);
		$rawValue = array_key_exists($userId, $rawValues) ? $rawValues[$userId] : '';
		return [$this->decodeState($rawValue, $this->today()), $rawValue];
	}

	/** @return array{array,string} */
	private function synchronizeMembership(string $userId, array $state, string $rawValue): array {
		$isActive = $this->isActiveGroupMember($userId);
		if ($state['isActive'] === $isActive) {
			return [$state, $rawValue];
		}
		$state = $this->changeFlag($state, 'isActive', $isActive, $this->today());
		return [$state, $this->saveState($userId, $state, $rawValue)];
	}

	private function isActiveGroupMember(string $userId): bool {
		return $this->groupManager->isInGroup($userId, self::ACTIVE_GROUP_ID);
	}

	private function changeFlag(array $state, string $flag, bool $value, string $today): array {
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
		return $state;
	}

	private function saveState(string $userId, array $state, string $rawValue): string {
		$newRawValue = json_encode($state, JSON_THROW_ON_ERROR);
		// The database precondition also rejects writers outside this account lock.
		$this->userConfig->setUserValue($userId, self::APP_ID, self::STATUS_KEY, $newRawValue, $rawValue);
		return $newRawValue;
	}

	private function lockKey(string $userId): string {
		return hash('sha256', 'timesheet:employment-status:' . $userId);
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
