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
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Timesheet\Service\MissingEntryReminderSettings;
use OCA\Timesheet\Service\EmployeeBalanceNotificationSettings;
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
		private \OCP\IAppConfig $sharedAppConfig,
		private ILockingProvider $lockingProvider,
	) {
		parent::__construct($appName, $request);
		$this->hrAssignments = new HrRuleAssignments($groupManager, $userManager);
	}

	// Access assignments belong to administrators; both save paths retain CSRF protection.
	public function saveHrAccessRules(): DataResponse {
		$currentUserId = $this->userSession->getUser()?->getUID();
		if (!$currentUserId || !$this->groupManager->isAdmin($currentUserId)) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}
		return $this->saveScopedRules('assignments', $currentUserId);
	}

	#[NoAdminRequired]
	public function saveHrPolicyRules(): DataResponse {
		$currentUserId = $this->userSession->getUser()?->getUID();
		if (!$currentUserId || !$this->hrService->isHr($currentUserId)) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}
		$missingSection = new \stdClass();
		$policySection = $this->request->getParam('policySection', $missingSection);
		if ($policySection === $missingSection) {
			$policySection = null;
		} elseif (!is_string($policySection) || !in_array($policySection, ['timeRules', 'notifications'], true)) {
			return new DataResponse(['message' => 'Invalid policy section'], 400);
		}
		return $this->saveScopedRules('policy', $currentUserId, $policySection);
	}

	private function saveScopedRules(string $scope, string $currentUserId, ?string $policySection = null): DataResponse {
		$rulesJson = $this->request->getParam('rules');
		$submittedRules = is_string($rulesJson) ? json_decode($rulesJson, true) : null;
		if (!is_array($submittedRules) || !array_is_list($submittedRules) || !str_starts_with(ltrim($rulesJson), '[')) {
			return new DataResponse(['message' => 'Invalid rules'], 400);
		}
		$expectedRevision = $this->request->getParam('rulesRevision');
		if (!is_string($expectedRevision) || preg_match('/\A[0-9a-f]{64}\z/i', $expectedRevision) !== 1) {
			return new DataResponse(['message' => 'Invalid rules revision'], 400);
		}
		// Stable identifiers preserve enrollment when either editor saves the same rule.
		$submittedById = [];
		foreach ($submittedRules as $rule) {
			if (!is_array($rule) || !is_scalar($rule['id'] ?? null) || trim((string)$rule['id']) === '') {
				return new DataResponse(['message' => 'Invalid rules'], 400);
			}
			$ruleId = trim((string)$rule['id']);
			if (isset($submittedById[$ruleId])) {
				return new DataResponse(['message' => 'Duplicate rule identifiers'], 400);
			}
			$submittedById[$ruleId] = $rule;
		}

		$lockKey = hash('sha256', 'timesheet:hr-access-rules');
		try {
			$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return $this->currentRulesConflict($scope, $currentUserId);
		} catch (\Throwable $saveError) {
			return new DataResponse(['message' => 'Saving reminder settings failed'], 500);
		}

		try {
			// Authentication can preload app settings; discard that snapshot under the shared lock.
			$this->sharedAppConfig->clearCache();
			if (!$this->canSaveScope($scope, $currentUserId)) {
				return new DataResponse(['message' => 'Forbidden'], 403);
			}
			$previousRulesJson = $this->appConfig->getAppValueString('hr_access_rules');
			$decodedRules = json_decode($previousRulesJson, true);
			$previousRules = is_array($decodedRules) ? $this->sanitizeRules($decodedRules) : [];
			if (!hash_equals(hash('sha256', $previousRulesJson), strtolower($expectedRevision))) {
				return $this->rulesConflict($previousRules, $previousRulesJson);
			}

			$mergedRules = $scope === 'assignments'
				? $this->mergeAssignmentChanges($submittedById, $previousRules)
				: $this->mergePolicyChanges($submittedById, $previousRules, $policySection);
			$sanitizedRules = $this->sanitizeRules($mergedRules, true);
			$duplicateGroupIds = $this->findDuplicateUserGroups($sanitizedRules);
			if ($duplicateGroupIds !== []) {
				return new DataResponse(['message' => 'Duplicate employee groups in rules', 'groups' => array_values($duplicateGroupIds)], 400);
			}
			// Enrollment finishes before an updated employee assignment becomes visible to the job.
			$this->missingEntryReminderService->prepareRuleChanges($previousRules, $sanitizedRules);
			$savedRulesJson = json_encode($sanitizedRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$this->appConfig->setAppValueString('hr_access_rules', $savedRulesJson);
			return new DataResponse(['rules' => $sanitizedRules, 'rulesRevision' => hash('sha256', $savedRulesJson)]);
		} catch (\InvalidArgumentException $invalidSettings) {
			return new DataResponse(['message' => $invalidSettings->getMessage()], 400);
		} catch (\Throwable $saveError) {
			return new DataResponse(['message' => 'Saving reminder settings failed'], 500);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function canSaveScope(string $scope, string $userId): bool {
		if ($scope === 'assignments') {
			return $this->groupManager->isAdmin($userId);
		}
		return (new HrService($this->appConfig, $this->userSession, $this->groupManager, $this->userManager))->isHr($userId);
	}

	private function currentRulesConflict(string $scope, string $userId): DataResponse {
		try {
			$this->sharedAppConfig->clearCache();
			if (!$this->canSaveScope($scope, $userId)) {
				return new DataResponse(['message' => 'Forbidden'], 403);
			}
			$rawRules = $this->appConfig->getAppValueString('hr_access_rules');
			$rules = json_decode($rawRules, true);
			return $this->rulesConflict(is_array($rules) ? $this->sanitizeRules($rules) : [], $rawRules);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Saving reminder settings failed'], 500);
		}
	}

	private function rulesConflict(array $rules, string $rawRules): DataResponse {
		return new DataResponse(['code' => 'rules_conflict', 'rules' => $rules, 'rulesRevision' => hash('sha256', $rawRules)], 409);
	}

	/** Assignment edits cannot alter existing time rules or broaden restricted mail delivery. */
	private function mergeAssignmentChanges(array $submittedById, array $previousRules): array {
		$previousById = array_column($previousRules, null, 'id');
		$mergedRules = [];
		foreach ($submittedById as $ruleId => $submitted) {
			$previous = $previousById[$ruleId] ?? $this->sanitizeRules([['id' => $ruleId]])[0];
			$assignments = $this->hrAssignments->validateForSave([
				'hrGroups' => $submitted['hrGroups'] ?? $previous['hrGroups'],
				'hrUsers' => $submitted['hrUsers'] ?? $previous['hrUsers'],
			]);
			$mergedRules[] = array_replace($previous, [
				'id' => (string)$ruleId,
				'hrGroups' => $assignments['hrGroups'],
				'hrUsers' => $assignments['hrUsers'],
				'userGroups' => $this->cleanGroupList(is_array($submitted['userGroups'] ?? null) ? $submitted['userGroups'] : []),
				'priority' => $this->sanitizeRuleThresholds($submitted)['priority'],
			]);
		}
		return $mergedRules;
	}

	/** Each HR editor owns its fields; older clients retain the complete policy writer. */
	private function mergePolicyChanges(array $submittedById, array $previousRules, ?string $policySection): array {
		$previousIds = array_column($previousRules, 'id');
		if (count($submittedById) !== count($previousIds) || array_diff(array_keys($submittedById), $previousIds) !== []) {
			throw new \InvalidArgumentException('HR may only change existing rule settings.');
		}
		$timeFields = ['breakShortMinutes', 'breakShortHours', 'breakLongMinutes', 'breakLongHours', 'maxHours'];
		$notificationFields = ['missingEntryReminders', 'employeeBalanceNotifications'];
		$policyFields = match ($policySection) {
			'timeRules' => $timeFields,
			'notifications' => $notificationFields,
			default => array_merge($timeFields, $notificationFields),
		};
		$mergedRules = [];
		foreach ($previousRules as $previous) {
			$submitted = $submittedById[$previous['id']];
			$merged = array_replace($previous, array_intersect_key($submitted, array_flip($policyFields)));
			if ($policySection !== 'timeRules') {
				$merged['employeeBalanceNotifications'] = EmployeeBalanceNotificationSettings::validateForSave($merged['employeeBalanceNotifications']);
			}
			if ($policySection !== 'timeRules' && array_key_exists('mailRecipients', $submitted) && $submitted['mailRecipients'] !== $previous['mailRecipients']) {
				$validated = $this->hrAssignments->validateForSave(array_replace($previous, ['mailRecipients' => $submitted['mailRecipients']]));
				$merged['mailRecipients'] = $validated['mailRecipients'];
			}
			$mergedRules[] = $merged;
		}
		return $policySection === 'timeRules' ? $mergedRules : $this->validateReminderSettings($mergedRules, $previousRules);
	}

	/** Directory searches follow the editor's scope; mail candidates use stored assignments. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchPeople(): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		$search = $this->request->getParam('search', '');
		$purpose = $this->request->getParam('purpose', 'responsible');
		$isAllowed = $userId && ($purpose === 'responsible' ? $this->groupManager->isAdmin($userId) : $this->hrService->isHr($userId));
		if (!$isAllowed) {
			return new DataResponse(['message' => 'Forbidden'], 403);
		}
		if (!is_string($search) || mb_strlen($search) > 200 || !in_array($purpose, ['responsible', 'mail'], true)) {
			return new DataResponse(['message' => 'Invalid account search'], 400);
		}
		$search = trim($search);
		if ($purpose === 'responsible' && mb_strlen($search) < 2) {
			return new DataResponse(['users' => [], 'hasMore' => false]);
		}
		try {
			if ($purpose === 'mail') {
				$ruleId = $this->request->getParam('ruleId');
				if (!is_string($ruleId) || trim($ruleId) === '') {
					return new DataResponse(['message' => 'Invalid account search'], 400);
				}
				$storedRules = json_decode($this->appConfig->getAppValueString('hr_access_rules'), true);
				$storedById = array_column(is_array($storedRules) ? $this->sanitizeRules($storedRules) : [], null, 'id');
				if (!isset($storedById[trim($ruleId)])) {
					return new DataResponse(['message' => 'Invalid account search'], 400);
				}
			}
			if ($purpose === 'mail') {
				$rule = $storedById[trim($ruleId)];
				$availableIds = array_diff($this->hrAssignments->getResponsibleUserIds($rule), $rule['mailRecipients']['userIds']);
				$candidates = array_map(fn(string $id) => $this->userManager->get($id), $availableIds);
			} else {
				$candidates = $this->userManager->searchDisplayName($search, 21, 0);
			}
			$users = [];
			$needle = mb_strtolower($search);
			$namePrefixPattern = '/(?:^|[^\p{L}\p{M}\p{N}])' . preg_quote($needle, '/') . '/u';
			foreach ($candidates as $candidate) {
				if (!$candidate || !$candidate->isEnabled()) {
					continue;
				}
				$name = (string)$candidate->getDisplayName();
				$id = $candidate->getUID();
				if ($needle !== '') {
					$normalizedName = mb_strtolower($name);
					$normalizedId = mb_strtolower($id);
					$matches = $purpose === 'mail'
						? preg_match($namePrefixPattern, $normalizedName) === 1 || str_starts_with($normalizedId, $needle)
						: str_contains($normalizedName, $needle) || str_contains($normalizedId, $needle);
					if (!$matches) {
						continue;
					}
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
