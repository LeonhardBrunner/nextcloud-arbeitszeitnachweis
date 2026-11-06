<?php

declare(strict_types=1);

// Standalone checks replace Nextcloud infrastructure without opening a database or mailbox.
namespace OCP {
	interface IConfig {
		public function getUserValueForUsers($app, $key, $users);
		public function setUserValue($user, $app, $key, $value, $preCondition = null);
	}
	interface IRequest {
		public function getParam(string $key, mixed $default = null): mixed;
	}
	interface IUser {
		public function getUID(): string;
	}
	interface IUserSession {
		public function getUser(): ?IUser;
	}
	interface IGroupManager {
		public function isAdmin(string $userId): bool;
		public function isInGroup(string $userId, string $groupId): bool;
	}
	interface IGroup {
		public function getGID(): string;
	}
	interface IL10N {
		public function getLanguageCode(): string;
	}
	class PreConditionNotMetException extends \RuntimeException {
	}
	class Util {
		public static array $scripts = [];
		public static array $styles = [];
		public static function addTranslations(string $app): void {
		}
		public static function addScript(string $app, string $name): void {
			self::$scripts[] = $name;
		}
		public static function addStyle(string $app, string $name): void {
			self::$styles[] = $name;
		}
	}
}

namespace OCP\EventDispatcher {
	class Event {
	}
	interface IEventListener {
		public function handle(Event $event): void;
	}
}

namespace OCP\Group\Events {
	abstract class MembershipFixtureEvent extends \OCP\EventDispatcher\Event {
		public function __construct(private \OCP\IGroup $group, private \OCP\IUser $user) {
		}
		public function getGroup(): \OCP\IGroup {
			return $this->group;
		}
		public function getUser(): \OCP\IUser {
			return $this->user;
		}
	}
	class UserAddedEvent extends MembershipFixtureEvent {
	}
	class UserRemovedEvent extends MembershipFixtureEvent {
	}
}

namespace OCP\Lock {
	class LockedException extends \RuntimeException {
	}
	interface ILockingProvider {
		public const LOCK_EXCLUSIVE = 2;
		public function acquireLock(string $path, int $type, ?string $readablePath = null): void;
		public function releaseLock(string $path, int $type): void;
	}
}

namespace OCP\AppFramework\Utility {
	interface ITimeFactory {
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime;
	}
}

namespace OCP\AppFramework\Services {
	interface IAppConfig {
	}
}

namespace OCP\AppFramework {
	class Controller {
		public function __construct(protected string $appName, protected \OCP\IRequest $request) {
		}
	}
	class Http {
		public const STATUS_OK = 200;
		public const STATUS_BAD_REQUEST = 400;
		public const STATUS_FORBIDDEN = 403;
		public const STATUS_CONFLICT = 409;
		public const STATUS_INTERNAL_SERVER_ERROR = 500;
	}
}

namespace OCP\AppFramework\Http {
	class DataResponse {
		public function __construct(private array $data, private int $status = 200) {
		}
		public function getData(): array {
			return $this->data;
		}
		public function getStatus(): int {
			return $this->status;
		}
	}
	class JSONResponse extends DataResponse {
	}
	class TemplateResponse {
		public function __construct(private string $app, private string $template, private array $parameters = []) {
		}
		public function getParams(): array {
			return $this->parameters;
		}
	}
}

namespace OCP\AppFramework\Http\Attribute {
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class NoAdminRequired {
	}
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class NoCSRFRequired {
	}
}

namespace Psr\Log {
	interface LoggerInterface {
		public function warning($message, array $context = []): void;
		public function error($message, array $context = []): void;
	}
}

