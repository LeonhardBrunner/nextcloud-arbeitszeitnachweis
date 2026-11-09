<?php

namespace OCA\Timesheet\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Util;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\Timesheet\Service\HrRuleAssignments;
use OCA\Timesheet\Service\MissingEntryReminderSettings;
use OCA\Timesheet\Service\EmployeeBalanceNotificationSettings;

/** Builds the common rule form; each settings provider checks its own role first. */
class RuleSettingsForm {
	private HrRuleAssignments $hrAssignments;

	private const DEFAULT_RULE_SETTINGS = [
		'priority' => 1,
		'breakShortMinutes' => 30,
		'breakShortHours' => 6,
		'breakLongMinutes' => 45,
		'breakLongHours' => 9,
		'maxHours' => 10,
	];

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private string $appName,
		private IUserManager $userManager,
	) {
		$this->hrAssignments = new HrRuleAssignments($groupManager, $userManager);
	}

	public function getForm(string $scope): TemplateResponse {
		Util::addScript($this->appName, 'hr-settings');
		Util::addStyle($this->appName, 'hr-settings');
		return new TemplateResponse($this->appName, 'settings-hr', $this->getData($scope));
	}

	/** The profile form and embedded notification editor share one sanitized snapshot. */
	public function getData(string $scope): array {
		if (!in_array($scope, ['assignments', 'policy', 'notifications'], true)) {
			throw new \InvalidArgumentException('Invalid rule editor scope.');
		}
		$allGroups = [];
		if ($scope === 'assignments') {
			foreach ($this->groupManager->search('') as $group) {
				$allGroups[] = $group->getGID();
			}
		}
		sort($allGroups, SORT_NATURAL | SORT_FLAG_CASE);

		$rawRules = (string)$this->appConfig->getAppValueString('hr_access_rules');
		$accessRules = $this->loadRules($rawRules);

		return [
			'settingsScope' => $scope === 'assignments' ? 'assignments' : 'policy',
			'policySection' => match ($scope) {
				'policy' => 'timeRules',
				'notifications' => 'notifications',
				default => null,
			},
			'rulesRevision' => hash('sha256', $rawRules),
			'allGroups'     => $allGroups,
			'hrAccessRules' => $accessRules,
			'hrPeople' => $this->selectedPeople($accessRules),
		];
	}

	/** Only selected accounts are embedded; the directory is searched on demand. */
	private function selectedPeople(array $rules): array {
		$people = [];
		foreach ($rules as $rule) {
			foreach (array_merge($rule['hrUsers'], $rule['mailRecipients']['userIds']) as $userId) {
				$user = $this->userManager->get($userId);
				if ($user !== null) {
					$people['user:' . $userId] = ['id' => $userId, 'name' => (string)$user->getDisplayName(), 'enabled' => $user->isEnabled()];
				}
			}
		}
		return array_values($people);
	}

	private function loadRules(string $rulesJson): array {
		if (trim($rulesJson) === '') {
			return [];
		}
		$decodedRules = json_decode($rulesJson, true);
		if (!is_array($decodedRules)) {
			return [];
		}
		return $this->sanitizeRules($decodedRules);
	}

	private function sanitizeRules(array $rules): array {
		$sanitizedRules = [];
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$ruleId = isset($rule['id']) ? trim((string)$rule['id']) : '';
			if ($ruleId === '') {
				continue;
			}

			$userGroups = isset($rule['userGroups']) && is_array($rule['userGroups']) ? $rule['userGroups'] : [];

			$userGroups = array_values(array_unique(array_values(array_filter(array_map('trim', $userGroups), fn($groupId) => $groupId !== ''))));

			$sanitizedRules[] = array_merge([
				'id' => $ruleId,
				...$this->hrAssignments->normalize($rule),
				'userGroups' => $userGroups,
				'missingEntryReminders' => MissingEntryReminderSettings::normalize($rule['missingEntryReminders'] ?? null),
				'employeeBalanceNotifications' => EmployeeBalanceNotificationSettings::normalize($rule['employeeBalanceNotifications'] ?? null),
			], $this->sanitizeRuleThresholds($rule));
		}
		return $sanitizedRules;
	}

	/**
	 * @param array $item
	 * @return array<string, int|float>
	 */
	private function sanitizeRuleThresholds(array $item): array {
		$defaults = self::DEFAULT_RULE_SETTINGS;

		return [
			'priority' => $this->clampInt($item['priority'] ?? $defaults['priority'], 0, 9999),
			'breakShortMinutes' => $this->clampInt($item['breakShortMinutes'] ?? $defaults['breakShortMinutes'], 0, 600),
			'breakShortHours' => $this->clampFloat($item['breakShortHours'] ?? $defaults['breakShortHours'], 0, 24),
			'breakLongMinutes' => $this->clampInt($item['breakLongMinutes'] ?? $defaults['breakLongMinutes'], 0, 600),
			'breakLongHours' => $this->clampFloat($item['breakLongHours'] ?? $defaults['breakLongHours'], 0, 24),
			'maxHours' => $this->clampFloat($item['maxHours'] ?? $defaults['maxHours'], 0, 24),
		];
	}

	private function clampInt($value, int $min, int $max): int {
		if (!is_numeric($value)) {
			return $min;
		}
		$numericValue = (int)$value;
		return max($min, min($max, $numericValue));
	}

	private function clampFloat($value, float $min, float $max): float {
		if (!is_numeric($value)) {
			return $min;
		}
		$numericValue = (float)$value;
		if (is_nan($numericValue) || is_infinite($numericValue)) {
			return $min;
		}
		return max($min, min($max, round($numericValue, 2)));
	}

}
