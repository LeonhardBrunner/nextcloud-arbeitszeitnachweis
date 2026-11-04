<?php

declare(strict_types=1);

namespace OCA\Timesheet\BackgroundJob;

use OCA\Timesheet\Db\ChangeHistoryStore;
use OCA\Timesheet\Service\ChangeHistoryService;
use OCA\Timesheet\Service\HistoryWriteCoordinator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class ChangeHistoryCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ChangeHistoryStore $history,
		private ChangeHistoryService $historyService,
		private LoggerInterface $logger,
		private HistoryWriteCoordinator $writes,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setAllowParallelRuns(false);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($arguments): void {
		try {
			$this->history->deleteExpired($this->historyService->retentionCutoff());
			foreach ($this->writes->pendingAccountCleanups() as $userId) {
				$this->writes->retryAccountCleanup($userId);
			}
		} catch (\Throwable $cleanupError) {
			$this->logger->error('Change history cleanup failed.', ['exception' => $cleanupError]);
		}
	}
}
