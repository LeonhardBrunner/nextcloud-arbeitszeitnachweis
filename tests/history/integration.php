<?php
declare(strict_types=1);

// This runner creates only disposable accounts in the dedicated history-test database.
if (PHP_SAPI !== 'cli' || getenv('HISTORY_TEST_ENVIRONMENT') !== '1' || !getenv('HISTORY_TEST_PASSWORD')) {
	fwrite(STDERR, "The dedicated history test environment and password are required.\n");
	exit(1);
}
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
	fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . '\n' . $error->getTraceAsString() . "\n");
	exit(1);
});
$server = \OCP\Server::class;
$config = $server::get(\OCP\IConfig::class);
if ($config->getSystemValue('dbname') !== 'timesheet_history_test' || $config->getSystemValue('mail_smtphost') !== 'mailpit') {
	throw new RuntimeException('Production and normal demo instances are refused.');
}
$application = new \OCA\Timesheet\AppInfo\Application();
$container = $application->getContainer();
$users = $server::get(\OCP\IUserManager::class);
$groups = $server::get(\OCP\IGroupManager::class);
$database = $server::get(\OCP\IDBConnection::class);
$session = $server::get(\OCP\IUserSession::class);
$appConfig = $container->get(\OCP\AppFramework\Services\IAppConfig::class);
$entryMapper = $container->get(\OCA\Timesheet\Db\EntryMapper::class);
$configMapper = $container->get(\OCA\Timesheet\Db\UserConfigMapper::class);
$phase = $argv[1] ?? 'test';
$checks = 0;
function historyCheck(mixed $actual, mixed $expected, string $description): void {
	global $checks;
	$checks++;
	if ($actual !== $expected) {
		throw new RuntimeException($description . ': ' . json_encode(['actual' => $actual, 'expected' => $expected], JSON_THROW_ON_ERROR));
	}
}
function historyRows(string $table): array {
	global $database;
	return $database->getQueryBuilder()->select('*')->from($table)->orderBy('id')->executeQuery()->fetchAll();
}
function historyAs(string $userId): void {
	global $users, $session;
	$session->setUser($users->get($userId));
}

