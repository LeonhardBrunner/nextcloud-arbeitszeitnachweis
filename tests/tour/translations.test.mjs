import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coverage = JSON.parse(readFileSync(resolve(root, 'tests/tour/translation-coverage.json'), 'utf8'));
const absenceTerms = JSON.parse(readFileSync(resolve(root, 'lib/Service/absence-comment-terms.json'), 'utf8'));
const absenceHint = 'For days without start and end times, only the exact comments "%1$s" and "%2$s" prevent a reminder. Letter case and surrounding spaces are ignored. Entries with only one time remain incomplete.';
const workingTimeExplanation = 'The start of work can be chosen individually in accordance with company guidelines.';
const federalStateExplanation = 'The public holidays shown in the Status column depend on the selected federal state.';
const commentsExplanation = 'Comments add context to a day. An absence can be marked with "{vacation}" or "{timeOffInLieu}".';
const generalHelpExplanation = 'Questions, help and privacy information about Nextcloud in general.';
const restartExplanation = 'Questions and suggestions for HR. The guide can be restarted using the tour icon.';
const warningsExplanation = 'Breaks that exceed or fall below the limits and missing timesheets trigger warnings and should be corrected promptly.';
const freelancerExplanation = 'Freelancer mode disables warning emails about missing or incomplete entries, overtime and negative balances for the employee and HR.';
const emailHelpMessages = {
	'Opening the default mail app…': 'Standard-Mailprogramm wird aufgerufen.',
	'No mail app opened?': 'Kein Mailprogramm geöffnet?',
	'Email links require a default mail app in the browser or operating system.': 'Für E-Mail-Links muss im Browser oder Betriebssystem ein Standard-Mailprogramm ausgewählt sein.',
	'Close email help': 'E-Mail-Hinweis schließen',
};
const contactDraftMessages = [
	'Question about the Timesheet app',
	'Feedback on the Timesheet app',
	'Hello Timesheet team,',
	'Best regards,',
	'Your name',
];
const placeholders = text => [...text.matchAll(/\{\w+\}/g)].map(match => match[0]).sort();

// Every supported account language must contain the complete tour in both runtimes.
for (const [locale, pluralForm] of Object.entries(coverage.locales)) {
	test(`complete tour messages and matching browser/server catalogs: ${locale}`, () => {
		const server = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8'));
		let browser;
		vm.runInNewContext(readFileSync(resolve(root, 'l10n', `${locale}.js`), 'utf8'), {
			OC: { L10N: { register(app, values, forms) {
				assert.equal(app, 'timesheet');
				browser = values;
				assert.equal(forms, pluralForm);
			} } }
		});
		assert.ok(browser, 'Browser catalog registers its translations');
		assert.equal(server.pluralForm, pluralForm);
		for (const message of [...coverage.messages, ...contactDraftMessages]) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message));
			if (message.includes('An absence can be marked')) {
				assert.deepEqual(placeholders(translated), ['{timeOffInLieu}', '{vacation}'], 'Absence labels come from the shared language catalog');
				assert.doesNotMatch(translated, /Urlaub|Stundenausgleich/u, 'Tour text does not hard-code German absence labels');
			}
		}
		assert.equal(typeof server.translations[absenceHint], 'string', 'HR absence guidance is translated');
		assert.equal(browser[absenceHint], server.translations[absenceHint]);
		assert.deepEqual([...server.translations[absenceHint].matchAll(/%\d\$s/g)].map(match => match[0]).sort(), ['%1$s', '%2$s']);
		assert.ok(absenceTerms[locale], 'The displayed absence terms are available for this account language');
		if (!locale.startsWith('en')) {
			assert.notEqual(server.translations['A short tour explains the main features.'], 'A short tour explains the main features.', 'An English copy is not a translated tour');
			for (const message of [workingTimeExplanation, federalStateExplanation, generalHelpExplanation, 'Help and tour', 'Tour guide', 'Questions for HR', 'Suggestions for improvement', restartExplanation, 'Time entry warnings', warningsExplanation, 'Freelancer mode', freelancerExplanation, ...Object.keys(emailHelpMessages), ...contactDraftMessages]) {
				assert.notEqual(server.translations[message], message, 'New explanations are translated into the account language');
			}
		}
		assert.doesNotMatch(server.translations[workingTimeExplanation], /0?7:30|0?9:30/u, 'Working time follows company guidelines without fixed start times');
	});
}

