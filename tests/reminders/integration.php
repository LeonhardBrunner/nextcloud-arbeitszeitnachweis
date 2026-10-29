<?php

declare(strict_types=1);

// This runner creates synthetic data only in the dedicated reminder test database.
if (PHP_SAPI !== 'cli' || getenv('REMINDER_TEST_ENVIRONMENT') !== '1') {
	fwrite(STDERR, "The dedicated reminder test environment is required.\n");
	exit(1);
}
$configuredDatabase = (static function (): string {
	$CONFIG = [];
	require '/var/www/html/config/config.php';
	return (string)($CONFIG['dbname'] ?? '');
})();
if ($configuredDatabase !== 'timesheet_reminders_test' || (getenv('NC_dbname') !== false && getenv('NC_dbname') !== $configuredDatabase)) {
	throw new RuntimeException('Refusing to run against a different database.');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';

use OCA\Timesheet\AppInfo\Application;
use OCA\Timesheet\Db\Entry;
use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\MissingEntryReminderStore;
use OCA\Timesheet\Db\UserConfig;
use OCA\Timesheet\Db\UserConfigMapper;
use OCA\Timesheet\Service\GroupRuleService;
use OCA\Timesheet\Service\HolidayService;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\MissingEntryDetector;
use OCA\Timesheet\Service\MissingEntryReminderMailer;
use OCA\Timesheet\Service\MissingEntryReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

$config = \OCP\Server::get(\OCP\IConfig::class);
$database = \OCP\Server::get(\OCP\IDBConnection::class);
if ($config->getSystemValueString('dbname') !== 'timesheet_reminders_test'
	|| $database->executeQuery('SELECT DATABASE()')->fetchOne() !== 'timesheet_reminders_test'
	|| $config->getSystemValueString('mail_smtphost') !== 'mailpit') {
	throw new RuntimeException('The isolated database and Mailpit transport are required.');
}

class ReminderTestClock implements ITimeFactory {
	public function __construct(public DateTimeImmutable $current) {
	}
	public function getTime(): int {
		return $this->current->getTimestamp();
	}
	public function now(): DateTimeImmutable {
		return $this->current;
	}
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		$value = $this->current->setTimezone($timezone ?? new DateTimeZone('UTC'));
		if ($time === 'today') {
			$value = $value->setTime(0, 0);
		} elseif ($time !== 'now') {
			$value = $value->modify($time);
		}
		return DateTime::createFromImmutable($value);
	}
	public function withTimeZone(DateTimeZone $timezone): static {
		$copy = clone $this;
		$copy->current = $copy->current->setTimezone($timezone);
		return $copy;
	}
	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return new DateTimeZone($timezone ?? 'UTC');
	}
}

class CapturingReminderMailer extends MissingEntryReminderMailer {
	public array $employees = [];
	public array $hrSummaries = [];
	public array $failEmployeeIds = [];
	public bool $deliver = false;
	public function __construct(private MissingEntryReminderMailer $realMailer) {
	}
	public function sendEmployeeReminder(IUser $employee, array $issues): bool {
		$this->employees[$employee->getUID()] = $issues;
		if (in_array($employee->getUID(), $this->failEmployeeIds, true) || !$employee->getEMailAddress()) {
			return false;
		}
		return !$this->deliver || $this->realMailer->sendEmployeeReminder($employee, $issues);
	}
	public function sendHrSummary(IUser $hrUser, array $employees): bool {
		$this->hrSummaries[$hrUser->getUID()] = $employees;
		return !$this->deliver || $this->realMailer->sendHrSummary($hrUser, $employees);
	}
}

class UnavailableReminderHolidays extends HolidayService {
	public function __construct() {
	}
	public function getHolidays(int $year, string $state): array {
		throw new RuntimeException('Synthetic holiday transport failure.');
	}
}

$checks = 0;
function verifyReminder(mixed $actual, mixed $expected, string $description): void {
	global $checks;
	$checks++;
	if ($actual !== $expected) {
		throw new RuntimeException($description . ': ' . json_encode(['actual' => $actual, 'expected' => $expected]));
	}
}

