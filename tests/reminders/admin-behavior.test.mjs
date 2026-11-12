import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const hrSettingsSource = readFileSync(resolve(repositoryRoot, 'js/hr-settings.js'), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const rulesRevision = value => String(value).padStart(64, '0');

// Exercise the actual HR settings event handlers with a small DOM containing the rendered inputs.
async function openHrSettings(initialRules = [{ id: 'one', hrGroups: ['HR'], userGroups: ['Staff'], priority: 2 }], respondToSave = null, language = 'en', peopleOptions = {}, scope = 'policy', includeHelp = false, policySection = '') {
	const elements = new Map();
	const events = new Map();
	const requests = [];
	const messages = [];
	const translations = {};
	const searchRequests = [];
	const otherRequests = [];
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
			setAttribute(name, value) { this.attributes[name] = String(value); },
			getAttribute(name) { return this.attributes[name] ?? null; },
			removeAttribute(name) { delete this.attributes[name]; },
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

	for (const id of ['timesheet-hr-settings', 'timesheet-hr-rules', scope === 'assignments' ? 'timesheet-add-hr-rule' : 'timesheet-specialdays-overtime']) element(id);
	elements.get('timesheet-hr-settings').dataset = { settingsScope: scope, rulesRevision: rulesRevision(0), policySection };
	if (policySection === 'notifications') {
		elements.delete('timesheet-specialdays-overtime');
		element('timesheet-notification-rule', 'select');
	}
	if (peopleOptions.rootUnavailable) elements.delete('timesheet-hr-settings');
	if (includeHelp) {
		element('timesheet-rules-help-toggle', 'button', { 'aria-expanded': 'false', 'aria-controls': 'timesheet-rules-help' });
		element('timesheet-rules-help', 'div', { hidden: '' });
	}
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
					ruleId: parameters.get('ruleId'),
					hrGroups: parameters.getAll('hrGroups[]'), hrUsers: parameters.getAll('hrUsers[]'),
				};
				searchRequests.push(request);
				if (peopleOptions.respondToSearch) return peopleOptions.respondToSearch(request, searchRequests.length);
				const query = request.search.toLowerCase();
				const users = (peopleOptions.users || []).filter(person => `${person.name} ${person.id}`.toLowerCase().includes(query));
				return { ok: true, json: async () => ({ users, hasMore: false }) };
			}
			const rules = options.body?.get('rules');
			if (!rules) {
				otherRequests.push({ url, method: options.method });
				return { ok: true, status: 200, json: async () => ({ check: false }) };
			}
			const request = { url, headers: options.headers, rules: JSON.parse(rules), rulesRevision: options.body.get('rulesRevision'), policySection: options.body.get('policySection'), nextRevision: rulesRevision(requests.length + 1) };
			requests.push(request);
			return respondToSave ? respondToSave(request, requests.length) : saveResponse(request);
		},
	});
	context.window = context;
	if (language === 'de' || language === 'de_DE') {
		vm.runInContext(readFileSync(resolve(repositoryRoot, `l10n/${language}.js`), 'utf8'), context, { filename: `${language}.js` });
	}
	vm.runInContext(hrSettingsSource, context, { filename: 'hr-settings.js' });
	await new Promise(setImmediate);

	return {
		requests, messages, searchRequests, otherRequests, elements,
		async selectRule(ruleId) {
			const selector = elements.get('timesheet-notification-rule');
			selector.value = ruleId;
			await Promise.all((events.get('timesheet-notification-rule:change') || []).map(callback => callback({ target: selector })));
		},
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
			if (purpose === 'mail') {
				const suggestion = picker.results.children.find(option => option.dataset.personId === userId);
				assert.ok(suggestion, 'The account appears in the suggestions');
				await this.click(suggestion);
				return;
			}
			picker.results.value = userId;
			await this.change(picker.results);
			await this.click(picker.addButton);
		},
		async pressSearchKey(purpose, key) {
			const input = this.picker(purpose).input;
			await Promise.all((events.get('timesheet-hr-rules:keydown') || []).map(callback => callback({ target: input, key, preventDefault() {} })));
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
			const callbacks = events.get(`${target.id}:click`) || events.get('timesheet-hr-rules:click') || [];
			await Promise.all(callbacks.map(callback => callback({ target, preventDefault() {} })));
		},
		input: key => elements.get('timesheet-hr-rules').children[0].querySelector(`[data-reminder-key="${key}"]`),
		balanceInput: key => elements.get('timesheet-hr-rules').children[0].querySelector(`[data-balance-key="${key}"]`),
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
	return { ok: true, status: 200, json: async () => ({ rules: request.rules, rulesRevision: request.nextRevision }) };
}

function openAdminSettings(initialRules, respondToSave, language, peopleOptions) {
	return openHrSettings(initialRules, respondToSave, language, peopleOptions, 'assignments');
}

