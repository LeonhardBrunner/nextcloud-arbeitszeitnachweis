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
	interface IL10N {
		public function getLanguageCode(): string;
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

namespace OCP\AppFramework\Utility {
	interface ITimeFactory {
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime;
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

namespace OCA\Timesheet\Settings {
	class RuleSettingsForm {
		public function getData(string $scope): array {
			return ['settingsScope' => 'policy', 'policySection' => 'notifications', 'rulesRevision' => hash('sha256', ''), 'allGroups' => [], 'hrAccessRules' => [], 'hrPeople' => []];
		}
	}
}

namespace {
	use OCA\Timesheet\Controller\PageController;
	use OCA\Timesheet\Controller\TourController;
	use OCA\Timesheet\Db\TourProgressStore;
	use OCA\Timesheet\Db\UserPreferencesStore;
	use OCA\Timesheet\Db\EmploymentStatusStore;
	use OCA\Timesheet\Service\HrService;
	use OCA\Timesheet\Service\AbsenceCommentCatalog;

	require_once __DIR__ . '/../../lib/Db/TourProgressStore.php';
	require_once __DIR__ . '/../../lib/Db/UserPreferencesStore.php';
	require_once __DIR__ . '/../../lib/Service/EmploymentStatus.php';
	require_once __DIR__ . '/../../lib/Db/EmploymentStatusStore.php';
	require_once __DIR__ . '/../../lib/Service/AbsenceCommentCatalog.php';
	require_once __DIR__ . '/../../lib/Controller/TourController.php';
	require_once __DIR__ . '/../../lib/Controller/PageController.php';

	class TourTimeFixture implements \OCP\AppFramework\Utility\ITimeFactory {
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
			$date = new \DateTime('2026-11-02T12:00:00+00:00');
			return $timezone === null ? $date : $date->setTimezone($timezone);
		}
	}

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
			if ($this->rejectWrites) {
				throw new \RuntimeException('Synthetic storage failure: private diagnostic detail.');
			}
			$this->writes[] = compact('userId', 'appName', 'key', 'value');
			$this->values[$userId][$appName][$key] = $value;
		}
	}

	class TourRequestFixture implements \OCP\IRequest {
		public array $parameters = ['userId' => 'somebody-else'];
	}
	class TourTranslationFixture implements \OCP\IL10N {
		public function __construct(private string $language = 'en') {
		}
		public function getLanguageCode(): string {
			return $this->language;
		}
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
		public function isInGroup(string $userId, string $groupId): bool {
			return $groupId === 'Zeitnachweis';
		}
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

	foreach ([[true, false], [false, true], [true, true]] as [$isHr, $isAdmin]) {
		$roleName = $isHr ? ($isAdmin ? 'HR administrator' : 'HR account') : 'administrator';
		$test('An ' . $roleName . ' cannot complete any tour or read its state', static function () use ($fixture, $expectSame, $isHr, $isAdmin): void {
			foreach ([false, true] as $legacyFlag) {
				[$controller, , $config, $locks] = $fixture('alice', $isHr, $isAdmin);
				$response = $controller->complete(1, $legacyFlag);
				$expectSame(403, $response->getStatus());
				$expectSame(['code' => 'tour_unavailable'], $response->getData());
				$expectSame([], $config->reads);
				$expectSame([], $config->writes);
				$expectSame(0, $locks->acquisitions);
			}
		});
		$test('An ' . $roleName . ' page excludes all tour assets and progress reads', static function () use ($fixture, $expectSame, $isHr, $isAdmin): void {
			[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture('alice', $isHr, $isAdmin);
			$config->rejectReads = true;
			$config->values['alice']['timesheet'] = ['tour_employee_version' => '1', 'tour_hr_version' => '1'];
			$logger = new TourLoggerFixture();
			\OCP\Util::$scripts = [];
			\OCP\Util::$styles = [];
			$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, $logger, $groupManager, new AbsenceCommentCatalog(), new TourTranslationFixture(), new EmploymentStatusStore($config, $locks, new TourTimeFixture(), $groupManager), new \OCA\Timesheet\Settings\RuleSettingsForm());
			$parameters = $page->index()->getParams();
			$expectSame(false, $parameters['tourEnabled']);
			$expectSame(false, $parameters['tourStateAvailable']);
			$expectSame(false, in_array('timesheet-tour-launcher', \OCP\Util::$scripts, true));
			$expectSame(false, in_array('timesheet-tour', \OCP\Util::$scripts, true));
			$expectSame(true, in_array('timesheet-main', \OCP\Util::$scripts, true));
			$expectSame(true, in_array('timesheet-header', \OCP\Util::$scripts, true));
			$expectedStyles = ['style'];
			if ($isHr) $expectedStyles = array_merge($expectedStyles, ['timesheet-change-history', 'hr-settings']);
			$expectedStyles[] = 'timesheet-update-notes';
			if ($isHr) $expectedStyles[] = 'timesheet-help-panels';
			$expectSame($expectedStyles, \OCP\Util::$styles);
			$expectSame($isHr, in_array('timesheet-mail-help', \OCP\Util::$scripts, true));
			$expectSame(true, $parameters['updateNotesEnabled']);
			$expectSame([], array_values(array_filter($config->reads, static fn(string $key): bool => str_starts_with($key, 'tour_'))));
			$expectSame([], $config->writes);
			$expectSame([], $logger->warnings);
			$expectSame($isHr, $parameters['isHR']);
		});
	}

	$test('A new employee has one incomplete tour state', static function () use ($fixture, $expectSame): void {
		[, $store, $config] = $fixture();
		$expectSame(['version' => 1, 'employeeCompleted' => false], $store->getProgress('alice'));
		$expectSame(['tour_employee_version'], $config->reads);
	});
	$test('Completion belongs only to the signed-in employee', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config] = $fixture();
		$response = $controller->complete(1);
		$expectSame(200, $response->getStatus());
		$expectSame(['version' => 1, 'employeeCompleted' => true, 'hrCompleted' => false, 'isHr' => false], $response->getData());
		$expectSame(false, $store->getProgress('somebody-else')['employeeCompleted']);
		$expectSame(['tour_employee_version'], array_column($config->writes, 'key'));
		$expectSame('alice', $config->writes[0]['userId']);
	});
	$test('The obsolete false flag remains accepted for an already open employee client', static function () use ($fixture, $expectSame): void {
		[$controller] = $fixture();
		$expectSame(200, $controller->complete(1, false)->getStatus());
	});
	$test('Promotion to HR blocks completion without changing existing employee progress', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config, $locks, , $hrService] = $fixture();
		$store->markCompleted('alice');
		$config->reads = [];
		$config->writes = [];
		$hrService->hrUsers = ['alice'];
		$expectSame(403, $controller->complete(1)->getStatus());
		$expectSame([], $config->reads);
		$expectSame([], $config->writes);
		$expectSame(1, $locks->acquisitions);
		$expectSame('1', $config->values['alice']['timesheet']['tour_employee_version']);
	});
	$test('Promotion to administrator blocks an already opened employee tour', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks, , , $groupManager] = $fixture();
		$groupManager->administrators = ['alice'];
		$expectSame(403, $controller->complete(1)->getStatus());
		$expectSame([], $config->reads);
		$expectSame([], $config->writes);
		$expectSame(0, $locks->acquisitions);
	});
	$test('Removing HR rights restores the preserved employee completion', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config, , , $hrService] = $fixture('alice', true);
		$config->values['alice']['timesheet'] = ['tour_employee_version' => '1', 'tour_hr_version' => '1'];
		$hrService->hrUsers = [];
		$expectSame(['version' => 1, 'employeeCompleted' => true], $store->getProgress('alice'));
		$expectSame(200, $controller->complete(1)->getStatus());
		$expectSame([], $config->writes);
		$expectSame('1', $config->values['alice']['timesheet']['tour_hr_version']);
	});
	$test('Old HR preferences remain untouched and are never read', static function () use ($fixture, $expectSame): void {
		[$controller, $store, $config] = $fixture();
		$config->values['alice']['timesheet'] = ['tour_hr_version' => '8', 'break_input_mode' => 'hours'];
		$expectSame(['version' => 1, 'employeeCompleted' => false], $store->getProgress('alice'));
		$controller->complete(1);
		$expectSame(false, in_array('tour_hr_version', $config->reads, true));
		$expectSame(['tour_employee_version'], array_column($config->writes, 'key'));
		$expectSame('8', $config->values['alice']['timesheet']['tour_hr_version']);
		$expectSame('hours', $config->values['alice']['timesheet']['break_input_mode']);
	});
	foreach ([null, ''] as $userId) {
		$test('An unauthenticated or empty account cannot complete a tour (' . var_export($userId, true) . ')', static function () use ($fixture, $expectSame, $userId): void {
			[$controller, , $config] = $fixture($userId);
			$expectSame(403, $controller->complete(1)->getStatus());
			$expectSame([], $config->writes);
		});
	}
	foreach ([null, '1', 1.0, true, [], new \stdClass()] as $version) {
		$test('A version must be an integer (' . get_debug_type($version) . ')', static function () use ($fixture, $expectSame, $version): void {
			[$controller, , $config] = $fixture();
			$expectSame(400, $controller->complete($version)->getStatus());
			$expectSame([], $config->writes);
		});
	}
	foreach ([0, -1, 2, PHP_INT_MAX] as $version) {
		$test('A different tour version cannot mark this version complete (' . $version . ')', static function () use ($fixture, $expectSame, $version): void {
			[$controller, , $config] = $fixture();
			$response = $controller->complete($version);
			$expectSame(409, $response->getStatus());
			$expectSame(['code' => 'tour_version_changed'], $response->getData());
			$expectSame([], $config->writes);
		});
	}
	foreach ([true, null, 0, 1, 'false', 'true', [], new \stdClass()] as $legacyFlag) {
		$test('Only an obsolete false flag is accepted (' . get_debug_type($legacyFlag) . ')', static function () use ($fixture, $expectSame, $legacyFlag): void {
			[$controller, , $config] = $fixture();
			$expectSame(400, $controller->complete(1, $legacyFlag)->getStatus());
			$expectSame([], $config->writes);
		});
	}
	$test('Repeated completions are idempotent', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks] = $fixture();
		$controller->complete(1);
		$controller->complete(1);
		$expectSame(1, count($config->writes));
		$expectSame(2, $locks->acquisitions);
		$expectSame(2, $locks->releases);
	});
	$test('Newer stored employee completion versions are not lowered', static function () use ($fixture, $expectSame): void {
		[$controller, , $config] = $fixture();
		$config->values['alice']['timesheet'] = ['tour_employee_version' => '7', 'tour_hr_version' => '8', 'break_input_mode' => 'hours'];
		$controller->complete(1);
		$expectSame([], $config->writes);
		$expectSame(['tour_employee_version' => '7', 'tour_hr_version' => '8', 'break_input_mode' => 'hours'], $config->values['alice']['timesheet']);
	});
	foreach (['broken', '-1', '1.5', '', '999999999999999999999999'] as $storedVersion) {
		$test('Invalid stored employee versions remain incomplete (' . $storedVersion . ')', static function () use ($fixture, $expectSame, $storedVersion): void {
			[, $store, $config] = $fixture();
			$config->values['alice']['timesheet'] = ['tour_employee_version' => $storedVersion];
			$expectSame(false, $store->getProgress('alice')['employeeCompleted']);
		});
	}
	$test('Completion survives a fresh store and login session', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks] = $fixture();
		$controller->complete(1);
		$freshStore = new TourProgressStore($config, $locks);
		$expectSame(true, $freshStore->getProgress('alice')['employeeCompleted']);
		$expectSame(false, $freshStore->getProgress('bob')['employeeCompleted']);
	});
	$test('A busy lock requests a bounded client retry without writing', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks] = $fixture();
		$locks->busy = true;
		$response = $controller->complete(1);
		$expectSame(409, $response->getStatus());
		$expectSame(['code' => 'preference_busy'], $response->getData());
		$expectSame([], $config->writes);
		$expectSame(0, $locks->releases);
		$locks->busy = false;
		$expectSame(200, $controller->complete(1)->getStatus());
	});
	$test('Read and write failures release the account lock and reveal no private details', static function () use ($fixture, $expectSame): void {
		foreach (['rejectReads', 'rejectWrites'] as $failureProperty) {
			[$controller, , $config, $locks] = $fixture();
			$config->{$failureProperty} = true;
			$response = $controller->complete(1);
			$expectSame(500, $response->getStatus());
			$expectSame(['message' => 'Saving tour completion failed'], $response->getData());
			$expectSame([], $locks->held);
			$expectSame(1, $locks->releases);
		}
	});
	$test('An unavailable role check cannot permit completion', static function () use ($fixture, $expectSame): void {
		[$controller, , $config, $locks, , $hrService] = $fixture();
		$hrService->rejectReads = true;
		$expectSame(500, $controller->complete(1)->getStatus());
		$expectSame([], $config->writes);
		$expectSame(0, $locks->acquisitions);
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
	$test('Employee page startup preserves progress and loads tour modules before main', static function () use ($fixture, $expectSame): void {
		[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture();
		$store->markCompleted('alice');
		$logger = new TourLoggerFixture();
		\OCP\Util::$scripts = [];
		\OCP\Util::$styles = [];
		$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, $logger, $groupManager, new AbsenceCommentCatalog(), new TourTranslationFixture(), new EmploymentStatusStore($config, $locks, new TourTimeFixture(), $groupManager), new \OCA\Timesheet\Settings\RuleSettingsForm());
		$parameters = $page->index()->getParams();
		$expectSame(true, $parameters['tourEnabled']);
		$expectSame(true, $parameters['tourStateAvailable']);
		$expectSame(false, $parameters['isHR']);
		$expectSame(['version' => 1, 'employeeCompleted' => true], $parameters['tourProgress']);
		$expectSame(true, in_array('timesheet-mail-help', \OCP\Util::$scripts, true));
		$expectSame(true, array_search('timesheet-mail-help', \OCP\Util::$scripts, true) < array_search('timesheet-tour-launcher', \OCP\Util::$scripts, true));
		$expectSame(true, array_search('timesheet-tour-launcher', \OCP\Util::$scripts, true) < array_search('timesheet-tour', \OCP\Util::$scripts, true));
		$expectSame(true, array_search('timesheet-tour', \OCP\Util::$scripts, true) < array_search('timesheet-main', \OCP\Util::$scripts, true));
		$expectSame(['style', 'timesheet-update-notes', 'timesheet-help-panels', 'timesheet-tour'], \OCP\Util::$styles);
		$expectSame([], $logger->warnings);
	});
	$test('A tour-state read failure leaves the main timesheet renderable', static function () use ($fixture, $expectSame): void {
		[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture();
		$config->rejectReads = true;
		$logger = new TourLoggerFixture();
		$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, $logger, $groupManager, new AbsenceCommentCatalog(), new TourTranslationFixture(), new EmploymentStatusStore($config, $locks, new TourTimeFixture(), $groupManager), new \OCA\Timesheet\Settings\RuleSettingsForm());
		$parameters = $page->index()->getParams();
		$expectSame(false, $parameters['tourStateAvailable']);
		$expectSame('minutes', $parameters['breakInputMode']);
		$expectSame(false, $parameters['isHR']);
		$expectSame(1, count($logger->warnings));
		$expectSame(['version' => 1, 'employeeCompleted' => false], $parameters['tourProgress']);
	});
	$test('The account language still selects absence terms for HR pages without a tour', static function () use ($fixture, $expectSame): void {
		foreach ([
			'en' => ['vacation' => 'Vacation', 'timeOffInLieu' => 'Time off in lieu'],
			'en_GB' => ['vacation' => 'Vacation', 'timeOffInLieu' => 'Time off in lieu'],
			'de_DE' => ['vacation' => 'Urlaub', 'timeOffInLieu' => 'Stundenausgleich'],
		] as $language => $expectedTerms) {
			[, $store, $config, $locks, $session, $hrService, $groupManager] = $fixture('alice', true);
			$page = new PageController('timesheet', new TourRequestFixture(), $hrService, new UserPreferencesStore($config, $locks), $session, $store, new TourLoggerFixture(), $groupManager, new AbsenceCommentCatalog(), new TourTranslationFixture($language), new EmploymentStatusStore($config, $locks, new TourTimeFixture(), $groupManager), new \OCA\Timesheet\Settings\RuleSettingsForm());
			$parameters = $page->index()->getParams();
			$expectSame($expectedTerms, $parameters['absenceCommentTerms']);
			$expectSame(false, $parameters['tourEnabled']);
		}
	});
	$test('Page attributes expose only employee introduction state', static function () use ($expectSame): void {
		$template = file_get_contents(__DIR__ . '/../../templates/main.php');
		foreach (['enabled', 'version', 'employee-completed', 'state-available'] as $attribute) {
			$expectSame(true, str_contains($template, 'data-tour-' . $attribute . '="'));
		}
		foreach (['hr-completed', 'is-hr'] as $attribute) {
			$expectSame(false, str_contains($template, 'data-tour-' . $attribute . '="'));
		}
	});

	fwrite(STDOUT, sprintf("Tour progress: %d checks, %d failures.\n", $checks, $failures));
	exit($failures === 0 ? 0 : 1);
}
