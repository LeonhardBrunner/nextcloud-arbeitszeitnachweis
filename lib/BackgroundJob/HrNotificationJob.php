<?php

namespace OCA\Timesheet\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJob;
use OCP\Mail\IMailer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\AppFramework\Services\IAppConfig;
use OCA\Timesheet\Service\HrNotificationService;
use OCP\IUser;
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
		IFactory $l10nFactory
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

			$translator = $this->lForUser($hrUser);

			$subject = $translator->t("Timesheet Notifications") . " - " . $currentTime->format('m-d');
			$plainBody = $translator->t('Hello %1$s,', [$hrUser->getDisplayName()]) . "\n\n";
			$htmlBody = "<!DOCTYPE html><html><body>";
			$htmlBody .= "<p>" . $translator->t('Hello %1$s,', [htmlspecialchars($hrUser->getDisplayName())]) . "</p>";

			$plainBody .= $translator->t(
				'In the employee list you oversee, one or more warnings are currently shown. The details are listed below.'
			) . "\n\n";
			$htmlBody .= "<p>" . $translator->t(
				'In the employee list you oversee, one or more warnings are currently shown. The details are listed below.'
			) . "</p>";

			// Add missing-entry warnings to both message formats.
			if (!empty($notificationData['noEntry'])) {
				$plainBody .= $translator->t('No entry for more than %1$s days:', [$notificationData['noEntryDays']]) . "\n";
				$htmlBody .= "<p><strong>" . $translator->t(
					'No entry for more than %1$s days:',
					[$notificationData['noEntryDays']]
				) . "</strong></p><ul>";
				foreach ($notificationData['noEntry'] as $warning) {
					if ($warning['days'] === null) {
						$plainBody .= " - " . $translator->t(
							'%1$s (more than %2$s days since last entry)',
							[$warning['user'], $notificationData['noEntryDays']]
						) . "\n";
						$htmlBody .= "<li>" . $translator->t(
							'%1$s (more than %2$s days since last entry)',
							[htmlspecialchars($warning['user']), $notificationData['noEntryDays']]
						) . "</li>";
					} else {
						$plainBody .= " - " . $translator->t(
							'%1$s (%2$s days since last entry)',
							[$warning['user'], $warning['days']]
						) . "\n";
						$htmlBody .= "<li>" . $translator->t(
							'%1$s (%2$s days since last entry)',
							[htmlspecialchars($warning['user']), $warning['days']]
						) . "</li>";
					}
				}
				$plainBody .= "\n";
				$htmlBody .= "</ul>";
			}
			// Add overtime warnings.
			if (!empty($notificationData['overtime'])) {
				$plainBody .= $translator->t('Too much overtime:') . "\n";
				$htmlBody .= "<p><strong>" . $translator->t('Too much overtime:') . "</strong></p><ul>";
				foreach ($notificationData['overtime'] as $warning) {
					$plainBody .= " - " . $translator->t('%1$s (%2$s overtime)', [$warning['user'], $warning['overtime']]) . "\n";
					$htmlBody .= "<li>" . $translator->t(
						'%1$s (%2$s overtime)',
						[htmlspecialchars($warning['user']), $warning['overtime']]
					) . "</li>";
				}
				$plainBody .= "\n";
				$htmlBody  .= "</ul>";
			}
			// Add negative-balance warnings.
			if (!empty($notificationData['negative'])) {
				$plainBody .= $translator->t('Too many negative hours:') . "\n";
				$htmlBody .= "<p><strong>" . $translator->t('Too many negative hours:') . "</strong></p><ul>";
				foreach ($notificationData['negative'] as $warning) {
					$plainBody .= " - " . $translator->t('%1$s (%2$s deficit)', [$warning['user'], $warning['deficit']]) . "\n";
					$htmlBody .= "<li>" . $translator->t(
						'%1$s (%2$s deficit)',
						[htmlspecialchars($warning['user']), $warning['deficit']]
					) . "</li>";
				}
				$plainBody .= "\n";
				$htmlBody  .= "</ul>";
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

	private function lForUser(IUser $user): \OCP\IL10N {
		$language = $this->l10nFactory->getUserLanguage($user);
		return $this->l10nFactory->get('timesheet', $language);
	}
}
