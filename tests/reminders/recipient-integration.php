<?php

declare(strict_types=1);

// Run only against the disposable database and its private Mailpit transport.
if (PHP_SAPI !== 'cli' || getenv('REMINDER_TEST_ENVIRONMENT') !== '1') {
	throw new RuntimeException('The isolated reminder test environment is required.');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';

use OCA\Timesheet\AppInfo\Application;
use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\MissingEntryReminderStore;
use OCA\Timesheet\Db\UserConfig;
use OCA\Timesheet\Db\UserConfigMapper;
use OCA\Timesheet\Service\AbsenceCommentCatalog;
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
	throw new RuntimeException('Refusing to use any database or mail server outside the disposable fixture.');
}

class RecipientTestClock implements ITimeFactory {
	public function __construct(public DateTimeImmutable $current) {
	}
	public function getTime(): int { return $this->current->getTimestamp(); }
	public function now(): DateTimeImmutable { return $this->current; }
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		$value = $this->current->setTimezone($timezone ?? new DateTimeZone('UTC'));
		if ($time === 'today') $value = $value->setTime(0, 0);
		elseif ($time !== 'now') $value = $value->modify($time);
		return DateTime::createFromImmutable($value);
	}
	public function withTimeZone(DateTimeZone $timezone): static {
		$copy = clone $this;
		$copy->current = $copy->current->setTimezone($timezone);
		return $copy;
	}
	public function getTimeZone(?string $timezone = null): DateTimeZone { return new DateTimeZone($timezone ?? 'UTC'); }
}

/** Records the separate envelopes while using the real Nextcloud mailer. */
class RecipientTestMailer extends MissingEntryReminderMailer {
	public array $employeeIds = [];
	public array $hrFindings = [];
	public function __construct(private MissingEntryReminderMailer $realMailer) {
	}
	public function sendEmployeeReminder(IUser $employee, array $issues): bool {
		$this->employeeIds[] = $employee->getUID();
		return $this->realMailer->sendEmployeeReminder($employee, $issues);
	}
	public function sendHrSummary(IUser $hrUser, array $employees): bool {
		$this->hrFindings[$hrUser->getUID()] = $employees;
		return $this->realMailer->sendHrSummary($hrUser, $employees);
	}
	public function reset(): void { $this->employeeIds = []; $this->hrFindings = []; }
}

$checks = 0;
function verifyRecipient(mixed $actual, mixed $expected, string $description): void {
	global $checks;
	$checks++;
	if ($actual !== $expected) throw new RuntimeException($description . ': ' . json_encode(['actual' => $actual, 'expected' => $expected], JSON_UNESCAPED_UNICODE));
	echo 'PASS ' . $description . "\n";
}

