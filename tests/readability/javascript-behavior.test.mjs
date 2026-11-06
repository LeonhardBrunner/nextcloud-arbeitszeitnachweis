import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const candidateRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
// Set this only when an unchanged checkout is available for an additional comparison.
const baselineRoot = process.env.TIMESHEET_BASELINE_ROOT
	? resolve(process.env.TIMESHEET_BASELINE_ROOT)
	: null;
const copy = (value) => JSON.parse(JSON.stringify(value));

function createEnvironment(root) {
	const messages = [];
	const requests = [];
	const timers = [];
	const controls = {
		'.config-daily-min': { value: '08:00' },
		'.config-state': { value: 'BY' },
		'#overtime-total': { textContent: '' },
		'#worked-hours-month': { textContent: '' },
		'#overtime-month': { textContent: '' },
	};
	const selectedUser = { textContent: 'bob' };
	const container = { querySelector: (selector) => controls[selector] || null };
	const document = {
		documentElement: { getAttribute: () => 'de-DE' },
		querySelector: (selector) => selector === '#hr-user-title span'
			? selectedUser
			: selector.endsWith(' .config-state') ? controls['.config-state'] : controls[selector] || null,
		getElementById: (id) => ['tab-hr', 'hr-user-entries'].includes(id)
			? container
			: id === 'config-daily-min-mine' ? controls['.config-daily-min'] : null,
		querySelectorAll: () => [],
	};
	const context = vm.createContext({
		document,
		navigator: { language: 'de-DE' },
		console: Object.fromEntries(['log', 'warn', 'error'].map((name) => [name, () => {}])),
		setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length; },
		clearTimeout: () => {},
		t: (app, message) => `${app}:${message}`,
		OC: {
			requestToken: 'fixture-token',
			getCurrentUser: () => ({ uid: 'alice' }),
			getLocale: () => 'de-DE',
			generateUrl: (path) => path,
			Notification: { showTemporary: (message) => messages.push(message) },
		},
		fetch: async (url, options) => {
			requests.push({ url, options: copy(options) });
			return { ok: true, status: 200, json: async () => ({ ok: true }) };
		},
	});
	context.window = context;
	function load(filename) {
		vm.runInContext(readFileSync(join(root, 'js', filename), 'utf8'), context, { filename });
	}
	load('timesheet-core.js');
	return { context, load, controls, document, container, messages, requests, timers };
}

function createRow(environment, overrides = {}, hr = false) {
	const fields = {
		'.startTime': { value: '08:00', type: 'time' },
		'.endTime': { value: '16:30', type: 'time' },
		'.breakMinutes': { value: '30', type: 'text' },
		'.commentInput': { value: 'Working' },
		'.ts-warn': { textContent: '' },
		'.ts-duration': { textContent: '--:--' },
		'.ts-diff': { textContent: '--:--' },
	};
	for (const [selector, value] of Object.entries(overrides)) {
		fields[selector].value = value;
	}
	const classes = new Set();
	const row = {
		dataset: {
			date: '2026-10-28', savedStart: '', savedEnd: '', savedBreak: '0', savedComment: '',
		},
		querySelector: (selector) => fields[selector] || null,
		classList: {
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
			contains: (name) => classes.has(name),
		},
		closest: (selector) => selector === 'tbody' ? tbody
			: selector === '#hr-user-entries' && hr ? environment.container : null,
	};
	const tbody = { dataset: { specialDays: '0' }, querySelectorAll: () => [row] };
	return { row, fields, classes };
}

function snapshotRow(environment, rowFixture, calls) {
	return copy({
		dataset: rowFixture.row.dataset,
		fields: rowFixture.fields,
		classes: Array.from(rowFixture.classes),
		controls: environment.controls,
		calls,
		messages: environment.messages,
		timerDelays: environment.timers.map(({ delay }) => delay),
	});
}

async function runForBoth(scenario) {
	const actual = await scenario(candidateRoot);
	if (baselineRoot) {
		const expected = await scenario(baselineRoot);
		assert.deepEqual(actual, expected);
	}
	return actual;
}

