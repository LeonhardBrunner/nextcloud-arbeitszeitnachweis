<?php

namespace OCA\Timesheet\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\BackgroundJob\IJobList;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCA\Timesheet\BackgroundJob\HrNotificationJob;
use OCA\Timesheet\BackgroundJob\MissingEntryReminderJob;
use OCA\Timesheet\BackgroundJob\ChangeHistoryCleanupJob;
use OCA\Timesheet\Db\ChangeHistoryRecordingStore;
use OCA\Timesheet\Notification\UpdateNotesNotifier;
use OCA\Timesheet\Listener\UpdateNotesUserCreatedListener;
use OCA\Timesheet\Listener\EmployeeActivityListener;
use OCA\Timesheet\Listener\ChangeHistoryUserDeletedListener;
use OCA\Timesheet\Listener\ChangeHistoryUserCreatedListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'timesheet';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
		if (file_exists($vendorAutoload)) {
			require_once $vendorAutoload;
		}
		// Fresh installs can apply schema directly without running migration post hooks.
		$this->getContainer()->get(ChangeHistoryRecordingStore::class)->initialize();
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(UpdateNotesNotifier::class);
		$context->registerEventListener(UserCreatedEvent::class, ChangeHistoryUserCreatedListener::class);
		$context->registerEventListener(UserCreatedEvent::class, UpdateNotesUserCreatedListener::class);
		$context->registerEventListener(UserAddedEvent::class, EmployeeActivityListener::class);
		$context->registerEventListener(UserRemovedEvent::class, EmployeeActivityListener::class);
		$context->registerEventListener(UserDeletedEvent::class, ChangeHistoryUserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
		$this->getContainer()->get(ChangeHistoryRecordingStore::class)->initialize();
		$context->injectFn(function (INavigationManager $navigationManager, IURLGenerator $urlGenerator, IFactory $l10nFactory) {
			$appId = self::APP_ID;
			$navigationManager->add(function () use ($appId, $urlGenerator, $l10nFactory) {
				$translator = $l10nFactory->get($appId);
				return [
					'id' => $appId,
					'order' => 10,
					'href' => $urlGenerator->linkToRoute($appId . '.page.index'),
					'icon' => $urlGenerator->imagePath($appId, 'app.svg'),
					'name' => $translator->t('Timesheet'),
					'app' => $appId,
				];
			});
		});

		$serverContainer = $context->getServerContainer();

		/** @var IJobList $jobList */
		$jobList = $serverContainer->get(IJobList::class);
		// Register background jobs for existing installations as well as app updates.
		foreach ([HrNotificationJob::class, MissingEntryReminderJob::class, ChangeHistoryCleanupJob::class] as $jobClass) {
			if (method_exists($jobList, 'has') && !$jobList->has($jobClass, null)) {
				$jobList->add($jobClass, null);
			}
		}
	}
}
