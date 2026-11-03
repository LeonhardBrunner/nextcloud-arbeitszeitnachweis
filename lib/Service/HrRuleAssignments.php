<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

/** Keeps HR access assignments separate from the recipients of each rule's emails. */
class HrRuleAssignments {
	public function __construct(private IGroupManager $groupManager, private IUserManager $userManager) {
	}

	/** @return array{hrGroups:string[],hrUsers:string[],mailRecipients:array{mode:string,userIds:string[]}} */
	public function normalize(array $rule): array {
		return [
			'hrGroups' => $this->existingGroups($rule['hrGroups'] ?? $rule['hr_groups'] ?? []),
			'hrUsers' => $this->existingUsers($rule['hrUsers'] ?? []),
			'mailRecipients' => $this->normalizeMailRecipients($rule),
		];
	}

	/** New account selections are validated before any setting is saved. */
	public function validateForSave(array $rule): array {
		$hrUsers = array_key_exists('hrUsers', $rule)
			? $this->validateUsers($rule['hrUsers'], 'Invalid HR account selection.') : [];
		$mailRecipients = ['mode' => 'all', 'userIds' => []];
		if (array_key_exists('mailRecipients', $rule)) {
			$mail = $rule['mailRecipients'];
			if (!is_array($mail) || !in_array($mail['mode'] ?? null, ['all', 'selected'], true)) {
				throw new \InvalidArgumentException('Invalid mail recipient settings.');
			}
			$userIds = $this->validateUsers(array_key_exists('userIds', $mail) ? $mail['userIds'] : [], 'Invalid mail recipient settings.');
			$mailRecipients = ['mode' => $mail['mode'], 'userIds' => $mail['mode'] === 'selected' ? $userIds : []];
		}
		// Keep validated IDs verbatim; a later transient lookup must not silently drop an assignment.
		$assignments = [
			'hrGroups' => $this->existingGroups($rule['hrGroups'] ?? $rule['hr_groups'] ?? []),
			'hrUsers' => $hrUsers,
			'mailRecipients' => $mailRecipients,
		];
		if ($assignments['mailRecipients']['mode'] === 'selected') {
			foreach ($assignments['mailRecipients']['userIds'] as $userId) {
				$isResponsible = in_array($userId, $hrUsers, true);
				foreach ($assignments['hrGroups'] as $groupId) {
					$isResponsible = $isResponsible || $this->groupManager->isInGroup($userId, $groupId);
				}
				if (!$isResponsible) {
					throw new \InvalidArgumentException('Mail recipients must be responsible for this rule.');
				}
			}
		}
		return $assignments;
	}

	public function isResponsible(array $rule, string $userId): bool {
		if ($this->findUser($userId) === null) {
			return false;
		}
		$assignments = $this->normalize($rule);
		if (in_array($userId, $assignments['hrUsers'], true)) {
			return true;
		}
		foreach ($assignments['hrGroups'] as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId)) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	public function getResponsibleUserIds(array $rule): array {
		$assignments = $this->normalize($rule);
		$userIds = [];
		foreach ($assignments['hrGroups'] as $groupId) {
			$group = $this->groupManager->get($groupId);
			if (!$group) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$userId = $user->getUID();
				if ($this->findUser($userId) !== null) {
					$userIds['user:' . $userId] = $userId;
				}
			}
		}
		foreach ($assignments['hrUsers'] as $userId) {
			$userIds['user:' . $userId] = $userId;
		}
		return array_values($userIds);
	}

	/** Mail selection never grants access; current responsibility and account state are rechecked. */
	public function getMailRecipientUserIds(array $rule): array {
		$assignments = $this->normalize($rule);
		$selectedUserIds = $assignments['mailRecipients']['mode'] === 'all'
			? $this->getResponsibleUserIds($assignments) : $assignments['mailRecipients']['userIds'];
		$recipients = [];
		foreach ($selectedUserIds as $userId) {
			try {
				$user = $this->findUser($userId);
				if ($user !== null && $user->isEnabled() && $this->isResponsible($assignments, $userId)) {
					$recipients[] = $userId;
				}
			} catch (\Throwable) {
				// An unavailable account must not block delivery to other responsible recipients.
			}
		}
		return $recipients;
	}

	private function normalizeMailRecipients(array $rule): array {
		// Missing settings preserve legacy delivery; malformed explicit settings never expand it.
		if (!array_key_exists('mailRecipients', $rule)) {
			return ['mode' => 'all', 'userIds' => []];
		}
		$mail = $rule['mailRecipients'];
		if (!is_array($mail) || !in_array($mail['mode'] ?? null, ['all', 'selected'], true)) {
			return ['mode' => 'selected', 'userIds' => []];
		}
		$userIds = array_key_exists('userIds', $mail) ? $mail['userIds'] : [];
		if (!$this->isStringList($userIds)) {
			return ['mode' => 'selected', 'userIds' => []];
		}
		return [
			'mode' => $mail['mode'],
			'userIds' => $mail['mode'] === 'selected' ? $this->existingUsers($userIds) : [],
		];
	}

	private function existingGroups(mixed $groupIds): array {
		return array_values(array_filter($this->identifiers($groupIds), fn(string $groupId): bool => $this->groupManager->groupExists($groupId)));
	}

	private function existingUsers(mixed $userIds): array {
		return array_values(array_filter($this->identifiers($userIds), fn(string $userId): bool => $this->findUser($userId) !== null));
	}

	private function validateUsers(mixed $userIds, string $message): array {
		if (!$this->isStringList($userIds)) {
			throw new \InvalidArgumentException($message);
		}
		foreach ($userIds as $userId) {
			if (trim($userId) === '' || $this->userManager->get(trim($userId)) === null) {
				throw new \InvalidArgumentException($message);
			}
		}
		return $this->identifiers($userIds);
	}

	private function findUser(string $userId): ?object {
		try {
			return $this->userManager->get($userId);
		} catch (\Throwable) {
			// Read paths fail closed; saving uses strict lookups and propagates the error instead.
			return null;
		}
	}

	private function isStringList(mixed $values): bool {
		return is_array($values) && array_is_list($values)
			&& count(array_filter($values, 'is_string')) === count($values);
	}

	private function identifiers(mixed $values): array {
		if (!is_array($values)) {
			return [];
		}
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $value): string => is_string($value) ? trim($value) : '', $values
		), static fn(string $value): bool => $value !== '')));
	}
}
