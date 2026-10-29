import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const adminSource = readFileSync(resolve(repositoryRoot, 'js/admin.js'), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));

// Exercise the actual admin event handlers with a small DOM containing the rendered inputs.
async function openAdmin(initialRules = [{ id: 'one', hrGroups: ['HR'], userGroups: ['Staff'], priority: 2 }], respondToSave = null, language = 'en') {
	const elements = new Map();
	const events = new Map();
	const requests = [];
	const messages = [];
	const translations = {};
	let generatedElementId = 0;

	function element(id) {
		const current = {
			id, className: '', dataset: {}, children: [], controls: [], textContent: '', checked: false,
			addEventListener(name, callback) {
				const key = `${id}:${name}`;
				events.set(key, [...(events.get(key) || []), callback]);
			},
			appendChild(child) { this.children.push(child); },
			set innerHTML(value) {
				this.html = value;
				this.children = [];
				this.controls = Array.from(value.matchAll(/<input\b([^>]*)>/g)).flatMap(([, attributes]) => {
					const reminderKey = attributes.match(/data-reminder-key="([^"]*)"/)?.[1];
					const reminderDay = attributes.match(/data-reminder-day="([^"]*)"/)?.[1];
					if (!reminderKey && !reminderDay) return [];
					const input = {
						dataset: reminderKey ? { reminderKey } : { reminderDay },
						value: attributes.match(/value="([^"]*)"/)?.[1] || '',
						checked: /(?:^|\s)checked(?:\s|$)/.test(attributes),
						closest: selector => selector === '.ts-reminder-input' ? input : selector === '.ts-rule' ? this : null,
					};
					return [input];
				});
			},
			get innerHTML() { return this.html || ''; },
			querySelector(selector) {
				const key = selector.match(/^\[data-reminder-key="([^"]*)"\]$/)?.[1];
				return this.controls.find(control => control.dataset.reminderKey === key);
			},
			querySelectorAll(selector) {
				if (selector === '[data-reminder-day]:checked') {
					return this.controls.filter(control => control.dataset.reminderDay && control.checked);
				}
				return [];
			},
		};
		elements.set(id, current);
		return current;
	}

	for (const id of ['timesheet-admin-settings', 'timesheet-hr-rules', 'timesheet-add-hr-rule', 'timesheet-specialdays-overtime']) element(id);
	element('timesheet-all-groups-data').textContent = JSON.stringify(['HR', 'Staff']);
	element('timesheet-hr-rules-data').textContent = JSON.stringify(initialRules);
	class FixedDate extends Date {
		constructor(...arguments_) {
			super(...(arguments_.length ? arguments_ : ['2026-10-28T23:30:00Z']));
		}
	}
	const context = vm.createContext({
		document: {
			getElementById: id => elements.get(id),
			createElement: tag => element(`generated-${++generatedElementId}-${tag}`),
		},
		Date: FixedDate,
		Intl,
		FormData,
		crypto: { randomUUID: () => 'new-rule' },
		t: (_app, message) => translations[message] ?? message,
		console: Object.fromEntries(['log', 'warn', 'error'].map(name => [name, () => {}])),
		OC: {
			requestToken: 'test-token', generateUrl: url => url,
			Notification: { showTemporary: message => messages.push(message) },
			L10N: { register: (_app, messages) => Object.assign(translations, messages) },
		},
		fetch: async (url, options) => {
			const rules = options.body?.get('rules');
			if (!rules) return { ok: true, status: 200, json: async () => ({ check: false }) };
			const request = { url, headers: options.headers, rules: JSON.parse(rules) };
			requests.push(request);
			return respondToSave ? respondToSave(request, requests.length) : { ok: true, status: 200, json: async () => ({ rules: request.rules }) };
		},
	});
	context.window = context;
	if (language === 'de' || language === 'de_DE') {
		vm.runInContext(readFileSync(resolve(repositoryRoot, `l10n/${language}.js`), 'utf8'), context, { filename: `${language}.js` });
	}
	vm.runInContext(adminSource, context, { filename: 'admin.js' });
	await new Promise(setImmediate);

	return {
		requests, messages,
		card: () => elements.get('timesheet-hr-rules').children[0],
		input: key => elements.get('timesheet-hr-rules').children[0].querySelector(`[data-reminder-key="${key}"]`),
		weekday: day => elements.get('timesheet-hr-rules').children[0].controls.find(control => control.dataset.reminderDay === String(day)),
		async change(input) {
			for (const callback of events.get('timesheet-hr-rules:input') || []) callback({ target: input });
			await Promise.all((events.get('timesheet-hr-rules:change') || []).map(callback => callback({ target: input })));
		},
		type(input) {
			for (const callback of events.get('timesheet-hr-rules:input') || []) callback({ target: input });
		},
	};
}