for (const language of ['de', 'de_DE']) {
	test(`German settings keep translated labels in their respective role (${language})`, async () => {
		const hrSettings = await openHrSettings(undefined, null, language);
		const cardHtml = hrSettings.card().innerHTML;
		for (const label of ['Gruppenregel', 'Zeitregeln', 'Erinnerung für einzelne fehlende oder unvollständige Arbeitstage']) {
			assert.ok(cardHtml.includes(label), `Missing German HR settings label: ${label}`);
		}
		const admin = await openAdminSettings(undefined, null, language);
		for (const label of ['Gruppenregel', 'Priorität', 'HR-Gruppen', 'Einzelne HR-Konten']) assert.ok(admin.card().innerHTML.includes(label));
		assert.ok(!cardHtml.includes('Group rule'));
		assert.ok(!cardHtml.includes('Reminder for missing or incomplete time entries'));
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
	const hrSettings = await openAdminSettings(undefined, null, 'de');
	const controls = Array.from(hrSettings.card().innerHTML.matchAll(/<a\b[^>]*class="ts-(?:chip-remove|rule-delete)"[^>]*>([^<]*)<\/a>/g));
	assert.equal(controls.length, 3);
	for (const [html, label] of controls) {
		const visibleLabel = label.replace(/&(?:times|#215|#x0*d7);/gi, '×').trim();
		assert.equal(visibleLabel, '×');
		assert.match(html, /aria-label="(?:Entfernen|Regel löschen)"/);
	}
});

test('English HR settings describe the days checked by reminders', async () => {
	const hrSettings = await openHrSettings();
	assert.ok(hrSettings.card().innerHTML.includes('Group rule'));
	assert.ok(!hrSettings.card().innerHTML.includes('Priority'));
	assert.ok(hrSettings.card().innerHTML.includes('Reminder for missing or incomplete time entries'));
	assert.ok(hrSettings.card().innerHTML.includes('calendar days after the workday'));
	const admin = await openAdminSettings();
	assert.ok(admin.card().innerHTML.includes('Priority'));
	assert.ok(!admin.card().innerHTML.includes('Reminder for missing or incomplete time entries'));
});

test('legacy rules render disabled reminders with Monday to Friday defaults', async () => {
	const hrSettings = await openHrSettings();
	assert.equal(hrSettings.input('notifyEmployees').checked, false);
	assert.equal(hrSettings.input('notifyHr').checked, false);
	assert.equal(hrSettings.input('enabled'), null, 'The combined reminder switch is no longer rendered');
	assert.ok(hrSettings.card().textContent.includes('Send reminders to employees'));
	assert.ok(hrSettings.card().textContent.includes('Send reminders to responsible HR'));
	assert.equal(hrSettings.input('graceDays').value, '14');
	assert.equal(hrSettings.input('startDate').value, '');
	assert.deepEqual([1, 2, 3, 4, 5, 6, 7].filter(day => hrSettings.weekday(day).checked), [1, 2, 3, 4, 5]);
	assert.equal(hrSettings.requests.length, 0);
});

test('first enable uses the Berlin calendar date and saves the complete policy', async () => {
	const hrSettings = await openHrSettings();
	hrSettings.input('notifyEmployees').checked = true;
	await hrSettings.change(hrSettings.input('notifyEmployees'));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
		enabled: true, notifyEmployees: true, notifyHr: false, graceDays: 14, weekdays: [1, 2, 3, 4, 5], startDate: '2026-10-29',
	});
	assert.equal(hrSettings.requests[0].rules[0].priority, 2);
	assert.deepEqual(hrSettings.requests[0].rules[0].userGroups, ['Staff']);
	assert.equal(hrSettings.requests[0].headers.requesttoken, 'test-token');
	assert.equal(hrSettings.input('notifyEmployees').checked, true);
	assert.equal(hrSettings.input('notifyHr').checked, false);
	assert.equal(hrSettings.input('startDate').value, '2026-10-29');
});

test('the first HR-only reminder also initializes the shared Berlin start date', async () => {
	const hrSettings = await openHrSettings();
	hrSettings.input('notifyHr').checked = true;
	await hrSettings.change(hrSettings.input('notifyHr'));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
		enabled: true, notifyEmployees: false, notifyHr: true,
		graceDays: 14, weekdays: [1, 2, 3, 4, 5], startDate: '2026-10-29',
	});
	assert.equal(hrSettings.input('notifyEmployees').checked, false);
	assert.equal(hrSettings.input('notifyHr').checked, true);
});

for (const enabled of [false, true]) {
	test(`legacy combined selection ${enabled} restores both recipients without saving`, async () => {
		const hrSettings = await openHrSettings([{
			id: 'legacy-rule', hrGroups: ['HR'], userGroups: ['Staff'],
			missingEntryReminders: { enabled, graceDays: 5, weekdays: [2, 5], startDate: '2026-09-01' },
		}]);
		assert.equal(hrSettings.input('notifyEmployees').checked, enabled);
		assert.equal(hrSettings.input('notifyHr').checked, enabled);
		assert.equal(hrSettings.requests.length, 0);
		hrSettings.input('graceDays').value = '6';
		await hrSettings.change(hrSettings.input('graceDays'));
		assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
			enabled, notifyEmployees: enabled, notifyHr: enabled,
			graceDays: 6, weekdays: [2, 5], startDate: '2026-09-01',
		});
	});
}

