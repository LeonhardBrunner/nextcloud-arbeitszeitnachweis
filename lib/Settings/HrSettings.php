<?php

namespace OCA\Timesheet\Settings;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\HrRuleAssignments;
use OCA\Timesheet\Service\MissingEntryReminderSettings;

class HrSettings implements ISettings {
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
		private HrService $hrService,
		private IUserManager $userManager,
	) {
		$this->hrAssignments = new HrRuleAssignments($groupManager, $userManager);
	}

	public function getForm(): TemplateResponse {
		// The direct form entry point follows the same role check as section visibility.
		if (!$this->hrService->isHr()) {
			return new TemplateResponse('core', '403', [], '', Http::STATUS_FORBIDDEN);
		}

		Util::addScript($this->appName, 'hr-settings');

		$allGroups = [];
		foreach ($this->groupManager->search('') as $group) {
			$allGroups[] = $group->getGID();
		}
		sort($allGroups, SORT_NATURAL | SORT_FLAG_CASE);

		$accessRules = $this->loadRules();

		return new TemplateResponse($this->appName, 'settings-hr', [
			'allGroups'     => $allGroups,
			'hrAccessRules' => $accessRules,
			'hrPeople' => $this->selectedPeople($accessRules),
		]);
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

	private function loadRules(): array {
		$rulesJson = (string)$this->appConfig->getAppValueString('hr_access_rules');
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

	public function getSection(): ?string {
		return $this->hrService->isHr() ? 'timesheet' : null;
	}

	public function getPriority(): int {
		return 50;
	}
}
