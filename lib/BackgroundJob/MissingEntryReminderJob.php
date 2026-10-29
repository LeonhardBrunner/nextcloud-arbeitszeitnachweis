<?php

namespace OCA\Timesheet\BackgroundJob;

use OCA\Timesheet\Service\MissingEntryReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class MissingEntryReminderJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MissingEntryReminderService $reminderService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setAllowParallelRuns(false);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($arguments): void {
		try {
			$this->logger->info('Missing-entry reminder check completed.', $this->reminderService->sendReminders());
		} catch (\Throwable $checkError) {
			$this->logger->error('Missing-entry reminder job failed.', ['exception' => $checkError]);
		}
	}
}