test('time parsing, formatting, overnight durations and rule boundaries match baseline', async () => {
	const result = await runForBoth(async (root) => {
		const { context } = createEnvironment(root);
		const utils = context.Timesheet.util;
		const results = {};
		for (const name of ['hmToMin', 'parseBreakMinutesInput']) {
			results[name] = [null, undefined, '', ' ', '-', '-00:30', '0', '30', '1:90', '12:30', ' 2 : 30 ', 'abc', '1:2:3']
				.map((value) => utils[name](value));
		}
		results.format = [null, 0, -30, 59, 60, 90, 1500].map((minutes) => [
			utils.minToHm(minutes), utils.formatBreakValue(minutes, 'minutes'), utils.formatBreakValue(minutes, 'hours'),
		]);
		results.work = [[480, 990, 30], [1320, 360, 45], [480, 480, 0], [null, 600, 0], [480, 600, -30]]
			.map((args) => utils.calcWorkMinutes(...args));
		results.config = [null, {}, { dailyMin: 420 }, { workMinutes: 300 }, { workMinutes: '07:30' }]
			.map((config) => utils.pickDailyMin(config));
		results.rules = [
			{ startMin: 480, endMin: 840, breakMinutes: 0 },
			{ startMin: 480, endMin: 841, breakMinutes: 0 },
			{ startMin: 480, endMin: 1050, breakMinutes: 30 },
			{ startMin: 480, endMin: 1140, breakMinutes: 45 },
			{ startMin: 1320, endMin: 360, breakMinutes: 30 },
			{ startMin: 480, endMin: null },
		].map((entry) => utils.checkRules(entry, '2026-10-25', { '2026-10-25': 'Holiday' }));
		results.zeroThresholds = utils.checkRules({ startMin: 0, endMin: 601, breakMinutes: 0 }, null, {}, {
			breakShortMinutes: 0, breakShortHours: 0, breakLongMinutes: 0, breakLongHours: 0, maxHours: 0,
		});
		return copy(results);
	});
	assert.deepEqual(result.hmToMin, [null, null, null, null, null, -30, null, null, 150, 750, 150, null, null]);
	assert.deepEqual(result.parseBreakMinutesInput, [0, 0, 0, 0, 0, -30, 0, 30, 150, 750, 150, null, null]);
	assert.deepEqual(result.work, [480, 435, 0, null, 150]);
	assert.deepEqual(result.config, [null, null, 420, 300, 450]);
	assert.deepEqual(result.format, [
		['--:--', '0', '00:00'], ['00:00', '0', '00:00'], ['-00:30', '-30', '-00:30'],
		['00:59', '59', '00:59'], ['01:00', '60', '01:00'], ['01:30', '90', '01:30'], ['25:00', '1500', '25:00'],
	]);
	const dayWarnings = 'timesheet:Sunday work not allowed, timesheet:Holiday work not allowed';
	assert.deepEqual(result.rules, [dayWarnings, `timesheet:Break too short, ${dayWarnings}`, dayWarnings, `timesheet:Above maximum time, ${dayWarnings}`, dayWarnings, '']);
	assert.equal(result.zeroThresholds, 'timesheet:Above maximum time, timesheet:Break too short');
});

test('request headers, session notification and blocked writes match baseline', async () => {
	const result = await runForBoth(async (root) => {
		const environment = createEnvironment(root);
		const { context } = environment;
		await context.Timesheet.api('/api/entries');
		await context.Timesheet.api('/api/entries', { method: 'POST', body: '{}', headers: { 'X-Fixture': 'value' } });
		context.OC.requestToken = null;
		const errors = [];
		for (let attempt = 0; attempt < 2; attempt += 1) {
			try { await context.Timesheet.api('/api/entries', { method: 'PUT' }); }
			catch (error) { errors.push(error.message); }
		}
		context.fetch = async () => ({ ok: false, status: 403, text: async () => 'Forbidden' });
		try { await context.Timesheet.api('/api/entries'); }
		catch (error) { errors.push(error.message); }
		return copy({ requests: environment.requests, messages: environment.messages, errors });
	});
	assert.equal(result.requests.length, 2);
	assert.equal(result.requests[0].url, '/apps/timesheet/api/entries');
	assert.deepEqual(result.requests[0].options.headers, { 'Content-Type': 'application/json', requesttoken: 'fixture-token' });
	assert.equal(result.requests[1].options.method, 'POST');
	assert.equal(result.requests[1].options.headers['X-Fixture'], 'value');
	assert.deepEqual(result.errors, ['Session expired', 'Session expired', 'Forbidden']);
	assert.deepEqual(result.messages, ['timesheet:Session expired. Please reload the page.']);
});

