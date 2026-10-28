<?php

namespace OCA\Timesheet\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;

class SettingsController extends Controller {

	private const GROUP_DEFAULTS = [
		'priority' => 1,
		'breakShortMinutes' => 30,
		'breakShortHours' => 6,
		'breakLongMinutes' => 45,
		'breakLongHours' => 9,
		'maxHours' => 10,
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private \OCA\Timesheet\Service\MonthlyBalanceService $monthlyBalanceService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @AdminRequired
	 * @CSRFCheck
	 */
	public function saveAdmin(string $hrGroups, string $hrUserGroup): DataResponse {
		$hrGroups = trim($hrGroups);
		$hrUserGroup = trim($hrUserGroup);

		$this->appConfig->setAppValueString('hr_groups', $hrGroups);
		$this->appConfig->setAppValueString('hr_user_group', $hrUserGroup);

		return new DataResponse(['status' => 'success'], Http::STATUS_OK);
	}

	public function updateHrGroups(): DataResponse {
		$groupId = $this->request->getParam('group');
		$removeGroup = $this->request->getParam('remove') === '1';
		$groupsJson = $this->appConfig->getAppValueString('hr_groups');
		$groupIds = json_decode($groupsJson, true) ?: [];

		if ($removeGroup) {
			$groupIds = array_values(array_filter($groupIds, fn($existingGroupId) => $existingGroupId !== $groupId));
		} else {
			if (!in_array($groupId, $groupIds, true)) {
				$groupIds[] = $groupId;
			}
		}
		$this->appConfig->setAppValueString('hr_groups', json_encode($groupIds));

		return new DataResponse(['hr_groups' => $groupIds]);
	}

	public function updateHrUserGroups(): DataResponse {
		$groupId = $this->request->getParam('group');
		$removeGroup = $this->request->getParam('remove') === '1';
		$groupsJson = $this->appConfig->getAppValueString('hr_user_groups');
		$groupIds = json_decode($groupsJson, true) ?: [];

		if ($removeGroup) {
			$groupIds = array_values(array_filter($groupIds, fn($existingGroupId) => $existingGroupId !== $groupId));
		} else {
			if (!in_array($groupId, $groupIds, true)) {
				$groupIds[] = $groupId;
			}
		}
		$this->appConfig->setAppValueString('hr_user_groups', json_encode($groupIds));

		return new DataResponse(['hr_user_groups' => $groupIds]);
	}

	public function saveHrAccessRules(): DataResponse {
		$currentUser = $this->userSession->getUser();
		$currentUserId = $currentUser?->getUID();
		if (!$currentUserId || !$this->groupManager->isAdmin($currentUserId)) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}

		$rulesJson = (string)$this->request->getParam('rules', '[]');
		$submittedRules = json_decode($rulesJson, true);
		if (!is_array($submittedRules)) {
			return new DataResponse(['message' => 'Invalid rules'], 400);
		}

		$sanitizedRules = $this->sanitizeRules($submittedRules);
		// Each employee group may belong to only one access rule.
		$duplicateGroupIds = $this->findDuplicateUserGroups($sanitizedRules);
		if (!empty($duplicateGroupIds)) {
			return new DataResponse([
				'message' => 'Duplicate employee groups in rules',
				'groups' => array_values($duplicateGroupIds),
			], 400);
		}

		$this->appConfig->setAppValueString('hr_access_rules', json_encode($sanitizedRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		return new DataResponse(['rules' => $sanitizedRules]);
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
		$defaults = self::GROUP_DEFAULTS;

		return [
			'priority' => $this->clampInt($item['priority'] ?? $defaults['priority'], 0, 9999),
			'breakShortMinutes' => $this->clampInt($item['breakShortMinutes'] ?? $defaults['breakShortMinutes'], 0, 600),
			'breakShortHours' => $this->clampFloat($item['breakShortHours'] ?? $defaults['breakShortHours'], 0, 24),
			'breakLongMinutes' => $this->clampInt($item['breakLongMinutes'] ?? $defaults['breakLongMinutes'], 0, 600),
			'breakLongHours' => $this->clampFloat($item['breakLongHours'] ?? $defaults['breakLongHours'], 0, 24),
			'maxHours' => $this->clampFloat($item['maxHours'] ?? $defaults['maxHours'], 0, 24),
		];
	}

	private function cleanGroupList(array $groups): array {
		$groups = array_values(array_filter(array_map(fn($groupId) => trim((string)$groupId), $groups), fn($trimmedGroupId) => $trimmedGroupId !== ''));
		$groups = array_values(array_unique($groups));

		$existingGroupIds = [];
		foreach ($groups as $groupId) {
			if ($this->groupManager->groupExists($groupId)) {
				$existingGroupIds[] = $groupId;
			}
		}
		return $existingGroupIds;
	}

	/**
	 * @param array<int, array<string, mixed>> $rules
	 * @return string[]
	 */
	private function findDuplicateUserGroups(array $rules): array {
		$seenGroupIds = [];
		$duplicateGroupIds = [];
		foreach ($rules as $rule) {
			$userGroups = isset($rule['userGroups']) && is_array($rule['userGroups']) ? $rule['userGroups'] : [];
			foreach ($userGroups as $groupId) {
				$groupKey = (string)$groupId;
				if ($groupKey === '') {
					continue;
				}
				if (isset($seenGroupIds[$groupKey])) {
					$duplicateGroupIds[$groupKey] = $groupKey;
				} else {
					$seenGroupIds[$groupKey] = true;
				}
			}
		}
		return array_values($duplicateGroupIds);
	}

	/**
	 * @AdminRequired
	 * @CSRFCheck
	 */
	public function saveSpecialDaysCheck(): DataResponse {
		$currentUser = $this->userSession->getUser();
		$currentUserId = $currentUser?->getUID();
		if (!$currentUserId || !$this->groupManager->isAdmin($currentUserId)) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}

		$requestedValue = (string)$this->request->getParam('specialDaysCheck', 'false');
		$isEnabled = filter_var($requestedValue, FILTER_VALIDATE_BOOLEAN);

		$wasEnabled = $this->appConfig->getAppValueString('specialdays_check', '0') === '1';
		$this->appConfig->setAppValueString('specialdays_check', $isEnabled ? '1' : '0');
		if ($wasEnabled !== $isEnabled) {
			$this->monthlyBalanceService->markAllDirty();
		}

		return new DataResponse(['check' => $isEnabled]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function loadSpecialDaysCheck(): DataResponse {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser) {
			return new DataResponse(['message' => 'Unauthorized'], 401);
		}

		$savedValue = $this->appConfig->getAppValueString('specialdays_check', '0');
		$isEnabled = $savedValue === '1';

		return new DataResponse(['check' => $isEnabled]);
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
