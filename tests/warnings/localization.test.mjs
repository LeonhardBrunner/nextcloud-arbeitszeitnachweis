import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = process.env.TIMESHEET_WARNING_SOURCE_ROOT
	? resolve(process.env.TIMESHEET_WARNING_SOURCE_ROOT)
	: resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const warningKeys = [
	'Time incomplete',
	'Break too short',
	'Above maximum time',
	'Sunday work not allowed',
	'Holiday work not allowed',
	'Too much overtime',
	'Too many negative hours',
	'No entry for at least {days} days',
];

function openEntries(accountLanguage, browserLanguage = 'en-GB') {
	const translations = {};
	const requests = [];
	const fields = {
		'.config-daily-min': { value: '08:00' },
		'.config-state': { value: 'BY' },
		'#overtime-total': { textContent: '' },
		'#worked-hours-month': { textContent: '' },
		'#overtime-month': { textContent: '' },
	};
	const container = { querySelector: selector => fields[selector] || null };
	const document = {
		documentElement: { getAttribute: () => accountLanguage },
		getElementById: id => ['tab-hr', 'hr-user-entries'].includes(id)
			? container : id === 'config-daily-min-mine' ? fields['.config-daily-min'] : null,
		querySelector: selector => selector === '#hr-user-title span'
			? { textContent: 'employee' }
			: selector.endsWith(' .config-state') ? fields['.config-state'] : fields[selector] || null,
		querySelectorAll: () => [],
		createElement: () => ({ dataset: {}, classList: { add() {} }, innerHTML: '' }),
	};
	const context = vm.createContext({
		document, navigator: { language: browserLanguage }, Date, Intl,
		console: { log() {}, warn() {}, error() {} }, setTimeout() {},
		t: (_app, message, parameters = {}) => Object.entries(parameters).reduce(
			(text, [key, value]) => text.replaceAll('{' + key + '}', value), translations[message] ?? message,
		),
		OC: {
			requestToken: 'fixture-token', getCurrentUser: () => ({ uid: 'employee' }),
			getLocale: () => accountLanguage,
			L10N: { register(app, messages) { assert.equal(app, 'timesheet'); Object.assign(translations, messages); } },
		},
	});
	context.window = context;
	for (const filename of ['l10n/' + accountLanguage + '.js', 'js/timesheet-core.js', 'js/timesheet-entries.js']) {
		vm.runInContext(readFileSync(resolve(root, filename), 'utf8'), context, { filename });
	}
	context.Timesheet.api = async (path, options = {}) => {
		requests.push({ path, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null });
		return path.includes('/summary') ? { overtimeMinutes: 90 } : { id: 42 };
	};
	return { context, translations, requests, container };
}

function renderedWarning(environment, entry, workDate = '2026-11-02', holidays = {}) {
	const row = environment.context.Timesheet.entries.createEntryRow(
		new Date(workDate + 'T12:00:00Z'), entry, holidays,
	);
	return row.innerHTML.match(/<td class="ts-warn">([^<]*)<\/td>/)?.[1];
}

function editableRow(environment, hr = false) {
	const fields = {
		'.startTime': { value: '08:00', type: 'time' },
		'.endTime': { value: '', type: 'time' },
		'.breakMinutes': { value: '0', type: 'text' },
		'.commentInput': { value: '' },
		'.ts-warn': { textContent: '' },
		'.ts-duration': { textContent: '--:--' },
		'.ts-diff': { textContent: '--:--' },
	};
	const classes = new Set();
	const row = {
		dataset: { date: '2026-11-02', savedStart: '', savedEnd: '', savedBreak: '0', savedComment: '' },
		querySelector: selector => fields[selector] || null,
		classList: { add: name => classes.add(name), remove: name => classes.delete(name) },
		closest: selector => selector === 'tbody' ? tbody
			: selector === '#hr-user-entries' && hr ? environment.container : null,
	};
	const tbody = { dataset: { specialDays: '0' }, querySelectorAll: () => [row] };
	return { row, fields };
}

