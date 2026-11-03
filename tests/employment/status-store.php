<?php

declare(strict_types=1);

require __DIR__ . '/status-fixtures.php';

use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Listener\EmployeeActivityListener;
use OCA\Timesheet\Service\EmploymentStatus;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;

$suite = new EmploymentTestSuite();
$fixture = static function (): array {
	$locks = new EmploymentLockFixture(); $config = new EmploymentConfigFixture($locks);
	$clock = new EmploymentClockFixture(); $groups = new EmploymentGroupFixture();
	return [new EmploymentStatusStore($config, $locks, $clock, $groups), $config, $locks, $clock, $groups];
};

$suite->test('Group members without saved preferences are active without unnecessary writes', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	$suite->same(['isActive' => true, 'freelancerMode' => false, 'statusRevision' => hash('sha256', '')], $store->getStatus('alice')->toPublicArray());
	$suite->same([], $store->getStatus('alice')->getExcludedPeriods()); $suite->same([], $config->writes);
});
$suite->test('A person outside Zeitnachweis starts inactive without invented older dates', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture(); $status = $store->getStatus('carol');
	$suite->same(false, $status->isActive);
	$suite->same([['from' => '2026-11-02', 'to' => null]], $status->getExcludedPeriods());
	$suite->same($status->toPublicArray(), $store->getStatus('carol')->toPublicArray()); $suite->same(1, count($config->writes));
});
$suite->test('Missing and similarly named groups never count as exact Zeitnachweis membership', static function () use ($suite, $fixture): void {
	[$store, , , , $groups] = $fixture();
	$groups->membersByGroup = ['zeitnachweis' => ['alice'], 'Zeitnachweis-alt' => ['alice']];
	$suite->same(false, $store->getStatus('alice')->isActive);
});
$suite->test('Unchanged batch reads remove duplicates and use one uncached preference read', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	$suite->same(['alice', 'bob'], array_keys($store->getStatusesForUsers(['alice', 'bob', 'alice'])));
	$suite->same([['alice', 'bob']], $config->reads); $suite->same([], $store->getStatusesForUsers([]));
	$suite->same(1, count($config->reads));
});
$suite->test('No-op synchronization and freelancer saves keep the original revision', static function () use ($suite, $fixture): void {
	[$store, $config, $locks] = $fixture(); $original = $store->getStatus('alice');
	$suite->same($original->toPublicArray(), $store->syncActivity('alice')->toPublicArray());
	$suite->same($original->toPublicArray(), $store->setFreelancerMode('alice', false, $original->statusRevision)->toPublicArray());
	$suite->same([], $config->writes); $suite->same($locks->acquisitions, $locks->releases);
});
$suite->test('Freelancer periods retain their Berlin calendar-day boundaries', static function () use ($suite, $fixture): void {
	[$store, $config, , $clock] = $fixture(); $clock->instant = '2026-11-02T23:30:00+00:00';
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$suite->same([['from' => '2026-11-03', 'to' => null]], $status->getExcludedPeriods());
	$suite->same(null, $status->getMissingEntryReferenceDate('2026-10-01'));
	$clock->instant = '2026-11-05T12:00:00+00:00'; $status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([['from' => '2026-11-03', 'to' => '2026-11-05']], $status->getExcludedPeriods());
	$suite->same('2026-11-05', $status->getMissingEntryReferenceDate(null));
	$suite->same('2026-11-05', $status->getMissingEntryReferenceDate('2026-10-01'));
	$suite->same('2026-11-06', $status->getMissingEntryReferenceDate('2026-11-06')); $suite->same(2, count($config->writes));
});
$suite->test('Overlapping activity and freelancer periods preserve both histories', static function () use ($suite, $fixture): void {
	[$store, , , $clock, $groups] = $fixture();
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$clock->instant = '2026-11-04T12:00:00+00:00'; $groups->removeMember('alice'); $status = $store->getStatus('alice');
	$clock->instant = '2026-11-06T12:00:00+00:00'; $status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same(false, $status->isActive); $suite->same([['from' => '2026-11-02', 'to' => null]], $status->getExcludedPeriods());
	$clock->instant = '2026-11-08T12:00:00+00:00'; $groups->addMember('alice'); $status = $store->getStatus('alice');
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-08']], $status->getExcludedPeriods());
	$suite->same('2026-11-08', $status->getMissingEntryReferenceDate('2026-10-20'));
});
$suite->test('Adjacent periods merge and same-day membership round trips remove empty intervals', static function () use ($suite, $fixture): void {
	[$store, , , $clock, $groups] = $fixture(); $groups->removeMember('alice'); $store->getStatus('alice');
	$clock->instant = '2026-11-04T12:00:00+00:00'; $groups->addMember('alice'); $status = $store->getStatus('alice');
	$status = $store->setFreelancerMode('alice', true, $status->statusRevision);
	$clock->instant = '2026-11-06T12:00:00+00:00'; $status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-06']], $status->getExcludedPeriods());
	$groups->removeMember('alice'); $store->syncActivity('alice');
	$groups->addMember('alice'); $status = $store->syncActivity('alice');
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-06']], $status->getExcludedPeriods());
});
$suite->test('A missed group event is repaired by a read without changing freelancer mode', static function () use ($suite, $fixture): void {
	[$store, , , , $groups] = $fixture(); $status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$groups->removeMember('alice'); $updated = $store->getStatus('alice');
	$suite->same(false, $updated->isActive); $suite->same(true, $updated->freelancerMode);
	$suite->same(false, $status->statusRevision === $updated->statusRevision);
});
$suite->test('A stale freelancer save cannot undo a newer group transition', static function () use ($suite, $fixture): void {
	[$store, $config, $locks, , $groups] = $fixture(); $groups->removeMember('alice');
	$suite->throws(\OCP\PreConditionNotMetException::class, static fn() => $store->setFreelancerMode('alice', true, hash('sha256', '')));
	$status = $store->getStatus('alice'); $suite->same(false, $status->isActive); $suite->same(false, $status->freelancerMode);
	$suite->same(1, count($config->writes)); $status = $store->setFreelancerMode('alice', true, $status->statusRevision);
	$suite->same(false, $status->isActive); $suite->same(true, $status->freelancerMode);
	$suite->same($locks->acquisitions, $locks->releases);
});
$suite->test('Same-day group round trips retain a fresh revision', static function () use ($suite, $fixture): void {
	[$store, , , , $groups] = $fixture(); $groups->removeMember('alice'); $store->syncActivity('alice');
	$groups->addMember('alice'); $status = $store->syncActivity('alice');
	$suite->same([], $status->getExcludedPeriods()); $suite->same(false, $status->statusRevision === hash('sha256', ''));
	$suite->throws(\OCP\PreConditionNotMetException::class, static fn() => $store->setFreelancerMode('alice', true, hash('sha256', '')));
});
foreach ([['2026-03-28T23:30:00Z', '2026-03-29T22:15:00Z', '2026-03-29', '2026-03-30'], ['2025-12-31T23:30:00Z', '2026-01-01T23:30:00Z', '2026-01-01', '2026-01-02']] as [$fromInstant, $toInstant, $fromDay, $toDay]) {
	$suite->test('Group history uses Berlin day boundaries: ' . $fromDay, static function () use ($suite, $fixture, $fromInstant, $toInstant, $fromDay, $toDay): void {
		[$store, , , $clock, $groups] = $fixture(); $clock->instant = $fromInstant;
		$groups->removeMember('alice'); $store->syncActivity('alice'); $clock->instant = $toInstant;
		$groups->addMember('alice'); $status = $store->syncActivity('alice');
		$suite->same([['from' => $fromDay, 'to' => $toDay]], $status->getExcludedPeriods());
		$suite->same($toDay, $status->getMissingEntryReferenceDate(null));
	});
}
$suite->test('Legacy inactive periods remain when group membership becomes authoritative', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	$config->values['alice'] = json_encode(['version' => 1, 'nonce' => str_repeat('a', 32), 'isActive' => false, 'freelancerMode' => false,
		'freelancerPeriods' => [], 'inactivePeriods' => [['from' => '2026-10-01', 'to' => '2026-10-05'], ['from' => '2026-10-20', 'to' => null]]]);
	$status = $store->getStatus('alice'); $suite->same(true, $status->isActive);
	$suite->same([['from' => '2026-10-01', 'to' => '2026-10-05'], ['from' => '2026-10-20', 'to' => '2026-11-02']], $status->getExcludedPeriods());
});
$suite->test('A competing database write fails its precondition and releases the lock', static function () use ($suite, $fixture): void {
	[$store, $config, $locks, , $groups] = $fixture(); $groups->removeMember('alice');
	$config->beforeWrite = static function (EmploymentConfigFixture $config): void { $config->values['alice'] = 'changed-by-another-request'; };
	$suite->throws(\OCP\PreConditionNotMetException::class, static fn() => $store->syncActivity('alice'));
	$suite->same([], $config->writes); $suite->same($locks->acquisitions, $locks->releases);
});
foreach (['busy', 'write', 'read', 'group'] as $failureType) {
	$suite->test('Infrastructure failure remains an error: ' . $failureType, static function () use ($suite, $fixture, $failureType): void {
		[$store, $config, $locks, , $groups] = $fixture(); $groups->removeMember('alice');
		$locks->busy = $failureType === 'busy'; $config->rejectReads = $failureType === 'read';
		$config->rejectWrites = $failureType === 'write'; $groups->rejectReads = $failureType === 'group';
		$suite->throws(\Throwable::class, static fn() => $store->syncActivity('alice'));
		$suite->same([], $config->writes); $suite->same($locks->acquisitions, $locks->releases);
	});
}
$suite->test('Invalid revisions and identifiers are rejected before touching storage', static function () use ($suite, $fixture): void {
	[$store, $config, $locks] = $fixture();
	$suite->throws(\InvalidArgumentException::class, static fn() => $store->setFreelancerMode('alice', true, 'invalid'));
	foreach ([[''], [null], [[]]] as $userIds) $suite->throws(\InvalidArgumentException::class, static fn() => $store->getStatusesForUsers($userIds));
	$suite->same([], $config->reads); $suite->same(0, $locks->acquisitions);
});
$valid = ['version' => 1, 'nonce' => str_repeat('a', 32), 'isActive' => true, 'freelancerMode' => false, 'freelancerPeriods' => [], 'inactivePeriods' => []];
$malformed = ['not json', 'null', '[]', '{}', json_encode(array_merge($valid, ['version' => 2])), json_encode(array_merge($valid, ['isActive' => 1])), json_encode(array_merge($valid, ['nonce' => 'broken'])), json_encode(array_merge($valid, ['freelancerMode' => true])), json_encode(array_merge($valid, ['inactivePeriods' => [['from' => '2026-02-30', 'to' => '2026-03-01']]])), json_encode(array_merge($valid, ['inactivePeriods' => [['from' => '2026-11-01', 'to' => null]]])), json_encode(array_merge($valid, ['freelancerPeriods' => [['from' => '2026-10-10', 'to' => '2026-10-12'], ['from' => '2026-10-11', 'to' => '2026-10-13']]]))];
foreach ($malformed as $index => $rawValue) {
	$suite->test('Malformed saved state never silently enables reminders: ' . $index, static function () use ($suite, $fixture, $rawValue): void {
		[$store, $config] = $fixture(); $config->values['alice'] = $rawValue;
		$suite->throws(\Throwable::class, static fn() => $store->getStatus('alice'));
		$suite->throws(\Throwable::class, static fn() => $store->setFreelancerMode('alice', true, hash('sha256', $rawValue)));
		$suite->same([], $config->writes);
	});
}
$suite->test('Group listener records removal and rejoining without changing HR permissions', static function () use ($suite, $fixture): void {
	[$store, , , $clock, $groups] = $fixture(); $logger = new EmploymentLoggerFixture();
	$listener = new EmployeeActivityListener($store, $logger); $user = new EmploymentUserFixture('alice');
	$group = new EmploymentGroupObjectFixture('Zeitnachweis');
	$groups->removeMember('alice'); $listener->handle(new UserRemovedEvent($group, $user));
	$clock->instant = '2026-11-04T12:00:00Z'; $groups->addMember('alice'); $listener->handle(new UserAddedEvent($group, $user));
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-04']], $store->getStatus('alice')->getExcludedPeriods());
	$suite->same(['admin', 'hr-admin'], $groups->adminUserIds); $suite->same([], $logger->errors);
});
$suite->test('Unrelated group events cannot invent transitions', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture(); $logger = new EmploymentLoggerFixture();
	$listener = new EmployeeActivityListener($store, $logger); $user = new EmploymentUserFixture('alice');
	$listener->handle(new \OCP\EventDispatcher\Event());
	$listener->handle(new UserRemovedEvent(new EmploymentGroupObjectFixture('other'), $user));
	$suite->same([], $config->writes); $suite->same(true, $store->getStatus('alice')->isActive);
});
$suite->test('Native membership events persist before Nextcloud invalidates its old group cache', static function () use ($suite, $fixture): void {
	[$store, $config, $locks, $clock, $groups] = $fixture(); $logger = new EmploymentLoggerFixture();
	$listener = new EmployeeActivityListener($store, $logger); $user = new EmploymentUserFixture('alice');
	$group = new EmploymentGroupObjectFixture('Zeitnachweis');
	// Nextcloud dispatches removal while its manager still reports the old membership.
	$suite->same(true, $groups->isInGroup('alice', 'Zeitnachweis'));
	$listener->handle(new UserRemovedEvent($group, $user));
	$removed = json_decode($config->values['alice'] ?? 'null', true);
	$suite->same(false, $removed['isActive'] ?? null);
	$suite->same([['from' => '2026-11-02', 'to' => null]], $removed['inactivePeriods']);
	$groups->removeMember('alice'); $suite->same(false, $store->getStatus('alice')->isActive);
	// The matching add event also precedes cache invalidation; preserve the whole interval.
	$clock->instant = '2026-11-04T12:00:00Z';
	$listener->handle(new UserAddedEvent($group, $user));
	$added = json_decode($config->values['alice'], true);
	$suite->same(true, $added['isActive']);
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-04']], $added['inactivePeriods']);
	$groups->addMember('alice'); $suite->same(true, $store->getStatus('alice')->isActive);
	$suite->same(2, count($config->writes)); $suite->same([], $logger->errors);
	$suite->same($locks->acquisitions, $locks->releases);
});
$suite->test('A listener failure leaves group administration usable and a later read repairs state', static function () use ($suite, $fixture): void {
	[$store, $config, , , $groups] = $fixture(); $logger = new EmploymentLoggerFixture();
	$listener = new EmployeeActivityListener($store, $logger); $groups->removeMember('alice'); $config->rejectWrites = true;
	$listener->handle(new UserRemovedEvent(new EmploymentGroupObjectFixture('Zeitnachweis'), new EmploymentUserFixture('alice')));
	$suite->same(1, count($logger->errors)); $suite->same([], $config->writes);
	$config->rejectWrites = false; $suite->same(false, $store->getStatus('alice')->isActive);
});
$suite->test('Activity has no caller-selected setter and the public response stays compatible', static function () use ($suite): void {
	$suite->same(false, method_exists(EmploymentStatusStore::class, 'setActive')); $status = new EmploymentStatus();
	$suite->same(null, $status->getMissingEntryReferenceDate(null));
	$suite->same('2025-12-31', $status->getMissingEntryReferenceDate('2025-12-31'));
	$suite->same(['isActive', 'freelancerMode', 'statusRevision'], array_keys($status->toPublicArray()));
});
$suite->finish('Group-derived employment status/store');