for (const [name, overrides, existingId, hr] of [
	['new complete personal row', {}, null, false],
	['existing complete HR row', {}, '42', true],
	['comment only', { '.startTime': '', '.endTime': '', '.commentInput': '  Note  ' }, null, false],
	['incomplete time', { '.endTime': '', '.commentInput': '' }, null, false],
	['invalid break with complete time', { '.breakMinutes': 'invalid' }, null, false],
	['invalid break with incomplete time', { '.breakMinutes': 'invalid', '.endTime': '' }, '42', false],
	['automatic deletion', { '.startTime': '', '.endTime': '', '.commentInput': '' }, '42', false],
	['empty unsaved row', { '.startTime': '', '.endTime': '', '.commentInput': '' }, null, false],
]) {
	test(`row saving matches baseline: ${name}`, async () => {
		const result = await runForBoth(async (root) => {
			const environment = createEnvironment(root);
			environment.load('timesheet-entries.js');
			const fixture = createRow(environment, overrides, hr);
			if (existingId) fixture.row.dataset.id = existingId;
			const calls = [];
			environment.context.Timesheet.api = async (path, options = {}) => {
				calls.push({ path, options });
				return path.includes('/summary') ? { overtimeMinutes: 90 } : { id: existingId || 101 };
			};
			await environment.context.Timesheet.entries.saveRowIfNeeded(fixture.row);
			await environment.context.Timesheet.entries.saveRowIfNeeded(fixture.row);
			return snapshotRow(environment, fixture, calls);
		});
		const writes = result.calls.filter(({ options }) => options.method);
		assert.deepEqual(writes.map(({ options }) => options.method), name === 'empty unsaved row' ? [] : [
			name === 'automatic deletion' ? 'DELETE' : existingId ? 'PUT' : 'POST',
		]);
		assert.equal(result.dataset.saving, undefined);
		assert.equal(result.dataset.saveQueued, undefined);
		if (name === 'empty unsaved row' || name === 'automatic deletion') {
			assert.equal(result.dataset.id, undefined);
			assert.equal(result.fields['.ts-duration'].textContent, '--:--');
		} else {
			const payload = JSON.parse(writes[0].options.body);
			assert.equal(payload.workDate, '2026-10-28');
			assert.equal(result.dataset.savedComment, name === 'comment only' ? 'Note' : overrides['.commentInput'] ?? 'Working');
			if (name === 'comment only') {
				assert.deepEqual(payload, { workDate: '2026-10-28', comment: 'Note', commentOnly: 1 });
				assert.equal(result.dataset.savedStart, '');
				assert.equal(result.dataset.savedBreak, '0');
			} else {
				assert.equal(payload.start, '08:00');
				assert.equal(payload.end, overrides['.endTime'] ?? '16:30');
				assert.equal(payload.breakMinutes, name.startsWith('invalid break') ? 0 : 30);
				assert.equal(result.fields['.ts-warn'].textContent, name.includes('incomplete') ? 'timesheet:Time incomplete' : name.startsWith('invalid break') ? 'timesheet:Break too short' : '');
			}
			assert.equal(result.calls.at(-1).path, `/api/overtime/summary?user=${hr ? 'bob' : 'alice'}`);
		}
	});
}

