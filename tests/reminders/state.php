<?php

declare(strict_types=1);

// Minimal public-config contracts keep this state test independent of Nextcloud boot.
namespace OCP {
	interface IConfig {
		public function getUserValue($userId, $appName, $key, $default = '');
		public function setUserValue($userId, $appName, $key, $value, $preCondition = null);
	}
}

namespace OCP\AppFramework\Services {
	interface IAppConfig {
		public function getAppValueString(string $key, string $default = ''): string;
		public function setAppValueString(string $key, string $value): void;
	}
}

namespace {
	use OCA\Timesheet\Db\MissingEntryReminderStore;
	use OCP\AppFramework\Services\IAppConfig;
	use OCP\IConfig;

	require_once __DIR__ . '/../../lib/Db/MissingEntryReminderStore.php';

	class ReminderUserConfigFixture implements IConfig {
		public array $values = [];
		public int $writes = 0;
		public bool $rejectWrites = false;

		public function getUserValue($userId, $appName, $key, $default = '') {
			return $this->values[$userId][$appName][$key] ?? $default;
		}

		public function setUserValue($userId, $appName, $key, $value, $preCondition = null) {
			if ($this->rejectWrites) {
				throw new RuntimeException('Simulated user-config persistence failure.');
			}
			$this->writes++;
			$this->values[$userId][$appName][$key] = $value;
		}
	}

	class ReminderAppConfigFixture implements IAppConfig {
		public array $values = [];
		public bool $rejectWrites = false;

		public function getAppValueString(string $key, string $default = ''): string {
			return $this->values[$key] ?? $default;
		}

		public function setAppValueString(string $key, string $value): void {
			if ($this->rejectWrites) {
				throw new RuntimeException('Simulated app-config persistence failure.');
			}
			$this->values[$key] = $value;
		}
	}

