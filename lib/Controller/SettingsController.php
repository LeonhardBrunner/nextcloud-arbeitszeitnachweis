<?php

namespace OCA\Timesheet\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Timesheet\Service\MissingEntryReminderSettings;
use OCA\Timesheet\Service\MissingEntryReminderService;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\HrRuleAssignments;

class SettingsController extends Controller {
	private HrRuleAssignments $hrAssignments;

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
		private MissingEntryReminderService $missingEntryReminderService,
		private ITimeFactory $timeFactory,
		private HrService $hrService,
		private IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
		$this->hrAssignments = new HrRuleAssignments($groupManager, $userManager);
	}

	// HR manages the shared policy; CSRF protection remains enabled for both writes.
	#[NoAdminRequired]
	public function saveHrAccessRules(): DataResponse {
		$currentUser = $this->userSession->getUser();
		$currentUserId = $currentUser?->getUID();
		if (!$currentUserId || !$this->hrService->isHr($currentUserId)) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}

		$rulesJson = (string)$this->request->getParam('rules', '[]');
		$submittedRules = json_decode($rulesJson, true);
		if (!is_array($submittedRules)) {
			return new DataResponse(['message' => 'Invalid rules'], 400);
		}
		// Stable rule IDs identify enrollment; two rules must never share that state.
		$submittedRuleIds = [];
		foreach ($submittedRules as $rule) {
			$ruleId = is_array($rule) ? trim((string)($rule['id'] ?? '')) : '';
			if ($ruleId !== '' && isset($submittedRuleIds[$ruleId])) {
				return new DataResponse(['message' => 'Duplicate rule identifiers'], 400);
			}
			$submittedRuleIds[$ruleId] = true;
		}

		$previousRulesJson = $this->appConfig->getAppValueString('hr_access_rules');
		$previousRules = json_decode($previousRulesJson, true);
		$previousAssignmentRules = is_array($previousRules) ? $previousRules : [];
		$previousRules = is_array($previousRules) ? $this->sanitizeRules($previousRules) : [];

		try {
			$submittedRules = $this->validateAssignmentSettings($submittedRules, $previousAssignmentRules);
			$submittedRules = $this->validateReminderSettings($submittedRules, $previousRules);
			$sanitizedRules = $this->sanitizeRules($submittedRules, true);
			$hasHrAssignment = $this->hasRemainingHrAssignment($sanitizedRules);
		} catch (\InvalidArgumentException $invalidSettings) {
			return new DataResponse(['message' => $invalidSettings->getMessage()], 400);
		} catch (\Throwable $validationError) {
			return new DataResponse(['message' => 'Saving reminder settings failed'], 500);
		}
		// A group or enabled individual keeps future policy changes reachable.
		if (!$hasHrAssignment) {
			return new DataResponse(['message' => 'At least one HR group or enabled HR account must remain assigned.'], 400);
		}

		// Each employee group may belong to only one access rule.
		$duplicateGroupIds = $this->findDuplicateUserGroups($sanitizedRules);
		if (!empty($duplicateGroupIds)) {
			return new DataResponse([
				'message' => 'Duplicate employee groups in rules',
				'groups' => array_values($duplicateGroupIds),
			], 400);
		}

		// Record the initial membership before an enabled rule becomes visible to the job.
		try {
			$this->missingEntryReminderService->prepareRuleChanges($previousRules, $sanitizedRules);
			$this->appConfig->setAppValueString('hr_access_rules', json_encode($sanitizedRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		} catch (\Throwable $saveError) {
			return new DataResponse(['message' => 'Saving reminder settings failed'], 500);
		}

		return new DataResponse(['rules' => $sanitizedRules]);
	}

	/** Older tabs may omit the new fields; omission never resets restricted mail delivery. */
	private function validateAssignmentSettings(array $rules, array $previousRules): array {
		$previousById = [];
		foreach ($previousRules as $previousRule) {
			if (is_array($previousRule) && is_scalar($previousRule['id'] ?? null)) {
				$previousById[trim((string)$previousRule['id'])] = $previousRule;
			}
		}
		foreach ($rules as &$rule) {
			if (!is_array($rule) || trim((string)($rule['id'] ?? '')) === '') {
				continue;
			}
			$previous = $previousById[trim((string)$rule['id'])] ?? [];
			foreach (['hrUsers', 'mailRecipients'] as $field) {
				if (!array_key_exists($field, $rule) && array_key_exists($field, $previous)) {
					$rule[$field] = $previous[$field];
				}
			}
			$rule = array_replace($rule, $this->hrAssignments->validateForSave($rule));
		}
		unset($rule);
		return $rules;
	}

	private function hasRemainingHrAssignment(array $rules): bool {
		foreach ($rules as $rule) {
			if ($rule['hrGroups'] !== []) {
				return true;
			}
			foreach ($rule['hrUsers'] as $userId) {
				if ($this->userManager->get($userId)?->isEnabled()) {
					return true;
				}
			}
		}
		return false;
	}

	/** Bounded account search is available only to accounts that manage HR rules. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchPeople(): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if (!$userId || !$this->hrService->isHr($userId)) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}
		$search = $this->request->getParam('search', '');
		$purpose = $this->request->getParam('purpose', 'responsible');
		if (!is_string($search) || mb_strlen($search) > 200 || !in_array($purpose, ['responsible', 'mail'], true)) {
			return new DataResponse(['message' => 'Invalid account search'], 400);
		}
		$search = trim($search);
		if ($purpose === 'responsible' && mb_strlen($search) < 2) {
			return new DataResponse(['users' => [], 'hasMore' => false]);
		}
		try {
			$groupIds = $this->request->getParam('hrGroups', []);
			$directUserIds = $this->request->getParam('hrUsers', []);
			if (!is_array($groupIds) || !is_array($directUserIds)) {
				return new DataResponse(['message' => 'Invalid account search'], 400);
			}
			$candidates = $purpose === 'mail'
				? array_map(fn(string $id) => $this->userManager->get($id), $this->hrAssignments->getResponsibleUserIds(['hrGroups' => $groupIds, 'hrUsers' => $directUserIds]))
				: $this->userManager->searchDisplayName($search, 21, 0);
			$users = [];
			$needle = mb_strtolower($search);
			foreach ($candidates as $candidate) {
				if (!$candidate || !$candidate->isEnabled()) {
					continue;
				}
				$name = (string)$candidate->getDisplayName();
				$id = $candidate->getUID();
				if ($needle !== '' && !str_contains(mb_strtolower($name), $needle) && !str_contains(mb_strtolower($id), $needle)) {
					continue;
				}
				$users['user:' . $id] = ['id' => $id, 'name' => $name, 'enabled' => true];
			}
			$users = array_values($users);
			usort($users, static fn(array $first, array $second): int => strnatcasecmp($first['name'], $second['name']) ?: strcmp($first['id'], $second['id']));
			$hasMore = count($users) > 20 || ($purpose === 'responsible' && count($candidates) > 20);
			return new DataResponse(['users' => array_slice($users, 0, 20), 'hasMore' => $hasMore]);
		} catch (\Throwable $searchError) {
			return new DataResponse(['message' => 'Accounts could not be loaded. Try again.'], 500);
		}
	}

	/** @return array<int, mixed> */
	private function validateReminderSettings(array $rules, array $previousRules): array {
		$previousSettingsByRule = [];
		foreach ($previousRules as $previousRule) {
			$previousSettingsByRule[$previousRule['id']] = $previousRule['missingEntryReminders'];
		}
		$todayDate = $this->timeFactory->getDateTime('today', new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');

		foreach ($rules as &$rule) {
			if (!is_array($rule) || trim((string)($rule['id'] ?? '')) === '') {
				continue;
			}
			$ruleId = trim((string)$rule['id']);
			$rule['missingEntryReminders'] = MissingEntryReminderSettings::validateForSave(
				$rule['missingEntryReminders'] ?? null,
				$todayDate,
				$previousSettingsByRule[$ruleId] ?? null
			);
		}
		unset($rule);
		return $rules;
	}

	private function sanitizeRules(array $rules, bool $assignmentsValidated = false): array {
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

			$userGroups = $this->cleanGroupList($userGroups);

			$sanitizedRules[] = array_merge([
				'id' => $ruleId,
				...($assignmentsValidated ? array_intersect_key($rule, array_flip(['hrGroups', 'hrUsers', 'mailRecipients'])) : $this->hrAssignments->normalize($rule)),
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

	#[NoAdminRequired]
	public function saveSpecialDaysCheck(): DataResponse {
		$currentUser = $this->userSession->getUser();
		$currentUserId = $currentUser?->getUID();
		if (!$currentUserId || !$this->hrService->isHr($currentUserId)) {
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