test('edits made during an in-flight save are queued and persisted in the same order', async () => {
	const result = await runForBoth(async (root) => {
		const environment = createEnvironment(root);
		environment.load('timesheet-entries.js');
		const fixture = createRow(environment);
		const calls = [];
		let finishFirstSave;
		let writes = 0;
		environment.context.Timesheet.api = async (path, options = {}) => {
			calls.push(copy({ path, options }));
			if (options.method && ++writes === 1) {
				await new Promise((resolveSave) => { finishFirstSave = resolveSave; });
			}
			return path.includes('/summary') ? { overtimeMinutes: 90 } : { id: 101 };
		};
		const saving = environment.context.Timesheet.entries.saveRowIfNeeded(fixture.row);
		fixture.fields['.commentInput'].value = 'Edited during request';
		await environment.context.Timesheet.entries.saveRowIfNeeded(fixture.row);
		finishFirstSave();
		await saving;
		return snapshotRow(environment, fixture, calls);
	});
	assert.deepEqual(result.calls.filter(({ options }) => options.method).map(({ options }) => options.method), ['POST', 'PUT']);
	assert.equal(result.dataset.savedComment, 'Edited during request');
	assert.equal(result.dataset.saving, undefined);
	assert.equal(result.dataset.saveQueued, undefined);
});

test('rule and holiday caches match baseline', async () => {
	const result = await runForBoth(async (root) => {
		const environment = createEnvironment(root);
		environment.load('timesheet-entries.js');
		const calls = [];
		environment.context.Timesheet.api = async (path) => {
			calls.push(path);
			if (path.includes('/rules/')) return { maxHours: 8 };
			if (path.includes('/holidays')) return { '2026-10-03': 'Holiday' };
			return null;
		};
		const entries = environment.context.Timesheet.entries;
		await entries.loadRuleThresholds('bob');
		await entries.loadRuleThresholds('bob');
		await entries.getHolidays(2026, 'BY');
		await entries.getHolidays(2026, 'BY');
		await entries.getHolidays(2026, '');
		return copy({ calls, thresholds: entries.getRuleThresholds('bob'), holidayCache: Array.from(environment.context.Timesheet.state.holidayCache) });
	});
	assert.deepEqual(result.calls, ['/api/rules/effective/bob', '/api/holidays?year=2026&state=BY']);
	assert.deepEqual(result.thresholds, { maxHours: 8 });
	assert.deepEqual(result.holidayCache, [['BY_2026', { '2026-10-03': 'Holiday' }]]);
});

