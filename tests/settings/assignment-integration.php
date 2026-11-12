<?php

declare(strict_types=1);

// Reuse the guarded reminder database and synthetic accounts; production instances are refused there.
if (PHP_SAPI !== 'cli' || getenv('REMINDER_TEST_ENVIRONMENT') !== '1') {
	fwrite(STDERR, "The dedicated reminder test environment is required.\n");
	exit(1);
}
require dirname(__DIR__) . '/employment/integration.php';

use OCA\Timesheet\BackgroundJob\HrNotificationJob;
use OCA\Timesheet\Db\UserConfig;
use OCA\Timesheet\Service\HrNotificationService;
use OCA\Timesheet\Service\HrRuleAssignments;
use OCA\Timesheet\Service\HrService;

$assignmentStartChecks = $checks;
$assignmentOriginalRules = $rules;
$assignmentOriginalRulesJson = $appConfig->getAppValueString('hr_access_rules');
$assignmentEntries = $database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll();
$assignmentBalances = $database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll();
$assignmentClock = new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Europe/Berlin'));
$clock->current = $assignmentClock;
$capturingMailer->deliver = false;

$assignmentUsers = [];
$password = getenv('REMINDER_TEST_PASSWORD');
foreach (['direct' => 'Dana Direkt', 'extra' => 'Eva Ohne Zuständigkeit'] as $suffix => $name) {
	$userId = 'assignment-' . $suffix;
	$user = $users->get($userId) ?? $users->createUser($userId, $password);
	$user->setEnabled(true);
	$user->setDisplayName($name);
	$user->setEMailAddress($userId . '@example.test');
	$config->setUserValue($userId, 'core', 'lang', 'de');
	try {
		$configMapper->findByUser($userId);
	} catch (\OCP\AppFramework\Db\DoesNotExistException) {
		$workConfig = new UserConfig();
		$workConfig->setUserId($userId);
		$workConfig->setWorkMinutes(480);
		$workConfig->setState('BY');
		$configMapper->insert($workConfig);
	}
	$hrPreferences->saveNotificationSettings($userId, [
		'noEntryEnabled' => true,
		'overtimeEnabled' => true,
		'negativeOvertimeEnabled' => true,
		'warningThresholds' => ['noEntryDays' => 14, 'overtimeThresholdMinutes' => 60, 'negativeOvertimeThresholdMinutes' => 60],
	]);
	$assignmentUsers[$suffix] = $user;
}
unset($password);

$assignmentHrIds = ['employment-hr-active', 'employment-hr-freelancer', 'employment-hr-inactive', 'employment-hr-foreign', 'assignment-direct', 'assignment-extra'];
$assignmentResetDelivery = static function () use ($assignmentHrIds, $config, $capturingMailer): void {
	// Each scenario starts with due synthetic HR recipients; employee cooldowns are never reset.
	foreach ($assignmentHrIds as $userId) {
		$config->deleteUserValue($userId, 'timesheet', 'missing_entry_hr_last_sent');
	}
	$capturingMailer->employees = [];
	$capturingMailer->hrSummaries = [];
};
$assignmentScope = static fn() => new HrService($appConfig, $session, $groups, $users);
$assignmentBalanceFixture = new EmploymentBalanceFixture();
$assignmentBalanceFixture->summaries['employment-never'] = [];
$assignmentLegacy = static fn() => new HrNotificationService(
	$hrPreferences, new HrService($appConfig, $session, $groups, $users),
	$assignmentBalanceFixture, $statuses, $clock, $logger, $users,
);
$assignmentWarningNames = static function (array $warnings): array {
	$names = [];
	foreach (['noEntry', 'overtime', 'negative'] as $category) {
		$names = array_merge($names, array_column($warnings[$category] ?? [], 'user'));
	}
	$names = array_values(array_unique($names));
	sort($names);
	return $names;
};
$assignmentPublish = static function (array $updatedRules) use ($appConfig, $assignmentResetDelivery): void {
	$appConfig->setAppValueString('hr_access_rules', json_encode($updatedRules, JSON_THROW_ON_ERROR));
	$assignmentResetDelivery();
};