namespace OCA\Timesheet\Service {
	class HrService {
		public array $hrUsers = ['hr', 'hr-admin'];
		public array $accessible = [['id' => 'alice', 'name' => 'Alice'], ['id' => 'bob', 'name' => 'Bob']];
		public function __construct(private \OCP\IUserSession $session) {
		}
		public function isHr(?string $userId = null): bool {
			return in_array($userId ?? $this->session->getUser()?->getUID(), $this->hrUsers, true);
		}
		public function canAccessUser(string $userId, ?string $hrUserId = null): bool {
			return $userId === ($hrUserId ?? $this->session->getUser()?->getUID()) || in_array($userId, array_column($this->accessible, 'id'), true);
		}
		public function getAccessibleUsers(?string $hrUserId = null): array {
			return $this->isHr($hrUserId) ? $this->accessible : [];
		}
	}
	class MonthlyBalanceService {
		public array $calls = [];
		public array $summaries = [];
		public function calculateCurrentBalance(string $userId): array {
			$this->calls[] = $userId;
			return $this->summaries[$userId] ?? ['dailyMin' => 480, 'overtimeMinutes' => 123, 'totalMinutes' => 456, 'lastEntryDate' => '2026-10-01'];
		}
	}
	class HolidayService {
	}
	class AbsenceCommentCatalog {
		public function getTerms(string $language): array {
			return ['vacation' => 'Urlaub', 'timeOffInLieu' => 'Stundenausgleich'];
		}
	}
}

namespace OCA\Timesheet\Db {
	class EntryMapper {
	}
	class UserConfigMapper {
	}
	class UserPreferencesStore {
		public array $historyPreferences = [];
		public array $historyReads = [];
		public bool $rejectHistoryReads = false;
		public function getBreakInputPreference(string $userId): array {
			return ['breakInputMode' => 'minutes', 'revision' => hash('sha256', '')];
		}
		public function getHistoryViewPreference(string $userId): array {
			$this->historyReads[] = $userId;
			if ($this->rejectHistoryReads) {
				throw new \RuntimeException('Synthetic history preference read failure.');
			}
			return $this->historyPreferences[$userId] ?? ['historyView' => 'cards', 'revision' => hash('sha256', '')];
		}
	}
	class TourProgressStore {
		public const VERSION = 1;
		public function getProgress(string $userId): array {
			return ['version' => 1, 'employeeCompleted' => true];
		}
	}
}

