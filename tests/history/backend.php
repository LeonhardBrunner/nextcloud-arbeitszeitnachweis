<?php

declare(strict_types=1);

// Standalone behavior checks use the real write coordinator, entry service and history reader.
namespace OC { class ForbiddenException extends \Exception {} }
namespace OCP {
	interface IDBConnection { public function beginTransaction(): void; public function commit(): void; public function rollBack(): void; }
	interface IUserSession { public function getUser(); }
	interface IUserManager { public function get($userId); }
	interface IConfig {}
}
namespace OCP\EventDispatcher { class Event {} interface IEventListener { public function handle(Event $event): void; } }
namespace OCP\User\Events {
	class UserCreatedEvent extends \OCP\EventDispatcher\Event { public function __construct(private $user) {} public function getUser() { return $this->user; } }
}
namespace OCP\Lock {
	interface ILockingProvider { public const LOCK_EXCLUSIVE = 2; public function acquireLock($key, $type); public function releaseLock($key, $type); }
}
namespace OCP\AppFramework\Utility { interface ITimeFactory { public function getTime(); public function getDateTime($time = 'now', ?\DateTimeZone $zone = null); } }
namespace OCP\AppFramework\Services { interface IAppConfig { public function getAppValueString($key, $default = ''); } }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { public const PARAM_INT = 1; public const PARAM_STR = 2; public const PARAM_NULL = 0; public const PARAM_STR_ARRAY = 102; } }
namespace OCP\AppFramework\Db {
	class DoesNotExistException extends \Exception {}
	class Entity {
		protected $id;
		protected function addType($name, $type): void {}
		public function __call($method, $arguments) {
			$field = lcfirst(substr($method, 3));
			if (str_starts_with($method, 'get')) return $this->$field;
			$this->$field = $arguments[0];
		}
	}
}
namespace OCA\Timesheet\Db {
	class EntryMapper {
		public function __construct(private \HistoryMemoryDatabase $database) {}
		public function findById(int $id): Entry {
			if (!isset($this->database->entries[$id])) throw new \OCP\AppFramework\Db\DoesNotExistException();
			return clone $this->database->entries[$id];
		}
		public function findByUserAndDate(string $id, string $date): ?Entry {
			foreach ($this->database->entries as $entry) if ($entry->getUserId() === $id && $entry->getWorkDate() === $date) return clone $entry;
			return null;
		}
		public function insert(Entry $entry): Entry { $entry->setId(++$this->database->nextId); return $this->update($entry); }
		public function update(Entry $entry): Entry { $this->database->entries[$entry->getId()] = clone $entry; return $entry; }
		public function delete(Entry $entry): void { unset($this->database->entries[$entry->getId()]); }
		public function upsertCommentOnly(string $id, string $date, string $comment): Entry {
			$entry = $this->findByUserAndDate($id, $date);
			if (!$entry) {
				$entry = new Entry(); $entry->setUserId($id); $entry->setWorkDate($date); $entry->setStartMin(null); $entry->setEndMin(null);
				$entry->setBreakMinutes(0); $entry->setComment($comment); $entry->setCreatedAt(1); $entry->setUpdatedAt(1);
				return $this->insert($entry);
			}
			$entry->setComment($comment); $entry->setUpdatedAt(2); return $this->update($entry);
		}
	}
	class UserConfigMapper {
		public function __construct(private \HistoryMemoryDatabase $database) {}
		public function getConfigData(string $id): array { return $this->database->settings[$id] ?? ['dailyMin' => null, 'state' => null]; }
		public function upsertConfigData(string $id, int $minutes, string $state): array { return $this->database->settings[$id] = ['dailyMin' => $minutes, 'state' => $state]; }
	}
}
namespace OCA\Timesheet\Service {
	class HrService {
		public function __construct(private \HistorySession $session) {}
		public function isHr(?string $id = null): bool { return in_array($id ?? $this->session->getUser()?->getUID(), ['hr-a', 'hr-b', 'hr-admin'], true); }
		public function canAccessUser(string $employee, ?string $actor = null): bool {
			$actor ??= $this->session->getUser()?->getUID();
			return $actor === $employee || ($this->isHr($actor) && in_array($employee, ['employee', 'inactive', 'freelancer'], true));
		}
		public function getAccessibleUsers(?string $id = null): array { return array_map(static fn($uid) => ['id' => $uid, 'name' => $uid], ['employee', 'inactive', 'freelancer']); }
	}
	class MonthlyBalanceService {
		public function __construct(private \HistoryMemoryDatabase $database) {}
		public function markDirtyFromDate(string $id, string $date): void { $this->database->dirty[] = [$id, $date]; }
		public function markAllDirtyForUser(string $id): void { $this->database->dirty[] = [$id, 'all']; }
	}
}
namespace {
	require __DIR__ . '/../../lib/Db/Entry.php';
	require __DIR__ . '/../../lib/Db/ChangeHistoryStore.php';
	require __DIR__ . '/../../lib/Db/ChangeHistoryAccountQueue.php';
	require __DIR__ . '/../../lib/Db/ChangeHistoryRecordingStore.php';
	require __DIR__ . '/../../lib/Service/HistoryChangeSet.php';
	require __DIR__ . '/../../lib/Service/HistoryWriteCoordinator.php';
	require __DIR__ . '/../../lib/Service/WorkSettingsService.php';
	require __DIR__ . '/../../lib/Service/ChangeHistoryService.php';
	require __DIR__ . '/../../lib/Service/EntryService.php';
	require __DIR__ . '/../../lib/Listener/ChangeHistoryUserCreatedListener.php';

