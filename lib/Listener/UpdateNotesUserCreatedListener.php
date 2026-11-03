<?php

declare(strict_types=1);

namespace OCA\Timesheet\Listener;

use OCA\Timesheet\Db\UpdateNotesStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserCreatedEvent;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<UserCreatedEvent> */
class UpdateNotesUserCreatedListener implements IEventListener {
	public function __construct(private UpdateNotesStore $store, private LoggerInterface $logger) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserCreatedEvent) {
			return;
		}
		$userId = $event->getUser()->getUID();
		try {
			// New accounts start with the current notes; returning employees retain missed releases.
			$this->store->markNewAccount($userId);
		} catch (\Throwable $markerError) {
			$this->logger->warning('The update notes marker for a new account could not be saved.', ['userId' => $userId, 'exception' => $markerError]);
		}
	}
}
