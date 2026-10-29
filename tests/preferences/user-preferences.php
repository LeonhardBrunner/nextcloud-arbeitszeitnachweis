<?php

declare(strict_types=1);

// Run this standalone check without bootstrapping Nextcloud.
namespace OCP {
	interface IConfig {
		public function getUserValue($userId, $appName, $key, $default = '');
		public function getUserValueForUsers($appName, $key, $userIds);
		public function setUserValue($userId, $appName, $key, $value, $preCondition = null);
	}
	interface IRequest {
	}
	interface IUser {
		public function getUID(): string;
	}
	interface IUserSession {
		public function getUser(): ?IUser;
	}
	class PreConditionNotMetException extends \RuntimeException {
	}
}

namespace OCP\Lock {
	class LockedException extends \RuntimeException {
	}
	interface ILockingProvider {
		public const LOCK_EXCLUSIVE = 2;
		public function acquireLock(string $path, int $type, ?string $readablePath = null): void;
		public function releaseLock(string $path, int $type): void;
	}
}

namespace OCP\AppFramework {
	class Controller {
		public function __construct(string $appName, \OCP\IRequest $request) {
		}
	}
	class Http {
		public const STATUS_OK = 200;
		public const STATUS_BAD_REQUEST = 400;
		public const STATUS_FORBIDDEN = 403;
		public const STATUS_CONFLICT = 409;
		public const STATUS_INTERNAL_SERVER_ERROR = 500;
	}
}

namespace OCP\AppFramework\Http {
	class DataResponse {
		public function __construct(private array $data, private int $status) {
		}
		public function getData(): array {
			return $this->data;
		}
		public function getStatus(): int {
			return $this->status;
		}
	}
}

namespace OCP\AppFramework\Http\Attribute {
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class NoAdminRequired {
	}
}

namespace {
	use OCA\Timesheet\Controller\PreferencesController;
	use OCA\Timesheet\Db\UserPreferencesStore;

	require_once __DIR__ . '/../../lib/Db/UserPreferencesStore.php';
	require_once __DIR__ . '/../../lib/Controller/PreferencesController.php';

	class PreferencesConfigFixture implements \OCP\IConfig {
		public array $values = [];
		public int $writes = 0;
		public bool $rejectWrites = false;
		public bool $rejectReads = false;
		public array $cachedValues = [];
		public ?\Closure $beforeWrite = null;
		public function __construct(private PreferencesLockFixture $lockingProvider) {
		}
		public function getUserValue($userId, $appName, $key, $default = '') {
			if ($this->rejectReads) {
				throw new \RuntimeException('Synthetic read failure.');
			}
			return $this->cachedValues[$userId][$appName][$key] ?? $this->values[$userId][$appName][$key] ?? $default;
		}
		public function getUserValueForUsers($appName, $key, $userIds) {
			if ($this->rejectReads) {
				throw new \RuntimeException('Synthetic uncached read failure.');
			}
			$values = [];
			foreach ($userIds as $userId) {
				if (isset($this->values[$userId][$appName][$key])) {
					$values[$userId] = $this->values[$userId][$appName][$key];
				}
			}
			return $values;
		}
		public function setUserValue($userId, $appName, $key, $value, $preCondition = null) {
			$lockKey = hash('sha256', 'timesheet:break-input-mode:' . $userId);
			if (!isset($this->lockingProvider->held[$lockKey])) {
				throw new \RuntimeException('Preference write was not protected by the account lock.');
			}
			if ($this->rejectWrites) {
				throw new \RuntimeException('Synthetic storage failure: private diagnostic detail.');
			}
			if ($this->beforeWrite !== null) {
				($this->beforeWrite)($this);
			}
			if ($preCondition !== null && isset($this->values[$userId][$appName][$key]) && $this->values[$userId][$appName][$key] !== $preCondition) {
				throw new \OCP\PreConditionNotMetException();
			}
			$this->writes++;
			$this->values[$userId][$appName][$key] = $value;
		}
	}

