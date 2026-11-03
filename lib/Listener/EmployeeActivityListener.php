<?php

declare(strict_types=1);

namespace OCA\Timesheet\Listener;

use OCA\Timesheet\Db\EmploymentStatusStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;

/** Records activity transitions when membership of the active employee group changes. */
class EmployeeActivityListener implements IEventListener {
	public function __construct(private EmploymentStatusStore $statusStore, private LoggerInterface $logger) {
	}

	public function handle(Event $event): void {
		if ((!$event instanceof UserAddedEvent && !$event instanceof UserRemovedEvent)
			|| $event->getGroup()->getGID() !== EmploymentStatusStore::ACTIVE_GROUP_ID) {
			return;
		}
		$userId = $event->getUser()->getUID();
		try {
			// Native events describe the completed change before membership caches are cleared.
			$this->statusStore->recordMembershipEvent($event);
		} catch (\Throwable $saveError) {
			// Group administration remains usable; the next status read retries synchronization.
			$this->logger->error('Employee activity could not be synchronized after a group change.', ['userId' => $userId, 'exception' => $saveError]);
		}
	}
}