test('administrator assignment editor retains rule creation, group constraints and request ordering', async () => {
	// The role split intentionally differs from the original combined settings page.
	const result = await (async (root) => {
		// The unchanged reference checkout retains its original asset and root names.
		const settingsScript = root === candidateRoot ? 'hr-settings.js' : 'admin.js';
		const settingsRootId = root === candidateRoot ? 'timesheet-hr-settings' : 'timesheet-admin-settings';
		const events = new Map();
		const requests = [];
		const messages = [];
		const elements = new Map();
		const initialRules = [
			{ id: 'one', hrGroups: ['HR'], userGroups: ['Staff', 'Staff'], priority: 2, maxHours: 9 },
			{ id: 'two', hrGroups: ['HR'], userGroups: ['Staff', 'Other'], priority: 1 },
		];
		function element(id) {
			const result = {
				id, dataset: {}, children: [], textContent: '', checked: false,
				addEventListener: (name, callback) => {
					const key = `${id}:${name}`;
					events.set(key, [...(events.get(key) || []), callback]);
				},
				appendChild(child) { this.children.push(child); },
				set innerHTML(value) { this.html = value; this.children = []; },
				get innerHTML() { return this.html || ''; },
			};
			elements.set(id, result);
			return result;
		}
		for (const id of [settingsRootId, 'timesheet-hr-rules', 'timesheet-add-hr-rule', 'timesheet-specialdays-overtime']) element(id);
		elements.get(settingsRootId).dataset = { settingsScope: 'assignments', rulesRevision: '0'.repeat(64) };
		element('timesheet-all-groups-data').textContent = JSON.stringify(['HR', 'Staff', 'Other', 'A&B']);
		element('timesheet-hr-rules-data').textContent = JSON.stringify(initialRules);
		const context = vm.createContext({
			document: {
				getElementById: (id) => elements.get(id),
				createElement: (tag) => element(`generated-${elements.size}-${tag}`),
			},
			FormData,
			crypto: { randomUUID: () => 'fixture-rule-id' },
			t: (app, message) => `${app}:${message}`,
			console: Object.fromEntries(['log', 'warn', 'error'].map((name) => [name, () => {}])),
			OC: { requestToken: 'fixture-token', generateUrl: (url) => url, Notification: { showTemporary: (message) => messages.push(message) } },
			fetch: async (url, options) => {
				const body = options.body ? Object.fromEntries(options.body.entries()) : null;
				requests.push({ url, method: options.method, headers: options.headers, body });
				return { ok: true, status: 200, json: async () => body?.rules ? { rules: JSON.parse(body.rules), rulesRevision: String(requests.length).padStart(64, '0') } : { check: true } };
			},
		});
		context.window = context;
		vm.runInContext(readFileSync(join(root, 'js', settingsScript), 'utf8'), context, { filename: settingsScript });
		await new Promise(setImmediate);
		// Dispatch every registered listener, as the browser does; registration order is not an API.
		const dispatch = async (key, event = {}) => Promise.all((events.get(key) || []).map(callback => callback(event)));
		const snapshots = [];
		const snapshot = () => snapshots.push(elements.get('timesheet-hr-rules').children.map(({ className, dataset, innerHTML, textContent }) => ({ className, dataset, innerHTML, textContent })));
		snapshot();
		await dispatch('timesheet-add-hr-rule:click');
		snapshot();
		const card = { dataset: { ruleId: 'fixture-rule-id' } };
		const groupSelect = { dataset: { kind: 'userGroups' }, value: 'Staff' };
		const target = { closest: (selector) => selector === '.ts-rule' ? card : selector === '.ts-rule-select' ? groupSelect : null };
		await dispatch('timesheet-hr-rules:change', { target });
		groupSelect.value = 'A&B';
		await dispatch('timesheet-hr-rules:change', { target });
		snapshot();
		const settingInput = { dataset: { key: 'maxHours' }, value: '999' };
		await dispatch('timesheet-hr-rules:change', { target: { closest: (selector) => selector === '.ts-rule' ? card : selector === '.ts-rule-input' ? settingInput : null } });
		snapshot();
		const removeLink = { dataset: { kind: 'userGroups', group: 'A&B' } };
		await dispatch('timesheet-hr-rules:click', { preventDefault() {}, target: { closest: (selector) => selector === '.ts-rule' ? card : selector === '.ts-chip-remove' ? removeLink : null } });
		snapshot();
		await dispatch('timesheet-hr-rules:click', { preventDefault() {}, target: { closest: (selector) => selector === '.ts-rule' ? card : selector === '.ts-rule-delete' ? {} : null } });
		snapshot();
		await dispatch('timesheet-specialdays-overtime:change', { target: { checked: false } });
		return copy({ snapshots, requests, messages, settingValue: settingInput.value, checked: elements.get('timesheet-specialdays-overtime').checked });
	})(candidateRoot);
	assert.deepEqual(result.snapshots.map((cards) => cards.length), [2, 3, 3, 3, 3, 2]);
	assert.equal(result.requests[0].method, 'POST');
	assert.equal(result.requests.length, 4);
	const savedRules = result.requests.filter(({ body }) => body?.rules).map(({ body }) => JSON.parse(body.rules));
	assert.equal(savedRules[0][2].priority, 3);
	assert.deepEqual(savedRules[0][1].userGroups, ['Other']);
	assert.deepEqual(savedRules[1][2].userGroups, ['A&B']);
	assert.equal(savedRules[2][2].maxHours, 10, 'Assignment controls do not edit time policy');
	assert.deepEqual(savedRules[2][2].userGroups, []);
	assert.equal(savedRules[3].length, 2);
	assert.ok(result.requests.every(request => request.url.endsWith('/settings/hr_access_rules')));
	assert.equal(result.settingValue, '999');
	assert.equal(result.checked, false);
	assert.ok(result.snapshots[2][2].innerHTML.includes('data-group="A&amp;B"'));
	assert.deepEqual(result.messages, [
		'timesheet:Some Employee groups were assigned to multiple rules. Duplicates were removed.',
		'timesheet:Group already assigned to another rule.',
	]);
});
