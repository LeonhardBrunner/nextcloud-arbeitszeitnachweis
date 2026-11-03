<?php

declare(strict_types=1);

// These checks exercise the real access and recipient services without a database or mail transport.
namespace OCP\AppFramework\Services {
	interface IAppConfig {
		public function getAppValueString(string $key): string;
	}
}

namespace OCP {
	interface IGroupManager {
		public function groupExists(string $groupId): bool;
		public function isInGroup(string $userId, string $groupId): bool;
		public function get(string $groupId): ?object;
	}
	interface IUserManager {
		public function get(string $userId): ?object;
	}
	interface IUserSession {
		public function getUser(): ?object;
	}
}

namespace {
	use OCA\Timesheet\Service\HrRuleAssignments;
	use OCA\Timesheet\Service\HrService;

	require __DIR__ . '/../../lib/Service/HrRuleAssignments.php';
	require __DIR__ . '/../../lib/Service/HrService.php';

	class AssignmentUser {
		public bool $failEnabledLookup = false;
		public function __construct(private string $id, private string $name, public bool $enabled = true) { }
		public function getUID(): string { return $this->id; }
		public function getDisplayName(): string { return $this->name; }
		public function isEnabled(): bool {
			if ($this->failEnabledLookup) throw new \RuntimeException('Synthetic enabled-state lookup failure.');
			return $this->enabled;
		}
	}
	class AssignmentUsers implements \OCP\IUserManager {
		public array $records = [];
		public array $failedIds = [];
		public array $calls = [];
		public ?\Closure $beforeGet = null;
		public function __construct() {
			foreach (['anton' => 'Anton', 'bea' => 'Bea', 'cara' => 'Cara', 'off' => 'Disabled HR', 'outside' => 'Outside', 'alice' => 'Alice', 'bob' => 'Bob', '0' => 'Numeric identifier'] as $id => $name) {
				$this->records[$id] = new AssignmentUser((string)$id, $name, $id !== 'off');
			}
		}
		public function get(string $userId): ?object {
			$this->calls[] = $userId;
			if ($this->beforeGet !== null) ($this->beforeGet)($userId);
			if (in_array($userId, $this->failedIds, true)) throw new \RuntimeException('Synthetic account backend failure.');
			return $this->records[$userId] ?? null;
		}
	}
	class AssignmentGroup {
		public function __construct(private array $members, private AssignmentUsers $users) { }
		public function getUsers(): array {
			return array_values(array_filter(array_map(fn(string $id): ?object => $this->users->records[$id] ?? null, $this->members)));
		}
	}
	class AssignmentGroups implements \OCP\IGroupManager {
		public array $members = ['HR' => ['anton', 'bea', 'off'], 'HR backup' => ['anton'], 'Staff A' => ['alice'], 'Staff B' => ['bob']];
		public function __construct(private AssignmentUsers $users) { }
		public function groupExists(string $groupId): bool { return array_key_exists($groupId, $this->members); }
		public function isInGroup(string $userId, string $groupId): bool { return in_array($userId, $this->members[$groupId] ?? [], true); }
		public function get(string $groupId): ?object { return $this->groupExists($groupId) ? new AssignmentGroup($this->members[$groupId], $this->users) : null; }
	}
	class AssignmentConfig implements \OCP\AppFramework\Services\IAppConfig {
		public function __construct(public array $rules) { }
		public function getAppValueString(string $key): string { return json_encode($this->rules, JSON_THROW_ON_ERROR); }
	}
	class AssignmentSession implements \OCP\IUserSession {
		public function __construct(public ?AssignmentUser $user) { }
		public function getUser(): ?object { return $this->user; }
	}