namespace {
	function p(mixed $value): void {
		echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
	function print_unescaped(mixed $value): void {
		echo $value;
	}
	require_once __DIR__ . '/../../lib/Service/EmploymentStatus.php';
	require_once __DIR__ . '/../../lib/Db/EmploymentStatusStore.php';
	require_once __DIR__ . '/../../lib/Listener/EmployeeActivityListener.php';
	require_once __DIR__ . '/../../lib/Controller/EmploymentStatusController.php';
	require_once __DIR__ . '/../../lib/Controller/PageController.php';
	require_once __DIR__ . '/../../lib/Controller/OverviewController.php';

	class EmploymentConfigFixture implements \OCP\IConfig {
		public array $values = [];
		public array $reads = [];
		public array $writes = [];
		public bool $rejectReads = false;
		public bool $rejectWrites = false;
		public ?\Closure $beforeWrite = null;
		public function __construct(private EmploymentLockFixture $locks) {
		}
		public function getUserValueForUsers($app, $key, $users) {
			$this->reads[] = $users;
			if ($app !== 'timesheet' || $key !== 'employment_status' || $this->rejectReads) {
				throw new \RuntimeException('Private storage diagnostic.');
			}
			return array_intersect_key($this->values, array_flip($users));
		}
		public function setUserValue($user, $app, $key, $value, $preCondition = null) {
			$lockKey = hash('sha256', 'timesheet:employment-status:' . $user);
			if (!isset($this->locks->held[$lockKey]) || $app !== 'timesheet' || $key !== 'employment_status') {
				throw new \RuntimeException('The employment update requires its shared account lock.');
			}
			if ($this->rejectWrites) {
				throw new \RuntimeException('Private storage diagnostic.');
			}
			if ($this->beforeWrite !== null) {
				($this->beforeWrite)($this);
			}
			if ($preCondition !== null && ($this->values[$user] ?? '') !== $preCondition) {
				throw new \OCP\PreConditionNotMetException();
			}
			$this->values[$user] = $value;
			$this->writes[] = [$user, $key, $value];
		}
	}
	class EmploymentLockFixture implements \OCP\Lock\ILockingProvider {
		public array $held = [];
		public int $acquisitions = 0;
		public int $releases = 0;
		public bool $busy = false;
		public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
			if ($this->busy) {
				throw new \OCP\Lock\LockedException('Synthetic busy account.');
			}
			if (strlen($path) !== 64 || $type !== self::LOCK_EXCLUSIVE || isset($this->held[$path])) {
				throw new \RuntimeException('Invalid shared account lock.');
			}
			$this->held[$path] = true;
			$this->acquisitions++;
		}
		public function releaseLock(string $path, int $type): void {
			unset($this->held[$path]);
			$this->releases++;
		}
	}
	class EmploymentClockFixture implements \OCP\AppFramework\Utility\ITimeFactory {
		public string $instant = '2026-11-02T12:00:00+00:00';
		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
			$date = new \DateTime($this->instant);
			return $timezone === null ? $date : $date->setTimezone($timezone);
		}
	}
	class EmploymentRequestFixture implements \OCP\IRequest {
		public array $parameters = [];
		public function getParam(string $key, mixed $default = null): mixed {
			return $this->parameters[$key] ?? $default;
		}
	}
	class EmploymentUserFixture implements \OCP\IUser {
		public bool $enabled = true;
		public function __construct(private string $userId) {
		}
		public function getUID(): string {
			return $this->userId;
		}
		public function isEnabled(): bool {
			return $this->enabled;
		}
	}
	class EmploymentSessionFixture implements \OCP\IUserSession {
		public function __construct(public ?\OCP\IUser $user) {
		}
		public function getUser(): ?\OCP\IUser {
			return $this->user;
		}
	}
	class EmploymentLoggerFixture implements \Psr\Log\LoggerInterface {
		public array $warnings = [];
		public array $errors = [];
		public function warning($message, array $context = []): void {
			$this->warnings[] = $message;
		}
		public function error($message, array $context = []): void {
			$this->errors[] = $message;
		}
	}
	class EmploymentGroupFixture implements \OCP\IGroupManager {
		public array $adminUserIds = ['admin', 'hr-admin'];
		public array $membersByGroup = ['Zeitnachweis' => ['alice', 'bob']];
		public bool $rejectReads = false;
		public function isAdmin(string $userId): bool {
			return in_array($userId, $this->adminUserIds, true);
		}
		public function isInGroup(string $userId, string $groupId): bool {
			if ($this->rejectReads) {
				throw new \RuntimeException('Synthetic group lookup failure.');
			}
			return in_array($userId, $this->membersByGroup[$groupId] ?? [], true);
		}
		public function get(string $groupId): ?EmploymentGroupObjectFixture {
			return array_key_exists($groupId, $this->membersByGroup) ? new EmploymentGroupObjectFixture($groupId) : null;
		}
		public function addMember(string $userId, string $groupId = 'Zeitnachweis'): void {
			$this->membersByGroup[$groupId] = array_values(array_unique([...($this->membersByGroup[$groupId] ?? []), $userId]));
		}
		public function removeMember(string $userId, string $groupId = 'Zeitnachweis'): void {
			$this->membersByGroup[$groupId] = array_values(array_filter($this->membersByGroup[$groupId] ?? [], static fn(string $memberId): bool => $memberId !== $userId));
		}
	}
	class EmploymentGroupObjectFixture implements \OCP\IGroup {
		public function __construct(private string $groupId) {
		}
		public function getGID(): string {
			return $this->groupId;
		}
	}
	class EmploymentTranslationFixture implements \OCP\IL10N {
		public function t(string $message, array $parameters = []): string {
			return $message;
		}
		public function getLanguageCode(): string {
			return 'de';
		}
	}
	class EmploymentAppConfigFixture implements \OCP\AppFramework\Services\IAppConfig {
	}
	class EmploymentTestSuite {
		private int $checks = 0;
		private int $failures = 0;
		public function same(mixed $expected, mixed $actual): void {
			if ($expected !== $actual) {
				throw new \RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
			}
		}
		public function throws(string $exceptionClass, callable $run): void {
			try { $run(); } catch (\Throwable $error) {
				if ($error instanceof $exceptionClass) {
					return;
				}
				throw $error;
			}
			throw new \RuntimeException('Expected ' . $exceptionClass . '.');
		}
		public function test(string $label, callable $run): void {
			$this->checks++;
			try { $run(); } catch (\Throwable $error) {
				$this->failures++;
				fwrite(STDERR, 'FAIL ' . $label . ': ' . $error->getMessage() . "\n");
			}
		}
		public function finish(string $label): never {
			fwrite(STDOUT, $label . ': ' . $this->checks . ' checks, ' . $this->failures . " failures\n");
			exit($this->failures === 0 ? 0 : 1);
		}
	}
}
