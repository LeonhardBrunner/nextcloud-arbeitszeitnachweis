<?php

declare(strict_types=1);

// Reuse the guarded, disposable Nextcloud/Mailpit fixture and its regression checks.
if (PHP_SAPI !== 'cli' || getenv('REMINDER_TEST_ENVIRONMENT') !== '1') {
	fwrite(STDERR, "The dedicated reminder test environment is required.\n");
	exit(1);
}
$baselineRoot = getenv('EMPLOYMENT_BASELINE_ROOT');
if ($baselineRoot) {
	foreach (['MissingEntryDetector', 'MissingEntryReminderService', 'HrNotificationService'] as $serviceName) {
		require $baselineRoot . '/' . $serviceName . '.php';
	}
}
require dirname(__DIR__) . '/reminders/integration.php';

use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Db\UserConfig;
use OCA\Timesheet\Service\HrNotificationService;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\MonthlyBalanceService;

class EmploymentBalanceFixture extends MonthlyBalanceService {
	public array $summaries = [];
	public function __construct() {
	}
	public function calculateCurrentBalance(string $userId): array {
		return $this->summaries[$userId] ?? ['lastEntryDate' => '2026-10-01', 'to' => '2026-10-01', 'overtimeMinutes' => 120];
	}
}

$initialChecks = $checks;
$clock->current = new DateTimeImmutable('2026-10-19 10:00:00', new DateTimeZone('Europe/Berlin'));
$statuses = new EmploymentStatusStore($config, \OCP\Server::get(\OCP\Lock\ILockingProvider::class), $clock, $groups);
$employmentUsers = [];
$password = getenv('REMINDER_TEST_PASSWORD');
$names = ['active' => 'Marvin Adler', 'freelancer' => 'Marvin Berg', 'inactive' => 'Clara Inaktiv', 'returned' => 'Dora Rückkehr', 'never' => 'Erik Ohne Eintrag', 'foreign' => 'Marvin Fremd'];
foreach (['active', 'freelancer', 'inactive', 'returned', 'never', 'foreign', 'hr-active', 'hr-freelancer', 'hr-inactive', 'hr-foreign'] as $suffix) {
	$userId = 'employment-' . $suffix;
	$user = $users->get($userId) ?? $users->createUser($userId, $password);
	$user->setEnabled($suffix !== 'hr-inactive');
	$user->setDisplayName($names[$suffix] ?? $userId);
	$user->setEMailAddress($userId . '@example.test');
	$config->setUserValue($userId, 'core', 'lang', 'de');
	foreach (['employment_status', 'missing_entry_enrollments', 'missing_entry_employee_last_sent', 'missing_entry_hr_last_sent', 'tour_completed'] as $key) {
		$config->deleteUserValue($userId, 'timesheet', $key);
	}
	foreach (['ts_entries', 'ts_user_config', 'ts_monthly_balances'] as $table) {
		$query = $database->getQueryBuilder();
		$query->delete($table)->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
	}
	$workConfig = new UserConfig();
	$workConfig->setUserId($userId);
	$workConfig->setWorkMinutes(480);
	$workConfig->setState('BY');
	$configMapper->insert($workConfig);
	$employmentUsers[$suffix] = $user;
}
unset($password);
$activityGroup = $groups->get('Zeitnachweis') ?? $groups->createGroup('Zeitnachweis');
foreach (['active', 'freelancer', 'returned', 'never', 'foreign'] as $suffix) {
	$activityGroup->addUser($employmentUsers[$suffix]);
}
foreach (['inactive', 'hr-active', 'hr-freelancer', 'hr-inactive', 'hr-foreign'] as $suffix) {
	$activityGroup->removeUser($employmentUsers[$suffix]);
}
$memberships = [
	'employment-staff' => ['active', 'freelancer', 'inactive', 'returned', 'never'],
	'employment-hr' => ['hr-active', 'hr-freelancer', 'hr-inactive'],
	'employment-foreign-staff' => ['foreign'],
	'employment-foreign-hr' => ['hr-foreign'],
];
foreach ($memberships as $groupId => $suffixes) {
	$group = $groups->get($groupId) ?? $groups->createGroup($groupId);
	foreach ($group->getUsers() as $member) {
		$group->removeUser($member);
	}
	foreach ($suffixes as $suffix) {
		$group->addUser($employmentUsers[$suffix]);
	}
}
$rules = [];
foreach (['', '-foreign'] as $scope) {
	$rules[] = [
		'id' => 'employment-rule' . $scope,
		'hrGroups' => ['employment' . $scope . '-hr'],
		'userGroups' => ['employment' . $scope . '-staff'],
		'priority' => 1,
		'missingEntryReminders' => ['enabled' => true, 'graceDays' => 7, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => '2026-10-12'],
	];
	$appConfig->setAppValueString('missing_entry_rule_' . substr(hash('sha256', 'employment-rule' . $scope), 0, 32), '0');
}
$appConfig->setAppValueString('hr_access_rules', json_encode($rules, JSON_THROW_ON_ERROR));
$capturingMailer->deliver = false;
$makeService()->prepareRuleChanges([], $rules);
$statuses->setFreelancerMode('employment-freelancer', true, $statuses->getStatus('employment-freelancer')->statusRevision);
$statuses->syncActivity('employment-inactive');
$statuses->setFreelancerMode('employment-hr-freelancer', true, $statuses->getStatus('employment-hr-freelancer')->statusRevision);
// Existing dated exemptions survive the switch from manual status to group membership.
$config->setUserValue('employment-returned', 'timesheet', 'employment_status', json_encode([
	'version' => 1, 'nonce' => str_repeat('a', 32), 'isActive' => true, 'freelancerMode' => false,
	'freelancerPeriods' => [['from' => '2026-10-19', 'to' => '2026-10-24']],
	'inactivePeriods' => [['from' => '2026-10-22', 'to' => '2026-10-26']],
], JSON_THROW_ON_ERROR));
$clock->current = new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Europe/Berlin'));
verifyReminder($statuses->getStatus('employment-returned')->getExcludedPeriods(), [['from' => '2026-10-19', 'to' => '2026-10-26']], 'Group activity preserves overlapping historical exemptions');
foreach (['active' => '2026-10-13', 'returned' => '2026-10-20'] as $suffix => $date) {
	$entry = new \OCA\Timesheet\Db\Entry();
	$entry->setUserId('employment-' . $suffix);
	$entry->setWorkDate($date);
	$entry->setStartMin(480);
	$entry->setEndMin(null);
	$entry->setBreakMinutes(0);
	$entry->setComment('');
	$entry->setCreatedAt($clock->getTime());
	$entry->setUpdatedAt($clock->getTime());
	$entryMapper->insert($entry);
}
$unchangedEntries = $database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll();
$unchangedBalances = $database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll();
$capturingMailer->employees = [];
$capturingMailer->hrSummaries = [];
$employmentDelivery = $makeService()->sendReminders();
verifyReminder(array_keys($capturingMailer->employees), ['employment-active', 'employment-returned', 'employment-never', 'employment-foreign'], 'Daily mail excludes current freelancers and inactive subjects');
verifyReminder(isset($capturingMailer->hrSummaries['employment-hr-inactive']), false, 'Inactive HR receives no daily summary');
verifyReminder(isset($capturingMailer->hrSummaries['employment-hr-freelancer']), false, 'Freelance HR receives no assigned employee reminders');
verifyReminder($groups->isInGroup('employment-hr-active', 'Zeitnachweis'), false, 'HR recipients do not need employee activity membership');
$returnedDates = array_column($capturingMailer->employees['employment-returned'], 'date');
verifyReminder(in_array('2026-10-12', $returnedDates, true), true, 'An earlier regular hole remains eligible after return');
verifyReminder(in_array('2026-10-19', $returnedDates, true), false, 'Historical freelancer day remains excluded');
verifyReminder(in_array('2026-10-20', $returnedDates, true), false, 'Historical incomplete freelancer entry remains excluded');
verifyReminder(in_array('2026-10-23', $returnedDates, true), false, 'Historical inactive day remains excluded');
verifyReminder(in_array('2026-10-26', $returnedDates, true), true, 'Return date is regular again');
verifyReminder(array_values(array_filter($capturingMailer->employees['employment-active'], static fn(array $issue) => $issue['date'] === '2026-10-13')), [['date' => '2026-10-13', 'reason' => 'incomplete']], 'Regular incomplete entries are still reported');
$hrNames = array_column($capturingMailer->hrSummaries['employment-hr-active'], 'name');
sort($hrNames);
verifyReminder($hrNames, ['Dora Rückkehr', 'Erik Ohne Eintrag', 'Marvin Adler'], 'HR daily summary respects both status and responsibility');
$cooldownBefore = $config->getUserValue('employment-active', 'timesheet', 'missing_entry_employee_last_sent', '');
$statuses->setFreelancerMode('employment-active', true, $statuses->getStatus('employment-active')->statusRevision);
$statuses->setFreelancerMode('employment-active', false, $statuses->getStatus('employment-active')->statusRevision);
verifyReminder($config->getUserValue('employment-active', 'timesheet', 'missing_entry_employee_last_sent', ''), $cooldownBefore, 'Same-day mode changes preserve successful-delivery cooldown');
verifyReminder($makeService()->sendReminders()['employeeSent'], 0, 'Status changes do not bypass the seven-day limit');

