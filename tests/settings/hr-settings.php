<?php

declare(strict_types=1);

// Isolated Nextcloud doubles keep these permission checks independent of a running instance.
namespace OCP {
	interface IRequest {
		public function getParam(string $key, mixed $default = null): mixed;
	}
	interface IAppConfig {
		public function clearCache(bool $reload = false): void;
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
		public static array $styles = [];
		public static function addScript(string $app, string $name): void {
			self::$scripts[] = [$app, $name];
		}
		public static function addStyle(string $app, string $name): void {
			self::$styles[] = [$app, $name];
		}
	}
}

namespace OCP\Lock {
	interface ILockingProvider {
		public const LOCK_EXCLUSIVE = 2;
		public function acquireLock(string $path, int $type): void;
		public function releaseLock(string $path, int $type): void;
	}
	class LockedException extends \RuntimeException {
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
	require_once __DIR__ . '/../../lib/Service/EmployeeBalanceNotificationSettings.php';
	require_once __DIR__ . '/../../lib/Controller/SettingsController.php';
	$settingsFile = __DIR__ . '/../../lib/Settings/HrSettings.php';
	$sectionFile = __DIR__ . '/../../lib/Settings/HrSection.php';
	require_once $settingsFile;
	require_once $sectionFile;
	require_once __DIR__ . '/../../lib/Settings/RuleSettingsForm.php';
	require_once __DIR__ . '/../../lib/Settings/AdminSettings.php';
	require_once __DIR__ . '/../../lib/Settings/AdminSection.php';

