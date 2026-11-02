import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const hrSettingsSource = readFileSync(resolve(repositoryRoot, 'js/hr-settings.js'), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));

// Exercise the actual HR settings event handlers with a small DOM containing the rendered inputs.
async function openHrSettings(initialRules = [{ id: 'one', hrGroups: ['HR'], userGroups: ['Staff'], priority: 2 }], respondToSave = null, language = 'en') {
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

	for (const id of ['timesheet-hr-settings', 'timesheet-hr-rules', 'timesheet-add-hr-rule', 'timesheet-specialdays-overtime']) element(id);
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
	vm.runInContext(hrSettingsSource, context, { filename: 'hr-settings.js' });
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
		async removeHrGroup(groupId) {
			const ruleCard = elements.get('timesheet-hr-rules').children[0];
			const removeLink = { dataset: { kind: 'hrGroups', group: groupId } };
			const target = { closest: selector => selector === '.ts-rule' ? ruleCard : selector === '.ts-chip-remove' ? removeLink : null };
			await Promise.all((events.get('timesheet-hr-rules:click') || []).map(callback => callback({ target, preventDefault() {} })));
		},
		async addHrGroup(groupId) {
			const ruleCard = elements.get('timesheet-hr-rules').children[0];
			const groupSelect = { dataset: { kind: 'hrGroups' }, value: groupId };
			const target = { closest: selector => selector === '.ts-rule' ? ruleCard : selector === '.ts-rule-select' ? groupSelect : null };
			await Promise.all((events.get('timesheet-hr-rules:change') || []).map(callback => callback({ target })));
		},
	};
}

function saveResponse(request) {
	return { ok: true, status: 200, json: async () => ({ rules: request.rules }) };
}

for (const language of ['de', 'de_DE']) {
	test(`German HR settings cards translate group rules, priorities and reminder controls (${language})`, async () => {
		const hrSettings = await openHrSettings(undefined, null, language);
		const cardHtml = hrSettings.card().innerHTML;
		for (const label of ['Gruppenregel', 'Priorität', 'Zeitregeln', 'Erinnerungen an fehlende Zeiten']) {
			assert.ok(cardHtml.includes(label), `Missing German HR settings label: ${label}`);
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
	const hrSettings = await openHrSettings(undefined, null, 'de');
	const controls = Array.from(hrSettings.card().innerHTML.matchAll(/<a\b[^>]*class="ts-(?:chip-remove|rule-delete)"[^>]*>([^<]*)<\/a>/g));
	assert.equal(controls.length, 3);
	for (const [html, label] of controls) {
		const visibleLabel = label.replace(/&(?:times|#215|#x0*d7);/gi, '×').trim();
		assert.equal(visibleLabel, '×');
		assert.match(html, /aria-label="(?:Entfernen|Regel löschen)"/);
	}
});

test('English HR settings cards retain their original labels', async () => {
	const hrSettings = await openHrSettings();
	assert.ok(hrSettings.card().innerHTML.includes('Group rule'));
	assert.ok(hrSettings.card().innerHTML.includes('Priority'));
	assert.ok(hrSettings.card().innerHTML.includes('Missing time entry reminders'));
});

test('legacy rules render disabled reminders with Monday to Friday defaults', async () => {
	const hrSettings = await openHrSettings();
	assert.equal(hrSettings.input('enabled').checked, false);
	assert.equal(hrSettings.input('graceDays').value, '14');
	assert.equal(hrSettings.input('startDate').value, '');
	assert.deepEqual([1, 2, 3, 4, 5, 6, 7].filter(day => hrSettings.weekday(day).checked), [1, 2, 3, 4, 5]);
	assert.equal(hrSettings.requests.length, 0);
});

test('first enable uses the Berlin calendar date and saves the complete policy', async () => {
	const hrSettings = await openHrSettings();
	hrSettings.input('enabled').checked = true;
	await hrSettings.change(hrSettings.input('enabled'));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
		enabled: true, graceDays: 14, weekdays: [1, 2, 3, 4, 5], startDate: '2026-10-29',
	});
	assert.equal(hrSettings.requests[0].rules[0].priority, 2);
	assert.deepEqual(hrSettings.requests[0].rules[0].userGroups, ['Staff']);
	assert.equal(hrSettings.requests[0].headers.requesttoken, 'test-token');
	assert.equal(hrSettings.input('enabled').checked, true);
	assert.equal(hrSettings.input('startDate').value, '2026-10-29');
});

test('editing disabled reminder settings never enables the feature', async () => {
	const hrSettings = await openHrSettings();
	hrSettings.input('graceDays').value = '8';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
		enabled: false, graceDays: 8, weekdays: [1, 2, 3, 4, 5], startDate: '',
	});
});

test('saved weekday and start settings survive edits and server round trips', async () => {
	const hrSettings = await openHrSettings([{
		id: 'one', hrGroups: ['HR'], userGroups: ['Staff'], priority: 1,
		missingEntryReminders: { enabled: true, graceDays: 3, weekdays: [2, 6], startDate: '2026-09-01' },
	}]);
	hrSettings.input('graceDays').value = '9';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
		enabled: true, graceDays: 9, weekdays: [2, 6], startDate: '2026-09-01',
	});
	assert.equal(hrSettings.weekday(6).checked, true);
	assert.equal(hrSettings.weekday(1).checked, false);
	assert.equal(hrSettings.input('startDate').value, '2026-09-01');
});