// Exclude freelancers from every legacy warning category and preserve regular return delays.
foreach (['hr-active', 'hr-freelancer', 'hr-inactive', 'hr-foreign'] as $suffix) {
	$hrPreferences->saveNotificationSettings('employment-' . $suffix, [
		'noEntryEnabled' => true,
		'overtimeEnabled' => true,
		'negativeOvertimeEnabled' => true,
		'warningThresholds' => ['noEntryDays' => 14, 'overtimeThresholdMinutes' => 60, 'negativeOvertimeThresholdMinutes' => 60],
	]);
}
$balances = new EmploymentBalanceFixture();
$balances->summaries['employment-never'] = [];
$balances->summaries['employment-returned'] = ['lastEntryDate' => null, 'to' => '2026-11-02', 'overtimeMinutes' => 120];
$makeLegacy = static fn() => new HrNotificationService($hrPreferences, new HrService($appConfig, $session, $groups, $users), $balances, $statuses, $clock, $logger, $users);
$legacyWarnings = $makeLegacy()->doCron();
verifyReminder(isset($legacyWarnings['employment-hr-inactive']), false, 'Inactive HR receives no legacy warnings');
verifyReminder(isset($legacyWarnings['employment-hr-freelancer']), false, 'Freelance HR receives no legacy warnings');
verifyReminder(array_column($legacyWarnings['employment-hr-active']['noEntry'], 'user'), ['Marvin Adler'], 'Weekly inactivity mail requires a complete entry or return date and waits after return');
verifyReminder(array_column($legacyWarnings['employment-hr-active']['overtime'], 'user'), ['Dora Rückkehr', 'Marvin Adler'], 'Overtime warnings exclude freelancers and inactive subjects');
$balances->summaries['employment-freelancer'] = ['lastEntryDate' => '2026-10-01', 'to' => '2026-10-01', 'overtimeMinutes' => -120];
$negativeWarnings = $makeLegacy()->doCron();
verifyReminder(array_column($negativeWarnings['employment-hr-active']['negative'], 'user'), [], 'Negative-balance warnings exclude freelancers');
$clock->current = $clock->current->modify('+7 days');
$balances->summaries['employment-returned']['to'] = '2026-11-09';
$afterFullDelay = $makeLegacy()->doCron();
verifyReminder(in_array('Dora Rückkehr', array_column($afterFullDelay['employment-hr-active']['noEntry'], 'user'), true), true, 'Legacy reminder resumes exactly after a full new fourteen-day delay');
$balances->summaries['employment-returned'] = ['lastEntryDate' => '2026-11-08', 'to' => '2026-11-08', 'overtimeMinutes' => 120];
verifyReminder(in_array('Dora Rückkehr', array_column($makeLegacy()->doCron()['employment-hr-active']['noEntry'], 'user'), true), false, 'A newer actual entry takes precedence over return date');