// Legacy rules keep the same rights, recipients and stored representation.
$assignmentResetDelivery();
verifyReminder($assignmentScope()->getMailRecipientUserIds(), ['employment-hr-active', 'employment-hr-freelancer', 'employment-hr-foreign'], 'Existing rules keep all enabled responsible HR recipients');
verifyReminder($makeService()->sendReminders()['hrSent'], 2, 'Existing daily HR delivery excludes freelancer recipients');
verifyReminder(array_keys($assignmentLegacy()->doCron()), ['employment-hr-active', 'employment-hr-foreign'], 'Existing legacy HR delivery excludes freelancer recipients');
verifyReminder($appConfig->getAppValueString('hr_access_rules'), $assignmentOriginalRulesJson, 'Reading legacy assignments never rewrites stored rules');

// The same HR account may see both rules but receive email from only one of them.
$foreignHrGroup = $groups->get('employment-foreign-hr');
$foreignHrGroup->addUser($employmentUsers['hr-active']);
$assignmentMixedRules = $assignmentOriginalRules;
$assignmentMixedRules[0]['hrUsers'] = ['assignment-direct'];
$assignmentMixedRules[0]['mailRecipients'] = ['mode' => 'selected', 'userIds' => ['assignment-direct']];
$assignmentMixedRules[1]['hrUsers'] = ['assignment-direct'];
$assignmentMixedRules[1]['mailRecipients'] = ['mode' => 'selected', 'userIds' => ['employment-hr-active']];
$assignmentPublish($assignmentMixedRules);
$scope = $assignmentScope();
verifyReminder($scope->isHr('assignment-direct'), true, 'An individual account becomes responsible HR without an HR group');
verifyReminder($groups->isInGroup('assignment-direct', 'employment-hr'), false, 'Individual assignment never changes group memberships');
verifyReminder(count($scope->getAccessibleUsers('assignment-direct')), 6, 'Direct HR can access both assigned employee groups');
verifyReminder(count($scope->getAccessibleUsers('employment-hr-active')), 6, 'Mail restriction keeps broader existing HR access');
verifyReminder(count($scope->getMailAccessibleUsers('assignment-direct')), 5, 'Direct HR mail is limited to its selected rule');
verifyReminder(array_column($scope->getMailAccessibleUsers('employment-hr-active'), 'id'), ['employment-foreign'], 'Group HR mail excludes rules from which it was not selected');
verifyReminder($scope->isHr('assignment-extra'), false, 'An unrelated account has no HR rights');
verifyReminder($scope->getMailRecipientUserIds(), ['assignment-direct', 'employment-hr-active'], 'Only explicitly selected responsible accounts receive mail');
$mixedDelivery = $makeService()->sendReminders();
verifyReminder($mixedDelivery['hrSent'], 2, 'The daily path sends once to each selected HR account');
$directNames = array_column($capturingMailer->hrSummaries['assignment-direct'], 'name'); sort($directNames);
verifyReminder($directNames, ['Dora Rückkehr', 'Erik Ohne Eintrag', 'Marvin Adler'], 'Direct HR daily summary contains only its selected employee group');
verifyReminder(array_column($capturingMailer->hrSummaries['employment-hr-active'], 'name'), ['Marvin Fremd'], 'Group HR daily summary excludes its unselected employee group');
verifyReminder(isset($capturingMailer->hrSummaries['employment-hr-freelancer']), false, 'Unselected group members keep access but receive no daily HR mail');
$mixedLegacy = $assignmentLegacy()->doCron();
verifyReminder(array_keys($mixedLegacy), ['assignment-direct', 'employment-hr-active'], 'The older warning path uses the same selected recipients');
verifyReminder($assignmentWarningNames($mixedLegacy['assignment-direct']), ['Dora Rückkehr', 'Marvin Adler'], 'Direct weekly warnings use the shared thresholds for its selected employee group');
verifyReminder($assignmentWarningNames($mixedLegacy['employment-hr-active']), ['Marvin Fremd'], 'Group legacy warnings exclude the unselected rule');

