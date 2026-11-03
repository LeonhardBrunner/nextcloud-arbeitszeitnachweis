<?php

declare(strict_types=1);

// Isolated Nextcloud doubles keep these permission checks independent of a running instance.
namespace OCP {
	interface IRequest {
		public function getParam(string $key, mixed $default = null): mixed;
	}
	interface IUser {
		public function getUID(): string;
	}
	interface IUserManager {
		public function get(string $userId): ?IUser;
		public function searchDisplayName(string $pattern, ?int $limit = null, ?int $offset = null): array;
	}
	interface IUserSession {
		public function getUser(): ?IUser;
	}
	interface IGroupManager {
		public function groupExists(string $groupId): bool;
		public function isInGroup(string $userId, string $groupId): bool;
		public function isAdmin(string $userId): bool;
		public function search(string $query): array;
	}
	interface IL10N {
		public function t(string $text): string;
	}
	interface IURLGenerator {
		public function imagePath(string $app, string $file): string;
	}
	class Util {
		public static array $scripts = [];
		public static function addScript(string $app, string $name): void {
			self::$scripts[] = [$app, $name];
		}
	}
}

namespace OCP\AppFramework {
	class Controller {
		public function __construct(protected string $appName, protected \OCP\IRequest $request) {
		}
	}
	class Http {
		public const STATUS_OK = 200;
		public const STATUS_FORBIDDEN = 403;
	}
}

