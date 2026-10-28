<?php

namespace OCA\Timesheet\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\UserConfigMapper;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\HolidayService;
use OCA\Timesheet\Service\MonthlyBalanceService;

class OverviewController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private EntryMapper $entryMapper,
		private UserConfigMapper $userConfigMapper,
		private IUserSession $userSession,
		private HrService $hrService,
		private HolidayService $holidayService,
		private MonthlyBalanceService $monthlyBalanceService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function users(): DataResponse {
		if (!$this->hrService->isHr()) {
			return new DataResponse([], 403);
		}

		return new DataResponse($this->hrService->getAccessibleUsers());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getOvertimeSummary(): JSONResponse {
		// Get current user
		$currentUser = $this->userSession->getUser();
		if (!$currentUser) {
			return new JSONResponse(['error' => 'Unauthorized'], 401);
		}

		// Get requested userId or fallback to current user
		$userId = (string)($this->request->getParam('user') ?? $currentUser->getUID());
		if ($userId === '') {
			$userId = $currentUser->getUID();
		}

		// Check access rights
		if ($userId !== $currentUser->getUID() && !$this->hrService->canAccessUser($userId)) {
			return new JSONResponse(['error' => 'Forbidden'], 403);
		}

		$summary = $this->monthlyBalanceService->calculateCurrentBalance($userId);

		return new JSONResponse([
			'from' => $summary['from'],
			'to'   => $summary['to'],
			'totalMinutes' => $summary['totalMinutes'],
			'totalWorkdays' => $summary['totalWorkdays'],
			'targetMinutes' => $summary['targetMinutes'],
			'dailyMin' => $summary['dailyMin'],
			'openingBalanceMinutes' => $summary['openingBalanceMinutes'],
			'overtimeMinutes' => $summary['overtimeMinutes'],
			'source' => $summary['source'],
			'lastSnapshot' => $summary['lastSnapshot'],
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getHrUserListData(): DataResponse {
		if (!$this->hrService->isHr()) {
			return new DataResponse([], 403);
		}

		$accessibleUsers = $this->hrService->getAccessibleUsers();
		if ($accessibleUsers === []) {
			return new DataResponse([]);
		}

		$userIds = array_map(fn($userInfo) => (string)($userInfo['id'] ?? ''), $accessibleUsers);
		$userIds = array_values(array_filter($userIds, fn($userId) => $userId !== ''));
		if ($userIds === []) {
			return new DataResponse([]);
		}

		$userSummaries = [];

		foreach ($accessibleUsers as $user) {
			$userId = (string)($user['id'] ?? '');
			if ($userId === '') {
				continue;
			}

			$summary = $this->monthlyBalanceService->calculateCurrentBalance($userId);
			$userSummaries[] = [
				'id' => $userId,
				'name' => $user['name'] ?? $userId,
				'dailyMin' => (int)$summary['dailyMin'],
				'overtimeMinutes' => (int)$summary['overtimeMinutes'],
				'totalMinutes' => (int)$summary['totalMinutes'],
				'lastEntryDate' => $summary['lastEntryDate'] ?? null,
				'balanceSource' => $summary['source'] ?? 'entries',
				'lastSnapshot' => $summary['lastSnapshot'] ?? null,
			];
		}

		return new DataResponse($userSummaries);
	}
}
