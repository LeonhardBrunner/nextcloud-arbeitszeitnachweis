import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coverage = JSON.parse(readFileSync(resolve(root, 'tests/employment/translation-coverage.json'), 'utf8'));
const tourCoverage = JSON.parse(readFileSync(resolve(root, 'tests/tour/translation-coverage.json'), 'utf8'));
const placeholders = text => [...text.matchAll(/\{\w+\}/g)].map(match => match[0]).sort();
const sharedLoanwords = new Set(['Filter', 'Freelancer', 'Freelancers']);

assert.deepEqual(coverage.locales, tourCoverage.locales, 'Employment controls support every existing account language');

// Both PHP and browser interfaces use the same translated feature messages.
for (const [locale, pluralForm] of Object.entries(coverage.locales)) {
	test(`complete employment messages and matching browser/server catalogs: ${locale}`, () => {
		const server = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8'));
		let browser;
		vm.runInNewContext(readFileSync(resolve(root, 'l10n', `${locale}.js`), 'utf8'), {
			OC: { L10N: { register(app, values, forms) {
				assert.equal(app, 'timesheet');
				assert.equal(forms, pluralForm);
				browser = values;
			} } },
		});
		assert.ok(browser, 'Browser catalog registers its translations');
		assert.equal(server.pluralForm, pluralForm);
		for (const message of coverage.messages) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message), 'Employee counts retain their named placeholders');
			if (!locale.startsWith('en') && !sharedLoanwords.has(message)) {
				assert.notEqual(translated, message, 'Interface text is translated into the account language');
			}
		}
	});
}

test('both German variants use the agreed employment labels and neutral state messages', () => {
	const expected = {
		'Freelancer mode': 'Freelancer-Modus',
		'Off': 'Aus',
		'On': 'Ein',
		'Employment status': 'Beschäftigungsstatus',
		'Active': 'Aktiv',
		'Inactive': 'Inaktiv',
		'Filter': 'Filter',
		'Active employees': 'Aktive Mitarbeitende',
		'Inactive employees': 'Inaktive Mitarbeitende',
		'All employees': 'Alle Mitarbeitenden',
		'Freelancers': 'Freelancer',
		'Freelancer': 'Freelancer',
		'Search by name': 'Nach Namen suchen',
		'Filter employees': 'Mitarbeitende filtern',
		'Show employees': 'Mitarbeitende anzeigen',
		'Close employee filters': 'Mitarbeiterfilter schließen',
		'Clear filters': 'Filter zurücksetzen',
		'{visible} of {total} employees': '{visible} von {total} Mitarbeitenden',
		'No employees are assigned.': 'Es sind keine Mitarbeitenden zugeordnet.',
		'No matching employees.': 'Keine passenden Mitarbeitenden.',
		'Employee list could not be loaded.': 'Die Mitarbeiterliste konnte nicht geladen werden.',
		'Loading employees…': 'Mitarbeitende werden geladen…',
		'Retry': 'Erneut versuchen',
		'Employment status could not be loaded. Reload the page to try again.': 'Der Beschäftigungsstatus konnte nicht geladen werden. Ein Neuladen der Seite ermöglicht einen erneuten Versuch.',
		'Saving employment status…': 'Beschäftigungsstatus wird gespeichert…',
		'Employment status could not be saved.': 'Der Beschäftigungsstatus konnte nicht gespeichert werden.',
		'Employment status changed in another window. The current value has been restored.': 'Der Beschäftigungsstatus wurde in einem anderen Fenster geändert. Der aktuelle Wert wurde wiederhergestellt.',
	};
	assert.deepEqual(Object.keys(expected), coverage.messages);
	for (const locale of ['de', 'de_DE']) {
		const translations = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations;
		for (const [message, translated] of Object.entries(expected)) assert.equal(translations[message], translated);
	}
});