namespace OCP\AppFramework\Http {
	class DataResponse {
		public function __construct(private array $data, private int $status = 200) {
		}
		public function getData(): array {
			return $this->data;
		}
		public function getStatus(): int {
			return $this->status;
		}
	}
	class TemplateResponse {
		public function __construct(
			private string $app,
			private string $template,
			private array $parameters = [],
			private string $renderAs = 'user',
			private int $status = 200,
		) {
		}
		public function getParams(): array {
			return $this->parameters;
		}
		public function getStatus(): int {
			return $this->status;
		}
		public function getTemplateName(): string {
			return $this->template;
		}
		public function getApp(): string {
			return $this->app;
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

namespace OCP\AppFramework\Services {
	interface IAppConfig {
		public function getAppValueString(string $key, string $default = ''): string;
		public function setAppValueString(string $key, string $value): void;
	}
}

namespace OCP\AppFramework\Utility {
	interface ITimeFactory {
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime;
	}
}

namespace OCP\Settings {
	interface ISettings {
		public function getForm(): \OCP\AppFramework\Http\TemplateResponse;
		public function getSection();
		public function getPriority(): int;
	}
	interface IIconSection {
		public function getIcon(): string;
		public function getName(): string;
		public function getID(): string;
		public function getPriority(): int;
	}
}

namespace OCA\Timesheet\Service {
	class MonthlyBalanceService {
		public int $invalidations = 0;
		public function markAllDirty(): void {
			$this->invalidations++;
		}
	}
	class MissingEntryReminderService {
		public array $preparations = [];
		public bool $rejectPreparation = false;
		public function prepareRuleChanges(array $previous, array $submitted): void {
			if ($this->rejectPreparation) {
				throw new \RuntimeException('Private enrollment diagnostic.');
			}
			$this->preparations[] = [$previous, $submitted];
		}
	}
}

namespace {
	use OCA\Timesheet\Controller\SettingsController;
	use OCA\Timesheet\Service\HrService;
	use OCA\Timesheet\Service\MissingEntryReminderService;
	use OCA\Timesheet\Service\MonthlyBalanceService;

	require_once __DIR__ . '/../../lib/Service/HrRuleAssignments.php';
	require_once __DIR__ . '/../../lib/Service/HrService.php';
	require_once __DIR__ . '/../../lib/Service/MissingEntryReminderSettings.php';
	require_once __DIR__ . '/../../lib/Controller/SettingsController.php';
	$settingsFile = __DIR__ . '/../../lib/Settings/HrSettings.php';
	$sectionFile = __DIR__ . '/../../lib/Settings/HrSection.php';
	require_once $settingsFile;
	require_once $sectionFile;

	class SettingsConfigFixture implements \OCP\AppFramework\Services\IAppConfig {
		public array $reads = [];
		public array $writes = [];
		public bool $rejectWrites = false;
		public function __construct(public array $values) {
		}
		public function getAppValueString(string $key, string $default = ''): string {
			$this->reads[] = $key;
			return $this->values[$key] ?? $default;
		}
		public function setAppValueString(string $key, string $value): void {
			if ($this->rejectWrites) {
				throw new \RuntimeException('Private configuration diagnostic.');
			}
			$this->writes[] = [$key, $value];
			$this->values[$key] = $value;
		}
	}
	class SettingsUserFixture implements \OCP\IUser {
		public function __construct(private string $userId, private ?string $name = null, public bool $enabled = true) {
		}
		public function getUID(): string {
			return $this->userId;
		}
		public function getDisplayName(): string { return $this->name ?? $this->userId; }
		public function isEnabled(): bool { return $this->enabled; }
	}
	class SettingsUsersFixture implements \OCP\IUserManager {
		public array $users = [];
		public int $searches = 0;
		public bool $rejectSearch = false;
		public function __construct() {
			foreach (['employee', 'hr', 'hr-admin', 'admin', 'anton', 'other-anton', 'disabled'] as $id) {
				$name = match ($id) { 'anton', 'other-anton' => 'Anton Beispiel', default => $id };
				$this->users[$id] = new SettingsUserFixture($id, $name, $id !== 'disabled');
			}
		}
		public function get(string $userId): ?\OCP\IUser { return $this->users[$userId] ?? null; }
		public function searchDisplayName(string $pattern, ?int $limit = null, ?int $offset = null): array {
			$this->searches++;
			if ($this->rejectSearch) throw new \RuntimeException('Private directory diagnostic.');
			$matches = array_filter($this->users, static fn($user): bool => str_contains(mb_strtolower($user->getDisplayName()), mb_strtolower($pattern)) || str_contains(mb_strtolower($user->getUID()), mb_strtolower($pattern)));
			return array_slice(array_values($matches), $offset ?? 0, $limit);
		}
	}
	class SettingsSessionFixture implements \OCP\IUserSession {
		public function __construct(public ?\OCP\IUser $user) {
		}
		public function getUser(): ?\OCP\IUser {
			return $this->user;
		}
	}
	class SettingsGroupFixture {
		public function __construct(private string $groupId, private array $users = []) {
		}
		public function getGID(): string {
			return $this->groupId;
		}
		public function getUsers(): array { return $this->users; }
	}
	class SettingsGroupsFixture implements \OCP\IGroupManager {
		public int $searches = 0;
		public int $adminChecks = 0;
		public array $groups = ['staff', 'HR', 'HR backup', 'admin'];
		public array $memberships = [
			'employee' => ['staff'],
			'hr' => ['HR'],
			'hr-admin' => ['HR', 'admin'],
			'admin' => ['admin'],
		];
		public function get(string $groupId): ?SettingsGroupFixture {
			if (!$this->groupExists($groupId)) return null;
			$users = [];
			foreach ($this->memberships as $id => $groups) {
				if (in_array($groupId, $groups, true)) $users[] = new SettingsUserFixture($id);
			}
			return new SettingsGroupFixture($groupId, $users);
		}
		public function groupExists(string $groupId): bool {
			return in_array($groupId, $this->groups, true);
		}
		public function isInGroup(string $userId, string $groupId): bool {
			return in_array($groupId, $this->memberships[$userId] ?? [], true);
		}
		public function isAdmin(string $userId): bool {
			$this->adminChecks++;
			return $this->isInGroup($userId, 'admin');
		}
		public function search(string $query): array {
			$this->searches++;
			return array_map(static fn(string $groupId) => new SettingsGroupFixture($groupId), $this->groups);
		}
	}
	class SettingsRequestFixture implements \OCP\IRequest {
		public array $parameters = [];
		public function getParam(string $key, mixed $default = null): mixed {
			return $this->parameters[$key] ?? $default;
		}
	}
	class SettingsTimeFixture implements \OCP\AppFramework\Utility\ITimeFactory {
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
			return new \DateTime('2026-11-02', $timezone);
		}
	}
	class SettingsTranslationFixture implements \OCP\IL10N {
		public function t(string $text): string {
			return 'Translated: ' . $text;
		}
	}
	class SettingsUrlFixture implements \OCP\IURLGenerator {
		public function imagePath(string $app, string $file): string {
			return '/apps/' . $app . '/img/' . $file;
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
			fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
		}
	};
	$defaultRule = ['id' => 'rule-a', 'hrGroups' => ['HR'], 'userGroups' => ['staff']];
	$settingsClass = \OCA\Timesheet\Settings\HrSettings::class;
	$fixture = static function (?string $userId = 'hr', ?array $initialRules = null) use ($defaultRule, $settingsClass): array {
		$config = new SettingsConfigFixture(['hr_access_rules' => json_encode($initialRules ?? [$defaultRule]), 'specialdays_check' => '0']);
		$groups = new SettingsGroupsFixture();
		$session = new SettingsSessionFixture($userId === null ? null : new SettingsUserFixture($userId));
		$users = new SettingsUsersFixture();
		$hrService = new HrService($config, $session, $groups, $users);
		$request = new SettingsRequestFixture();
		$balances = new MonthlyBalanceService();
		$reminders = new MissingEntryReminderService();
		$controller = new SettingsController('timesheet', $request, $config, $groups, $session, $balances, $reminders, new SettingsTimeFixture(), $hrService, $users);
		$provider = new $settingsClass($config, $groups, 'timesheet', $hrService, $users);
		// Role lookup may read rules; the protected form and mutators must not read settings after denial.
		$config->reads = [];
		\OCP\Util::$scripts = [];
		return compact('config', 'users', 'groups', 'session', 'hrService', 'request', 'balances', 'reminders', 'controller', 'provider');
	};

	// The native personal settings manager hides sections whose provider returns null.
	foreach ([null, 'employee', 'admin', 'hr', 'hr-admin'] as $userId) {
		$isHr = in_array($userId, ['hr', 'hr-admin'], true);
		$label = $userId ?? 'anonymous';
		$test('section visibility: ' . $label, static function () use ($fixture, $userId, $isHr, $expectSame): void {
			$state = $fixture($userId);
			$expectSame($isHr ? 'timesheet' : null, $state['provider']->getSection());
			$expectSame([], $state['config']->reads);
			$expectSame(0, $state['groups']->searches);
		});
		$test('direct form access: ' . $label, static function () use ($fixture, $userId, $isHr, $expectSame): void {
			$state = $fixture($userId);
			$form = $state['provider']->getForm();
			$expectSame($isHr ? 200 : 403, $form->getStatus());
			if (!$isHr) {
				$expectSame([], $state['config']->reads);
				$expectSame(0, $state['groups']->searches);
				$expectSame([], $form->getParams());
				$expectSame([], \OCP\Util::$scripts);
				return;
			}
			$expectSame('settings-hr', $form->getTemplateName());
			$expectSame(['admin', 'HR', 'HR backup', 'staff'], $form->getParams()['allGroups']);
			$expectSame('rule-a', $form->getParams()['hrAccessRules'][0]['id']);
			$expectSame([['timesheet', 'hr-settings']], \OCP\Util::$scripts);
			$expectSame([], $state['config']->writes);
		});
		foreach (['saveHrAccessRules', 'saveSpecialDaysCheck'] as $method) {
			$test($method . ' role: ' . $label, static function () use ($fixture, $userId, $isHr, $method, $defaultRule, $expectSame): void {
				$state = $fixture($userId);
				$state['request']->parameters = ['rules' => json_encode([$defaultRule]), 'specialDaysCheck' => 'true'];
				$response = $state['controller']->$method();
				$expectSame($isHr ? 200 : 403, $response->getStatus());
				if (!$isHr) {
					$expectSame([], $state['config']->reads);
					$expectSame([], $state['config']->writes);
					$expectSame([], $state['reminders']->preparations);
					$expectSame(0, $state['balances']->invalidations);
				}
				$expectSame(0, $state['groups']->adminChecks);
			});
		}
	}

	$test('permission is checked again after HR membership is removed', static function () use ($fixture, $defaultRule, $expectSame): void {
		$state = $fixture('hr');
		$state['groups']->memberships['hr'] = [];
		$state['request']->parameters['rules'] = json_encode([$defaultRule]);
		$expectSame(null, $state['provider']->getSection());
		$expectSame(403, $state['provider']->getForm()->getStatus());
		$expectSame(403, $state['controller']->saveHrAccessRules()->getStatus());
		$expectSame(403, $state['controller']->saveSpecialDaysCheck()->getStatus());
		$expectSame([], $state['config']->reads);
		$expectSame([], $state['config']->writes);
	});
	$test('all existing groups and stored settings are retained when the form opens', static function () use ($fixture, $expectSame): void {
		$state = $fixture();
		$before = $state['config']->values;
		$state['provider']->getForm();
		$expectSame($before, $state['config']->values);
	});

	// Keep existing validation, enrollment order and invalidation behavior intact.
	foreach ([[], [['id' => 'empty', 'hrGroups' => [], 'userGroups' => ['staff']]], [['id' => 'unknown', 'hrGroups' => ['does-not-exist']]]] as $rules) {
		$test('reject removal of the final valid HR group: ' . json_encode($rules), static function () use ($fixture, $rules, $expectSame): void {
			$state = $fixture();
			$state['request']->parameters['rules'] = json_encode($rules);
			$response = $state['controller']->saveHrAccessRules();
			$expectSame(400, $response->getStatus());
			$expectSame(['message' => 'At least one HR group or enabled HR account must remain assigned.'], $response->getData());
			$expectSame([], $state['config']->writes);
			$expectSame([], $state['reminders']->preparations);
		});
	}
	$test('another existing HR group may replace the current member group', static function () use ($fixture, $expectSame): void {
		$state = $fixture();
		$state['request']->parameters['rules'] = json_encode([['id' => 'replacement', 'hrGroups' => ['HR backup'], 'userGroups' => ['staff']]]);
		$response = $state['controller']->saveHrAccessRules();
		$expectSame(200, $response->getStatus());
		$expectSame(['HR backup'], $response->getData()['rules'][0]['hrGroups']);
		$expectSame(1, count($state['reminders']->preparations));
		$expectSame(1, count($state['config']->writes));
	});
	$test('saving normalizes existing groups, thresholds and reminder defaults', static function () use ($fixture, $expectSame): void {
		$state = $fixture();
		$state['request']->parameters['rules'] = json_encode([[
			'id' => ' rule-b ', 'hrGroups' => [' HR ', 'HR', 'unknown'], 'userGroups' => ['staff', 'staff', 'unknown'],
			'priority' => -2, 'maxHours' => 99, 'missingEntryReminders' => ['enabled' => true],
		]]);
		$response = $state['controller']->saveHrAccessRules();
		$expectSame(200, $response->getStatus());
		$rule = $response->getData()['rules'][0];
		$expectSame('rule-b', $rule['id']);
		$expectSame(['HR'], $rule['hrGroups']);
		$expectSame(['staff'], $rule['userGroups']);
		$expectSame(0, $rule['priority']);
		$expectSame(24.0, $rule['maxHours']);
		$expectSame(['enabled' => true, 'graceDays' => 14, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => '2026-11-02'], $rule['missingEntryReminders']);
		$expectSame(json_encode($response->getData()['rules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $state['config']->values['hr_access_rules']);
	});
	$invalidSubmissions = [
		['not json', 'Invalid rules'],
		['null', 'Invalid rules'],
		[json_encode([$defaultRule, $defaultRule]), 'Duplicate rule identifiers'],
		[json_encode([$defaultRule, ['id' => 'other', 'hrGroups' => ['HR backup'], 'userGroups' => ['staff']]]), 'Duplicate employee groups in rules'],
		[json_encode([array_merge($defaultRule, ['missingEntryReminders' => ['graceDays' => 0]])]), 'Reminder grace days must be an integer between 1 and 365'],
		[json_encode([array_merge($defaultRule, ['missingEntryReminders' => ['enabled' => 'yes']])]), 'Reminder enabled must be a boolean'],
		[json_encode([array_merge($defaultRule, ['missingEntryReminders' => ['weekdays' => [0]]])]), 'Reminder weekdays must contain numbers between 1 and 7'],
		[json_encode([array_merge($defaultRule, ['missingEntryReminders' => ['startDate' => '2026-02-30']])]), 'Reminder start date must be a valid date in YYYY-MM-DD format'],
	];
	foreach ($invalidSubmissions as [$rulesJson, $message]) {
		$test('existing validation: ' . $message, static function () use ($fixture, $rulesJson, $message, $expectSame): void {
			$state = $fixture();
			$state['request']->parameters['rules'] = $rulesJson;
			$response = $state['controller']->saveHrAccessRules();
			$expectSame(400, $response->getStatus());
			$expectSame($message, $response->getData()['message']);
			$expectSame([], $state['config']->writes);
			$expectSame([], $state['reminders']->preparations);
		});
	}
	foreach (['enrollment', 'configuration'] as $failureType) {
		$test('failed ' . $failureType . ' does not report successful saving', static function () use ($fixture, $defaultRule, $failureType, $expectSame): void {
			$state = $fixture();
			$state['request']->parameters['rules'] = json_encode([$defaultRule]);
			$state['reminders']->rejectPreparation = $failureType === 'enrollment';
			$state['config']->rejectWrites = $failureType === 'configuration';
			$response = $state['controller']->saveHrAccessRules();
			$expectSame(500, $response->getStatus());
			$expectSame(['message' => 'Saving reminder settings failed'], $response->getData());
			$expectSame([], $state['config']->writes);
		});
	}
	$test('special-days changes invalidate balances only when toggled', static function () use ($fixture, $expectSame): void {
		$state = $fixture();
		$state['request']->parameters['specialDaysCheck'] = 'true';
		$expectSame(['check' => true], $state['controller']->saveSpecialDaysCheck()->getData());
		$expectSame('1', $state['config']->values['specialdays_check']);
		$expectSame(1, $state['balances']->invalidations);
		$state['controller']->saveSpecialDaysCheck();
		$expectSame(1, $state['balances']->invalidations);
		$state['request']->parameters['specialDaysCheck'] = 'false';
		$expectSame(['check' => false], $state['controller']->saveSpecialDaysCheck()->getData());
		$expectSame(2, $state['balances']->invalidations);
	});
	foreach ([null, 'employee', 'admin', 'hr', 'hr-admin'] as $userId) {
		$test('special-days read remains available to authenticated accounts: ' . ($userId ?? 'anonymous'), static function () use ($fixture, $userId, $expectSame): void {
			$state = $fixture($userId);
			$state['config']->values['specialdays_check'] = '1';
			$response = $state['controller']->loadSpecialDaysCheck();
			$expectSame($userId === null ? 401 : 200, $response->getStatus());
			if ($userId !== null) {
				$expectSame(['check' => true], $response->getData());
			} else {
				$expectSame([], $state['config']->reads);
			}
			$expectSame([], $state['config']->writes);
		});
	}

	// Runtime method checks complement the middleware annotations without disabling CSRF protection.
	foreach (['saveHrAccessRules', 'saveSpecialDaysCheck'] as $methodName) {
		$test('HR POST middleware contract: ' . $methodName, static function () use ($methodName, $expectSame): void {
			$method = new \ReflectionMethod(SettingsController::class, $methodName);
			$expectSame(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)));
			$expectSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class));
			$expectSame(false, str_contains($method->getDocComment() ?: '', '@NoCSRFRequired'));
		});
	}
	$test('legacy unrouted mutators are removed', static function () use ($expectSame): void {
		foreach (['saveAdmin', 'updateHrGroups', 'updateHrUserGroups'] as $methodName) {
			$expectSame(false, method_exists(SettingsController::class, $methodName));
		}
	});
	$test('settings are registered solely as native personal settings', static function () use ($expectSame): void {
		$metadata = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$expectSame('OCA\\Timesheet\\Settings\\HrSettings', (string)$metadata->settings->personal);
		$expectSame('OCA\\Timesheet\\Settings\\HrSection', (string)$metadata->settings->{'personal-section'});
		$expectSame(0, count($metadata->settings->admin));
		$expectSame(0, count($metadata->settings->{'admin-section'}));
	});
	$test('section keeps its translated name, stable identifier and app icon', static function () use ($expectSame): void {
		$section = new \OCA\Timesheet\Settings\HrSection(new SettingsTranslationFixture(), new SettingsUrlFixture());
		$expectSame('timesheet', $section->getID());
		$expectSame('Translated: Timesheet', $section->getName());
		$expectSame('/apps/timesheet/img/app_dark.svg', $section->getIcon());
	});