	$testCount = 0;
	$failureCount = 0;
	$date = static fn(string $value): DateTimeImmutable => new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));
	$assertSame = static function (mixed $expected, mixed $actual): void {
		if ($expected !== $actual) {
			throw new RuntimeException('Expected ' . var_export($expected, true) . '; received ' . var_export($actual, true));
		}
	};
	$test = static function (string $name, callable $check) use (&$testCount, &$failureCount): void {
		$testCount++;
		try {
			$check();
		} catch (Throwable $failure) {
			$failureCount++;
			fwrite(STDERR, 'FAIL: ' . $name . ': ' . $failure->getMessage() . "\n");
		}
	};
	$fixture = static function (): array {
		$userConfig = new ReminderUserConfigFixture();
		$appConfig = new ReminderAppConfigFixture();
		return [new MissingEntryReminderStore($userConfig, $appConfig), $userConfig, $appConfig];
	};

	$test('A rule is initialized explicitly and independently of other rules', static function () use ($fixture, $assertSame): void {
		[$store] = $fixture();
		$assertSame(false, $store->isRuleInitialized('first-rule'));
		$store->markRuleInitialized('first-rule');
		$assertSame(true, $store->isRuleInitialized('first-rule'));
		$assertSame(false, $store->isRuleInitialized('second-rule'));
	});

	$test('Failure to persist initialization cannot mark a rule ready', static function () use ($fixture, $assertSame): void {
		[$store, , $appConfig] = $fixture();
		$appConfig->rejectWrites = true;
		try {
			$store->markRuleInitialized('first-rule');
		} catch (RuntimeException) {
			$assertSame(false, $store->isRuleInitialized('first-rule'));
			return;
		}
		throw new RuntimeException('Expected persistence failure.');
	});

	$test('Initial enrollment is preserved on later checks and reactivation', static function () use ($fixture, $assertSame): void {
		[$store, $userConfig] = $fixture();
		$expected = ['initialCohort' => true, 'firstDetectedOn' => '2026-10-01'];
		$assertSame($expected, $store->rememberEnrollment('alice', 'rule-a', true, '2026-10-01'));
		$assertSame($expected, $store->rememberEnrollment('alice', 'rule-a', false, '2026-11-01'));
		$assertSame($expected, $store->rememberEnrollment('alice', 'rule-a', true, '2026-12-01'));
		$assertSame(1, $userConfig->writes);
	});

	$test('A later member cannot be promoted to the original cohort', static function () use ($fixture, $assertSame): void {
		[$store] = $fixture();
		$expected = ['initialCohort' => false, 'firstDetectedOn' => '2026-10-20'];
		$store->rememberEnrollment('alice', 'rule-a', false, '2026-10-20');
		$assertSame($expected, $store->rememberEnrollment('alice', 'rule-a', true, '2026-10-29'));
	});

	$test('Enrollment is separate for each person and stable rule identifier', static function () use ($fixture, $assertSame): void {
		[$store, $userConfig] = $fixture();
		$store->rememberEnrollment('alice', 'rule-a', true, '2026-10-01');
		$store->rememberEnrollment('alice', 'rule-b', false, '2026-10-15');
		$store->rememberEnrollment('bob', 'rule-a', false, '2026-10-20');
		$assertSame([
			'rule-a' => ['initialCohort' => true, 'firstDetectedOn' => '2026-10-01'],
			'rule-b' => ['initialCohort' => false, 'firstDetectedOn' => '2026-10-15'],
		], json_decode($userConfig->values['alice']['timesheet']['missing_entry_enrollments'], true));
		$assertSame(['initialCohort' => false, 'firstDetectedOn' => '2026-10-20'], $store->rememberEnrollment('bob', 'rule-a', false, '2026-10-29'));
	});

	$test('Corrupt enrollment JSON fails without replacing the saved boundary', static function () use ($fixture, $assertSame): void {
		[$store, $userConfig] = $fixture();
		$userConfig->values['alice']['timesheet']['missing_entry_enrollments'] = '{not-json';
		try {
			$store->rememberEnrollment('alice', 'rule-a', false, '2026-10-29');
		} catch (JsonException) {
			$assertSame(0, $userConfig->writes);
			$assertSame('{not-json', $userConfig->values['alice']['timesheet']['missing_entry_enrollments']);
			return;
		}
		throw new RuntimeException('Expected corrupt enrollment state to be rejected.');
	});

	$test('Invalid saved enrollment fields fail rather than silently resetting history', static function () use ($fixture): void {
		[$store, $userConfig] = $fixture();
		$userConfig->values['alice']['timesheet']['missing_entry_enrollments'] = '{"rule-a":{"initialCohort":"yes","firstDetectedOn":"2026-10-01"}}';
		try {
			$store->rememberEnrollment('alice', 'rule-a', false, '2026-10-29');
		} catch (UnexpectedValueException) {
			return;
		}
		throw new RuntimeException('Expected invalid enrollment state to be rejected.');
	});

	foreach (['2026-02-30', '2026-99-01', '0000-01-01'] as $invalidDate) {
		$test('An impossible enrollment date is rejected: ' . $invalidDate, static function () use ($fixture, $assertSame, $invalidDate): void {
			[$store, $userConfig] = $fixture();
			$savedState = json_encode(['rule-a' => ['initialCohort' => false, 'firstDetectedOn' => $invalidDate]], JSON_THROW_ON_ERROR);
			$userConfig->values['alice']['timesheet']['missing_entry_enrollments'] = $savedState;
			try {
				$store->rememberEnrollment('alice', 'rule-a', false, '2026-10-29');
			} catch (UnexpectedValueException) {
				$assertSame(0, $userConfig->writes);
				$assertSame($savedState, $userConfig->values['alice']['timesheet']['missing_entry_enrollments']);
				return;
			}
			throw new RuntimeException('Expected an impossible calendar date to be rejected.');
		});
	}

	$test('A due check alone never records a successful delivery', static function () use ($fixture, $assertSame, $date): void {
		[$store, $userConfig] = $fixture();
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-10-29 10:00:00')));
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-10-29 11:00:00')));
		$assertSame(0, $userConfig->writes);
		$assertSame([], $userConfig->values);
	});

	$test('Successful employee delivery is blocked before seven days and due exactly at the boundary', static function () use ($fixture, $assertSame, $date): void {
		[$store, $userConfig] = $fixture();
		$store->markDelivered('alice', 'employee', $date('2026-10-12 10:00:00'));
		$assertSame('2026-10-12T10:00:00+02:00', $userConfig->values['alice']['timesheet']['missing_entry_employee_last_sent']);
		$assertSame(false, $store->isDeliveryDue('alice', 'employee', $date('2026-10-19 09:59:59')));
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-10-19 10:00:00')));
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-10-20 10:00:00')));
	});

	$test('Employee and HR intervals remain separate for a person with both roles', static function () use ($fixture, $assertSame, $date): void {
		[$store] = $fixture();
		$now = $date('2026-10-29 10:00:00');
		$store->markDelivered('alice', 'employee', $now);
		$assertSame(false, $store->isDeliveryDue('alice', 'employee', $now));
		$assertSame(true, $store->isDeliveryDue('alice', 'hr', $now));
		$assertSame(true, $store->isDeliveryDue('bob', 'employee', $now));
		$store->markDelivered('alice', 'hr', $now);
		$assertSame(false, $store->isDeliveryDue('alice', 'hr', $now));
	});

	$test('Enrollment changes do not reset delivery cooldown', static function () use ($fixture, $assertSame, $date): void {
		[$store] = $fixture();
		$store->markDelivered('alice', 'employee', $date('2026-10-29 10:00:00'));
		$store->rememberEnrollment('alice', 'rule-a', true, '2026-10-29');
		$store->rememberEnrollment('alice', 'rule-b', false, '2026-10-30');
		$assertSame(false, $store->isDeliveryDue('alice', 'employee', $date('2026-10-30 10:00:00')));
	});

	$test('Spring clock change preserves seven local calendar days', static function () use ($fixture, $assertSame, $date): void {
		[$store] = $fixture();
		$store->markDelivered('alice', 'employee', $date('2026-03-28 12:30:00'));
		$assertSame(false, $store->isDeliveryDue('alice', 'employee', $date('2026-04-04 12:29:59')));
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-04-04 12:30:00')));
	});

	$test('Autumn clock change preserves seven local calendar days', static function () use ($fixture, $assertSame, $date): void {
		[$store] = $fixture();
		$store->markDelivered('alice', 'employee', $date('2026-10-24 12:30:00'));
		$assertSame(false, $store->isDeliveryDue('alice', 'employee', $date('2026-10-31 12:29:59')));
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-10-31 12:30:00')));
	});

	$test('Equivalent instants in different timezones compare correctly', static function () use ($fixture, $assertSame, $date): void {
		[$store] = $fixture();
		$store->markDelivered('alice', 'employee', $date('2026-10-12 10:00:00'));
		$assertSame(false, $store->isDeliveryDue('alice', 'employee', new DateTimeImmutable('2026-10-19T07:59:59+00:00')));
		$assertSame(true, $store->isDeliveryDue('alice', 'employee', new DateTimeImmutable('2026-10-19T08:00:00+00:00')));
	});

	$test('A failed success-marker write is visible and leaves the recipient retryable', static function () use ($fixture, $assertSame, $date): void {
		[$store, $userConfig] = $fixture();
		$userConfig->rejectWrites = true;
		try {
			$store->markDelivered('alice', 'employee', $date('2026-10-29 10:00:00'));
		} catch (RuntimeException) {
			$assertSame(true, $store->isDeliveryDue('alice', 'employee', $date('2026-10-30 10:00:00')));
			$assertSame(0, $userConfig->writes);
			return;
		}
		throw new RuntimeException('Expected persistence failure.');
	});

	$test('Invalid delivery types cannot read or write another configuration key', static function () use ($fixture, $assertSame, $date): void {
		[$store, $userConfig] = $fixture();
		foreach (['isDeliveryDue', 'markDelivered'] as $method) {
			try {
				$store->$method('alice', 'other', $date('2026-10-29'));
			} catch (InvalidArgumentException) {
				continue;
			}
			throw new RuntimeException('Expected an unknown delivery type to be rejected.');
		}
		$assertSame(0, $userConfig->writes);
	});

	// Corrupt or relative timestamps must never silently restart the repeat interval.
	foreach (['now', 'yesterday', '2026-10-01', '2026-10-01 10:00:00', '2026-10-01T10:00:00Z', '2026-02-30T10:00:00+01:00'] as $invalidTimestamp) {
		$test('A noncanonical delivery timestamp is rejected: ' . $invalidTimestamp, static function () use ($fixture, $assertSame, $date, $invalidTimestamp): void {
			[$store, $userConfig] = $fixture();
			$userConfig->values['alice']['timesheet']['missing_entry_employee_last_sent'] = $invalidTimestamp;
			try {
				$store->isDeliveryDue('alice', 'employee', $date('2026-10-29 10:00:00'));
			} catch (UnexpectedValueException) {
				$assertSame(0, $userConfig->writes);
				$assertSame($invalidTimestamp, $userConfig->values['alice']['timesheet']['missing_entry_employee_last_sent']);
				return;
			}
			throw new RuntimeException('Expected an invalid delivery timestamp to be rejected.');
		});
	}

	fwrite(STDOUT, sprintf("Missing-entry reminder state: %d checks, %d failures.\n", $testCount, $failureCount));
	exit($failureCount === 0 ? 0 : 1);
}