test('German incomplete time warnings use the account catalog when the browser is English', () => {
	for (const accountLanguage of ['de', 'de_DE']) {
		const app = openEntries(accountLanguage);
		for (const entry of [{ startMin: 480, endMin: null }, { startMin: null, endMin: 990 }]) {
			assert.equal(renderedWarning(app, entry), 'Zeitangaben unvollständig', accountLanguage);
		}
		assert.equal(app.requests.length, 0);
	}
});

for (const accountLanguage of ['de', 'de_DE', 'en_GB', 'ja', 'ar']) {
	test('loaded and newly saved incomplete entries share localized warnings: ' + accountLanguage, async () => {
		const app = openEntries(accountLanguage, accountLanguage === 'en_GB' ? 'de-DE' : 'en-GB');
		const expected = app.translations['Time incomplete'];
		assert.equal(typeof expected, 'string');
		if (!accountLanguage.startsWith('en')) assert.notEqual(expected, 'Time incomplete');
		assert.equal(renderedWarning(app, { startMin: 480, endMin: null }), expected);
		assert.equal(renderedWarning(app, { startMin: 0, endMin: null }), expected, 'Midnight is a recorded time');
		assert.equal(renderedWarning(app, { startMin: 480, endMin: 990, breakMinutes: 30 }), '');
		assert.equal(renderedWarning(app, { startMin: null, endMin: null }), '');
		for (const hr of [false, true]) {
			const { row, fields } = editableRow(app, hr);
			await app.context.Timesheet.entries.saveRowIfNeeded(row);
			assert.equal(fields['.ts-warn'].textContent, expected);
			assert.equal(fields['.ts-duration'].textContent, '--:--');
			const write = app.requests.filter(request => request.method === 'POST').at(-1);
			assert.deepEqual(write.body, { workDate: '2026-11-02', start: '08:00', end: '', breakMinutes: 0, comment: '' });
		}
	});

	test('break, maximum-hours, Sunday and holiday warnings use the same account catalog: ' + accountLanguage, () => {
		const app = openEntries(accountLanguage, 'en-GB');
		const translated = key => app.translations[key];
		for (const key of warningKeys.slice(1, 5)) assert.equal(typeof translated(key), 'string');
		assert.equal(renderedWarning(app, { startMin: 480, endMin: 900, breakMinutes: 0 }), translated('Break too short'));
		assert.equal(renderedWarning(app, { startMin: 480, endMin: 1140, breakMinutes: 60 }), '');
		assert.equal(renderedWarning(app, { startMin: 480, endMin: 1141, breakMinutes: 60 }), translated('Above maximum time'));
		assert.equal(renderedWarning(app, { startMin: 480, endMin: 540, breakMinutes: 0 }, '2026-11-01', { '2026-11-01': 'Holiday' }),
			translated('Sunday work not allowed') + ', ' + translated('Holiday work not allowed'));
		assert.equal(renderedWarning(app, { startMin: null, endMin: null, breakMinutes: 0 }, '2026-11-01', { '2026-11-01': 'Holiday' }), '');
	});
}

test('all shipped warning catalogs match in browser and server and preserve named day parameters', () => {
	const locales = readdirSync(resolve(root, 'l10n')).filter(filename => filename.endsWith('.json')).map(filename => filename.slice(0, -5));
	for (const locale of locales) {
		const server = JSON.parse(readFileSync(resolve(root, 'l10n/' + locale + '.json'), 'utf8'));
		let browser;
		vm.runInNewContext(readFileSync(resolve(root, 'l10n/' + locale + '.js'), 'utf8'), {
			OC: { L10N: { register(app, messages, pluralForm) {
				assert.equal(app, 'timesheet'); assert.equal(pluralForm, server.pluralForm); browser = messages;
			} } },
		});
		for (const message of warningKeys) {
			const value = server.translations[message];
			assert.equal(typeof value, 'string', locale + ': ' + message);
			assert.ok(value.trim(), locale + ': ' + message);
			assert.equal(browser[message], value);
			assert.deepEqual([...value.matchAll(/\{\w+\}/g)].map(match => match[0]), [...message.matchAll(/\{\w+\}/g)].map(match => match[0]));
		}
	}
});