	$checks = 0;
	$failures = 0;
	function sameAssignment(mixed $expected, mixed $actual): void {
		if ($expected !== $actual) throw new \RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual], JSON_THROW_ON_ERROR));
	}
	function invalidAssignment(callable $operation): void {
		try { $operation(); } catch (\InvalidArgumentException) { return; }
		throw new \RuntimeException('Invalid account or recipient selection was accepted.');
	}
	function checkAssignment(string $description, callable $operation): void {
		global $checks, $failures;
		$checks++;
		try { $operation(); } catch (\Throwable $error) { $failures++; fwrite(STDERR, $description . ': ' . $error->getMessage() . "\n"); }
	}
	function assignmentFixture(array $rules): array {
		$users = new AssignmentUsers(); $groups = new AssignmentGroups($users);
		$config = new AssignmentConfig($rules); $session = new AssignmentSession($users->records['anton']);
		$helper = new HrRuleAssignments($groups, $users); $service = new HrService($config, $session, $groups, $users);
		return compact('users', 'groups', 'config', 'session', 'helper', 'service');
	}
	$legacy = ['id' => 'one', 'hrGroups' => ['HR'], 'userGroups' => ['Staff A']];
	$selected = static fn(array $userIds): array => ['mode' => 'selected', 'userIds' => $userIds];

	checkAssignment('Legacy rules preserve their recipients and do not change stored settings', function () use ($legacy): void {
		$f = assignmentFixture([$legacy]);
		sameAssignment(['hrGroups' => ['HR'], 'hrUsers' => [], 'mailRecipients' => ['mode' => 'all', 'userIds' => []]], $f['helper']->normalize($legacy));
		sameAssignment(['anton', 'bea', 'off'], $f['service']->getAllHrUserIds());
		sameAssignment(['anton', 'bea'], $f['service']->getMailRecipientUserIds());
		sameAssignment(['alice'], array_column($f['service']->getAccessibleUsers('bea'), 'id'));
		sameAssignment(['alice'], array_column($f['service']->getMailAccessibleUsers('bea'), 'id'));
		sameAssignment([$legacy], $f['config']->rules);
	});
	checkAssignment('A direct HR assignee has access without membership in an HR group', function () use ($legacy): void {
		$f = assignmentFixture([array_merge($legacy, ['hrUsers' => ['cara']])]);
		sameAssignment(true, $f['service']->isHr('cara'));
		sameAssignment(['Staff A'], $f['service']->getAllowedUserGroups('cara'));
		sameAssignment(true, $f['service']->canAccessUser('alice', 'cara'));
		sameAssignment(false, $f['service']->canAccessUser('bob', 'cara'));
		sameAssignment(['anton', 'bea', 'cara'], $f['service']->getMailRecipientUserIds());
	});
	checkAssignment('Rules may assign only individual HR accounts', function () use ($legacy): void {
		$f = assignmentFixture([array_merge($legacy, ['hrGroups' => [], 'hrUsers' => ['cara']])]);
		sameAssignment(true, $f['service']->isHr('cara')); sameAssignment(false, $f['service']->isHr('anton'));
		sameAssignment(['cara'], $f['service']->getAllHrUserIds()); sameAssignment([], $f['service']->getHrGroups());
		sameAssignment(['alice'], array_column($f['service']->getAccessibleUsers('cara'), 'id'));
	});
	checkAssignment('Overlapping groups and direct selections do not duplicate recipients', function () use ($legacy): void {
		$rule = array_merge($legacy, ['hrGroups' => ['HR', 'HR backup'], 'hrUsers' => ['anton', 'cara', 'cara']]);
		$f = assignmentFixture([$rule, array_merge($rule, ['id' => 'two', 'userGroups' => ['Staff B']])]);
		sameAssignment(['anton', 'bea', 'off', 'cara'], $f['service']->getAllHrUserIds());
		sameAssignment(['anton', 'bea', 'cara'], $f['service']->getMailRecipientUserIds());
		sameAssignment(['alice', 'bob'], array_column($f['service']->getMailAccessibleUsers('anton'), 'id'));
	});
	checkAssignment('Selected recipients do not remove other HR access or grant outsider access', function () use ($legacy, $selected): void {
		$f = assignmentFixture([array_merge($legacy, ['mailRecipients' => $selected(['anton', 'outside'])])]);
		sameAssignment(['anton'], $f['service']->getMailRecipientUserIds());
		sameAssignment(true, $f['service']->isHr('bea')); sameAssignment(false, $f['service']->isHr('outside'));
		sameAssignment(['alice'], array_column($f['service']->getAccessibleUsers('bea'), 'id'));
		sameAssignment([], $f['service']->getMailAccessibleUsers('bea')); sameAssignment([], $f['service']->getAccessibleUsers('outside'));
	});
	checkAssignment('An explicitly empty mail selection never falls back to the whole group', function () use ($legacy, $selected): void {
		$f = assignmentFixture([array_merge($legacy, ['mailRecipients' => $selected([])])]);
		sameAssignment([], $f['service']->getMailRecipientUserIds());
		sameAssignment(['alice'], array_column($f['service']->getAccessibleUsers('anton'), 'id'));
		sameAssignment([], $f['service']->getMailAccessibleUsers('anton'));
	});
	checkAssignment('Per-rule mail scope excludes employees from rules omitted by the recipient selection', function () use ($legacy, $selected): void {
		$f = assignmentFixture([
			array_merge($legacy, ['mailRecipients' => $selected(['anton'])]),
			array_merge($legacy, ['id' => 'two', 'userGroups' => ['Staff B'], 'mailRecipients' => $selected(['bea'])]),
		]);
		sameAssignment(['alice', 'bob'], array_column($f['service']->getAccessibleUsers('anton'), 'id'));
		sameAssignment(['alice'], array_column($f['service']->getMailAccessibleUsers('anton'), 'id'));
		sameAssignment(['bob'], array_column($f['service']->getMailAccessibleUsers('bea'), 'id'));
		sameAssignment(['anton', 'bea'], $f['service']->getMailRecipientUserIds());
	});
	checkAssignment('Removing HR group membership immediately excludes a selected recipient', function () use ($legacy, $selected): void {
		$f = assignmentFixture([array_merge($legacy, ['mailRecipients' => $selected(['bea'])])]);
		$f['groups']->members['HR'] = ['anton', 'off'];
		sameAssignment(false, $f['service']->isHr('bea')); sameAssignment([], $f['service']->getMailRecipientUserIds());
		sameAssignment([], $f['service']->getMailAccessibleUsers('bea'));
	});
	checkAssignment('A remaining direct assignment keeps responsibility after group removal', function () use ($legacy, $selected): void {
		$f = assignmentFixture([array_merge($legacy, ['hrUsers' => ['bea'], 'mailRecipients' => $selected(['bea'])])]);
		$f['groups']->members['HR'] = ['anton'];
		sameAssignment(true, $f['service']->isHr('bea')); sameAssignment(['bea'], $f['service']->getMailRecipientUserIds());
	});
	checkAssignment('Deleted individual assignees cannot retain a role or receive mail', function () use ($legacy, $selected): void {
		$f = assignmentFixture([array_merge($legacy, ['hrGroups' => [], 'hrUsers' => ['cara'], 'mailRecipients' => $selected(['cara'])])]);
		unset($f['users']->records['cara']);
		sameAssignment(false, $f['service']->isHr('cara')); sameAssignment([], $f['service']->getMailRecipientUserIds());
		sameAssignment([], $f['service']->getAllHrUserIds());
	});
	checkAssignment('Disabled accounts stay assigned but receive no rule mail', function () use ($legacy, $selected): void {
		$f = assignmentFixture([array_merge($legacy, ['hrGroups' => [], 'hrUsers' => ['off'], 'mailRecipients' => $selected(['off'])])]);
		sameAssignment(['off'], $f['service']->getAllHrUserIds()); sameAssignment([], $f['service']->getMailRecipientUserIds());
		$f['users']->records['off']->enabled = true; sameAssignment(['off'], $f['service']->getMailRecipientUserIds());
	});
	checkAssignment('Deleted groups no longer confer access or mail delivery', function () use ($legacy): void {
		$f = assignmentFixture([$legacy]); unset($f['groups']->members['HR']);
		sameAssignment(false, $f['service']->isHr('anton')); sameAssignment([], $f['service']->getMailRecipientUserIds());
	});
	checkAssignment('Legacy alternative field names remain readable', function (): void {
		$f = assignmentFixture([['id' => 'alias', 'hr_groups' => ['HR'], 'user_groups' => ['Staff A']]]);
		sameAssignment(true, $f['service']->isHr('anton')); sameAssignment(['Staff A'], $f['service']->getAllowedUserGroups());
	});
	checkAssignment('Unauthenticated and unassigned accounts receive no HR scope', function () use ($legacy): void {
		$f = assignmentFixture([$legacy]); $f['session']->user = null;
		sameAssignment(false, $f['service']->isHr()); sameAssignment([], $f['service']->getAccessibleUsers());
		sameAssignment([], $f['service']->getMailAccessibleUsers()); sameAssignment(false, $f['service']->canAccessUser('alice'));
		sameAssignment(true, $f['service']->canAccessUser('alice', 'alice'));
	});
	checkAssignment('Numeric-looking user identifiers remain strings', function () use ($legacy): void {
		$f = assignmentFixture([array_merge($legacy, ['hrGroups' => [], 'hrUsers' => ['0']])]);
		sameAssignment(['0'], $f['service']->getAllHrUserIds()); sameAssignment(['0'], $f['service']->getMailRecipientUserIds());
	});
	checkAssignment('One unavailable account does not block other HR roles or recipients', function () use ($legacy): void {
		$f = assignmentFixture([array_merge($legacy, ['hrUsers' => ['cara']])]);
		$f['users']->failedIds = ['bea', 'cara'];
		sameAssignment(false, $f['service']->isHr('bea')); sameAssignment(false, $f['service']->isHr('cara'));
		sameAssignment(true, $f['service']->isHr('anton')); sameAssignment(['anton'], $f['service']->getMailRecipientUserIds());
		sameAssignment(['alice'], array_column($f['service']->getMailAccessibleUsers('anton'), 'id'));
	});
	checkAssignment('An unavailable enabled-state lookup skips only its own mail recipient', function () use ($legacy): void {
		$f = assignmentFixture([$legacy]); $f['users']->records['bea']->failEnabledLookup = true;
		sameAssignment(['anton'], $f['service']->getMailRecipientUserIds()); sameAssignment(true, $f['service']->isHr('bea'));
	});
	checkAssignment('Saving an unavailable account fails instead of silently removing it', function () use ($legacy): void {
		$f = assignmentFixture([$legacy]); $f['users']->failedIds = ['cara'];
		try {
			$f['helper']->validateForSave(array_merge($legacy, ['hrUsers' => ['cara']]));
			throw new \LogicException('The unavailable account was silently accepted.');
		} catch (\RuntimeException $error) {
			sameAssignment('Synthetic account backend failure.', $error->getMessage());
		}
	});
	checkAssignment('Successful validation retains exact IDs without a later unsafe normalization lookup', function () use ($legacy, $selected): void {
		$f = assignmentFixture([$legacy]); $lookups = 0;
		$f['users']->beforeGet = static function (string $userId) use (&$lookups): void {
			if ($userId === 'cara' && ++$lookups > 2) throw new \RuntimeException('The backend became unavailable after validation.');
		};
		$validated = $f['helper']->validateForSave(array_merge($legacy, ['hrUsers' => ['cara'], 'mailRecipients' => $selected(['cara'])]));
		sameAssignment(['cara'], $validated['hrUsers']); sameAssignment($selected(['cara']), $validated['mailRecipients']);
		sameAssignment(2, $lookups);
	});
	checkAssignment('Validation trims and deduplicates new selections without changing legacy group cleanup', function () use ($legacy, $selected): void {
		$f = assignmentFixture([$legacy]);
		$rule = array_merge($legacy, ['hrGroups' => [' HR ', 'HR', 'missing'], 'hrUsers' => [' cara ', 'cara'], 'mailRecipients' => $selected([' cara ', 'cara'])]);
		sameAssignment(['hrGroups' => ['HR'], 'hrUsers' => ['cara'], 'mailRecipients' => $selected(['cara'])], $f['helper']->validateForSave($rule));
	});
	checkAssignment('An empty selected list is valid and remains intentionally empty', function () use ($legacy, $selected): void {
		$f = assignmentFixture([$legacy]); sameAssignment($selected([]), $f['helper']->validateForSave(array_merge($legacy, ['mailRecipients' => $selected([])]))['mailRecipients']);
	});
	foreach ([null, 'anton', ['missing'], [123], ['id' => 'anton'], ['']] as $invalid) {
		checkAssignment('Invalid direct HR account lists are rejected: ' . json_encode($invalid), function () use ($legacy, $invalid): void {
			$f = assignmentFixture([$legacy]); invalidAssignment(fn() => $f['helper']->validateForSave(array_merge($legacy, ['hrUsers' => $invalid])));
		});
	}
	foreach ([null, 'all', [], ['mode' => 'unknown'], ['mode' => 'all', 'userIds' => null], ['mode' => 'selected', 'userIds' => 'anton'], ['mode' => 'selected', 'userIds' => ['missing']], ['mode' => 'selected', 'userIds' => [123]]] as $invalid) {
		checkAssignment('Invalid explicit mail settings are rejected: ' . json_encode($invalid), function () use ($legacy, $invalid): void {
			$f = assignmentFixture([$legacy]); invalidAssignment(fn() => $f['helper']->validateForSave(array_merge($legacy, ['mailRecipients' => $invalid])));
		});
	}
	checkAssignment('Saving cannot make an unrelated account an email recipient', function () use ($legacy, $selected): void {
		$f = assignmentFixture([$legacy]); invalidAssignment(fn() => $f['helper']->validateForSave(array_merge($legacy, ['mailRecipients' => $selected(['outside'])])));
	});
	foreach ([null, 'all', [], ['mode' => 'unknown'], ['mode' => 'all', 'userIds' => null], ['mode' => 'all', 'userIds' => 'bad'], ['mode' => 'selected', 'userIds' => ['deleted']]] as $invalid) {
		checkAssignment('Malformed stored mail settings fail closed: ' . json_encode($invalid), function () use ($legacy, $invalid): void {
			$f = assignmentFixture([array_merge($legacy, ['mailRecipients' => $invalid])]); sameAssignment([], $f['service']->getMailRecipientUserIds());
		});
	}

	fwrite(STDOUT, "HR assignments: $checks checks, $failures failures.\n");
	exit($failures === 0 ? 0 : 1);
}
