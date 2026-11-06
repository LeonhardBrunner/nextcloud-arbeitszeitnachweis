<?php

declare(strict_types=1);

// Run real update-note classes against isolated account, transport and Nextcloud doubles.
namespace OCP {
	interface IConfig {
		public function getUserValueForUsers($app, $key, $users);
		public function setUserValue($user, $app, $key, $value, $preCondition = null);
	}
	interface IUserManager { public function get(string $userId); }
	interface IUserSession { public function getUser(); }
	interface IGroupManager { public function isAdmin(string $userId): bool; }
	interface IRequest { }
	interface IL10N { public function t(string $message, array $parameters = []): string; }
	interface IURLGenerator {
		public function getAbsoluteURL(string $url): string;
		public function linkToRoute(string $route): string;
		public function imagePath(string $app, string $file): string;
	}
	class PreConditionNotMetException extends \RuntimeException { }
}
namespace OCP\App { interface IAppManager { public function isEnabledForUser($app, $user = null); } }
namespace OCP\Lock {
	class LockedException extends \RuntimeException { }
	interface ILockingProvider {
		public const LOCK_EXCLUSIVE = 2;
		public function acquireLock(string $key, int $type): void;
		public function releaseLock(string $key, int $type): void;
	}
}
namespace OCP\AppFramework\Services { interface IAppConfig { public function getAppValueString(string $key, string $default = ''): string; } }
namespace OCP\AppFramework\Utility { interface ITimeFactory { public function getDateTime(): \DateTime; } }
namespace OCP\AppFramework {
	class Controller { public function __construct(string $appName, \OCP\IRequest $request) { } }
	class Http {
		public const STATUS_OK = 200;
		public const STATUS_BAD_REQUEST = 400;
		public const STATUS_FORBIDDEN = 403;
		public const STATUS_INTERNAL_SERVER_ERROR = 500;
	}
}
namespace OCP\AppFramework\Http {
	class DataResponse {
		public function __construct(private array $data, private int $status) { }
		public function getData(): array { return $this->data; }
		public function getStatus(): int { return $this->status; }
	}
}
namespace OCP\AppFramework\Http\Attribute {
	#[\Attribute(\Attribute::TARGET_METHOD)] class NoAdminRequired { }
	#[\Attribute(\Attribute::TARGET_METHOD)] class NoCSRFRequired { }
}
namespace OCP\Notification {
	interface INotification { }
	interface IManager {
		public function createNotification(): INotification;
		public function getCount(INotification $notification): int;
		public function notify(INotification $notification): void;
		public function markProcessed(INotification $notification): void;
		public function defer(): bool;
		public function flush(): void;
	}
	interface IDismissableNotifier {
		public function getID(): string;
		public function getName(): string;
		public function prepare(INotification $notification, string $languageCode): INotification;
		public function dismissNotification(INotification $notification): void;
	}
	class UnknownNotificationException extends \RuntimeException { }
	class AlreadyProcessedException extends \RuntimeException { }
	class IncompleteParsedNotificationException extends \RuntimeException { }
}
namespace OCP\L10N { interface IFactory { public function get(string $app, ?string $language = null); } }
namespace Psr\Log { interface LoggerInterface { public function warning(string $message, array $context = []): void; } }
namespace OCP\EventDispatcher {
	class Event { }
	interface IEventListener { public function handle(Event $event): void; }
}
namespace OCP\User\Events {
	class UserCreatedEvent extends \OCP\EventDispatcher\Event {
		public function __construct(private object $user) { }
		public function getUser(): object { return $this->user; }
	}
}

namespace {
	use OCA\Timesheet\Controller\UpdateNotesController;
	use OCA\Timesheet\Db\UpdateNotesStore;
	use OCA\Timesheet\Notification\UpdateNotesNotifier;
	use OCA\Timesheet\Listener\UpdateNotesUserCreatedListener;
	use OCA\Timesheet\Service\UpdateNotesCatalog;
	use OCA\Timesheet\Service\UpdateNotesEligibility;
	use OCA\Timesheet\Service\UpdateNotesService;
	use OCP\Notification\INotification;

