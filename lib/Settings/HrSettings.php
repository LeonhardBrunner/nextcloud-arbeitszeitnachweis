<?php

namespace OCA\Timesheet\Settings;

use OCA\Timesheet\Service\HrService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/** HR manages time rules, reminder settings and email recipients. */
class HrSettings implements ISettings {
	public function __construct(private RuleSettingsForm $settingsForm, private HrService $hrService) {
	}

	public function getForm(): TemplateResponse {
		if (!$this->hrService->isHr()) {
			return new TemplateResponse('core', '403', [], '', Http::STATUS_FORBIDDEN);
		}
		return $this->settingsForm->getForm('policy');
	}

	public function getSection(): ?string {
		return $this->hrService->isHr() ? 'timesheet' : null;
	}

	public function getPriority(): int {
		return 50;
	}
}
