<?php

declare(strict_types=1);

namespace OCA\Timesheet\Controller;

use OCA\Timesheet\Db\UserPreferencesStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\PreConditionNotMetException;

class PreferencesController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private UserPreferencesStore $preferencesStore,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/** The viewer's preference never belongs to an employee selected in the HR view. */
	#[NoAdminRequired]
	public function setBreakInputMode(mixed $breakInputMode = null, mixed $revision = null): DataResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null || $currentUser->getUID() === '') {
			return new DataResponse(['message' => 'Not logged in'], Http::STATUS_FORBIDDEN);
		}
		if (!is_string($breakInputMode) || !in_array($breakInputMode, ['minutes', 'hours'], true)) {
			return new DataResponse(['message' => 'Invalid break input mode'], Http::STATUS_BAD_REQUEST);
		}
		if (!is_string($revision) || preg_match('/\A[0-9a-f]{64}\z/i', $revision) !== 1) {
			return new DataResponse(['message' => 'Invalid preference revision'], Http::STATUS_BAD_REQUEST);
		}

		try {
			try {
				$preference = $this->preferencesStore->setBreakInputMode($currentUser->getUID(), $breakInputMode, $revision);
			} catch (PreConditionNotMetException | LockedException) {
				return new DataResponse([
					'code' => 'preference_conflict',
					...$this->preferencesStore->getBreakInputPreference($currentUser->getUID()),
				], Http::STATUS_CONFLICT);
			}
			return new DataResponse($preference, Http::STATUS_OK);
		} catch (\Throwable $saveError) {
			return new DataResponse(['message' => 'Saving display preferences failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