	class PreferencesLockFixture implements \OCP\Lock\ILockingProvider {
		public array $held = [];
		public int $acquisitions = 0;
		public int $releases = 0;
		public bool $rejectLocks = false;
		public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
			if (strlen($path) > 64) {
				throw new \RuntimeException('Nextcloud database lock keys cannot exceed 64 bytes.');
			}
			if ($this->rejectLocks) {
				throw new \RuntimeException('Preference lock unavailable.');
			}
			if (isset($this->held[$path])) {
				throw new \OCP\Lock\LockedException('Preference is being saved.');
			}
			if ($type !== self::LOCK_EXCLUSIVE) {
				throw new \RuntimeException('An exclusive preference lock is required.');
			}
			$this->held[$path] = true;
			$this->acquisitions++;
		}
		public function releaseLock(string $path, int $type): void {
			unset($this->held[$path]);
			$this->releases++;
		}
	}

	class PreferencesRequestFixture implements \OCP\IRequest {
		public function __construct(public array $parameters = []) {
		}
	}

	class PreferencesUserFixture implements \OCP\IUser {
		public function __construct(private string $userId) {
		}
		public function getUID(): string {
			return $this->userId;
		}
	}

	class PreferencesSessionFixture implements \OCP\IUserSession {
		public function __construct(public ?\OCP\IUser $user) {
		}
		public function getUser(): ?\OCP\IUser {
			return $this->user;
		}
	}

	$checks = 0;
	$failures = 0;
	$expectSame = static function (mixed $expected, mixed $actual): void {
		if ($expected !== $actual) {
			throw new \RuntimeException('Expected ' . var_export($expected, true) . '; received ' . var_export($actual, true));
		}
	};
	$test = static function (string $description, callable $check) use (&$checks, &$failures): void {
		$checks++;
		try {
			$check();
		} catch (\Throwable $failure) {
			$failures++;
			fwrite(STDERR, 'FAIL: ' . $description . ': ' . $failure->getMessage() . "\n");
		}
	};
	$fixture = static function (?string $userId = 'alice', array $parameters = []): array {
		$lockingProvider = new PreferencesLockFixture();
		$config = new PreferencesConfigFixture($lockingProvider);
		$store = new UserPreferencesStore($config, $lockingProvider);
		$session = new PreferencesSessionFixture($userId === null ? null : new PreferencesUserFixture($userId));
		$controller = new PreferencesController('timesheet', new PreferencesRequestFixture($parameters), $store, $session);
		return [$controller, $store, $config, $session, $lockingProvider];
	};

	$test('An account without a saved preference starts with minutes and no write', static function () use ($fixture, $expectSame): void {
		[, $store, $config] = $fixture();
		$expectSame('minutes', $store->getBreakInputMode('alice'));
		$expectSame(['breakInputMode' => 'minutes', 'revision' => hash('sha256', '')], $store->getBreakInputPreference('alice'));
		$expectSame(0, $config->writes);
	});

	foreach (['', 'Hours', 'seconds', ' hours ', '{', '{"mode":"seconds"}', '{"mode":["hours"]}'] as $invalidStoredValue) {
		$test('An invalid stored preference falls back to minutes: ' . json_encode($invalidStoredValue), static function () use ($fixture, $expectSame, $invalidStoredValue): void {
			[, $store, $config] = $fixture();
			$config->values['alice']['timesheet']['break_input_mode'] = $invalidStoredValue;
			$expectSame('minutes', $store->getBreakInputMode('alice'));
			$expectSame(0, $config->writes);
		});
	}

	$test('A saved selection survives new store and session instances', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, , $lockingProvider] = $fixture();
		$expectSame(200, $controller->setBreakInputMode('hours', hash('sha256', ''))->getStatus());
		$newStore = new UserPreferencesStore($config, $lockingProvider);
		$newSession = new PreferencesSessionFixture(new PreferencesUserFixture('alice'));
		$newController = new PreferencesController('timesheet', new PreferencesRequestFixture(), $newStore, $newSession);
		$expectSame('hours', $newStore->getBreakInputMode($newSession->getUser()->getUID()));
		$response = $newController->setBreakInputMode('minutes', $newStore->getBreakInputPreference('alice')['revision']);
		$expectSame(200, $response->getStatus());
		$expectSame('minutes', $response->getData()['breakInputMode']);
		$expectSame('minutes', $newStore->getBreakInputMode('alice'));
	});

	$test('The store keeps different accounts and app namespaces separate', static function () use ($fixture, $expectSame): void {
		[, $store, $config] = $fixture();
		$config->values['alice']['another-app']['break_input_mode'] = 'hours';
		$expectSame('minutes', $store->getBreakInputMode('alice'));
		$store->setBreakInputMode('alice', 'hours', hash('sha256', ''));
		$expectSame('minutes', $store->getBreakInputMode('bob'));
		$store->setBreakInputMode('bob', 'minutes', hash('sha256', ''));
		$expectSame('hours', $store->getBreakInputMode('alice'));
		$expectSame('minutes', $store->getBreakInputMode('bob'));
		$expectSame('hours', $config->values['alice']['another-app']['break_input_mode']);
	});

	$test('Changing display preferences leaves all other settings untouched', static function () use ($fixture, $expectSame): void {
		[, $store, $config] = $fixture();
		$config->values['alice'] = ['core' => ['lang' => 'de_DE'], 'timesheet' => ['missing_entry_employee_last_sent' => '2026-10-29T10:00:00+01:00']];
		$before = $config->values;
		$store->setBreakInputMode('alice', 'hours', hash('sha256', ''));
		$expected = $before;
		$expected['alice']['timesheet']['break_input_mode'] = $config->values['alice']['timesheet']['break_input_mode'];
		$expectSame($expected, $config->values);
	});

	$test('A forged request target cannot overwrite another account', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config] = $fixture('alice', ['userId' => 'bob', 'targetUserId' => 'bob']);
		$response = $controller->setBreakInputMode('hours', hash('sha256', ''));
		$expectSame(200, $response->getStatus());
		$expectSame($store->getBreakInputPreference('alice'), $response->getData());
		$expectSame('hours', $store->getBreakInputMode('alice'));
		$expectSame('minutes', $store->getBreakInputMode('bob'));
		$expectSame(['alice'], array_keys($config->values));
	});

	$test('HR viewing an employee saves the HR viewers own display choice', static function () use ($fixture, $expectSame): void {
		[$controller, $store] = $fixture('hr-user', ['userId' => 'employee']);
		$expectSame(200, $controller->setBreakInputMode('hours', hash('sha256', ''))->getStatus());
		$expectSame('hours', $store->getBreakInputMode('hr-user'));
		$expectSame('minutes', $store->getBreakInputMode('employee'));
	});

	foreach ([null, ''] as $unsignedUserId) {
		$test('An unauthenticated or empty account cannot save preferences', static function () use ($fixture, $expectSame, $unsignedUserId): void {
			[$controller, , $config] = $fixture($unsignedUserId);
			$expectSame(403, $controller->setBreakInputMode('hours', hash('sha256', ''))->getStatus());
			$expectSame(0, $config->writes);
		});
	}

	$test('Omitting the mode returns an input error without writing', static function () use ($fixture, $expectSame): void {
		[$controller, , $config] = $fixture();
		$expectSame(400, $controller->setBreakInputMode()->getStatus());
		$expectSame(0, $config->writes);
	});

	foreach ([null, '', 'Hours', 'hours ', 'seconds', true, 1, ['hours']] as $invalidSubmittedValue) {
		$test('Invalid submitted mode is rejected: ' . json_encode($invalidSubmittedValue), static function () use ($fixture, $expectSame, $invalidSubmittedValue): void {
			[$controller, , $config] = $fixture();
			$expectSame(400, $controller->setBreakInputMode($invalidSubmittedValue, hash('sha256', ''))->getStatus());
			$expectSame(0, $config->writes);
		});
	}

	$test('The store independently rejects unsupported mode strings before persisting', static function () use ($fixture, $expectSame): void {
		[, $store, $config] = $fixture();
		try {
			$store->setBreakInputMode('alice', 'seconds', hash('sha256', ''));
		} catch (\InvalidArgumentException) {
			$expectSame(0, $config->writes);
			return;
		}
		throw new \RuntimeException('Expected invalid mode rejection.');
	});

	$test('A storage failure reports an error and keeps the previous preference', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config] = $fixture();
		$store->setBreakInputMode('alice', 'minutes', hash('sha256', ''));
		$config->rejectWrites = true;
		$response = $controller->setBreakInputMode('hours', $store->getBreakInputPreference('alice')['revision']);
		$expectSame(500, $response->getStatus());
		$expectSame(['message' => 'Saving display preferences failed'], $response->getData());
		$expectSame('minutes', $store->getBreakInputMode('alice'));
		$expectSame(1, $config->writes);
	});

	foreach (['minutes', 'hours'] as $legacyMode) {
		$test('Existing plain mode remains readable: ' . $legacyMode, static function () use ($fixture, $expectSame, $legacyMode): void {
			[, $store, $config] = $fixture();
			$config->values['alice']['timesheet']['break_input_mode'] = $legacyMode;
			$expectSame(['breakInputMode' => $legacyMode, 'revision' => hash('sha256', $legacyMode)], $store->getBreakInputPreference('alice'));
			$saved = $store->setBreakInputMode('alice', $legacyMode, hash('sha256', $legacyMode));
			$expectSame($legacyMode, $saved['breakInputMode']);
			$expectSame(false, $saved['revision'] === hash('sha256', $legacyMode));
			$expectSame($legacyMode, json_decode($config->values['alice']['timesheet']['break_input_mode'], true)['mode']);
		});
	}

	$test('Every successful save gets a new revision even when the mode is unchanged', static function () use ($fixture, $expectSame): void {
		[, $store, $config] = $fixture();
		$first = $store->setBreakInputMode('alice', 'hours', hash('sha256', ''));
		$second = $store->setBreakInputMode('alice', 'hours', $first['revision']);
		$expectSame('hours', $second['breakInputMode']);
		$expectSame(false, $first['revision'] === $second['revision']);
		$rawValue = $config->values['alice']['timesheet']['break_input_mode'];
		$expectSame(hash('sha256', $rawValue), $second['revision']);
		$expectSame(1, preg_match('/\A[0-9a-f]{32}\z/', json_decode($rawValue, true)['nonce']));
	});

	$test('A late first-page save cannot overwrite a newer same-mode save after reload', static function () use ($fixture, $expectSame): void {
		[$oldController, $store, $config, $session] = $fixture();
		$firstPage = $store->setBreakInputMode('alice', 'hours', hash('sha256', ''));
		$newController = new PreferencesController('timesheet', new PreferencesRequestFixture(), $store, $session);
		$newResponse = $newController->setBreakInputMode('hours', $firstPage['revision']);
		$expectSame(200, $newResponse->getStatus());
		$lateResponse = $oldController->setBreakInputMode('minutes', $firstPage['revision']);
		$expectSame(409, $lateResponse->getStatus());
		$expectSame(['code' => 'preference_conflict', ...$newResponse->getData()], $lateResponse->getData());
		$expectSame('hours', $store->getBreakInputMode('alice'));
		$expectSame(2, $config->writes);
	});

	$test('The config precondition also detects a write that occurs after the raw read', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, , $lockingProvider] = $fixture();
		$externalValue = '{"mode":"hours","nonce":"external-writer"}';
		$config->beforeWrite = static function (PreferencesConfigFixture $config) use ($externalValue): void {
			$config->values['alice']['timesheet']['break_input_mode'] = $externalValue;
		};
		$response = $controller->setBreakInputMode('minutes', hash('sha256', ''));
		$expectSame(409, $response->getStatus());
		$expectSame(['code' => 'preference_conflict', 'breakInputMode' => 'hours', 'revision' => hash('sha256', $externalValue)], $response->getData());
		$expectSame(0, $config->writes);
		$expectSame([], $lockingProvider->held);
		$expectSame(1, $lockingProvider->releases);
	});

	$test('Cached authentication preferences cannot hide a more recent save', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config, , $lockingProvider] = $fixture();
		$oldRawValue = '{"mode":"minutes","nonce":"old-page"}';
		$newRawValue = '{"mode":"hours","nonce":"new-page"}';
		$config->cachedValues['alice']['timesheet']['break_input_mode'] = $oldRawValue;
		$config->values['alice']['timesheet']['break_input_mode'] = $newRawValue;
		$expectSame($oldRawValue, $config->getUserValue('alice', 'timesheet', 'break_input_mode'));
		$expectSame(['breakInputMode' => 'hours', 'revision' => hash('sha256', $newRawValue)], $store->getBreakInputPreference('alice'));
		$response = $controller->setBreakInputMode('minutes', hash('sha256', $oldRawValue));
		$expectSame(409, $response->getStatus());
		$expectSame(['code' => 'preference_conflict', 'breakInputMode' => 'hours', 'revision' => hash('sha256', $newRawValue)], $response->getData());
		$expectSame($newRawValue, $config->values['alice']['timesheet']['break_input_mode']);
		$expectSame(0, $config->writes);
		$expectSame([], $lockingProvider->held);
	});

	foreach ([null, '', 'a', str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64), true, [str_repeat('a', 64)]] as $invalidRevision) {
		$test('An invalid or missing revision is rejected before saving: ' . json_encode($invalidRevision), static function () use ($fixture, $expectSame, $invalidRevision): void {
			[$controller, , $config, , $lockingProvider] = $fixture();
			$expectSame(400, $controller->setBreakInputMode('hours', $invalidRevision)->getStatus());
			$expectSame(0, $config->writes);
			$expectSame(0, $lockingProvider->acquisitions);
		});
	}

	$test('A request without its revision is rejected', static function () use ($fixture, $expectSame): void {
		[$controller, , $config] = $fixture();
		$expectSame(400, $controller->setBreakInputMode('hours')->getStatus());
		$expectSame(0, $config->writes);
	});

	$test('The store rejects invalid revisions before acquiring a lock', static function () use ($fixture, $expectSame): void {
		[, $store, $config, , $lockingProvider] = $fixture();
		try {
			$store->setBreakInputMode('alice', 'hours', 'invalid');
		} catch (\InvalidArgumentException) {
			$expectSame(0, $config->writes);
			$expectSame(0, $lockingProvider->acquisitions);
			return;
		}
		throw new \RuntimeException('Expected invalid revision rejection.');
	});

	$test('Each account uses an exclusive lock that is released after success and conflict', static function () use ($fixture, $expectSame): void {
		[$controller, $store, , , $lockingProvider] = $fixture();
		$store->setBreakInputMode('alice', 'hours', hash('sha256', ''));
		$expectSame(1, $lockingProvider->acquisitions);
		$expectSame(1, $lockingProvider->releases);
		$expectSame([], $lockingProvider->held);
		$expectSame(409, $controller->setBreakInputMode('minutes', hash('sha256', ''))->getStatus());
		$expectSame(2, $lockingProvider->acquisitions);
		$expectSame(2, $lockingProvider->releases);
		$expectSame([], $lockingProvider->held);
	});

	$test('A concurrent save cannot enter an already-held account lock', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, , $lockingProvider] = $fixture();
		$concurrentStatus = null;
		$config->beforeWrite = static function () use ($controller, &$concurrentStatus): void {
			$concurrentStatus = $controller->setBreakInputMode('minutes', hash('sha256', ''))->getStatus();
		};
		$expectSame(200, $controller->setBreakInputMode('hours', hash('sha256', ''))->getStatus());
		$expectSame(409, $concurrentStatus);
		$expectSame(1, $config->writes);
		$expectSame(1, $lockingProvider->acquisitions);
		$expectSame(1, $lockingProvider->releases);
	});

	$test('A busy account lock returns its current revision and permits a later retry', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config, , $lockingProvider] = $fixture();
		$lockKey = hash('sha256', 'timesheet:break-input-mode:' . 'alice');
		$lockingProvider->held[$lockKey] = true;
		$blocked = $controller->setBreakInputMode('hours', hash('sha256', ''));
		$expectSame(409, $blocked->getStatus());
		$expectSame(['code' => 'preference_conflict', 'breakInputMode' => 'minutes', 'revision' => hash('sha256', '')], $blocked->getData());
		$expectSame(0, $config->writes);
		$expectSame(0, $lockingProvider->releases);
		$lockingProvider->releaseLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
		$expectSame(200, $controller->setBreakInputMode('hours', $blocked->getData()['revision'])->getStatus());
		$expectSame('hours', $store->getBreakInputMode('alice'));
		$expectSame(1, $config->writes);
		$expectSame([], $lockingProvider->held);
	});

	foreach (['read', 'write', 'lock'] as $failurePoint) {
		$test('A ' . $failurePoint . ' failure is generic and leaves locks and preferences intact', static function () use ($fixture, $expectSame, $failurePoint): void {
			[$controller, , $config, , $lockingProvider] = $fixture();
			$config->rejectReads = $failurePoint === 'read';
			$config->rejectWrites = $failurePoint === 'write';
			$lockingProvider->rejectLocks = $failurePoint === 'lock';
			$response = $controller->setBreakInputMode('hours', hash('sha256', ''));
			$expectSame(500, $response->getStatus());
			$expectSame(['message' => 'Saving display preferences failed'], $response->getData());
			$expectSame([], $config->values);
			$expectSame(0, $config->writes);
			$expectSame([], $lockingProvider->held);
			$expectSame($failurePoint === 'lock' ? 0 : 1, $lockingProvider->releases);
		});
	}

	$test('Authenticated non-admin accounts are allowed and CSRF protection remains active', static function () use ($expectSame): void {
		$method = new \ReflectionMethod(PreferencesController::class, 'setBreakInputMode');
		$expectSame(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)));
		$expectSame(0, count($method->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired')));
		$expectSame(['breakInputMode', 'revision'], array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters()));
	});

	$test('The route exposes only the authenticated PUT preference action', static function () use ($expectSame): void {
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		$preferenceRoutes = array_values(array_filter($routes['routes'], static fn(array $route): bool => $route['name'] === 'preferences#setBreakInputMode'));
		$expectSame([['name' => 'preferences#setBreakInputMode', 'url' => '/api/preferences/break-input-mode', 'verb' => 'PUT']], $preferenceRoutes);
	});

	fwrite(STDOUT, sprintf("User display preferences: %d checks, %d failures.\n", $checks, $failures));
	exit($failures === 0 ? 0 : 1);
}