$app = new Application();
$container = $app->getContainer();
$appConfig = $container->get(\OCP\AppFramework\Services\IAppConfig::class);
$users = \OCP\Server::get(\OCP\IUserManager::class);
$groups = \OCP\Server::get(\OCP\IGroupManager::class);
$session = \OCP\Server::get(\OCP\IUserSession::class);
$session->setUser($users->get('reminder-admin'));
$entryMapper = $container->get(EntryMapper::class);
$configMapper = $container->get(UserConfigMapper::class);
$store = $container->get(MissingEntryReminderStore::class);
$logger = \OCP\Server::get(\Psr\Log\LoggerInterface::class);
$clock = new ReminderTestClock(new DateTimeImmutable('2026-10-29 10:00:00', new DateTimeZone('Europe/Berlin')));
$capturingMailer = new CapturingReminderMailer($container->get(MissingEntryReminderMailer::class));
$testUsers = [];
$password = getenv('REMINDER_TEST_PASSWORD');
if (!$password) {
	throw new RuntimeException('A synthetic test password is required.');
}

// Reset only this runner's synthetic users and app configuration inside the guarded database.
foreach (['employee-a', 'employee-b', 'no-region', 'no-mail', 'disabled', 'hr-a', 'hr-b', 'late'] as $suffix) {
	$userId = 'reminder-' . $suffix;
	$user = $users->get($userId) ?? $users->createUser($userId, $password);
	$user->setEnabled($suffix !== 'disabled');
	$user->setDisplayName($suffix === 'employee-a' ? 'Employee A <em>demo</em>' : $userId);
	$user->setEMailAddress($suffix === 'no-mail' ? '' : $userId . '@example.test');
	$config->setUserValue($userId, 'core', 'lang', in_array($suffix, ['employee-a', 'hr-a'], true) ? 'de' : 'en');
	foreach (['missing_entry_enrollments', 'missing_entry_employee_last_sent', 'missing_entry_hr_last_sent'] as $key) {
		$config->deleteUserValue($userId, 'timesheet', $key);
	}
	foreach (['ts_entries', 'ts_user_config', 'ts_monthly_balances'] as $table) {
		$query = $database->getQueryBuilder();
		$query->delete($table)->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
	}
	$workConfig = new UserConfig();
	$workConfig->setUserId($userId);
	$workConfig->setWorkMinutes($suffix === 'employee-b' ? 0 : 480);
	$workConfig->setState($suffix === 'no-region' ? '' : 'BY');
	$configMapper->insert($workConfig);
	$testUsers[$suffix] = $user;
}
unset($password);
$members = [
	'reminder-employees-a' => ['employee-a', 'no-region', 'no-mail', 'disabled'],
	'reminder-extra-a' => ['employee-a'],
	'reminder-employees-b' => ['employee-b'],
	'reminder-hr-a' => ['hr-a'],
	'reminder-hr-b' => ['hr-b'],
];
foreach ($members as $groupId => $suffixes) {
	$group = $groups->get($groupId) ?? $groups->createGroup($groupId);
	foreach ($group->getUsers() as $member) {
		$group->removeUser($member);
	}
	foreach ($suffixes as $suffix) {
		$group->addUser($testUsers[$suffix]);
	}
}
$rules = [];
foreach (['a', 'b'] as $letter) {
	$rules[] = [
		'id' => 'reminder-rule-' . $letter,
		'hrGroups' => ['reminder-hr-' . $letter],
		'userGroups' => $letter === 'a' ? ['reminder-employees-a', 'reminder-extra-a'] : ['reminder-employees-b'],
		'priority' => 1,
		'missingEntryReminders' => ['enabled' => true, 'graceDays' => 7, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => '2026-10-19'],
	];
	$appConfig->setAppValueString('missing_entry_rule_' . substr(hash('sha256', 'reminder-rule-' . $letter), 0, 32), '0');
}
$appConfig->setAppValueString('hr_access_rules', '[]');
$makeService = static function (?HolidayService $holidays = null) use ($container, $entryMapper, $configMapper, $appConfig, $session, $groups, $users, $store, $clock, $capturingMailer, $logger): MissingEntryReminderService {
	return new MissingEntryReminderService(
		$container->get(GroupRuleService::class), $entryMapper, $configMapper,
		new HrService($appConfig, $session, $groups), $holidays ?? $container->get(HolidayService::class), new MissingEntryDetector(),
		$store, $capturingMailer, $groups, $users, $clock, $logger,
	);
};
$makeService()->prepareRuleChanges([], $rules);
verifyReminder($capturingMailer->employees, [], 'Saving settings sends no email');
$appConfig->setAppValueString('hr_access_rules', json_encode($rules, JSON_THROW_ON_ERROR));

$appData = $container->get(\OCP\Files\IAppData::class);
try {
	$holidayFolder = $appData->getFolder('holidays');
} catch (\OCP\Files\NotFoundException) {
	$holidayFolder = $appData->newFolder('holidays');
}
$holidayFile = $holidayFolder->fileExists('2026_by.json') ? $holidayFolder->getFile('2026_by.json') : $holidayFolder->newFile('2026_by.json');
$holidayFile->putContent('{}');

