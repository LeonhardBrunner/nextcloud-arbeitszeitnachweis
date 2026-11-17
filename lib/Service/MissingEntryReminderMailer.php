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

	public static function originalBody(IL10N $translator, bool $isHrSummary): string {
		[$greeting, $introduction, $linkLabel, $footer] = self::originalParts($translator, $isHrSummary, '{recipient}');
		return implode("\n\n", [$greeting, $introduction, '{entries}', $linkLabel . ': {appUrl}', $footer]);
	}

	/**
	 * @param array<int,array{date:string,reason:string}> $issues
	 * @param array<string,string> $mailTemplates
	 */
	public function sendEmployeeReminder(IUser $employee, array $issues, array $mailTemplates = []): bool {
		return $this->send($employee, false, [[
			'name' => $employee->getDisplayName(),
			'issues' => $issues,
			'mailTemplates' => $mailTemplates,
		]]);
	}

	/** @param array<int,array{name:string,issues:array,setupIssue:?string,mailTemplates?:array<string,string>}> $employees */
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
			$language = $this->l10nFactory->getLanguageIterator($recipient)->current();
			$translator = $this->l10nFactory->get('timesheet', $language);
			$subject = $translator->t($isHrSummary ? 'Timesheet: missing time entries in your team' : 'Timesheet: missing time entries');
			$appUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('timesheet.page.index'));
			[$plainBody, $htmlBody] = $this->renderBodies($translator, $language, $recipient->getDisplayName(), $isHrSummary, $employees, $appUrl);

			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setTo([$emailAddress => $recipient->getDisplayName()]);
			$message->setPlainBody($plainBody);
			$message->setHtmlBody('<!DOCTYPE html><html><body>' . $htmlBody . '</body></html>');
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

	private static function originalParts(IL10N $translator, bool $isHrSummary, string $recipient): array {
		return [
			$translator->t('Hello %1$s,', [$recipient]),
			$translator->t($isHrSummary
				? 'The following employees have missing or incomplete time entries, or need their reminder settings checked.'
				: 'The following days have missing or incomplete time entries.'),
			$translator->t('Open Timesheet'),
			$translator->t('This is an automatic reminder based on the group rules in Nextcloud.'),
		];
	}

	private function renderBodies(IL10N $translator, string $language, string $recipient, bool $isHrSummary, array $employees, string $appUrl): array {
		// One summary can span several rules. Each text covers only the findings from rules using it.
		$groups = [];
		foreach ($employees as $employee) {
			$body = $employee['mailTemplates'][$language] ?? '';
			$groups[$body][] = $employee;
		}
		if ($groups === []) {
			$groups[''] = [];
		}

		$plainSections = [];
		$htmlSections = [];
		foreach ($groups as $body => $findings) {
			[$plainLines, $htmlFindings] = $this->renderFindings($translator, $isHrSummary, $findings);
			$escapedUrl = $this->escape($appUrl);
			if ($body === '') {
				[$greeting, $introduction, $linkLabel, $footer] = self::originalParts($translator, $isHrSummary, $recipient);
				$plainSections[] = implode("\n", [$greeting, '', $introduction, '', ...$plainLines, $linkLabel . ': ' . $appUrl, '', $footer]);
				$htmlSections[] = '<p>' . $this->escape($greeting) . '</p>'
					. '<p>' . $this->escape($introduction) . '</p>'
					. $htmlFindings
					. '<p><a href="' . $escapedUrl . '">' . $this->escape($linkLabel) . '</a></p>'
					. '<hr><p>' . $this->escape($footer) . '</p>';
				continue;
			}

			$plainSections[] = strtr($body, [
				'{recipient}' => $recipient,
				'{entries}' => implode("\n", array_slice($plainLines, 0, -1)),
				'{appUrl}' => $appUrl,
			]);
			// Expand only the known tokens after escaping user text, including any HTML entered in the editor.
			$htmlSections[] = '<div>' . strtr(nl2br($this->escape($body), false), [
				'{recipient}' => $this->escape($recipient),
				'{entries}' => $htmlFindings,
				'{appUrl}' => '<a href="' . $escapedUrl . '">' . $escapedUrl . '</a>',
			]) . '</div>';
		}

		return [implode("\n\n", $plainSections), implode('<hr>', $htmlSections)];
	}

	private function renderFindings(IL10N $translator, bool $isHrSummary, array $employees): array {
		$plainLines = [];
		$htmlSections = [];
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
				$date = $translator->l('date', $issue['date'], ['width' => 'medium']);
				$line = $date . ': ' . $status;
				$plainLines[] = ' - ' . $line;
				$htmlRows[] = '<li>' . $this->escape($line) . '</li>';
			}
			if ($htmlRows !== []) {
				$htmlSections[] = '<ul>' . implode('', $htmlRows) . '</ul>';
			}
			$plainLines[] = '';
		}
		return [$plainLines, implode('', $htmlSections)];
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
