<?php

declare(strict_types=1);

// Standalone checks use isolated Nextcloud doubles, without loading a real installation.
namespace OCP {
	interface IConfig {
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
	interface IGroupManager {
		public function isAdmin(string $userId): bool;
	}
	class Util {
		public static array $scripts = [];
		public static array $styles = [];
		public static function addTranslations(string $app): void {
		}
		public static function addScript(string $app, string $name): void {
			self::$scripts[] = $name;
		}
		public static function addStyle(string $app, string $name): void {
			self::$styles[] = $name;
		}
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
		public function __construct(protected string $appName, \OCP\IRequest $request) {
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
	class TemplateResponse {
		public function __construct(string $app, string $template, private array $parameters) {
		}
		public function getParams(): array {
			return $this->parameters;
		}
	}
}

namespace OCP\AppFramework\Http\Attribute {
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class NoAdminRequired {
	}
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class NoCSRFRequired {
	}
}

namespace Psr\Log {
	interface LoggerInterface {
		public function warning($message, array $context = []): void;
	}
}

namespace OCA\Timesheet\Service {
	class HrService {
		public array $hrUsers = [];
		public bool $rejectReads = false;
		public function __construct(public string $currentUserId = 'alice') {
		}
		public function isHr(?string $userId = null): bool {
			if ($this->rejectReads) {
				throw new \RuntimeException('Synthetic HR lookup failure.');
			}
			return in_array($userId ?? $this->currentUserId, $this->hrUsers, true);
		}
	}
}

namespace {
	use OCA\Timesheet\Controller\PageController;
	use OCA\Timesheet\Controller\TourController;
	use OCA\Timesheet\Db\TourProgressStore;
	use OCA\Timesheet\Db\UserPreferencesStore;
	use OCA\Timesheet\Service\HrService;

	require_once __DIR__ . '/../../lib/Db/TourProgressStore.php';
	require_once __DIR__ . '/../../lib/Db/UserPreferencesStore.php';
	require_once __DIR__ . '/../../lib/Controller/TourController.php';
	require_once __DIR__ . '/../../lib/Controller/PageController.php';

	class TourLockFixture implements \OCP\Lock\ILockingProvider {
		public array $held = [];
		public int $acquisitions = 0;
		public int $releases = 0;
		public bool $busy = false;
		public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
			if (strlen($path) > 64 || $type !== self::LOCK_EXCLUSIVE) {
				throw new \RuntimeException('Invalid Nextcloud database lock.');
			}
			if ($this->busy || isset($this->held[$path])) {
				throw new \OCP\Lock\LockedException('Tour completion is being saved.');
			}
			$this->held[$path] = true;
			$this->acquisitions++;
		}
		public function releaseLock(string $path, int $type): void {
			unset($this->held[$path]);
			$this->releases++;
		}
	}

	class TourConfigFixture implements \OCP\IConfig {
		public array $values = [];
		public array $writes = [];
		public array $reads = [];
		public bool $rejectReads = false;
		public bool $rejectWrites = false;
		public ?string $rejectWriteKey = null;
		public function __construct(private TourLockFixture $locks) {
		}
		public function getUserValueForUsers($appName, $key, $userIds) {
			$this->reads[] = $key;
			if ($this->rejectReads && str_starts_with($key, 'tour_')) {
				throw new \RuntimeException('Synthetic tour read failure.');
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
			$lockKey = hash('sha256', 'timesheet:tour-progress:' . $userId);
			if (!isset($this->locks->held[$lockKey])) {
				throw new \RuntimeException('Tour writes require the account lock.');
			}
			if ($this->rejectWrites || $key === $this->rejectWriteKey) {
				throw new \RuntimeException('Synthetic storage failure: private diagnostic detail.');
			}
			$this->writes[] = compact('userId', 'appName', 'key', 'value');
			$this->values[$userId][$appName][$key] = $value;
		}
	}

	class TourRequestFixture implements \OCP\IRequest {
		public array $parameters = ['userId' => 'somebody-else'];
	}
	class TourUserFixture implements \OCP\IUser {
		public function __construct(private string $userId) {
		}
		public function getUID(): string {
			return $this->userId;
		}
	}
	class TourSessionFixture implements \OCP\IUserSession {
		public function __construct(public ?\OCP\IUser $user) {
		}
		public function getUser(): ?\OCP\IUser {
			return $this->user;
		}
	}
	class TourLoggerFixture implements \Psr\Log\LoggerInterface {
		public array $warnings = [];
		public function warning($message, array $context = []): void {
			$this->warnings[] = $message;
		}
	}
	class TourGroupManagerFixture implements \OCP\IGroupManager {
		public array $administrators = [];
		public function isAdmin(string $userId): bool {
			return in_array($userId, $this->administrators, true);
		}
	}

