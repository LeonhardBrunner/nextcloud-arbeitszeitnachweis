<?php

namespace OCA\Timesheet\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\MissingEntryReminderStore;
use OCA\Timesheet\Db\UserConfigMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Coordinates enrollment, missing-day checks and separate employee/HR delivery. */
class MissingEntryReminderService {
	public function __construct(
		private GroupRuleService $groupRuleService,
		private EntryMapper $entryMapper,
		private UserConfigMapper $userConfigMapper,
		private HrService $hrService,
		private HolidayService $holidayService,
		private MissingEntryDetector $detector,
		private MissingEntryReminderStore $reminderStore,
		private MissingEntryReminderMailer $reminderMailer,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** Enrollment must succeed before an enabled rule is published; saving sends no email. */
	public function prepareRuleChanges(array $previousRules, array $updatedRules): void {
		$today = $this->currentTime()->format('Y-m-d');
		foreach ($updatedRules as $rule) {
			if (!($rule['missingEntryReminders']['enabled'] ?? false)) {
				continue;
			}
			$this->prepareRule($rule, $today);
		}
	}

	/** @return array{checkedEmployees:int,employeeSent:int,hrSent:int,deliveryFailures:int,skippedEmployees:int} */
	public function sendReminders(): array {
		$now = $this->currentTime();
		$today = $now->format('Y-m-d');
		$statistics = ['checkedEmployees' => 0, 'employeeSent' => 0, 'hrSent' => 0, 'deliveryFailures' => 0, 'skippedEmployees' => 0];
		$employeesById = [];
		$preparedRuleIds = [];

		// Resolve employees from employee groups, independently of HR mail preferences.
		foreach ($this->groupRuleService->getReminderRules() as $rule) {
			if (!($rule['missingEntryReminders']['enabled'] ?? false)) {
				continue;
			}
			try {
				$ruleEmployees = $this->prepareRule($rule, $today);
				$preparedRuleIds[$rule['id']] = true;
				$employeesById += $ruleEmployees;
			} catch (\Throwable $enrollmentError) {
				$this->logger->error('Missing-entry enrollment failed.', ['ruleId' => $rule['id'], 'exception' => $enrollmentError]);
			}
		}

		$findingsByUser = [];
		$holidaysByRegionAndYear = [];
		foreach ($employeesById as $userId => $employee) {
			try {
				$rule = $this->groupRuleService->getMissingEntryReminderPolicy((string)$userId);
				if ($rule === null || !($rule['missingEntryReminders']['enabled'] ?? false)
					|| !isset($preparedRuleIds[$rule['id']])) {
					continue;
				}
				$statistics['checkedEmployees']++;
				$finding = $this->checkEmployee($employee, $rule, $now, $holidaysByRegionAndYear);
				if ($finding['issues'] === [] && $finding['setupIssue'] === null) {
					continue;
				}
				$findingsByUser[$userId] = $finding;
				if ($finding['setupIssue'] !== null) {
					$statistics['skippedEmployees']++;
					continue;
				}
				if ($this->reminderStore->isDeliveryDue((string)$userId, 'employee', $now)) {
					if ($this->reminderMailer->sendEmployeeReminder($employee, $finding['issues'])) {
						$this->reminderStore->markDelivered((string)$userId, 'employee', $now);
						$statistics['employeeSent']++;
					} else {
						$statistics['deliveryFailures']++;
					}
				}
			} catch (\Throwable $checkError) {
				// A delivery-state error must not hide successfully checked dates from HR.
				if (isset($findingsByUser[$userId])) {
					$statistics['deliveryFailures']++;
				} else {
					$findingsByUser[$userId] = ['name' => $employee->getDisplayName(), 'issues' => [], 'setupIssue' => 'inspectionFailed'];
					$statistics['skippedEmployees']++;
				}
				$this->logger->error('Missing-entry processing failed for an employee.', ['userId' => $userId, 'exception' => $checkError]);
			}
		}

		// Recheck current HR scope before composing each separate summary.
		if ($findingsByUser !== []) {
			foreach ($this->hrService->getAllHrUserIds() as $hrUserId) {
				try {
					$hrUser = $this->userManager->get($hrUserId);
					if (!$hrUser || !$hrUser->isEnabled() || !$this->reminderStore->isDeliveryDue($hrUserId, 'hr', $now)) {
						continue;
					}
					$hrFindings = [];
					foreach ($this->hrService->getAccessibleUsers($hrUserId) as $accessibleEmployee) {
						if (isset($findingsByUser[$accessibleEmployee['id']])) {
							$hrFindings[] = $findingsByUser[$accessibleEmployee['id']];
						}
					}
					if ($hrFindings !== []) {
						if ($this->reminderMailer->sendHrSummary($hrUser, $hrFindings)) {
							$this->reminderStore->markDelivered($hrUserId, 'hr', $now);
							$statistics['hrSent']++;
						} else {
							$statistics['deliveryFailures']++;
						}
					}
				} catch (\Throwable $hrError) {
					$statistics['deliveryFailures']++;
					$this->logger->error('Missing-entry HR summary failed.', ['hrUserId' => $hrUserId, 'exception' => $hrError]);
				}
			}
		}
		return $statistics;
	}

	/** @return array<string,IUser> */
	private function prepareRule(array $rule, string $today): array {
		$ruleId = (string)$rule['id'];
		$isInitialCohort = !$this->reminderStore->isRuleInitialized($ruleId);
		$employees = [];
		foreach ($rule['userGroups'] as $groupId) {
			$group = $this->groupManager->get($groupId);
			if (!$group) {
				continue;
			}
			foreach ($group->getUsers() as $employee) {
				if (!$employee->isEnabled()) {
					continue;
				}
				$userId = $employee->getUID();
				$this->reminderStore->rememberEnrollment($userId, $ruleId, $isInitialCohort, $today);
				$employees[$userId] = $employee;
			}
		}
		if ($isInitialCohort) {
			$this->reminderStore->markRuleInitialized($ruleId);
		}
		return $employees;
	}

	/** @return array{name:string,issues:array,setupIssue:?string} */
	private function checkEmployee(IUser $employee, array $rule, DateTimeImmutable $now, array &$holidayCache): array {
		$userId = $employee->getUID();
		$settings = $rule['missingEntryReminders'];
		$result = ['name' => $employee->getDisplayName(), 'issues' => [], 'setupIssue' => null];
		$enrollment = $this->reminderStore->rememberEnrollment($userId, $rule['id'], false, $now->format('Y-m-d'));
		$startDate = $settings['startDate'];
		if (!$enrollment['initialCohort']) {
			$startDate = max($startDate, $enrollment['firstDetectedOn']);
		}
		$workConfig = $this->userConfigMapper->getConfigData($userId);
		$state = trim((string)($workConfig['state'] ?? ''));
		if (!$this->holidayService->isSupportedState($state)) {
			$result['setupIssue'] = 'missingRegion';
			return $result;
		}
		$cutoff = $now->setTime(0, 0)->modify('-' . $settings['graceDays'] . ' days')->format('Y-m-d');
		if ($startDate === '' || $startDate > $cutoff) {
			return $result;
		}

		$holidayDates = [];
		try {
			for ($year = (int)substr($startDate, 0, 4); $year <= (int)substr($cutoff, 0, 4); $year++) {
				$cacheKey = $year . ':' . $state;
				if (!array_key_exists($cacheKey, $holidayCache)) {
					try {
						$holidayCache[$cacheKey] = $this->holidayService->getHolidays($year, $state);
					} catch (\Throwable $holidayError) {
						$holidayCache[$cacheKey] = $holidayError;
					}
				}
				if ($holidayCache[$cacheKey] instanceof \Throwable) {
					throw $holidayCache[$cacheKey];
				}
				$holidayDates = array_merge($holidayDates, array_keys($holidayCache[$cacheKey]));
			}
		} catch (\Throwable $holidayError) {
			$result['setupIssue'] = 'holidayUnavailable';
			$this->logger->warning('Missing-entry check paused because holidays are unavailable.', ['userId' => $userId, 'exception' => $holidayError]);
			return $result;
		}

		$entries = array_map(fn($entry) => $entry->jsonSerialize(), $this->entryMapper->findByUserAndRange($userId, $startDate, $cutoff));
		$result['issues'] = $this->detector->findMissingEntries($now, $startDate, $settings['graceDays'], $settings['weekdays'], $entries, $holidayDates);
		return $result;
	}

	private function currentTime(): DateTimeImmutable {
		return DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime('now', new DateTimeZone('Europe/Berlin')));
	}
}