for (const [notifyEmployees, notifyHr] of [[false, false], [false, true], [true, false], [true, true]]) {
	test(`recipient choices stay independent and survive reopening: employees=${notifyEmployees}, HR=${notifyHr}`, async () => {
		const hrSettings = await openHrSettings([{
			id: 'split-rule', hrGroups: ['HR'], userGroups: ['Staff'],
			missingEntryReminders: { enabled: !(notifyEmployees || notifyHr), notifyEmployees, notifyHr,
				graceDays: 5, weekdays: [1, 3], startDate: '2026-09-01' },
		}]);
		assert.equal(hrSettings.input('notifyEmployees').checked, notifyEmployees);
		assert.equal(hrSettings.input('notifyHr').checked, notifyHr);
		hrSettings.input('notifyEmployees').checked = !notifyEmployees;
		await hrSettings.change(hrSettings.input('notifyEmployees'));
		const employeeSave = hrSettings.requests[0].rules[0].missingEntryReminders;
		assert.equal(employeeSave.notifyEmployees, !notifyEmployees);
		assert.equal(employeeSave.notifyHr, notifyHr, 'An employee toggle leaves the HR choice unchanged');
		assert.equal(employeeSave.enabled, !notifyEmployees || notifyHr);
		assert.equal(employeeSave.startDate, '2026-09-01');
		const reopened = await openHrSettings(hrSettings.requests[0].rules);
		assert.equal(reopened.input('notifyEmployees').checked, !notifyEmployees);
		assert.equal(reopened.input('notifyHr').checked, notifyHr);
		assert.equal(reopened.requests.length, 0);
		reopened.input('notifyHr').checked = !notifyHr;
		await reopened.change(reopened.input('notifyHr'));
		const hrSave = reopened.requests[0].rules[0].missingEntryReminders;
		assert.equal(hrSave.notifyEmployees, !notifyEmployees, 'An HR toggle leaves the employee choice unchanged');
		assert.equal(hrSave.notifyHr, !notifyHr);
		assert.equal(hrSave.enabled, !notifyEmployees || !notifyHr);
		assert.equal(hrSave.startDate, '2026-09-01');
		const admin = await openAdminSettings(reopened.requests[0].rules);
		await admin.addHrGroup('Other');
		assert.deepEqual(admin.requests[0].rules[0].missingEntryReminders, hrSave,
			'Editing assignments never resets the two recipient choices');
	});
}

test('an explicit recipient selection never falls back to a true legacy combined flag', async () => {
	for (const selection of [{ notifyEmployees: false }, { notifyHr: false }, { notifyEmployees: 'true', notifyHr: null },
		{ notifyEmployees: true, notifyHr: 'true' }, { notifyEmployees: 'true', notifyHr: true }]) {
		const hrSettings = await openHrSettings([{
			id: 'split-rule', hrGroups: ['HR'], userGroups: ['Staff'],
			missingEntryReminders: { enabled: true, ...selection, graceDays: 5, weekdays: [1], startDate: '2026-09-01' },
		}]);
		assert.equal(hrSettings.input('notifyEmployees').checked, false);
		assert.equal(hrSettings.input('notifyHr').checked, false);
		assert.equal(hrSettings.requests.length, 0);
	}
});

test('changing the other recipient does not replace a cleared active-policy start date', async () => {
	const hrSettings = await openHrSettings([{
		id: 'split-rule', hrGroups: ['HR'], userGroups: ['Staff'],
		missingEntryReminders: { enabled: true, notifyEmployees: true, notifyHr: false,
			graceDays: 5, weekdays: [1], startDate: '2026-09-01' },
	}]);
	hrSettings.input('startDate').value = '';
	hrSettings.input('notifyHr').checked = true;
	await hrSettings.change(hrSettings.input('notifyHr'));
	assert.equal(hrSettings.requests.length, 0);
	assert.equal(hrSettings.input('startDate').value, '');
	assert.deepEqual(hrSettings.messages, ['Select a valid start date for reminders.']);
});