foreach (['employee-a', 'employee-b', 'no-mail'] as $suffix) {
	for ($day = new DateTimeImmutable('2026-10-19'); $day <= new DateTimeImmutable('2026-11-15'); $day = $day->modify('+1 day')) {
		if ((int)$day->format('N') > 5) {
			continue;
		}
		$date = $day->format('Y-m-d');
		if ($date === '2026-10-19' || ($suffix === 'employee-b' && $date === '2026-10-20')) {
			continue;
		}
		$entry = new Entry();
		$entry->setUserId($testUsers[$suffix]->getUID());
		$entry->setWorkDate($date);
		$entry->setStartMin($date === '2026-10-22' ? null : 480);
		$entry->setEndMin(in_array($date, ['2026-10-21', '2026-10-22'], true) ? null : 1020);
		$entry->setComment($date === '2026-10-22' ? ' URLAUB ' : ($date === '2026-10-21' ? 'Urlaub' : ''));
		$entry->setBreakMinutes(0);
		$entry->setCreatedAt($clock->getTime());
		$entry->setUpdatedAt($clock->getTime());
		$entryMapper->insert($entry);
	}
}
$entrySnapshot = $database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll();
$balanceSnapshot = $database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->executeQuery()->fetchAll();
$capturingMailer->failEmployeeIds = ['reminder-employee-b'];
$statistics = $makeService()->sendReminders();
verifyReminder($statistics['employeeSent'], 1, 'One successful employee delivery');
verifyReminder($statistics['hrSent'], 2, 'Independent HR summaries');
verifyReminder($statistics['checkedEmployees'], 4, 'Deduplicate groups and exclude disabled accounts');
verifyReminder($capturingMailer->employees['reminder-employee-a'], [
	['date' => '2026-10-19', 'reason' => 'missing'], ['date' => '2026-10-21', 'reason' => 'incomplete'],
], 'Individual gap and commented partial entry are reported');
verifyReminder(isset($capturingMailer->employees['reminder-no-region']), false, 'Unknown holiday region blocks employee delivery');
$hrANames = array_column($capturingMailer->hrSummaries['reminder-hr-a'], 'name');
verifyReminder(in_array('reminder-employee-b', $hrANames, true), false, 'HR A cannot see group B');
verifyReminder(in_array('reminder-no-region', $hrANames, true), true, 'HR receives missing-region notice');
verifyReminder(count($capturingMailer->hrSummaries['reminder-hr-b']), 1, 'HR B sees only its employee');
verifyReminder($store->isDeliveryDue('reminder-employee-b', 'employee', $clock->now()), true, 'Failed delivery stays retryable');
$capturingMailer->failEmployeeIds = [];
$retry = $makeService()->sendReminders();
verifyReminder($retry['employeeSent'], 1, 'Failed employee can retry without resending successful employees');
verifyReminder($retry['hrSent'], 0, 'HR cooldown is independent');

// Later members never inherit gaps from before their first observation.
$groups->get('reminder-employees-a')->addUser($testUsers['late']);
$makeService()->sendReminders();
$enrollment = $store->rememberEnrollment('reminder-late', 'reminder-rule-a', true, '2026-10-01');
verifyReminder($enrollment, ['initialCohort' => false, 'firstDetectedOn' => '2026-10-29'], 'Late joiner begins at first detection');
verifyReminder(isset($capturingMailer->employees['reminder-late']), false, 'Late joiner has no historical reminders');

// Saving and changing policy does not reset successful-delivery timestamps.
$makeService()->prepareRuleChanges($rules, $rules);
verifyReminder($store->isDeliveryDue('reminder-employee-a', 'employee', $clock->now()), false, 'Settings save preserves cooldown');
$rules[0]['missingEntryReminders']['enabled'] = false;
$appConfig->setAppValueString('hr_access_rules', json_encode($rules, JSON_THROW_ON_ERROR));
verifyReminder($makeService()->sendReminders()['checkedEmployees'], 1, 'Disabled winning policy excludes its employees');
$rules[0]['missingEntryReminders']['enabled'] = true;
$appConfig->setAppValueString('hr_access_rules', json_encode($rules, JSON_THROW_ON_ERROR));
$makeService()->prepareRuleChanges($rules, $rules);
verifyReminder($store->rememberEnrollment('reminder-late', 'reminder-rule-a', false, '2026-11-01'), $enrollment, 'Re-enabling preserves first detection');
verifyReminder($store->isDeliveryDue('reminder-employee-a', 'employee', $clock->now()), false, 'Re-enabling preserves cooldown');