$container = (new Application())->getContainer();
$sharedConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$appConfig = $container->get(\OCP\AppFramework\Services\IAppConfig::class);
$users = \OCP\Server::get(\OCP\IUserManager::class);
$groups = \OCP\Server::get(\OCP\IGroupManager::class);
$session = \OCP\Server::get(\OCP\IUserSession::class);
$userConfigMapper = $container->get(UserConfigMapper::class);
$entryMapper = $container->get(EntryMapper::class);
$store = $container->get(MissingEntryReminderStore::class);
$clock = new RecipientTestClock(new DateTimeImmutable('2026-11-04 10:00:00', new DateTimeZone('Europe/Berlin')));
$mailer = new RecipientTestMailer($container->get(MissingEntryReminderMailer::class));
$password = getenv('REMINDER_TEST_PASSWORD');
if (!$password) throw new RuntimeException('The synthetic account password is missing.');
$people = [];
$labels = [
	'both' => 'Both Recipient', 'employee-only' => 'Employee Only Recipient',
	'hr-only' => 'HR Only Recipient', 'neither' => 'Neither Recipient',
	'employee-setup' => 'Employee Only Setup', 'hr-setup' => 'HR Only Setup',
	'hr' => 'HR Recipient',
];
$activityGroup = $groups->get('Zeitnachweis') ?? $groups->createGroup('Zeitnachweis');
foreach ($labels as $suffix => $name) {
	$userId = 'recipient-' . $suffix;
	$user = $users->get($userId) ?? $users->createUser($userId, $password);
	$user->setEnabled(true);
	$user->setDisplayName($name);
	$user->setEMailAddress($userId . '@example.test');
	$config->setUserValue($userId, 'core', 'lang', 'de');
	$config->setUserValue($userId, 'firstrunwizard', 'show', '0');
	foreach (['missing_entry_enrollments', 'missing_entry_employee_last_sent', 'missing_entry_hr_last_sent', 'employment_status'] as $key) {
		$config->deleteUserValue($userId, 'timesheet', $key);
	}
	foreach (['ts_entries', 'ts_user_config', 'ts_monthly_balances'] as $table) {
		$query = $database->getQueryBuilder();
		$query->delete($table)->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
	}
	$workConfig = new UserConfig();
	$workConfig->setUserId($userId);
	$workConfig->setWorkMinutes(480);
	$workConfig->setState(str_ends_with($suffix, '-setup') ? '' : 'BY');
	$userConfigMapper->insert($workConfig);
	if ($suffix !== 'hr') $activityGroup->addUser($user);
	$people[$suffix] = $user;
}
unset($password);
$session->setUser($users->get('recipient-split-admin'));
$rules = [];
foreach (['both', 'employee-only', 'hr-only', 'neither', 'employee-setup', 'hr-setup'] as $suffix) {
	$groupId = 'recipient-staff-' . $suffix;
	$group = $groups->get($groupId) ?? $groups->createGroup($groupId);
	foreach ($group->getUsers() as $member) $group->removeUser($member);
	$group->addUser($people[$suffix]);
	$rules[] = [
		'id' => 'recipient-rule-' . $suffix, 'hrGroups' => [], 'hrUsers' => ['recipient-hr'],
		'userGroups' => [$groupId], 'priority' => 1,
		'missingEntryReminders' => [
			'enabled' => true,
			'notifyEmployees' => in_array($suffix, ['both', 'employee-only', 'employee-setup'], true),
			'notifyHr' => in_array($suffix, ['both', 'hr-only', 'hr-setup'], true),
			'graceDays' => 1, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => '2026-11-02',
		],
	];
	$appConfig->setAppValueString('missing_entry_rule_' . substr(hash('sha256', 'recipient-rule-' . $suffix), 0, 32), '0');
}
$initialRules = $rules;
$setRules = static function (array $newRules) use ($sharedConfig): void {
	$sharedConfig->setValueString('timesheet', 'hr_access_rules', json_encode($newRules, JSON_THROW_ON_ERROR));
	$sharedConfig->clearCache();
};
$makeService = static function () use ($container, $entryMapper, $userConfigMapper, $appConfig, $session, $groups, $users, $store, $clock, $mailer, $config): MissingEntryReminderService {
	return new MissingEntryReminderService(
		$container->get(GroupRuleService::class), $entryMapper, $userConfigMapper,
		new HrService($appConfig, $session, $groups, $users), $container->get(HolidayService::class),
		new MissingEntryDetector(new AbsenceCommentCatalog()), $store, $mailer, $groups, $users,
		$clock, \OCP\Server::get(\Psr\Log\LoggerInterface::class),
		new EmploymentStatusStore($config, \OCP\Server::get(\OCP\Lock\ILockingProvider::class), $clock, $groups),
	);
};

// Cached synthetic holidays keep this check independent of external services.
$appData = $container->get(\OCP\Files\IAppData::class);
try { $holidayFolder = $appData->getFolder('holidays'); }
catch (\OCP\Files\NotFoundException) { $holidayFolder = $appData->newFolder('holidays'); }
$holidayFile = $holidayFolder->fileExists('2026_by.json') ? $holidayFolder->getFile('2026_by.json') : $holidayFolder->newFile('2026_by.json');
$holidayFile->putContent('{}');
if (file_get_contents('http://mailpit:8025/api/v1/messages?limit=1000') === false) throw new RuntimeException('Private Mailpit is unavailable.');
$deleteMailbox = stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true]]);
file_get_contents('http://mailpit:8025/api/v1/messages', false, $deleteMailbox);
$setRules($rules);
$makeService()->prepareRuleChanges([], $rules);
$entryCount = (int)$database->getQueryBuilder()->selectAlias($database->getQueryBuilder()->func()->count('*'), 'count')->from('ts_entries')->executeQuery()->fetchOne();
$balanceCount = (int)$database->getQueryBuilder()->selectAlias($database->getQueryBuilder()->func()->count('*'), 'count')->from('ts_monthly_balances')->executeQuery()->fetchOne();

$statistics = $makeService()->sendReminders();
sort($mailer->employeeIds);
verifyRecipient($mailer->employeeIds, ['recipient-both', 'recipient-employee-only'], 'The four flag combinations deliver only the two enabled employee reminders');
verifyRecipient($statistics['employeeSent'], 2, 'Two real employee emails succeed');
verifyRecipient($statistics['hrSent'], 1, 'One real HR summary succeeds');
$hrNames = array_column($mailer->hrFindings['recipient-hr'], 'name'); sort($hrNames);
verifyRecipient($hrNames, ['Both Recipient', 'HR Only Recipient', 'HR Only Setup'], 'Mixed HR summary excludes employee-only and fully disabled rules');
verifyRecipient($mailer->hrFindings['recipient-hr'][array_search('HR Only Setup', array_column($mailer->hrFindings['recipient-hr'], 'name'), true)]['setupIssue'], 'missingRegion', 'HR-enabled setup problems remain visible to HR');
verifyRecipient(in_array('Employee Only Setup', $hrNames, true), false, 'Employee-only setup problems do not produce HR notifications');

