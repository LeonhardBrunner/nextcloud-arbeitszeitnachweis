import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coverage = JSON.parse(readFileSync(resolve(root, 'tests/history/translation-coverage.json'), 'utf8'));
const languages = JSON.parse(readFileSync(resolve(root, 'tests/tour/translation-coverage.json'), 'utf8')).locales;
const placeholders = value => [...value.matchAll(/\{\w+\}/g)].map(match => match[0]).sort();
const messages = [...coverage.messages, ...coverage.reusedMessages];

test('history covers all supported account languages and unique message keys', () => {
	assert.deepEqual(coverage.locales, languages);
	assert.equal(new Set(messages).size, messages.length);
	assert.equal(coverage.messages.length, 42);
});

// Every history label, explanation and release item has matching browser and server wording.
for (const [locale, pluralForm] of Object.entries(coverage.locales)) {
	test(`complete history translations and matching catalogs: ${locale}`, () => {
		const server = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8'));
		let browser;
		vm.runInNewContext(readFileSync(resolve(root, 'l10n', `${locale}.js`), 'utf8'), {
			OC: { L10N: { register(app, values, forms) {
				assert.equal(app, 'timesheet');
				assert.equal(forms, pluralForm);
				browser = values;
			} } },
		});
		assert.equal(server.pluralForm, pluralForm);
		assert.ok(browser);
		for (const message of messages) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message));
			assert.doesNotMatch(translated, /\uFFFD/u);
			if (!locale.startsWith('en') && (message.includes(' ') || message.includes('…'))) {
				assert.notEqual(translated, message, `Untranslated ${locale} history message`);
			}
		}
	});
}

test('both German variants use matching neutral history text', () => {
	const catalogs = ['de', 'de_DE'].map(locale => JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations);
	for (const message of coverage.messages) {
		assert.equal(catalogs[0][message], catalogs[1][message]);
		assert.doesNotMatch(catalogs[0][message], /\b(?:Du|du|Dich|dich|Dein|dein|Deine|deine|Sie|Ihnen|Ihr)\b/);
	}
	assert.equal(catalogs[0]['Change history'], 'Änderungsverlauf');
	assert.equal(catalogs[0]['Changed by'], 'Bearbeiter');
	assert.equal(catalogs[0]['Comment changed'], 'Kommentar geändert');
	assert.equal(catalogs[0]['Before'], 'Vorher');
	assert.equal(catalogs[0]['After'], 'Nachher');
	assert.equal(catalogs[0]['Deleted account'], 'Gelöschtes Konto');
});

test('history has native-script explanations for Japanese and right-to-left languages', () => {
	for (const [locale, script] of [['ja', /[\u3040-\u30ff\u4e00-\u9fff]/u], ['ar', /[\u0600-\u06ff]/u], ['he', /[\u0590-\u05ff]/u]]) {
		const translations = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations;
		assert.match(translations['Change history'], script);
		assert.match(translations['Changes are kept for twelve months.'], script);
	}
});
