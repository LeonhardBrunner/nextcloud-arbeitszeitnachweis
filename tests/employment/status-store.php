<?php

declare(strict_types=1);

foreach (['Service/EmploymentStatus.php', 'Db/EmploymentStatusStore.php'] as $requiredFile) {
	if (!is_file(__DIR__ . '/../../lib/' . $requiredFile)) {
		fwrite(STDERR, 'Employment status implementation missing: ' . $requiredFile . "\n");
		exit(1);
	}
}

require __DIR__ . '/status-fixtures.php';

use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Service\EmploymentStatus;

$suite = new EmploymentTestSuite();
$fixture = static function (): array {
	$locks = new EmploymentLockFixture();
	$config = new EmploymentConfigFixture($locks);
	$clock = new EmploymentClockFixture();
	return [new EmploymentStatusStore($config, $locks, $clock), $config, $locks, $clock];
};

$suite->test('Unknown accounts retain the existing active, ordinary-employee state', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	$suite->same(['isActive' => true, 'freelancerMode' => false, 'statusRevision' => hash('sha256', '')], $store->getStatus('alice')->toPublicArray());
	$suite->same([], $store->getStatus('alice')->getExcludedPeriods());
	$suite->same([], $config->writes);
});
$suite->test('Batch reads include defaults, remove duplicates and use one uncached call', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	$result = $store->getStatusesForUsers(['alice', 'bob', 'alice']);
	$suite->same(['alice', 'bob'], array_keys($result));
	$suite->same([['alice', 'bob']], $config->reads);
	$suite->same([], $store->getStatusesForUsers([]));
	$suite->same(1, count($config->reads));
});
$suite->test('No-op saves keep the original revision and avoid all writes', static function () use ($suite, $fixture): void {
	[$store, $config, $locks] = $fixture();
	$original = $store->getStatus('alice');
	$suite->same($original->toPublicArray(), $store->setActive('alice', true, $original->statusRevision)->toPublicArray());
	$suite->same($original->toPublicArray(), $store->setFreelancerMode('alice', false, $original->statusRevision)->toPublicArray());
	$suite->same([], $config->writes);
	$suite->same($locks->acquisitions, $locks->releases);
});
$suite->test('A freelancer period starts on the current Berlin day and closes exclusively', static function () use ($suite, $fixture): void {
	[$store, $config, , $clock] = $fixture();
	$clock->instant = '2026-11-02T23:30:00+00:00';
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$suite->same([['from' => '2026-11-03', 'to' => null]], $status->getExcludedPeriods());
	$suite->same(null, $status->getMissingEntryReferenceDate('2026-10-01'));
	$clock->instant = '2026-11-05T12:00:00+00:00';
	$status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([['from' => '2026-11-03', 'to' => '2026-11-05']], $status->getExcludedPeriods());
	$suite->same('2026-11-05', $status->getMissingEntryReferenceDate(null));
	$suite->same('2026-11-05', $status->getMissingEntryReferenceDate('2026-10-01'));
	$suite->same('2026-11-06', $status->getMissingEntryReferenceDate('2026-11-06'));
	$suite->same(2, count($config->writes));
});
$suite->test('Independent overlapping periods merge without losing either flag or history', static function () use ($suite, $fixture): void {
	[$store, , , $clock] = $fixture();
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$clock->instant = '2026-11-04T12:00:00+00:00';
	$status = $store->setActive('alice', false, $status->statusRevision);
	$clock->instant = '2026-11-06T12:00:00+00:00';
	$status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same(false, $status->isActive);
	$suite->same([['from' => '2026-11-02', 'to' => null]], $status->getExcludedPeriods());
	$clock->instant = '2026-11-08T12:00:00+00:00';
	$status = $store->setActive('alice', true, $status->statusRevision);
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-08']], $status->getExcludedPeriods());
	$suite->same('2026-11-08', $status->getMissingEntryReferenceDate('2026-10-20'));
	$clock->instant = '2026-11-10T12:00:00+00:00';
	$status = $store->setFreelancerMode('alice', true, $status->statusRevision);
	$clock->instant = '2026-11-11T12:00:00+00:00';
	$status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-08'], ['from' => '2026-11-10', 'to' => '2026-11-11']], $status->getExcludedPeriods());
});
$suite->test('Adjacent periods merge and same-day on/off removes only the empty interval', static function () use ($suite, $fixture): void {
	[$store, , , $clock] = $fixture();
	$status = $store->setActive('alice', false, hash('sha256', ''));
	$clock->instant = '2026-11-04T12:00:00+00:00';
	$status = $store->setActive('alice', true, $status->statusRevision);
	$status = $store->setFreelancerMode('alice', true, $status->statusRevision);
	$clock->instant = '2026-11-06T12:00:00+00:00';
	$status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-06']], $status->getExcludedPeriods());
	$status = $store->setActive('alice', false, $status->statusRevision);
	$status = $store->setActive('alice', true, $status->statusRevision);
	$suite->same([['from' => '2026-11-02', 'to' => '2026-11-06']], $status->getExcludedPeriods());
});
$suite->test('Shared revisions prevent stale HR/employee requests from replacing one another', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$suite->throws(\OCP\PreConditionNotMetException::class, static fn() => $store->setActive('alice', false, hash('sha256', '')));
	$suite->same($status->toPublicArray(), $store->getStatus('alice')->toPublicArray());
	$suite->same(1, count($config->writes));
});
$suite->test('Same-day round trips keep a fresh revision even when both flags return to defaults', static function () use ($suite, $fixture): void {
	[$store] = $fixture();
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([], $status->getExcludedPeriods());
	$suite->same(false, $status->statusRevision === hash('sha256', ''));
	$suite->throws(\OCP\PreConditionNotMetException::class, static fn() => $store->setActive('alice', false, hash('sha256', '')));
});
$suite->test('A daylight-saving change still produces calendar-day boundaries', static function () use ($suite, $fixture): void {
	[$store, , , $clock] = $fixture();
	$clock->instant = '2026-03-28T23:30:00+00:00';
	$status = $store->setActive('alice', false, hash('sha256', ''));
	$clock->instant = '2026-03-29T22:15:00+00:00';
	$status = $store->setActive('alice', true, $status->statusRevision);
	$suite->same([['from' => '2026-03-29', 'to' => '2026-03-30']], $status->getExcludedPeriods());
	$suite->same('2026-03-30', $status->getMissingEntryReferenceDate(null));
});
$suite->test('The Berlin year boundary is retained in period history', static function () use ($suite, $fixture): void {
	[$store, , , $clock] = $fixture();
	$clock->instant = '2025-12-31T23:30:00+00:00';
	$status = $store->setFreelancerMode('alice', true, hash('sha256', ''));
	$clock->instant = '2026-01-01T23:30:00+00:00';
	$status = $store->setFreelancerMode('alice', false, $status->statusRevision);
	$suite->same([['from' => '2026-01-01', 'to' => '2026-01-02']], $status->getExcludedPeriods());
});
$suite->test('A competing database write still fails the conditional save and releases the lock', static function () use ($suite, $fixture): void {
	[$store, $config, $locks] = $fixture();
	$config->beforeWrite = static function (EmploymentConfigFixture $config): void { $config->values['alice'] = 'changed-by-another-request'; };
	$suite->throws(\OCP\PreConditionNotMetException::class, static fn() => $store->setActive('alice', false, hash('sha256', '')));
	$suite->same([], $config->writes);
	$suite->same($locks->acquisitions, $locks->releases);
});
foreach (['busy', 'write', 'read'] as $failureType) {
	$suite->test('Storage/lock failure remains an error: ' . $failureType, static function () use ($suite, $fixture, $failureType): void {
		[$store, $config, $locks] = $fixture();
		$locks->busy = $failureType === 'busy';
		$config->rejectReads = $failureType === 'read';
		$config->rejectWrites = $failureType === 'write';
		$suite->throws(\Throwable::class, static fn() => $store->setActive('alice', false, hash('sha256', '')));
		$suite->same([], $config->writes);
		$suite->same($locks->acquisitions, $locks->releases);
	});
}
$suite->test('Invalid revisions are rejected before acquiring a lock', static function () use ($suite, $fixture): void {
	[$store, , $locks] = $fixture();
	$suite->throws(\InvalidArgumentException::class, static fn() => $store->setActive('alice', false, 'invalid'));
	$suite->same(0, $locks->acquisitions);
});
$suite->test('Invalid account identifiers are rejected without preference reads', static function () use ($suite, $fixture): void {
	[$store, $config] = $fixture();
	foreach ([[''], [null], [[]]] as $userIds) {
		$suite->throws(\InvalidArgumentException::class, static fn() => $store->getStatusesForUsers($userIds));
	}
	$suite->same([], $config->reads);
});
$valid = ['version' => 1, 'nonce' => str_repeat('a', 32), 'isActive' => true, 'freelancerMode' => false, 'freelancerPeriods' => [], 'inactivePeriods' => []];
$malformed = ['not json', 'null', '[]', '{}', json_encode(array_merge($valid, ['version' => 2])), json_encode(array_merge($valid, ['isActive' => 1])), json_encode(array_merge($valid, ['nonce' => 'broken'])), json_encode(array_merge($valid, ['freelancerMode' => true])), json_encode(array_merge($valid, ['inactivePeriods' => [['from' => '2026-02-30', 'to' => '2026-03-01']]])), json_encode(array_merge($valid, ['inactivePeriods' => [['from' => '2026-11-01', 'to' => null]]])), json_encode(array_merge($valid, ['freelancerPeriods' => [['from' => '2026-10-10', 'to' => '2026-10-12'], ['from' => '2026-10-11', 'to' => '2026-10-13']]]))];
foreach ($malformed as $index => $rawValue) {
	$suite->test('Malformed state never silently enables reminders: ' . $index, static function () use ($suite, $fixture, $rawValue): void {
		[$store, $config] = $fixture();
		$config->values['alice'] = $rawValue;
		$suite->throws(\Throwable::class, static fn() => $store->getStatus('alice'));
		$suite->throws(\Throwable::class, static fn() => $store->setActive('alice', true, hash('sha256', $rawValue)));
		$suite->same([], $config->writes);
	});
}
$suite->test('Regular legacy accounts retain the original no-entry reference', static function () use ($suite): void {
	$status = new EmploymentStatus();
	$suite->same(null, $status->getMissingEntryReferenceDate(null));
	$suite->same('2025-12-31', $status->getMissingEntryReferenceDate('2025-12-31'));
	$suite->same(['isActive', 'freelancerMode', 'statusRevision'], array_keys($status->toPublicArray()));
});
$suite->finish('Employment status/store');