// A corrupt exemption record must fail closed for both independent mail paths.
$config->setUserValue('employment-active', 'timesheet', 'employment_status', '{invalid');
$capturingMailer->employees = [];
$capturingMailer->hrSummaries = [];
$makeService()->sendReminders();
verifyReminder(isset($capturingMailer->employees['employment-active']), false, 'Unreadable status never unmutes daily employee reminders');
$corruptSummary = $capturingMailer->hrSummaries['employment-hr-active'] ?? [];
verifyReminder(in_array('Marvin Adler', array_column($corruptSummary, 'name'), true), false, 'Unreadable status does not become an HR setup warning');
verifyReminder(in_array('Marvin Adler', array_column($makeLegacy()->doCron()['employment-hr-active']['noEntry'], 'user'), true), false, 'Unreadable status also blocks legacy subject warnings');
$config->deleteUserValue('employment-active', 'timesheet', 'employment_status');

// Leave only synthetic, deterministic fixtures for native browser verification.
$clock->current = new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Europe/Berlin'));
$capturingMailer->deliver = true;
$capturingMailer->employees = [];
$capturingMailer->hrSummaries = [];
foreach (['active', 'returned', 'never', 'foreign', 'hr-active', 'hr-freelancer', 'hr-foreign'] as $suffix) {
	foreach (['missing_entry_employee_last_sent', 'missing_entry_hr_last_sent'] as $key) {
		$config->deleteUserValue('employment-' . $suffix, 'timesheet', $key);
	}
}
$realEmploymentDelivery = $makeService()->sendReminders();
verifyReminder($realEmploymentDelivery['employeeSent'], 4, 'Status-aware employee mails reach isolated Mailpit');
verifyReminder($realEmploymentDelivery['hrSent'], 2, 'Status-aware scoped HR mails exclude freelancer recipients in isolated Mailpit');
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll(), $unchangedEntries, 'Both reminder paths leave all time entries unchanged');
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll(), $unchangedBalances, 'Both reminder paths leave existing balances unchanged');

