import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coverage = JSON.parse(readFileSync(resolve(root, 'tests/settings/assignment-translation-coverage.json'), 'utf8'));
const accountLanguages = JSON.parse(readFileSync(resolve(root, 'tests/employment/translation-coverage.json'), 'utf8')).locales;
const messages = new Set(coverage.messages);
const notificationMessages = [
	'Alert',
	'Alert threshold',
	'Alerts for HR',
	'Check entries starting on',
	'Checks run daily. Open dates are collected in one email, with at least seven days between emails of the same kind.',
	'Days since the last complete entry',
	'Days that require a time entry; public holidays in the employee state are excluded.',
	'Each HR account sets its own thresholds for alerts about the employees it is responsible for. The same threshold applies to each alert in the employee list and the weekly email to HR.',
	'Employee list and weekly email to HR',
	'Employees can also get an email about their own time balance on Mondays if the group rule enables it and their balance exceeds the threshold.',
	'Enter whole days or hours and minutes in the format HH:MM.',
	'Existing members use the chosen start date. Later members are checked from their first detection. A state is required for the holiday check.',
	'First reminder at the earliest',
	'Friday',
	'Group rule',
	'Include in my weekly email',
	'Monday',
	'Negative hours',
	'Notification settings could not be loaded. Reload the page to try again.',
	'Notification settings explained',
	'Notifications',
	'Notifications by group',
	'Overtime',
	'Reminder for missing or incomplete time entries',
	'Remove',
	'Saturday',
	'Save failed',
	'Saved',
	'Saving failed',
	'Select a valid start date for reminders.',
	'Select at least one weekday for reminders.',
	'Sunday',
	'The employee list shows the current alerts in the app. The weekly email to HR bundles the selected alerts that still apply on Monday.',
	'The new rule works as in this example. Employee A records no working time for Tuesday. On Wednesday, employee B only records that he started at 8 a.m. With a two-calendar-day waiting period, employee A receives an email notification on Thursday and employee B on Friday.',
	'The reminder delay must be a whole number from 1 to 365.',
	'The reminder shows which workdays still need an entry or a correction. The group rule lets you change which HR account receives the notification email.',
	'This alert highlights long gaps in time tracking. At a threshold of 14 days, HR can see who has gone at least 14 days without a complete time entry.',
	'This alert shows when an employee last made an entry in the timesheet. The threshold in calendar days determines when HR is notified.',
	'Thursday',
	'Tuesday',
	'Wednesday',
	'Weekdays',
	'Weekly email to HR',
	'When checked, this alert is also included in the weekly summary email.',
	'With a threshold of 10 hours, HR can see in the employee list who has 10 or more hours of overtime.',
	'With a threshold of 10 hours, HR can see in the employee list who has a time deficit of 10 hours or more.',
	'calendar days',
	'calendar days after the workday',
	'hours',
];
const automaticMailMessages = [
	'%1$s (%2$s days without a complete entry)',
	'%1$s (%2$s deficit)',
	'%1$s (%2$s overtime)',
	'For questions about the working time balance or recorded entries, contact the responsible HR team.',
	'Hello %1$s,',
	'Incomplete entry',
	'Missing entry',
	'Negative overtime warning',
	'No complete entry for at least %1$s days:',
	'Notification threshold: %1$s hours.',
	'Open Timesheet',
	'Overtime warning',
	'Reminder check paused because the employee data could not be checked. See the Nextcloud log.',
	'Reminder check paused: public holidays could not be loaded. The check will be retried.',
	'Reminder check paused: set a valid German federal state for this employee.',
	'The following days have no complete time entry.',
	'The following employees have missing or incomplete time entries, or need their reminder settings checked.',
	'This email was automatically generated based on the notification settings you selected in Nextcloud. Do not reply to this email.',
	'This is an automatic reminder based on the group rules in Nextcloud.',
	'This message was generated automatically.',
	'Timesheet Notifications',
	'Timesheet: missing time entries',
	'Timesheet: missing time entries in your team',
	'Timesheet: working time balance',
	'Too many negative hours:',
	'Too much overtime:',
	'Working time balance: %1$s hours.',
];
const appRequestLabel = 'Do you have any requests for the app?';
const germanAppRequestDraft = {
	'Change request for the Timesheet app': 'Änderungswunsch an der Arbeitszeitapp',
	'Dear Timesheet team,': 'Liebes Arbeitszeitapp-Team,',
	'I would like to suggest a change.': 'ich möchte eine Änderung vorschlagen.',
	'Kind regards,': 'Mit freundlichen Grüßen.',
};
const placeholders = value => [...value.matchAll(/\{\w+\}|%\d+\$[ds]/g)].map(match => match[0]).sort();

test('HR assignment and notification translations cover all existing account languages', () => {
	assert.deepEqual(coverage.locales, accountLanguages);
	assert.equal(coverage.messages.length, messages.size);
	assert.equal(messages.size, 39);
	assert.equal(notificationMessages.length, new Set(notificationMessages).size);
	for (const message of notificationMessages) assert.ok(!messages.has(message), message);
});

