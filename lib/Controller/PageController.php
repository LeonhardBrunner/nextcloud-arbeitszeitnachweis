<?php

namespace OCA\Timesheet\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\Util;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Db\UserPreferencesStore;
use OCA\Timesheet\Db\TourProgressStore;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private HrService $hrService,
		private UserPreferencesStore $userPreferencesStore,
		private IUserSession $userSession,
		private TourProgressStore $tourProgressStore,
		private LoggerInterface $logger,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$currentUser = $this->userSession->getUser();
		// The introduction is currently limited to non-administrator accounts.
		$tourEnabled = $currentUser !== null && !$this->groupManager->isAdmin($currentUser->getUID());
		Util::addTranslations('timesheet');

		// Load modules in dependency order.
		Util::addScript('timesheet', 'timesheet-core');
		Util::addScript('timesheet', 'timesheet-entries');
		Util::addScript('timesheet', 'timesheet-hr');
		Util::addScript('timesheet', 'timesheet-export');
		Util::addScript('timesheet', 'timesheet-copy');
		Util::addScript('timesheet', 'timesheet-config');
		if ($tourEnabled) {
			Util::addScript('timesheet', 'timesheet-tour-header');
			Util::addScript('timesheet', 'timesheet-tour');
		}
		Util::addScript('timesheet', 'timesheet-main');

		Util::addStyle('timesheet', 'style');
		if ($tourEnabled) {
			Util::addStyle('timesheet', 'timesheet-tour');
		}

		$breakPreference = $currentUser
			? $this->userPreferencesStore->getBreakInputPreference($currentUser->getUID())
			: ['breakInputMode' => 'minutes', 'revision' => hash('sha256', '')];
		$isHr = $this->hrService->isHr();
		$tourProgress = [
			'version' => TourProgressStore::VERSION,
			'employeeCompleted' => false,
			'hrCompleted' => false,
			'isHr' => $isHr,
		];
		$tourStateAvailable = false;
		if ($tourEnabled) {
			try {
				$tourProgress = $this->tourProgressStore->getProgress($currentUser->getUID(), $isHr);
				$tourStateAvailable = true;
			} catch (\Throwable $loadError) {
				// A missing introduction preference must not prevent normal timesheet use.
				$this->logger->warning('Timesheet tour progress could not be loaded.', ['exception' => $loadError]);
			}
		}
		return new TemplateResponse($this->appName, 'main', [
			'isHR' => $isHr,
			'breakInputMode' => $breakPreference['breakInputMode'],
			'breakInputRevision' => $breakPreference['revision'],
			'tourProgress' => $tourProgress,
			'tourEnabled' => $tourEnabled,
			'tourStateAvailable' => $tourStateAvailable,
		]);
	}
}