// Exercise native group events with the real clock, without rewriting time data.
\OCP\Server::get(\OC\AppFramework\Bootstrap\Coordinator::class)->bootApp('timesheet');
$liveStatuses = $container->get(EmploymentStatusStore::class);
$scopedHr = new HrService($appConfig, $session, $groups, $users);
$scopeBefore = $scopedHr->getAccessibleUsers('employment-hr-active');
verifyReminder(count($scopeBefore), 5, 'All assigned employees remain in the HR scope');
verifyReminder(in_array('employment-foreign', array_column($scopeBefore, 'id'), true), false, 'Activity membership never extends HR responsibility');
verifyReminder($scopedHr->isHr('employment-inactive'), false, 'An inactive assigned employee has no HR role');
$liveStatuses->getStatus('employment-active');
$activityGroup->removeUser($employmentUsers['active']);
$removedState = $config->getUserValueForUsers('timesheet', 'employment_status', ['employment-active']);
$removedState = json_decode($removedState['employment-active'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
verifyReminder($removedState['isActive'] ?? null, false, 'Removing activity membership synchronizes the native event immediately');
verifyReminder($liveStatuses->getStatus('employment-active')->isActive, false, 'Removed activity membership is inactive');
verifyReminder($scopedHr->getAccessibleUsers('employment-hr-active'), $scopeBefore, 'Removing activity membership retains assigned HR access');
$activityGroup->addUser($employmentUsers['active']);
$restoredState = $config->getUserValueForUsers('timesheet', 'employment_status', ['employment-active']);
$restoredState = json_decode($restoredState['employment-active'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
verifyReminder($restoredState['isActive'] ?? null, true, 'Restoring activity membership synchronizes the native event immediately');
verifyReminder($liveStatuses->getStatus('employment-active')->freelancerMode, false, 'Activity changes preserve freelancer classification');
$registeredRoutes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
verifyReminder(count(array_filter($registeredRoutes['routes'], static fn(array $route): bool => $route['name'] === 'employmentStatus#setActivity')), 0, 'No manual activity route remains');
verifyReminder(method_exists(\OCA\Timesheet\Controller\EmploymentStatusController::class, 'setActivity'), false, 'No manual controller method can override group activity');
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll(), $unchangedEntries, 'Native activity changes preserve existing time entries');
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll(), $unchangedBalances, 'Native activity changes preserve existing balances');
echo json_encode(['employmentChecks' => $checks - $initialChecks, 'result' => 'passed', 'realDelivery' => $realEmploymentDelivery], JSON_UNESCAPED_SLASHES) . "\n";
