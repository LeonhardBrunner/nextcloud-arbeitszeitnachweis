<?php

declare(strict_types=1);

// Run both real warning services without a Nextcloud database or mail transport.
namespace OCP {
	interface IUser { public function getUID(): string; public function getDisplayName(): string; public function isEnabled(): bool; }
	interface IUserManager { public function get(string $id): ?IUser; }
	interface IGroupManager { public function get(string $id); }
}
namespace OCP\AppFramework\Db { class DoesNotExistException extends \RuntimeException {} }
namespace OCP\AppFramework\Utility {
	interface ITimeFactory { public function getDateTime(string $time = 'now', ?\DateTimeZone $zone = null): \DateTime; }
}
namespace Psr\Log { interface LoggerInterface { public function error($message, array $context = []): void; public function warning($message, array $context = []): void; } }

namespace OCA\Timesheet\Db {
	class EntryMapper {
		public array $reads = [];
		public function findByUserAndRange(string $id, string $from, string $to): array { $this->reads[] = [$id, $from, $to]; return []; }
	}
	class UserConfigMapper {
		public array $states = [];
		public array $unavailable = [];
		public function getConfigData(string $id): array {
			if (in_array($id, $this->unavailable, true)) throw new \RuntimeException('Synthetic work configuration failure.');
			return ['dailyMin' => 480, 'state' => $this->states[$id] ?? 'BY'];
		}
	}
	class HrPreferencesStore {
		public array $settings = [
			'noEntryEnabled' => true,
			'overtimeEnabled' => true,
			'negativeOvertimeEnabled' => true,
			'warningThresholds' => ['noEntryDays' => 1, 'overtimeThresholdMinutes' => 60, 'negativeOvertimeThresholdMinutes' => 60],
		];
		public function getNotificationSettings(string $id): array { return $this->settings; }
	}
	class EmploymentStatusStore {
		public array $statuses = [];
		public array $unavailable = [];
		public function getStatus(string $id): \OCA\Timesheet\Service\EmploymentStatus {
			if (in_array($id, $this->unavailable, true)) throw new \RuntimeException('Synthetic unavailable employment status.');
			return $this->statuses[$id];
		}
	}
	class MissingEntryReminderStore {
		public array $deliveries = [];
		public array $enrollments = [];
		public function isRuleInitialized(string $id): bool { return true; }
		public function markRuleInitialized(string $id): void {}
		public function rememberEnrollment(string $id, string $ruleId, bool $initial, string $today): array {
			return $this->enrollments[$id] ??= ['initialCohort' => true, 'firstDetectedOn' => $today];
		}
		public function isDeliveryDue(string $id, string $kind, \DateTimeImmutable $now): bool {
			return !isset($this->deliveries[$kind][$id]) || $now >= $this->deliveries[$kind][$id]->modify('+7 days');
		}
		public function markDelivered(string $id, string $kind, \DateTimeImmutable $now): void { $this->deliveries[$kind][$id] = $now; }
	}
}
namespace OCA\Timesheet\Service {
	class HrService {
		public array $recipients = ['hr', 'hr-freelancer', 'hr-disabled', 'hr-unavailable'];
		public array $employees = [];
		public function getMailRecipientUserIds(): array { return $this->recipients; }
		public function getMailAccessibleUsers(string $id): array { return $this->employees; }
	}
	class GroupRuleService {
		public array $rule = ['id' => 'test-rule', 'userGroups' => ['staff'], 'missingEntryReminders' => ['enabled' => true, 'graceDays' => 1, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => '2026-11-04']];
		public array $employeeRules = [];
		public array $unavailable = [];
		public function getReminderRules(): array { return array_map($this->normalizeRule(...), [$this->rule, ...array_values($this->employeeRules)]); }
		public function getMissingEntryReminderPolicy(string $id): array {
			if (in_array($id, $this->unavailable, true)) throw new \RuntimeException('Synthetic policy lookup failure.');
			return $this->normalizeRule($this->employeeRules[$id] ?? $this->rule);
		}
		private function normalizeRule(array $rule): array {
			$rule['missingEntryReminders'] = MissingEntryReminderSettings::normalize($rule['missingEntryReminders']);
			return $rule;
		}
	}
	class MonthlyBalanceService {
		public array $reads = [];
		public array $summaries = [];
		public function calculateCurrentBalance(string $id): array { $this->reads[] = $id; return $this->summaries[$id]; }
	}
	class HolidayService {
		public bool $unavailable = false;
		public function isSupportedState(string $state): bool { return $state === 'BY'; }
		public function getHolidays(int $year, string $state): array {
			if ($this->unavailable) throw new \RuntimeException('Synthetic holiday lookup failure.');
			return [];
		}
	}
	class AbsenceCommentCatalog { public function isExemptComment(?string $comment): bool { return false; } }
	class MissingEntryReminderMailer {
		public array $employees = [];
		public array $hr = [];
		public array $failedEmployees = [];
		public array $failedHr = [];
		public function sendEmployeeReminder(\OCP\IUser $employee, array $issues): bool {
			if (in_array($employee->getUID(), $this->failedEmployees, true)) return false;
			$this->employees[$employee->getUID()] = $issues;
			return true;
		}
		public function sendHrSummary(\OCP\IUser $employee, array $findings): bool {
			if (in_array($employee->getUID(), $this->failedHr, true)) return false;
			$this->hr[$employee->getUID()] = $findings;
			return true;
		}
	}
}
namespace {
	use OCA\Timesheet\Service\EmploymentStatus;
	use OCA\Timesheet\Service\HrNotificationService;
	use OCA\Timesheet\Service\MissingEntryReminderService;

