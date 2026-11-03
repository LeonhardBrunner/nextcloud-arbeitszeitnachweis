<?php

declare(strict_types=1);

require __DIR__ . '/status-fixtures.php';

use OCA\Timesheet\Controller\EmploymentStatusController;
use OCA\Timesheet\Controller\OverviewController;
use OCA\Timesheet\Controller\PageController;
use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Service\HrService;

$suite = new EmploymentTestSuite();
$fixture = static function (?string $actor = 'alice'): array {
	$locks = new EmploymentLockFixture();
	$config = new EmploymentConfigFixture($locks);
	$clock = new EmploymentClockFixture();
	$groups = new EmploymentGroupFixture();
	$store = new EmploymentStatusStore($config, $locks, $clock, $groups);
	$session = new EmploymentSessionFixture($actor === null ? null : new EmploymentUserFixture($actor));
	$request = new EmploymentRequestFixture();
	$hr = new HrService($session);
	$logger = new EmploymentLoggerFixture();
	$controller = new EmploymentStatusController('timesheet', $request, $store, $session, $hr, $logger, $groups);
	$balances = new \OCA\Timesheet\Service\MonthlyBalanceService();
	$page = new PageController('timesheet', $request, $hr, new \OCA\Timesheet\Db\UserPreferencesStore(), $session, new \OCA\Timesheet\Db\TourProgressStore(), $logger, $groups, new \OCA\Timesheet\Service\AbsenceCommentCatalog(), new EmploymentTranslationFixture(), $store);
	$overview = new OverviewController('timesheet', $request, new EmploymentAppConfigFixture(), new \OCA\Timesheet\Db\EntryMapper(), new \OCA\Timesheet\Db\UserConfigMapper(), $session, $hr, new \OCA\Timesheet\Service\HolidayService(), $balances, $store);
	return compact('locks', 'config', 'clock', 'store', 'session', 'request', 'hr', 'logger', 'groups', 'controller', 'balances', 'page', 'overview');
};

