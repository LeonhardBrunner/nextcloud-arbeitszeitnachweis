<?php

namespace OCA\Timesheet\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJob;
use OCP\Mail\IMailer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\AppFramework\Services\IAppConfig;
use OCA\Timesheet\Service\HrNotificationService;
use OCA\Timesheet\Service\HrWeeklyMailTemplates;
use OCA\Timesheet\Service\EmployeeBalanceNotificationService;

use Psr\Log\LoggerInterface;
use OCP\L10N\IFactory;

class HrNotificationJob extends TimedJob {
	private IUserManager $userManager;
	private IMailer $mailer;
	private HrNotificationService $notificationService;
	private ITimeFactory $timeFactory;
	private IAppConfig $config;
	private LoggerInterface $logger;
	private IFactory $l10nFactory;

	public function __construct(
		ITimeFactory $time,
		IUserManager $userManager,
		IMailer $mailer,
		HrNotificationService $notificationService,
		IAppConfig $config,
		LoggerInterface $logger,
		IFactory $l10nFactory,
		private EmployeeBalanceNotificationService $employeeBalanceNotifications,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 60 * 60); // once a day
		$this->setAllowParallelRuns(false);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

		$this->timeFactory = $time;
		$this->config = $config;
		$this->logger = $logger;
		$this->userManager = $userManager;
		$this->mailer = $mailer;
		$this->notificationService = $notificationService;
		$this->l10nFactory = $l10nFactory;
	}

	protected function run($arguments): void {
		$currentTime = $this->timeFactory->getDateTime('now');

		$forceRun = $this->config->getAppValueString('hr_notification_force', '0') === '1';

		if (!$forceRun && (int)$currentTime->format('N') !== 1) {
			// Run on Mondays unless explicitly forced.
			return;
		}

		$this->logger->warning("HrNotificationJob RUN", [
			'force' => $forceRun,
			'weekday' => (int)$currentTime->format('N'),
			'date' => $currentTime->format(\DATE_ATOM),
		]);

		// Employee delivery runs even when no personal HR summary is enabled.
		try {
			$this->logger->info('Employee balance notification check completed.', $this->employeeBalanceNotifications->sendNotifications());
		} catch (\Throwable $employeeError) {
			$this->logger->error('Employee balance notification check failed.', ['exception' => $employeeError]);
		}

		$notificationsByHrUser = $this->notificationService->doCron();
		if (empty($notificationsByHrUser)) {
			$this->logger->warning("HrNotificationJob completed (no notifications)");
			return;
		}

		$sentCount = 0;
		$sendErrorCount = 0;

		foreach ($notificationsByHrUser as $hrUserId => $notificationData) {
			$hrUser = $this->userManager->get($hrUserId);
			if (!$hrUser) {
				continue;
			}

			$emailAddress = $hrUser->getEMailAddress();
			if (empty($emailAddress)) {
				continue;
			}

			$language = $this->l10nFactory->getLanguageIterator($hrUser)->current();
			$translator = $this->l10nFactory->get('timesheet', $language);

			$subject = $translator->t("Timesheet Notifications") . " - " . $translator->l('date', $currentTime, ['width' => 'medium']);
			$plainBody = $translator->t('Hello %1$s,', [$hrUser->getDisplayName()]) . "\n\n";
			$htmlBody = "<!DOCTYPE html><html><body>";
			$htmlBody .= "<p>" . $translator->t('Hello %1$s,', [htmlspecialchars($hrUser->getDisplayName())]) . "</p>";

			foreach (HrWeeklyMailTemplates::ALERTS as $alert) {
				$warnings = $notificationData[$alert] ?? [];
				if ($warnings === []) {
					continue;
				}
				$threshold = $notificationData['thresholds'][$alert] ?? (string)($notificationData['noEntryDays'] ?? '');
				$lines = array_map(static fn (array $warning): string => HrWeeklyMailTemplates::entry($translator, $alert, $warning), $warnings);
				$plainEntries = implode("\n", array_map(static fn (string $line): string => ' - ' . $line, $lines));
				$htmlEntries = '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $lines)) . '</li></ul>';
				$template = $notificationData['weeklyMailTemplates'][$alert][$language] ?? null;
				if ($template === null) {
					$heading = HrWeeklyMailTemplates::heading($translator, $alert, $threshold);
					$plainBody .= $heading . "\n" . $plainEntries . "\n\n";
					$htmlBody .= '<p><strong>' . htmlspecialchars($heading) . '</strong></p>' . $htmlEntries;
					continue;
				}
				$plainBody .= strtr($template, ['{entries}' => $plainEntries, '{threshold}' => $threshold]) . "\n\n";
				$htmlBody .= '<div>' . strtr(nl2br(htmlspecialchars($template), false), [
					'{entries}' => $htmlEntries,
					'{threshold}' => htmlspecialchars($threshold),
				]) . '</div>';
			}

			$plainBody .= "\n"
				. $translator->t(
					'This email was automatically generated based on the notification settings you selected in Nextcloud. Do not reply to this email.'
				) . "\n";
			$htmlBody .= "<hr><p style=\"color:#666;font-size:12px;\">"
				. $translator->t(
					'This email was automatically generated based on the notification settings you selected in Nextcloud. Do not reply to this email.'
				) . "</p>";

			if (trim($plainBody) === $translator->t('Hello %1$s,', [$hrUser->getDisplayName()])) {
				continue;
			}

			$htmlBody .= "</body></html>";

			try {
				$message = $this->mailer->createMessage();
				$message->setSubject($subject);
				$message->setTo([$emailAddress => $hrUser->getDisplayName()]);
				$message->setPlainBody($plainBody);
				$message->setHtmlBody($htmlBody);
				$this->mailer->send($message);
				$sentCount++;
			} catch (\Throwable $sendError) {
				$sendErrorCount++;
				$this->logger->error("HrNotificationJob: mail send failed", [
					'hrUserId' => $hrUserId,
					'exception' => $sendError,
				]);
			}
		}

		$this->logger->warning("HrNotificationJob completed", [
			'sent' => $sentCount,
			'sendErrors' => $sendErrorCount,
		]);
	}
}
