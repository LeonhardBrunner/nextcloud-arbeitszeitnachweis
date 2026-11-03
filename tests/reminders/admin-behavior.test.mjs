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
async function openHrSettings(initialRules = [{ id: 'one', hrGroups: ['HR'], userGroups: ['Staff'], priority: 2 }], respondToSave = null, language = 'en', peopleOptions = {}) {
	const elements = new Map();
	const events = new Map();
	const requests = [];
	const messages = [];
	const translations = {};
	const searchRequests = [];
	const timers = new Map();
	let timerId = 0;
	let generatedElementId = 0;

	const decode = value => String(value).replace(/&(?:amp|lt|gt|quot|apos|#39|times|#215|#x0*d7);/gi, entity => ({ '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&apos;': "'", '&#39;': "'" }[entity] ?? '×'));
	const descendants = current => current.children.flatMap(child => [child, ...descendants(child)]);
	function matches(current, selector) {
		const checked = selector.endsWith(':checked');
		const base = checked ? selector.slice(0, -8) : selector;
		if (checked && !current.checked) return false;
		if (base.startsWith('.')) return current.className.split(/\s+/).includes(base.slice(1));
		const attribute = base.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
		if (attribute) return Object.hasOwn(current.attributes, attribute[1]) && (attribute[2] === undefined || current.attributes[attribute[1]] === attribute[2]);
		return current.tagName.toLowerCase() === base;
	}
	function element(id, tag = 'div', attributes = {}) {
		const current = {
			id, tagName: tag.toUpperCase(), className: attributes.class || '', dataset: {}, attributes,
			children: [], parentElement: null, _text: '', value: attributes.value || '',
			checked: Object.hasOwn(attributes, 'checked'), disabled: Object.hasOwn(attributes, 'disabled'), hidden: Object.hasOwn(attributes, 'hidden'),
			addEventListener(name, callback) {
				const key = `${id}:${name}`;
				events.set(key, [...(events.get(key) || []), callback]);
			},
			appendChild(child) { this.children.push(child); child.parentElement = this; },
			replaceChildren(...children) { this.children = []; children.forEach(child => this.appendChild(child)); },
			set textContent(value) { this._text = String(value); this.children = []; },
			get textContent() { return this._text + this.children.map(child => child.textContent).join(''); },
			get controls() { return descendants(this).filter(child => child.tagName === 'INPUT'); },
			closest(selector) { return matches(this, selector) ? this : this.parentElement?.closest(selector) || null; },
			set innerHTML(value) {
				this.html = value;
				this.children = [];
				this._text = '';
				const stack = [this];
				for (const token of value.matchAll(/<!--[\s\S]*?-->|<\/?[^>]+>|[^<]+/g)) {
					const markup = token[0];
					if (markup.startsWith('<!--')) continue;
					if (markup.startsWith('</')) { stack.pop(); continue; }
					if (!markup.startsWith('<')) { stack.at(-1)._text += decode(markup); continue; }
					const tagName = markup.match(/^<([\w-]+)/)?.[1];
					if (!tagName) continue;
					const parsedAttributes = {};
					const attributesText = markup.slice(tagName.length + 1, -1);
					for (const attribute of attributesText.matchAll(/([^\s=/>]+)(?:="([^"]*)"|='([^']*)'|=([^\s>]+))?/g)) {
						parsedAttributes[attribute[1]] = decode(attribute[2] ?? attribute[3] ?? attribute[4] ?? '');
					}
					const child = element(parsedAttributes.id || `generated-${++generatedElementId}-${tagName}`, tagName, parsedAttributes);
					stack.at(-1).appendChild(child);
					if (tagName === 'option' && Object.hasOwn(parsedAttributes, 'selected')) child.parentElement.value = child.value;
					if (!['input', 'hr', 'br', 'img', 'meta', 'link'].includes(tagName) && !markup.endsWith('/>')) stack.push(child);
				}
			},
			get innerHTML() { return this.html || ''; },
			querySelector(selector) {
				return descendants(this).find(child => matches(child, selector)) || null;
			},
			querySelectorAll(selector) {
				return descendants(this).filter(child => matches(child, selector));
			},
		};
		for (const [name, value] of Object.entries(attributes)) {
			if (name.startsWith('data-')) current.dataset[name.slice(5).replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase())] = value;
		}
		elements.set(id, current);
		return current;
	}

	for (const id of ['timesheet-hr-settings', 'timesheet-hr-rules', 'timesheet-add-hr-rule', 'timesheet-specialdays-overtime']) element(id);
	element('timesheet-all-groups-data').textContent = JSON.stringify(['HR', 'Staff']);
	element('timesheet-hr-rules-data').textContent = JSON.stringify(initialRules);
	element('timesheet-hr-people-data').textContent = JSON.stringify(peopleOptions.labels || []);
	class FixedDate extends Date {
		constructor(...arguments_) {
			super(...(arguments_.length ? arguments_ : ['2026-10-28T23:30:00Z']));
		}
	}
	const context = vm.createContext({
		document: {
			getElementById: id => elements.get(id),
			createElement: tag => element(`generated-${++generatedElementId}-${tag}`, tag),
		},
		Date: FixedDate,
		Intl,
		FormData,
		URLSearchParams,
		AbortController,
		setTimeout: callback => { const id = ++timerId; timers.set(id, callback); return id; },
		clearTimeout: id => timers.delete(id),
		crypto: { randomUUID: () => 'new-rule' },
		t: (_app, message) => translations[message] ?? message,
		console: Object.fromEntries(['log', 'warn', 'error'].map(name => [name, () => {}])),
		OC: {
			requestToken: 'test-token', generateUrl: url => url,
			Notification: { showTemporary: message => messages.push(message) },
			L10N: { register: (_app, messages) => Object.assign(translations, messages) },
		},
		fetch: async (url, options) => {
			if (url.includes('/api/hr/people?')) {
				const parameters = new URLSearchParams(url.split('?')[1]);
				const request = {
					url, headers: options.headers, signal: options.signal,
					search: parameters.get('search'), purpose: parameters.get('purpose'),
					hrGroups: parameters.getAll('hrGroups[]'), hrUsers: parameters.getAll('hrUsers[]'),
				};
				searchRequests.push(request);
				if (peopleOptions.respondToSearch) return peopleOptions.respondToSearch(request, searchRequests.length);
				const query = request.search.toLowerCase();
				const users = (peopleOptions.users || []).filter(person => `${person.name} ${person.id}`.toLowerCase().includes(query));
				return { ok: true, json: async () => ({ users, hasMore: false }) };
			}
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
		requests, messages, searchRequests,
		card: () => elements.get('timesheet-hr-rules').children[0],
		picker(purpose) {
			const wrapper = this.card().querySelector(`[data-person-picker="${purpose}"]`);
			return { wrapper, input: wrapper.querySelector('.ts-person-search'), results: wrapper.querySelector('.ts-person-results'), addButton: wrapper.querySelector('.ts-person-add'), status: wrapper.querySelector('.ts-person-search-status') };
		},
		async searchAccounts(purpose, query) {
			const input = this.picker(purpose).input;
			input.value = query;
			this.type(input);
			const pending = [...timers.values()];
			timers.clear();
			await Promise.all(pending.map(callback => callback()));
		},
		async focusSearch(purpose) {
			const input = this.picker(purpose).input;
			await Promise.all((events.get('timesheet-hr-rules:focusin') || []).map(callback => callback({ target: input })));
			await new Promise(setImmediate);
		},
		async choosePerson(purpose, userId) {
			const picker = this.picker(purpose);
			picker.results.value = userId;
			await this.change(picker.results);
			await this.click(picker.addButton);
		},
		async removePerson(purpose, userId) {
			const button = this.picker(purpose).wrapper.querySelectorAll('.ts-person-remove').find(candidate => candidate.dataset.personId === userId);
			assert.ok(button, 'The person removal control exists');
			await this.click(button);
		},
		async setMailMode(mode) {
			const select = this.card().querySelector('.ts-mail-recipient-mode');
			select.value = mode;
			await this.change(select);
		},
		async click(target) {
			await Promise.all((events.get('timesheet-hr-rules:click') || []).map(callback => callback({ target, preventDefault() {} })));
		},
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

const assignmentRule = (overrides = {}) => ({ id: 'assignment', hrGroups: ['HR'], hrUsers: [], userGroups: ['Staff'], ...overrides });
const accounts = [
	{ id: 'anton', name: 'Anton Example', enabled: true },
	{ id: 'bea', name: 'Bea Example', enabled: true },
];
const searchResponse = users => ({ ok: true, json: async () => ({ users, hasMore: false }) });

test('legacy rules do not save on load and retain their group recipients on the next edit', async () => {
	const hrSettings = await openHrSettings();
	assert.equal(hrSettings.requests.length, 0);
	assert.equal(hrSettings.searchRequests.length, 0);
	assert.equal(hrSettings.card().querySelector('.ts-mail-recipient-mode').value, 'all');
	hrSettings.input('graceDays').value = '6';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.deepEqual(hrSettings.requests[0].rules[0].hrGroups, ['HR']);
	assert.deepEqual(hrSettings.requests[0].rules[0].hrUsers, []);
	assert.deepEqual(hrSettings.requests[0].rules[0].mailRecipients, { mode: 'all', userIds: [] });
});

test('person-only responsibility and selected recipients survive unrelated edits and reopening', async () => {
	const rule = assignmentRule({ hrGroups: [], hrUsers: ['anton'], mailRecipients: { mode: 'selected', userIds: ['anton'] } });
	const hrSettings = await openHrSettings([rule], null, 'en', { labels: accounts });
	assert.ok(hrSettings.picker('responsible').wrapper.textContent.includes('Anton Example (anton)'));
	assert.equal(hrSettings.card().querySelector('.ts-selected-mail-recipients').hidden, false);
	hrSettings.input('graceDays').value = '5';
	await hrSettings.change(hrSettings.input('graceDays'));
	const saved = hrSettings.requests[0].rules[0];
	assert.deepEqual(saved.hrGroups, []);
	assert.deepEqual(saved.hrUsers, ['anton']);
	assert.deepEqual(saved.mailRecipients, { mode: 'selected', userIds: ['anton'] });
	assert.deepEqual(saved.userGroups, ['Staff']);
	const reopened = await openHrSettings([saved], null, 'en', { labels: accounts });
	assert.equal(reopened.requests.length, 0);
	assert.equal(reopened.card().querySelector('.ts-mail-recipient-mode').value, 'selected');
	assert.ok(reopened.picker('mail').wrapper.textContent.includes('Anton Example (anton)'));
});

test('account search is read-only, waits for two characters, and adds an enabled person alongside HR groups', async () => {
	const hrSettings = await openHrSettings([assignmentRule()], null, 'en', { users: accounts });
	await hrSettings.searchAccounts('responsible', 'a');
	assert.equal(hrSettings.searchRequests.length, 0);
	assert.equal(hrSettings.picker('responsible').status.textContent, 'Enter at least two characters.');
	await hrSettings.searchAccounts('responsible', 'An');
	assert.equal(hrSettings.requests.length, 0);
	assert.equal(hrSettings.searchRequests[0].purpose, 'responsible');
	assert.deepEqual(hrSettings.searchRequests[0].hrGroups, ['HR']);
	assert.deepEqual(hrSettings.searchRequests[0].hrUsers, []);
	assert.equal(hrSettings.searchRequests[0].headers.requesttoken, 'test-token');
	await hrSettings.choosePerson('responsible', 'anton');
	assert.equal(hrSettings.requests.length, 1);
	assert.deepEqual(hrSettings.requests[0].rules[0].hrGroups, ['HR']);
	assert.deepEqual(hrSettings.requests[0].rules[0].hrUsers, ['anton']);
	assert.deepEqual(hrSettings.requests[0].rules[0].mailRecipients, { mode: 'all', userIds: [] });
});

test('selected mode with no accounts explicitly disables HR mail without changing responsibility', async () => {
	const hrSettings = await openHrSettings([assignmentRule({ hrUsers: ['anton'] })], null, 'en', { users: accounts });
	await hrSettings.setMailMode('selected');
	assert.deepEqual(hrSettings.requests[0].rules[0].mailRecipients, { mode: 'selected', userIds: [] });
	assert.deepEqual(hrSettings.requests[0].rules[0].hrUsers, ['anton']);
	assert.ok(hrSettings.card().innerHTML.includes('No selected accounts means no HR summary for this rule.'));
	await hrSettings.focusSearch('mail');
	assert.equal(hrSettings.searchRequests.at(-1).purpose, 'mail');
	assert.equal(hrSettings.searchRequests.at(-1).search, '');
	assert.deepEqual(hrSettings.searchRequests.at(-1).hrGroups, ['HR']);
	assert.deepEqual(hrSettings.searchRequests.at(-1).hrUsers, ['anton']);
	await hrSettings.choosePerson('mail', 'bea');
	assert.deepEqual(hrSettings.requests[1].rules[0].mailRecipients, { mode: 'selected', userIds: ['bea'] });
	assert.deepEqual(hrSettings.requests[1].rules[0].hrUsers, ['anton']);
});

test('removing responsibility never quietly clears or widens a selected mail list', async () => {
	const rule = assignmentRule({ hrUsers: ['anton'], mailRecipients: { mode: 'selected', userIds: ['anton'] } });
	const hrSettings = await openHrSettings([rule], async (request, count) => count === 1
		? { ok: false, status: 400, text: async () => JSON.stringify({ message: 'Mail recipients must be responsible for this rule.' }) }
		: saveResponse(request), 'en', { labels: accounts });
	await hrSettings.removePerson('responsible', 'anton');
	assert.deepEqual(hrSettings.requests[0].rules[0].hrUsers, []);
	assert.deepEqual(hrSettings.requests[0].rules[0].mailRecipients, { mode: 'selected', userIds: ['anton'] });
	assert.deepEqual(hrSettings.messages, ['Mail recipients must be responsible for this rule.']);
	await hrSettings.removePerson('mail', 'anton');
	assert.deepEqual(hrSettings.requests[1].rules[0].mailRecipients, { mode: 'selected', userIds: [] });
	assert.deepEqual(hrSettings.requests[1].rules[0].hrUsers, []);
});

test('search failure and empty results remain distinct and never save settings', async () => {
	const hrSettings = await openHrSettings([assignmentRule()], null, 'en', {
		respondToSearch: (_request, count) => count === 1 ? { ok: false, status: 500 } : searchResponse([]),
	});
	await hrSettings.searchAccounts('responsible', 'An');
	assert.equal(hrSettings.picker('responsible').status.textContent, 'Accounts could not be loaded. Try again.');
	assert.equal(hrSettings.picker('responsible').results.disabled, true);
	await hrSettings.searchAccounts('responsible', 'Nobody');
	assert.equal(hrSettings.picker('responsible').status.textContent, 'No matching accounts.');
	assert.equal(hrSettings.requests.length, 0);
});

test('late search responses cannot replace newer query results even if transport ignores abort', async () => {
	let releaseFirst;
	const hrSettings = await openHrSettings([assignmentRule()], null, 'en', {
		respondToSearch: async (_request, count) => {
			if (count === 1) await new Promise(resolve => { releaseFirst = resolve; });
			return searchResponse([accounts[count === 1 ? 0 : 1]]);
		},
	});
	const first = hrSettings.searchAccounts('responsible', 'An');
	await new Promise(setImmediate);
	await hrSettings.searchAccounts('responsible', 'Be');
	releaseFirst();
	await first;
	assert.equal(hrSettings.searchRequests[0].signal.aborted, true);
	assert.deepEqual(hrSettings.picker('responsible').results.children.map(option => option.value), ['', 'bea']);
	assert.equal(hrSettings.requests.length, 0);
});

test('a response for a replaced rule card cannot populate its successor', async () => {
	let releaseSearch;
	const hrSettings = await openHrSettings([assignmentRule()], null, 'en', {
		respondToSearch: async () => { await new Promise(resolve => { releaseSearch = resolve; }); return searchResponse(accounts); },
	});
	const originalCard = hrSettings.card();
	const pendingSearch = hrSettings.searchAccounts('responsible', 'An');
	await new Promise(setImmediate);
	hrSettings.input('graceDays').value = '3';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.notEqual(hrSettings.card(), originalCard);
	releaseSearch();
	await pendingSearch;
	assert.deepEqual(hrSettings.picker('responsible').results.children.map(option => option.value), ['']);
	assert.equal(hrSettings.requests.length, 1);
});

test('display names remain text in selected chips and search options', async () => {
	const malicious = { id: 'danger', name: '</script><img src=x onerror=alert(1)>', enabled: true };
	const hrSettings = await openHrSettings([assignmentRule({ hrUsers: ['danger'] })], null, 'en', { labels: [malicious], users: [malicious] });
	assert.equal(hrSettings.picker('responsible').wrapper.querySelectorAll('img').length, 0);
	assert.equal(hrSettings.picker('responsible').wrapper.querySelectorAll('script').length, 0);
	assert.ok(hrSettings.card().innerHTML.includes('&lt;/script&gt;&lt;img'));
	await hrSettings.searchAccounts('responsible', 'danger');
	assert.equal(hrSettings.picker('responsible').results.children[1].textContent, `${malicious.name} (danger)`);
	assert.equal(hrSettings.picker('responsible').results.children[1].children.length, 0);
});

test('disabled accounts stay visible as existing assignments but cannot be added from search', async () => {
	const disabled = { id: 'disabled', name: 'Former account', enabled: false };
	const hrSettings = await openHrSettings([assignmentRule({ hrUsers: ['disabled'] })], null, 'en', { labels: [disabled], users: [disabled] });
	assert.ok(hrSettings.picker('responsible').wrapper.textContent.includes('Disabled account'));
	await hrSettings.searchAccounts('responsible', 'disabled');
	assert.equal(hrSettings.picker('responsible').results.children[1].disabled, true);
	await hrSettings.choosePerson('responsible', 'disabled');
	assert.equal(hrSettings.requests.length, 0);
});

for (const malformed of [null, { mode: 'all', userIds: null }, { mode: 'all', userIds: 'anton' }, { mode: 'selected', userIds: [5] }]) {
	test(`malformed explicit mail settings never fall back to all recipients: ${JSON.stringify(malformed)}`, async () => {
		const hrSettings = await openHrSettings([assignmentRule({ mailRecipients: malformed })]);
		assert.equal(hrSettings.card().querySelector('.ts-mail-recipient-mode').value, 'selected');
		hrSettings.input('graceDays').value = '3';
		await hrSettings.change(hrSettings.input('graceDays'));
		assert.deepEqual(hrSettings.requests[0].rules[0].mailRecipients, { mode: 'selected', userIds: [] });
	});
}

test('returning to all recipients clears hidden stale selections without changing HR access', async () => {
	const hrSettings = await openHrSettings([assignmentRule({ hrUsers: ['anton'], mailRecipients: { mode: 'selected', userIds: ['deleted-account'] } })], null, 'en', { labels: accounts });
	await hrSettings.setMailMode('all');
	assert.deepEqual(hrSettings.requests[0].rules[0].mailRecipients, { mode: 'all', userIds: [] });
	assert.deepEqual(hrSettings.requests[0].rules[0].hrUsers, ['anton']);
	assert.deepEqual(hrSettings.requests[0].rules[0].hrGroups, ['HR']);
});

for (const message of ['At least one HR group or enabled HR account must remain assigned.', 'Invalid HR account selection.', 'Invalid mail recipient settings.']) {
	test(`assignment validation stays understandable: ${message}`, async () => {
		const hrSettings = await openHrSettings([assignmentRule()], async () => ({ ok: false, status: 400, text: async () => JSON.stringify({ message }) }));
		hrSettings.input('graceDays').value = '5';
		await hrSettings.change(hrSettings.input('graceDays'));
		assert.deepEqual(hrSettings.messages, [message.startsWith('Invalid') ? 'HR assignments or email recipients are invalid.' : message]);
	});
}