	require __DIR__ . '/../../lib/Service/EmploymentStatus.php';
	require __DIR__ . '/../../lib/Service/MissingEntryDetector.php';
	require __DIR__ . '/../../lib/Service/MissingEntryReminderSettings.php';
	require __DIR__ . '/../../lib/Service/HrNotificationService.php';
	require __DIR__ . '/../../lib/Service/MissingEntryReminderService.php';

	class WarningUser implements \OCP\IUser {
		public function __construct(private string $id, private bool $enabled = true) {}
		public function getUID(): string { return $this->id; }
		public function getDisplayName(): string { return $this->id; }
		public function isEnabled(): bool { return $this->enabled; }
	}
	class WarningUsers implements \OCP\IUserManager {
		public array $users = [];
		public function get(string $id): ?\OCP\IUser { return $this->users[$id] ?? null; }
	}
	class WarningGroups implements \OCP\IGroupManager {
		public function __construct(private array $employees) {}
		public function get(string $id): object { return new class($this->employees) { public function __construct(private array $users) {} public function getUsers(): array { return $this->users; } }; }
	}
	class WarningClock implements \OCP\AppFramework\Utility\ITimeFactory {
		public string $date = '2026-11-06 10:00:00';
		public function getDateTime(string $time = 'now', ?\DateTimeZone $zone = null): \DateTime {
			$value = new \DateTime($this->date, $zone ?? new \DateTimeZone('Europe/Berlin'));
			return $time === 'today' ? $value->setTime(0, 0) : $value;
		}
	}
	class WarningLogger implements \Psr\Log\LoggerInterface {
		public array $errors = [];
		public function error($message, array $context = []): void { $this->errors[] = $message; }
		public function warning($message, array $context = []): void {}
	}
	function warningFixture(): array {
		$users = new WarningUsers(); $statuses = new \OCA\Timesheet\Db\EmploymentStatusStore(); $hr = new \OCA\Timesheet\Service\HrService();
		$balances = new \OCA\Timesheet\Service\MonthlyBalanceService();
		$employeeIds = ['active-plus', 'active-minus', 'freelancer-plus', 'freelancer-minus', 'inactive'];
		foreach ([...$employeeIds, ...$hr->recipients] as $id) {
			$users->users[$id] = new WarningUser($id, $id !== 'hr-disabled');
			$statuses->statuses[$id] = new EmploymentStatus(!str_starts_with($id, 'hr') && $id !== 'inactive', str_contains($id, 'freelancer'));
			$balances->summaries[$id] = ['lastEntryDate' => '2026-10-01', 'to' => '2026-10-01', 'overtimeMinutes' => str_ends_with($id, 'minus') ? -120 : 120];
		}
		$statuses->unavailable = ['hr-unavailable'];
		$hr->employees = array_map(static fn(string $id): array => ['id' => $id, 'name' => $id], $employeeIds);
		$groups = new WarningGroups(array_map(static fn(string $id) => $users->get($id), $employeeIds));
		$entries = new \OCA\Timesheet\Db\EntryMapper(); $configs = new \OCA\Timesheet\Db\UserConfigMapper(); $clock = new WarningClock(); $logger = new WarningLogger();
		$store = new \OCA\Timesheet\Db\MissingEntryReminderStore(); $mailer = new \OCA\Timesheet\Service\MissingEntryReminderMailer(); $rules = new \OCA\Timesheet\Service\GroupRuleService();
		$holidays = new \OCA\Timesheet\Service\HolidayService();
		$preferences = new \OCA\Timesheet\Db\HrPreferencesStore();
		$legacy = static fn() => new HrNotificationService($preferences, $hr, $balances, $statuses, $clock, $logger, $users);
		$daily = static fn() => new MissingEntryReminderService($rules, $entries, $configs, $hr, $holidays, new \OCA\Timesheet\Service\MissingEntryDetector(new \OCA\Timesheet\Service\AbsenceCommentCatalog()), $store, $mailer, $groups, $users, $clock, $logger, $statuses);
		return compact('users', 'statuses', 'hr', 'balances', 'entries', 'configs', 'holidays', 'rules', 'clock', 'store', 'mailer', 'logger', 'preferences', 'legacy', 'daily');
	}
	$checks = 0; $failures = 0;
	function warningSame(mixed $expected, mixed $actual): void { if ($expected !== $actual) throw new RuntimeException('Expected ' . json_encode($expected) . ', got ' . json_encode($actual)); }
	function warningTest(string $name, callable $test): void { global $checks, $failures; $checks++; try { $test(); } catch (Throwable $error) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"); } }

	warningTest('legacy summaries exclude all freelancer subjects and recipients', function (): void {
		$f = warningFixture(); $warnings = $f['legacy']()->doCron();
		warningSame(['hr'], array_keys($warnings));
		warningSame(['active-plus', 'active-minus'], array_column($warnings['hr']['noEntry'], 'user'));
		warningSame(['active-plus'], array_column($warnings['hr']['overtime'], 'user'));
		warningSame(['active-minus'], array_column($warnings['hr']['negative'], 'user'));
		warningSame(['active-plus', 'active-minus'], $f['balances']->reads);
	});
	warningTest('weekly HR mail uses the shared thresholds at the same boundary as the HR list', function (): void {
		$f = warningFixture();
		$f['balances']->summaries['active-plus'] = ['lastEntryDate' => '2026-11-05', 'overtimeMinutes' => 60];
		$f['balances']->summaries['active-minus'] = ['lastEntryDate' => '2026-11-06', 'overtimeMinutes' => -60];

		$warnings = $f['legacy']()->doCron()['hr'];
		warningSame([['user' => 'active-plus', 'days' => 1]], $warnings['noEntry']);
		warningSame(['active-plus'], array_column($warnings['overtime'], 'user'));
		warningSame(['active-minus'], array_column($warnings['negative'], 'user'));
	});
	warningTest('weekly HR mail does not treat zero balance or missing reference as a shared-threshold warning', function (): void {
		$f = warningFixture();
		$f['preferences']->settings['warningThresholds']['overtimeThresholdMinutes'] = 0;
		$f['preferences']->settings['warningThresholds']['negativeOvertimeThresholdMinutes'] = 0;
		$f['balances']->summaries['active-plus'] = ['lastEntryDate' => null, 'to' => '2026-10-01', 'overtimeMinutes' => 0];
		$f['balances']->summaries['active-minus'] = ['lastEntryDate' => null, 'overtimeMinutes' => 0];

		warningSame([], $f['legacy']()->doCron());
	});
	warningTest('disabled HR email categories stay disabled at the shared thresholds', function (): void {
		$f = warningFixture();
		$f['preferences']->settings['noEntryEnabled'] = false;
		$f['preferences']->settings['negativeOvertimeEnabled'] = false;
		$f['balances']->summaries['active-plus']['overtimeMinutes'] = 60;
		$f['balances']->summaries['active-minus']['overtimeMinutes'] = -60;

		$warnings = $f['legacy']()->doCron()['hr'];
		warningSame([], $warnings['noEntry']);
		warningSame(['active-plus'], array_column($warnings['overtime'], 'user'));
		warningSame([], $warnings['negative']);
	});
	warningTest('a team containing only freelancers produces no legacy summary', function (): void {
		$f = warningFixture(); $f['hr']->employees = array_slice($f['hr']->employees, 2, 2);
		warningSame([], $f['legacy']()->doCron()); warningSame([], $f['balances']->reads);
	});
	warningTest('daily reminders exclude freelancers from personal mail and HR summaries', function (): void {
		$f = warningFixture(); $statistics = $f['daily']()->sendReminders();
		warningSame(2, $statistics['employeeSent']); warningSame(1, $statistics['hrSent']);
		warningSame(['active-plus', 'active-minus'], array_keys($f['mailer']->employees)); warningSame(['hr'], array_keys($f['mailer']->hr));
		warningSame(['active-plus', 'active-minus'], array_column($f['mailer']->hr['hr'], 'name'));
		warningSame(false, isset($f['store']->deliveries['employee']['freelancer-plus']));
		warningSame(false, isset($f['store']->deliveries['hr']['hr-freelancer']));
	});
	warningTest('HR recipients without employee activity membership still receive regular warnings', function (): void {
		$f = warningFixture(); warningSame(false, $f['statuses']->statuses['hr']->isActive);
		warningSame(true, isset($f['legacy']()->doCron()['hr'])); $f['daily']()->sendReminders(); warningSame(true, isset($f['mailer']->hr['hr']));
	});
	warningTest('unreadable employee status excludes every warning category', function (): void {
		$f = warningFixture(); $f['statuses']->unavailable[] = 'active-plus'; $warnings = $f['legacy']()->doCron();
		warningSame([], $warnings['hr']['overtime']); warningSame(['active-minus'], array_column($warnings['hr']['noEntry'], 'user'));
		$f['daily']()->sendReminders(); warningSame(['active-minus'], array_keys($f['mailer']->employees)); warningSame(['active-minus'], array_column($f['mailer']->hr['hr'], 'name'));
	});
	warningTest('freelancer and unreadable recipients do not block other HR accounts', function (): void {
		$f = warningFixture(); $f['hr']->recipients = ['hr-freelancer', 'hr-unavailable', 'hr'];
		warningSame(['hr'], array_keys($f['legacy']()->doCron())); warningSame(1, $f['daily']()->sendReminders()['hrSent']);
	});
	warningTest('turning freelancer mode off resumes ordinary plus and minus warnings', function (): void {
		$f = warningFixture(); foreach (['freelancer-plus', 'freelancer-minus'] as $id) $f['statuses']->statuses[$id] = new EmploymentStatus(true, false);
		$warnings = $f['legacy']()->doCron()['hr'];
		warningSame(['active-plus', 'freelancer-plus'], array_column($warnings['overtime'], 'user'));
		warningSame(['active-minus', 'freelancer-minus'], array_column($warnings['negative'], 'user'));
	});
	warningTest('returning employees retain delivery cooldowns while previously excluded ones can receive mail', function (): void {
		$f = warningFixture(); $f['daily']()->sendReminders(); $previousDelivery = $f['store']->deliveries['employee']['active-plus'];
		$f['mailer']->employees = []; $f['mailer']->hr = [];
		$f['statuses']->statuses['freelancer-plus'] = new EmploymentStatus(true, false, 'returned', [['from' => '2026-11-05', 'to' => '2026-11-06']]);
		$statistics = $f['daily']()->sendReminders();
		warningSame(1, $statistics['employeeSent']); warningSame(0, $statistics['hrSent']); warningSame(['freelancer-plus'], array_keys($f['mailer']->employees));
		warningSame([['date' => '2026-11-04', 'reason' => 'missing']], $f['mailer']->employees['freelancer-plus']);
		warningSame($previousDelivery, $f['store']->deliveries['employee']['active-plus']);
	});
	warningTest('a former freelancer HR account can receive mail without joining the employee activity group', function (): void {
		$f = warningFixture(); $f['statuses']->statuses['hr-freelancer'] = new EmploymentStatus(false, false);
		warningSame(['hr', 'hr-freelancer'], array_keys($f['legacy']()->doCron())); warningSame(2, $f['daily']()->sendReminders()['hrSent']);
	});
	foreach ([false, true] as $notifyEmployees) {
		foreach ([false, true] as $notifyHr) {
			warningTest('independent missing-entry recipients: ' . json_encode([$notifyEmployees, $notifyHr]), function () use ($notifyEmployees, $notifyHr): void {
				$f = warningFixture();
				$f['rules']->rule['missingEntryReminders'] += ['notifyEmployees' => $notifyEmployees, 'notifyHr' => $notifyHr];
				$statistics = $f['daily']()->sendReminders();
				warningSame($notifyEmployees ? 2 : 0, $statistics['employeeSent']);
				warningSame($notifyHr ? 1 : 0, $statistics['hrSent']);
				warningSame($notifyEmployees ? ['active-plus', 'active-minus'] : [], array_keys($f['mailer']->employees));
				warningSame($notifyHr ? ['hr'] : [], array_keys($f['mailer']->hr));
				warningSame($notifyEmployees || $notifyHr ? 2 : 0, $statistics['checkedEmployees']);
				warningSame($notifyEmployees, isset($f['store']->deliveries['employee']['active-plus']));
				warningSame($notifyHr, isset($f['store']->deliveries['hr']['hr']));
			});
		}
	}
	warningTest('mixed winning policies keep each recipient summary limited to HR-enabled findings', function (): void {
		$f = warningFixture();
		$f['rules']->rule['missingEntryReminders'] += ['notifyEmployees' => false, 'notifyHr' => true];
		$f['rules']->employeeRules['active-minus'] = array_replace($f['rules']->rule, ['id' => 'employee-only', 'missingEntryReminders' => array_replace($f['rules']->rule['missingEntryReminders'], ['notifyEmployees' => true, 'notifyHr' => false])]);
		$f['daily']()->sendReminders();
		warningSame(['active-minus'], array_keys($f['mailer']->employees));
		warningSame(['active-plus'], array_column($f['mailer']->hr['hr'], 'name'));
	});
	foreach (['region', 'holiday', 'inspection'] as $failure) {
		foreach ([false, true] as $notifyHr) {
			warningTest('setup notices respect the HR switch: ' . $failure . ' ' . json_encode($notifyHr), function () use ($failure, $notifyHr): void {
				$f = warningFixture();
				$f['rules']->rule['missingEntryReminders'] += ['notifyEmployees' => true, 'notifyHr' => $notifyHr];
				if ($failure === 'region') $f['configs']->states['active-plus'] = '';
				if ($failure === 'holiday') $f['holidays']->unavailable = true;
				if ($failure === 'inspection') $f['configs']->unavailable[] = 'active-plus';
				$statistics = $f['daily']()->sendReminders();
				warningSame($notifyHr ? 1 : 0, $statistics['hrSent']);
				if ($notifyHr) {
					$firstFinding = array_values(array_filter($f['mailer']->hr['hr'], static fn(array $finding): bool => $finding['name'] === 'active-plus'))[0];
					warningSame(['region' => 'missingRegion', 'holiday' => 'holidayUnavailable', 'inspection' => 'inspectionFailed'][$failure], $firstFinding['setupIssue']);
				} else warningSame([], $f['mailer']->hr);
			});
		}
	}
	warningTest('unknown policy intent never creates an HR inspection notice', function (): void {
		$f = warningFixture(); $f['rules']->unavailable[] = 'active-plus'; $f['daily']()->sendReminders();
		warningSame(['active-minus'], array_column($f['mailer']->hr['hr'], 'name'));
	});
	warningTest('recipient changes preserve both delivery clocks and enrollment', function (): void {
		$f = warningFixture();
		$f['rules']->rule['missingEntryReminders'] += ['notifyEmployees' => true, 'notifyHr' => false];
		$f['daily']()->sendReminders();
		$enrollments = $f['store']->enrollments;
		$employeeDelivery = $f['store']->deliveries['employee'];
		$f['rules']->rule['missingEntryReminders']['notifyEmployees'] = false;
		$f['daily']()->prepareRuleChanges([], [$f['rules']->rule]);
		warningSame(0, $f['daily']()->sendReminders()['checkedEmployees']);
		$f['rules']->rule['missingEntryReminders']['notifyHr'] = true;
		$hrOnly = $f['daily']()->sendReminders();
		warningSame(0, $hrOnly['employeeSent']); warningSame(1, $hrOnly['hrSent']);
		$hrDelivery = $f['store']->deliveries['hr'];
		$f['rules']->rule['missingEntryReminders']['notifyEmployees'] = true;
		$both = $f['daily']()->sendReminders();
		warningSame(0, $both['employeeSent']); warningSame(0, $both['hrSent']);
		warningSame($employeeDelivery, $f['store']->deliveries['employee']);
		warningSame($hrDelivery, $f['store']->deliveries['hr']);
		warningSame($enrollments, $f['store']->enrollments);
		$f['clock']->date = '2026-11-13 10:00:00';
		$later = $f['daily']()->sendReminders();
		warningSame(2, $later['employeeSent']); warningSame(1, $later['hrSent']);
	});
	warningTest('an employee delivery failure stays retryable without delaying the HR summary', function (): void {
		$f = warningFixture(); $f['mailer']->failedEmployees = ['active-plus'];
		$first = $f['daily']()->sendReminders();
		warningSame(1, $first['employeeSent']); warningSame(1, $first['hrSent']); warningSame(1, $first['deliveryFailures']);
		warningSame(false, isset($f['store']->deliveries['employee']['active-plus']));
		$f['mailer']->failedEmployees = [];
		$retry = $f['daily']()->sendReminders(); warningSame(1, $retry['employeeSent']); warningSame(0, $retry['hrSent']);
	});
	warningTest('an HR delivery failure stays retryable without resending employee reminders', function (): void {
		$f = warningFixture(); $f['mailer']->failedHr = ['hr'];
		$first = $f['daily']()->sendReminders();
		warningSame(2, $first['employeeSent']); warningSame(0, $first['hrSent']); warningSame(1, $first['deliveryFailures']);
		warningSame(false, isset($f['store']->deliveries['hr']['hr']));
		$f['mailer']->failedHr = [];
		$retry = $f['daily']()->sendReminders(); warningSame(0, $retry['employeeSent']); warningSame(1, $retry['hrSent']);
	});
	echo 'Employment warning notifications: ' . $checks . ' checks, ' . $failures . " failures.\n";
	exit($failures === 0 ? 0 : 1);
}
