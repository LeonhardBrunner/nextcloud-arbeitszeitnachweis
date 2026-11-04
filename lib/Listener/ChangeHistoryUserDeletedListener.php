<?php

declare(strict_types=1);

namespace OCA\Timesheet\Listener;

use OCA\Timesheet\Service\HistoryWriteCoordinator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<UserDeletedEvent> */
class ChangeHistoryUserDeletedListener implements IEventListener {
	public function __construct(private HistoryWriteCoordinator $writes, private LoggerInterface $logger) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			try {
				$this->writes->forgetAccount($event->getUser()->getUID());
			} catch (\Throwable $cleanupError) {
				// A persisted positive marker is retried by the daily cleanup.
				$this->logger->error('Deleted account history cleanup failed.', ['exception' => $cleanupError]);
				throw $cleanupError;
			}
		}
	}
}
