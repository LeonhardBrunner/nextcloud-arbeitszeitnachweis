<?php

declare(strict_types=1);

// This fixture refuses any database or mail transport outside the disposable test stack.
if (PHP_SAPI !== 'cli' || getenv('REMINDER_TEST_ENVIRONMENT') !== '1') {
	throw new RuntimeException('The isolated balance-mail test environment is required.');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';

use OCA\Timesheet\AppInfo\Application;
use OCA\Timesheet\BackgroundJob\HrNotificationJob;
use OCA\Timesheet\Db\EmployeeBalanceNotificationStore;
use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Service\EmployeeBalanceNotificationMailer;
use OCA\Timesheet\Service\EmployeeBalanceNotificationService;
use OCA\Timesheet\Service\GroupRuleService;
use OCA\Timesheet\Service\HrNotificationService;
use OCA\Timesheet\Service\MonthlyBalanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

$config = \OCP\Server::get(\OCP\IConfig::class);
$database = \OCP\Server::get(\OCP\IDBConnection::class);
if ($config->getSystemValueString('dbname') !== 'timesheet_reminders_test'
	|| $database->executeQuery('SELECT DATABASE()')->fetchOne() !== 'timesheet_reminders_test'
	|| $config->getSystemValueString('mail_smtphost') !== 'mailpit') {
	throw new RuntimeException('Refusing a database or mail server outside the disposable fixture.');
}

class BalanceNativeClock implements ITimeFactory {
	public function __construct(public DateTimeImmutable $current) {}
	public function getTime(): int { return $this->current->getTimestamp(); }
	public function now(): DateTimeImmutable { return $this->current; }
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		$value = $this->current->setTimezone($timezone ?? new DateTimeZone('UTC'));
		if ($time === 'today') $value = $value->setTime(0, 0);
		elseif ($time !== 'now') $value = $value->modify($time);
		return DateTime::createFromImmutable($value);
	}
	public function withTimeZone(DateTimeZone $timezone): static {
		$copy = clone $this; $copy->current = $copy->current->setTimezone($timezone); return $copy;
	}
	public function getTimeZone(?string $timezone = null): DateTimeZone { return new DateTimeZone($timezone ?? 'UTC'); }
}
class BalanceNativeSummaries extends MonthlyBalanceService {
	public array $balances = [];
	public function __construct() {}
	public function calculateCurrentBalance(string $userId): array { return ['overtimeMinutes' => $this->balances[$userId] ?? 900]; }
}
class BalanceNativeMailer extends EmployeeBalanceNotificationMailer {
	public array $sent = [];
	public array $failIds = [];
	public function __construct(private EmployeeBalanceNotificationMailer $realMailer) {}
	public function sendBalanceNotification(IUser $user, int $balance, int $threshold, string $kind): bool {
		if (in_array($user->getUID(), $this->failIds, true)) return false;
		$success = $this->realMailer->sendBalanceNotification($user, $balance, $threshold, $kind);
		if ($success) $this->sent[] = $user->getUID();
		return $success;
	}
}
class BalanceNativeNoHrMail extends HrNotificationService {
	public int $calls = 0;
	public function __construct() {}
	public function doCron(): array { $this->calls++; return []; }
}

