import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coverage = JSON.parse(readFileSync(resolve(root, 'tests/tour/translation-coverage.json'), 'utf8'));
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
		for (const message of coverage.messages) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message));
			if (message.includes('An absence can be marked')) {
				assert.ok(translated.includes('Urlaub') && translated.includes('Stundenausgleich'), 'Absence comments remain the fixed German terms');
			}
		}
		if (!locale.startsWith('en')) {
			assert.notEqual(server.translations['A short tour explains the main features.'], 'A short tour explains the main features.', 'An English copy is not a translated tour');
		}
	});
}

test('the two German tour variants use the same neutral explanations', () => {
	const informal = JSON.parse(readFileSync(resolve(root, 'l10n/de.json'), 'utf8')).translations;
	const formal = JSON.parse(readFileSync(resolve(root, 'l10n/de_DE.json'), 'utf8')).translations;
	for (const message of coverage.messages) assert.equal(informal[message], formal[message]);
});

test('Japanese and right-to-left tours have their own translated explanations', () => {
	const japanese = JSON.parse(readFileSync(resolve(root, 'l10n/ja.json'), 'utf8')).translations;
	const arabic = JSON.parse(readFileSync(resolve(root, 'l10n/ar.json'), 'utf8')).translations;
	const hebrew = JSON.parse(readFileSync(resolve(root, 'l10n/he.json'), 'utf8')).translations;
	assert.match(japanese['Explore Timesheet?'], /[\u3040-\u30ff\u4e00-\u9fff]/u);
	assert.match(arabic['Explore Timesheet?'], /[\u0600-\u06ff]/u);
	assert.match(hebrew['Explore Timesheet?'], /[\u0590-\u05ff]/u);
});

test('German guidance uses the requested app name, employee title and planned approval wording', () => {
	for (const locale of ['de', 'de_DE']) {
		const translations = JSON.parse(readFileSync(resolve(root, `l10n/${locale}.json`), 'utf8')).translations;
		assert.equal(translations['Explore Timesheet?'], 'Arbeitszeitnachweis kennenlernen?');
		assert.equal(translations['Individual employee view'], 'Einzelansicht eines Mitarbeiters');
		const absenceKey = coverage.messages.find(message => message.includes('An absence can be marked'));
		assert.match(translations[absenceKey], /^Kommentare ergänzen Informationen zum Tag\./u);
		assert.match(translations[absenceKey], /Freigabe durch HR/u);
		assert.match(translations[absenceKey], /Warnung/u);
		assert.doesNotMatch(translations[absenceKey], /Strg\+C|Command\+C/u);
		const profileKey = coverage.messages.find(message => message.startsWith('The profile menu provides access'));
		assert.match(translations[profileKey], /Kontoeinstellungen/u);
		assert.doesNotMatch(translations[profileKey], /Hilfe|Datenschutz/u);
	}
});
