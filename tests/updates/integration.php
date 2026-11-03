<?php

declare(strict_types=1);

// Run only against a disposable Nextcloud instance with a local test mailbox.
if (PHP_SAPI !== 'cli' || getenv('UPDATE_TEST_ENVIRONMENT') !== '1') {
	fwrite(STDERR, "The isolated update-test environment is required.\n");
	exit(1);
}
$configuredDatabase = (static function (): string {
	$CONFIG = [];
	require '/var/www/html/config/config.php';
	return (string)($CONFIG['dbname'] ?? '');
})();
if ($configuredDatabase !== 'timesheet_updates_test') {
	throw new RuntimeException('Refusing to run against a different database.');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';

use OCA\Timesheet\AppInfo\Application;
use OCA\Timesheet\Db\UpdateNotesStore;
use OCA\Timesheet\Service\UpdateNotesService;
use OCP\Notification\AlreadyProcessedException;

$config = \OCP\Server::get(\OCP\IConfig::class);
$database = \OCP\Server::get(\OCP\IDBConnection::class);
if ($database->executeQuery('SELECT DATABASE()')->fetchOne() !== 'timesheet_updates_test'
	|| $config->getSystemValueString('mail_smtphost') !== 'mailpit') {
	throw new RuntimeException('The isolated database and Mailpit transport are required.');
}
$password = getenv('UPDATE_TEST_PASSWORD');
if (!$password) {
	throw new RuntimeException('A synthetic test password is required.');
}
$users = \OCP\Server::get(\OCP\IUserManager::class);
$groups = \OCP\Server::get(\OCP\IGroupManager::class);
$session = \OCP\Server::get(\OCP\IUserSession::class);
$translations = \OCP\Server::get(\OCP\L10N\IFactory::class);
$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
// Console bootstrap registers notifiers but does not boot the bell's storage app.
\OCP\Server::get(\OC\AppFramework\Bootstrap\Coordinator::class)->bootApp('notifications');
$handler = \OCP\Server::get(\OCA\Notifications\Handler::class);
$app = new Application();
$container = $app->getContainer();
$appConfig = $container->get(\OCP\AppFramework\Services\IAppConfig::class);
$service = $container->get(UpdateNotesService::class);
$store = $container->get(UpdateNotesStore::class);
$accounts = [];

foreach (['employee', 'hr', 'hr-admin', 'other', 'promoted', 'disabled', 'unreleased'] as $suffix) {
	$userId = 'update-' . $suffix;
	$user = $users->get($userId) ?? $users->createUser($userId, $password);
	$user->setDisplayName('Update test ' . $suffix);
	$user->setEMailAddress($userId . '@example.test');
	$user->setEnabled($suffix !== 'disabled');
	// Clear only this runner's synthetic notes and preference state.
	$query = $database->getQueryBuilder();
	$query->delete('notifications')->where($query->expr()->eq('user', $query->createNamedParameter($userId)))
		->andWhere($query->expr()->eq('app', $query->createNamedParameter('timesheet')))->executeStatement();
	$config->deleteUserValue($userId, 'timesheet', 'update_notes_state');
	$config->setUserValue($userId, 'core', 'lang', $suffix === 'hr' ? 'en' : 'de');
	$accounts[$suffix] = $user;
}
unset($password);
$adminGroup = $groups->get('admin');
$adminGroup->addUser($accounts['hr-admin']);
$adminGroup->removeUser($accounts['promoted']);
$accounts['admin'] = $users->get('update-admin');
foreach (['update-staff' => ['employee','other','promoted'], 'update-hr' => ['hr','hr-admin']] as $groupId => $suffixes) {
	$group = $groups->get($groupId) ?? $groups->createGroup($groupId);
	foreach ($suffixes as $suffix) {
		$group->addUser($accounts[$suffix]);
	}
}
$appConfig->setAppValueString('hr_access_rules', json_encode([
	['id'=>'update-rule','hrGroups'=>['update-hr'],'userGroups'=>['update-staff'],'priority'=>1],
], JSON_THROW_ON_ERROR));
$session->setUser($accounts['employee']);

$checks = 0;
function verifyUpdate(mixed $actual, mixed $expected, string $description): void {
	global $checks;
	$checks++;
	if ($actual !== $expected) {
		throw new RuntimeException($description . ': ' . json_encode(['actual'=>$actual,'expected'=>$expected]));
	}
}
$notesFor = static function (string $userId) use ($database): array {
	$query = $database->getQueryBuilder();
	return $query->select('*')->from('notifications')
		->where($query->expr()->eq('user', $query->createNamedParameter($userId)))
		->andWhere($query->expr()->eq('app', $query->createNamedParameter('timesheet')))
		->orderBy('notification_id')->executeQuery()->fetchAll();
};
$entriesBefore = $database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll();
$balancesBefore = $database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll();
$de = $translations->get('timesheet','de');
$en = $translations->get('timesheet','en');
verifyUpdate($appConfig->getAppValueString('installed_version'), '1.3.0', 'Successfully installed release is used');
verifyUpdate(in_array('timesheet', array_map(static fn($notifier): string => $notifier->getID(), $manager->getNotifiers()), true), true, 'Native notifier is registered through app bootstrap');
$createdId='update-created-'.bin2hex(random_bytes(3));
$users->createUser($createdId,getenv('UPDATE_TEST_PASSWORD'));
verifyUpdate($store->isNewAccount($createdId),true,'Native account creation marks only the new account for current-release onboarding');

$first = $service->open('update-employee','automatic',$de);
verifyUpdate($first['shouldShow'],true,'First employee visit displays notes');
verifyUpdate(array_column($first['releases'],'version'),['1.3.0'],'A first visit contains the current release only');
verifyUpdate(count($notesFor('update-employee')),1,'First visit creates one native bell notification');
$service->open('update-employee','automatic',$de);
verifyUpdate(count($notesFor('update-employee')),1,'Repeated visits do not duplicate the native notification');
$service->markShown('update-employee','1.3.0');
verifyUpdate($service->open('update-employee','automatic',$de)['shouldShow'],false,'A displayed popup does not reopen automatically');
verifyUpdate(count($notesFor('update-employee')),1,'Popup acknowledgment leaves the bell entry available');
verifyUpdate($store->read('update-employee')['dismissedVersion'],null,'Popup acknowledgment is not a bell dismissal');
verifyUpdate($service->open('update-employee','manual',$de)['shouldShow'],true,'The bell link reopens the pending notes');
$record=$notesFor('update-employee')[0];
$notification=$handler->getById((int)$record['notification_id'],'update-employee');
$prepared=$manager->prepare($notification,'de');
verifyUpdate(str_contains($prepared->getParsedSubject(),'Arbeitszeitnachweis'),true,'Native German subject uses the account language');
verifyUpdate(str_contains($prepared->getParsedSubject(),'1.3.0'),true,'Native subject substitutes the release version');
verifyUpdate($prepared->getParsedMessage(),'Änderungsliste öffnen.','Native notification keeps only a concise translated link');
verifyUpdate(str_contains($prepared->getLink(),'update-notes=1'),true,'Native link opens the update popup');
verifyUpdate($handler->deleteById((int)$record['notification_id'],'update-employee'),true,'The actual native X path deletes the note');
verifyUpdate($store->read('update-employee')['dismissedVersion'],'1.3.0','The native X persists dismissal for this account');
verifyUpdate($service->open('update-employee','automatic',$de)['shouldShow'],false,'A dismissed release does not reopen');
verifyUpdate(count($notesFor('update-employee')),0,'A dismissed release is not republished');
$service->open('update-employee','manual',$de);
verifyUpdate(count($notesFor('update-employee')),0,'Manually reading dismissed notes does not resurrect the bell');

$hr=$service->open('update-hr','automatic',$en);
verifyUpdate($hr['shouldShow'],true,'HR receives release notes');
$hrRecord=$notesFor('update-hr')[0];
verifyUpdate(str_contains($manager->prepare($handler->getById((int)$hrRecord['notification_id'],'update-hr'),'en')->getParsedSubject(),'updated'),true,'English notification uses its own language');
$service->open('update-other','automatic',$de);
verifyUpdate($store->read('update-other')['dismissedVersion'],null,'Separate accounts retain independent unread state');
verifyUpdate($handler->deleteByUser('update-hr'),true,'The actual native clear-all path removes HR notes');
verifyUpdate($store->read('update-hr')['dismissedVersion'],'1.3.0','Native clear-all persists dismissal');
verifyUpdate(count($notesFor('update-other')),1,'Clearing one account leaves other accounts intact');

foreach (['admin','hr-admin','disabled'] as $suffix) {
	$session->setUser($accounts[$suffix]);
	$controller=$container->get(\OCA\Timesheet\Controller\UpdateNotesController::class);
	verifyUpdate($controller->load()->getStatus(),403,'Excluded account cannot load notes: '.$suffix);
	verifyUpdate($controller->shown('1.3.0')->getStatus(),403,'Excluded account cannot mark notes: '.$suffix);
	verifyUpdate(count($notesFor($accounts[$suffix]->getUID())),0,'Excluded account has no native note: '.$suffix);
}

$service->open('update-promoted','automatic',$de);
$promoted=$notesFor('update-promoted')[0];
$adminGroup->addUser($accounts['promoted']);
$hidden=false;
try {
	$manager->prepare($handler->getById((int)$promoted['notification_id'],'update-promoted'),'de');
} catch (AlreadyProcessedException) {
	$hidden=true;
}
verifyUpdate($hidden,true,'A newly promoted administrator no longer sees the native note');
verifyUpdate(count($notesFor('update-promoted')),0,'Native preparation removes the excluded administrator note');
verifyUpdate($store->read('update-promoted')['dismissedVersion'],null,'Role cleanup does not acknowledge unread changes');
$adminGroup->removeUser($accounts['promoted']);

$installedVersion=$appConfig->getAppValueString('installed_version');
try {
	$appConfig->setAppValueString('installed_version','1.2.0');
	$missing=$service->open('update-unreleased','automatic',$de);
	verifyUpdate($missing['shouldShow'],false,'An installed version without bundled notes has no empty popup');
	verifyUpdate(count($notesFor('update-unreleased')),0,'Missing release notes create no bell entry');
} finally {
	$appConfig->setAppValueString('installed_version',$installedVersion);
}
verifyUpdate($database->getQueryBuilder()->select('*')->from('ts_entries')->orderBy('id')->executeQuery()->fetchAll(),$entriesBefore,'Update notes leave time entries unchanged');
verifyUpdate($database->getQueryBuilder()->select('*')->from('ts_monthly_balances')->orderBy('id')->executeQuery()->fetchAll(),$balancesBefore,'Update notes leave balance records unchanged');

// Fresh, synthetic fixtures remain available for the isolated HTTP and visual checks.
foreach (['employee','hr'] as $suffix) {
	$handler->deleteByUser('update-'.$suffix);
	$config->deleteUserValue('update-'.$suffix,'timesheet','update_notes_state');
}
echo json_encode(['updateChecks'=>$checks,'result'=>'passed'],JSON_UNESCAPED_SLASHES)."\n";