$checks = 0;
function verifyBalanceNative(mixed $actual, mixed $expected, string $description): void {
	global $checks; $checks++;
	if ($actual !== $expected) throw new RuntimeException($description . ': ' . json_encode(['actual' => $actual, 'expected' => $expected]));
	echo 'PASS ' . $description . "\n";
}
// CLI bootstrap does not load enabled app routes as a web request or cron entry point would.
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('timesheet');
$container = (new Application())->getContainer();
$sharedConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$appConfig = $container->get(\OCP\AppFramework\Services\IAppConfig::class);
$users = \OCP\Server::get(\OCP\IUserManager::class);
$groups = \OCP\Server::get(\OCP\IGroupManager::class);
$session = \OCP\Server::get(\OCP\IUserSession::class);
$logger = \OCP\Server::get(\Psr\Log\LoggerInterface::class);
$locks = \OCP\Server::get(\OCP\Lock\ILockingProvider::class);
$clock = new BalanceNativeClock(new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Europe/Berlin')));
$summaries = new BalanceNativeSummaries();
$store = new EmployeeBalanceNotificationStore($config, $locks);
$statusStore = new EmploymentStatusStore($config, $locks, $clock, $groups);
$mailer = new BalanceNativeMailer($container->get(EmployeeBalanceNotificationMailer::class));
$password = getenv('REMINDER_TEST_PASSWORD');
if (!$password) throw new RuntimeException('Missing synthetic account password.');
$people = [];
$activity = $groups->get('Zeitnachweis') ?? $groups->createGroup('Zeitnachweis');
$staff = $groups->get('balance-staff') ?? $groups->createGroup('balance-staff');
$duplicate = $groups->get('balance-duplicate') ?? $groups->createGroup('balance-duplicate');
$blocked = $groups->get('balance-disabled-winner') ?? $groups->createGroup('balance-disabled-winner');
foreach (['plus', 'minus', 'edge', 'retry', 'freelancer', 'inactive', 'disabled', 'no-address', 'off', 'unassigned', 'hr'] as $suffix) {
	$id = 'balance-' . $suffix;
	$user = $users->get($id) ?? $users->createUser($id, $password);
	$user->setEnabled(true); $user->setDisplayName('Balance ' . $suffix);
	$user->setEMailAddress($suffix === 'no-address' ? '' : $id . '@example.test');
	$config->setUserValue($id, 'core', 'lang', $suffix === 'minus' ? 'en_GB' : 'de');
	foreach (['employee_balance_notification_last_sent', 'employment_status'] as $key) $config->deleteUserValue($id, 'timesheet', $key);
	if ($suffix !== 'inactive' && $suffix !== 'hr') $activity->addUser($user);
	if (!in_array($suffix, ['hr', 'unassigned'], true)) $staff->addUser($user);
	$people[$suffix] = $user;
}
unset($password);
$duplicate->addUser($people['plus']);
$blocked->addUser($people['off']);
$people['disabled']->setEnabled(false);
$freelancerStatus = $statusStore->getStatus('balance-freelancer');
$statusStore->setFreelancerMode('balance-freelancer', true, $freelancerStatus->statusRevision);
$session->setUser($users->get('balance-mail-admin'));
$settings = ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 600, 'negativeOvertimeEnabled' => true, 'negativeOvertimeThresholdMinutes' => 600];
$rules = [
	['id' => 'balance-main', 'hrGroups' => [], 'hrUsers' => ['balance-hr'], 'userGroups' => ['balance-staff'], 'priority' => 1, 'employeeBalanceNotifications' => $settings],
	['id' => 'balance-duplicate', 'hrGroups' => [], 'hrUsers' => ['balance-hr'], 'userGroups' => ['balance-duplicate'], 'priority' => 2, 'employeeBalanceNotifications' => $settings],
	['id' => 'balance-off', 'hrGroups' => [], 'hrUsers' => ['balance-hr'], 'userGroups' => ['balance-disabled-winner'], 'priority' => 0, 'employeeBalanceNotifications' => array_replace($settings, ['overtimeEnabled' => false, 'negativeOvertimeEnabled' => false])],
];
$initialRules = $rules;
$setRules = static function (array $newRules) use ($sharedConfig): void {
	$sharedConfig->setValueString('timesheet', 'hr_access_rules', json_encode($newRules, JSON_THROW_ON_ERROR)); $sharedConfig->clearCache();
};
$makeService = static function () use ($container, $summaries, $statusStore, $store, $mailer, $groups, $users, $clock, $logger): EmployeeBalanceNotificationService {
	return new EmployeeBalanceNotificationService($container->get(GroupRuleService::class), $summaries, $statusStore, $store, $mailer, $groups, $users, $clock, $logger);
};
$summaries->balances = ['balance-plus' => 750, 'balance-minus' => -840, 'balance-edge' => 600];
$mailer->failIds = ['balance-retry'];
$setRules($rules);
$deleteMailbox = stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true]]);
file_get_contents('http://mailpit:8025/api/v1/messages', false, $deleteMailbox);
$entriesBefore = $database->getQueryBuilder()->select('*')->from('ts_entries')->executeQuery()->fetchAll();
$balancesBefore = $database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->executeQuery()->fetchAll();
$first = $makeService()->sendNotifications();
sort($mailer->sent);
verifyBalanceNative($mailer->sent, ['balance-minus', 'balance-plus'], 'Only eligible employees receive their own positive or negative balance mail');
verifyBalanceNative($first['sent'], 2, 'Multiple group memberships produce one email per employee');
verifyBalanceNative($first['deliveryFailures'], 2, 'Missing addresses and a failed delivery remain isolated');
verifyBalanceNative($store->isDeliveryDue('balance-retry', $clock->current), true, 'Failed delivery does not start its seven-day clock');
verifyBalanceNative($store->isDeliveryDue('balance-plus', $clock->current), false, 'Successful delivery starts its own seven-day clock');
$mailbox = json_decode(file_get_contents('http://mailpit:8025/api/v1/messages?limit=1000'), true, 512, JSON_THROW_ON_ERROR);
verifyBalanceNative($mailbox['total'], 2, 'Private Mailpit receives exactly the two expected initial messages');
foreach ($mailbox['messages'] as $message) {
	$details = json_decode(file_get_contents('http://mailpit:8025/api/v1/message/' . rawurlencode($message['ID'])), true, 512, JSON_THROW_ON_ERROR);
	$address = $message['To'][0]['Address']; $text = $details['Text']; $html = $details['HTML'];
	verifyBalanceNative(count($message['To']), 1, 'Each message has only its own employee recipient');
	if ($address === 'balance-plus@example.test') {
		verifyBalanceNative(str_contains($text, '+12:30') && str_contains($text, '+10:00'), true, 'German overtime mail contains the own balance and threshold');
		verifyBalanceNative(str_contains($text, 'zuständige Personalabteilung'), true, 'German mail includes the agreed HR contact hint');
		verifyBalanceNative(str_contains($text, '-14:00'), false, 'A positive employee mail does not disclose another employee balance');
	} elseif ($address === 'balance-minus@example.test') {
		verifyBalanceNative(str_contains($text, '-14:00') && str_contains($text, '-10:00'), true, 'English negative-hours mail contains the own balance and threshold');
		verifyBalanceNative(str_contains($text, 'contact the responsible HR team'), true, 'English mail includes its HR contact hint');
	}
	verifyBalanceNative(str_contains($html, '/apps/timesheet/'), true, 'HTML messages include the app link');
}
$mailer->failIds = []; $mailer->sent = [];
verifyBalanceNative($makeService()->sendNotifications()['sent'], 1, 'Only the failed employee retries without resending successful messages');
verifyBalanceNative($mailer->sent, ['balance-retry'], 'The retry targets only the previously failed employee');
$lastSent = $config->getUserValue('balance-plus', 'timesheet', 'employee_balance_notification_last_sent');
$rules[0]['employeeBalanceNotifications']['overtimeEnabled'] = false;
$setRules($rules); $makeService()->sendNotifications();
$rules[0]['employeeBalanceNotifications']['overtimeEnabled'] = true;
$setRules($rules);
verifyBalanceNative($makeService()->sendNotifications()['sent'], 0, 'Disabling and re-enabling a group channel does not bypass its clock');
verifyBalanceNative($config->getUserValue('balance-plus', 'timesheet', 'employee_balance_notification_last_sent'), $lastSent, 'Settings changes preserve the stored successful-delivery timestamp');
$clock->current = $clock->current->modify('+7 days');
verifyBalanceNative($makeService()->sendNotifications()['sent'], 3, 'Warnings are due again after seven calendar days');

