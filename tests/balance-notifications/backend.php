<?php

declare(strict_types=1);

// Exercise real balance settings, delivery state, mail construction and the weekly job without a database.
namespace OCP {
	interface IUser { public function getUID(): string; public function getDisplayName(): string; public function getEMailAddress(): ?string; public function isEnabled(): bool; }
	interface IUserManager { public function get(string $id): ?IUser; }
	interface IGroupManager { public function get(string $id); }
	interface IConfig { public function getUserValueForUsers($app, $key, $users); public function setUserValue($user, $app, $key, $value); }
	interface IURLGenerator { public function getAbsoluteURL(string $url): string; public function linkToRoute(string $route): string; }
	interface IL10N {
		public function t(string $message, array $parameters = []): string;
		public function l(string $type, $data, array $options = []): string;
	}
}
namespace OCP\Lock {
	class LockedException extends \RuntimeException { }
	interface ILockingProvider { public const LOCK_EXCLUSIVE = 2; public function acquireLock(string $key, int $type): void; public function releaseLock(string $key, int $type): void; }
}
namespace OCP\AppFramework\Services { interface IAppConfig { public function getAppValueString(string $key, string $default = ''): string; } }
namespace OCP\AppFramework\Utility { interface ITimeFactory { public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime; } }
namespace OCP\L10N { interface IFactory { public function getUserLanguage(\OCP\IUser $user): string; public function get(string $app, ?string $language = null): \OCP\IL10N; } }
namespace OCP\Mail { interface IMailer { public function createMessage(); public function send($message); } }
namespace OCP\BackgroundJob {
	interface IJob { public const TIME_INSENSITIVE = 0; }
	class TimedJob {
		public function __construct(object $time) { }
		protected function setInterval(int $interval): void { }
		protected function setAllowParallelRuns(bool $allow): void { }
		protected function setTimeSensitivity(int $sensitivity): void { }
	}
}
namespace Psr\Log { interface LoggerInterface { public function info($message, array $context = []): void; public function warning($message, array $context = []): void; public function error($message, array $context = []): void; } }
namespace OCA\Timesheet\Db {
	class EmploymentStatusStore {
		public array $statuses = [];
		public array $unavailable = [];
		public function getStatus(string $id): object {
			if (in_array($id, $this->unavailable, true)) throw new \RuntimeException('Synthetic status failure.');
			return (object)($this->statuses[$id] ?? ['isActive' => true, 'freelancerMode' => false]);
		}
	}
}
namespace OCA\Timesheet\Service {
	class GroupRuleService {
		public array $rules = [];
		public array $byEmployee = [];
		public function getReminderRules(): array { return $this->rules; }
		public function getEmployeeBalanceNotificationPolicy(string $id): ?array { return $this->byEmployee[$id] ?? $this->rules[0] ?? null; }
	}
	class MonthlyBalanceService {
		public array $summaries = [];
		public array $reads = [];
		public array $unavailable = [];
		public function calculateCurrentBalance(string $id): ?array {
			$this->reads[] = $id;
			if (in_array($id, $this->unavailable, true)) throw new \RuntimeException('Synthetic calculation failure.');
			return array_key_exists($id, $this->summaries) ? ['overtimeMinutes' => $this->summaries[$id]] : null;
		}
	}
	class HrNotificationService {
		public int $calls = 0;
		public array $notifications = [];
		public function doCron(): array { $this->calls++; return $this->notifications; }
	}
}
namespace {
	use OCA\Timesheet\BackgroundJob\HrNotificationJob;
	use OCA\Timesheet\Db\EmployeeBalanceNotificationStore;
	use OCA\Timesheet\Db\EmploymentStatusStore;
	use OCA\Timesheet\Service\EmployeeBalanceNotificationMailer;
	use OCA\Timesheet\Service\EmployeeBalanceNotificationService;
	use OCA\Timesheet\Service\EmployeeBalanceNotificationSettings;
	use OCA\Timesheet\Service\GroupRuleService;
	use OCA\Timesheet\Service\HrNotificationService;
	use OCA\Timesheet\Service\MonthlyBalanceService;

