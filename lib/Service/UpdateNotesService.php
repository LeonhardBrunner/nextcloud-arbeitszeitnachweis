<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\UpdateNotesStore;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/** Coordinates account progress and the native bell while keeping popup display independent. */
class UpdateNotesService {
	public const OBJECT_TYPE = 'update_notes';
	public const SUBJECT = 'update_notes';
	// Native notifier and app controllers may resolve through different containers in one request.
	private static array $technicalDismissals = [];

	public function __construct(
		private UpdateNotesCatalog $catalog,
		private UpdateNotesStore $store,
		private UpdateNotesEligibility $eligibility,
		private IAppConfig $appConfig,
		private IManager $notificationManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function canReceive(string $userId): bool {
		return $this->eligibility->canReceive($userId);
	}

	public function open(string $userId, string $mode, IL10N $translator): array {
		$this->requireEligible($userId);
		$version = $this->installedVersion();
		if (!$this->catalog->contains($version)) {
			$this->logger->warning('No update notes are bundled for the installed Timesheet version.', ['version' => $version]);
			return ['enabled' => true, 'throughVersion' => null, 'shouldShow' => false, 'releases' => []];
		}
		$shouldFlush = false;
		try {
			$shouldFlush = $this->notificationManager->defer();
		} catch (\Throwable $deferralError) {
			$this->logger->warning('Update notification delivery could not be deferred.', ['exception' => $deferralError]);
		}
		try {
			return $this->openWithAccountLock($userId, $mode, $translator, $version);
		} finally {
			if ($shouldFlush) {
				try {
					// Push preparation and device transport run after the account state is committed.
					$this->notificationManager->flush();
				} catch (\Throwable $flushError) {
					$this->logger->warning('Deferred update notification delivery failed.', ['exception' => $flushError]);
				}
			}
		}
	}

	private function openWithAccountLock(string $userId, string $mode, IL10N $translator, string $version): array {
		return $this->store->update($userId, function (?array $state) use ($userId, $mode, $translator, $version): array {
			$state ??= $this->initialStateForUser($userId, $version);
			$pending = $this->catalog->between($state['floorVersion'], $state['dismissedVersion'], $version);
			$knownVersion = $state['floorVersion'];
			foreach (['shownVersion', 'dismissedVersion', 'publishedVersion'] as $key) {
				$knownVersion = $this->laterVersion($knownVersion, $state[$key]);
			}
			$isDowngrade = version_compare($version, $knownVersion, '<');
			// Native delivery may fail independently of an otherwise usable change list.
			try {
				if (!$isDowngrade) {
					$this->synchronizeBell($userId, $version, $state, $pending !== []);
				}
			} catch (\Throwable $deliveryError) {
				$this->logger->warning('The update notes bell notification could not be refreshed.', ['userId' => $userId, 'exception' => $deliveryError]);
			}
			$afterVersion = $this->laterVersion($state['shownVersion'], $state['dismissedVersion']);
			$releases = $mode === 'manual' ? $pending : $this->catalog->between($state['floorVersion'], $afterVersion, $version);
			if ($mode === 'automatic' && $isDowngrade) {
				$releases = [];
			}
			if ($mode === 'manual' && $releases === []) {
				$releases = $this->catalog->between($version, null, $version);
			}
			$throughVersion = $releases === [] ? null : $releases[array_key_last($releases)]['version'];
			return ['state' => $state, 'result' => [
				'enabled' => true,
				'throughVersion' => $throughVersion,
				'shouldShow' => $releases !== [],
				'releases' => $this->catalog->translate($releases, $translator),
			]];
		});
	}

	public function markShown(string $userId, string $throughVersion): array {
		$this->requireEligible($userId);
		$this->requireAvailableVersion($throughVersion);
		return $this->store->update($userId, function (?array $state) use ($throughVersion): array {
			if ($state === null || version_compare($throughVersion, $state['floorVersion'], '<')) {
				throw new \InvalidArgumentException('The update notes were not opened for this account.');
			}
			$state['shownVersion'] = $this->laterVersion($state['shownVersion'], $throughVersion);
			return ['state' => $state, 'result' => ['throughVersion' => $state['shownVersion']]];
		});
	}

	/** Called by native X and clear-all actions, never by the popup close button. */
	public function dismiss(string $userId, string $throughVersion): void {
		$key = $userId . ':' . $throughVersion;
		if (isset(self::$technicalDismissals[$key]) || !$this->canReceive($userId)) {
			return;
		}
		if (!$this->isAvailableVersion($throughVersion)) {
			// Native cleanup must remain possible after a downgrade or a removed catalog entry.
			return;
		}
		$this->store->update($userId, function (?array $state) use ($userId, $throughVersion): array {
			$state ??= $this->initialStateForUser($userId, $throughVersion);
			$state['dismissedVersion'] = $this->laterVersion($state['dismissedVersion'], $throughVersion);
			if ($state['publishedVersion'] !== null && version_compare($state['publishedVersion'], $throughVersion, '<=')) {
				$state['publishedVersion'] = null;
			}
			return ['state' => $state, 'result' => null];
		});
	}

	/** The native notifier reads current state without taking a second publication lock. */
	public function notificationReleases(string $userId, string $throughVersion, IL10N $translator): array {
		if (!$this->canReceive($userId) || !$this->isAvailableVersion($throughVersion)) {
			return [];
		}
		$state = $this->store->read($userId) ?? $this->initialStateForUser($userId, $throughVersion);
		return $this->catalog->translate($this->catalog->between($state['floorVersion'], $state['dismissedVersion'], $throughVersion), $translator);
	}

	public function appLink(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('timesheet.page.index')) . '?update-notes=1';
	}

