<?php

declare(strict_types=1);

namespace OCA\Timesheet\Db;

use DateTimeImmutable;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;

/** Stores successful employee balance deliveries independently of all other mail types. */
class EmployeeBalanceNotificationStore {
	private const APP_ID = 'timesheet';
	private const LAST_SENT_KEY = 'employee_balance_notification_last_sent';
	private const REPEAT_DAYS = 7;

	public function __construct(private IConfig $userConfig, private ILockingProvider $lockingProvider) {
	}

	/**
	 * @template TResult
	 * @param callable():TResult $operation
	 * @return TResult
	 */
	public function withDeliveryLock(string $userId, callable $operation): mixed {
		$lockKey = hash('sha256', 'timesheet:employee-balance-notification:' . $userId);
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			return $operation();
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function isDeliveryDue(string $userId, DateTimeImmutable $now): bool {
		// Read fresh values because a second job may have populated the request's preference cache.
		$values = $this->userConfig->getUserValueForUsers(self::APP_ID, self::LAST_SENT_KEY, [$userId]);
		$lastSent = $values[$userId] ?? '';
		if ($lastSent === '') {
			return true;
		}
		$lastSentAt = DateTimeImmutable::createFromFormat(\DATE_ATOM, $lastSent);
		if ($lastSentAt === false || $lastSentAt->format(\DATE_ATOM) !== $lastSent) {
			throw new \UnexpectedValueException('Invalid employee balance delivery timestamp.');
		}
		return $now >= $lastSentAt->setTimezone($now->getTimezone())->modify('+' . self::REPEAT_DAYS . ' days');
	}

	public function markDelivered(string $userId, DateTimeImmutable $now): void {
		$this->userConfig->setUserValue($userId, self::APP_ID, self::LAST_SENT_KEY, $now->format(\DATE_ATOM));
	}
}
