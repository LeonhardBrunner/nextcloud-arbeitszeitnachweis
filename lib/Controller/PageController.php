<?php

namespace OCA\Timesheet\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Util;
use OCA\Timesheet\Service\AbsenceCommentCatalog;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Settings\RuleSettingsForm;
use OCA\Timesheet\Db\UserPreferencesStore;
use OCA\Timesheet\Db\TourProgressStore;
use OCA\Timesheet\Db\EmploymentStatusStore;
use OCA\Timesheet\Service\EmploymentStatus;
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
		private AbsenceCommentCatalog $absenceCommentCatalog,
		private IL10N $l10n,
		private EmploymentStatusStore $employmentStatusStore,
		private RuleSettingsForm $ruleSettingsForm,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$currentUser = $this->userSession->getUser();
		$isHr = $this->hrService->isHr();
		$isEmployeeAccount = $currentUser !== null && !$isHr && !$this->groupManager->isAdmin($currentUser->getUID());
		// The introduction and personal classification controls belong to ordinary employees.
		$tourEnabled = $isEmployeeAccount;
		$updateNotesEnabled = $currentUser !== null;
		Util::addTranslations('timesheet');

		// Load modules in dependency order.
		Util::addScript('timesheet', 'timesheet-header');
		Util::addScript('timesheet', 'timesheet-core');
		if ($isHr || $tourEnabled) {
			Util::addScript('timesheet', 'timesheet-mail-help');
		}
		Util::addScript('timesheet', 'timesheet-entries');
		Util::addScript('timesheet', 'timesheet-employment-status');
		Util::addScript('timesheet', 'timesheet-hr');
		Util::addScript('timesheet', 'timesheet-export');
		Util::addScript('timesheet', 'timesheet-copy');
		Util::addScript('timesheet', 'timesheet-configuration');
		if ($isHr) {
			Util::addScript('timesheet', 'timesheet-change-history');
			Util::addScript('timesheet', 'hr-settings');
		}
		if ($updateNotesEnabled) {
			Util::addScript('timesheet', 'timesheet-update-notes');
		}
		if ($tourEnabled) {
			Util::addScript('timesheet', 'timesheet-tour-launcher');
			Util::addScript('timesheet', 'timesheet-tour');
		}
		Util::addScript('timesheet', 'timesheet-main');

		Util::addStyle('timesheet', 'style');
		if ($isHr) {
			Util::addStyle('timesheet', 'timesheet-change-history');
			Util::addStyle('timesheet', 'hr-settings');
		}
		if ($updateNotesEnabled) {
			Util::addStyle('timesheet', 'timesheet-update-notes');
		}
		if ($isHr || $tourEnabled) {
			Util::addStyle('timesheet', 'timesheet-help-panels');
		}
		if ($tourEnabled) {
			Util::addStyle('timesheet', 'timesheet-tour');
		}

		$breakPreference = $currentUser
			? $this->userPreferencesStore->getBreakInputPreference($currentUser->getUID())
			: ['breakInputMode' => 'minutes', 'revision' => hash('sha256', '')];
		$historyPreference = ['historyView' => 'cards', 'revision' => hash('sha256', '')];
		$historyPreferenceAvailable = false;
		if ($isHr && $currentUser !== null) {
			try {
				$historyPreference = $this->userPreferencesStore->getHistoryViewPreference($currentUser->getUID());
				$historyPreferenceAvailable = true;
			} catch (\Throwable $loadError) {
				// Display preferences must not block access to the employee history.
				$this->logger->warning('Change history view could not be loaded.', ['exception' => $loadError]);
			}
		}
		$employmentStatus = (new EmploymentStatus())->toPublicArray();
		$employmentStatusAvailable = false;
		if ($currentUser !== null) {
			try {
				$employmentStatus = $this->employmentStatusStore->getStatus($currentUser->getUID())->toPublicArray();
				$employmentStatusAvailable = true;
			} catch (\Throwable $loadError) {
				// A failed classification read disables its controls without blocking recorded times.
				$this->logger->warning('Employment status could not be loaded.', ['exception' => $loadError]);
			}
		}
		$tourProgress = [
			'version' => TourProgressStore::VERSION,
			'employeeCompleted' => false,
		];
		$tourStateAvailable = false;
		if ($tourEnabled) {
			try {
				$tourProgress = $this->tourProgressStore->getProgress($currentUser->getUID());
				$tourStateAvailable = true;
			} catch (\Throwable $loadError) {
				// A missing introduction preference must not prevent normal timesheet use.
				$this->logger->warning('Timesheet tour progress could not be loaded.', ['exception' => $loadError]);
			}
		}
		$notificationRuleSettings = null;
		if ($isHr) {
			try {
				$notificationRuleSettings = $this->ruleSettingsForm->getData('notifications');
			} catch (\Throwable $loadError) {
				// A failed rule snapshot must not block entries or personal HR settings.
				$this->logger->warning('Notification rules could not be loaded.', ['exception' => $loadError]);
			}
		}
		return new TemplateResponse($this->appName, 'main', [
			'isHR' => $isHr,
			'notificationRuleSettings' => $notificationRuleSettings,
			'updateNotesEnabled' => $updateNotesEnabled,
			'breakInputMode' => $breakPreference['breakInputMode'],
			'breakInputRevision' => $breakPreference['revision'],
			'historyView' => $historyPreference['historyView'],
			'historyViewRevision' => $historyPreference['revision'],
			'historyViewAvailable' => $historyPreferenceAvailable,
			'employmentStatus' => $employmentStatus,
			'employmentStatusAvailable' => $employmentStatusAvailable,
			'canSetFreelancerMode' => $isEmployeeAccount,
			'absenceCommentTerms' => $this->absenceCommentCatalog->getTerms($this->l10n->getLanguageCode()),
			'tourProgress' => $tourProgress,
			'tourEnabled' => $tourEnabled,
			'tourStateAvailable' => $tourStateAvailable,
		]);
	}
}