// Feature messages match in both catalogs; other features may add or revise their own translations.
for (const [locale, pluralForm] of Object.entries(coverage.locales)) {
	test(`complete HR assignment and notification translations with matching catalogs: ${locale}`, () => {
		const server = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8'));
		let browser;
		vm.runInNewContext(readFileSync(resolve(root, 'l10n', `${locale}.js`), 'utf8'), {
			OC: { L10N: { register(app, entries, forms) {
				assert.equal(app, 'timesheet');
				assert.equal(forms, pluralForm);
				browser = entries;
			} } },
		});
		assert.equal(server.pluralForm, pluralForm);
		assert.ok(browser);
		for (const message of [...messages, ...notificationMessages, ...automaticMailMessages, appRequestLabel, ...Object.keys(germanAppRequestDraft)]) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message));
			const sameAsEnglish = locale.startsWith('en') || (locale === 'fr' && message === 'Notifications');
			if (!sameAsEnglish) assert.notEqual(translated, message, `Untranslated ${locale} message: ${message}`);
		}
	});
}

test('both German variants use concise, neutral assignment and delivery wording', () => {
	const german = ['de', 'de_DE'].map(locale => JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations);
	for (const message of messages) {
		assert.equal(german[0][message], german[1][message]);
		assert.doesNotMatch(german[0][message], /\b(?:Du|du|Dich|dich|Dein|dein|Deine|deine|Sie|Ihnen|Ihr)\b/);
	}
	assert.equal(german[0][appRequestLabel], 'Hast Du Wünsche für die App?');
	assert.equal(german[1][appRequestLabel], 'Haben Sie Wünsche für die App?');
	for (const [message, translated] of Object.entries(germanAppRequestDraft)) {
		for (const catalog of german) assert.equal(catalog[message], translated);
	}
	assert.equal(german[0]['Individual HR accounts'], 'Einzelne HR-Konten');
	assert.equal(german[0]['Email recipients'], 'E-Mail-Empfänger');
	assert.equal(german[0]['All responsible HR accounts'], 'Alle zuständigen HR-Konten');
	assert.equal(german[0]['Selected HR accounts'], 'Ausgewählte HR-Konten');
	assert.equal(german[0]['No selected accounts means no HR summary for this rule.'], 'Ohne ausgewählte Konten wird für diese Regel keine HR-Übersicht versendet.');
	assert.equal(german[0]['HR assignments or email recipients are invalid.'], 'HR-Zuordnungen oder E-Mail-Empfänger sind ungültig.');
	assert.equal(german[0]['Email selection does not change HR access.'], 'Hier steht, wer die HR-Mails für diese Gruppe erhält. Die HR-Zuständigkeit wird in der Administration festgelegt.');
	assert.equal(german[0]['Send reminders to employees'], 'Erinnerungen an Mitarbeitende senden');
	assert.equal(german[0]['Send reminders to responsible HR'], 'Erinnerungen an zuständiges HR senden');
	assert.equal(german[0]['Employee balance notifications'], 'Benachrichtigungen zum Stundenstand');
	assert.equal(german[0]['My HR email settings'], 'Meine HR-Mail-Einstellungen');
	assert.equal(german[0]['Group rules explained'], 'Hinweise zu den Gruppenregeln');
	assert.equal(german[0]['Notify employees about overtime'], 'Mitarbeitende bei Überstunden benachrichtigen');
	assert.equal(german[0]['Notify employees about negative hours'], 'Mitarbeitende bei Minusstunden benachrichtigen');
});

test('Japanese and right-to-left accounts have translated search, permissions and mail guidance', () => {
	for (const locale of ['ja', 'ar', 'he', 'fa', 'ug']) {
		const translated = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations;
		for (const message of messages) assert.notEqual(translated[message], message);
	}
});

test('employee balance emails retain placeholders and matching German variants', () => {
	const mailMessages = [
		'Timesheet: working time balance',
		'Working time balance: %1$s hours.',
		'Notification threshold: %1$s hours.',
		'For questions about the working time balance or recorded entries, contact the responsible HR team.',
		'This message was generated automatically.',
	];
	const catalog = locale => JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations;
	const german = catalog('de');
	const formalGerman = catalog('de_DE');
	const english = catalog('en_GB');
	for (const message of mailMessages) {
		assert.equal(typeof german[message], 'string');
		assert.ok(german[message].trim());
		assert.equal(german[message], formalGerman[message]);
		assert.equal(english[message], message);
		assert.deepEqual([...german[message].matchAll(/%\d+\$[sd]/g)].map(match => match[0]), [...message.matchAll(/%\d+\$[sd]/g)].map(match => match[0]));
	}
	assert.equal(german[mailMessages[3]], 'Bei Fragen zum Stundenstand oder zu den erfassten Zeiten hilft die zuständige Personalabteilung.');
});

test('weekly HR email addresses each German account with its selected form of address', () => {
	const key = 'This email was automatically generated based on the notification settings you selected in Nextcloud. Do not reply to this email.';
	const footer = locale => JSON.parse(readFileSync(resolve(root, 'l10n', locale + '.json'), 'utf8')).translations[key];
	assert.match(footer('de'), /\bDu\b/);
	assert.match(footer('de_DE'), /\bSie\b/);
});
