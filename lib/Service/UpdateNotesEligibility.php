<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCP\App\IAppManager;
use OCP\IUserManager;

/** Release notes are available to enabled accounts with access to the app. */
class UpdateNotesEligibility {
	public function __construct(private IUserManager $userManager, private IAppManager $appManager) {
	}

	public function canReceive(string $userId): bool {
		$user = $userId === '' ? null : $this->userManager->get($userId);
		return $user !== null && $user->isEnabled()
			&& $this->appManager->isEnabledForUser('timesheet', $user);
	}
}
