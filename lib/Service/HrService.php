<?php

namespace OCA\Timesheet\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

class HrService {

	/** @var array<int, array{id:string,hrGroups:string[],userGroups:string[]}> */
	private array $rules = [];

	/** @var string[] */
	private array $hrGroups = [];

	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private IGroupManager $groupManager
	) {
		$this->rules = $this->loadRules();
		$this->hrGroups = $this->collectAllHrGroups($this->rules);
	}

	public function isHr(?string $uid = null): bool {
		if ($uid === null) {
			$currentUser = $this->userSession->getUser();
			if (!$currentUser) {
				return false;
			}
			$uid = $currentUser->getUID();
		}

		foreach ($this->rules as $rule) {
			foreach ($rule['hrGroups'] as $group) {
				if ($group === '') {
					continue;
				}
				if ($this->groupManager->isInGroup($uid, $group)) {
					return true;
				}
			}
		}

		return false;
	}

	public function getAllHrUserIds(): array {
		$hrUserIds = [];

		foreach ($this->hrGroups as $groupId) {
			if ($groupId === '') {
				continue;
			}
			$group = $this->groupManager->get($groupId);
			if (!$group) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$hrUserIds[$user->getUID()] = true;
			}
		}

		return array_keys($hrUserIds);
	}

	/**
	 * @return string[]
	 */
	public function getAllowedUserGroups(?string $hrUid = null): array {
		if ($hrUid === null) {
			$currentUser = $this->userSession->getUser();
			if (!$currentUser) {
				return [];
			}
			$hrUid = $currentUser->getUID();
		}

		$allowedGroupIds = [];

		foreach ($this->rules as $rule) {
			$isInHrGroup = false;

			foreach ($rule['hrGroups'] as $hrGroupId) {
				if ($hrGroupId === '') {
					continue;
				}
				if ($this->groupManager->isInGroup($hrUid, $hrGroupId)) {
					$isInHrGroup = true;
					break;
				}
			}

			if (!$isInHrGroup) {
				continue;
			}

			foreach ($rule['userGroups'] as $employeeGroupId) {
				if ($employeeGroupId === '') {
					continue;
				}
				$allowedGroupIds[$employeeGroupId] = true;
			}
		}

		return array_keys($allowedGroupIds);
	}

	public function canAccessUser(string $targetUid, ?string $hrUid = null): bool {
		if ($hrUid === null) {
			$currentUser = $this->userSession->getUser();
			if (!$currentUser) {
				return false;
			}
			$hrUid = $currentUser->getUID();
		}

		if ($targetUid === $hrUid) {
			return true;
		}
		if (!$this->isHr($hrUid)) {
			return false;
		}

		$allowedGroups = $this->getAllowedUserGroups($hrUid);
		foreach ($allowedGroups as $groupId) {
			if ($this->groupManager->isInGroup($targetUid, $groupId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, array{id:string,name:string}>
	 */
	public function getAccessibleUsers(?string $hrUid = null): array {
		if ($hrUid === null) {
			$user = $this->userSession->getUser();
			if (!$user) {
				return [];
			}
			$hrUid = $user->getUID();
		}

		if (!$this->isHr($hrUid)) {
			return [];
		}

		$allowedGroups = $this->getAllowedUserGroups($hrUid);
		if (!$allowedGroups) {
			return [];
		}

		$usersById = [];

		foreach ($allowedGroups as $groupId) {
			$group = $this->groupManager->get($groupId);
			if (!$group) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$userId = $user->getUID();
				$usersById[$userId] = [
					'id' => $userId,
					'name' => $user->getDisplayName(),
				];
			}
		}

		$accessibleUsers = array_values($usersById);
		usort($accessibleUsers, fn($firstUser, $secondUser) => strnatcasecmp((string)$firstUser['name'], (string)$secondUser['name']));
		return $accessibleUsers;
	}

	/**
	 * @return string[]
	 */
	public function getHrGroups(): array {
		return $this->hrGroups;
	}

	/**
	 * @return array<int, array{id:string,hrGroups:string[],userGroups:string[]}>
	 */
	private function loadRules(): array {
		$rulesJson = (string)$this->appConfig->getAppValueString('hr_access_rules');
		if (trim($rulesJson) === '') {
			return [];
		}
		$rules = json_decode($rulesJson, true);
		if (!is_array($rules) || count($rules) === 0) {
			return [];
		}
		$sanitizedRules = $this->sanitizeRules($rules);
		return $sanitizedRules ?: [];
	}

	/**
	 * @param array $rules
	 * @return array<int, array{id:string,hrGroups:string[],userGroups:string[]}>
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

			// Accept both stored key spellings for access rules.
			$hrGroups = $rule['hrGroups'] ?? $rule['hr_groups'] ?? [];
			$userGroups = $rule['userGroups'] ?? $rule['user_groups'] ?? [];

			$hrGroups = is_array($hrGroups) ? $hrGroups : [];
			$userGroups = is_array($userGroups) ? $userGroups : [];

			$hrGroups = $this->cleanGroupList($hrGroups);
			$userGroups = $this->cleanGroupList($userGroups);

			if (!$hrGroups) {
				continue;
			}

			$sanitizedRules[] = [
				'id' => $ruleId,
				'hrGroups' => $hrGroups,
				'userGroups' => $userGroups,
			];
		}

		return $sanitizedRules;
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

	/**
	 * @param array<int, array{id:string,hrGroups:string[],userGroups:string[]}> $rules
	 * @return string[]
	 */
	private function collectAllHrGroups(array $rules): array {
		$hrGroupIds = [];
		foreach ($rules as $rule) {
			foreach (($rule['hrGroups'] ?? []) as $hrGroupId) {
				$hrGroupIds[$hrGroupId] = true;
			}
		}
		return array_keys($hrGroupIds);
	}
}
