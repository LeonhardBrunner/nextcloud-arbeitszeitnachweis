<?php

/** Standalone mail checks; run in a separate PHP process without Nextcloud bootstrap. */
namespace OCP {
	interface IUser {
		public function getUID(): string;
		public function getDisplayName(): string;
		public function getEMailAddress(): ?string;
	}
	interface IL10N {
		public function t(string $text, array $parameters = []): string;
	}
	interface IURLGenerator {
		public function linkToRoute(string $route): string;
		public function getAbsoluteURL(string $url): string;
	}
}

namespace OCP\L10N {
	interface IFactory {
		public function getUserLanguage(\OCP\IUser $user): string;
		public function get(string $app, ?string $language = null): \OCP\IL10N;
	}
}

namespace OCP\Mail {
	interface IMailer {
		public function createMessage(): object;
		public function send(object $message): array;
	}
}

namespace Psr\Log {
	interface LoggerInterface {
		public function warning(string $message, array $context = []): void;
		public function error(string $message, array $context = []): void;
	}
}

namespace {
	use OCA\Timesheet\Service\MissingEntryReminderMailer;
	use OCP\IUser;

	require_once __DIR__ . '/../../lib/Service/MissingEntryReminderMailer.php';

	class ReminderMailRecipient implements IUser {
		public function __construct(private string $userId, private string $name, private ?string $email) {
		}
		public function getUID(): string {
			return $this->userId;
		}
		public function getDisplayName(): string {
			return $this->name;
		}
		public function getEMailAddress(): ?string {
			return $this->email;
		}
	}

	class CapturedReminderMessage {
		public string $subject = '';
		public array $to = [];
		public string $plainBody = '';
		public string $htmlBody = '';
		public function setSubject(string $subject): void {
			$this->subject = $subject;
		}
		public function setTo(array $to): void {
			$this->to = $to;
		}
		public function setPlainBody(string $body): void {
			$this->plainBody = $body;
		}
		public function setHtmlBody(string $body): void {
			$this->htmlBody = $body;
		}
	}