test('shared absence labels cover all account languages and preserve the German and English terms', () => {
	assert.deepEqual(Object.keys(absenceTerms).sort(), ['en', ...Object.keys(coverage.locales)].sort());
	for (const [locale, terms] of Object.entries(absenceTerms)) {
		assert.deepEqual(Object.keys(terms).sort(), ['timeOffInLieu', 'vacation']);
		for (const term of Object.values(terms)) {
			assert.equal(typeof term, 'string');
			assert.ok(term.trim(), `Empty absence term for ${locale}`);
			assert.equal(term.trim(), term);
			assert.doesNotMatch(term, /[{}%]/u, 'Labels contain no unresolved placeholders');
		}
		assert.notEqual(terms.vacation, terms.timeOffInLieu, `Distinct absence reasons for ${locale}`);
	}
	for (const locale of ['de', 'de_DE']) assert.deepEqual(absenceTerms[locale], { vacation: 'Urlaub', timeOffInLieu: 'Stundenausgleich' });
	for (const locale of ['en', 'en_GB']) assert.deepEqual(absenceTerms[locale], { vacation: 'Vacation', timeOffInLieu: 'Time off in lieu' });
});

test('the two German tour variants use the same neutral explanations', () => {
	const informal = JSON.parse(readFileSync(resolve(root, 'l10n/de.json'), 'utf8')).translations;
	const formal = JSON.parse(readFileSync(resolve(root, 'l10n/de_DE.json'), 'utf8')).translations;
	for (const message of coverage.messages) assert.equal(informal[message], formal[message]);
	assert.equal(informal['Your name'], 'Dein Name');
	assert.equal(formal['Your name'], 'Ihr Name');
});

test('Japanese and right-to-left tours have their own translated explanations', () => {
	const japanese = JSON.parse(readFileSync(resolve(root, 'l10n/ja.json'), 'utf8')).translations;
	const arabic = JSON.parse(readFileSync(resolve(root, 'l10n/ar.json'), 'utf8')).translations;
	const hebrew = JSON.parse(readFileSync(resolve(root, 'l10n/he.json'), 'utf8')).translations;
	assert.match(japanese['Explore Timesheet?'], /[\u3040-\u30ff\u4e00-\u9fff]/u);
	assert.match(arabic['Explore Timesheet?'], /[\u0600-\u06ff]/u);
	assert.match(hebrew['Explore Timesheet?'], /[\u0590-\u05ff]/u);
});

test('German employee guidance uses the shortened copy and company guidelines without approval promises', () => {
	for (const locale of ['de', 'de_DE']) {
		const translations = JSON.parse(readFileSync(resolve(root, `l10n/${locale}.json`), 'utf8')).translations;
		assert.equal(translations['Explore Timesheet?'], 'Arbeitszeitnachweis kennenlernen?');
		assert.equal(translations['Working time'], 'Arbeitszeit');
		assert.equal(translations[workingTimeExplanation], 'Der Beginn der Arbeitszeit ist individuell nach den Unternehmensrichtlinien wählbar.');
		assert.equal(translations['Federal state'], 'Bundesland');
		assert.equal(translations[federalStateExplanation], 'Die Feiertage in der Spalte „Status“ richten sich nach dem gewählten Bundesland.');
		assert.equal(translations[commentsExplanation], 'Kommentare ergänzen Informationen zum Tag. Abwesenheiten können mit „{vacation}“ oder „{timeOffInLieu}“ eingetragen werden.');
		assert.doesNotMatch(translations[commentsExplanation], /Freigabe|Genehmigung|Warnung|Strg\+C|Command\+C/u);
		assert.equal(translations['Time entry warnings'], 'Warnungen');
		assert.equal(translations['Freelancer mode'], 'Freelancer-Modus');
		assert.equal(translations[freelancerExplanation], 'Im Freelancer-Modus entfallen Warnmails zu fehlenden oder unvollständigen Einträgen sowie zu Plus- und Minusstunden. Das gilt für Mitarbeitende und HR.');
		assert.equal(translations[warningsExplanation], 'Warnungen bei Über- oder Unterschreitungen von Pausen sowie fehlende Arbeitszeitnachweise führen zu Warnungen und sollten umgehend korrigiert werden.');
		assert.equal(translations['Warnings'], 'Warnhinweise');
		assert.equal(translations[generalHelpExplanation], 'Fragen, Hilfe und Datenschutzhinweise zur allgemeinen Nextcloud.');
		assert.equal(translations['Help and tour'], 'Hilfe und Tour');
		assert.equal(translations['Tour guide'], 'Tourguide');
		assert.equal(translations['Questions for HR'], 'Fragen an HR');
		assert.equal(translations['Suggestions for improvement'], 'Verbesserungswünsche');
		assert.equal(translations[restartExplanation], 'Fragen und Verbesserungsvorschläge an HR. Über das Toursymbol lässt sich der Guide neustarten.');
		for (const [message, translated] of Object.entries(emailHelpMessages)) assert.equal(translations[message], translated);
		const profileKey = coverage.messages.find(message => message.startsWith('The profile menu provides access'));
		assert.match(translations[profileKey], /Kontoeinstellungen/u);
		assert.doesNotMatch(translations[profileKey], /Hilfe|Datenschutz/u);
	}
});