	foreach (['Service/UpdateNotesCatalog', 'Db/UpdateNotesStore', 'Service/UpdateNotesEligibility', 'Service/UpdateNotesService', 'Notification/UpdateNotesNotifier', 'Controller/UpdateNotesController', 'Listener/UpdateNotesUserCreatedListener'] as $class) {
		require __DIR__ . '/../../lib/' . $class . '.php';
	}
	class NotesUser {
		public function __construct(private string $id, public bool $enabled = true) { }
		public function getUID(): string { return $this->id; }
		public function isEnabled(): bool { return $this->enabled; }
	}
	class NotesUsers implements \OCP\IUserManager, \OCP\IUserSession {
		public ?string $current = 'employee';
		public array $records = [];
		public function get(string $userId): ?NotesUser { return $this->records[$userId] ?? null; }
		public function getUser(): ?NotesUser { return $this->current === null ? null : $this->get($this->current); }
	}
	class NotesGroups implements \OCP\IGroupManager {
		public array $admins = ['admin', 'hr-admin'];
		public function isAdmin(string $userId): bool { return in_array($userId, $this->admins, true); }
	}
	class NotesAppManager implements \OCP\App\IAppManager {
		public array $blocked = [];
		public function isEnabledForUser($app, $user = null): bool { return $app === 'timesheet' && !in_array($user->getUID(), $this->blocked, true); }
	}
	class NotesLocks implements \OCP\Lock\ILockingProvider {
		public array $held = [];
		public int $busyAttempts = 0;
		public bool $permanentlyBusy = false;
		public int $attempts = 0;
		public function acquireLock(string $key, int $type): void {
			$this->attempts++;
			if ($this->permanentlyBusy || $this->busyAttempts-- > 0) throw new \OCP\Lock\LockedException('Synthetic contention.');
			if (strlen($key) !== 64 || isset($this->held[$key])) throw new \RuntimeException('Nested account lock.');
			$this->held[$key] = true;
		}
		public function releaseLock(string $key, int $type): void { unset($this->held[$key]); }
	}
	class NotesConfig implements \OCP\IConfig {
		public array $values = [];
		public array $writes = [];
		public array $newAccounts = [];
		public array $markerWrites = [];
		public bool $failRead = false;
		public bool $failWrite = false;
		public function getUserValueForUsers($app, $key, $users) {
			if ($this->failRead) throw new \RuntimeException('Synthetic read failure.');
			if ($app === 'timesheet' && $key === 'update_notes_new_account') return array_intersect_key($this->newAccounts, array_flip($users));
			if ($app !== 'timesheet' || $key !== 'update_notes_state') throw new \RuntimeException('Unrelated preference accessed.');
			return array_intersect_key($this->values, array_flip($users));
		}
		public function setUserValue($user, $app, $key, $value, $preCondition = null) {
			if ($this->failWrite) throw new \RuntimeException('Synthetic write failure.');
			if ($app === 'timesheet' && $key === 'update_notes_new_account') {
				$this->newAccounts[$user] = $value;
				$this->markerWrites[] = [$user, $value];
				return;
			}
			if (($this->values[$user] ?? '') !== $preCondition) throw new \OCP\PreConditionNotMetException();
			$this->values[$user] = $value;
			$this->writes[] = $user;
		}
	}
	class NotesAppConfig implements \OCP\AppFramework\Services\IAppConfig {
		public string $version = '1.3.0';
		public function getAppValueString(string $key, string $default = ''): string { return $key === 'installed_version' ? $this->version : $default; }
	}
	class NotesTranslator implements \OCP\IL10N {
		public function __construct(private string $language = 'en') { }
		public function t(string $message, array $parameters = []): string {
			$text = $this->language === 'en' ? $message : $this->language . ':' . $message;
			// PHP translations do not interpolate JavaScript-style named braces.
			return $text;
		}
	}
	class NotesTranslationFactory implements \OCP\L10N\IFactory {
		public function get(string $app, ?string $language = null): NotesTranslator { return new NotesTranslator($language ?? 'en'); }
	}
	class NotesUrl implements \OCP\IURLGenerator {
		public function getAbsoluteURL(string $url): string { return 'https://example.test' . $url; }
		public function linkToRoute(string $route): string { return '/apps/timesheet/'; }
		public function imagePath(string $app, string $file): string { return '/custom_apps/' . $app . '/img/' . $file; }
	}
	class NotesClock implements \OCP\AppFramework\Utility\ITimeFactory {
		public function getDateTime(): \DateTime { return new \DateTime('2026-11-03T12:00:00+00:00'); }
	}
	class NotesLogger implements \Psr\Log\LoggerInterface {
		public array $warnings = [];
		public function warning(string $message, array $context = []): void { $this->warnings[] = $message; }
	}
	class NotesRequest implements \OCP\IRequest { }
	class NotesNotification implements INotification {
		private string $app = '';
		private string $user = '';
		private string $objectType = '';
		private string $objectId = '';
		private string $subject = '';
		public string $parsedSubject = '';
		public string $parsedMessage = '';
		public string $link = '';
		public string $icon = '';
		public function setApp(string $value): self { $this->app = $value; return $this; }
		public function getApp(): string { return $this->app; }
		public function setUser(string $value): self { $this->user = $value; return $this; }
		public function getUser(): string { return $this->user; }
		public function setObject(string $type, string $id): self { $this->objectType = $type; $this->objectId = $id; return $this; }
		public function getObjectType(): string { return $this->objectType; }
		public function getObjectId(): string { return $this->objectId; }
		public function setSubject(string $value, array $parameters = []): self { $this->subject = $value; return $this; }
		public function getSubject(): string { return $this->subject; }
		public function setDateTime(\DateTime $time): self { return $this; }
		public function setLink(string $value): self { $this->link = $value; return $this; }
		public function setIcon(string $value): self { $this->icon = $value; return $this; }
		public function setParsedSubject(string $value): self { $this->parsedSubject = $value; return $this; }
		public function setParsedMessage(string $value): self { $this->parsedMessage = $value; return $this; }
	}
	class NotesManager implements \OCP\Notification\IManager {
		public array $records = [];
		public ?UpdateNotesNotifier $notifier = null;
		public int $sends = 0;
		public bool $failSend = false;
		public bool $failDeferral = false;
		public bool $failFlush = false;
		public int $flushes = 0;
		private bool $deferred = false;
		private array $queued = [];
		public function __construct(private NotesLocks $locks) { }
		public function createNotification(): INotification { return new NotesNotification(); }
		private function key(INotification $notification): string { return $notification->getUser() . '/' . $notification->getObjectId(); }
		public function getCount(INotification $notification): int { return isset($this->records[$this->key($notification)]) ? 1 : 0; }
		public function notify(INotification $notification): void {
			if ($this->failSend) throw new \RuntimeException('Synthetic notification failure.');
			$this->records[$this->key($notification)] = clone $notification;
			$this->sends++;
			// Native insertion is immediate, while device preparation can be deferred.
			if ($this->deferred) $this->queued[] = clone $notification;
			else $this->notifier?->prepare(clone $notification, 'en');
		}
		public function markProcessed(INotification $filter): void {
			$key = $this->key($filter);
			if (isset($this->records[$key])) {
				$this->notifier?->dismissNotification($this->records[$key]);
				unset($this->records[$key]);
			}
		}
		public function userDismiss(string $userId, string $version): void {
			$key = $userId . '/' . $version;
			$this->notifier->dismissNotification($this->records[$key]);
			unset($this->records[$key]);
		}
		public function clearAll(string $userId): void {
			foreach ($this->records as $note) if ($note->getUser() === $userId) $this->userDismiss($userId, $note->getObjectId());
		}
		public function defer(): bool {
			if ($this->failDeferral) throw new \RuntimeException('Synthetic deferral failure.');
			if ($this->deferred) return false;
			$this->deferred = true;
			return true;
		}
		public function flush(): void {
			if ($this->locks->held !== []) throw new \RuntimeException('Device delivery ran inside the account lock.');
			$this->deferred = false;
			$this->flushes++;
			if ($this->failFlush) throw new \RuntimeException('Synthetic flush failure.');
			$notifications = $this->queued;
			$this->queued = [];
			foreach ($notifications as $notification) $this->notifier?->prepare($notification, 'en');
		}
	}
	function notesFixture(): array {
		$config = new NotesConfig(); $locks = new NotesLocks(); $users = new NotesUsers(); $groups = new NotesGroups(); $apps = new NotesAppManager();
		foreach (['employee', 'other', 'hr', 'admin', 'hr-admin', 'disabled', 'blocked'] as $id) $users->records[$id] = new NotesUser($id, $id !== 'disabled');
		$apps->blocked[] = 'blocked';
		$appConfig = new NotesAppConfig(); $manager = new NotesManager($locks); $translator = new NotesTranslator(); $logger = new NotesLogger();
		$catalog = new UpdateNotesCatalog(__DIR__ . '/fixtures/release-notes.json');
		$store = new UpdateNotesStore($config, $locks); $eligibility = new UpdateNotesEligibility($users, $apps);
		$service = new UpdateNotesService($catalog, $store, $eligibility, $appConfig, $manager, new NotesUrl(), new NotesClock(), $logger);
		$notifier = new UpdateNotesNotifier($service, new NotesTranslationFactory(), $logger); $manager->notifier = $notifier;
		$controller = new UpdateNotesController('timesheet', new NotesRequest(), $users, $service, $translator, $logger);
		return compact('config', 'locks', 'users', 'groups', 'apps', 'appConfig', 'manager', 'translator', 'logger', 'catalog', 'store', 'service', 'notifier', 'controller');
	}
	$checks = 0; $failures = 0;
	function checkNotes(string $description, callable $callback): void {
		global $checks, $failures; $checks++;
		try { $callback(); } catch (\Throwable $error) { $failures++; fwrite(STDERR, $description . ': ' . $error->getMessage() . "\n"); }
	}
	function sameNotes(mixed $expected, mixed $actual): void { if ($expected !== $actual) throw new \RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual])); }
	function throwsNotes(string $class, callable $callback): void {
		try { $callback(); } catch (\Throwable $error) { if ($error instanceof $class) return; throw $error; }
		throw new \RuntimeException('Expected ' . $class);
	}
	function versionsNotes(array $result): array { return array_column($result['releases'], 'version'); }

	checkNotes('First app visit creates one note and offers the current release', function (): void {
		$f = notesFixture(); $result = $f['controller']->load()->getData();
		sameNotes(['1.3.0'], versionsNotes($result)); sameNotes(true, $result['shouldShow']); sameNotes(1, $f['manager']->sends);
		$f['controller']->load(); sameNotes(1, $f['manager']->sends); sameNotes([], $f['locks']->held);
	});
	checkNotes('Brief contention from a second tab retries and leaves only one notification', function (): void {
		$f = notesFixture(); $f['locks']->busyAttempts = 2;
		sameNotes(200, $f['controller']->load()->getStatus()); sameNotes(3, $f['locks']->attempts);
		sameNotes(1, $f['manager']->sends); sameNotes([], $f['locks']->held);
	});
	checkNotes('Permanent contention returns a bounded storage error without touching progress', function (): void {
		$f = notesFixture(); $f['locks']->permanentlyBusy = true; $start = microtime(true);
		sameNotes(500, $f['controller']->load()->getStatus());
		$elapsed = microtime(true) - $start;
		sameNotes(true, $elapsed >= 1.0 && $elapsed < 2.0); sameNotes([], $f['config']->writes); sameNotes(0, $f['manager']->sends);
	});
	checkNotes('Deferred device delivery runs only after the account lock is released', function (): void {
		$f = notesFixture(); sameNotes(200, $f['controller']->load()->getStatus()); sameNotes(1, $f['manager']->flushes); sameNotes([], $f['logger']->warnings);
	});
	foreach (['deferral', 'flush'] as $failure) checkNotes('A native ' . $failure . ' failure leaves popup data usable', function () use ($failure): void {
		$f = notesFixture();
		if ($failure === 'deferral') $f['manager']->failDeferral = true;
		else $f['manager']->failFlush = true;
		sameNotes(200, $f['controller']->load()->getStatus()); sameNotes([], $f['locks']->held);
		sameNotes(null, $f['store']->read('employee')['shownVersion']);
	});
	checkNotes('Shown acknowledgement suppresses repeat popups while preserving the bell', function (): void {
		$f = notesFixture(); $f['controller']->load(); sameNotes(200, $f['controller']->shown('1.3.0')->getStatus());
		sameNotes(false, $f['controller']->load()->getData()['shouldShow']); sameNotes(1, count($f['manager']->records));
		sameNotes(['1.3.0'], versionsNotes($f['controller']->load('manual')->getData()));
	});
	checkNotes('New account on a later version sees only that current version', function (): void {
		$f = notesFixture(); $f['store']->markNewAccount('employee'); $f['appConfig']->version = '1.5.0';
		sameNotes(['1.5.0'], versionsNotes($f['controller']->load()->getData()));
	});
	checkNotes('A returning legacy account receives releases missed before its first feature visit', function (): void {
		$f = notesFixture(); $f['appConfig']->version = '1.5.0';
		sameNotes(['1.3.0', '1.4.0', '1.5.0'], versionsNotes($f['controller']->load()->getData()));
		sameNotes('1.3.0', $f['store']->read('employee')['floorVersion']); sameNotes([], $f['config']->markerWrites);
	});
	checkNotes('The new-account marker never rewrites an already initialized release baseline', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['store']->markNewAccount('employee'); $f['appConfig']->version = '1.5.0';
		sameNotes(['1.3.0', '1.4.0', '1.5.0'], versionsNotes($f['controller']->load()->getData()));
	});
	checkNotes('The creation listener marks only its new account and sends no notifications', function (): void {
		$f = notesFixture(); $listener = new UpdateNotesUserCreatedListener($f['store'], $f['logger']);
		$listener->handle(new \OCP\EventDispatcher\Event()); sameNotes([], $f['config']->markerWrites);
		$listener->handle(new \OCP\User\Events\UserCreatedEvent(new NotesUser('fresh')));
		sameNotes([['fresh', '1']], $f['config']->markerWrites); sameNotes(['fresh' => '1'], $f['config']->newAccounts);
		sameNotes([], $f['config']->values); sameNotes(0, $f['manager']->sends);
	});
	checkNotes('A marker write failure is logged without interrupting account creation', function (): void {
		$f = notesFixture(); $f['config']->failWrite = true; $listener = new UpdateNotesUserCreatedListener($f['store'], $f['logger']);
		$listener->handle(new \OCP\User\Events\UserCreatedEvent(new NotesUser('fresh')));
		sameNotes([], $f['config']->markerWrites); sameNotes(1, count($f['logger']->warnings)); sameNotes(0, $f['manager']->sends);
	});
	checkNotes('A newly created administrator receives only the currently installed release', function (): void {
		$f = notesFixture(); $listener = new UpdateNotesUserCreatedListener($f['store'], $f['logger']);
		$listener->handle(new \OCP\User\Events\UserCreatedEvent($f['users']->get('admin'))); $f['users']->current = 'admin';
		$f['appConfig']->version = '1.5.0';
		$result = $f['controller']->load();
		sameNotes(200, $result->getStatus()); sameNotes(['1.5.0'], versionsNotes($result->getData()));
		sameNotes('1.5.0', $f['store']->read('admin')['floorVersion']); sameNotes(1, $f['manager']->sends);
		sameNotes(['admin/1.5.0'], array_keys($f['manager']->records));
	});
	checkNotes('Skipped releases accumulate but technical replacement never acknowledges them', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['appConfig']->version = '1.5.0';
		sameNotes(['1.3.0', '1.4.0', '1.5.0'], versionsNotes($f['controller']->load()->getData()));
		sameNotes(null, $f['store']->read('employee')['dismissedVersion']); sameNotes(['employee/1.5.0'], array_keys($f['manager']->records));
	});
	checkNotes('Technical cleanup is shared across independently resolved native notifier services', function (): void {
		$f = notesFixture(); $f['controller']->load();
		$f['manager']->notifier = new UpdateNotesNotifier(clone $f['service'], new NotesTranslationFactory(), $f['logger']);
		$f['appConfig']->version = '1.4.0'; $f['controller']->load();
		sameNotes(['employee/1.4.0'], array_keys($f['manager']->records)); sameNotes(null, $f['store']->read('employee')['dismissedVersion']);
	});
	checkNotes('Automatic notes exclude previously shown versions but bell retains them', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['controller']->shown('1.3.0'); $f['appConfig']->version = '1.5.0';
		sameNotes(['1.4.0', '1.5.0'], versionsNotes($f['controller']->load()->getData()));
		sameNotes(['1.3.0', '1.4.0', '1.5.0'], versionsNotes($f['controller']->load('manual')->getData()));
	});
	foreach (['individual', 'clearAll'] as $action) checkNotes('Native ' . $action . ' dismissal persists without muting future releases', function () use ($action): void {
		$f = notesFixture(); $f['controller']->load();
		$action === 'individual' ? $f['manager']->userDismiss('employee', '1.3.0') : $f['manager']->clearAll('employee');
		sameNotes(false, $f['controller']->load()->getData()['shouldShow']); sameNotes([], $f['manager']->records);
		$f['appConfig']->version = '1.4.0'; sameNotes(['1.4.0'], versionsNotes($f['controller']->load()->getData()));
	});
	checkNotes('Manual reopening after dismissal never recreates the removed bell notice', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['manager']->userDismiss('employee', '1.3.0');
		sameNotes(['1.3.0'], versionsNotes($f['controller']->load('manual')->getData())); sameNotes([], $f['manager']->records);
	});
	checkNotes('A stale native X acknowledges only its own older version', function (): void {
		$f = notesFixture(); $f['controller']->load(); $old = clone $f['manager']->records['employee/1.3.0'];
		$f['appConfig']->version = '1.5.0'; $f['controller']->load(); $f['notifier']->dismissNotification($old);
		sameNotes('1.3.0', $f['store']->read('employee')['dismissedVersion']); sameNotes(['employee/1.5.0'], array_keys($f['manager']->records));
		sameNotes(['1.4.0', '1.5.0'], versionsNotes($f['controller']->load()->getData()));
	});
	checkNotes('A stale displayed acknowledgement cannot move progress backwards', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['appConfig']->version = '1.5.0'; $f['controller']->load();
		$f['controller']->shown('1.5.0'); sameNotes('1.5.0', $f['controller']->shown('1.3.0')->getData()['throughVersion']);
	});
	checkNotes('Account states and bell records stay separate', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['controller']->shown('1.3.0'); $f['users']->current = 'other';
		sameNotes(true, $f['controller']->load()->getData()['shouldShow']); sameNotes(2, count($f['manager']->records));
	});
	foreach ([null, 'disabled', 'blocked', 'unknown'] as $actor) checkNotes('Ineligible account has no update access or preference side effects: ' . ($actor ?? 'anonymous'), function () use ($actor): void {
		$f = notesFixture(); $f['users']->current = $actor;
		sameNotes(403, $f['controller']->load()->getStatus()); sameNotes(403, $f['controller']->shown('1.3.0')->getStatus());
		sameNotes([], $f['config']->writes); sameNotes(0, $f['manager']->sends);
	});
	checkNotes('HR without administrator rights receives the same update capability', function (): void {
		$f = notesFixture(); $f['users']->current = 'hr'; sameNotes(200, $f['controller']->load()->getStatus());
	});
	foreach (['admin', 'hr-admin'] as $actor) checkNotes('Administrator update progress and native dismissal work independently: ' . $actor, function () use ($actor): void {
		$f = notesFixture(); $f['users']->current = $actor;
		$result = $f['controller']->load(); sameNotes(200, $result->getStatus()); sameNotes(true, $result->getData()['shouldShow']);
		sameNotes(['1.3.0'], versionsNotes($result->getData())); sameNotes([$actor . '/1.3.0'], array_keys($f['manager']->records));
		$f['controller']->load(); sameNotes(1, $f['manager']->sends);
		sameNotes(200, $f['controller']->shown('1.3.0')->getStatus()); sameNotes(false, $f['controller']->load()->getData()['shouldShow']);
		sameNotes(['1.3.0'], versionsNotes($f['controller']->load('manual')->getData())); sameNotes(1, count($f['manager']->records));
		$note = $f['notifier']->prepare(clone $f['manager']->records[$actor . '/1.3.0'], 'de');
		sameNotes('de:Timesheet updated to 1.3.0', $note->parsedSubject);
		$f['manager']->clearAll($actor); sameNotes([], $f['manager']->records);
		sameNotes('1.3.0', $f['store']->read($actor)['dismissedVersion']);
		$f['appConfig']->version = '1.4.0'; sameNotes(['1.4.0'], versionsNotes($f['controller']->load()->getData()));
		sameNotes([$actor . '/1.4.0'], array_keys($f['manager']->records));
		sameNotes(null, $f['store']->read('employee'));
	});
	checkNotes('An existing administrator without prior progress receives missed catalog releases', function (): void {
		$f = notesFixture(); $f['users']->current = 'admin'; $f['appConfig']->version = '1.5.0';
		sameNotes(['1.3.0', '1.4.0', '1.5.0'], versionsNotes($f['controller']->load()->getData()));
		sameNotes('1.3.0', $f['store']->read('admin')['floorVersion']); sameNotes([], $f['config']->markerWrites);
	});
	foreach (['admin', 'hr-admin'] as $actor) foreach (['disabled', 'blocked'] as $restriction) checkNotes('Administrator privileges do not bypass ' . $restriction . ' accounts: ' . $actor, function () use ($actor, $restriction): void {
		$f = notesFixture(); $f['users']->current = $actor;
		if ($restriction === 'disabled') $f['users']->records[$actor]->enabled = false;
		else $f['apps']->blocked[] = $actor;
		sameNotes(403, $f['controller']->load()->getStatus()); sameNotes(403, $f['controller']->shown('1.3.0')->getStatus());
		sameNotes([], $f['config']->writes); sameNotes(0, $f['manager']->sends);
	});
	checkNotes('Administrator promotion preserves an existing native notification and update progress', function (): void {
		$f = notesFixture(); $f['controller']->load(); $note = clone $f['manager']->records['employee/1.3.0']; $state = $f['config']->values;
		$f['groups']->admins[] = 'employee'; $prepared = $f['notifier']->prepare($note, 'de');
		sameNotes('de:Timesheet updated to 1.3.0', $prepared->parsedSubject); sameNotes($state, $f['config']->values);
		sameNotes(['employee/1.3.0'], array_keys($f['manager']->records)); sameNotes(200, $f['controller']->load()->getStatus());
		sameNotes(null, $f['store']->read('employee')['dismissedVersion']);
		$f['manager']->userDismiss('employee', '1.3.0'); sameNotes('1.3.0', $f['store']->read('employee')['dismissedVersion']);
	});
	checkNotes('The native bell stays concise while the linked popup keeps all pending releases', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['appConfig']->version = '1.4.0'; $f['controller']->load();
		$popup = $f['controller']->load('manual')->getData();
		sameNotes(['1.3.0', '1.4.0'], versionsNotes($popup));
		sameNotes('First release feature.', $popup['releases'][0]['sections'][0]['items'][0]);
		sameNotes('Second release improvement.', $popup['releases'][1]['sections'][0]['items'][0]);
		$note = $f['notifier']->prepare(clone $f['manager']->records['employee/1.4.0'], 'ja');
		sameNotes('ja:Timesheet updated to 1.4.0', $note->parsedSubject);
		sameNotes('ja:Open the update notes.', $note->parsedMessage);
		sameNotes('https://example.test/apps/timesheet/?update-notes=1', $note->link);
		sameNotes('https://example.test/custom_apps/timesheet/img/app_dark.svg', $note->icon);
	});
	checkNotes('The notifier leaves unrelated application notifications untouched', function (): void {
		$f = notesFixture(); $note = new NotesNotification(); $note->setApp('calendar');
		throwsNotes(\OCP\Notification\UnknownNotificationException::class, fn() => $f['notifier']->prepare($note, 'en'));
		throwsNotes(\OCP\Notification\UnknownNotificationException::class, fn() => $f['notifier']->dismissNotification($note));
	});
	checkNotes('Notification delivery failure does not prevent popup data or consume display progress', function (): void {
		$f = notesFixture(); $f['manager']->failSend = true; $result = $f['controller']->load();
		sameNotes(200, $result->getStatus()); sameNotes(true, $result->getData()['shouldShow']); sameNotes(null, $f['store']->read('employee')['shownVersion']);
		$f['manager']->failSend = false; $f['controller']->load(); sameNotes(1, $f['manager']->sends);
	});
	foreach (['read', 'write', 'malformed'] as $failure) checkNotes('Storage failure never becomes a successful display: ' . $failure, function () use ($failure): void {
		$f = notesFixture(); $f['controller']->load(); $before = $f['config']->values;
		if ($failure === 'read') $f['config']->failRead = true;
		if ($failure === 'write') $f['config']->failWrite = true;
		if ($failure === 'malformed') $f['config']->values['employee'] = '{broken';
		sameNotes(500, $f['controller']->shown('1.3.0')->getStatus());
		if ($failure !== 'malformed') sameNotes($before, $f['config']->values);
		sameNotes([], $f['locks']->held);
	});
	checkNotes('A failed first state write can retry without a duplicate notification', function (): void {
		$f = notesFixture(); $f['config']->failWrite = true; sameNotes(500, $f['controller']->load()->getStatus());
		$f['config']->failWrite = false; sameNotes(200, $f['controller']->load()->getStatus()); sameNotes(1, $f['manager']->sends);
	});
	foreach (['read', 'malformed'] as $failure) checkNotes('A note preparation failure does not break other native bell entries: ' . $failure, function () use ($failure): void {
		$f = notesFixture(); $f['controller']->load(); $note = clone $f['manager']->records['employee/1.3.0'];
		if ($failure === 'read') $f['config']->failRead = true;
		else $f['config']->values['employee'] = '{broken';
		$before = $f['config']->values;
		throwsNotes(\OCP\Notification\IncompleteParsedNotificationException::class, fn() => $f['notifier']->prepare($note, 'en'));
		sameNotes($before, $f['config']->values); sameNotes(1, count($f['manager']->records));
	});
	checkNotes('Unknown, future and unopened versions cannot be acknowledged', function (): void {
		$f = notesFixture(); foreach ([null, 130, 'bad', '1.4.0', '9.9.9', '1.3.0'] as $version) sameNotes(400, $f['controller']->shown($version)->getStatus());
		sameNotes([], $f['config']->writes);
	});
	checkNotes('Unknown mode cannot write account state', function (): void {
		$f = notesFixture(); foreach ([null, [], 'unexpected'] as $mode) sameNotes(400, $f['controller']->load($mode)->getStatus()); sameNotes([], $f['config']->writes);
	});
	checkNotes('No matching release never publishes an empty or future notice', function (): void {
		$f = notesFixture(); $f['appConfig']->version = '1.2.0'; sameNotes([], versionsNotes($f['controller']->load()->getData())); sameNotes(0, $f['manager']->sends);
	});
	checkNotes('A downgrade does not replay releases or erase account history', function (): void {
		$f = notesFixture(); $f['controller']->load(); $f['appConfig']->version = '1.5.0'; $f['controller']->load(); $f['controller']->shown('1.5.0');
		$before = $f['config']->values; $f['appConfig']->version = '1.3.0'; sameNotes(false, $f['controller']->load()->getData()['shouldShow']);
		sameNotes('1.5.0', $f['store']->read('employee')['shownVersion']); sameNotes($before, $f['config']->values);
		sameNotes(['employee/1.5.0'], array_keys($f['manager']->records)); sameNotes(2, $f['manager']->sends);
	});
	checkNotes('Native X can remove a now-unavailable release after rollback without acknowledging it', function (): void {
		$f = notesFixture(); $f['appConfig']->version = '1.5.0'; $f['controller']->load(); $note = clone $f['manager']->records['employee/1.5.0'];
		$before = $f['config']->values; $f['appConfig']->version = '1.3.0';
		throwsNotes(\OCP\Notification\AlreadyProcessedException::class, fn() => $f['notifier']->prepare($note, 'en'));
		$f['manager']->userDismiss('employee', '1.5.0'); sameNotes([], $f['manager']->records); sameNotes($before, $f['config']->values);
	});
	checkNotes('An unavailable native object can be cleaned up without changing current account progress', function (): void {
		$f = notesFixture(); $f['controller']->load(); $note = (new NotesNotification())->setApp('timesheet')->setUser('employee')->setObject('update_notes', '9.9.9')->setSubject('update_notes');
		$before = $f['config']->values;
		throwsNotes(\OCP\Notification\AlreadyProcessedException::class, fn() => $f['notifier']->prepare($note, 'en'));
		$f['notifier']->dismissNotification($note); sameNotes($before, $f['config']->values);
	});
	checkNotes('Write actions retain CSRF protection and the agreed paths', function (): void {
		foreach (['load', 'shown'] as $method) {
			$reflection = new \ReflectionMethod(UpdateNotesController::class, $method);
			sameNotes(1, count($reflection->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class)));
			sameNotes([], $reflection->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class));
		}
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		sameNotes([
			['name' => 'updateNotes#load', 'url' => '/api/update-notes', 'verb' => 'POST'],
			['name' => 'updateNotes#shown', 'url' => '/api/update-notes/shown', 'verb' => 'POST'],
		], array_values(array_filter($routes['routes'], fn(array $route): bool => str_starts_with($route['name'], 'updateNotes#'))));
	});
	echo 'Update notes backend: ' . $checks . ' checks, ' . $failures . " failures.\n";
	exit($failures === 0 ? 0 : 1);
}