	foreach (['Service/EmployeeBalanceNotificationSettings', 'Db/EmployeeBalanceNotificationStore', 'Service/EmployeeBalanceNotificationMailer', 'Service/EmployeeBalanceNotificationService', 'BackgroundJob/HrNotificationJob'] as $class) {
		require __DIR__ . '/../../lib/' . $class . '.php';
	}
	class BalanceUser implements \OCP\IUser {
		public function __construct(public string $id, public string $name = '', public ?string $email = null, public bool $enabled = true, public string $language = 'en') { }
		public function getUID(): string { return $this->id; }
		public function getDisplayName(): string { return $this->name ?: $this->id; }
		public function getEMailAddress(): ?string { return $this->email; }
		public function isEnabled(): bool { return $this->enabled; }
	}
	class BalanceUsers implements \OCP\IUserManager {
		public array $users = [];
		public function get(string $id): ?\OCP\IUser { return $this->users[$id] ?? null; }
	}
	class BalanceGroups implements \OCP\IGroupManager {
		public array $members = [];
		public function get(string $id): ?object {
			return isset($this->members[$id]) ? new class($this->members[$id]) { public function __construct(private array $members) { } public function getUsers(): array { return $this->members; } } : null;
		}
	}
	class BalanceLocks implements \OCP\Lock\ILockingProvider {
		public array $held = [];
		public int $acquisitions = 0;
		public int $releases = 0;
		public function acquireLock(string $key, int $type): void {
			if (isset($this->held[$key])) throw new \OCP\Lock\LockedException('Synthetic busy delivery.');
			if (strlen($key) !== 64 || $type !== self::LOCK_EXCLUSIVE) throw new \RuntimeException('Wrong account lock.');
			$this->held[$key] = true; $this->acquisitions++;
		}
		public function releaseLock(string $key, int $type): void { unset($this->held[$key]); $this->releases++; }
	}
	class BalanceConfig implements \OCP\IConfig {
		public array $values = [];
		public array $reads = [];
		public array $writes = [];
		public function getUserValueForUsers($app, $key, $users) {
			$this->reads[] = [$app, $key, $users];
			$result = [];
			foreach ($users as $user) if (isset($this->values[$user][$app][$key])) $result[$user] = $this->values[$user][$app][$key];
			return $result;
		}
		public function setUserValue($user, $app, $key, $value): void { $this->values[$user][$app][$key] = $value; $this->writes[] = [$user, $app, $key, $value]; }
	}
	class BalanceClock implements \OCP\AppFramework\Utility\ITimeFactory {
		public string $instant = '2026-11-09T10:00:00+01:00';
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
			$date = new \DateTime($this->instant);
			if ($timezone) $date->setTimezone($timezone);
			return $date;
		}
		public function now(): \DateTimeImmutable { return \DateTimeImmutable::createFromMutable($this->getDateTime()); }
	}
	class BalanceLogger implements \Psr\Log\LoggerInterface {
		public array $errors = [];
		public function info($message, array $context = []): void { }
		public function warning($message, array $context = []): void { }
		public function error($message, array $context = []): void { $this->errors[] = $message; }
	}
	class BalanceTranslator implements \OCP\IL10N {
		public function __construct(private string $language) { }
		public function t(string $message, array $parameters = []): string { return '[' . $this->language . '] ' . ($parameters ? vsprintf($message, $parameters) : $message); }
		public function l(string $type, $data, array $options = []): string { return $data->format($this->language === 'de_DE' ? 'd.m.Y' : 'Y-m-d'); }
	}
	class BalanceLanguages implements \OCP\L10N\IFactory {
		public array $calls = [];
		public function getUserLanguage(\OCP\IUser $user): string { return $user->language; }
		public function get(string $app, ?string $language = null): \OCP\IL10N { $this->calls[] = [$app, $language]; return new BalanceTranslator($language ?? 'en'); }
	}
	class BalanceUrl implements \OCP\IURLGenerator {
		public function getAbsoluteURL(string $url): string { return 'https://example.test' . $url; }
		public function linkToRoute(string $route): string { return '/apps/timesheet/'; }
	}
	class BalanceMessage {
		public string $subject = '';
		public array $to = [];
		public string $plain = '';
		public string $html = '';
		public function setSubject(string $subject): void { $this->subject = $subject; }
		public function setTo(array $recipients): void { $this->to = $recipients; }
		public function setPlainBody(string $text): void { $this->plain = $text; }
		public function setHtmlBody(string $text): void { $this->html = $text; }
	}
	class BalanceTransport implements \OCP\Mail\IMailer {
		public array $messages = [];
		public array $attempts = [];
		public array $failedAddresses = [];
		public ?\Closure $onSend = null;
		public function createMessage(): BalanceMessage { return new BalanceMessage(); }
		public function send($message): array {
			$this->attempts[] = $message;
			if ($this->onSend) ($this->onSend)();
			$failures = array_intersect(array_keys($message->to), $this->failedAddresses);
			if ($failures) return array_values($failures);
			$this->messages[] = $message;
			return [];
		}
	}
	class BalanceAppConfig implements \OCP\AppFramework\Services\IAppConfig {
		public string $force = '0';
		public function getAppValueString(string $key, string $default = ''): string { return $key === 'hr_notification_force' ? $this->force : $default; }
	}
	class BalanceJobService extends EmployeeBalanceNotificationService {
		public int $calls = 0;
		public bool $fails = false;
		public function __construct() { }
		public function sendNotifications(): array { $this->calls++; if ($this->fails) throw new \RuntimeException('Synthetic employee job failure.'); return ['sent' => 0]; }
	}
	function balanceFixture(array $settings = ['overtimeEnabled' => true, 'negativeOvertimeEnabled' => true], array $balanceValues = ['plus' => 601, 'minus' => -601]): array {
		$users = new BalanceUsers(); $groups = new BalanceGroups(); $statuses = new EmploymentStatusStore(); $clock = new BalanceClock(); $logger = new BalanceLogger();
		$locks = new BalanceLocks(); $config = new BalanceConfig(); $store = new EmployeeBalanceNotificationStore($config, $locks);
		$transport = new BalanceTransport(); $languages = new BalanceLanguages(); $mailer = new EmployeeBalanceNotificationMailer($transport, $languages, new BalanceUrl(), $logger);
		$rules = new GroupRuleService(); $rules->rules = [['id' => 'one', 'userGroups' => ['staff'], 'employeeBalanceNotifications' => $settings]];
		$balances = new MonthlyBalanceService(); $balances->summaries = $balanceValues;
		foreach ($balanceValues as $id => $_) $users->users[$id] = new BalanceUser($id, 'Name ' . $id, $id . '@example.test');
		$groups->members['staff'] = array_values($users->users);
		$service = new EmployeeBalanceNotificationService($rules, $balances, $statuses, $store, $mailer, $groups, $users, $clock, $logger);
		return compact('users', 'groups', 'statuses', 'clock', 'logger', 'locks', 'config', 'store', 'transport', 'languages', 'mailer', 'rules', 'balances', 'service');
	}
	$checks = 0; $failures = 0;
	function balanceTest(string $description, callable $operation): void {
		global $checks, $failures; $checks++;
		try { $operation(); } catch (\Throwable $error) { $failures++; fwrite(STDERR, $description . ': ' . $error->getMessage() . "\n"); }
	}
	function balanceSame(mixed $expected, mixed $actual): void { if ($expected !== $actual) throw new \RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual])); }
	function balanceThrows(string $class, callable $operation): void { try { $operation(); } catch (\Throwable $error) { if ($error instanceof $class) return; throw $error; } throw new \RuntimeException('Expected ' . $class); }
	function balanceRecipients(BalanceTransport $transport): array { return array_merge([], ...array_map(static fn(BalanceMessage $message): array => array_keys($message->to), $transport->messages)); }

	$defaults = ['overtimeEnabled' => false, 'overtimeThresholdMinutes' => 600, 'negativeOvertimeEnabled' => false, 'negativeOvertimeThresholdMinutes' => 600];
	balanceTest('Legacy absence loads and saves with both employee channels off', static function () use ($defaults): void {
		balanceSame($defaults, EmployeeBalanceNotificationSettings::normalize(null));
		balanceSame($defaults, EmployeeBalanceNotificationSettings::validateForSave(null));
	});
	foreach ([0, 600, 525600] as $threshold) balanceTest('Whole-minute threshold boundary: ' . $threshold, static function () use ($threshold): void {
		$settings = EmployeeBalanceNotificationSettings::validateForSave(['overtimeEnabled' => true, 'overtimeThresholdMinutes' => $threshold]);
		balanceSame(true, $settings['overtimeEnabled']); balanceSame($threshold, $settings['overtimeThresholdMinutes']); balanceSame(false, $settings['negativeOvertimeEnabled']);
	});
	foreach (['bad', ['overtimeEnabled' => 'true'], ['negativeOvertimeEnabled' => null], ['overtimeThresholdMinutes' => -1], ['negativeOvertimeThresholdMinutes' => 525601], ['overtimeThresholdMinutes' => '600'], ['overtimeThresholdMinutes' => 600.5], ['overtimeThresholdMinutes' => null]] as $invalid) {
		balanceTest('Invalid settings are rejected: ' . json_encode($invalid), static function () use ($invalid): void { balanceThrows(\InvalidArgumentException::class, static fn() => EmployeeBalanceNotificationSettings::validateForSave($invalid)); });
	}
	balanceTest('One malformed stored field disables both channels while retaining valid thresholds', static function (): void {
		$settings = EmployeeBalanceNotificationSettings::normalize(['overtimeEnabled' => true, 'negativeOvertimeEnabled' => true, 'overtimeThresholdMinutes' => -1, 'negativeOvertimeThresholdMinutes' => 900]);
		balanceSame(false, $settings['overtimeEnabled']); balanceSame(false, $settings['negativeOvertimeEnabled']); balanceSame(600, $settings['overtimeThresholdMinutes']); balanceSame(900, $settings['negativeOvertimeThresholdMinutes']);
	});
	foreach ([[false, false], [true, false], [false, true], [true, true]] as [$overtime, $negative]) {
		balanceTest('Independent positive and negative channels: ' . json_encode([$overtime, $negative]), static function () use ($overtime, $negative): void {
			$f = balanceFixture(['overtimeEnabled' => $overtime, 'negativeOvertimeEnabled' => $negative]);
			$f['service']->sendNotifications();
			balanceSame(array_merge($overtime ? ['plus@example.test'] : [], $negative ? ['minus@example.test'] : []), balanceRecipients($f['transport'])); balanceSame([], $f['locks']->held);
		});
	}
	balanceTest('Exact thresholds and a zero balance do not send warnings', static function (): void {
		$f = balanceFixture(['overtimeEnabled' => true, 'negativeOvertimeEnabled' => true], ['plus' => 600, 'minus' => -600, 'zero' => 0]);
		balanceSame(0, $f['service']->sendNotifications()['sent']); balanceSame([], $f['transport']->messages);
	});
	balanceTest('Zero thresholds still require a nonzero balance on the selected side', static function (): void {
		$f = balanceFixture(['overtimeEnabled' => true, 'negativeOvertimeEnabled' => true, 'overtimeThresholdMinutes' => 0, 'negativeOvertimeThresholdMinutes' => 0], ['plus' => 1, 'minus' => -1, 'zero' => 0]);
		balanceSame(2, $f['service']->sendNotifications()['sent']); balanceSame(['plus@example.test', 'minus@example.test'], balanceRecipients($f['transport']));
	});
	balanceTest('Group overlap and repeated rules create only one evaluation and mail per employee', static function (): void {
		$f = balanceFixture(); $f['groups']->members['other'] = $f['groups']->members['staff']; $f['rules']->rules[] = [...$f['rules']->rules[0], 'id' => 'two', 'userGroups' => ['other']];
		balanceSame(2, $f['service']->sendNotifications()['sent']); balanceSame(['plus', 'minus'], $f['balances']->reads);
	});
	balanceTest('A disabled winning rule never falls through to an enabled lower rule', static function (): void {
		$f = balanceFixture(); $active = $f['rules']->rules[0]; $inactive = [...$active, 'id' => 'winner', 'employeeBalanceNotifications' => []];
		$f['rules']->rules = [$inactive, $active]; $f['rules']->byEmployee = ['plus' => $inactive, 'minus' => $inactive];
		balanceSame(0, $f['service']->sendNotifications()['sent']); balanceSame([], $f['balances']->reads);
	});
	balanceTest('Freelancer, inactive, disabled and removed accounts remain excluded', static function (): void {
		$f = balanceFixture(['overtimeEnabled' => true], ['free' => 900, 'inactive' => 900, 'disabled' => 900, 'removed' => 900, 'active' => 900]);
		$f['statuses']->statuses['free'] = ['isActive' => true, 'freelancerMode' => true]; $f['statuses']->statuses['inactive'] = ['isActive' => false, 'freelancerMode' => false];
		$f['users']->users['disabled']->enabled = false; unset($f['users']->users['removed']);
		balanceSame(1, $f['service']->sendNotifications()['sent']); balanceSame(['active@example.test'], balanceRecipients($f['transport'])); balanceSame(['active'], $f['balances']->reads);
	});
	balanceTest('Unknown status fails closed and does not block another recipient', static function (): void {
		$f = balanceFixture(); $f['statuses']->unavailable[] = 'plus';
		balanceSame(1, $f['service']->sendNotifications()['deliveryFailures']); balanceSame(['minus@example.test'], balanceRecipients($f['transport']));
	});
	balanceTest('Employee mail works without any HR recipients or personal HR configuration', static function (): void {
		$f = balanceFixture(); balanceSame(2, $f['service']->sendNotifications()['sent']); balanceSame(2, count($f['transport']->messages));
	});
	balanceTest('Each email contains only its own signed balance and the HR contact hint', static function (): void {
		$f = balanceFixture(); $f['service']->sendNotifications(); [$plus, $minus] = $f['transport']->messages;
		balanceSame(true, str_contains($plus->plain, '+10:01')); balanceSame(false, str_contains($plus->plain, 'Name minus'));
		balanceSame(true, str_contains($minus->plain, '-10:01')); balanceSame(false, str_contains($minus->plain, 'Name plus'));
		foreach ([$plus, $minus] as $message) { balanceSame(1, count($message->to)); balanceSame(true, str_contains($message->plain, 'contact the responsible HR team')); balanceSame(true, str_contains($message->html, 'contact the responsible HR team')); balanceSame(true, str_contains($message->plain, 'https://example.test/apps/timesheet/')); }
	});
	balanceTest('Mail language follows the recipient and HTML treats account names as text', static function (): void {
		$f = balanceFixture(); $f['users']->users['plus']->language = 'de_DE'; $f['users']->users['plus']->name = '<img src=x onerror=bad()> & Name';
		$f['service']->sendNotifications(); $message = $f['transport']->messages[0]; balanceSame('[de_DE] Timesheet: working time balance', $message->subject); balanceSame(true, str_contains($message->plain, '<img src=x onerror=bad()>')); balanceSame(false, str_contains($message->html, '<img src=x')); balanceSame(true, str_contains($message->html, '&lt;img src=x'));
	});
	balanceTest('Missing addresses and rejected delivery keep only their account retryable', static function (): void {
		$f = balanceFixture(); $f['users']->users['plus']->email = ''; $f['transport']->failedAddresses = ['minus@example.test'];
		balanceSame(2, $f['service']->sendNotifications()['deliveryFailures']); balanceSame([], $f['config']->writes);
		$f['users']->users['plus']->email = 'plus@example.test'; $f['transport']->failedAddresses = [];
		balanceSame(2, $f['service']->sendNotifications()['sent']); balanceSame(0, $f['service']->sendNotifications()['sent']);
	});
	balanceTest('A calculation failure leaves other employee messages and locks usable', static function (): void {
		$f = balanceFixture(); $f['balances']->unavailable = ['plus'];
		balanceSame(1, $f['service']->sendNotifications()['sent']); balanceSame(['minus@example.test'], balanceRecipients($f['transport'])); balanceSame([], $f['locks']->held);
	});
	balanceTest('Seven-day delivery survives setting changes and stays separate from other mail types', static function (): void {
		$f = balanceFixture(); $f['config']->values['plus']['timesheet']['missing_entry_employee_last_sent'] = 'unchanged';
		balanceSame(2, $f['service']->sendNotifications()['sent']); $saved = $f['config']->values;
		$f['rules']->rules[0]['employeeBalanceNotifications'] = []; $f['clock']->instant = '2026-11-10T10:00:00+01:00'; balanceSame(0, $f['service']->sendNotifications()['sent']);
		$f['rules']->rules[0]['employeeBalanceNotifications'] = ['overtimeEnabled' => true, 'negativeOvertimeEnabled' => true, 'overtimeThresholdMinutes' => 0];
		$f['clock']->instant = '2026-11-16T09:59:59+01:00'; balanceSame(0, $f['service']->sendNotifications()['sent']); balanceSame($saved, $f['config']->values);
		$f['clock']->instant = '2026-11-16T10:00:00+01:00'; balanceSame(2, $f['service']->sendNotifications()['sent']); balanceSame('unchanged', $f['config']->values['plus']['timesheet']['missing_entry_employee_last_sent']);
	});
	balanceTest('Malformed delivery timestamps fail closed without any mail or overwrite', static function (): void {
		$f = balanceFixture(['overtimeEnabled' => true], ['plus' => 900]); $f['config']->values['plus']['timesheet']['employee_balance_notification_last_sent'] = 'invalid';
		balanceSame(1, $f['service']->sendNotifications()['deliveryFailures']); balanceSame([], $f['transport']->messages); balanceSame('invalid', $f['config']->values['plus']['timesheet']['employee_balance_notification_last_sent']);
	});
	balanceTest('The account lock blocks a second sender and is released after exceptions', static function (): void {
		$f = balanceFixture();
		balanceThrows(\OCP\Lock\LockedException::class, static function () use ($f): void { $f['store']->withDeliveryLock('plus', static fn() => $f['store']->withDeliveryLock('plus', static fn() => null)); });
		balanceSame([], $f['locks']->held); balanceSame($f['locks']->acquisitions, $f['locks']->releases);
		$f['store']->withDeliveryLock('plus', static fn() => null); balanceSame([], $f['locks']->held);
	});

	foreach ([['2026-11-09T10:00:00+01:00', '0', 1], ['2026-11-06T10:00:00+01:00', '0', 0], ['2026-11-06T10:00:00+01:00', '1', 1]] as [$instant, $force, $expectedCalls]) {
		balanceTest('Weekly job schedule and explicit test override: ' . $instant . ' force=' . $force, static function () use ($instant, $force, $expectedCalls): void {
			$f = balanceFixture(); $f['clock']->instant = $instant; $appConfig = new BalanceAppConfig(); $appConfig->force = $force;
			$employeeService = new BalanceJobService(); $hrService = new HrNotificationService();
			$job = new HrNotificationJob($f['clock'], $f['users'], $f['transport'], $hrService, $appConfig, $f['logger'], $f['languages'], $employeeService);
			(new \ReflectionMethod($job, 'run'))->invoke($job, []); balanceSame($expectedCalls, $employeeService->calls); balanceSame($expectedCalls, $hrService->calls);
		});
	}
	balanceTest('An employee-service failure does not prevent the older HR summary check', static function (): void {
		$f = balanceFixture(); $employeeService = new BalanceJobService(); $employeeService->fails = true; $hrService = new HrNotificationService();
		$job = new HrNotificationJob($f['clock'], $f['users'], $f['transport'], $hrService, new BalanceAppConfig(), $f['logger'], $f['languages'], $employeeService);
		(new \ReflectionMethod($job, 'run'))->invoke($job, []); balanceSame(1, $employeeService->calls); balanceSame(1, $hrService->calls); balanceSame(1, count($f['logger']->errors));
	});
	balanceTest('The older HR message keeps its own recipients, thresholds and text', static function (): void {
		$f = balanceFixture(); $f['users']->users['hr'] = new BalanceUser('hr', 'HR', 'hr@example.test'); $employeeService = new BalanceJobService(); $hrService = new HrNotificationService();
		$hrService->notifications = ['hr' => ['noEntry' => [], 'overtime' => [['user' => 'Name plus', 'overtime' => '+10:01']], 'negative' => []]];
		$job = new HrNotificationJob($f['clock'], $f['users'], $f['transport'], $hrService, new BalanceAppConfig(), $f['logger'], $f['languages'], $employeeService);
		(new \ReflectionMethod($job, 'run'))->invoke($job, []); balanceSame(['hr@example.test'], balanceRecipients($f['transport'])); balanceSame(true, str_contains($f['transport']->messages[0]->plain, 'Too much overtime:'));
		balanceSame('[en] Timesheet Notifications - 2026-11-09', $f['transport']->messages[0]->subject);
	});

	fwrite(STDOUT, 'Employee balance notifications: ' . $checks . ' checks, ' . $failures . " failures\n");
	exit($failures === 0 ? 0 : 1);
}