	public function icon(): string {
		// Native notifications invert dark icons to match the text in dark themes.
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('timesheet', 'app_dark.svg'));
	}

	private function synchronizeBell(string $userId, string $version, array &$state, bool $hasPending): void {
		if (!$hasPending) {
			return;
		}
		$notification = $this->notificationFilter($userId, $version);
		if ($this->notificationManager->getCount($notification) === 0) {
			$notification->setDateTime($this->timeFactory->getDateTime())
				->setSubject(self::SUBJECT, ['throughVersion' => $version])
				->setLink($this->appLink());
			$this->notificationManager->notify($notification);
		}
		$previousVersion = $state['publishedVersion'];
		if ($previousVersion !== null && $previousVersion !== $version) {
			$key = $userId . ':' . $previousVersion;
			self::$technicalDismissals[$key] = true;
			try {
				$this->notificationManager->markProcessed($this->notificationFilter($userId, $previousVersion));
			} finally {
				unset(self::$technicalDismissals[$key]);
			}
		}
		$state['publishedVersion'] = $version;
	}

	private function notificationFilter(string $userId, string $version): INotification {
		return $this->notificationManager->createNotification()->setApp('timesheet')->setUser($userId)->setObject(self::OBJECT_TYPE, $version);
	}

	private function initialState(string $version): array {
		return ['version' => 1, 'floorVersion' => $version, 'shownVersion' => null, 'dismissedVersion' => null, 'publishedVersion' => null];
	}

	private function initialStateForUser(string $userId, string $throughVersion): array {
		$floorVersion = $throughVersion;
		if (!$this->store->isNewAccount($userId)) {
			$announcedReleases = $this->catalog->between('0.0.0', null, $throughVersion);
			$floorVersion = $announcedReleases[0]['version'] ?? $throughVersion;
		}
		return $this->initialState($floorVersion);
	}

	private function requireEligible(string $userId): void {
		if (!$this->canReceive($userId)) {
			throw new \DomainException('Update notes are unavailable to this account.');
		}
	}

	private function installedVersion(): string {
		$version = $this->appConfig->getAppValueString('installed_version', '');
		if (!UpdateNotesCatalog::isVersion($version)) {
			throw new \UnexpectedValueException('The installed app version is unavailable.');
		}
		return $version;
	}

	private function isAvailableVersion(string $version): bool {
		return UpdateNotesCatalog::isVersion($version) && $this->catalog->contains($version)
			&& version_compare($version, $this->installedVersion(), '<=');
	}

	private function requireAvailableVersion(string $version): void {
		if (!$this->isAvailableVersion($version)) {
			throw new \InvalidArgumentException('Unknown update notes version.');
		}
	}

	private function laterVersion(?string $first, ?string $second): ?string {
		return $first === null ? $second : ($second === null || version_compare($first, $second, '>=') ? $first : $second);
	}
}
