<?php

namespace OCA\Timesheet\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IGroupManager;
use OCA\Timesheet\Service\MissingEntryReminderSettings;

class AdminSettings implements ISettings {

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
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript($this->appName, 'admin');

		$allGroups = [];
		foreach ($this->groupManager->search('') as $group) {
			$allGroups[] = $group->getGID();
		}
		sort($allGroups, SORT_NATURAL | SORT_FLAG_CASE);

		$accessRules = $this->loadRules();

		return new TemplateResponse($this->appName, 'settings-admin', [
			'allGroups'     => $allGroups,
			'hrAccessRules' => $accessRules,
		]);
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

			$hrGroups = isset($rule['hrGroups']) && is_array($rule['hrGroups']) ? $rule['hrGroups'] : [];
			$userGroups = isset($rule['userGroups']) && is_array($rule['userGroups']) ? $rule['userGroups'] : [];

			$hrGroups = array_values(array_unique(array_values(array_filter(array_map('trim', $hrGroups), fn($groupId) => $groupId !== ''))));
			$userGroups = array_values(array_unique(array_values(array_filter(array_map('trim', $userGroups), fn($groupId) => $groupId !== ''))));

			$sanitizedRules[] = array_merge([
				'id' => $ruleId,
				'hrGroups' => $hrGroups,
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

	public function getSection(): string {
		return 'timesheet';
	}

	public function getPriority(): int {
		return 50;
	}
}
