<?php

declare(strict_types=1);

namespace OCA\Timesheet\Listener;

use OCA\Timesheet\Service\HistoryWriteCoordinator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserCreatedEvent;

/** @implements IEventListener<UserCreatedEvent> */
class ChangeHistoryUserCreatedListener implements IEventListener {
	public function __construct(private HistoryWriteCoordinator $writes) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserCreatedEvent) {
			// Reused UIDs must not inherit former subjects, editors or stale retry markers.
			$this->writes->forgetAccount($event->getUser()->getUID());
		}
	}
}
