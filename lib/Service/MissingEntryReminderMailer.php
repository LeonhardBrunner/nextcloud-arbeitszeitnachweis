<?php

namespace OCA\Timesheet\Service;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/** Creates private employee reminders and separately scoped HR summaries. */
class MissingEntryReminderMailer {
	public function __construct(
		private IMailer $mailer,
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<int,array{date:string,reason:string}> $issues */
	public function sendEmployeeReminder(IUser $employee, array $issues): bool {
		return $this->send($employee, false, [['name' => $employee->getDisplayName(), 'issues' => $issues]]);
	}

	/** @param array<int,array{name:string,issues:array,setupIssue:?string}> $employees */
	public function sendHrSummary(IUser $hrUser, array $employees): bool {
		return $this->send($hrUser, true, $employees);
	}

	private function send(IUser $recipient, bool $isHrSummary, array $employees): bool {
		$emailAddress = trim((string)$recipient->getEMailAddress());
		if ($emailAddress === '') {
			$this->logger->warning('Missing-entry reminder skipped: recipient has no email address.', ['userId' => $recipient->getUID()]);
			return false;
		}

		try {
			$translator = $this->l10nFactory->get('timesheet', $this->l10nFactory->getUserLanguage($recipient));
			$subject = $translator->t($isHrSummary ? 'Timesheet: missing time entries in your team' : 'Timesheet: missing time entries');
			$introduction = $translator->t($isHrSummary
				? 'The following employees have missing or incomplete time entries, or need their reminder settings checked.'
				: 'The following days have no complete time entry.');
			$greeting = $translator->t('Hello %1$s,', [$recipient->getDisplayName()]);
			$plainLines = [$greeting, '', $introduction, ''];
			$htmlSections = ['<p>' . $this->escape($greeting) . '</p>', '<p>' . $this->escape($introduction) . '</p>'];

			foreach ($employees as $employee) {
				if ($isHrSummary) {
					$plainLines[] = $employee['name'];
					$htmlSections[] = '<h3>' . $this->escape($employee['name']) . '</h3>';
				}
				$setupIssue = $employee['setupIssue'] ?? null;
				if ($setupIssue !== null) {
					$notice = $this->setupNotice($translator, $setupIssue);
					$plainLines[] = $notice;
					$htmlSections[] = '<p>' . $this->escape($notice) . '</p>';
				}
				$htmlRows = [];
				foreach ($employee['issues'] as $issue) {
					$status = $translator->t($issue['reason'] === 'incomplete' ? 'Incomplete entry' : 'Missing entry');
					$line = $issue['date'] . ': ' . $status;
					$plainLines[] = ' - ' . $line;
					$htmlRows[] = '<li>' . $this->escape($line) . '</li>';
				}
				if ($htmlRows !== []) {
					$htmlSections[] = '<ul>' . implode('', $htmlRows) . '</ul>';
				}
				$plainLines[] = '';
			}

			$appUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('timesheet.page.index'));
			$footer = $translator->t('This is an automatic reminder based on the group rules in Nextcloud.');
			$plainLines[] = $translator->t('Open Timesheet') . ': ' . $appUrl;
			$plainLines[] = '';
			$plainLines[] = $footer;
			$htmlSections[] = '<p><a href="' . $this->escape($appUrl) . '">' . $this->escape($translator->t('Open Timesheet')) . '</a></p>';
			$htmlSections[] = '<hr><p>' . $this->escape($footer) . '</p>';

			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setTo([$emailAddress => $recipient->getDisplayName()]);
			$message->setPlainBody(implode("\n", $plainLines));
			$message->setHtmlBody('<!DOCTYPE html><html><body>' . implode('', $htmlSections) . '</body></html>');
			$failedRecipients = $this->mailer->send($message);
			if ($failedRecipients !== []) {
				throw new \RuntimeException('The mail transport rejected a reminder recipient.');
			}
			return true;
		} catch (\Throwable $sendError) {
			$this->logger->error('Missing-entry reminder could not be sent.', [
				'userId' => $recipient->getUID(),
				'exception' => $sendError,
			]);
			return false;
		}
	}

	private function setupNotice(IL10N $translator, string $reason): string {
		return $translator->t(match ($reason) {
			'missingRegion' => 'Reminder check paused: set a valid German federal state for this employee.',
			'holidayUnavailable' => 'Reminder check paused: public holidays could not be loaded. The check will be retried.',
			default => 'Reminder check paused because the employee data could not be checked. See the Nextcloud log.',
		});
	}

	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
