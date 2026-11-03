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
const placeholders = value => [...value.matchAll(/\{\w+\}/g)].map(match => match[0]).sort();

test('HR assignment translations cover all existing account languages without duplicate messages', () => {
	assert.deepEqual(coverage.locales, accountLanguages);
	assert.equal(coverage.messages.length, messages.size);
	assert.equal(messages.size, 22);
});

// Feature messages match in both catalogs; other features may add or revise their own translations.
for (const [locale, pluralForm] of Object.entries(coverage.locales)) {
	test(`complete HR assignment translations and matching catalogs: ${locale}`, () => {
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
		for (const message of messages) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message));
			if (!locale.startsWith('en')) assert.notEqual(translated, message, `Untranslated ${locale} message`);
		}
	});
}

test('both German variants use concise, neutral assignment and delivery wording', () => {
	const german = ['de', 'de_DE'].map(locale => JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations);
	for (const message of messages) {
		assert.equal(german[0][message], german[1][message]);
		assert.doesNotMatch(german[0][message], /\b(?:Du|du|Dich|dich|Dein|dein|Deine|deine|Sie|Ihnen|Ihr)\b/);
	}
	assert.equal(german[0]['Individual HR accounts'], 'Einzelne HR-Konten');
	assert.equal(german[0]['Email recipients'], 'E-Mail-Empfänger');
	assert.equal(german[0]['All responsible HR accounts'], 'Alle zuständigen HR-Konten');
	assert.equal(german[0]['Selected HR accounts'], 'Ausgewählte HR-Konten');
	assert.equal(german[0]['No selected accounts means no HR summary for this rule.'], 'Ohne ausgewählte Konten wird für diese Regel keine HR-Übersicht versendet.');
	assert.equal(german[0]['HR assignments or email recipients are invalid.'], 'HR-Zuordnungen oder E-Mail-Empfänger sind ungültig.');
	assert.equal(german[0]['Email selection does not change HR access.'], 'Die E-Mail-Auswahl ändert keine HR-Zugriffsrechte.');
});

test('Japanese and right-to-left accounts have translated search, permissions and mail guidance', () => {
	for (const locale of ['ja', 'ar', 'he', 'fa', 'ug']) {
		const translated = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations;
		for (const message of messages) assert.notEqual(translated[message], message);
	}
});
