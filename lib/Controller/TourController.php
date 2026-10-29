<?php

declare(strict_types=1);

namespace OCA\Timesheet\Controller;

use OCA\Timesheet\Db\TourProgressStore;
use OCA\Timesheet\Service\HrService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;

class TourController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private TourProgressStore $tourProgressStore,
		private IUserSession $userSession,
		private HrService $hrService,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	/** Completion belongs to the current account; HR completion also requires its current role. */
	#[NoAdminRequired]
	public function complete(mixed $version = null, mixed $includeHr = false): DataResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null || $currentUser->getUID() === '') {
			return new DataResponse(['message' => 'Not logged in'], Http::STATUS_FORBIDDEN);
		}
		if (!is_int($version) || !is_bool($includeHr)) {
			return new DataResponse(['message' => 'Invalid tour completion'], Http::STATUS_BAD_REQUEST);
		}
		if ($version !== TourProgressStore::VERSION) {
			return new DataResponse(['code' => 'tour_version_changed'], Http::STATUS_CONFLICT);
		}

		try {
			$userId = $currentUser->getUID();
			if ($this->groupManager->isAdmin($userId)) {
				return new DataResponse(['code' => 'tour_unavailable'], Http::STATUS_FORBIDDEN);
			}
			$isHr = $this->hrService->isHr($userId);
			if ($includeHr && !$isHr) {
				return new DataResponse(['code' => 'hr_access_changed'], Http::STATUS_FORBIDDEN);
			}
			$this->tourProgressStore->markCompleted($userId, $includeHr);
			return new DataResponse($this->tourProgressStore->getProgress($userId, $isHr), Http::STATUS_OK);
		} catch (LockedException) {
			return new DataResponse(['code' => 'preference_busy'], Http::STATUS_CONFLICT);
		} catch (\Throwable $saveError) {
			return new DataResponse(['message' => 'Saving tour completion failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
