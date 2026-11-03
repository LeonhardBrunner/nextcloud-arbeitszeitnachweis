<?php

namespace OCA\Timesheet\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/** Resolves HR access and independently scoped email delivery from the same rules. */
class HrService {
	private array $rules;
	private HrRuleAssignments $assignments;

	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
		$this->assignments = new HrRuleAssignments($groupManager, $userManager);
		$this->rules = $this->loadRules();
	}

	public function isHr(?string $uid = null): bool {
		$userId = $this->resolveUserId($uid);
		if ($userId === null) {
			return false;
		}
		foreach ($this->rules as $rule) {
			if ($this->assignments->isResponsible($rule, $userId)) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	public function getAllHrUserIds(): array {
		$userIds = [];
		foreach ($this->rules as $rule) {
			foreach ($this->assignments->getResponsibleUserIds($rule) as $userId) {
				$userIds['user:' . $userId] = $userId;
			}
		}
		return array_values($userIds);
	}

	/** @return string[] */
	public function getMailRecipientUserIds(): array {
		$userIds = [];
		foreach ($this->rules as $rule) {
			foreach ($this->assignments->getMailRecipientUserIds($rule) as $userId) {
				$userIds['user:' . $userId] = $userId;
			}
		}
		return array_values($userIds);
	}

	/** @return string[] */
	public function getAllowedUserGroups(?string $hrUid = null): array {
		return $this->allowedEmployeeGroups($hrUid, false);
	}

	public function canAccessUser(string $targetUid, ?string $hrUid = null): bool {
		$userId = $this->resolveUserId($hrUid);
		if ($userId === null) {
			return false;
		}
		if ($targetUid === $userId) {
			return true;
		}
		foreach ($this->getAllowedUserGroups($userId) as $groupId) {
			if ($this->groupManager->isInGroup($targetUid, $groupId)) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array{id:string,name:string}> */
	public function getAccessibleUsers(?string $hrUid = null): array {
		return $this->usersInGroups($this->allowedEmployeeGroups($hrUid, false));
	}

	/** Email lists exclude rules whose mail selection omits this otherwise responsible HR user. */
	public function getMailAccessibleUsers(?string $hrUid = null): array {
		return $this->usersInGroups($this->allowedEmployeeGroups($hrUid, true));
	}

	/** @return string[] */
	public function getHrGroups(): array {
		$groupIds = [];
		foreach ($this->rules as $rule) {
			foreach ($rule['hrGroups'] as $groupId) {
				$groupIds['group:' . $groupId] = $groupId;
			}
		}
		return array_values($groupIds);
	}

	private function resolveUserId(?string $userId): ?string {
		return $userId ?? $this->userSession->getUser()?->getUID();
	}

	/** @return string[] */
	private function allowedEmployeeGroups(?string $hrUserId, bool $forMail): array {
		$userId = $this->resolveUserId($hrUserId);
		if ($userId === null) {
			return [];
		}
		$groupIds = [];
		foreach ($this->rules as $rule) {
			if (!$this->assignments->isResponsible($rule, $userId)
				|| ($forMail && !in_array($userId, $this->assignments->getMailRecipientUserIds($rule), true))) {
				continue;
			}
			foreach ($rule['userGroups'] as $groupId) {
				$groupIds['group:' . $groupId] = $groupId;
			}
		}
		return array_values($groupIds);
	}

	/** @return array<int,array{id:string,name:string}> */
	private function usersInGroups(array $groupIds): array {
		$usersById = [];
		foreach ($groupIds as $groupId) {
			$group = $this->groupManager->get($groupId);
			if (!$group) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$userId = $user->getUID();
				$usersById['user:' . $userId] = ['id' => $userId, 'name' => $user->getDisplayName()];
			}
		}
		$users = array_values($usersById);
		usort($users, static fn(array $first, array $second): int => strnatcasecmp($first['name'], $second['name']));
		return $users;
	}

	private function loadRules(): array {
		$decoded = json_decode((string)$this->appConfig->getAppValueString('hr_access_rules'), true);
		if (!is_array($decoded)) {
			return [];
		}
		$rules = [];
		foreach ($decoded as $rule) {
			if (!is_array($rule) || !is_scalar($rule['id'] ?? null)) {
				continue;
			}
			$ruleId = trim((string)$rule['id']);
			if ($ruleId === '') {
				continue;
			}
			$assignments = $this->assignments->normalize($rule);
			if ($assignments['hrGroups'] === [] && $assignments['hrUsers'] === []) {
				continue;
			}
			$employeeGroups = $rule['userGroups'] ?? $rule['user_groups'] ?? [];
			$employeeGroups = is_array($employeeGroups) ? $employeeGroups : [];
			$employeeGroups = array_values(array_unique(array_filter(array_map(
				static fn(mixed $groupId): string => is_string($groupId) ? trim($groupId) : '', $employeeGroups
			), fn(string $groupId): bool => $groupId !== '' && $this->groupManager->groupExists($groupId))));
			$rules[] = ['id' => $ruleId, 'userGroups' => $employeeGroups, ...$assignments];
		}
		return $rules;
	}
}