	$checks = 0;
	$failures = 0;
	$expectSame = static function (mixed $expected, mixed $actual): void {
		if ($expected !== $actual) {
			throw new \RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
	};
	$test = static function (string $name, \Closure $run) use (&$checks, &$failures): void {
		$checks++;
		try {
			$run();
		} catch (\Throwable $error) {
			$failures++;
			fwrite(STDERR, $name . ': ' . $error->getMessage() . "\n");
		}
	};
	$fixture = static function (?string $userId = 'alice', bool $isHr = false, bool $isAdmin = false): array {
		$locks = new TourLockFixture();
		$config = new TourConfigFixture($locks);
		$store = new TourProgressStore($config, $locks);
		$session = new TourSessionFixture($userId === null ? null : new TourUserFixture($userId));
		$hrService = new HrService($userId ?? '');
		$hrService->hrUsers = $isHr ? [$userId] : [];
		$groupManager = new TourGroupManagerFixture();
		$groupManager->administrators = $isAdmin ? [$userId] : [];
		$controller = new TourController('timesheet', new TourRequestFixture(), $store, $session, $hrService, $groupManager);
		return [$controller, $store, $config, $locks, $session, $hrService, $groupManager];
	};

	foreach ([false, true] as $isHr) {
		$test('An administrator cannot complete a tour, including an HR administrator (' . (int)$isHr . ')', static function () use ($fixture, $expectSame, $isHr): void {
			[$controller, , $config, $locks] = $fixture('alice', $isHr, true);
			$response = $controller->complete(1, $isHr);
			$expectSame(403, $response->getStatus());
			$expectSame(['code' => 'tour_unavailable'], $response->getData());
			$expectSame([], $config->writes);
			$expectSame([], $config->reads);
			$expectSame(0, $locks->acquisitions);
		});
		$test('An administrator page excludes tour assets and progress reads (' . (int)$isHr . ')', static function () use ($fixture, $expectSame, $isHr): void {
			[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture('alice', $isHr, true);
			$config->rejectReads = true;
			$logger = new TourLoggerFixture();
			\OCP\Util::$scripts = [];
			\OCP\Util::$styles = [];
			$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, $logger, $groupManager);
			$parameters = $page->index()->getParams();
			$expectSame(false, $parameters['tourEnabled'] ?? true);
			$expectSame(false, $parameters['tourStateAvailable']);
			$expectSame(false, in_array('timesheet-tour-header', \OCP\Util::$scripts, true));
			$expectSame(false, in_array('timesheet-tour', \OCP\Util::$scripts, true));
			$expectSame(true, in_array('timesheet-main', \OCP\Util::$scripts, true));
			$expectSame(['style'], \OCP\Util::$styles);
			$expectSame([], array_values(array_filter($config->reads, static fn(string $key): bool => str_starts_with($key, 'tour_'))));
			$expectSame([], $logger->warnings);
			$expectSame($isHr, $parameters['isHR']);
		});
	}

	$test('A new account starts with separate incomplete sections', static function () use ($fixture, $expectSame): void {
		[, $store] = $fixture();
		$expectSame(['version' => 1, 'employeeCompleted' => false, 'hrCompleted' => false, 'isHr' => false], $store->getProgress('alice', false));
	});
	$test('An employee completion belongs only to the signed-in account', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config] = $fixture();
		$response = $controller->complete(1, false);
		$expectSame(200, $response->getStatus());
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => false, 'isHr' => false], $response->getData());
		$expectSame(false, $store->getProgress('somebody-else', false)['employeeCompleted']);
		$expectSame(['tour_employee_version'], array_column($config->writes, 'key'));
	});
	$test('An HR completion stores both sections', static function () use ($fixture, $expectSame): void {
		[$controller, , $config] = $fixture('alice', true);
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => true, 'isHr' => true], $controller->complete(1, true)->getData());
		$expectSame(['tour_employee_version', 'tour_hr_version'], array_column($config->writes, 'key'));
	});
	$test('An HR account can finish only the common section', static function () use ($fixture, $expectSame): void {
		[$controller] = $fixture('alice', true);
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => false, 'isHr' => true], $controller->complete(1, false)->getData());
	});
	$test('Omitted includeHr defaults to the common section', static function () use ($fixture, $expectSame): void {
		[$controller] = $fixture('alice', true);
		$expectSame(false, $controller->complete(1)->getData()['hrCompleted']);
	});
	$test('An existing common completion leaves the new HR introduction open', static function () use ($fixture, $expectSame): void {
		[$controller, $store, , , , $hrService] = $fixture();
		$controller->complete(1, false);
		$hrService->hrUsers = ['alice'];
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => false, 'isHr' => true], $store->getProgress('alice', $hrService->isHr('alice')));
		$expectSame(true, $controller->complete(1, true)->getData()['hrCompleted']);
	});
	$test('Role removal prevents false HR completion', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, , , $hrService] = $fixture('alice', true);
		$hrService->hrUsers = [];
		$response = $controller->complete(1, true);
		$expectSame(403, $response->getStatus());
		$expectSame(['code' => 'hr_access_changed'], $response->getData());
		$expectSame([], $config->writes);
	});
	foreach ([null, ''] as $userId) {
		$test('An unauthenticated or empty account cannot complete a tour (' . var_export($userId, true) . ')', static function () use ($fixture, $expectSame, $userId): void {
			[$controller, , $config] = $fixture($userId);
			$expectSame(403, $controller->complete(1, false)->getStatus());
			$expectSame([], $config->writes);
		});
	}
	foreach ([null, '1', 1.0, true, [], new \stdClass()] as $version) {
		$test('A version must be an integer (' . get_debug_type($version) . ')', static function () use ($fixture, $expectSame, $version): void {
			[$controller, , $config] = $fixture();
			$expectSame(400, $controller->complete($version, false)->getStatus());
			$expectSame([], $config->writes);
		});
	}
	foreach ([0, -1, 2, PHP_INT_MAX] as $version) {
		$test('A different tour version cannot mark this version complete (' . $version . ')', static function () use ($fixture, $expectSame, $version): void {
			[$controller, , $config] = $fixture();
			$response = $controller->complete($version, false);
			$expectSame(409, $response->getStatus());
			$expectSame(['code' => 'tour_version_changed'], $response->getData());
			$expectSame([], $config->writes);
		});
	}
	foreach ([null, 0, 1, 'false', 'true', [], new \stdClass()] as $includeHr) {
		$test('HR completion must be a boolean (' . get_debug_type($includeHr) . ')', static function () use ($fixture, $expectSame, $includeHr): void {
			[$controller, , $config] = $fixture('alice', true);
			$expectSame(400, $controller->complete(1, $includeHr)->getStatus());
			$expectSame([], $config->writes);
		});
	}
	$test('Repeated completions are idempotent', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks] = $fixture('alice', true);
		$controller->complete(1, true);
		$controller->complete(1, true);
		$expectSame(2, count($config->writes));
		$expectSame(2, $locks->acquisitions);
		$expectSame(2, $locks->releases);
	});
	$test('Newer stored completion versions are not lowered', static function () use ($fixture, $expectSame): void {
		[$controller, , $config] = $fixture('alice', true);
		$config->values['alice']['timesheet'] = ['tour_employee_version' => '7', 'tour_hr_version' => '8', 'break_input_mode' => 'hours'];
		$controller->complete(1, true);
		$expectSame([], $config->writes);
		$expectSame(['tour_employee_version' => '7', 'tour_hr_version' => '8', 'break_input_mode' => 'hours'], $config->values['alice']['timesheet']);
	});
	foreach (['broken', '-1', '1.5', '', '999999999999999999999999'] as $storedVersion) {
		$test('Invalid stored versions remain incomplete (' . $storedVersion . ')', static function () use ($fixture, $expectSame, $storedVersion): void {
			[, $store, $config] = $fixture();
			$config->values['alice']['timesheet'] = ['tour_employee_version' => $storedVersion, 'tour_hr_version' => $storedVersion];
			$expectSame(false, $store->getProgress('alice', false)['employeeCompleted']);
			$expectSame(false, $store->getProgress('alice', false)['hrCompleted']);
		});
	}
	$test('Completion survives a fresh store and login session', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks] = $fixture();
		$controller->complete(1, false);
		$freshStore = new TourProgressStore($config, $locks);
		$expectSame(true, $freshStore->getProgress('alice', false)['employeeCompleted']);
		$expectSame(false, $freshStore->getProgress('bob', false)['employeeCompleted']);
	});
	$test('A busy lock requests a bounded client retry without writing', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks] = $fixture();
		$locks->busy = true;
		$response = $controller->complete(1, false);
		$expectSame(409, $response->getStatus());
		$expectSame(['code' => 'preference_busy'], $response->getData());
		$expectSame([], $config->writes);
		$expectSame(0, $locks->releases);
		$locks->busy = false;
		$expectSame(200, $controller->complete(1, false)->getStatus());
	});
	$test('Read and write failures release the account lock and reveal no private details', static function () use ($fixture, $expectSame): void {
		foreach (['rejectReads', 'rejectWrites'] as $failureProperty) {
			[$controller, , $config, $locks] = $fixture();
			$config->{$failureProperty} = true;
			$response = $controller->complete(1, false);
			$expectSame(500, $response->getStatus());
			$expectSame(['message' => 'Saving tour completion failed'], $response->getData());
			$expectSame([], $locks->held);
			$expectSame(1, $locks->releases);
		}
	});
	$test('A partial HR write does not report full success', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config, $locks] = $fixture('alice', true);
		$config->rejectWriteKey = 'tour_hr_version';
		$expectSame(500, $controller->complete(1, true)->getStatus());
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => false, 'isHr' => true], $store->getProgress('alice', true));
		$expectSame([], $locks->held);
	});
	$test('Completion remains available to ordinary users and retains CSRF protection', static function () use ($expectSame): void {
		$method = new \ReflectionMethod(TourController::class, 'complete');
		$attributes = array_map(static fn(\ReflectionAttribute $attribute): string => $attribute->getName(), $method->getAttributes());
		$expectSame(true, in_array(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class, $attributes, true));
		$expectSame(false, in_array(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class, $attributes, true));
		$expectSame(['version', 'includeHr'], array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters()));
	});
	$test('Exactly one protected POST route belongs to tour completion', static function () use ($expectSame): void {
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		$tourRoutes = array_values(array_filter($routes['routes'], static fn(array $route): bool => $route['name'] === 'tour#complete'));
		$expectSame([['name' => 'tour#complete', 'url' => '/api/preferences/tour/complete', 'verb' => 'POST']], $tourRoutes);
	});
	$test('Page startup includes progress and loads the new modules before main', static function () use ($fixture, $expectSame): void {
		[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture('alice', true);
		$store->markCompleted('alice', false);
		$logger = new TourLoggerFixture();
		\OCP\Util::$scripts = [];
		\OCP\Util::$styles = [];
		$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, $logger, $groupManager);
		$parameters = $page->index()->getParams();
		$expectSame(true, $parameters['tourEnabled'] ?? true);
		$expectSame(true, $parameters['tourStateAvailable']);
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => false, 'isHr' => true], $parameters['tourProgress']);
		$expectSame(true, array_search('timesheet-tour-header', \OCP\Util::$scripts, true) < array_search('timesheet-tour', \OCP\Util::$scripts, true));
		$expectSame(true, array_search('timesheet-tour', \OCP\Util::$scripts, true) < array_search('timesheet-main', \OCP\Util::$scripts, true));
		$expectSame(['style', 'timesheet-tour'], \OCP\Util::$styles);
		$expectSame([], $logger->warnings);
	});
	$test('A tour-state read failure leaves the main timesheet renderable', static function () use ($fixture, $expectSame): void {
		[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture();
		$config->rejectReads = true;
		$logger = new TourLoggerFixture();
		$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, $logger, $groupManager);
		$parameters = $page->index()->getParams();
		$expectSame(false, $parameters['tourStateAvailable']);
		$expectSame('minutes', $parameters['breakInputMode']);
		$expectSame(false, $parameters['isHR']);
		$expectSame(1, count($logger->warnings));
		$expectSame(['version' => 1, 'employeeCompleted' => false, 'hrCompleted' => false, 'isHr' => false], $parameters['tourProgress']);
	});
	$test('Page attributes encode account role and completion explicitly', static function () use ($expectSame): void {
		$template = file_get_contents(__DIR__ . '/../../templates/main.php');
		foreach (['enabled', 'version', 'employee-completed', 'hr-completed', 'is-hr', 'state-available'] as $attribute) {
			$expectSame(true, str_contains($template, 'data-tour-' . $attribute . '="'));
		}
	});

	fwrite(STDOUT, sprintf("Tour progress: %d checks, %d failures.\n", $checks, $failures));
	exit($failures === 0 ? 0 : 1);
}