if ($phase === 'fresh') {
	historyCheck($appConfig->getAppValueString('installed_version'), '1.4.0', 'Fresh installation enables 1.4.0');
	historyCheck(count(historyRows('ts_change_history')), 0, 'Fresh installation creates an empty history table');
	historyCheck((int)$appConfig->getAppValueString('change_history_started_at') > 0, true, 'Fresh installation records its activation date');
	historyCheck($server::get(\OCP\BackgroundJob\IJobList::class)->has(\OCA\Timesheet\BackgroundJob\ChangeHistoryCleanupJob::class, null), true, 'Fresh installation registers daily cleanup');
	echo json_encode(['historyFreshInstallChecks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
	exit;
}

if ($phase === 'lifecycle') {
	$entryService = $container->get(\OCA\Timesheet\Service\EntryService::class);
	$historyService = $container->get(\OCA\Timesheet\Service\ChangeHistoryService::class);
	$queue = $container->get(\OCA\Timesheet\Db\ChangeHistoryAccountQueue::class);
	$writes = $container->get(\OCA\Timesheet\Service\HistoryWriteCoordinator::class);
	$hr = $container->get(\OCA\Timesheet\Service\HrService::class);
	$testId = 'history-lifecycle';
	$user = $users->createUser($testId, getenv('HISTORY_TEST_PASSWORD'));
	$user->setDisplayName('Former account identity');
	$groups->get('history-staff-main')->addUser($user);
	historyAs('history-hr-a');
	$entryService->upsertCommentOnly($testId, '2026-11-05', 'Old account history');
	$failingRemoval = new class($database) extends \OCA\Timesheet\Db\ChangeHistoryStore {
		public function removeAccount(string $userId): void { throw new RuntimeException('Synthetic account cleanup failure'); }
	};
	$failingWrites = new \OCA\Timesheet\Service\HistoryWriteCoordinator($database, $server::get(\OCP\Lock\ILockingProvider::class), $session, $hr, $users, $failingRemoval, $server::get(\OCP\AppFramework\Utility\ITimeFactory::class), $queue);
	$failed = false; try { $failingWrites->forgetAccount($testId); } catch (RuntimeException) { $failed = true; }
	historyCheck($failed, true, 'Failed lifecycle cleanup remains visible as a failure');
	historyCheck($queue->contains($testId), true, 'A positive cleanup marker survives the failed transaction');
	$failed = false; try { $historyService->list([]); } catch (RuntimeException) { $failed = true; }
	historyCheck($failed, true, 'A pending identity cleanup blocks history disclosure');
	$failed = false; try { $entryService->upsertCommentOnly($testId, '2026-11-06', 'Must not be stored'); } catch (RuntimeException) { $failed = true; }
	historyCheck($failed, true, 'A pending identity cleanup blocks additional writes');
	$staleJobId = $queue->pendingAccountIds()[0];
	$user->delete();
	historyCheck($queue->contains($testId), false, 'A successful native deletion drains its cleanup marker');
	$replacement = $users->createUser($testId, getenv('HISTORY_TEST_PASSWORD'));
	$replacement->setDisplayName('New account identity');
	$groups->get('history-staff-main')->addUser($replacement);
	$entryService->upsertCommentOnly($testId, '2026-11-07', 'New account history');
	$writes->retryAccountCleanup($staleJobId);
	$items = $historyService->list(['employee' => $testId])['items'];
	historyCheck(count($items), 1, 'A stale cleanup retry cannot purge history for the replacement account');
	historyCheck($items[0]['employee']['name'], 'New account identity', 'The replacement account cannot inherit former account identity');
	historyCheck($items[0]['changes'][4]['after'] ?? '', 'New account history', 'Only new account history remains visible');
	echo json_encode(['historyLifecycleChecks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
	exit;
}

if ($phase === 'seed') {
	if ($appConfig->getAppValueString('history_test_baseline', '') !== '') {
		throw new RuntimeException('This isolated database has already been seeded.');
	}
	$names = [
		'hr-a' => 'Anton Beispiel', 'hr-b' => 'Beate Beispiel', 'hr-other' => 'Cora Andere Zuständigkeit',
		'hr-admin' => 'HR mit Administration', 'employee-a' => 'Marvin Adler', 'inactive' => 'Marvin Inaktiv',
		'freelancer' => 'Mara Freelancer', 'other' => 'Marvin Fremd', 'baseline' => 'Bestand Vor Update',
	];
	foreach ($names as $suffix => $name) {
		$userId = 'history-' . $suffix;
		$user = $users->get($userId) ?? $users->createUser($userId, getenv('HISTORY_TEST_PASSWORD'));
		$user->setDisplayName($name);
		$user->setEMailAddress($userId . '@example.test');
		$config->setUserValue($userId, 'core', 'lang', 'de');
		$configMapper->upsertConfigData($userId, 480, 'BY');
	}
	foreach ([
		'history-hr-main' => ['hr-a', 'hr-b', 'hr-admin'], 'history-hr-other' => ['hr-other'],
		'history-staff-main' => ['employee-a', 'inactive', 'freelancer', 'baseline'], 'history-staff-other' => ['other'],
		'Zeitnachweis' => ['employee-a', 'freelancer', 'other', 'baseline'], 'admin' => ['hr-admin'],
	] as $groupId => $suffixes) {
		$group = $groups->get($groupId) ?? $groups->createGroup($groupId);
		foreach ($suffixes as $suffix) $group->addUser($users->get('history-' . $suffix));
	}
	$appConfig->setAppValueString('hr_access_rules', json_encode([
		['id' => 'history-main', 'hrGroups' => ['history-hr-main'], 'userGroups' => ['history-staff-main'], 'priority' => 1, 'mailRecipients' => ['mode' => 'selected', 'userIds' => []]],
		['id' => 'history-other', 'hrGroups' => ['history-hr-other'], 'userGroups' => ['history-staff-other'], 'priority' => 1],
	], JSON_THROW_ON_ERROR));
	$statuses = $container->get(\OCA\Timesheet\Db\EmploymentStatusStore::class);
	$statuses->setFreelancerMode('history-freelancer', true, $statuses->getStatus('history-freelancer')->statusRevision);
	foreach ([['2025-12-31', null, null, -180, 'Historical balance carryover'], ['2026-11-02', 480, 1020, 30, 'Vorhandener Eintrag']] as [$day, $start, $end, $break, $comment]) {
		$entry = new \OCA\Timesheet\Db\Entry();
		$entry->setUserId('history-baseline'); $entry->setWorkDate($day); $entry->setStartMin($start); $entry->setEndMin($end);
		$entry->setBreakMinutes($break); $entry->setComment($comment); $entry->setCreatedAt(1700000000); $entry->setUpdatedAt(1700000000);
		$entryMapper->insert($entry);
	}
	$query = $database->getQueryBuilder();
	$balance = ['user_id' => 'history-baseline', 'year' => 2026, 'month' => 11, 'opening_balance_minutes' => 180,
		'target_minutes' => 480, 'actual_minutes' => 450, 'delta_minutes' => -30, 'closing_balance_minutes' => 150,
		'is_closed' => 0, 'needs_recalculation' => 0, 'calculated_at' => 1700000000, 'updated_at' => 1700000000];
	$query->insert('ts_monthly_balances')->values(array_map(fn(mixed $value) => $query->createNamedParameter($value), $balance))->executeStatement();
	$appConfig->setAppValueString('history_test_baseline', json_encode([
		'entries' => historyRows('ts_entries'), 'configs' => historyRows('ts_user_config'), 'balances' => historyRows('ts_monthly_balances'),
	], JSON_THROW_ON_ERROR));
	echo "Seeded isolated version 1.3.1 accounts and preserved entries, negative breaks and balance snapshots.\n";
	exit;
}

if ($phase === 'reset') {
	$baseline = json_decode($appConfig->getAppValueString('history_test_baseline'), true, 512, JSON_THROW_ON_ERROR);
	foreach (['ts_change_history', 'ts_entries', 'ts_user_config', 'ts_monthly_balances'] as $table) {
		$database->getQueryBuilder()->delete($table)->executeStatement();
	}
	foreach (['entries' => 'ts_entries', 'configs' => 'ts_user_config', 'balances' => 'ts_monthly_balances'] as $key => $table) {
		foreach ($baseline[$key] as $row) {
			$query = $database->getQueryBuilder();
			$query->insert($table)->values(array_map(fn(mixed $value) => $query->createNamedParameter($value, $value === null ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL : (is_int($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)), $row))->executeStatement();
		}
	}
	$groups->get('history-staff-main')->addUser($users->get('history-inactive'));
	echo "Reset only the guarded disposable history database to its saved baseline.\n";
	exit;
}
historyCheck($appConfig->getAppValueString('installed_version'), '1.4.0', 'The isolated upgrade installed 1.4.0');
$baseline = json_decode($appConfig->getAppValueString('history_test_baseline'), true, 512, JSON_THROW_ON_ERROR);
historyCheck(historyRows('ts_entries'), $baseline['entries'], 'Upgrade preserves every original entry and historical negative break');
historyCheck(historyRows('ts_user_config'), $baseline['configs'], 'Upgrade preserves work settings');
historyCheck(historyRows('ts_monthly_balances'), $baseline['balances'], 'Upgrade preserves every original balance');
$entries = $container->get(\OCA\Timesheet\Service\EntryService::class);
$settings = $container->get(\OCA\Timesheet\Service\WorkSettingsService::class);
$history = $container->get(\OCA\Timesheet\Service\ChangeHistoryService::class);
$store = $container->get(\OCA\Timesheet\Db\ChangeHistoryStore::class);
$balances = $container->get(\OCA\Timesheet\Service\MonthlyBalanceService::class);
$hrService = $container->get(\OCA\Timesheet\Service\HrService::class);
historyCheck(count(historyRows('ts_change_history')), 0, 'No fabricated historical events are created during upgrade');
historyCheck((int)$appConfig->getAppValueString('change_history_started_at') > 0, true, 'Recording start is set by the new migration');
historyAs('history-hr-a');
$entry = $entries->create(['workDate' => '2026-10-28', 'startMin' => 0, 'endMin' => 480, 'breakMinutes' => 30, 'comment' => 'Erster Stand'], 'history-employee-a');
$records = $history->list([])['items'];
historyCheck(count($records), 1, 'Foreign HR creation creates one event');
historyCheck($records[0]['kind'], 'created', 'Creation is explicitly identified');
historyCheck($records[0]['actor']['id'], 'history-hr-a', 'Actor comes from the session');
historyCheck($records[0]['changes'][1]['after'] ?? null, 0, 'Midnight is retained as zero rather than missing');
historyAs('history-hr-b');
$entries->update((int)$entry->getId(), ['comment' => '<script>alert("literal")</script>']);
$records = $history->list([])['items'];
historyCheck(count($records), 2, 'A second HR editor creates a separate event');
historyCheck($records[0]['actor']['id'], 'history-hr-b', 'Second editor is recorded');
historyCheck($records[0]['changes'], [['field' => 'comment', 'before' => 'Erster Stand', 'after' => '<script>alert("literal")</script>']], 'Only changed fields and exact plain-text comments are returned');
$entries->update((int)$entry->getId(), ['comment' => '<script>alert("literal")</script>']);
historyCheck(count($history->list([])['items']), 2, 'Identical repeated saves produce no event');
historyAs('history-employee-a');
$entries->update((int)$entry->getId(), ['comment' => 'Eigenänderung']);
historyCheck(count(historyRows('ts_change_history')), 2, 'Employee self-edits remain excluded');
historyAs('history-hr-a');
$entries->update((int)$entry->getId(), ['comment' => 'Nach Eigenänderung']);
historyCheck($history->list([])['items'][0]['changes'][0]['before'], 'Eigenänderung', 'Fresh values include an intervening unlogged self-edit');
$self = $entries->create(['workDate' => '2026-10-29', 'startMin' => null, 'endMin' => null, 'breakMinutes' => 0, 'comment' => 'Eigener HR-Eintrag']);
historyCheck(count($history->list([])['items']), 3, 'HR self-edits remain excluded');
$entries->upsertCommentOnly('history-inactive', '2026-10-29', 'Urlaub');
$entries->upsertCommentOnly('history-inactive', '2026-10-29', 'Stundenausgleich');
historyCheck($history->list(['employee' => 'history-inactive'])['items'][0]['changes'], [['field' => 'comment', 'before' => 'Urlaub', 'after' => 'Stundenausgleich']], 'Comment-only upsert logs existing comment changes');
$settings->save('history-freelancer', 420, 'HE');
$settingsEvent = $history->list(['employee' => 'history-freelancer'])['items'][0];
historyCheck($settingsEvent['kind'], 'settings', 'Work settings produce a settings event');
historyCheck($settingsEvent['workDate'], null, 'Settings events have no working date');
historyCheck(count($settingsEvent['changes']), 2, 'Daily target and state form one event');
$settings->save('history-freelancer', 420, 'HE');
historyCheck(count($history->list(['employee' => 'history-freelancer'])['items']), 1, 'Unchanged settings are a no-op');
$entries->delete((int)$entry->getId());
$deleted = $history->list(['employee' => 'history-employee-a'])['items'][0];
historyCheck($deleted['kind'], 'deleted', 'Deleted entries retain a deletion event');
historyCheck($deleted['entryId'], (int)$entry->getId(), 'Deleted entry identity remains available');
historyCheck($deleted['changes'][0]['after'], null, 'Deletion preserves previous data with empty new values');

// Both row changes and balance invalidation roll back if history persistence fails.
$failingStore = new class($database) extends \OCA\Timesheet\Db\ChangeHistoryStore {
	public function append(array $record): void { throw new RuntimeException('Synthetic history persistence failure'); }
};
$failingWrites = new \OCA\Timesheet\Service\HistoryWriteCoordinator($database, $server::get(\OCP\Lock\ILockingProvider::class), $session, $hrService, $users, $failingStore, $server::get(\OCP\AppFramework\Utility\ITimeFactory::class), $container->get(\OCA\Timesheet\Db\ChangeHistoryAccountQueue::class));
$failingEntries = new \OCA\Timesheet\Service\EntryService($entryMapper, $session, $balances, $failingWrites);
$beforeEntries = historyRows('ts_entries'); $beforeBalances = historyRows('ts_monthly_balances'); $beforeEvents = historyRows('ts_change_history');
$failed = false;
try { $failingEntries->update((int)$baseline['entries'][0]['id'], ['comment' => 'Must not persist']); } catch (RuntimeException $error) { $failed = true; }
historyCheck($failed, true, 'A history write failure is propagated');
historyCheck(historyRows('ts_entries'), $beforeEntries, 'Failed auditing rolls back the changed entry');
historyCheck(historyRows('ts_monthly_balances'), $beforeBalances, 'Failed auditing rolls back balance invalidation');
historyCheck(historyRows('ts_change_history'), $beforeEvents, 'Failed auditing leaves no partial event');
$beforeConfigs = historyRows('ts_user_config');
$failingSettings = new \OCA\Timesheet\Service\WorkSettingsService($configMapper, $balances, $failingWrites);
$failed = false;
try { $failingSettings->save('history-baseline', 360, 'SH'); } catch (RuntimeException $error) { $failed = true; }
historyCheck($failed, true, 'Settings auditing failures are propagated');
historyCheck(historyRows('ts_user_config'), $beforeConfigs, 'Failed settings auditing rolls back target and state');
historyCheck(historyRows('ts_monthly_balances'), $beforeBalances, 'Failed settings auditing rolls back balance invalidation');

// Current employee assignments limit items and every filter option independently of mail settings.
$response = $history->list([]);
historyCheck(in_array('history-inactive', array_column($response['filters']['employees'], 'id'), true), true, 'Inactive employees remain in scope');
historyCheck(in_array('history-freelancer', array_column($response['filters']['employees'], 'id'), true), true, 'Freelancers remain in scope');
historyCheck(in_array('history-other', array_column($response['filters']['employees'], 'id'), true), false, 'Foreign employee names do not leak through filters');
$denied = false;
try { $history->list(['employee' => 'history-other']); } catch (\OC\ForbiddenException $error) { $denied = true; }
historyCheck($denied, true, 'An explicit unassigned history target is forbidden');
$denied = false;
try { $settings->save('history-other', 480, 'BE'); } catch (\OC\ForbiddenException $error) { $denied = true; }
historyCheck($denied, true, 'Unassigned work setting writes are forbidden');
historyAs('history-employee-a');
$denied = false; try { $history->list([]); } catch (\OC\ForbiddenException $error) { $denied = true; }
historyCheck($denied, true, 'Ordinary employees have no history endpoint');
historyAs('history-hr-admin');
historyCheck(count($history->list([])['items']) > 0, true, 'An administrator with an HR assignment is treated as HR');
historyAs('history-hr-a');
$groups->get('history-staff-main')->removeUser($users->get('history-inactive'));
historyCheck(in_array('history-inactive', array_column(array_column($history->list([])['items'], 'employee'), 'id'), true), false, 'Removed employee assignment hides earlier history');
$groups->get('history-staff-main')->addUser($users->get('history-inactive'));
historyCheck(count($history->list(['employee' => 'history-inactive'])['items']), 2, 'Reassignment restores retained history without recreating it');

// Native account deletion removes the subject history or anonymises the editor in other histories.
foreach (['deleted-hr', 'deleted-employee'] as $suffix) {
	$userId = 'history-' . $suffix;
	$user = $users->createUser($userId, getenv('HISTORY_TEST_PASSWORD'));
	$user->setDisplayName($suffix);
	$groups->get($suffix === 'deleted-hr' ? 'history-hr-main' : 'history-staff-main')->addUser($user);
}
historyAs('history-deleted-hr');
$entries->upsertCommentOnly('history-freelancer', '2026-10-30', 'Former HR editor');
historyAs('history-hr-a');
$users->get('history-deleted-hr')->delete();
$anonymous = $history->list(['actorDeleted' => 1])['items'];
historyCheck(count($anonymous), 1, 'A deleted HR account leaves an anonymised event');
historyCheck($anonymous[0]['actor'], ['id' => null, 'name' => ''], 'Deleted editor name and ID are erased');
$entries->upsertCommentOnly('history-deleted-employee', '2026-10-30', 'Temporary employee history');
$users->get('history-deleted-employee')->delete();
historyCheck(count(array_filter(historyRows('ts_change_history'), static fn(array $row): bool => $row['employee_id'] === 'history-deleted-employee')), 0, 'Deleting the employee removes their history');

// The reader enforces retention even before the scheduled cleaner runs.
$cutoff = $history->retentionCutoff();
$expired = ['occurredAt' => $cutoff - 86400, 'actor' => ['id' => 'history-hr-a', 'name' => 'Anton Beispiel'],
	'employee' => ['id' => 'history-inactive', 'name' => 'Marvin Inaktiv'], 'kind' => 'updated', 'entryId' => null,
	'workDate' => '2020-01-01', 'changes' => [['field' => 'comment', 'before' => '', 'after' => 'Expired history test']]];
$store->append($expired);
$all = $history->list(['from' => '2020-01-01', 'to' => '2099-12-31'])['items'];
historyCheck(count(array_filter($all, static fn(array $record): bool => $record['occurredAt'] < $cutoff)), 0, 'Expired events stay hidden before cleanup');
$store->deleteExpired($cutoff);
historyCheck(count(array_filter(historyRows('ts_change_history'), static fn(array $record): bool => (int)$record['occurred_at'] < $cutoff)), 0, 'Retention cleanup removes expired events');

// Multiple pages have no duplicate or skipped records; newer inserts do not shift the cursor.
for ($index = 0; $index < 55; $index++) $entries->upsertCommentOnly('history-inactive', '2026-10-31', 'Version ' . $index);
$first = $history->list(['employee' => 'history-inactive']);
historyCheck(count($first['items']), 50, 'First page is limited to fifty changes');
historyCheck(is_int($first['nextCursor']), true, 'A stable next cursor is returned');
$second = $history->list(['employee' => 'history-inactive', 'beforeId' => $first['nextCursor']]);
historyCheck(count(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id'))), 0, 'Pages do not duplicate events');
historyCheck(count($first['items']) + count($second['items']), 57, 'Pagination does not skip retained events');
historyCheck(historyRows('ts_monthly_balances'), $baseline['balances'], 'All tests preserve original historical balances');
$baselineEntries = array_values(array_filter(historyRows('ts_entries'), static fn(array $row): bool => $row['user_id'] === 'history-baseline'));
historyCheck($baselineEntries, $baseline['entries'], 'All tests preserve original entries and negative pauses');
echo json_encode(['historyIntegrationChecks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