test('editing disabled reminder settings never enables the feature', async () => {
	const hrSettings = await openHrSettings();
	hrSettings.input('graceDays').value = '8';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.deepEqual(hrSettings.requests[0].rules[0].missingEntryReminders, {
		enabled: false, notifyEmployees: false, notifyHr: false, graceDays: 8, weekdays: [1, 2, 3, 4, 5], startDate: '',
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
		enabled: true, notifyEmployees: true, notifyHr: true, graceDays: 9, weekdays: [2, 6], startDate: '2026-09-01',
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
	hrSettings.input('notifyEmployees').checked = true;
	const firstSave = hrSettings.change(hrSettings.input('notifyEmployees'));
	await new Promise(setImmediate);
	hrSettings.input('graceDays').value = '3';
	const secondSave = hrSettings.change(hrSettings.input('graceDays'));
	assert.equal(hrSettings.requests.length, 1);
	releaseFirstSave();
	await Promise.all([firstSave, secondSave]);
	assert.deepEqual(hrSettings.requests.map(request => request.rules[0].missingEntryReminders.graceDays), [14, 3]);
	assert.deepEqual(hrSettings.requests.map(request => request.rulesRevision), [rulesRevision(0), rulesRevision(1)]);
	assert.equal(hrSettings.input('graceDays').value, '3');
	assert.equal(hrSettings.input('notifyEmployees').checked, true);
});

test('typing during a pending save is preserved until the next change is submitted', async () => {
	let releaseFirstSave;
	const hrSettings = await openHrSettings(undefined, async (request, count) => {
		if (count === 1) await new Promise(resolve => { releaseFirstSave = resolve; });
		return saveResponse(request);
	});
	hrSettings.input('notifyEmployees').checked = true;
	const firstSave = hrSettings.change(hrSettings.input('notifyEmployees'));
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
	hrSettings.input('notifyEmployees').checked = true;
	await hrSettings.change(hrSettings.input('notifyEmployees'));
	assert.deepEqual(hrSettings.messages, ['Saving failed']);
	hrSettings.input('graceDays').value = '4';
	await hrSettings.change(hrSettings.input('graceDays'));
	assert.equal(hrSettings.requests.length, 2);
	assert.equal(hrSettings.requests[1].rules[0].missingEntryReminders.graceDays, 4);
	assert.deepEqual(hrSettings.requests.map(request => request.rulesRevision), [rulesRevision(0), rulesRevision(0)]);
});

test('the last HR group rejection is explained and does not block a queued reassignment', async () => {
	let releaseFirstSave;
	const hrSettings = await openAdminSettings(undefined, async (request, count) => {
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
	assert.equal(hrSettings.card().querySelector('[data-person-picker="responsible"]'), null);
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
	const hrSettings = await openAdminSettings([assignmentRule()], null, 'en', { users: accounts });
	await hrSettings.searchAccounts('responsible', 'a');
	assert.equal(hrSettings.searchRequests.length, 0);
	assert.equal(hrSettings.picker('responsible').status.textContent, 'Enter at least two characters.');
	await hrSettings.searchAccounts('responsible', 'An');
	assert.equal(hrSettings.requests.length, 0);
	assert.equal(hrSettings.searchRequests[0].purpose, 'responsible');
	assert.equal(hrSettings.searchRequests[0].ruleId, 'assignment');
	assert.deepEqual(hrSettings.searchRequests[0].hrGroups, []);
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
	assert.equal(hrSettings.searchRequests.at(-1).ruleId, 'assignment');
	assert.deepEqual(hrSettings.searchRequests.at(-1).hrGroups, []);
	assert.deepEqual(hrSettings.searchRequests.at(-1).hrUsers, []);
	await hrSettings.choosePerson('mail', 'bea');
	assert.deepEqual(hrSettings.requests[1].rules[0].mailRecipients, { mode: 'selected', userIds: ['bea'] });
	assert.deepEqual(hrSettings.requests[1].rules[0].hrUsers, ['anton']);
});

test('typing in the mail recipient picker opens scoped suggestions and allows keyboard selection', async () => {
	const rule = assignmentRule({ mailRecipients: { mode: 'selected', userIds: ['ta-selected'] } });
	const hr = await openHrSettings([rule], null, 'de', { users: [
		{ id: 'ta-selected', name: 'Tara A', enabled: true },
		{ id: 'ta-disabled', name: 'Tara B', enabled: false },
		{ id: 'taskin', name: 'Taskin', enabled: true },
		{ id: 'ella', name: 'Ella Taylor', enabled: true },
	] }, 'policy', false, 'notifications');
	await hr.searchAccounts('mail', 'Ta');
	assert.equal(hr.searchRequests[0].ruleId, 'assignment');
	assert.equal(hr.searchRequests[0].search, 'Ta');
	const picker = hr.picker('mail');
	assert.equal(picker.input.getAttribute('aria-expanded'), 'true');
	assert.deepEqual(picker.results.children.map(option => option.dataset.personId), ['taskin', 'ella']);
	await hr.pressSearchKey('mail', 'ArrowDown');
	assert.equal(picker.input.getAttribute('aria-activedescendant'), picker.results.children[0].id);
	await hr.pressSearchKey('mail', 'Escape');
	assert.equal(picker.results.hidden, true);
	assert.equal(picker.input.getAttribute('aria-expanded'), 'false');
	assert.equal(hr.requests.length, 0);
	await hr.pressSearchKey('mail', 'ArrowUp');
	await hr.pressSearchKey('mail', 'Enter');
	assert.deepEqual(hr.requests[0].rules[0].mailRecipients.userIds, ['ta-selected', 'ella']);
	await hr.searchAccounts('mail', 'Nobody');
	assert.equal(hr.picker('mail').status.textContent, 'Keine passenden Konten gefunden.');
	assert.equal(hr.picker('mail').results.hidden, true);
});

test('assignment and mail changes use separate forms and retain the other settings in their snapshots', async () => {
	const rule = assignmentRule({ hrUsers: ['anton'], mailRecipients: { mode: 'selected', userIds: ['anton'] } });
	const admin = await openAdminSettings([rule], null, 'en', { labels: accounts });
	assert.equal(admin.card().querySelector('.ts-mail-recipient-mode'), null);
	await admin.removePerson('responsible', 'anton');
	const saved = admin.requests[0].rules[0];
	assert.deepEqual(saved.hrUsers, []);
	assert.deepEqual(saved.mailRecipients, { mode: 'selected', userIds: ['anton'] });
	assert.equal(admin.requests[0].url, '/apps/timesheet/settings/hr_access_rules');
	const hr = await openHrSettings([saved], null, 'en', { labels: accounts });
	await hr.removePerson('mail', 'anton');
	assert.deepEqual(hr.requests[0].rules[0].mailRecipients, { mode: 'selected', userIds: [] });
	assert.deepEqual(hr.requests[0].rules[0].hrUsers, []);
	assert.equal(hr.requests[0].url, '/apps/timesheet/settings/hr_policy_rules');
});

test('search failure and empty results remain distinct and never save settings', async () => {
	const hrSettings = await openAdminSettings([assignmentRule()], null, 'en', {
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
	const hrSettings = await openAdminSettings([assignmentRule()], null, 'en', {
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
	const hrSettings = await openAdminSettings([assignmentRule()], null, 'en', {
		respondToSearch: async () => { await new Promise(resolve => { releaseSearch = resolve; }); return searchResponse(accounts); },
	});
	const originalCard = hrSettings.card();
	const pendingSearch = hrSettings.searchAccounts('responsible', 'An');
	await new Promise(setImmediate);
	const priorityInput = hrSettings.card().querySelector('[data-key="priority"]');
	priorityInput.value = '3';
	await hrSettings.change(priorityInput);
	assert.notEqual(hrSettings.card(), originalCard);
	releaseSearch();
	await pendingSearch;
	assert.deepEqual(hrSettings.picker('responsible').results.children.map(option => option.value), ['']);
	assert.equal(hrSettings.requests.length, 1);
});

test('display names remain text in selected chips and search options', async () => {
	const malicious = { id: 'danger', name: '</script><img src=x onerror=alert(1)>', enabled: true };
	const hrSettings = await openAdminSettings([assignmentRule({ hrUsers: ['danger'] })], null, 'en', { labels: [malicious], users: [malicious] });
	assert.equal(hrSettings.picker('responsible').wrapper.querySelectorAll('img').length, 0);
	assert.equal(hrSettings.picker('responsible').wrapper.querySelectorAll('script').length, 0);
	assert.ok(hrSettings.card().innerHTML.includes('&lt;/script&gt;&lt;img'));
	await hrSettings.searchAccounts('responsible', 'danger');
	assert.equal(hrSettings.picker('responsible').results.children[1].textContent, `${malicious.name} (danger)`);
	assert.equal(hrSettings.picker('responsible').results.children[1].children.length, 0);
});

test('disabled accounts stay visible as existing assignments but cannot be added from search', async () => {
	const disabled = { id: 'disabled', name: 'Former account', enabled: false };
	const hrSettings = await openAdminSettings([assignmentRule({ hrUsers: ['disabled'] })], null, 'en', { labels: [disabled], users: [disabled] });
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

test('administrator and HR forms expose only their own controls and never save on load', async () => {
	const admin = await openAdminSettings();
	assert.equal(admin.card().querySelectorAll('.ts-rule-select').length, 2);
	assert.ok(admin.card().textContent.includes('Employee groups'));
	assert.ok(admin.card().querySelector('[data-kind="userGroups"]'));
	assert.ok(admin.card().querySelector('.ts-rule-priority'));
	assert.ok(admin.card().querySelector('[data-person-picker="responsible"]'));
	assert.equal(admin.card().querySelector('.ts-mail-recipient-mode'), null);
	assert.equal(admin.card().querySelector('.ts-reminder-settings'), null);
	assert.equal(admin.card().querySelector('.ts-balance-settings'), null);
	assert.ok(admin.elements.has('timesheet-add-hr-rule'));
	assert.equal(admin.elements.has('timesheet-specialdays-overtime'), false);
	assert.equal(admin.otherRequests.length, 0, 'The admin page has no overtime-setting request');
	const hr = await openHrSettings();
	assert.equal(hr.card().textContent.includes('Employee groups'), false);
	assert.equal(hr.card().textContent.includes('Staff'), false);
	assert.equal(hr.card().querySelector('.ts-rule-select'), null);
	assert.equal(hr.card().querySelector('.ts-rule-priority'), null);
	assert.equal(hr.card().querySelector('.ts-rule-delete'), null);
	assert.equal(hr.card().querySelector('.ts-chip-remove'), null);
	assert.equal(hr.card().querySelector('[data-person-picker="responsible"]'), null);
	assert.ok(hr.card().querySelector('.ts-mail-recipient-mode'));
	assert.ok(hr.card().querySelector('.ts-reminder-settings'));
	assert.ok(hr.card().querySelector('.ts-balance-settings'));
	assert.equal(hr.elements.has('timesheet-add-hr-rule'), false);
	assert.ok(hr.elements.has('timesheet-specialdays-overtime'));
	assert.equal(hr.otherRequests.length, 1);
	assert.equal(hr.requests.length + admin.requests.length, 0);
	await hr.addHrGroup('Other');
	await hr.removeHrGroup('HR');
	assert.equal(hr.requests.length, 0, 'Synthetic assignment events cannot use the HR policy writer');
});

for (const scope of ['assignments', 'policy']) {
	test(`a ${scope} conflict adopts the server snapshot, drops queued edits and permits an intentional retry`, async () => {
		let releaseFirst;
		const serverRules = [assignmentRule({ userGroups: ['Server only'], priority: 4,
			missingEntryReminders: { enabled: false, graceDays: 9, weekdays: [1, 2, 3, 4, 5], startDate: '' },
			employeeBalanceNotifications: { overtimeEnabled: true, overtimeThresholdMinutes: 750, negativeOvertimeEnabled: false, negativeOvertimeThresholdMinutes: 225 } })];
		const app = await openHrSettings(undefined, async (request, count) => {
			if (count !== 1) return saveResponse(request);
			await new Promise(resolve => { releaseFirst = resolve; });
			return { ok: false, status: 409, text: async () => JSON.stringify({ code: 'rules_conflict', rules: serverRules, rulesRevision: rulesRevision(7) }) };
		}, 'en', {}, scope);
		const control = () => scope === 'assignments' ? app.card().querySelector('[data-key="priority"]') : app.input('graceDays');
		control().value = '3';
		const first = app.change(control());
		await new Promise(setImmediate);
		control().value = '5';
		const queued = app.change(control());
		releaseFirst();
		await Promise.all([first, queued]);
		assert.equal(app.requests.length, 1, 'Queued edits from the stale snapshot are not replayed');
		assert.deepEqual(app.messages, ['Saving failed']);
		assert.equal(control().value, scope === 'assignments' ? '4' : '9');
		control().value = '6';
		await app.change(control());
		assert.equal(app.requests.length, 2);
		assert.equal(app.requests[1].rulesRevision, rulesRevision(7));
		assert.deepEqual(app.requests[1].rules[0].userGroups, ['Server only']);
		assert.deepEqual(app.requests[1].rules[0].employeeBalanceNotifications, serverRules[0].employeeBalanceNotifications);
		assert.equal(app.requests[1].url, scope === 'assignments' ? '/apps/timesheet/settings/hr_access_rules' : '/apps/timesheet/settings/hr_policy_rules');
	});
}

const defaultBalanceNotifications = {
	overtimeEnabled: false,
	overtimeThresholdMinutes: 600,
	negativeOvertimeEnabled: false,
	negativeOvertimeThresholdMinutes: 600,
};

test('legacy group rules show disabled balance notifications after missing-entry reminders without saving', async () => {
	const app = await openHrSettings();
	assert.equal(app.balanceInput('overtimeEnabled').checked, false);
	assert.equal(app.balanceInput('negativeOvertimeEnabled').checked, false);
	assert.equal(app.balanceInput('overtimeThresholdMinutes').value, '10');
	assert.equal(app.balanceInput('negativeOvertimeThresholdMinutes').value, '10');
	assert.ok(app.card().innerHTML.indexOf('ts-balance-settings') > app.card().innerHTML.indexOf('ts-reminder-settings'));
	assert.equal(app.requests.length, 0);
	for (const [field, label] of [['overtimeThresholdMinutes', 'Notify employees about overtime'], ['negativeOvertimeThresholdMinutes', 'Notify employees about negative hours']]) {
		const control = app.balanceInput(field);
		assert.equal(control.attributes.type, 'number');
		assert.equal(control.attributes.min, '0');
		assert.equal(control.attributes.max, '8760');
		assert.equal(control.attributes.step, '0.25');
		assert.equal(control.getAttribute('aria-label'), `${label}: Threshold (hours)`);
	}
});

for (const [overtimeEnabled, negativeOvertimeEnabled] of [[false, false], [true, false], [false, true], [true, true]]) {
	test(`balance channels stay independent and reopen with stored minute values: ${overtimeEnabled}/${negativeOvertimeEnabled}`, async () => {
		const app = await openHrSettings([assignmentRule({ employeeBalanceNotifications: { ...defaultBalanceNotifications, overtimeEnabled, negativeOvertimeEnabled } })]);
		assert.equal(app.balanceInput('overtimeEnabled').checked, overtimeEnabled);
		assert.equal(app.balanceInput('negativeOvertimeEnabled').checked, negativeOvertimeEnabled);
		app.balanceInput('overtimeEnabled').checked = !overtimeEnabled;
		await app.change(app.balanceInput('overtimeEnabled'));
		assert.deepEqual(app.requests[0].rules[0].employeeBalanceNotifications,
			{ ...defaultBalanceNotifications, overtimeEnabled: !overtimeEnabled, negativeOvertimeEnabled });
		app.balanceInput('overtimeThresholdMinutes').value = '12.25';
		await app.change(app.balanceInput('overtimeThresholdMinutes'));
		const reopened = await openHrSettings(app.requests[1].rules);
		assert.equal(reopened.balanceInput('overtimeEnabled').checked, !overtimeEnabled);
		assert.equal(reopened.balanceInput('negativeOvertimeEnabled').checked, negativeOvertimeEnabled);
		assert.equal(reopened.balanceInput('overtimeThresholdMinutes').value, '12.25');
		assert.equal(reopened.requests.length, 0);
		reopened.balanceInput('negativeOvertimeEnabled').checked = !negativeOvertimeEnabled;
		reopened.balanceInput('negativeOvertimeThresholdMinutes').value = '3.5';
		await reopened.change(reopened.balanceInput('negativeOvertimeEnabled'));
		const saved = reopened.requests[0].rules[0].employeeBalanceNotifications;
		assert.deepEqual(saved, { overtimeEnabled: !overtimeEnabled, overtimeThresholdMinutes: 735,
			negativeOvertimeEnabled: !negativeOvertimeEnabled, negativeOvertimeThresholdMinutes: 210 });
		const admin = await openAdminSettings(reopened.requests[0].rules);
		assert.equal(admin.balanceInput('overtimeEnabled'), null);
		await admin.addHrGroup('Other');
		assert.deepEqual(admin.requests[0].rules[0].employeeBalanceNotifications, saved);
	});
}

test('balance thresholds accept zero and the maximum duration independently', async () => {
	const app = await openHrSettings();
	app.balanceInput('overtimeThresholdMinutes').value = '0';
	app.balanceInput('negativeOvertimeThresholdMinutes').value = '8760';
	await app.change(app.balanceInput('overtimeThresholdMinutes'));
	assert.deepEqual(app.requests[0].rules[0].employeeBalanceNotifications,
		{ ...defaultBalanceNotifications, overtimeThresholdMinutes: 0, negativeOvertimeThresholdMinutes: 525600 });
});

for (const field of ['overtimeThresholdMinutes', 'negativeOvertimeThresholdMinutes']) {
	for (const invalidValue of ['', ' ', 'invalid', '-0.25', 'Infinity', 'NaN', '8760.01']) {
		test(`invalid ${field} hours ${JSON.stringify(invalidValue)} never reach the policy writer`, async () => {
			const app = await openHrSettings();
			app.balanceInput(field).value = invalidValue;
			await app.change(app.balanceInput(field));
			assert.equal(app.requests.length, 0);
			assert.deepEqual(app.messages, ['Enter a valid threshold in hours.']);
		});
	}
}

test('malformed stored balance settings never turn on either channel while rendering', async () => {
	for (const malformed of [null, [], 'enabled', { overtimeEnabled: 'true', negativeOvertimeEnabled: true },
		{ overtimeEnabled: true, negativeOvertimeEnabled: null },
		{ overtimeEnabled: true, overtimeThresholdMinutes: '600' },
		{ negativeOvertimeEnabled: true, overtimeThresholdMinutes: -1 },
		{ overtimeEnabled: true, negativeOvertimeThresholdMinutes: 525601 }]) {
		const app = await openHrSettings([assignmentRule({ employeeBalanceNotifications: malformed })]);
		assert.equal(app.balanceInput('overtimeEnabled').checked, false);
		assert.equal(app.balanceInput('negativeOvertimeEnabled').checked, false);
		assert.equal(app.requests.length, 0);
	}
});

test('new assignment rules do not inherit active employee balance channels from another rule', async () => {
	const app = await openAdminSettings([assignmentRule({ employeeBalanceNotifications: { ...defaultBalanceNotifications, overtimeEnabled: true, negativeOvertimeEnabled: true } })]);
	await app.click(app.elements.get('timesheet-add-hr-rule'));
	assert.equal(app.requests.length, 1);
	assert.deepEqual(app.requests[0].rules[1].employeeBalanceNotifications, defaultBalanceNotifications);
	assert.equal(app.requests[0].rules[0].employeeBalanceNotifications.overtimeEnabled, true);
});

for (const scope of ['assignments', 'policy']) {
	test(`the ${scope} group-rules help expands without writing settings`, async () => {
		const app = await openHrSettings(undefined, null, 'en', {}, scope, true);
		const button = app.elements.get('timesheet-rules-help-toggle');
		const help = app.elements.get('timesheet-rules-help');
		assert.equal(help.hidden, true);
		assert.equal(button.getAttribute('aria-expanded'), 'false');
		assert.equal(button.getAttribute('aria-controls'), help.id);
		await app.click(button);
		assert.equal(help.hidden, false);
		assert.equal(button.getAttribute('aria-expanded'), 'true');
		await app.click(button);
		assert.equal(help.hidden, true);
		assert.equal(button.getAttribute('aria-expanded'), 'false');
		assert.equal(app.requests.length, 0);
		assert.equal(app.searchRequests.length, 0);
	});
}


test('profile time rules retain only time controls and write the timeRules section', async () => {
	const app = await openHrSettings(undefined, null, 'en', {}, 'policy', false, 'timeRules');
	assert.ok(app.card().querySelector('[data-key="breakShortMinutes"]'));
	assert.equal(app.card().querySelector('.ts-mail-recipient-mode'), null);
	assert.equal(app.card().querySelector('.ts-reminder-input'), null);
	assert.equal(app.card().querySelector('.ts-balance-input'), null);
	assert.equal(app.requests.length, 0);
	const maximumHours = app.card().querySelector('[data-key="maxHours"]');
	maximumHours.value = '9';
	await app.change(maximumHours);
	assert.equal(app.requests[0].policySection, 'timeRules');
	assert.equal(app.requests[0].rules[0].maxHours, 9);
});

test('notification editor starts with the first saved rule and never loads calculation settings', async () => {
	const app = await openHrSettings([
		{ id: 'first', hrGroups: ['HR'], userGroups: ['Staff'], priority: 5 },
		{ id: 'second', hrGroups: ['HR'], userGroups: ['Other'], priority: 1 }
	], null, 'en', {}, 'policy', false, 'notifications');
	assert.equal(app.elements.get('timesheet-notification-rule').value, 'first');
	assert.equal(app.elements.get('timesheet-hr-rules').children.length, 1);
	assert.equal(app.card().dataset.ruleId, 'first');
	assert.ok(app.card().querySelector('.ts-mail-recipient-mode'));
	assert.ok(app.card().querySelector('.ts-reminder-input'));
	assert.ok(app.card().querySelector('.ts-balance-input'));
	assert.equal(app.card().querySelector('[data-key="maxHours"]'), null);
	assert.equal(app.card().querySelector('[data-kind="userGroups"]'), null);
	assert.equal(app.requests.length, 0);
	assert.equal(app.otherRequests.length, 0);
});

test('selecting a notification rule only changes the displayed controls', async () => {
	const app = await openHrSettings([
		{ id: 'first', hrGroups: ['HR'], userGroups: ['Staff'] },
		{ id: 'second', hrGroups: ['HR'], userGroups: ['Other'] }
	], null, 'en', {}, 'policy', false, 'notifications');
	await app.selectRule('second');
	assert.equal(app.card().dataset.ruleId, 'second');
	assert.equal(app.requests.length, 0);
	const threshold = app.balanceInput('overtimeThresholdMinutes');
	threshold.value = '12.5';
	await app.change(threshold);
	assert.equal(app.requests[0].policySection, 'notifications');
	assert.equal(app.requests[0].rules[1].employeeBalanceNotifications.overtimeThresholdMinutes, 750);
	assert.equal(app.requests[0].rules[0].employeeBalanceNotifications.overtimeThresholdMinutes, 600);
	assert.equal(app.elements.get('timesheet-notification-rule').value, 'second');
	assert.equal(app.card().dataset.ruleId, 'second');
});

test('switching groups during an in-flight save preserves both edits and the chosen group', async () => {
	let resolveFirst;
	const app = await openHrSettings([
		{ id: 'first', hrGroups: ['HR'], userGroups: ['Staff'] },
		{ id: 'second', hrGroups: ['HR'], userGroups: ['Other'] }
	], (request, number) => number === 1
		? new Promise(resolve => { resolveFirst = () => resolve(saveResponse(request)); })
		: saveResponse(request), 'en', {}, 'policy', false, 'notifications');
	const firstThreshold = app.balanceInput('overtimeThresholdMinutes');
	firstThreshold.value = '12';
	const firstSave = app.change(firstThreshold);
	await new Promise(setImmediate);
	await app.selectRule('second');
	const secondThreshold = app.balanceInput('negativeOvertimeThresholdMinutes');
	secondThreshold.value = '8';
	const secondSave = app.change(secondThreshold);
	resolveFirst();
	await Promise.all([firstSave, secondSave]);
	assert.equal(app.requests.length, 2);
	assert.equal(app.requests[1].rulesRevision, app.requests[0].nextRevision);
	assert.equal(app.requests[1].rules[0].employeeBalanceNotifications.overtimeThresholdMinutes, 720);
	assert.equal(app.requests[1].rules[1].employeeBalanceNotifications.negativeOvertimeThresholdMinutes, 480);
	assert.equal(app.card().dataset.ruleId, 'second');
	assert.equal(app.balanceInput('negativeOvertimeThresholdMinutes').value, '8');
});

test('notification rule selection returns to the first rule after a fresh page load', async () => {
	const rules = [
		{ id: 'first', hrGroups: ['HR'], userGroups: ['Staff'] },
		{ id: 'second', hrGroups: ['HR'], userGroups: ['Other'] }
	];
	const first = await openHrSettings(rules, null, 'en', {}, 'policy', false, 'notifications');
	await first.selectRule('second');
	const reopened = await openHrSettings(rules, null, 'en', {}, 'policy', false, 'notifications');
	assert.equal(reopened.card().dataset.ruleId, 'first');
	assert.equal(reopened.requests.length, 0);
});

test('an empty notification rule list disables the selector without creating a rule', async () => {
	const app = await openHrSettings([], null, 'en', {}, 'policy', false, 'notifications');
	assert.equal(app.elements.get('timesheet-notification-rule').disabled, true);
	assert.match(app.elements.get('timesheet-hr-rules').textContent, /No rules have been configured/);
	assert.equal(app.requests.length, 0);
});

test('mail help remains usable when the rule data failed to load', async () => {
	const app = await openHrSettings([], null, 'en', { rootUnavailable: true }, 'policy', true, 'notifications');
	await app.click(app.elements.get('timesheet-rules-help-toggle'));
	assert.equal(app.elements.get('timesheet-rules-help').hidden, false);
	assert.equal(app.requests.length, 0);
});

test('a conflict retains a still-present rule selection and falls back when that rule was removed', async () => {
	const initialRules = [
		{ id: 'first', hrGroups: ['HR'], userGroups: ['Staff'] },
		{ id: 'second', hrGroups: ['HR'], userGroups: ['Other'] }
	];
	for (const keepSelected of [true, false]) {
		const currentRules = keepSelected ? initialRules : [initialRules[0]];
		const app = await openHrSettings(initialRules, () => ({
			ok: false, status: 409,
			text: async () => JSON.stringify({ code: 'rules_conflict', rules: currentRules, rulesRevision: rulesRevision(50) })
		}), 'en', {}, 'policy', false, 'notifications');
		await app.selectRule('second');
		const threshold = app.balanceInput('overtimeThresholdMinutes');
		threshold.value = '18';
		await app.change(threshold);
		assert.equal(app.card().dataset.ruleId, keepSelected ? 'second' : 'first');
		assert.equal(app.elements.get('timesheet-notification-rule').value, keepSelected ? 'second' : 'first');
		assert.equal(app.elements.get('timesheet-notification-rule').children.length, currentRules.length);
		assert.equal(app.balanceInput('overtimeThresholdMinutes').value, '10');
		assert.equal(app.requests.length, 1);
	}
});