	class SettingsConfigFixture implements \OCP\AppFramework\Services\IAppConfig, \OCP\IAppConfig {
		public array $reads = [];
		public array $writes = [];
		public bool $rejectWrites = false;
		public bool $rejectReads = false;
		public ?array $cachedValues = null;
		public int $cacheClears = 0;
		public ?\Closure $beforeWrite = null;
		public ?\Closure $beforeClearCache = null;
		public ?SettingsLockFixture $locks = null;
		public function __construct(public array $values) {
		}
		public function getAppValueString(string $key, string $default = ''): string {
			$this->reads[] = $key;
			if ($this->rejectReads) throw new \RuntimeException('Private configuration read diagnostic.');
			return ($this->cachedValues ?? $this->values)[$key] ?? $default;
		}
		public function setAppValueString(string $key, string $value): void {
			if ($this->rejectWrites) {
				throw new \RuntimeException('Private configuration diagnostic.');
			}
			if ($key === 'hr_access_rules' && !isset($this->locks?->held[hash('sha256', 'timesheet:hr-access-rules')])) {
				throw new \RuntimeException('The shared rules lock is required.');
			}
			if ($this->beforeWrite) ($this->beforeWrite)();
			$this->writes[] = [$key, $value];
			$this->values[$key] = $value;
		}
		public function clearCache(bool $reload = false): void {
			if ($this->beforeClearCache) ($this->beforeClearCache)();
			$this->cacheClears++;
			$this->cachedValues = null;
		}
	}
	class SettingsLockFixture implements \OCP\Lock\ILockingProvider {
		public array $held = [];
		public int $acquisitions = 0;
		public int $releases = 0;
		public bool $rejectLocks = false;
		public function acquireLock(string $path, int $type): void {
			if ($this->rejectLocks) throw new \RuntimeException('Private lock diagnostic.');
			if (isset($this->held[$path])) throw new \OCP\Lock\LockedException('Busy settings.');
			if ($type !== self::LOCK_EXCLUSIVE || strlen($path) !== 64) throw new \RuntimeException('Invalid settings lock.');
			$this->held[$path] = true;
			$this->acquisitions++;
		}
		public function releaseLock(string $path, int $type): void {
			unset($this->held[$path]);
			$this->releases++;
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
	$fixture = static function (?string $userId = 'hr', ?array $initialRules = null) use ($defaultRule): array {
		$config = new SettingsConfigFixture(['hr_access_rules' => json_encode($initialRules ?? [$defaultRule]), 'specialdays_check' => '0']);
		$groups = new SettingsGroupsFixture();
		$session = new SettingsSessionFixture($userId === null ? null : new SettingsUserFixture($userId));
		$users = new SettingsUsersFixture();
		$hrService = new HrService($config, $session, $groups, $users);
		$request = new SettingsRequestFixture();
		$balances = new MonthlyBalanceService();
		$reminders = new MissingEntryReminderService();
		$locks = new SettingsLockFixture();
		$config->locks = $locks;
		$controller = new SettingsController('timesheet', $request, $config, $groups, $session, $balances, $reminders, new SettingsTimeFixture(), $hrService, $users, $config, $locks);
		$form = new \OCA\Timesheet\Settings\RuleSettingsForm($config, $groups, 'timesheet', $users);
		$provider = new \OCA\Timesheet\Settings\HrSettings($form, $hrService);
		$adminProvider = new \OCA\Timesheet\Settings\AdminSettings($form, $session, $groups);
		$config->reads = [];
		\OCP\Util::$scripts = [];
		\OCP\Util::$styles = [];
		return compact('config', 'users', 'groups', 'session', 'hrService', 'request', 'balances', 'reminders', 'locks', 'controller', 'form', 'provider', 'adminProvider');
	};
	$save = static function (array $state, mixed $rules, string $scope = 'policy', ?string $revision = null, mixed $policySection = null) {
		$state['request']->parameters = ['rules' => is_string($rules) ? $rules : json_encode($rules), 'rulesRevision' => $revision ?? hash('sha256', $state['config']->values['hr_access_rules'])];
		if ($policySection !== null) $state['request']->parameters['policySection'] = $policySection;
		return $state['controller']->{$scope === 'assignments' ? 'saveHrAccessRules' : 'saveHrPolicyRules'}();
	};
	$storedRules = static fn(array $state): array => json_decode($state['config']->values['hr_access_rules'], true);

	foreach ([null, 'employee', 'admin', 'hr', 'hr-admin'] as $actor) {
		$isHr = in_array($actor, ['hr', 'hr-admin'], true);
		$isAdmin = in_array($actor, ['admin', 'hr-admin'], true);
		foreach (['policy' => $isHr, 'assignments' => $isAdmin] as $scope => $allowed) {
			$test($scope . ' form and role: ' . ($actor ?? 'anonymous'), static function () use ($fixture, $save, $defaultRule, $scope, $allowed, $actor, $expectSame): void {
				$state = $fixture($actor);
				$provider = $state[$scope === 'assignments' ? 'adminProvider' : 'provider'];
				$expectSame($allowed ? 'timesheet' : null, $provider->getSection());
				$form = $provider->getForm();
				$expectSame($allowed ? 200 : 403, $form->getStatus());
				if ($allowed) {
					$expectSame('settings-hr', $form->getTemplateName());
					$expectSame($scope, $form->getParams()['settingsScope']);
					$expectSame(hash('sha256', $state['config']->values['hr_access_rules']), $form->getParams()['rulesRevision']);
					$expectSame('rule-a', $form->getParams()['hrAccessRules'][0]['id']);
					$expectSame([['timesheet', 'hr-settings']], \OCP\Util::$scripts);
					$expectSame([['timesheet', 'hr-settings']], \OCP\Util::$styles);
					$expectSame($scope === 'policy' ? 'timeRules' : null, $form->getParams()['policySection']);
				} else {
					$expectSame([], $form->getParams());
					$expectSame([], $state['config']->reads);
					$expectSame([], \OCP\Util::$scripts);
				}
				$expectSame($allowed ? 200 : 403, $save($state, [$defaultRule], $scope)->getStatus());
				if (!$allowed) {
					$expectSame([], $state['config']->writes);
					$expectSame([], $state['reminders']->preparations);
				}
			});
		}
		$test('Special-day write remains HR-only: ' . ($actor ?? 'anonymous'), static function () use ($fixture, $actor, $isHr, $expectSame): void {
			$state = $fixture($actor); $state['request']->parameters = ['specialDaysCheck' => 'true'];
			$expectSame($isHr ? 200 : 403, $state['controller']->saveSpecialDaysCheck()->getStatus());
			$expectSame($isHr ? 1 : 0, $state['balances']->invalidations);
			$expectSame($isHr ? '1' : '0', $state['config']->values['specialdays_check']);
		});
		$test('Special-day read remains signed-in: ' . ($actor ?? 'anonymous'), static function () use ($fixture, $actor, $expectSame): void {
			$state = $fixture($actor);
			$expectSame($actor === null ? 401 : 200, $state['controller']->loadSpecialDaysCheck()->getStatus());
		});
	}
	$test('The native metadata registers both personal HR and administrator sections', static function () use ($expectSame): void {
		$metadata = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$expectSame('OCA\\Timesheet\\Settings\\HrSettings', (string)$metadata->settings->personal);
		$expectSame('OCA\\Timesheet\\Settings\\HrSection', (string)$metadata->settings->{'personal-section'});
		$expectSame('OCA\\Timesheet\\Settings\\AdminSettings', (string)$metadata->settings->admin);
		$expectSame('OCA\\Timesheet\\Settings\\AdminSection', (string)$metadata->settings->{'admin-section'});
		foreach ([\OCA\Timesheet\Settings\HrSection::class, \OCA\Timesheet\Settings\AdminSection::class] as $sectionClass) {
			$section = new $sectionClass(new SettingsTranslationFixture(), new SettingsUrlFixture());
			$expectSame('timesheet', $section->getID());
			$expectSame('Translated: Timesheet', $section->getName());
			$expectSame('/apps/timesheet/img/app_dark.svg', $section->getIcon());
		}
	});
	$test('Assignments retain default administrator middleware while policy writes keep the HR exception and CSRF', static function () use ($expectSame): void {
		foreach (['saveHrAccessRules' => 0, 'saveHrPolicyRules' => 1, 'saveSpecialDaysCheck' => 1] as $name => $adminException) {
			$method = new \ReflectionMethod(SettingsController::class, $name);
			$expectSame($adminException, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)));
			$expectSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class));
		}
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		$names = array_column($routes['routes'], 'url', 'name');
		$expectSame('/settings/hr_access_rules', $names['settings#saveHrAccessRules']);
		$expectSame('/settings/hr_policy_rules', $names['settings#saveHrPolicyRules']);
	});
	$test('Opening either form retains all stored rules without writes', static function () use ($fixture, $expectSame): void {
		$state = $fixture('hr-admin'); $before = $state['config']->values;
		$state['provider']->getForm(); $state['adminProvider']->getForm();
		$expectSame($before, $state['config']->values); $expectSame([], $state['config']->writes);
	});
	$test('An administrator without HR can bootstrap a draft rule and remove all rules later', static function () use ($fixture, $save, $storedRules, $expectSame): void {
		$state = $fixture('admin', []);
		$created = $save($state, [['id' => 'new-rule', 'hrGroups' => [], 'userGroups' => [], 'maxHours' => 2, 'missingEntryReminders' => ['enabled' => true]]], 'assignments');
		$expectSame(200, $created->getStatus());
		$expectSame(false, $created->getData()['rules'][0]['missingEntryReminders']['enabled']);
		$expectSame(10.0, $created->getData()['rules'][0]['maxHours']);
		$expectSame(200, $save($state, [], 'assignments')->getStatus());
		$expectSame([], $storedRules($state));
	});
	$test('Admin changes assignments and priority while preserving every HR-controlled value', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$original = [...$defaultRule, 'hrUsers' => ['anton'], 'priority' => 3, 'maxHours' => 7.5, 'breakShortMinutes' => 20, 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']], 'missingEntryReminders' => ['enabled' => true, 'graceDays' => 3, 'weekdays' => [2, 4], 'startDate' => '2026-10-01']];
		$state = $fixture('admin', [$original]);
		$before = $state['adminProvider']->getForm()->getParams()['hrAccessRules'][0];
		$submitted = [...$original, 'hrGroups' => ['HR backup'], 'hrUsers' => [], 'priority' => 9, 'maxHours' => 1, 'mailRecipients' => ['mode' => 'all', 'userIds' => []], 'missingEntryReminders' => ['enabled' => false]];
		$response = $save($state, [$submitted], 'assignments'); $expectSame(200, $response->getStatus());
		$saved = $response->getData()['rules'][0];
		foreach (['maxHours', 'breakShortMinutes', 'breakShortHours', 'breakLongMinutes', 'breakLongHours', 'mailRecipients', 'missingEntryReminders'] as $field) $expectSame($before[$field], $saved[$field]);
		$expectSame(['HR backup'], $saved['hrGroups']); $expectSame([], $saved['hrUsers']); $expectSame(9, $saved['priority']);
		$expectSame(['mode' => 'selected', 'userIds' => ['anton']], $saved['mailRecipients']);
		$expectSame([], (new \OCA\Timesheet\Service\HrRuleAssignments($state['groups'], $state['users']))->getMailRecipientUserIds($saved));
		$expectSame(hash('sha256', $state['config']->values['hr_access_rules']), $response->getData()['rulesRevision']);
	});
	$test('HR ignores assignment, priority and ordering tampering while saving policy values', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$second = ['id' => 'rule-b', 'hrGroups' => ['HR backup'], 'userGroups' => []];
		$state = $fixture('hr', [$defaultRule, $second]);
		$response = $save($state, [[...$second, 'priority' => 0, 'maxHours' => 8], [...$defaultRule, 'hrGroups' => ['admin'], 'hrUsers' => ['employee'], 'userGroups' => ['admin'], 'priority' => 99, 'maxHours' => 9]]);
		$expectSame(200, $response->getStatus()); [$first, $last] = $response->getData()['rules'];
		$expectSame('rule-a', $first['id']); $expectSame(['HR'], $first['hrGroups']); $expectSame([], $first['hrUsers']); $expectSame(['staff'], $first['userGroups']); $expectSame(1, $first['priority']); $expectSame(9.0, $first['maxHours']);
		$expectSame('rule-b', $last['id']); $expectSame(8.0, $last['maxHours']);
	});
	foreach ([[], [['id' => 'new-rule']], [['id' => 'rule-a'], ['id' => 'new-rule']]] as $rules) {
		$test('HR cannot create or delete rule identities: ' . json_encode($rules), static function () use ($fixture, $save, $rules, $expectSame): void {
			$state = $fixture(); $before = $state['config']->values;
			$expectSame(400, $save($state, $rules)->getStatus()); $expectSame($before, $state['config']->values); $expectSame([], $state['reminders']->preparations);
		});
	}
	$test('A direct HR account can edit policy but cannot assign responsibility', static function () use ($fixture, $save, $expectSame): void {
		$rule = ['id' => 'direct', 'hrGroups' => [], 'hrUsers' => ['anton'], 'userGroups' => ['staff']];
		$state = $fixture('anton', [$rule]); $expectSame('timesheet', $state['provider']->getSection());
		$expectSame(200, $save($state, [[...$rule, 'maxHours' => 9]])->getStatus());
		$expectSame(403, $save($state, [$rule], 'assignments')->getStatus());
	});
	$test('HR can set selected or empty recipients without broadening mail delivery', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$rule = [...$defaultRule, 'hrUsers' => ['anton']]; $state = $fixture('hr', [$rule]);
		foreach ([['anton'], []] as $ids) {
			$response = $save($state, [[...$rule, 'mailRecipients' => ['mode' => 'selected', 'userIds' => $ids]]]);
			$expectSame(200, $response->getStatus()); $expectSame(['mode' => 'selected', 'userIds' => $ids], $response->getData()['rules'][0]['mailRecipients']);
		}
	});
	$test('Older HR payloads preserve direct assignments and restricted recipients', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$rule = [...$defaultRule, 'hrUsers' => ['anton'], 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']]];
		$state = $fixture('hr', [$rule]); $response = $save($state, [[...$defaultRule, 'maxHours' => 9]]);
		$expectSame(200, $response->getStatus()); $expectSame(['anton'], $response->getData()['rules'][0]['hrUsers']); $expectSame($rule['mailRecipients'], $response->getData()['rules'][0]['mailRecipients']);
	});
	$test('An unchanged restricted mail selection remains restricted after responsibility was removed', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$rule = [...$defaultRule, 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']]];
		$state = $fixture('hr', [$rule]); $response = $save($state, [[...$rule, 'maxHours' => 9]]);
		$expectSame(200, $response->getStatus()); $expectSame($rule['mailRecipients'], $response->getData()['rules'][0]['mailRecipients']);
	});
	foreach ([null, '', 'invalid', str_repeat('g', 64), 14, []] as $invalidRevision) {
		$test('Missing or invalid revisions are rejected: ' . json_encode($invalidRevision), static function () use ($fixture, $defaultRule, $invalidRevision, $expectSame): void {
			$state = $fixture('admin'); $state['request']->parameters = ['rules' => json_encode([$defaultRule]), 'rulesRevision' => $invalidRevision];
			$expectSame(400, $state['controller']->saveHrAccessRules()->getStatus()); $expectSame(0, $state['locks']->acquisitions); $expectSame([], $state['config']->writes);
		});
	}
	$test('A stale HR page cannot overwrite an intervening administrator assignment', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$state = $fixture('hr'); $oldRevision = hash('sha256', $state['config']->values['hr_access_rules']);
		$updated = [...$defaultRule, 'hrUsers' => ['anton'], 'priority' => 2]; $state['config']->values['hr_access_rules'] = json_encode([$updated]);
		$state['config']->cachedValues = ['hr_access_rules' => json_encode([$defaultRule])];
		$response = $save($state, [[...$defaultRule, 'maxHours' => 9]], 'policy', $oldRevision);
		$expectSame(409, $response->getStatus()); $expectSame('rules_conflict', $response->getData()['code']); $expectSame(['anton'], $response->getData()['rules'][0]['hrUsers']);
		$expectSame(hash('sha256', $state['config']->values['hr_access_rules']), $response->getData()['rulesRevision']); $expectSame([], $state['config']->writes); $expectSame([], $state['locks']->held);
	});
	$test('A stale administrator page cannot overwrite an intervening HR policy', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$state = $fixture('admin'); $oldRevision = hash('sha256', $state['config']->values['hr_access_rules']);
		$state['config']->values['hr_access_rules'] = json_encode([[...$defaultRule, 'maxHours' => 7]]);
		$response = $save($state, [$defaultRule], 'assignments', $oldRevision);
		$expectSame(409, $response->getStatus()); $expectSame(7.0, $response->getData()['rules'][0]['maxHours']); $expectSame([], $state['config']->writes);
	});
	$test('Permission is resolved again from current rules after the shared lock is acquired', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$state = $fixture('hr');
		$replacementRule = ['id' => 'rule-a', 'hrGroups' => ['HR backup'], 'userGroups' => ['staff']];
		$state['config']->beforeClearCache = static function () use ($state, $replacementRule): void { $state['config']->values['hr_access_rules'] = json_encode([$replacementRule]); };
		$response = $save($state, [$defaultRule]);
		$expectSame(403, $response->getStatus()); $expectSame(['message' => 'Forbidden'], $response->getData()); $expectSame([], $state['config']->writes); $expectSame([], $state['locks']->held);
	});
	$test('Concurrent writers share one lock and a busy writer receives a current conflict', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$state = $fixture('hr-admin'); $otherStatus = null;
		$state['config']->beforeWrite = static function () use ($state, $save, $defaultRule, &$otherStatus): void { $otherStatus = $save($state, [$defaultRule], 'policy')->getStatus(); };
		$expectSame(200, $save($state, [$defaultRule], 'assignments')->getStatus()); $expectSame(409, $otherStatus); $expectSame(1, count($state['config']->writes)); $expectSame(1, $state['locks']->acquisitions); $expectSame(1, $state['locks']->releases); $expectSame([], $state['locks']->held);
	});
	foreach (['read', 'write', 'enrollment', 'lock'] as $failure) {
		$test('Failed ' . $failure . ' has no saved settings and releases its own lock', static function () use ($fixture, $save, $defaultRule, $failure, $expectSame): void {
			$state = $fixture('admin'); $state['config']->rejectReads = $failure === 'read'; $state['config']->rejectWrites = $failure === 'write'; $state['reminders']->rejectPreparation = $failure === 'enrollment'; $state['locks']->rejectLocks = $failure === 'lock';
			$response = $save($state, [$defaultRule], 'assignments'); $expectSame(500, $response->getStatus()); $expectSame(['message' => 'Saving reminder settings failed'], $response->getData()); $expectSame([], $state['config']->writes); $expectSame([], $state['locks']->held);
		});
	}
	foreach ([['not json', 'Invalid rules'], ['null', 'Invalid rules'], ['{}', 'Invalid rules'], [[['id' => 'a'], ['id' => 'a']], 'Duplicate rule identifiers'], [[['id' => 'a', 'userGroups' => ['staff']], ['id' => 'b', 'userGroups' => ['staff']]], 'Duplicate employee groups in rules'], [[['id' => 'a', 'hrUsers' => 'anton']], 'Invalid HR account selection.'], [[['id' => 'a', 'hrUsers' => ['unknown']]], 'Invalid HR account selection.']] as [$rules, $message]) {
		$test('Administrator validation remains intact: ' . $message . json_encode($rules), static function () use ($fixture, $save, $rules, $message, $expectSame): void {
			$state = $fixture('admin'); $response = $save($state, $rules, 'assignments'); $expectSame(400, $response->getStatus()); $expectSame($message, $response->getData()['message']); $expectSame([], $state['config']->writes);
		});
	}
	foreach ([['missingEntryReminders' => ['graceDays' => 0]], ['missingEntryReminders' => ['enabled' => 'yes']], ['missingEntryReminders' => ['weekdays' => [0]]], ['missingEntryReminders' => ['startDate' => '2026-02-30']], ['mailRecipients' => ['mode' => 'unknown']], ['mailRecipients' => ['mode' => 'selected', 'userIds' => ['employee']]]] as $invalid) {
		$test('HR policy validation rejects invalid values: ' . json_encode($invalid), static function () use ($fixture, $save, $defaultRule, $invalid, $expectSame): void {
			$state = $fixture(); $response = $save($state, [[...$defaultRule, ...$invalid]]); $expectSame(400, $response->getStatus()); $expectSame([], $state['config']->writes); $expectSame([], $state['reminders']->preparations);
		});
	}
	$test('Enabling reminders retains validation and prepares enrollment before writing', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$state = $fixture(); $state['config']->beforeWrite = static function () use ($state, $expectSame): void { $expectSame(1, count($state['reminders']->preparations)); };
		$response = $save($state, [[...$defaultRule, 'missingEntryReminders' => ['enabled' => true]]]); $expectSame(200, $response->getStatus());
		$expectSame(['enabled' => true, 'notifyEmployees' => true, 'notifyHr' => true, 'graceDays' => 14, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => '2026-11-02'], $response->getData()['rules'][0]['missingEntryReminders']);
	});
	foreach ([false, true] as $notifyEmployees) {
		foreach ([false, true] as $notifyHr) {
			$test('HR saves independent reminder recipients: ' . json_encode([$notifyEmployees, $notifyHr]), static function () use ($fixture, $save, $defaultRule, $notifyEmployees, $notifyHr, $expectSame): void {
				$state = $fixture();
				$response = $save($state, [[...$defaultRule, 'missingEntryReminders' => ['notifyEmployees' => $notifyEmployees, 'notifyHr' => $notifyHr]]]);
				$expectSame(200, $response->getStatus());
				$saved = $response->getData()['rules'][0]['missingEntryReminders'];
				$expectSame($notifyEmployees, $saved['notifyEmployees']);
				$expectSame($notifyHr, $saved['notifyHr']);
				$expectSame($notifyEmployees || $notifyHr, $saved['enabled']);
				$reloaded = $state['provider']->getForm()->getParams()['hrAccessRules'][0]['missingEntryReminders'];
				$expectSame($saved, $reloaded);
			});
		}
	}
	foreach ([null, 'employee', 'hr', 'admin', 'hr-admin'] as $actor) {
		$test('Directory responsibility search is administrator-only: ' . ($actor ?? 'anonymous'), static function () use ($fixture, $actor, $expectSame): void {
			$state = $fixture($actor); $state['request']->parameters = ['search' => 'Anton', 'purpose' => 'responsible']; $response = $state['controller']->searchPeople();
			$expectSame(in_array($actor, ['admin', 'hr-admin'], true) ? 200 : 403, $response->getStatus());
			if ($response->getStatus() === 200) $expectSame(['anton', 'other-anton'], array_column($response->getData()['users'], 'id')); else $expectSame(0, $state['users']->searches);
		});
	}
	$test('Mail search uses stored rule responsibility and ignores proposed groups and accounts', static function () use ($fixture, $defaultRule, $expectSame): void {
		$state = $fixture('hr', [[...$defaultRule, 'hrUsers' => ['anton']]]); $state['request']->parameters = ['purpose' => 'mail', 'ruleId' => 'rule-a', 'hrGroups' => ['admin'], 'hrUsers' => ['employee']];
		$response = $state['controller']->searchPeople(); $expectSame(200, $response->getStatus()); $ids = array_column($response->getData()['users'], 'id'); sort($ids); $expectSame(['anton', 'hr', 'hr-admin'], $ids); $expectSame(0, $state['users']->searches);
		foreach ([null, '', 'unknown'] as $ruleId) { $state['request']->parameters['ruleId'] = $ruleId; $expectSame(400, $state['controller']->searchPeople()->getStatus()); }
	});
	$test('Mail search matches available name and account prefixes within the selected rule', static function () use ($fixture, $defaultRule, $expectSame): void {
		$state = $fixture('hr', [
			[...$defaultRule, 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['tara']]],
			[...$defaultRule, 'id' => 'rule-b', 'hrGroups' => ['HR backup']],
		]);
		foreach ([
			'taskin' => ['Taskin Müller', true, 'HR'],
			'alex' => ['Alex Taylor', true, 'HR'],
			'team-a' => ['Andrea Test', true, 'HR'],
			'tara' => ['Tara Zorn', true, 'HR'],
			'disabled' => ['Tanja Grau', false, 'HR'],
			'osman' => ['Osman Otay', true, 'HR'],
			'other' => ['Tabea Wenig', true, 'HR backup'],
		] as $id => [$name, $enabled, $group]) {
			$state['users']->users[$id] = new SettingsUserFixture($id, $name, $enabled);
			$state['groups']->memberships[$id] = [$group];
		}
		$state['request']->parameters = ['purpose' => 'mail', 'ruleId' => 'rule-a', 'search' => 'tA'];
		$expectSame(['alex', 'taskin'], array_column($state['controller']->searchPeople()->getData()['users'], 'id'));
		$state['request']->parameters['search'] = 'TEAM-';
		$expectSame(['team-a'], array_column($state['controller']->searchPeople()->getData()['users'], 'id'));
	});
	$test('Directory searches stay bounded, read-only, exclude disabled accounts and hide private failures', static function () use ($fixture, $expectSame): void {
		$state = $fixture('admin'); $state['request']->parameters = ['search' => 'A']; $expectSame(['users' => [], 'hasMore' => false], $state['controller']->searchPeople()->getData()); $expectSame(0, $state['users']->searches);
		for ($index = 0; $index < 25; $index++) $state['users']->users['many-' . $index] = new SettingsUserFixture('many-' . $index, 'Many accounts');
		$state['request']->parameters = ['search' => 'Many']; $result = $state['controller']->searchPeople()->getData(); $expectSame(20, count($result['users'])); $expectSame(true, $result['hasMore']);
		$state['request']->parameters = ['search' => 'disabled']; $expectSame([], $state['controller']->searchPeople()->getData()['users']);
		$state['users']->rejectSearch = true; $response = $state['controller']->searchPeople(); $expectSame(500, $response->getStatus()); $expectSame(['message' => 'Accounts could not be loaded. Try again.'], $response->getData()); $expectSame([], $state['config']->writes);
	});
	$test('The read-only search route retains its existing URL and annotations', static function () use ($expectSame): void {
		$method = new \ReflectionMethod(SettingsController::class, 'searchPeople'); $expectSame(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class))); $expectSame(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class)));
		$routes = require __DIR__ . '/../../appinfo/routes.php'; $matches = array_values(array_filter($routes['routes'], static fn(array $route): bool => $route['name'] === 'settings#searchPeople')); $expectSame([['name' => 'settings#searchPeople', 'url' => '/api/hr/people', 'verb' => 'GET']], $matches);
	});

	$employeeBalanceDefaults = \OCA\Timesheet\Service\EmployeeBalanceNotificationSettings::normalize(null);
	$test('Existing rules expose disabled employee balance defaults without writing', static function () use ($fixture, $expectSame, $employeeBalanceDefaults): void {
		$state = $fixture('hr-admin');
		foreach (['provider', 'adminProvider'] as $provider) {
			$expectSame($employeeBalanceDefaults, $state[$provider]->getForm()->getParams()['hrAccessRules'][0]['employeeBalanceNotifications']);
		}
		$expectSame([], $state['config']->writes);
	});
	$test('HR can save separate employee balance channels and reload them', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$state = $fixture();
		foreach ([[true, false, 0, 900], [false, true, 1200, 600], [false, false, 60, 120]] as [$positive, $negative, $positiveMinutes, $negativeMinutes]) {
			$settings = ['overtimeEnabled' => $positive, 'overtimeThresholdMinutes' => $positiveMinutes, 'negativeOvertimeEnabled' => $negative, 'negativeOvertimeThresholdMinutes' => $negativeMinutes];
			$response = $save($state, [[...$defaultRule, 'employeeBalanceNotifications' => $settings]]);
			$expectSame(200, $response->getStatus());
			$expectSame($settings, $response->getData()['rules'][0]['employeeBalanceNotifications']);
			$expectSame($settings, $state['provider']->getForm()->getParams()['hrAccessRules'][0]['employeeBalanceNotifications']);
		}
	});
	$test('Old HR payloads retain the saved employee balance channels by rule identity', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$settings = ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 300, 'negativeOvertimeEnabled' => false, 'negativeOvertimeThresholdMinutes' => 1200];
		$state = $fixture('hr', [[...$defaultRule, 'employeeBalanceNotifications' => $settings]]);
		$response = $save($state, [[...$defaultRule, 'maxHours' => 9]]);
		$expectSame(200, $response->getStatus()); $expectSame($settings, $response->getData()['rules'][0]['employeeBalanceNotifications']);
	});
	$test('Admin assignment changes cannot alter existing employee balance policy', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$settings = ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 300, 'negativeOvertimeEnabled' => true, 'negativeOvertimeThresholdMinutes' => 1200];
		$state = $fixture('admin', [[...$defaultRule, 'employeeBalanceNotifications' => $settings]]);
		$response = $save($state, [[...$defaultRule, 'hrUsers' => ['anton'], 'employeeBalanceNotifications' => ['overtimeEnabled' => false]]], 'assignments');
		$expectSame(200, $response->getStatus()); $expectSame($settings, $response->getData()['rules'][0]['employeeBalanceNotifications']);
	});
	$test('New admin-created rules ignore posted employee mail choices and remain disabled', static function () use ($fixture, $save, $expectSame, $employeeBalanceDefaults): void {
		$state = $fixture('admin', []);
		$response = $save($state, [['id' => 'new-rule', 'hrGroups' => [], 'userGroups' => [], 'employeeBalanceNotifications' => ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 0]]], 'assignments');
		$expectSame(200, $response->getStatus()); $expectSame($employeeBalanceDefaults, $response->getData()['rules'][0]['employeeBalanceNotifications']);
	});
	foreach ([true, ['overtimeEnabled' => 'yes'], ['negativeOvertimeEnabled' => 1], ['overtimeThresholdMinutes' => -1], ['negativeOvertimeThresholdMinutes' => 525601], ['overtimeThresholdMinutes' => '600'], ['overtimeThresholdMinutes' => 1.5], ['negativeOvertimeThresholdMinutes' => null]] as $invalidSettings) {
		$test('Invalid employee balance policy cannot write or prepare reminders: ' . json_encode($invalidSettings), static function () use ($fixture, $save, $defaultRule, $expectSame, $invalidSettings): void {
			$state = $fixture(); $before = $state['config']->values;
			$response = $save($state, [[...$defaultRule, 'employeeBalanceNotifications' => $invalidSettings]]);
			$expectSame(400, $response->getStatus()); $expectSame($before, $state['config']->values); $expectSame([], $state['reminders']->preparations);
		});
	}

	$test('Embedded notifications reuse the profile snapshot without loading groups, assets or writing', static function () use ($fixture, $expectSame): void {
		$state = $fixture();
		$data = $state['form']->getData('notifications');
		$expectSame('policy', $data['settingsScope']);
		$expectSame('notifications', $data['policySection']);
		$expectSame([], $data['allGroups']);
		$expectSame(0, $state['groups']->searches);
		$expectSame([], \OCP\Util::$scripts);
		$expectSame([], \OCP\Util::$styles);
		$profile = $state['provider']->getForm()->getParams();
		foreach (['rulesRevision', 'hrAccessRules', 'hrPeople'] as $key) $expectSame($profile[$key], $data[$key]);
		$expectSame([], $state['config']->writes);
	});
	foreach (['', 'all', 'policy', true, 0, [], ['notifications']] as $invalidSection) {
		$test('Unknown policy sections fail before locking: ' . json_encode($invalidSection), static function () use ($fixture, $save, $defaultRule, $invalidSection, $expectSame): void {
			$state = $fixture();
			$response = $save($state, [$defaultRule], 'policy', null, $invalidSection);
			$expectSame(400, $response->getStatus());
			$expectSame(['message' => 'Invalid policy section'], $response->getData());
			$expectSame(0, $state['locks']->acquisitions);
			$expectSame([], $state['config']->writes);
			$expectSame([], $state['reminders']->preparations);
		});
	}
	foreach (['timeRules', 'notifications'] as $section) {
		foreach ([null, 'employee', 'admin', 'hr', 'hr-admin'] as $actor) {
			$test($section . ' writer remains HR-only: ' . ($actor ?? 'anonymous'), static function () use ($fixture, $save, $defaultRule, $section, $actor, $expectSame): void {
				$state = $fixture($actor);
				$response = $save($state, [$defaultRule], 'policy', null, $section);
				$expectSame(in_array($actor, ['hr', 'hr-admin'], true) ? 200 : 403, $response->getStatus());
				if ($response->getStatus() === 403) $expectSame([], $state['config']->writes);
			});
		}
	}
	$test('Time rules cannot replace reminders, balance mail or recipients even with invalid submitted values', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$original = [...$defaultRule, 'hrUsers' => ['anton'], 'maxHours' => 7.5, 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']], 'missingEntryReminders' => ['enabled' => true, 'graceDays' => 5, 'weekdays' => [1, 3], 'startDate' => '2026-10-01'], 'employeeBalanceNotifications' => ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 420]];
		$state = $fixture('hr', [$original]);
		$before = $state['form']->getData('notifications')['hrAccessRules'][0];
		$submitted = [...$before, 'maxHours' => 9, 'hrUsers' => [], 'priority' => 999, 'missingEntryReminders' => ['enabled' => 'invalid'], 'employeeBalanceNotifications' => true, 'mailRecipients' => ['mode' => 'invalid']];
		$response = $save($state, [$submitted], 'policy', null, 'timeRules');
		$expectSame(200, $response->getStatus());
		$saved = $response->getData()['rules'][0];
		$expectSame(9.0, $saved['maxHours']);
		foreach (['missingEntryReminders', 'employeeBalanceNotifications', 'mailRecipients', 'hrUsers', 'priority'] as $field) $expectSame($before[$field], $saved[$field]);
	});
	$test('Notifications save only their fields while retaining time rules and protected assignments', static function () use ($fixture, $save, $defaultRule, $expectSame): void {
		$original = [...$defaultRule, 'hrUsers' => ['anton'], 'maxHours' => 7.5, 'breakShortMinutes' => 20, 'priority' => 4];
		$state = $fixture('hr', [$original]);
		$before = $state['form']->getData('notifications')['hrAccessRules'][0];
		$balance = ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 330, 'negativeOvertimeEnabled' => true, 'negativeOvertimeThresholdMinutes' => 900];
		$submitted = [...$before, 'hrUsers' => [], 'userGroups' => [], 'priority' => 999, 'maxHours' => ['invalid'], 'breakShortMinutes' => -100, 'missingEntryReminders' => ['notifyEmployees' => true, 'notifyHr' => false, 'graceDays' => 3, 'weekdays' => [2, 4], 'startDate' => '2026-10-01'], 'employeeBalanceNotifications' => $balance, 'mailRecipients' => ['mode' => 'selected', 'userIds' => ['anton']]];
		$response = $save($state, [$submitted], 'policy', null, 'notifications');
		$expectSame(200, $response->getStatus());
		$saved = $response->getData()['rules'][0];
		foreach (['maxHours', 'breakShortMinutes', 'breakShortHours', 'breakLongMinutes', 'breakLongHours', 'hrUsers', 'userGroups', 'priority'] as $field) $expectSame($before[$field], $saved[$field]);
		$expectSame($balance, $saved['employeeBalanceNotifications']);
		$expectSame(['mode' => 'selected', 'userIds' => ['anton']], $saved['mailRecipients']);
		$expectSame(true, $saved['missingEntryReminders']['notifyEmployees']);
		$expectSame(false, $saved['missingEntryReminders']['notifyHr']);
		$expectSame(3, $saved['missingEntryReminders']['graceDays']);
	});
	foreach (['timeRules', 'notifications'] as $firstSection) {
		$test('Stale editors conflict across sections and retry preserves the other section: ' . $firstSection, static function () use ($fixture, $save, $defaultRule, $firstSection, $expectSame): void {
			$state = $fixture();
			$oldRevision = hash('sha256', $state['config']->values['hr_access_rules']);
			$firstPayload = [...$defaultRule, 'maxHours' => 7.5, 'employeeBalanceNotifications' => ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 330]];
			$expectSame(200, $save($state, [$firstPayload], 'policy', $oldRevision, $firstSection)->getStatus());
			$secondSection = $firstSection === 'timeRules' ? 'notifications' : 'timeRules';
			$secondPayload = [...$defaultRule, 'maxHours' => 9, 'employeeBalanceNotifications' => ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 600]];
			$conflict = $save($state, [$secondPayload], 'policy', $oldRevision, $secondSection);
			$expectSame(409, $conflict->getStatus());
			$expectSame(1, count($state['config']->writes));
			$retried = $save($state, [$secondPayload], 'policy', $conflict->getData()['rulesRevision'], $secondSection);
			$expectSame(200, $retried->getStatus());
			$saved = $retried->getData()['rules'][0];
			$expectSame($firstSection === 'timeRules' ? 7.5 : 9.0, $saved['maxHours']);
			$expectSame($firstSection === 'timeRules' ? 600 : 330, $saved['employeeBalanceNotifications']['overtimeThresholdMinutes']);
			$expectSame(true, $saved['employeeBalanceNotifications']['overtimeEnabled']);
			$expectSame([], $state['locks']->held);
		});
	}

	fwrite(STDOUT, 'HR/admin settings: ' . $checks . ' checks, ' . $failures . " failures\n");
	exit($failures === 0 ? 0 : 1);
}
