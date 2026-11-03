import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coverage = JSON.parse(readFileSync(resolve(root, 'tests/updates/translation-coverage.json'), 'utf8'));
const existingCoverage = JSON.parse(readFileSync(resolve(root, 'tests/tour/translation-coverage.json'), 'utf8'));
const releases = JSON.parse(readFileSync(resolve(root, 'appinfo/release-notes.json'), 'utf8')).releases;
const interfaceMessages = [
	"What's new in Timesheet?", 'Version {version}', 'Close',
	'The update notes could not be loaded. Please try again.', 'Timesheet updated to {version}',
	'Open the update notes.',
];
const placeholders = value => [...value.matchAll(/\{\w+\}/g)].map(match => match[0]).sort();

test('update notes cover the release catalog and all existing account languages', () => {
	assert.deepEqual(coverage.locales, existingCoverage.locales);
	const expectedMessages = new Set(interfaceMessages);
	for (const release of releases) {
		for (const section of release.sections) {
			expectedMessages.add(section.title);
			for (const item of section.items) expectedMessages.add(item);
		}
	}
	assert.deepEqual(new Set(coverage.messages), expectedMessages);
	assert.equal(coverage.messages.length, expectedMessages.size);
});

// The popup and native notification use matching browser and server translations.
for (const [locale, pluralForm] of Object.entries(coverage.locales)) {
	test(`complete update notes and matching language catalogs: ${locale}`, () => {
		const server = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8'));
		let browser;
		vm.runInNewContext(readFileSync(resolve(root, 'l10n', `${locale}.js`), 'utf8'), {
			OC: { L10N: { register(app, translations, forms) {
				assert.equal(app, 'timesheet');
				assert.equal(forms, pluralForm);
				browser = translations;
			} } },
		});
		assert.equal(server.pluralForm, pluralForm);
		assert.ok(browser);
		for (const message of coverage.messages) {
			const translated = server.translations[message];
			assert.equal(typeof translated, 'string', `Missing ${locale} translation: ${message}`);
			assert.ok(translated.trim(), `Empty ${locale} translation: ${message}`);
			assert.equal(browser[message], translated);
			assert.deepEqual(placeholders(translated), placeholders(message));
			if (!locale.startsWith('en') && message !== 'Version {version}') {
				assert.notEqual(translated, message, 'Update notes use the account language');
			}
		}
	});
}

test('both German variants use the same concise explanations without personal address', () => {
	const expected = {
		"What's new in Timesheet?": 'Neues im Arbeitszeitnachweis',
		'Version {version}': 'Version {version}',
		'Close': 'Schließen',
		'The update notes could not be loaded. Please try again.': 'Die Update-Hinweise konnten nicht geladen werden. Bitte erneut versuchen.',
		'Timesheet updated to {version}': 'Arbeitszeitnachweis auf Version {version} aktualisiert',
		'Open the update notes.': 'Änderungsliste öffnen.',
		'New features': 'Neue Funktionen',
		'Improvements': 'Verbesserungen',
		'Bug fixes': 'Fehlerbehebungen',
		'Freelancer mode is now available.': 'Der Freelancer-Modus ist jetzt verfügbar.',
	};
	const german = ['de', 'de_DE'].map(locale => JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations);
	for (const message of coverage.messages) {
		assert.equal(german[0][message], german[1][message]);
		assert.doesNotMatch(german[0][message], /\b(?:Du|du|Dich|dich|Dein|dein|Deine|deine|Sie|Ihnen|Ihr)\b/, 'Release notes use neutral wording');
	}
	for (const [message, translated] of Object.entries(expected)) assert.equal(german[0][message], translated);
});

test('Japanese and right-to-left accounts have translated release items and headings', () => {
	for (const locale of ['ja', 'ar', 'he', 'fa', 'ug']) {
		const translated = JSON.parse(readFileSync(resolve(root, 'l10n', `${locale}.json`), 'utf8')).translations;
		for (const release of releases) {
			for (const section of release.sections) {
				for (const message of [section.title, ...section.items]) assert.notEqual(translated[message], message);
			}
		}
	}
});
