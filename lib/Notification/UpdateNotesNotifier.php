<?php

declare(strict_types=1);

namespace OCA\Timesheet\Notification;

use OCA\Timesheet\Service\UpdateNotesService;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IDismissableNotifier;
use OCP\Notification\INotification;
use OCP\Notification\IncompleteParsedNotificationException;
use OCP\Notification\UnknownNotificationException;
use Psr\Log\LoggerInterface;

/** Translates only Timesheet release notifications and handles the native dismissal action. */
class UpdateNotesNotifier implements IDismissableNotifier {
	public function __construct(private UpdateNotesService $notesService, private IFactory $l10nFactory, private LoggerInterface $logger) {
	}

	public function getID(): string {
		return 'timesheet';
	}

	public function getName(): string {
		return (string)$this->l10nFactory->get('timesheet')->t('Timesheet');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$this->requireOwnNotification($notification);
		$version = $notification->getObjectId();
		try {
			$translator = $this->l10nFactory->get('timesheet', $languageCode);
			$releases = $this->notesService->notificationReleases($notification->getUser(), $version, $translator);
		} catch (\Throwable $loadError) {
			// The native bell skips this note while keeping unrelated notifications usable.
			$this->logger->warning('An update notification could not be prepared.', ['userId' => $notification->getUser(), 'exception' => $loadError]);
			throw new IncompleteParsedNotificationException();
		}
		if ($releases === []) {
			throw new AlreadyProcessedException();
		}
		// The native bell shows a brief link; the popup contains the full change list.
		return $notification
			->setParsedSubject(str_replace('{version}', $version, (string)$translator->t('Timesheet updated to {version}')))
			->setParsedMessage((string)$translator->t('Open the update notes.'))
			->setIcon($this->notesService->icon())
			->setLink($this->notesService->appLink());
	}

	public function dismissNotification(INotification $notification): void {
		$this->requireOwnNotification($notification);
		$this->notesService->dismiss($notification->getUser(), $notification->getObjectId());
	}

	private function requireOwnNotification(INotification $notification): void {
		if ($notification->getApp() !== 'timesheet' || $notification->getObjectType() !== UpdateNotesService::OBJECT_TYPE
			|| $notification->getSubject() !== UpdateNotesService::SUBJECT) {
			throw new UnknownNotificationException();
		}
	}
}