function saveResponse(request) {
	return { ok: true, status: 200, json: async () => ({ rules: request.rules }) };
}

for (const language of ['de', 'de_DE']) {
	test(`German admin cards translate group rules, priorities and reminder controls (${language})`, async () => {
		const admin = await openAdmin(undefined, null, language);
		const cardHtml = admin.card().innerHTML;
		for (const label of ['Gruppenregel', 'Priorität', 'Zeitregeln', 'Erinnerungen an fehlende Zeiten']) {
			assert.ok(cardHtml.includes(label), `Missing German admin label: ${label}`);
		}
		assert.ok(!cardHtml.includes('Group rule'));
		assert.ok(!cardHtml.includes('Missing time entry reminders'));
	});
}

test('both German server catalogs include the same interface and email translations', () => {
	const personalCatalog = JSON.parse(readFileSync(resolve(repositoryRoot, 'l10n/de.json'), 'utf8'));
	const formalCatalog = JSON.parse(readFileSync(resolve(repositoryRoot, 'l10n/de_DE.json'), 'utf8'));
	assert.deepEqual(Object.keys(formalCatalog.translations).sort(), Object.keys(personalCatalog.translations).sort());
	assert.equal(formalCatalog.translations.Timesheet, 'Arbeitszeitnachweis');
	assert.equal(formalCatalog.translations['Timesheet: missing time entries'], 'Timesheet: Fehlende Zeiteinträge');
});