$entryMapper->upsertCommentOnly('reminder-employee-a', '2026-10-19', 'Stundenausgleich');
$clock->current = $clock->current->modify('+7 days');
$capturingMailer->employees = [];
$makeService()->sendReminders();
verifyReminder($capturingMailer->employees['reminder-employee-a'], [['date' => '2026-10-21', 'reason' => 'incomplete']], 'Filled gap disappears and new seven-day delivery is due');
verifyReminder($capturingMailer->employees['reminder-late'], [['date' => '2026-10-29', 'reason' => 'missing']], 'Later joiner starts only at its own date');

// Date checks themselves never write entries or balances.
$entryMapper->findByUserAndDate('reminder-employee-a', '2026-10-19');
$makeService()->sendReminders();
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->executeQuery()->fetchAll(), $balanceSnapshot, 'Reminders leave balances untouched');
verifyReminder(count($database->getQueryBuilder()->select('*')->from('ts_entries')->executeQuery()->fetchAll()), count($entrySnapshot) + 1, 'Only the explicitly filled test gap changes entries');

// Deliver through the real Nextcloud mailer only to the guarded Mailpit instance.
$clock->current = $clock->current->modify('+7 days');
$capturingMailer->deliver = true;
$actualDelivery = $makeService()->sendReminders();
verifyReminder($actualDelivery['employeeSent'], 3, 'Real employee messages delivered to Mailpit');
verifyReminder($actualDelivery['hrSent'], 2, 'Real HR messages delivered to Mailpit');

// Old HR notifications remain independently callable with their existing preferences.
$hrPreferences = $container->get(\OCA\Timesheet\Db\HrPreferencesStore::class);
$hrPreferences->saveNotificationSettings('reminder-hr-a', ['overtimeEnabled' => true, 'overtimeThresholdMinutes' => 0]);
$appConfig->setAppValueString('hr_notification_force', '1');
$legacyJob = $container->get(\OCA\Timesheet\BackgroundJob\HrNotificationJob::class);
$legacyRun = new ReflectionMethod($legacyJob, 'run');
$legacyRun->invoke($legacyJob, []);
$appConfig->setAppValueString('hr_notification_force', '0');

// Employee delivery must continue even when no HR account is assigned.
$capturingMailer->deliver = false;
$clock->current = $clock->current->modify('+7 days');
foreach (['a', 'b'] as $letter) {
	$groups->get('reminder-hr-' . $letter)->removeUser($testUsers['hr-' . $letter]);
}
$withoutHr = $makeService()->sendReminders();
verifyReminder($withoutHr['employeeSent'], 3, 'Employee reminders are independent of HR membership');
verifyReminder($withoutHr['hrSent'], 0, 'No HR recipients produces no HR messages');
foreach (['a', 'b'] as $letter) {
	$groups->get('reminder-hr-' . $letter)->addUser($testUsers['hr-' . $letter]);
}

// Failed holiday lookups pause employee delivery and produce scoped HR notices.
$clock->current = $clock->current->modify('+7 days');
$capturingMailer->employees = [];
$capturingMailer->hrSummaries = [];
$holidayFailure = $makeService(new UnavailableReminderHolidays())->sendReminders();
verifyReminder($holidayFailure['employeeSent'], 0, 'Unknown holidays never generate employee warnings');
verifyReminder($holidayFailure['hrSent'], 2, 'HR receives holiday setup notices');
verifyReminder($capturingMailer->employees, [], 'Holiday failure does not attempt employee delivery');
$employeeAFinding = array_values(array_filter($capturingMailer->hrSummaries['reminder-hr-a'], fn($finding) => str_starts_with($finding['name'], 'Employee A')))[0];
verifyReminder($employeeAFinding['setupIssue'], 'holidayUnavailable', 'HR notice identifies unavailable holidays');

$jobQuery = $database->getQueryBuilder();
$registeredJobs = $jobQuery->select('class')->from('jobs')
	->where($jobQuery->expr()->in('class', $jobQuery->createNamedParameter([
		\OCA\Timesheet\BackgroundJob\HrNotificationJob::class,
		\OCA\Timesheet\BackgroundJob\MissingEntryReminderJob::class,
	], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetchAll();
verifyReminder(count($registeredJobs), 2, 'Old and new jobs are both registered once');

echo json_encode(['checks' => $checks, 'result' => 'passed', 'realDelivery' => $actualDelivery], JSON_UNESCAPED_SLASHES) . "\n";
