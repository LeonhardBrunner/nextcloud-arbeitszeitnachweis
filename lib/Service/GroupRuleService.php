<?php

namespace OCA\Timesheet\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

class GroupRuleService {
	private const DEFAULTS = [
		'breakShortMinutes' => 30,
		'breakShortHours' => 6,
		'breakLongMinutes' => 45,
		'breakLongHours' => 9,
		'maxHours' => 10,
	];
	private const PRIORITY_DEFAULT = 1;
	private const PRIORITY_MIN = 0;
	private const PRIORITY_MAX = 9999;

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Returns effective rule thresholds for the given user.
	 * If userId is null, resolves to the current logged-in user.
	 *
	 * @return array<string, int|float>
	 */
	public function getEffectiveRules(?string $userId = null): array {
		$resolvedUserId = $userId;
		if ($resolvedUserId === null) {
			$currentUser = $this->userSession->getUser();
			if (!$currentUser) {
				return self::DEFAULTS;
			}
			$resolvedUserId = $currentUser->getUID();
		}

		$user = $this->userManager->get($resolvedUserId);
		if (!$user) {
			return self::DEFAULTS;
		}

		if (method_exists($this->groupManager, 'getUserGroupIds')) {
			$userGroupIds = $this->groupManager->getUserGroupIds($user);
		} else {
			$userGroupIds = array_map(
				fn($group) => $group->getGID(),
				$this->groupManager->getUserGroups($user)
			);
		}
		if (!$userGroupIds) {
			return self::DEFAULTS;
		}

		$rules = $this->loadRules();
		if (!$rules) {
			return self::DEFAULTS;
		}

		// The lowest priority wins; ties retain the first matching rule.
		$bestRule = null;
		$bestPriority = null;

		foreach ($rules as $rule) {
			$ruleGroups = $rule['userGroups'] ?? [];
			if (!$ruleGroups || !array_intersect($ruleGroups, $userGroupIds)) {
				continue;
			}

			$priority = $this->getRulePriority($rule);
			if ($bestRule === null || $priority < $bestPriority) {
				$bestRule = $rule;
				$bestPriority = $priority;
			}
		}

		if ($bestRule !== null) {
			return $this->extractThresholds($bestRule);
		}

		return self::DEFAULTS;
	}

	/**
	 * @return array<int, array{id:string,hrGroups:string[],userGroups:string[],priority:int}>
	 */
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

	/**
	 * @param array $rules
	 * @return array<int, array<string, mixed>>
	 */
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

			$hrGroups = $rule['hrGroups'] ?? [];
			$userGroups = $rule['userGroups'] ?? [];

			$hrGroups = is_array($hrGroups) ? $hrGroups : [];
			$userGroups = is_array($userGroups) ? $userGroups : [];

			$hrGroups = $this->cleanGroupList($hrGroups);
			$userGroups = $this->cleanGroupList($userGroups);

			$sanitizedRules[] = array_merge([
				'id' => $ruleId,
				'hrGroups' => $hrGroups,
				'userGroups' => $userGroups,
			], $this->sanitizeRuleThresholds($rule));
		}

		return $sanitizedRules;
	}

	/**
	 * @param array $item
	 * @return array<string, int|float>
	 */
	private function sanitizeRuleThresholds(array $item): array {
		$defaults = self::DEFAULTS;

		return [
			'priority' => $this->clampInt($item['priority'] ?? self::PRIORITY_DEFAULT, self::PRIORITY_MIN, self::PRIORITY_MAX),
			'breakShortMinutes' => $this->clampInt($item['breakShortMinutes'] ?? $defaults['breakShortMinutes'], 0, 600),
			'breakShortHours' => $this->clampFloat($item['breakShortHours'] ?? $defaults['breakShortHours'], 0, 24),
			'breakLongMinutes' => $this->clampInt($item['breakLongMinutes'] ?? $defaults['breakLongMinutes'], 0, 600),
			'breakLongHours' => $this->clampFloat($item['breakLongHours'] ?? $defaults['breakLongHours'], 0, 24),
			'maxHours' => $this->clampFloat($item['maxHours'] ?? $defaults['maxHours'], 0, 24),
		];
	}

	/**
	 * @param array<string, int|float> $rule
	 * @return array<string, int|float>
	 */
	private function extractThresholds(array $rule): array {
		$thresholds = [];
		foreach (self::DEFAULTS as $key => $fallback) {
			$thresholds[$key] = array_key_exists($key, $rule) ? $rule[$key] : $fallback;
		}
		return $thresholds;
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private function getRulePriority(array $rule): int {
		return $this->clampInt($rule['priority'] ?? self::PRIORITY_DEFAULT, self::PRIORITY_MIN, self::PRIORITY_MAX);
	}

	/**
	 * @param array $groups
	 * @return string[]
	 */
	private function cleanGroupList(array $groups): array {
		$groups = array_values(array_filter(array_map(fn($groupId) => trim((string)$groupId), $groups), fn($groupId) => $groupId !== ''));
		$groups = array_values(array_unique($groups));

		$existingGroupIds = [];
		foreach ($groups as $groupId) {
			if ($this->groupManager->groupExists($groupId)) {
				$existingGroupIds[] = $groupId;
			}
		}
		return $existingGroupIds;
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