	function createReminderMailFixture(): array {
		$transport = new class implements \OCP\Mail\IMailer {
			public array $messages = [];
			public array $failedRecipients = [];
			public bool $throwOnSend = false;
			public int $createdMessages = 0;
			public function createMessage(): object {
				$this->createdMessages++;
				return new CapturedReminderMessage();
			}
			public function send(object $message): array {
				$this->messages[] = $message;
				if ($this->throwOnSend) {
					throw new \RuntimeException('Synthetic SMTP failure');
				}
				return $this->failedRecipients;
			}
		};
		$translations = new class implements \OCP\L10N\IFactory {
			public array $userLanguages = ['employee-a' => 'de', 'employee-b' => 'en', 'hr-a' => 'en'];
			public array $requestedUsers = [];
			public array $requestedLanguages = [];
			public array $requestedTexts = [];
			public function getUserLanguage(IUser $user): string {
				$this->requestedUsers[] = $user->getUID();
				return $this->userLanguages[$user->getUID()] ?? 'en';
			}
			public function get(string $app, ?string $language = null): \OCP\IL10N {
				$this->requestedLanguages[] = [$app, $language];
				return new class ($this, $language) implements \OCP\IL10N {
					private const GERMAN_TEXTS = [
						'Timesheet: missing time entries' => 'Timesheet: fehlende Zeiteinträge',
						'Hello %1$s,' => 'Hallo %1$s,',
						'Missing entry' => 'Fehlender Eintrag',
						'Incomplete entry' => 'Unvollständiger Eintrag',
						'Open Timesheet' => 'Timesheet öffnen',
					];
					public function __construct(private object $factory, private ?string $language) {
					}
					public function t(string $text, array $parameters = []): string {
						$this->factory->requestedTexts[] = $text;
						$translated = $this->language === 'de' ? (self::GERMAN_TEXTS[$text] ?? $text) : $text;
						return $parameters === [] ? $translated : vsprintf($translated, $parameters);
					}
				};
			}
		};
		$urls = new class implements \OCP\IURLGenerator {
			public array $requestedRoutes = [];
			public string $appUrl = 'https://demo.example.test/apps/timesheet/?label="open"&section=<entries>';
			public function linkToRoute(string $route): string {
				$this->requestedRoutes[] = $route;
				return '/apps/timesheet/';
			}
			public function getAbsoluteURL(string $url): string {
				return $this->appUrl;
			}
		};
		$logger = new class implements \Psr\Log\LoggerInterface {
			public array $warnings = [];
			public array $errors = [];
			public function warning(string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
			public function error(string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		};
		return [new MissingEntryReminderMailer($transport, $translations, $urls, $logger), $transport, $translations, $urls, $logger];
	}

	$checks = 0;
	$expectSame = static function (mixed $expected, mixed $actual, string $description) use (&$checks): void {
		$checks++;
		if ($expected !== $actual) {
			throw new \RuntimeException($description . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
	};
	$expectContains = static function (string $expected, string $actual, string $description) use ($expectSame): void {
		$expectSame(true, str_contains($actual, $expected), $description);
	};
	$issues = [['date' => '2026-10-01', 'reason' => 'missing'], ['date' => '2026-10-02', 'reason' => 'incomplete']];
	$employee = new ReminderMailRecipient('employee-a', 'Alex <script> & "Team"', 'alex@example.test');

	// Each message has one private recipient and two useful body formats.
	[$mailer, $transport, $translations, $urls, $logger] = createReminderMailFixture();
	$expectSame(true, $mailer->sendEmployeeReminder($employee, $issues), 'successful employee mail');
	$expectSame(1, count($transport->messages), 'one message created');
	$message = $transport->messages[0];
	$expectSame(['alex@example.test' => 'Alex <script> & "Team"'], $message->to, 'one private To address');
	$expectSame('Timesheet: fehlende Zeiteinträge', $message->subject, 'German subject chosen');
	$expectSame(['employee-a'], $translations->requestedUsers, 'recipient determines translation language');
	$expectSame([['timesheet', 'de']], $translations->requestedLanguages, 'German timesheet translator requested');
	$expectContains('Hallo Alex <script> & "Team",', $message->plainBody, 'plain greeting preserves readable characters');
	$expectContains('2026-10-01: Fehlender Eintrag', $message->plainBody, 'plain missing date and reason');
	$expectContains('2026-10-02: Unvollständiger Eintrag', $message->plainBody, 'plain incomplete date and reason');
	$expectContains('<li>2026-10-01: Fehlender Eintrag</li>', $message->htmlBody, 'HTML missing date and reason');
	$expectContains('<li>2026-10-02: Unvollständiger Eintrag</li>', $message->htmlBody, 'HTML incomplete date and reason');
	$expectContains('Alex &lt;script&gt; &amp; &quot;Team&quot;', $message->htmlBody, 'HTML name escaped');
	$expectSame(false, str_contains($message->htmlBody, '<script>'), 'raw name markup is not executable HTML');
	$expectContains('href="https://demo.example.test/apps/timesheet/?label=&quot;open&quot;&amp;section=&lt;entries&gt;"', $message->htmlBody, 'link attribute escaped');
	$expectContains($urls->appUrl, $message->plainBody, 'plain link remains usable');
	$expectSame(['timesheet.page.index'], $urls->requestedRoutes, 'app route used');
	$expectSame([], $logger->errors, 'successful mail has no delivery error');

	// Subsequent recipients cannot inherit another employee's name or dates.
	$otherEmployee = new ReminderMailRecipient('employee-b', 'Morgan', 'morgan@example.test');
	$expectSame(true, $mailer->sendEmployeeReminder($otherEmployee, [['date' => '2026-10-09', 'reason' => 'missing']]), 'second employee mail');
	$otherMessage = $transport->messages[1];
	$expectSame(['morgan@example.test' => 'Morgan'], $otherMessage->to, 'second message has its own private recipient');
	$expectSame('Timesheet: missing time entries', $otherMessage->subject, 'English subject chosen');
	$expectSame([['timesheet', 'de'], ['timesheet', 'en']], $translations->requestedLanguages, 'each recipient gets their own language');
	$expectSame(false, str_contains($otherMessage->plainBody, 'Alex'), 'second plain body contains no first employee name');
	$expectSame(false, str_contains($otherMessage->htmlBody, '2026-10-01'), 'second HTML body contains no first employee dates');
	$expectSame(false, str_contains($message->plainBody, 'Morgan'), 'first plain body contains no second employee name');
	$expectSame(false, str_contains($message->htmlBody, '2026-10-09'), 'first HTML body contains no second employee dates');

	// HR receives its separately supplied summary and the explicit setup notices.
	[$mailer, $transport, $translations] = createReminderMailFixture();
	$hrUser = new ReminderMailRecipient('hr-a', 'HR Team', 'hr@example.test');
	$summary = [
		['name' => 'Alex <script>', 'issues' => $issues, 'setupIssue' => null],
		['name' => 'Missing Region', 'issues' => [], 'setupIssue' => 'missingRegion'],
		['name' => 'Holiday Failure', 'issues' => [], 'setupIssue' => 'holidayUnavailable'],
		['name' => 'Check Failure', 'issues' => [], 'setupIssue' => 'inspectionFailed'],
	];
	$expectSame(true, $mailer->sendHrSummary($hrUser, $summary), 'HR summary delivered');
	$summaryMessage = $transport->messages[0];
	$expectSame(['hr@example.test' => 'HR Team'], $summaryMessage->to, 'HR summary has only HR recipient');
	$expectSame('Timesheet: missing time entries in your team', $summaryMessage->subject, 'HR-specific English subject');
	$expectContains('<h3>Alex &lt;script&gt;</h3>', $summaryMessage->htmlBody, 'HR employee heading escaped');
	$expectContains('2026-10-02: Incomplete entry', $summaryMessage->plainBody, 'HR sees supplied incomplete date');
	$expectContains('set a valid German federal state', $summaryMessage->plainBody, 'missing-region setup notice');
	$expectContains('public holidays could not be loaded', $summaryMessage->plainBody, 'holiday failure setup notice');
	$expectContains('See the Nextcloud log.', $summaryMessage->plainBody, 'inspection failure setup notice');
	$expectSame([['timesheet', 'en']], $translations->requestedLanguages, 'HR language selected independently');

	// Rejected recipients are failures even when the transport does not throw.
	[$mailer, $transport, $translations, $urls, $logger] = createReminderMailFixture();
	$transport->failedRecipients = ['alex@example.test'];
	$expectSame(false, $mailer->sendEmployeeReminder($employee, $issues), 'rejected-recipient return is not successful delivery');
	$expectSame(1, count($logger->errors), 'rejected recipient logged');
	$expectSame('employee-a', $logger->errors[0][1]['userId'], 'delivery error identifies correct user');

	[$mailer, $transport, $translations, $urls, $logger] = createReminderMailFixture();
	$transport->throwOnSend = true;
	$expectSame(false, $mailer->sendEmployeeReminder($employee, $issues), 'SMTP exception is contained');
	$expectSame(1, count($logger->errors), 'SMTP exception logged once');
	$expectSame('Synthetic SMTP failure', $logger->errors[0][1]['exception']->getMessage(), 'original exception retained in log');

	// Missing addresses never create a message or call the SMTP transport.
	foreach ([null, '', '   '] as $emptyAddress) {
		[$mailer, $transport, $translations, $urls, $logger] = createReminderMailFixture();
		$withoutEmail = new ReminderMailRecipient('no-email', 'No Email', $emptyAddress);
		$expectSame(false, $mailer->sendEmployeeReminder($withoutEmail, $issues), 'missing address skips delivery');
		$expectSame(0, $transport->createdMessages, 'missing address creates no message');
		$expectSame([], $transport->messages, 'missing address never invokes transport');
		$expectSame('no-email', $logger->warnings[0][1]['userId'], 'missing-address warning identifies recipient');
	}

	fwrite(STDOUT, 'Reminder mailer: ' . $checks . " checks passed.\n");
}