test('group and rule removal controls use a readable multiplication sign and translated labels', async () => {
	const admin = await openAdmin(undefined, null, 'de');
	const controls = Array.from(admin.card().innerHTML.matchAll(/<a\b[^>]*class="ts-(?:chip-remove|rule-delete)"[^>]*>([^<]*)<\/a>/g));
	assert.equal(controls.length, 3);
	for (const [html, label] of controls) {
		const visibleLabel = label.replace(/&(?:times|#215|#x0*d7);/gi, '×').trim();
		assert.equal(visibleLabel, '×');
		assert.match(html, /aria-label="(?:Entfernen|Regel löschen)"/);
	}
});

test('English admin cards retain their original labels', async () => {
	const admin = await openAdmin();
	assert.ok(admin.card().innerHTML.includes('Group rule'));
	assert.ok(admin.card().innerHTML.includes('Priority'));
	assert.ok(admin.card().innerHTML.includes('Missing time entry reminders'));
});

test('legacy rules render disabled reminders with Monday to Friday defaults', async () => {
	const admin = await openAdmin();
	assert.equal(admin.input('enabled').checked, false);
	assert.equal(admin.input('graceDays').value, '14');
	assert.equal(admin.input('startDate').value, '');
	assert.deepEqual([1, 2, 3, 4, 5, 6, 7].filter(day => admin.weekday(day).checked), [1, 2, 3, 4, 5]);
	assert.equal(admin.requests.length, 0);
});

test('first enable uses the Berlin calendar date and saves the complete policy', async () => {
	const admin = await openAdmin();
	admin.input('enabled').checked = true;
	await admin.change(admin.input('enabled'));
	assert.deepEqual(admin.requests[0].rules[0].missingEntryReminders, {
		enabled: true, graceDays: 14, weekdays: [1, 2, 3, 4, 5], startDate: '2026-10-29',
	});
	assert.equal(admin.requests[0].rules[0].priority, 2);
	assert.deepEqual(admin.requests[0].rules[0].userGroups, ['Staff']);
	assert.equal(admin.requests[0].headers.requesttoken, 'test-token');
	assert.equal(admin.input('enabled').checked, true);
	assert.equal(admin.input('startDate').value, '2026-10-29');
});

test('editing disabled reminder settings never enables the feature', async () => {
	const admin = await openAdmin();
	admin.input('graceDays').value = '8';
	await admin.change(admin.input('graceDays'));
	assert.deepEqual(admin.requests[0].rules[0].missingEntryReminders, {
		enabled: false, graceDays: 8, weekdays: [1, 2, 3, 4, 5], startDate: '',
	});
});

test('saved weekday and start settings survive edits and server round trips', async () => {
	const admin = await openAdmin([{
		id: 'one', hrGroups: ['HR'], userGroups: ['Staff'], priority: 1,
		missingEntryReminders: { enabled: true, graceDays: 3, weekdays: [2, 6], startDate: '2026-09-01' },
	}]);
	admin.input('graceDays').value = '9';
	await admin.change(admin.input('graceDays'));
	assert.deepEqual(admin.requests[0].rules[0].missingEntryReminders, {
		enabled: true, graceDays: 9, weekdays: [2, 6], startDate: '2026-09-01',
	});
	assert.equal(admin.weekday(6).checked, true);
	assert.equal(admin.weekday(1).checked, false);
	assert.equal(admin.input('startDate').value, '2026-09-01');
});

test('removing the last expected weekday blocks saving until a valid selection exists', async () => {
	const admin = await openAdmin([{
		id: 'one', hrGroups: [], userGroups: ['Staff'],
		missingEntryReminders: { enabled: true, graceDays: 7, weekdays: [1], startDate: '2026-10-01' },
	}]);
	admin.weekday(1).checked = false;
	await admin.change(admin.weekday(1));
	assert.equal(admin.requests.length, 0);
	assert.deepEqual(admin.messages, ['Select at least one weekday for reminders.']);
	admin.weekday(2).checked = true;
	await admin.change(admin.weekday(2));
	assert.deepEqual(admin.requests[0].rules[0].missingEntryReminders.weekdays, [2]);
});

for (const invalidDelay of ['', '0', '366', '1.5']) {
	test(`invalid reminder delay ${JSON.stringify(invalidDelay)} never reaches the server`, async () => {
		const admin = await openAdmin();
		admin.input('graceDays').value = invalidDelay;
		await admin.change(admin.input('graceDays'));
		assert.equal(admin.requests.length, 0);
		assert.deepEqual(admin.messages, ['The reminder delay must be a whole number from 1 to 365.']);
	});
}

test('invalid calendar dates never reach the server', async () => {
	const admin = await openAdmin();
	admin.input('startDate').value = '2026-02-31';
	await admin.change(admin.input('startDate'));
	assert.equal(admin.requests.length, 0);
	assert.deepEqual(admin.messages, ['Select a valid start date for reminders.']);
});

test('clearing an enabled start date does not silently replace it with today', async () => {
	const admin = await openAdmin([{
		id: 'one', hrGroups: ['HR'], userGroups: ['Staff'],
		missingEntryReminders: { enabled: true, graceDays: 7, weekdays: [1], startDate: '2026-10-01' },
	}]);
	admin.input('startDate').value = '';
	await admin.change(admin.input('startDate'));
	assert.equal(admin.requests.length, 0);
	assert.equal(admin.input('startDate').value, '');
	assert.deepEqual(admin.messages, ['Select a valid start date for reminders.']);
});

test('rapid edits are saved in order and an old response does not overwrite the latest input', async () => {
	let releaseFirstSave;
	const admin = await openAdmin(undefined, async (request, count) => {
		if (count === 1) await new Promise(resolve => { releaseFirstSave = resolve; });
		return saveResponse(request);
	});
	admin.input('enabled').checked = true;
	const firstSave = admin.change(admin.input('enabled'));
	await new Promise(setImmediate);
	admin.input('graceDays').value = '3';
	const secondSave = admin.change(admin.input('graceDays'));
	assert.equal(admin.requests.length, 1);
	releaseFirstSave();
	await Promise.all([firstSave, secondSave]);
	assert.deepEqual(admin.requests.map(request => request.rules[0].missingEntryReminders.graceDays), [14, 3]);
	assert.equal(admin.input('graceDays').value, '3');
	assert.equal(admin.input('enabled').checked, true);
});

test('typing during a pending save is preserved until the next change is submitted', async () => {
	let releaseFirstSave;
	const admin = await openAdmin(undefined, async (request, count) => {
		if (count === 1) await new Promise(resolve => { releaseFirstSave = resolve; });
		return saveResponse(request);
	});
	admin.input('enabled').checked = true;
	const firstSave = admin.change(admin.input('enabled'));
	await new Promise(setImmediate);
	const currentCard = admin.card();
	admin.input('graceDays').value = '5';
	admin.type(admin.input('graceDays'));
	releaseFirstSave();
	await firstSave;
	assert.equal(admin.card(), currentCard);
	assert.equal(admin.input('graceDays').value, '5');
	await admin.change(admin.input('graceDays'));
	assert.equal(admin.requests[1].rules[0].missingEntryReminders.graceDays, 5);
});

test('a failed save reports an error and does not prevent a later retry', async () => {
	const admin = await openAdmin(undefined, async (request, count) => count === 1
		? { ok: false, status: 500, text: async () => 'Preparation failed' }
		: saveResponse(request));
	admin.input('enabled').checked = true;
	await admin.change(admin.input('enabled'));
	assert.deepEqual(admin.messages, ['Saving failed']);
	admin.input('graceDays').value = '4';
	await admin.change(admin.input('graceDays'));
	assert.equal(admin.requests.length, 2);
	assert.equal(admin.requests[1].rules[0].missingEntryReminders.graceDays, 4);
});