// Verify actual legacy email delivery and bodies in the isolated Mailpit instance.
$assignmentMailbox = static function (): array {
	$content = file_get_contents('http://mailpit:8025/api/v1/messages?limit=1000');
	if ($content === false) throw new RuntimeException('The isolated Mailpit mailbox is unavailable.');
	return json_decode($content, true, 512, JSON_THROW_ON_ERROR)['messages'];
};
$beforeMessageIds = array_column($assignmentMailbox(), 'ID');
$assignmentJob = new HrNotificationJob($clock, $users, $container->get(\OCP\Mail\IMailer::class), $assignmentLegacy(), $appConfig, $logger, $container->get(\OCP\L10N\IFactory::class), $container->get(\OCA\Timesheet\Service\EmployeeBalanceNotificationService::class));
(new ReflectionMethod($assignmentJob, 'run'))->invoke($assignmentJob, []);
$newLegacyMessages = array_values(array_filter($assignmentMailbox(), static fn(array $message): bool => !in_array($message['ID'], $beforeMessageIds, true)));
verifyReminder(count($newLegacyMessages), 2, 'Only two selected HR messages reach Mailpit through the older job');
$deliveredLegacyRecipients = [];
foreach ($newLegacyMessages as $message) {
	$address = $message['To'][0]['Address'];
	$deliveredLegacyRecipients[] = $address;
	$details = json_decode(file_get_contents('http://mailpit:8025/api/v1/message/' . rawurlencode($message['ID'])), true, 512, JSON_THROW_ON_ERROR);
	$body = (string)($details['Text'] ?? '');
	if ($address === 'assignment-direct@example.test') {
		verifyReminder(str_contains($body, 'Marvin Adler'), true, 'Direct HR actual mail includes its own employee');
		verifyReminder(str_contains($body, 'Marvin Fremd'), false, 'Direct HR actual mail omits another rule');
	} else {
		verifyReminder(str_contains($body, 'Marvin Fremd'), true, 'Group HR actual mail includes its selected employee');
		verifyReminder(str_contains($body, 'Marvin Adler'), false, 'Group HR actual mail omits its unselected employee group');
	}
}
sort($deliveredLegacyRecipients);
verifyReminder($deliveredLegacyRecipients, ['assignment-direct@example.test', 'employment-hr-active@example.test'], 'Actual legacy recipients match the separate rule selection');

$assignmentPublish($assignmentMixedRules);
$capturingMailer->deliver = true;
verifyReminder($makeService()->sendReminders()['hrSent'], 2, 'Selected daily HR summaries also reach isolated Mailpit');
$capturingMailer->deliver = false;

// Group and person overlap produces one recipient and one summary, never a duplicate.
$overlapGroup = $groups->get('assignment-overlap-hr') ?? $groups->createGroup('assignment-overlap-hr');
$overlapGroup->addUser($employmentUsers['hr-active']);
$overlapRules = $assignmentMixedRules;
$overlapRules[0]['hrGroups'][] = 'assignment-overlap-hr';
$overlapRules[0]['hrUsers'][] = 'employment-hr-active';
$overlapRules[0]['mailRecipients'] = ['mode' => 'all', 'userIds' => []];
$assignmentPublish($overlapRules);
$overlapIds = $assignmentScope()->getMailRecipientUserIds();
verifyReminder(count(array_filter($overlapIds, static fn(string $userId): bool => $userId === 'employment-hr-active')), 1, 'Overlapping group and direct assignments do not duplicate recipients');
verifyReminder($makeService()->sendReminders()['hrSent'], 2, 'Overlapping assignment produces one daily summary per non-freelancer recipient');
verifyReminder(array_keys($capturingMailer->hrSummaries), ['employment-hr-active', 'assignment-direct'], 'Freelancer recipients stay muted despite overlapping assignments');
verifyReminder(array_keys($assignmentLegacy()->doCron()), ['employment-hr-active', 'assignment-direct'], 'Overlapping assignment produces one older summary per non-freelancer recipient');

