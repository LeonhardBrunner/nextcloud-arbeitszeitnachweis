<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/** Sends only the recipient's own balance, with a contact hint for questions about recorded time. */
class EmployeeBalanceNotificationMailer {
	public function __construct(
		private IMailer $mailer,
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function sendBalanceNotification(IUser $recipient, int $balanceMinutes, int $thresholdMinutes, string $warningType): bool {
		if (!in_array($warningType, ['overtime', 'negative'], true)) {
			throw new \InvalidArgumentException('Unknown employee balance warning type.');
		}
		$emailAddress = trim((string)$recipient->getEMailAddress());
		if ($emailAddress === '') {
			$this->logger->warning('Employee balance mail skipped: recipient has no email address.', ['userId' => $recipient->getUID()]);
			return false;
		}
		try {
			$translator = $this->l10nFactory->get('timesheet', $this->l10nFactory->getUserLanguage($recipient));
			$subject = $translator->t('Timesheet: working time balance');
			$greeting = $translator->t('Hello %1$s,', [$recipient->getDisplayName()]);
			$title = $translator->t($warningType === 'overtime' ? 'Overtime warning' : 'Negative overtime warning');
			$balance = $translator->t('Working time balance: %1$s hours.', [$this->formatMinutes($balanceMinutes)]);
			$threshold = $translator->t('Notification threshold: %1$s hours.', [$this->formatMinutes($warningType === 'negative' ? -$thresholdMinutes : $thresholdMinutes)]);
			$contact = $translator->t('For questions about the working time balance or recorded entries, contact the responsible HR team.');
			$footer = $translator->t('This message was generated automatically.');
			$linkLabel = $translator->t('Open Timesheet');
			$appUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('timesheet.page.index'));
			$plainBody = implode("\n\n", [$greeting, $title, $balance, $threshold, $contact, $linkLabel . ': ' . $appUrl, $footer]);
			$htmlBody = '<!DOCTYPE html><html><body><p>' . $this->escape($greeting) . '</p>'
				. '<h2>' . $this->escape($title) . '</h2><p>' . $this->escape($balance) . '</p>'
				. '<p>' . $this->escape($threshold) . '</p><p>' . $this->escape($contact) . '</p>'
				. '<p><a href="' . $this->escape($appUrl) . '">' . $this->escape($linkLabel) . '</a></p>'
				. '<hr><p>' . $this->escape($footer) . '</p></body></html>';
			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setTo([$emailAddress => $recipient->getDisplayName()]);
			$message->setPlainBody($plainBody);
			$message->setHtmlBody($htmlBody);
			if ($this->mailer->send($message) !== []) {
				throw new \RuntimeException('The mail transport rejected an employee balance recipient.');
			}
			return true;
		} catch (\Throwable $sendError) {
			$this->logger->error('Employee balance notification could not be sent.', ['userId' => $recipient->getUID(), 'exception' => $sendError]);
			return false;
		}
	}

	private function formatMinutes(int $minutes): string {
		$sign = $minutes < 0 ? '-' : ($minutes > 0 ? '+' : '');
		$absoluteMinutes = abs($minutes);
		return $sign . sprintf('%02d:%02d', intdiv($absoluteMinutes, 60), $absoluteMinutes % 60);
	}

	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
