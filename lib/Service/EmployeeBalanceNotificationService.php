<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Timesheet\Db\EmployeeBalanceNotificationStore;
use OCA\Timesheet\Db\EmploymentStatusStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Evaluates one winning group policy per employee, independently of personal HR mail choices. */
class EmployeeBalanceNotificationService {
	public function __construct(
		private GroupRuleService $groupRuleService,
		private MonthlyBalanceService $monthlyBalanceService,
		private EmploymentStatusStore $employmentStatusStore,
		private EmployeeBalanceNotificationStore $notificationStore,
		private EmployeeBalanceNotificationMailer $notificationMailer,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{checkedEmployees:int,sent:int,deliveryFailures:int,skippedEmployees:int} */
	public function sendNotifications(): array {
		$now = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime('now', new DateTimeZone('Europe/Berlin')));
		$statistics = ['checkedEmployees' => 0, 'sent' => 0, 'deliveryFailures' => 0, 'skippedEmployees' => 0];
		$employeeIds = [];
		// Collect group members once, even when several rules or HR accounts cover the same employee.
		foreach ($this->groupRuleService->getReminderRules() as $rule) {
			$settings = EmployeeBalanceNotificationSettings::normalize($rule['employeeBalanceNotifications'] ?? null);
			if (!$settings['overtimeEnabled'] && !$settings['negativeOvertimeEnabled']) {
				continue;
			}
			foreach ($rule['userGroups'] as $groupId) {
				try {
					foreach ($this->groupManager->get($groupId)?->getUsers() ?? [] as $employee) {
						$employeeIds[$employee->getUID()] = true;
					}
				} catch (\Throwable $groupError) {
					$this->logger->error('Employee balance group could not be read.', ['groupId' => $groupId, 'exception' => $groupError]);
				}
			}
		}
		foreach (array_keys($employeeIds) as $userId) {
			$userId = (string)$userId;
			try {
				$this->notificationStore->withDeliveryLock($userId, function () use ($userId, $now, &$statistics): void {
					$employee = $this->userManager->get($userId);
					if (!$employee || !$employee->isEnabled()) {
						$statistics['skippedEmployees']++;
						return;
					}
					$status = $this->employmentStatusStore->getStatus($userId);
					if (!$status->isActive || $status->freelancerMode) {
						$statistics['skippedEmployees']++;
						return;
					}
					$rule = $this->groupRuleService->getEmployeeBalanceNotificationPolicy($userId);
					$settings = EmployeeBalanceNotificationSettings::normalize($rule['employeeBalanceNotifications'] ?? null);
					if (!$settings['overtimeEnabled'] && !$settings['negativeOvertimeEnabled']) {
						return;
					}
					$statistics['checkedEmployees']++;
					if (!$this->notificationStore->isDeliveryDue($userId, $now)) {
						return;
					}
					$summary = $this->monthlyBalanceService->calculateCurrentBalance($userId);
					if (!is_array($summary) || !isset($summary['overtimeMinutes']) || !is_numeric($summary['overtimeMinutes'])) {
						$statistics['skippedEmployees']++;
						return;
					}
					$balanceMinutes = (int)$summary['overtimeMinutes'];
					$warningType = null;
					$thresholdMinutes = 0;
					if ($settings['overtimeEnabled'] && $balanceMinutes > $settings['overtimeThresholdMinutes']) {
						$warningType = 'overtime';
						$thresholdMinutes = $settings['overtimeThresholdMinutes'];
					} elseif ($settings['negativeOvertimeEnabled'] && $balanceMinutes < -$settings['negativeOvertimeThresholdMinutes']) {
						$warningType = 'negative';
						$thresholdMinutes = $settings['negativeOvertimeThresholdMinutes'];
					}
					if ($warningType === null) {
						return;
					}
					if ($this->notificationMailer->sendBalanceNotification($employee, $balanceMinutes, $thresholdMinutes, $warningType)) {
						$this->notificationStore->markDelivered($userId, $now);
						$statistics['sent']++;
					} else {
						$statistics['deliveryFailures']++;
					}
				});
			} catch (\Throwable $employeeError) {
				$statistics['deliveryFailures']++;
				$this->logger->error('Employee balance notification processing failed.', ['userId' => $userId, 'exception' => $employeeError]);
			}
		}
		return $statistics;
	}
}