// The existing weekly job must call employee delivery even when all personal HR mail is off.
foreach ($people as $person) $config->deleteUserValue($person->getUID(), 'timesheet', 'employee_balance_notification_last_sent');
$mailer->sent = [];
$clock->current = new DateTimeImmutable('2026-11-03 10:00:00', new DateTimeZone('Europe/Berlin'));
$legacy = new BalanceNativeNoHrMail();
$job = new HrNotificationJob($clock, $users, \OCP\Server::get(\OCP\Mail\IMailer::class), $legacy, $appConfig, $logger, \OCP\Server::get(\OCP\L10N\IFactory::class), $makeService());
$run = new ReflectionMethod($job, 'run');
$appConfig->setAppValueString('hr_notification_force', '0'); $run->invoke($job, []);
verifyBalanceNative($mailer->sent, [], 'The regular weekly job does not send employee balance mail on Tuesday');
$clock->current = new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('Europe/Berlin'));
$run->invoke($job, []); sort($mailer->sent);
verifyBalanceNative($mailer->sent, ['balance-minus', 'balance-plus', 'balance-retry'], 'Monday employee mail works before the empty personal-HR result returns');
verifyBalanceNative($legacy->calls, 1, 'The unchanged legacy HR path is still invoked on Monday');

// Responsibility selects HR access, but an absent HR account must not block personal employee mail.
$clock->current = $clock->current->modify('+7 days');
foreach ($rules as &$rule) $rule['hrUsers'] = [];
unset($rule); $setRules($rules);
verifyBalanceNative($makeService()->sendNotifications()['sent'], 3, 'Employee balance mail works without any HR account');
$clock->current = $clock->current->modify('+8 days');
$mailer->sent = [];
$appConfig->setAppValueString('hr_notification_force', '1');
$run->invoke($job, []);
verifyBalanceNative(count($mailer->sent), 3, 'The existing force flag also runs employee delivery outside Monday');
$mailer->sent = [];
$run->invoke($job, []);
verifyBalanceNative($mailer->sent, [], 'A forced weekly run still respects successful employee delivery clocks');
verifyBalanceNative($database->getQueryBuilder()->select('*')->from('ts_entries')->executeQuery()->fetchAll(), $entriesBefore, 'Balance notification checks never change time entries');
verifyBalanceNative($database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->executeQuery()->fetchAll(), $balancesBefore, 'The isolated notification fixture does not change calculated balance rows');
$setRules($initialRules);
$appConfig->setAppValueString('hr_notification_force', '0');
echo 'Employee balance integration: ' . $checks . " checks passed.\n";