$mailbox = json_decode(file_get_contents('http://mailpit:8025/api/v1/messages?limit=1000'), true, 512, JSON_THROW_ON_ERROR);
$recipientAddresses = [];
foreach ($mailbox['messages'] as $message) foreach ($message['To'] as $recipient) $recipientAddresses[] = $recipient['Address'];
sort($recipientAddresses);
verifyRecipient($recipientAddresses, ['recipient-both@example.test', 'recipient-employee-only@example.test', 'recipient-hr@example.test'], 'Private Mailpit receives exactly the enabled employee and HR envelopes');
foreach ($mailbox['messages'] as $message) {
	if ($message['To'][0]['Address'] !== 'recipient-hr@example.test') continue;
	$details = json_decode(file_get_contents('http://mailpit:8025/api/v1/message/' . rawurlencode($message['ID'])), true, 512, JSON_THROW_ON_ERROR);
	verifyRecipient(str_contains($details['Text'], 'Employee Only Recipient'), false, 'The actual HR email body contains no employee-only finding');
	verifyRecipient(str_contains($details['Text'], 'HR Only Recipient'), true, 'The actual HR email body contains its enabled HR-only finding');
}

// Recipient changes do not reset either delivery interval or enrollment.
$enrollment = $store->rememberEnrollment('recipient-both', 'recipient-rule-both', false, '2026-11-04');
$lastEmployee = $config->getUserValue('recipient-both', 'timesheet', 'missing_entry_employee_last_sent');
$lastHr = $config->getUserValue('recipient-hr', 'timesheet', 'missing_entry_hr_last_sent');
$clock->current = $clock->current->modify('+1 hour');
$rules[0]['missingEntryReminders']['notifyEmployees'] = false;
$rules[1]['missingEntryReminders']['notifyEmployees'] = false;
$rules[1]['missingEntryReminders']['notifyHr'] = true;
$rules[2]['missingEntryReminders']['notifyEmployees'] = true;
$rules[2]['missingEntryReminders']['notifyHr'] = false;
$setRules($rules); $makeService()->prepareRuleChanges($initialRules, $rules); $mailer->reset();
$switched = $makeService()->sendReminders();
verifyRecipient($mailer->employeeIds, ['recipient-hr-only'], 'Enabling employee delivery uses its independent previously unused interval');
verifyRecipient($switched['hrSent'], 0, 'Newly enabled HR findings do not bypass the existing HR interval');
verifyRecipient($config->getUserValue('recipient-both', 'timesheet', 'missing_entry_employee_last_sent'), $lastEmployee, 'Disabling an employee toggle preserves its delivery timestamp');
verifyRecipient($config->getUserValue('recipient-hr', 'timesheet', 'missing_entry_hr_last_sent'), $lastHr, 'Changing recipients preserves the HR delivery timestamp');
verifyRecipient($store->rememberEnrollment('recipient-both', 'recipient-rule-both', false, '2026-12-01'), $enrollment, 'Recipient toggles preserve the original enrollment');

$rules[0]['missingEntryReminders']['notifyEmployees'] = true;
$setRules($rules); $mailer->reset();
verifyRecipient($makeService()->sendReminders()['employeeSent'], 0, 'Re-enabling an employee toggle does not bypass its successful delivery interval');
$clock->current = $clock->current->modify('+7 days'); $mailer->reset();
$due = $makeService()->sendReminders();
sort($mailer->employeeIds);
verifyRecipient($mailer->employeeIds, ['recipient-both', 'recipient-hr-only'], 'After seven days only currently enabled employee recipients receive another reminder');
$hrNames = array_column($mailer->hrFindings['recipient-hr'], 'name'); sort($hrNames);
verifyRecipient($hrNames, ['Both Recipient', 'Employee Only Recipient', 'HR Only Setup'], 'The next HR summary reflects the independently changed HR toggles');
verifyRecipient($due['hrSent'], 1, 'The HR summary is sent again after its own seven-day interval');

// An employee-only rule still works when there is no responsible HR account.
$clock->current = $clock->current->modify('+7 days');
foreach ($rules as &$rule) {
	$rule['hrUsers'] = [];
	$rule['missingEntryReminders']['notifyEmployees'] = $rule['id'] === 'recipient-rule-employee-only';
	$rule['missingEntryReminders']['notifyHr'] = false;
}
unset($rule);
$setRules($rules); $mailer->reset();
$withoutHr = $makeService()->sendReminders();
verifyRecipient($mailer->employeeIds, ['recipient-employee-only'], 'Employee-only delivery works without any responsible HR account');
verifyRecipient($withoutHr['hrSent'], 0, 'Employee-only delivery does not create an HR summary');
verifyRecipient((int)$database->getQueryBuilder()->selectAlias($database->getQueryBuilder()->func()->count('*'), 'count')->from('ts_entries')->executeQuery()->fetchOne(), $entryCount, 'Recipient preferences never change time entries');
verifyRecipient((int)$database->getQueryBuilder()->selectAlias($database->getQueryBuilder()->func()->count('*'), 'count')->from('ts_monthly_balances')->executeQuery()->fetchOne(), $balanceCount, 'Recipient preferences never change balances');

// Restore the matrix for HTTP persistence checks and visual verification.
$setRules($initialRules);
echo 'Recipient integration: ' . $checks . " checks passed.\n";