	class HistoryMemoryDatabase implements \OCP\IDBConnection {
		public array $entries = []; public array $settings = []; public array $dirty = []; public array $records = []; public array $cleanupMarkers = [];
		public array $historyReadLimits = [];
		public int $nextId = 0; public int $commits = 0; public int $rollbacks = 0; private ?string $snapshot = null;
		public function beginTransaction(): void { if ($this->snapshot !== null) throw new RuntimeException('Nested transaction'); $this->snapshot = serialize([$this->entries, $this->settings, $this->dirty, $this->records, $this->nextId, $this->cleanupMarkers]); }
		public function commit(): void { $this->snapshot = null; $this->commits++; }
		public function rollBack(): void { [$this->entries, $this->settings, $this->dirty, $this->records, $this->nextId, $this->cleanupMarkers] = unserialize($this->snapshot); $this->snapshot = null; $this->rollbacks++; }
		public function getQueryBuilder() { return new HistoryReadQuery($this); }
	}

	/** Executes the production store's scoped read queries against the same in-memory records. */
	class HistoryReadQuery implements \OCP\DB\QueryBuilder\IQueryBuilder {
		private array $conditions = []; private int $limit = PHP_INT_MAX;
		public function __construct(private HistoryMemoryDatabase $database) {}
		public function select($fields) { return $this; }
		public function from($table) { if ($table !== 'ts_change_history') throw new RuntimeException('Unexpected history table'); return $this; }
		public function where($condition) { $this->conditions = [$condition]; return $this; }
		public function andWhere($condition) { $this->conditions[] = $condition; return $this; }
		public function createNamedParameter($value, $type = null) { return $value; }
		public function expr() { return $this; }
		public function eq($field, $value) { return static fn($row) => $row[$field] === $value; }
		public function in($field, $value) { return static fn($row) => in_array($row[$field], $value, true); }
		public function gte($field, $value) { return static fn($row) => $row[$field] >= $value; }
		public function lt($field, $value) { return static fn($row) => $row[$field] < $value; }
		public function isNull($field) { return static fn($row) => $row[$field] === null; }
		public function orderBy($field, $direction) { if ($field !== 'id' || $direction !== 'DESC') throw new RuntimeException('Unexpected history order'); return $this; }
		public function setMaxResults($limit) { $this->limit = $limit; return $this; }
		public function executeQuery() {
			$this->database->historyReadLimits[] = $this->limit;
			$rows = [];
			foreach ($this->database->records as $record) {
				$row = ['id' => $record['id'], 'occurred_at' => $record['occurredAt'], 'actor_id' => $record['actor']['id'], 'actor_name' => $record['actor']['name'],
					'employee_id' => $record['employee']['id'], 'employee_name' => $record['employee']['name'], 'kind' => $record['kind'],
					'entry_id' => $record['entryId'], 'work_date' => $record['workDate'], 'changes' => json_encode($record['changes'], JSON_THROW_ON_ERROR)];
				$matches = true;
				foreach ($this->conditions as $condition) {
					if (!$condition($row)) { $matches = false; break; }
				}
				if ($matches) $rows[] = $row;
			}
			usort($rows, static fn($first, $second) => $second['id'] <=> $first['id']);
			return new class(array_slice($rows, 0, $this->limit)) {
				public function __construct(private array $rows) {}
				public function fetchAll() { return $this->rows; }
				public function closeCursor(): void {}
			};
		}
	}
	class HistoryUser {
		public function __construct(private string $id, public bool $enabled = true) {}
		public function getUID(): string { return $this->id; }
		public function getDisplayName(): string { return 'Name ' . $this->id; }
		public function isEnabled(): bool { return $this->enabled; }
	}
	class HistorySession implements \OCP\IUserSession { public function __construct(public ?HistoryUser $user) {} public function getUser() { return $this->user; } }
	class HistoryUsers implements \OCP\IUserManager { public array $users = []; public function get($id) { return $this->users[$id] ?? null; } }
	class HistoryLocks implements \OCP\Lock\ILockingProvider {
		public array $held = []; public array $trace = [];
		public function acquireLock($key, $type) { if (isset($this->held[$key])) throw new RuntimeException('Locked'); $this->held[$key] = true; $this->trace[] = $key; }
		public function releaseLock($key, $type) { unset($this->held[$key]); }
	}
	class HistoryClock implements \OCP\AppFramework\Utility\ITimeFactory {
		public function __construct(public DateTimeImmutable $now) {}
		public function getTime() { return $this->now->getTimestamp(); }
		public function getDateTime($time = 'now', ?DateTimeZone $zone = null) { $value = $this->now->setTimezone($zone ?? new DateTimeZone('UTC')); if ($time === 'today') $value = $value->setTime(0, 0); return DateTime::createFromImmutable($value); }
	}
	class HistoryAppConfig implements \OCP\AppFramework\Services\IAppConfig { public function getAppValueString($key, $default = '') { return $key === 'change_history_started_at' ? '1793600000' : $default; } }
	class HistoryMemoryRecording extends \OCA\Timesheet\Db\ChangeHistoryRecordingStore {
		public function __construct() {}
		public function getStartedAt(): int { return 1793600000; }
	}
	class RecordingMemoryDatabase extends HistoryMemoryDatabase {
		public bool $tablePresent = true; public array $values = []; public int $schemaReads = 0;
		public function tableExists($table): bool { $this->schemaReads++; return $this->tablePresent; }
		public function getQueryBuilder() {
			return new class($this) {
				public function __construct(private RecordingMemoryDatabase $database) {}
				public function select($fields) { return $this; } public function from($table) { return $this; } public function where($condition) { return $this; } public function andWhere($condition) { return $this; }
				public function createNamedParameter($value) { return $value; }
				public function expr() { return new class { public function eq($field, $value) { return [$field, $value]; } }; }
				public function executeQuery() { return new class($this->database->values['change_history_started_at'] ?? false) { public function __construct(private $value) {} public function fetchOne() { return $this->value; } public function closeCursor(): void {} }; }
			};
		}
	}
	class RecordingConfig implements \OCP\IConfig {
		public string $version = '1.4.0'; public int $writes = 0;
		public function __construct(private RecordingMemoryDatabase $database) {}
		public function getAppValue($app, $key, $default = '') { return $key === 'installed_version' ? $this->version : ($this->database->values[$key] ?? $default); }
		public function setAppValue($app, $key, $value): void { $this->writes++; $this->database->values[$key] = $value; }
	}
	class HistoryMemoryQueue extends \OCA\Timesheet\Db\ChangeHistoryAccountQueue {
		public function __construct(private HistoryMemoryDatabase $memory) {}
		public function mark(string $id): void { $this->memory->cleanupMarkers[$id] = true; }
		public function remove(string $id): void { unset($this->memory->cleanupMarkers[$id]); }
		public function contains(string $id): bool { return isset($this->memory->cleanupMarkers[$id]); }
		public function pendingAccountIds(): array { return array_keys($this->memory->cleanupMarkers); }
	}
	class HistoryMemoryStore extends \OCA\Timesheet\Db\ChangeHistoryStore {
		public bool $fail = false; public bool $failRemoval = false;
		public function __construct(public HistoryMemoryDatabase $memory) { parent::__construct($memory); }
		public function append(array $record): void { if ($this->fail) throw new RuntimeException('Synthetic audit failure'); $record['id'] = count($this->memory->records) + 1; $this->memory->records[] = $record; }
		public function actors(array $ids, int $cutoff): array { $actors = []; foreach ($this->memory->records as $record) if (in_array($record['employee']['id'], $ids, true) && $record['occurredAt'] >= $cutoff) $actors[$record['actor']['id'] ?? 'deleted'] = $record['actor']; return array_values($actors); }
		public function removeAccount(string $id): void { if ($this->failRemoval) throw new RuntimeException('Synthetic deletion failure'); $this->memory->records = array_values(array_filter($this->memory->records, static fn($r) => $r['employee']['id'] !== $id)); foreach ($this->memory->records as &$r) if ($r['actor']['id'] === $id) $r['actor'] = ['id' => null, 'name' => '']; unset($r); }
	}
	function historyFixture(): array {
		$db = new HistoryMemoryDatabase(); $users = new HistoryUsers(); foreach (['hr-a', 'hr-b', 'hr-admin', 'employee', 'inactive', 'freelancer', 'foreign', 'admin'] as $id) $users->users[$id] = new HistoryUser($id);
		$session = new HistorySession($users->get('hr-a')); $hr = new \OCA\Timesheet\Service\HrService($session); $locks = new HistoryLocks();
		$clock = new HistoryClock(new DateTimeImmutable('2026-11-03 10:30:00', new DateTimeZone('Europe/Berlin'))); $history = new HistoryMemoryStore($db); $queue = new HistoryMemoryQueue($db);
		$writes = new \OCA\Timesheet\Service\HistoryWriteCoordinator($db, $locks, $session, $hr, $users, $history, $clock, $queue);
		$mapper = new \OCA\Timesheet\Db\EntryMapper($db); $balances = new \OCA\Timesheet\Service\MonthlyBalanceService($db);
		$entries = new \OCA\Timesheet\Service\EntryService($mapper, $session, $balances, $writes);
		$configs = new \OCA\Timesheet\Service\WorkSettingsService(new \OCA\Timesheet\Db\UserConfigMapper($db), $balances, $writes);
		$reader = new \OCA\Timesheet\Service\ChangeHistoryService($history, $hr, $session, new HistoryAppConfig(), $clock, $queue, new HistoryMemoryRecording());
		return compact('db', 'users', 'session', 'hr', 'locks', 'clock', 'history', 'queue', 'writes', 'mapper', 'entries', 'configs', 'reader');
	}
	$checks = 0; $failures = 0;
	function checkHistory(string $name, callable $test): void { global $checks, $failures; $checks++; try { $test(); } catch (Throwable $error) { $failures++; fwrite(STDERR, $name . ': ' . $error->getMessage() . "\n"); } }
	function sameHistory(mixed $actual, mixed $expected): void { if ($actual !== $expected) throw new RuntimeException('Expected ' . json_encode($expected) . '; got ' . json_encode($actual)); }
	function failsHistory(callable $call, string $type): void { try { $call(); } catch (Throwable $e) { if ($e instanceof $type) return; throw $e; } throw new RuntimeException('Expected exception ' . $type); }
	$entryData = ['workDate' => '2026-11-02', 'startMin' => 0, 'endMin' => 480, 'breakMinutes' => -10, 'comment' => 'Original'];
	checkHistory('creation records complete input snapshot, exact actor and historical pause value', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $record = $f['db']->records[0];
		sameHistory($record['actor'], ['id' => 'hr-a', 'name' => 'Name hr-a']); sameHistory($record['employee']['id'], 'employee'); sameHistory($record['kind'], 'created');
		sameHistory($record['entryId'], $entry->getId()); sameHistory(count($record['changes']), 5); sameHistory($record['changes'][1]['after'], 0); sameHistory($record['changes'][3]['after'], -10); sameHistory($f['locks']->held, []);
	});
	checkHistory('second HR editor records only changed fields and sees freshly stored values', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['session']->user = $f['users']->get('hr-b'); $f['entries']->update($entry->getId(), ['comment' => 'Changed']);
		sameHistory($f['db']->records[1]['actor']['id'], 'hr-b'); sameHistory($f['db']->records[1]['changes'], [['field' => 'comment', 'before' => 'Original', 'after' => 'Changed']]);
	});
	checkHistory('employee and HR own writes are serialized but never audited', function () use ($entryData): void {
		$f = historyFixture(); foreach (['employee', 'hr-a'] as $uid) { $f['session']->user = $f['users']->get($uid); $entry = $f['entries']->create($entryData, $uid); $f['entries']->update($entry->getId(), ['comment' => 'Own']); $f['entries']->delete($entry->getId()); }
		sameHistory($f['db']->records, []); sameHistory($f['db']->commits, 6); sameHistory($f['locks']->held, []);
	});
	checkHistory('no-op updates, repeated comment posts and duplicate creates never add events', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $dirty = $f['db']->dirty;
		$f['entries']->update($entry->getId(), $entryData); $f['entries']->upsertCommentOnly('employee', $entryData['workDate'], 'Original');
		failsHistory(fn() => $f['entries']->create($entryData, 'employee'), DomainException::class); sameHistory(count($f['db']->records), 1); sameHistory($f['db']->dirty, $dirty);
	});
	checkHistory('comment-only POST creation and existing comment updates are both recorded', function (): void {
		$f = historyFixture(); $entry = $f['entries']->upsertCommentOnly('employee', '2026-11-02', 'Vacation'); $f['entries']->upsertCommentOnly('employee', '2026-11-02', 'Time off in lieu');
		sameHistory(array_column($f['db']->records, 'kind'), ['created', 'updated']); sameHistory($f['db']->records[1]['changes'], [['field' => 'comment', 'before' => 'Vacation', 'after' => 'Time off in lieu']]); sameHistory($entry->getStartMin(), null);
	});
	checkHistory('comment classification includes additions, edits and clearing without changing event kinds', function (): void {
		foreach ([['created', null, 'Vacation', true], ['created', null, '', false], ['created', null, null, false],
			['updated', '', 'Added', true], ['updated', 'Before', 'After', true], ['updated', 'Before', '', true],
			['updated', 'Before', null, true], ['updated', '', null, false], ['updated', 'Same', 'Same', false],
			['deleted', 'Vacation', null, false], ['settings', '', 'Vacation', false]] as [$kind, $before, $after, $expected]) {
			sameHistory(\OCA\Timesheet\Service\HistoryChangeSet::hasCommentChange(['kind' => $kind,
				'changes' => [['field' => 'comment', 'before' => $before, 'after' => $after]]]), $expected);
		}
		sameHistory(\OCA\Timesheet\Service\HistoryChangeSet::hasCommentChange(['kind' => 'updated', 'changes' => [['field' => 'endMin', 'before' => 480, 'after' => 490]]]), false);
	});
	checkHistory('comment filter includes existing comment-only creations and mixed updates but excludes deletions', function () use ($entryData): void {
		$f = historyFixture();
		$entry = $f['entries']->upsertCommentOnly('employee', '2026-11-02', 'Vacation');
		$f['entries']->upsertCommentOnly('employee', '2026-11-02', 'Revised');
		$f['entries']->update($entry->getId(), ['startMin' => 480, 'endMin' => 960, 'comment' => 'Mixed change']);
		$f['entries']->update($entry->getId(), ['comment' => '']);
		$f['entries']->update($entry->getId(), ['endMin' => 975]);
		$f['entries']->delete($entry->getId());
		$f['entries']->create([...$entryData, 'workDate' => '2026-11-01', 'comment' => ''], 'employee');
		$before = serialize($f['db']->records);
		$page = $f['reader']->list(['kind' => 'comment']);
		sameHistory(array_column($page['items'], 'id'), [4, 3, 2, 1]);
		sameHistory(array_column($page['items'], 'kind'), ['updated', 'updated', 'updated', 'created']);
		sameHistory($page['nextCursor'], null); sameHistory(serialize($f['db']->records), $before);
	});
	checkHistory('comment filter scans past sparse nonmatching batches while keeping scope and other filters', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $template = $f['db']->records[0]; $f['db']->records = [];
		for ($id = 1; $id <= 260; $id++) {
			$record = [...$template, 'id' => $id, 'kind' => 'updated', 'changes' => [['field' => 'endMin', 'before' => 480, 'after' => 490]]];
			if (in_array($id, [1, 240, 250, 251, 252, 253], true)) $record['changes'] = [['field' => 'comment', 'before' => '', 'after' => 'Added']];
			if ($id === 250) $record['employee']['id'] = 'foreign';
			if ($id === 251) $record['actor'] = ['id' => 'hr-b', 'name' => 'Other HR'];
			if ($id === 252) $record['employee']['id'] = 'inactive';
			if ($id === 253) $record['occurredAt'] = $f['reader']->retentionCutoff() - 1;
			$f['db']->records[] = $record;
		}
		$page = $f['reader']->list(['kind' => 'comment', 'employee' => 'employee', 'actor' => 'hr-a']);
		sameHistory(array_column($page['items'], 'id'), [240, 1]); sameHistory($page['nextCursor'], null);
		sameHistory($f['db']->historyReadLimits, [100, 100, 100]);
	});
	checkHistory('comment-filter pagination counts matching events and uses stable IDs across several batches', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $template = $f['db']->records[0]; $f['db']->records = [];
		for ($id = 1; $id <= 220; $id++) {
			$f['db']->records[] = [...$template, 'id' => $id, 'kind' => 'updated', 'changes' => [$id % 4 === 0
				? ['field' => 'comment', 'before' => '', 'after' => 'Added'] : ['field' => 'endMin', 'before' => 480, 'after' => 490]]];
		}
		$first = $f['reader']->list(['kind' => 'comment']);
		$second = $f['reader']->list(['kind' => 'comment', 'beforeId' => $first['nextCursor']]);
		sameHistory(count($first['items']), 50); sameHistory($first['nextCursor'], 24); sameHistory(array_column($second['items'], 'id'), [20, 16, 12, 8, 4]);
		sameHistory($second['nextCursor'], null); sameHistory(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')), []);
	});
	checkHistory('comment-only PUT clearing times records every actual field change', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['entries']->update($entry->getId(), ['startMin' => null, 'endMin' => null, 'breakMinutes' => 0, 'comment' => 'Vacation']);
		sameHistory(array_column($f['db']->records[1]['changes'], 'field'), ['startMin', 'endMin', 'breakMinutes', 'comment']); sameHistory($f['db']->records[1]['changes'][0]['before'], 0);
	});
	checkHistory('deletion keeps a full input snapshot and no live entry', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['entries']->delete($entry->getId()); sameHistory($f['db']->records[1]['kind'], 'deleted'); sameHistory(count($f['db']->records[1]['changes']), 5); sameHistory($f['db']->entries, []);
	});
	checkHistory('settings include actual old/new target and federal state; no-op is not written', function (): void {
		$f = historyFixture(); $f['db']->settings['employee'] = ['dailyMin' => 480, 'state' => 'BY']; $f['configs']->save('employee', 0, 'HE'); $f['configs']->save('employee', 0, 'HE');
		sameHistory(count($f['db']->records), 1); sameHistory($f['db']->records[0]['kind'], 'settings'); sameHistory($f['db']->records[0]['workDate'], null); sameHistory($f['db']->records[0]['changes'][0], ['field' => 'dailyMin', 'before' => 480, 'after' => 0]); sameHistory(count($f['db']->dirty), 1);
	});
	checkHistory('audit failure rolls entry, history and balance flags back together', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $before = serialize([$f['db']->entries, $f['db']->records, $f['db']->dirty]); $f['history']->fail = true;
		failsHistory(fn() => $f['entries']->update($entry->getId(), ['comment' => 'Unrecorded']), RuntimeException::class); sameHistory(serialize([$f['db']->entries, $f['db']->records, $f['db']->dirty]), $before); sameHistory($f['db']->rollbacks, 1); sameHistory($f['locks']->held, []);
	});
	checkHistory('audit failure also rolls back deletion and settings', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['db']->settings['employee'] = ['dailyMin' => 480, 'state' => 'BY']; $before = serialize([$f['db']->entries, $f['db']->settings, $f['db']->records, $f['db']->dirty]); $f['history']->fail = true;
		failsHistory(fn() => $f['entries']->delete($entry->getId()), RuntimeException::class); failsHistory(fn() => $f['configs']->save('employee', 0, 'HE'), RuntimeException::class);
		sameHistory(serialize([$f['db']->entries, $f['db']->settings, $f['db']->records, $f['db']->dirty]), $before);
	});
	checkHistory('foreign accounts are denied regardless of supplied HR boolean', function () use ($entryData): void {
		$f = historyFixture(); $f['session']->user = $f['users']->get('foreign'); $entry = $f['entries']->create($entryData, 'foreign'); $f['session']->user = $f['users']->get('hr-a');
		foreach ([fn() => $f['entries']->update($entry->getId(), ['comment' => 'No'], true), fn() => $f['entries']->delete($entry->getId(), true), fn() => $f['entries']->create($entryData, 'foreign'), fn() => $f['configs']->save('foreign', 1, 'BY')] as $call) failsHistory($call, \OC\ForbiddenException::class);
		sameHistory($f['db']->records, []);
	});
	checkHistory('employee cannot turn a foreign write into an HR write', function () use ($entryData): void {
		$f = historyFixture(); $f['session']->user = $f['users']->get('employee'); failsHistory(fn() => $f['entries']->create($entryData, 'inactive'), \OC\ForbiddenException::class); sameHistory($f['db']->entries, []);
	});
	checkHistory('calendar cutoff clamps leap day and respects the Berlin clock', function (): void {
		$f = historyFixture(); $f['clock']->now = new DateTimeImmutable('2028-02-29 12:15:00', new DateTimeZone('Europe/Berlin'));
		sameHistory((new DateTimeImmutable('@' . $f['reader']->retentionCutoff()))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'), '2027-02-28 12:15');
	});
	checkHistory('reader defaults to 30 days, keeps inactive/freelance choices and masks foreign actors', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $page = $f['reader']->list([]); sameHistory(count($page['items']), 1); sameHistory($page['retentionMonths'], 12);
		sameHistory(array_column($page['filters']['employees'], 'id'), ['employee', 'inactive', 'freelancer']); sameHistory(array_column($page['filters']['actors'], 'id'), ['hr-a']);
		failsHistory(fn() => $f['reader']->list(['employee' => 'foreign']), \OC\ForbiddenException::class); failsHistory(fn() => $f['reader']->list(['actor' => 'admin']), InvalidArgumentException::class);
	});
	checkHistory('filters and pagination retain stable descending IDs without overlap', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); for ($i = 1; $i < 55; $i++) $f['entries']->update($entry->getId(), ['comment' => 'Revision ' . $i]);
		$first = $f['reader']->list([]); $second = $f['reader']->list(['beforeId' => $first['nextCursor']]); sameHistory(count($first['items']), 50); sameHistory(count($second['items']), 5); sameHistory($second['nextCursor'], null);
		sameHistory(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')), []); sameHistory(count($f['reader']->list(['kind' => 'created'])['items']), 1);
	});
	checkHistory('invalid dates, kinds and pagination are rejected', function (): void {
		$f = historyFixture(); foreach ([['from' => '2026-02-30'], ['from' => '2026-11-04', 'to' => '2026-11-03'], ['kind' => 'unknown'], ['beforeId' => '-1'], ['limit' => 51], ['actorDeleted' => 'true'], ['actorDeleted' => 1, 'actor' => 'hr-a']] as $query) failsHistory(fn() => $f['reader']->list($query), InvalidArgumentException::class);
	});
	checkHistory('employee and admin without HR responsibility cannot read history', function (): void {
		$f = historyFixture(); foreach (['employee', 'admin'] as $id) { $f['session']->user = $f['users']->get($id); failsHistory(fn() => $f['reader']->list([]), \OC\ForbiddenException::class); }
		$f['session']->user = $f['users']->get('hr-admin'); sameHistory($f['reader']->list([])['items'], []);
	});
	checkHistory('deleted editor is anonymized; deleted subject loses its history', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $f['writes']->forgetAccount('hr-a'); sameHistory($f['db']->records[0]['actor'], ['id' => null, 'name' => '']); sameHistory(count($f['reader']->list(['actorDeleted' => 1])['items']), 1);
		$f['writes']->forgetAccount('employee'); sameHistory($f['db']->records, []); sameHistory($f['locks']->held, []);
	});
	checkHistory('missing or disabled actors cannot append records after account deletion', function () use ($entryData): void {
		$f = historyFixture(); unset($f['users']->users['hr-a']); failsHistory(fn() => $f['entries']->create($entryData, 'employee'), \OC\ForbiddenException::class);
		$f = historyFixture(); $f['users']->users['hr-a']->enabled = false; failsHistory(fn() => $f['entries']->create($entryData, 'employee'), \OC\ForbiddenException::class);
	});
	checkHistory('creation is rolled back when its history record cannot be stored', function () use ($entryData): void {
		$f = historyFixture(); $f['history']->fail = true; failsHistory(fn() => $f['entries']->create($entryData, 'employee'), RuntimeException::class);
		sameHistory($f['db']->entries, []); sameHistory($f['db']->records, []); sameHistory($f['db']->dirty, []); sameHistory($f['locks']->held, []);
	});
	checkHistory('a comment-only POST preserves existing times while auditing the changed comment', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $updated = $f['entries']->upsertCommentOnly('employee', $entryData['workDate'], 'Changed');
		sameHistory($updated->getStartMin(), 0); sameHistory($updated->getEndMin(), 480); sameHistory($updated->getBreakMinutes(), -10); sameHistory(count($f['db']->records[1]['changes']), 1);
	});
	checkHistory('work-date conflicts keep entries, audit records and dirty flags unchanged', function () use ($entryData): void {
		$f = historyFixture(); $first = $f['entries']->create($entryData, 'employee'); $f['entries']->create([...$entryData, 'workDate' => '2026-11-01'], 'employee');
		$before = serialize([$f['db']->entries, $f['db']->records, $f['db']->dirty]); failsHistory(fn() => $f['entries']->update($first->getId(), ['workDate' => '2026-11-01']), DomainException::class);
		sameHistory(serialize([$f['db']->entries, $f['db']->records, $f['db']->dirty]), $before);
	});
	checkHistory('work-date changes retain old and new dates and dirty the earlier month', function () use ($entryData): void {
		$f = historyFixture(); $first = $f['entries']->create($entryData, 'employee'); $f['entries']->update($first->getId(), ['workDate' => '2026-10-31']);
		sameHistory($f['db']->records[1]['workDate'], '2026-10-31'); sameHistory($f['db']->records[1]['changes'], [['field' => 'workDate', 'before' => '2026-11-02', 'after' => '2026-10-31']]); sameHistory($f['db']->dirty[1], ['employee', '2026-10-31']);
	});
	checkHistory('retention applies before cleanup and includes its exact boundary', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['session']->user = $f['users']->get('hr-b'); $f['entries']->update($entry->getId(), ['comment' => 'Next']);
		$f['db']->records[0]['occurredAt'] = $f['reader']->retentionCutoff() - 1; $f['db']->records[1]['occurredAt'] = $f['reader']->retentionCutoff();
		$page = $f['reader']->list(['from' => '2020-01-01', 'to' => '2030-01-01']); sameHistory(array_column($page['items'], 'id'), [2]); sameHistory(array_column($page['filters']['actors'], 'id'), ['hr-b']);
	});
	checkHistory('failure to acquire a later lock releases only the locks acquired by the operation', function () use ($entryData): void {
		$f = historyFixture(); $blockedKey = hash('sha256', 'timesheet:employee-write:hr-a'); $f['locks']->held[$blockedKey] = true;
		failsHistory(fn() => $f['entries']->create($entryData, 'employee'), RuntimeException::class); sameHistory($f['locks']->held, [$blockedKey => true]); sameHistory($f['db']->entries, []); sameHistory($f['db']->records, []);
	});
	checkHistory('a failed positive account reset keeps its retry marker and blocks affected reads and writes', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['history']->failRemoval = true;
		failsHistory(fn() => $f['writes']->forgetAccount('employee'), RuntimeException::class); sameHistory($f['queue']->contains('employee'), true);
		failsHistory(fn() => $f['entries']->update($entry->getId(), ['comment' => 'Blocked']), RuntimeException::class); failsHistory(fn() => $f['reader']->list([]), RuntimeException::class);
		sameHistory(count($f['db']->records), 1); sameHistory($f['db']->entries[$entry->getId()]->getComment(), 'Original');
	});
	checkHistory('a retry processes only positive evidence and clears the marker with the history', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $f['writes']->retryAccountCleanup('employee'); sameHistory(count($f['db']->records), 1);
		$f['queue']->mark('employee'); $f['writes']->retryAccountCleanup('employee'); sameHistory($f['db']->records, []); sameHistory($f['queue']->pendingAccountIds(), []);
	});
	checkHistory('reusing an editor UID drains the old marker and a late retry leaves new history intact', function () use ($entryData): void {
		$f = historyFixture(); $entry = $f['entries']->create($entryData, 'employee'); $f['queue']->mark('hr-a'); $staleJobIds = $f['writes']->pendingAccountCleanups();
		$listener = new \OCA\Timesheet\Listener\ChangeHistoryUserCreatedListener($f['writes']); $listener->handle(new \OCP\User\Events\UserCreatedEvent($f['users']->get('hr-a')));
		sameHistory($f['db']->records[0]['actor'], ['id' => null, 'name' => '']); sameHistory($f['queue']->pendingAccountIds(), []);
		$f['entries']->update($entry->getId(), ['comment' => 'New account generation']); foreach ($staleJobIds as $id) $f['writes']->retryAccountCleanup($id);
		sameHistory(count($f['db']->records), 2); sameHistory($f['db']->records[1]['actor']['id'], 'hr-a');
	});
	checkHistory('a recreated employee UID cannot inherit the previous employee history', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $listener = new \OCA\Timesheet\Listener\ChangeHistoryUserCreatedListener($f['writes']);
		$listener->handle(new \OCP\User\Events\UserCreatedEvent($f['users']->get('employee'))); sameHistory($f['db']->records, []); sameHistory($f['queue']->pendingAccountIds(), []);
	});
	checkHistory('creation cleanup errors propagate and keep the reused identity quarantined', function () use ($entryData): void {
		$f = historyFixture(); $f['entries']->create($entryData, 'employee'); $f['history']->failRemoval = true; $listener = new \OCA\Timesheet\Listener\ChangeHistoryUserCreatedListener($f['writes']);
		failsHistory(fn() => $listener->handle(new \OCP\User\Events\UserCreatedEvent($f['users']->get('hr-a'))), RuntimeException::class);
		sameHistory($f['queue']->contains('hr-a'), true); failsHistory(fn() => $f['reader']->list([]), RuntimeException::class); failsHistory(fn() => $f['entries']->create([...$entryData, 'workDate' => '2026-11-01'], 'employee'), RuntimeException::class);
	});
	checkHistory('fresh installation initializes recording once and later startups keep its original value', function (): void {
		$db = new RecordingMemoryDatabase(); $config = new RecordingConfig($db); $locks = new HistoryLocks(); $clock = new HistoryClock(new DateTimeImmutable('2026-11-03 10:30:00Z'));
		$recording = new \OCA\Timesheet\Db\ChangeHistoryRecordingStore($db, $config, $clock, $locks); $recording->initialize(); $first = $recording->getStartedAt();
		sameHistory($first, $clock->getTime()); $clock->now = $clock->now->modify('+1 day'); $recording->initialize(); sameHistory($recording->getStartedAt(), $first); sameHistory($config->writes, 1); sameHistory(count($locks->trace), 1);
	});
	checkHistory('old versions and a missing schema never create an early recording marker', function (): void {
		foreach (['old-version', 'missing-table'] as $case) {
			$db = new RecordingMemoryDatabase(); $config = new RecordingConfig($db); $locks = new HistoryLocks(); $clock = new HistoryClock(new DateTimeImmutable('2026-11-03Z'));
			if ($case === 'old-version') $config->version = '1.3.1'; else $db->tablePresent = false;
			$recording = new \OCA\Timesheet\Db\ChangeHistoryRecordingStore($db, $config, $clock, $locks); $recording->initialize(); sameHistory($config->writes, 0); sameHistory($locks->trace, []);
		}
	});
	checkHistory('an existing upgrade marker is reused without a startup lock or write', function (): void {
		$db = new RecordingMemoryDatabase(); $db->values['change_history_started_at'] = '1793600000'; $config = new RecordingConfig($db); $locks = new HistoryLocks(); $clock = new HistoryClock(new DateTimeImmutable('2026-11-03Z'));
		$recording = new \OCA\Timesheet\Db\ChangeHistoryRecordingStore($db, $config, $clock, $locks); $recording->initialize(); sameHistory($recording->getStartedAt(), 1793600000); sameHistory($config->writes, 0); sameHistory($locks->trace, []);
	});
	echo 'HR change history backend: ' . $checks . ' checks, ' . $failures . " failures.\n";
	exit($failures === 0 ? 0 : 1);
}
