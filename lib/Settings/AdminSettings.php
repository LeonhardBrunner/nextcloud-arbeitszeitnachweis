<?php

namespace OCA\Timesheet\Settings;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/** Administrators assign HR responsibility and employee groups. */
class AdminSettings implements ISettings {
	public function __construct(
		private RuleSettingsForm $settingsForm,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
	}

	public function getForm(): TemplateResponse {
		if (!$this->isAdministrator()) {
			return new TemplateResponse('core', '403', [], '', Http::STATUS_FORBIDDEN);
		}
		return $this->settingsForm->getForm('assignments');
	}

	public function getSection(): ?string {
		return $this->isAdministrator() ? 'timesheet' : null;
	}

	public function getPriority(): int {
		return 50;
	}

	private function isAdministrator(): bool {
		$userId = $this->userSession->getUser()?->getUID();
		return $userId !== null && $userId !== '' && $this->groupManager->isAdmin($userId);
	}
}
