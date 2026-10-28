<?php

namespace OCA\Timesheet\Controller;

use OCA\Timesheet\Db\HrPreferencesStore;
use OCA\Timesheet\Db\UserConfigMapper;
use OCA\Timesheet\Service\GroupRuleService;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\MonthlyBalanceService;
use OCP\AppFramework\Annotations\NoAdminRequired;
use OCP\AppFramework\Annotations\NoCSRFRequired;
use OCP\AppFramework\Annotations\Route;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ConfigController extends Controller {

	private IUserSession $userSession;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		private HrPreferencesStore $hrPreferencesStore,
		private HrService $hrService,
		private UserConfigMapper $userConfigMapper,
		private GroupRuleService $groupRuleService,
		private MonthlyBalanceService $monthlyBalanceService,
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/hr/config/{userId}", methods={"GET"})
	 *
	 * Returns the configuration for the given user (dailyMin and state).
	 */
	public function getUserConfig(string $userId): DataResponse {
		$this->assertConfigAccess($userId);
		$data = $this->userConfigMapper->getConfigData($userId);
		return new DataResponse($data, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/hr/config/{userId}", methods={"PUT"})
	 *
	 * Creates or updates the configuration for the given user.
	 * Expects JSON body with fields "dailyMin" and "state".
	 */
	public function setUserConfig(string $userId, int $dailyMin, string $state): DataResponse {
		$this->assertConfigAccess($userId);
		$previousConfig = $this->userConfigMapper->getConfigData($userId);
		$data = $this->userConfigMapper->upsertConfigData($userId, $dailyMin, $state);

		$previousDailyMinutes = isset($previousConfig['dailyMin']) ? (int)$previousConfig['dailyMin'] : null;
		$previousState = trim((string)($previousConfig['state'] ?? ''));
		if ($previousState === 'null') {
			$previousState = '';
		}
		$updatedState = trim((string)$state);
		if ($updatedState === 'null') {
			$updatedState = '';
		}

		if ($previousDailyMinutes !== (int)$dailyMin || $previousState !== $updatedState) {
			$this->monthlyBalanceService->markAllDirtyForUser($userId);
		}

		return new DataResponse($data, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/hr/notifications", methods={"GET"})
	 *
	 * Read email notification preferences for the current HR user.
	 */
	public function getHrNotificationSettings(): DataResponse {
		$currentUserId = $this->assertHrUser();

		$settings = $this->hrPreferencesStore->getNotificationSettings($currentUserId);
		return new DataResponse($settings, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/hr/notifications", methods={"PUT"})
	 *
	 * Store email notification preferences for the current HR user.
	 */
	public function setHrNotificationSettings(): DataResponse {
		$currentUserId = $this->assertHrUser();

		$payload = $this->request->getParams();
		if (!is_array($payload)) {
			$payload = [];
		}

		$settings = $this->hrPreferencesStore->saveNotificationSettings($currentUserId, $payload);
		return new DataResponse($settings, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/hr/warnings", methods={"GET"})
	 *
	 * Read warning thresholds for the current HR user.
	 */
	public function getHrWarningThresholds(): DataResponse {
		$currentUserId = $this->assertHrUser();

		$settings = $this->hrPreferencesStore->getWarningThresholds($currentUserId);
		return new DataResponse($settings, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/hr/warnings", methods={"PUT"})
	 *
	 * Store warning thresholds for the current HR user.
	 */
	public function setHrWarningThresholds(): DataResponse {
		$currentUserId = $this->assertHrUser();

		$payload = $this->request->getParams();
		if (!is_array($payload)) {
			$payload = [];
		}

		$settings = $this->hrPreferencesStore->saveWarningThresholds($currentUserId, $payload);
		return new DataResponse($settings, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/rules/effective", methods={"GET"})
	 *
	 * Returns effective rule thresholds for the current user.
	 */
	public function getEffectiveRulesSelf(): DataResponse {
		$rules = $this->groupRuleService->getEffectiveRules(null);
		return new DataResponse($rules, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @Route("/api/rules/effective/{userId}", methods={"GET"})
	 *
	 * Returns effective rule thresholds for the given user (HR or self access).
	 */
	public function getEffectiveRulesForUser(string $userId): DataResponse {
		$this->assertConfigAccess($userId);
		$rules = $this->groupRuleService->getEffectiveRules($userId);
		return new DataResponse($rules, Http::STATUS_OK);
	}

	/**
	 * Require an HR user and return its ID.
	 */
	private function assertHrUser(): string {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser) {
			throw new \Exception("Not logged in", Http::STATUS_FORBIDDEN);
		}

		$currentUserId = $currentUser->getUID();
		if (!$this->hrService->isHr($currentUserId)) {
			throw new \Exception("Access denied", Http::STATUS_FORBIDDEN);
		}

		return $currentUserId;
	}

	/**
	 * Allow the current user to access their own configuration or any HR user to access it.
	 */
	private function assertConfigAccess(string $targetUserId): void {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser) {
			throw new \Exception("Not logged in", Http::STATUS_FORBIDDEN);
		}

		$currentUserId = $currentUser->getUID();
		if ($currentUserId === $targetUserId) {
			return;
		}
		if ($this->hrService->isHr($currentUserId)) {
			return;
		}

		throw new \Exception("Access denied", Http::STATUS_FORBIDDEN);
	}
}
