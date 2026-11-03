<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;

/** Administrator status takes precedence over any additional HR assignment. */
class UpdateNotesEligibility {
	public function __construct(private IUserManager $userManager, private IGroupManager $groupManager, private IAppManager $appManager) {
	}

	public function canReceive(string $userId): bool {
		$user = $userId === '' ? null : $this->userManager->get($userId);
		return $user !== null && $user->isEnabled() && !$this->groupManager->isAdmin($userId)
			&& $this->appManager->isEnabledForUser('timesheet', $user);
	}
}