test('removing the last expected weekday blocks saving until a valid selection exists', async () => {
	const hrSettings = await openHrSettings([{
		id: 'one', hrGroups: [], userGroups: ['Staff'],
		missingEntryReminders: { enabled: true, graceDays: 7, weekdays: [1], startDate: '2026-10-01' },
	}]);
	hrSettings.weekday(1).checked = false;
	await hrSettings.change(hrSettings.weekday(1));
	assert.equal(hrSettings.requests.length, 0);
	assert.deepEqual(hrSettings.messages, ['Select at least one weekday for reminders.']);
	hrSettings.weekday(2).checked = true;
	await hrSettings.change(hrSettings.weekday(2));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders.weekdays, [2]);
});

for (const invalidDelay of ['', '0', '366', '1.5']) {
	test(`invalid reminder delay ${JSON.stringify(invalidDelay)} never reaches the server`, async () => {
		const hrSettings = await openHrSettings();
		hrSettings.input('graceDays').value = invalidDelay;
		await hrSettings.change(hrSettings.input('graceDays'));
		assert.equal(hrSettings.requests.length, 0);
		assert.deepEqual(hrSettings.messages, ['The reminder delay must be a whole number from 1 to 365.']);
	});
}

test('invalid calendar dates never reach the server', async () => {
	const hrSettings = await openHrSettings();
	hrSettings.input('startDate').value = '2026-02-31';
	await hrSettings.change(hrSettings.input('startDate'));
	assert.equal(hrSettings.requests.length, 0);
	assert.deepEqual(hrSettings.messages, ['Select a valid start date for reminders.']);
});

test('clearing an enabled start date does not silently replace it with today', async () => {
	const hrSettings = await openHrSettings([{
		id: 'one', hrGroups: ['HR'], userGroups: ['Staff'],
		missingEntryReminders: { enabled: true, graceDays: 7, weekdays: [1], startDate: '2026-10-01' },
	}]);
	hrSettings.input('startDate').value = '';
	await hrSettings.change(hrSettings.input('startDate'));
	assert.equal(hrSettings.requests.length, 0);
	assert.equal(hrSettings.input('startDate').value, '');
	assert.deepEqual(hrSettings.messages, ['Select a valid start date for reminders.']);
});

test('rapid edits are saved in order and an old response does not overwrite the latest input', async () => {
	let releaseFirstSave;
	const hrSettings = await openHrSettings(undefined, async (request, count) => {
		if (count === 1) await new Promise(resolve => { releaseFirstSave = resolve; });
		return saveResponse(request);
	});
	hrSettings.input('enabled').checked = true;
	const firstSave = hrSettings.change(hrSettings.input('enabled'));
	await new Promise(setImmediate);
	hrSettings.input('graceDays').value = '3';
	const secondSave = hrSettings.change(hrSettings.input('graceDays'));
	assert.equal(hrSettings.requests.length, 1);
	releaseFirstSave();
	await Promise.all([firstSave, secondSave]);
	assert.deepEqual(hrSettings.requests.map(request => request.rules[0].missingEntryReminders.graceDays), [14, 3]);
	assert.equal(hrSettings.input('graceDays').value, '3');
	assert.equal(hrSettings.input('enabled').checked, true);
});

test('typing during a pending save is preserved until the next change is submitted', async () => {
	let releaseFirstSave;
	const hrSettings = await openHrSettings(undefined, async (request, count) => {
		if (count === 1) await new Promise(resolve => { releaseFirstSave = resolve; });
		return saveResponse(request);
	});
	hrSettings.input('enabled').checked = true;
	const firstSave = hrSettings.change(hrSettings.input('enabled'));
	await new Promise(setImmediate);
	const currentCard = hrSettings.card();
	hrSettings.input('graceDays').value = '5';
	hrSettings.type(hrSettings.input('graceDays'));
	releaseFirstSave();
	await firstSave;
	assert.equal(hrSettings.card(), currentCard);
	assert.equal(hrSettings.input('graceDays').value, '5');
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.equal(hrSettings.requests[1].rules[0].missingEntryReminders.graceDays, 5);
});

test('a failed save reports an error and does not prevent a later retry', async () => {
	const hrSettings = await openHrSettings(undefined, async (request, count) => count === 1
		? { ok: false, status: 500, text: async () => 'Preparation failed' }
		: saveResponse(request));
	hrSettings.input('enabled').checked = true;
	await hrSettings.change(hrSettings.input('enabled'));
	assert.deepEqual(hrSettings.messages, ['Saving failed']);
	hrSettings.input('graceDays').value = '4';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.equal(hrSettings.requests.length, 2);
	assert.equal(hrSettings.requests[1].rules[0].missingEntryReminders.graceDays, 4);
});

test('the last HR group rejection is explained and does not block a queued reassignment', async () => {
	let releaseFirstSave;
	const hrSettings = await openHrSettings(undefined, async (request, count) => {
		if (count === 1) {
			await new Promise(resolve => { releaseFirstSave = resolve; });
			return { ok: false, status: 400, text: async () => JSON.stringify({ message: 'At least one HR group must remain assigned.' }) };
		}
		return saveResponse(request);
	});
	const removal = hrSettings.removeHrGroup('HR');
	await new Promise(setImmediate);
	const reassignment = hrSettings.addHrGroup('HR');
	assert.equal(hrSettings.requests.length, 1);
	releaseFirstSave();
	await Promise.all([removal, reassignment]);
	assert.deepEqual(hrSettings.messages, ['At least one HR group must remain assigned.']);
	assert.deepEqual(hrSettings.requests.map(request => request.rules[0].hrGroups), [[], ['HR']]);
	assert.ok(hrSettings.card().innerHTML.includes('data-group="HR"'));
});