	$test('Legacy rules load unchanged and default to all responsible HR recipients', static function () use ($fixture, $expectSame): void {
		$state = $fixture(); $before = $state['config']->values;
		$rule = $state['provider']->getForm()->getParams()['hrAccessRules'][0];
		$expectSame([], $rule['hrUsers']);
		$expectSame(['mode' => 'all', 'userIds' => []], $rule['mailRecipients']);
		$expectSame($before, $state['config']->values);
	});
	$test('A direct enabled HR account can replace the group and receives the settings section', static function () use ($fixture, $expectSame): void {
		$state = $fixture();
		$rule = ['id' => 'direct', 'hrGroups' => [], 'hrUsers' => ['anton'], 'userGroups' => ['staff']];
		$state['request']->parameters['rules'] = json_encode([$rule]);
		$response = $state['controller']->saveHrAccessRules();
		$expectSame(200, $response->getStatus()); $expectSame(['anton'], $response->getData()['rules'][0]['hrUsers']);
		$direct = $fixture('anton', [$rule]);
		$expectSame(true, $direct['hrService']->isHr());
		$expectSame('timesheet', $direct['provider']->getSection());
		$expectSame(200, $direct['provider']->getForm()->getStatus());
		$expectSame(['staff'], $direct['hrService']->getAllowedUserGroups());
		$expectSame([['id' => 'anton', 'name' => 'Anton Beispiel', 'enabled' => true]], $direct['provider']->getForm()->getParams()['hrPeople']);
	});
	$test('Saving an older payload retains individual HR and restricted recipients by stable rule ID', static function () use ($fixture, $defaultRule, $expectSame): void {
		$rule = [...$defaultRule, 'hrUsers' => ['anton'], 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']]];
		$state = $fixture('hr', [$rule]);
		$state['request']->parameters['rules'] = json_encode([[...$defaultRule, 'maxHours' => 9]]);
		$response = $state['controller']->saveHrAccessRules();
		$expectSame(200, $response->getStatus());
		$expectSame(['anton'], $response->getData()['rules'][0]['hrUsers']);
		$expectSame(['mode' => 'selected', 'userIds' => ['anton']], $response->getData()['rules'][0]['mailRecipients']);
		$expectSame(9.0, $response->getData()['rules'][0]['maxHours']);
	});
	$test('Older payloads retain assignments when stored rule IDs contain surrounding spaces', static function () use ($fixture, $defaultRule, $expectSame): void {
		$rule = [...$defaultRule, 'id' => ' ' . $defaultRule['id'] . ' ', 'hrUsers' => ['anton'], 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']]];
		$state = $fixture('hr', [$rule]);
		$state['request']->parameters['rules'] = json_encode([$defaultRule]);
		$response = $state['controller']->saveHrAccessRules();
		$expectSame(200, $response->getStatus());
		$expectSame(['anton'], $response->getData()['rules'][0]['hrUsers']);
		$expectSame(['mode' => 'selected', 'userIds' => ['anton']], $response->getData()['rules'][0]['mailRecipients']);
	});
	$test('Empty selected recipients persist as no HR delivery instead of falling back to all', static function () use ($fixture, $defaultRule, $expectSame): void {
		$state = $fixture();
		$state['request']->parameters['rules'] = json_encode([[...$defaultRule, 'mailRecipients' => ['mode' => 'selected', 'userIds' => []]]]);
		$response = $state['controller']->saveHrAccessRules(); $expectSame(200, $response->getStatus());
		$stored = json_decode($state['config']->values['hr_access_rules'], true);
		$expectSame(['mode' => 'selected', 'userIds' => []], $stored[0]['mailRecipients']);
		$expectSame(['mode' => 'selected', 'userIds' => []], $state['provider']->getForm()->getParams()['hrAccessRules'][0]['mailRecipients']);
	});
	foreach ([
		[['hrUsers' => 'anton'], 'Invalid HR account selection.'],
		[['hrUsers' => ['unknown']], 'Invalid HR account selection.'],
		[['mailRecipients' => ['mode' => 'unknown', 'userIds' => []]], 'Invalid mail recipient settings.'],
		[['mailRecipients' => ['mode' => 'selected', 'userIds' => ['employee']]], 'Mail recipients must be responsible for this rule.'],
	] as [$changes, $message]) {
		$test('Invalid new assignments fail before enrollment or save: ' . $message . json_encode($changes), static function () use ($fixture, $defaultRule, $changes, $message, $expectSame): void {
			$state = $fixture(); $state['request']->parameters['rules'] = json_encode([[...$defaultRule, ...$changes]]);
			$response = $state['controller']->saveHrAccessRules();
			$expectSame(400, $response->getStatus()); $expectSame($message, $response->getData()['message']);
			$expectSame([], $state['config']->writes); $expectSame([], $state['reminders']->preparations);
		});
	}
	$test('A disabled direct account alone cannot lock out future HR settings', static function () use ($fixture, $expectSame): void {
		$state = $fixture(); $state['request']->parameters['rules'] = json_encode([['id' => 'disabled', 'hrGroups' => [], 'hrUsers' => ['disabled'], 'userGroups' => ['staff']]]);
		$response = $state['controller']->saveHrAccessRules();
		$expectSame(400, $response->getStatus()); $expectSame([], $state['config']->writes);
	});
	foreach ([null, 'employee', 'admin'] as $actor) {
		$test('Account search is unavailable without HR responsibility: ' . ($actor ?? 'anonymous'), static function () use ($fixture, $actor, $expectSame): void {
			$state = $fixture($actor); $state['request']->parameters = ['search' => 'Anton'];
			$expectSame(403, $state['controller']->searchPeople()->getStatus()); $expectSame(0, $state['users']->searches);
		});
	}
	$test('Account search finds names and IDs and returns only the documented display fields', static function () use ($fixture, $expectSame): void {
		$state = $fixture(); $state['request']->parameters = ['search' => 'ANTON'];
		$response = $state['controller']->searchPeople();
		$expectSame(200, $response->getStatus()); $expectSame(['anton', 'other-anton'], array_column($response->getData()['users'], 'id'));
		$expectSame(['id', 'name', 'enabled'], array_keys($response->getData()['users'][0]));
		$expectSame(false, $response->getData()['hasMore']);
		$state['request']->parameters = ['search' => 'disabled'];
		$expectSame([], $state['controller']->searchPeople()->getData()['users']);
	});
	$test('Mail search is limited to proposed responsible accounts and includes direct HR', static function () use ($fixture, $expectSame): void {
		$state = $fixture(); $state['request']->parameters = ['purpose' => 'mail', 'hrGroups' => ['HR'], 'hrUsers' => ['anton']];
		$ids = array_column($state['controller']->searchPeople()->getData()['users'], 'id'); sort($ids);
		$expectSame(['anton', 'hr', 'hr-admin'], $ids);
		$state['request']->parameters = ['purpose' => 'mail'];
		$expectSame([], $state['controller']->searchPeople()->getData()['users']);
	});
	$test('Directory results are bounded and short searches do not enumerate the directory', static function () use ($fixture, $expectSame): void {
		$state = $fixture(); $state['request']->parameters = ['search' => 'A'];
		$expectSame(['users' => [], 'hasMore' => false], $state['controller']->searchPeople()->getData());
		$expectSame(0, $state['users']->searches);
		for ($index = 0; $index < 25; $index++) $state['users']->users['many-' . $index] = new SettingsUserFixture('many-' . $index, 'Many accounts');
		$state['request']->parameters = ['search' => 'Many'];
		$result = $state['controller']->searchPeople()->getData();
		$expectSame(20, count($result['users'])); $expectSame(true, $result['hasMore']);
	});
	$test('Directory failures return a generic error without changing settings', static function () use ($fixture, $expectSame): void {
		$state = $fixture(); $state['users']->rejectSearch = true; $state['request']->parameters = ['search' => 'Anton'];
		$response = $state['controller']->searchPeople();
		$expectSame(500, $response->getStatus()); $expectSame(['message' => 'Accounts could not be loaded. Try again.'], $response->getData());
		$expectSame([], $state['config']->writes);
	});
	$test('Account search is a read-only HR route while all settings writes retain CSRF', static function () use ($expectSame): void {
		$method = new \ReflectionMethod(SettingsController::class, 'searchPeople');
		$expectSame(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)));
		$expectSame(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class)));
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		$matches = array_values(array_filter($routes['routes'], static fn(array $route): bool => $route['name'] === 'settings#searchPeople'));
		$expectSame([['name' => 'settings#searchPeople', 'url' => '/api/hr/people', 'verb' => 'GET']], $matches);
	});

	fwrite(STDOUT, 'HR settings: ' . $checks . ' checks, ' . $failures . " failures\n");
	exit($failures === 0 ? 0 : 1);
}