foreach ([null, 'alice', 'hr', 'admin', 'hr-admin'] as $actor) {
	$suite->test('Only an ordinary employee may update its own freelancer mode: ' . ($actor ?? 'anonymous'), static function () use ($suite, $fixture, $actor): void {
		$state = $fixture($actor);
		$state['request']->parameters = ['userId' => 'someone-else', 'isActive' => false];
		$response = $state['controller']->setFreelancerMode(true, hash('sha256', ''));
		$suite->same($actor === 'alice' ? 200 : 403, $response->getStatus());
		if ($actor !== 'alice') {
			$suite->same([], $state['config']->reads);
			$suite->same([], $state['config']->writes);
		} else {
			$suite->same(true, $response->getData()['freelancerMode']);
			$suite->same(true, $response->getData()['isActive']);
			$suite->same([$actor], array_keys($state['config']->values));
		}
	});
}
foreach ([null, 'alice', 'hr', 'admin', 'hr-admin'] as $actor) {
	$suite->test('The rendered switch and feedback appear only for ordinary employees: ' . ($actor ?? 'anonymous'), static function () use ($suite, $fixture, $actor): void {
		$state = $fixture($actor);
		$parameters = $state['page']->index()->getParams();
		$suite->same($actor === 'alice', $parameters['canSetFreelancerMode'] ?? false);
		$suite->same(in_array($actor, ['alice', 'hr'], true), $parameters['updateNotesEnabled'] ?? false);
		$html = (static function (array $_): string {
			$l = new EmploymentTranslationFixture();
			ob_start();
			try {
				require __DIR__ . '/../../templates/main.php';
				return ob_get_contents();
			} finally {
				ob_end_clean();
			}
		})($parameters);
		$suite->same($actor === 'alice', str_contains($html, 'id="freelancer-mode-switch"'));
		$suite->same($actor === 'alice', str_contains($html, 'id="freelancer-mode-feedback"'));
		$suite->same(in_array($actor, ['hr', 'hr-admin'], true), str_contains($html, 'id="hr-employee-freelancer"'));
		$suite->same(false, preg_match('/<select[^>]+id="hr-employment-status"/', $html) === 1);
	});
}
$suite->test('A role gained after page load rejects both freelancer choices without changing history', static function () use ($suite, $fixture): void {
	foreach (['hr', 'admin'] as $newRole) {
		$state = $fixture('alice');
		$status = $state['store']->setFreelancerMode('alice', true, hash('sha256', ''));
		$original = $state['config']->values;
		$state['config']->reads = [];
		$state['config']->writes = [];
		if ($newRole === 'hr') $state['hr']->hrUsers[] = 'alice';
		else $state['groups']->adminUserIds[] = 'alice';
		foreach ([false, true] as $choice) {
			$suite->same(403, $state['controller']->setFreelancerMode($choice, $status->statusRevision)->getStatus());
		}
		$suite->same($original, $state['config']->values);
		$suite->same([], $state['config']->reads);
		$suite->same([], $state['config']->writes);
	}
});
$suite->test('Employee activity is read-only and the manual write method is removed', static function () use ($suite): void {
	$suite->same(false, method_exists(EmploymentStatusController::class, 'setActivity'));
});
foreach ([null, 0, 1, 'false', 'true', [], new \stdClass()] as $badBoolean) {
	$suite->test('Freelancer updates require real booleans: ' . get_debug_type($badBoolean), static function () use ($suite, $fixture, $badBoolean): void {
		$state = $fixture('alice');
		$suite->same(400, $state['controller']->setFreelancerMode($badBoolean, hash('sha256', ''))->getStatus());
		$suite->same([], $state['config']->writes);
	});
}
foreach ([null, '', 'bad', 123, []] as $badRevision) {
	$suite->test('Freelancer updates require a status revision: ' . get_debug_type($badRevision), static function () use ($suite, $fixture, $badRevision): void {
		$state = $fixture('alice');
		$suite->same(400, $state['controller']->setFreelancerMode(false, $badRevision)->getStatus());
		$suite->same([], $state['config']->writes);
	});
}
$suite->test('A group change returns a fresh conflict before a stale freelancer request can overwrite it', static function () use ($suite, $fixture): void {
	$state = $fixture('alice');
	$state['groups']->membersByGroup['Zeitnachweis'] = ['bob'];
	$response = $state['controller']->setFreelancerMode(true, hash('sha256', ''));
	$current = $state['store']->getStatus('alice');
	$suite->same(409, $response->getStatus());
	$suite->same(['code' => 'employment_status_conflict', ...$current->toPublicArray()], $response->getData());
	$suite->same(false, $current->isActive);
	$suite->same(false, $current->freelancerMode);
});
$suite->test('Extra request fields cannot override group membership or HR responsibilities', static function () use ($suite, $fixture): void {
	$state = $fixture('alice');
	$state['groups']->membersByGroup['Zeitnachweis'] = ['bob'];
	$current = $state['store']->getStatus('alice');
	$state['request']->parameters = ['isActive' => true, 'userId' => 'bob', 'hrGroups' => ['admin']];
	$response = $state['controller']->setFreelancerMode(true, $current->statusRevision);
	$suite->same(200, $response->getStatus());
	$suite->same(false, $response->getData()['isActive']);
	$suite->same(true, $response->getData()['freelancerMode']);
	$suite->same(['alice'], array_keys($state['config']->values));
	$suite->same(false, $state['hr']->isHr('alice'));
});
$suite->test('A busy account returns a usable conflict snapshot', static function () use ($suite, $fixture): void {
	$state = $fixture();
	$state['locks']->busy = true;
	$response = $state['controller']->setFreelancerMode(true, hash('sha256', ''));
	$suite->same(409, $response->getStatus());
	$suite->same(['code' => 'employment_status_conflict', 'isActive' => true, 'freelancerMode' => false, 'statusRevision' => hash('sha256', '')], $response->getData());
	$suite->same([], $state['config']->writes);
});
foreach (['read', 'write', 'malformed'] as $failure) {
	$suite->test('Storage failures return a generic error: ' . $failure, static function () use ($suite, $fixture, $failure): void {
		$state = $fixture();
		$state['config']->rejectReads = $failure === 'read';
		$state['config']->rejectWrites = $failure === 'write';
		if ($failure === 'malformed') $state['config']->values['alice'] = '{broken';
		$response = $state['controller']->setFreelancerMode(true, hash('sha256', $state['config']->values['alice'] ?? ''));
		$suite->same(500, $response->getStatus());
		$suite->same(['message' => 'Saving employment status failed'], $response->getData());
		$suite->same(1, count($state['logger']->errors));
		$suite->same([], $state['config']->writes);
	});
}
$suite->test('Nextcloud account enablement is independent of employment activity', static function () use ($suite, $fixture): void {
	$state = $fixture();
	$state['session']->user->enabled = false;
	$suite->same(true, $state['store']->getStatus('alice')->isActive);
	$suite->same(true, $state['page']->index()->getParams()['employmentStatus']['isActive']);
});
$suite->test('Page startup hydrates the status and loads its module between core and HR', static function () use ($suite, $fixture): void {
	$state = $fixture();
	$status = $state['store']->setFreelancerMode('alice', true, hash('sha256', ''));
	$state['config']->writes = [];
	\OCP\Util::$scripts = [];
	$parameters = $state['page']->index()->getParams();
	$suite->same($status->toPublicArray(), $parameters['employmentStatus']);
	$suite->same(true, $parameters['employmentStatusAvailable']);
	$suite->same(true, array_search('timesheet-core', \OCP\Util::$scripts, true) < array_search('timesheet-employment-status', \OCP\Util::$scripts, true));
	$suite->same(true, array_search('timesheet-employment-status', \OCP\Util::$scripts, true) < array_search('timesheet-hr', \OCP\Util::$scripts, true));
	$suite->same([], $state['config']->writes);
});
$suite->test('Status load failure disables its page controls without blocking the timesheet', static function () use ($suite, $fixture): void {
	$state = $fixture();
	$state['config']->rejectReads = true;
	$parameters = $state['page']->index()->getParams();
	$suite->same(false, $parameters['employmentStatusAvailable']);
	$suite->same(1, count($state['logger']->warnings));
	$suite->same('minutes', $parameters['breakInputMode']);
});
$suite->test('Unauthenticated page startup does not read another account status', static function () use ($suite, $fixture): void {
	$state = $fixture(null);
	$suite->same(false, $state['page']->index()->getParams()['employmentStatusAvailable']);
	$suite->same([], $state['config']->reads);
});
foreach ([null, 'alice', 'admin'] as $actor) {
	$suite->test('Non-HR overview never reads classifications: ' . ($actor ?? 'anonymous'), static function () use ($suite, $fixture, $actor): void {
		$state = $fixture($actor);
		$suite->same(403, $state['overview']->getHrUserListData()->getStatus());
		$suite->same([], $state['config']->reads);
		$suite->same([], $state['balances']->calls);
	});
}
$suite->test('HR list only enriches assigned IDs and retains balances for inactive employees', static function () use ($suite, $fixture): void {
	$state = $fixture('hr');
	$state['clock']->instant = '2026-10-10T12:00:00+00:00';
	$alice = $state['store']->setFreelancerMode('alice', true, hash('sha256', ''));
	$state['clock']->instant = '2026-10-20T12:00:00+00:00';
	$state['store']->setFreelancerMode('alice', false, $alice->statusRevision);
	$state['clock']->instant = '2026-11-02T12:00:00+00:00';
	$state['groups']->membersByGroup['Zeitnachweis'] = ['alice'];
	$state['store']->syncActivity('bob');
	$state['config']->values['unassigned'] = 'malformed-but-outside-HR-scope';
	$state['config']->reads = [];
	$state['config']->writes = [];
	$response = $state['overview']->getHrUserListData();
	$suite->same(200, $response->getStatus());
	$suite->same([['alice', 'bob']], $state['config']->reads);
	[$aliceRow, $bobRow] = $response->getData();
	$suite->same('2026-10-01', $aliceRow['lastEntryDate']);
	$suite->same('2026-10-20', $aliceRow['missingEntryReferenceDate']);
	$suite->same(false, $bobRow['isActive']);
	$suite->same(null, $bobRow['missingEntryReferenceDate']);
	$suite->same(123, $bobRow['overtimeMinutes']);
	$suite->same(456, $bobRow['totalMinutes']);
	$suite->same([], $state['config']->writes);
});
foreach (['read', 'malformed'] as $failure) {
	$suite->test('HR status read failure rejects the list instead of inventing defaults: ' . $failure, static function () use ($suite, $fixture, $failure): void {
		$state = $fixture('hr');
		$state['config']->rejectReads = $failure === 'read';
		if ($failure === 'malformed') $state['config']->values['alice'] = 'broken';
		$response = $state['overview']->getHrUserListData();
		$suite->same(500, $response->getStatus());
		$suite->same(['message' => 'Loading employment status failed'], $response->getData());
		$suite->same([], $state['balances']->calls);
	});
}
$suite->test('Only the personal freelancer route remains writable and keeps CSRF protection', static function () use ($suite): void {
	foreach (['setFreelancerMode'] as $methodName) {
		$method = new \ReflectionMethod(EmploymentStatusController::class, $methodName);
		$suite->same(1, count($method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)));
		$suite->same([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class));
	}
	$routes = require __DIR__ . '/../../appinfo/routes.php';
	$routes = array_values(array_filter($routes['routes'], static fn(array $route): bool => str_starts_with($route['name'], 'employmentStatus#')));
	$suite->same([
		['name' => 'employmentStatus#setFreelancerMode', 'url' => '/api/preferences/freelancer-mode', 'verb' => 'PUT'],
	], $routes);
});
$suite->finish('Employment controllers');