// Empty selection and disabled or no-longer-responsible accounts never broaden delivery.
$emptyRules = $assignmentMixedRules;
foreach ($emptyRules as &$rule) $rule['mailRecipients'] = ['mode' => 'selected', 'userIds' => []];
unset($rule);
$assignmentPublish($emptyRules);
verifyReminder($assignmentScope()->isHr('assignment-direct'), true, 'Empty mail selection does not remove HR access');
verifyReminder($assignmentScope()->getMailRecipientUserIds(), [], 'Explicitly empty selection has no HR recipients');
verifyReminder($makeService()->sendReminders()['hrSent'], 0, 'Empty selection disables only daily HR delivery');
verifyReminder($assignmentLegacy()->doCron(), [], 'Empty selection disables older HR delivery as well');

$assignmentPublish($assignmentMixedRules);
$assignmentUsers['direct']->setEnabled(false);
verifyReminder($assignmentScope()->getMailRecipientUserIds(), ['employment-hr-active'], 'Disabled individual assignee receives no mail');
verifyReminder($makeService()->sendReminders()['hrSent'], 1, 'A disabled assignee does not block another daily recipient');
verifyReminder(array_keys($assignmentLegacy()->doCron()), ['employment-hr-active'], 'A disabled assignee does not block another older recipient');
$assignmentUsers['direct']->setEnabled(true);
$assignmentPublish($assignmentMixedRules);
$foreignHrGroup->removeUser($employmentUsers['hr-active']);
verifyReminder($assignmentScope()->isHr('employment-hr-active'), true, 'Remaining HR assignment survives removal from a different rule');
verifyReminder($assignmentScope()->getMailRecipientUserIds(), ['assignment-direct'], 'A former group member loses only that rule mail eligibility');
verifyReminder($makeService()->sendReminders()['hrSent'], 1, 'Removed membership is rechecked before daily delivery');
verifyReminder(array_keys($assignmentLegacy()->doCron()), ['assignment-direct'], 'Removed membership is rechecked before older delivery');
$foreignHrGroup->addUser($employmentUsers['hr-active']);

$personOnlyRules = $assignmentMixedRules;
$personOnlyRules[0]['hrGroups'] = [];
unset($personOnlyRules[0]['mailRecipients']);
$assignmentPublish($personOnlyRules);
verifyReminder($assignmentScope()->isHr('assignment-direct'), true, 'A rule can be managed by an individual without an HR group');
verifyReminder(count($assignmentScope()->getAccessibleUsers('assignment-direct')), 6, 'Person-only assignment retains its employee scope');
verifyReminder($makeService()->sendReminders()['hrSent'], 2, 'Person-only assignment supports daily HR summaries');
verifyReminder(array_keys($assignmentLegacy()->doCron()), ['assignment-direct', 'employment-hr-active'], 'Person-only assignment supports older HR summaries');

$invalidStored = $assignmentMixedRules;
$invalidStored[0]['mailRecipients']['userIds'] = ['assignment-extra'];
$assignmentPublish($invalidStored);
verifyReminder($assignmentScope()->getMailRecipientUserIds(), ['employment-hr-active'], 'Stored unrelated mail ID cannot create access or receive a summary');
verifyReminder($assignmentScope()->isHr('assignment-extra'), false, 'A mail selection never grants HR privileges');

// Leave a readable mixed fixture for browser verification; no time data is changed.
$assignmentPublish($assignmentMixedRules);
$clock->current = $assignmentClock;
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll(), $assignmentEntries, 'Assignment and delivery checks preserve all time entries');
verifyReminder($database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll(), $assignmentBalances, 'Assignment and delivery checks preserve all existing balances');
echo json_encode(['assignmentChecks' => $checks - $assignmentStartChecks, 'result' => 'passed'], JSON_UNESCAPED_SLASHES) . "\n";
