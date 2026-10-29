import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const coreSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-core.js'), 'utf8');
const mainSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-main.js'), 'utf8');
const flush = () => new Promise(setImmediate);
const initialRevision = '0'.repeat(64);

function deferred() {
	let resolvePromise;
	let rejectPromise;
	const promise = new Promise((resolve, reject) => {
		resolvePromise = resolve;
		rejectPromise = reject;
	});
	return { promise, resolve: resolvePromise, reject: rejectPromise };
}

function createSessionStorage() {
	const values = new Map();
	return {
		getItem: key => values.get(key) ?? null,
		setItem: (key, value) => values.set(key, String(value)),
		removeItem: key => values.delete(key),
		values,
	};
}

function createPreferenceServer(initialMode = 'minutes') {
	let mode = initialMode === 'hours' ? 'hours' : 'minutes';
	let revisionNumber = 0;
	const snapshot = () => ({ breakInputMode: mode, revision: revisionNumber.toString(16).padStart(64, '0') });
	return {
		snapshot,
		save(request) {
			if (request.body.revision !== snapshot().revision) {
				throw new Error(JSON.stringify({ code: 'preference_conflict', ...snapshot() }));
			}
			mode = request.body.breakInputMode;
			revisionNumber++;
			return snapshot();
		},
	};
}

// Use the real startup and event handler with only the DOM and network replaced.
async function openTimesheet({ savedMode, viewer = 'alice', readyState = 'complete', respond = null, storage = createSessionStorage(), preferenceServer = createPreferenceServer(savedMode), scheduleTimeout = setTimeout } = {}) {
	const events = new Map();
	const windowEvents = new Map();
	const requests = [];
	const messages = [];
	const entryLoads = [];
	let entryWrites = 0;

	class Element {
		constructor() {
			this.dataset = {};
			this.attributes = new Map();
			this.checked = false;
			this.value = '';
			this.type = 'text';
			const classes = new Set();
			this.classList = {
				contains: name => classes.has(name),
				toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
			};
		}
		addEventListener() {
		}
		setAttribute(name, value) { this.attributes.set(name, value); }
		removeAttribute(name) { this.attributes.delete(name); }
		closest(selector) { return selector === '.ts-break-toggle-switch' && this.isBreakSwitch ? this : null; }
	}

	const root = new Element();
	if (savedMode !== undefined) root.dataset.breakInputMode = savedMode;
	root.dataset.breakInputRevision = preferenceServer.snapshot().revision;
	const breakInputs = ['45', '30'].map(value => Object.assign(new Element(), { value }));
	const toggles = ['mine', 'hr'].map(view => {
		const input = new Element();
		input.isBreakSwitch = true;
		input.dataset = { breakModeOff: 'minutes', breakModeOn: 'hours' };
		const labels = ['minutes', 'hours'].map(mode => {
			const label = new Element();
			label.dataset.breakModeLabel = mode;
			return label;
		});
		return {
			view, input, labels,
			querySelector: selector => selector === '.ts-break-toggle-switch' ? input : null,
			querySelectorAll: selector => selector === '.ts-break-mode-option' ? labels : [],
		};
	});
	const selectedEmployee = { querySelector: () => ({ textContent: 'another-employee' }) };
	const document = {
		readyState,
		documentElement: { getAttribute: () => 'de-DE' },
		getElementById: id => id === 'timesheet-app' ? root : id === 'hr-user-title' ? selectedEmployee : null,
		querySelector: () => null,
		querySelectorAll: selector => selector === 'input.breakMinutes' ? breakInputs : selector === '.ts-break-toggle' ? toggles : [],
		addEventListener(name, callback) { events.set(name, [...(events.get(name) || []), callback]); },
	};
	const context = vm.createContext({
		document, Element, HTMLElement: Element,
		sessionStorage: storage,
		setTimeout: scheduleTimeout,
		addEventListener(name, callback) { windowEvents.set(name, [...(windowEvents.get(name) || []), callback]); },
		navigator: { language: 'de-DE' },
		t: (_app, message) => message,
		console: Object.fromEntries(['log', 'warn', 'error'].map(name => [name, () => {}])),
		OC: {
			requestToken: 'test-token', getCurrentUser: () => ({ uid: viewer }), getLocale: () => 'de-DE',
			Notification: { showTemporary: message => messages.push(message) },
		},
	});
	context.window = context;
	vm.runInContext(coreSource, context, { filename: 'timesheet-core.js' });
	const timesheet = context.Timesheet;
	timesheet.copy = { init() {} };
	timesheet.hr = { init() {}, loadHrUserList() {} };
	timesheet.config = { init() {}, loadUserConfig: async () => {} };
	timesheet.export = { handleExportClick() {} };
	timesheet.entries = {
		loadRuleThresholds: async () => {},
		loadUserEntries: async () => { entryLoads.push(timesheet.state.breakInputMode); },
		refreshOvertimeTotal: async () => {},
		updateWorkedHours() {},
		saveRowIfNeeded: async () => { entryWrites++; },
	};
	timesheet.api = async (path, options) => {
		const request = { path, ...options, body: JSON.parse(options.body) };
		requests.push(request);
		if (respond) await respond(request, requests.length);
		return preferenceServer.save(request);
	};
	vm.runInContext(mainSource, context, { filename: 'timesheet-main.js' });
	await flush();

	return {
		state: timesheet.state, toggles, breakInputs, requests, messages, entryLoads,
		storage, preferenceServer,
		storedMode: () => preferenceServer.snapshot().breakInputMode,
		entryWrites: () => entryWrites,
		navigateAway() {
			(windowEvents.get('pagehide') || []).forEach(callback => callback({ persisted: false }));
		},
		async start() {
			await Promise.all((events.get('DOMContentLoaded') || []).map(callback => callback()));
		},
		change(mode, view = 'mine') {
			const input = toggles.find(toggle => toggle.view === view).input;
			input.checked = mode === 'hours';
			return Promise.all((events.get('change') || []).map(callback => callback({ target: input })));
		},
	};
}

test('saved hours are applied to both switches before entries load without another request', async () => {
	const app = await openTimesheet({ savedMode: 'hours' });
	assert.deepEqual(app.entryLoads, ['hours']);
	assert.equal(app.state.breakInputMode, 'hours');
	assert.ok(app.toggles.every(toggle => toggle.input.checked));
	assert.ok(app.toggles.every(toggle => toggle.input.attributes.get('aria-checked') === 'true'));
	assert.deepEqual(app.breakInputs.map(input => [input.type, input.value]), [['time', '00:45'], ['time', '00:30']]);
	assert.equal(app.requests.length, 0);
});

test('startup reads the server preference when the DOM becomes ready', async () => {
	const app = await openTimesheet({ savedMode: 'hours', readyState: 'loading' });
	assert.deepEqual(app.entryLoads, []);
	await app.start();
	assert.deepEqual(app.entryLoads, ['hours']);
	assert.equal(app.state.breakInputMode, 'hours');
});

for (const savedMode of [undefined, 'minutes', 'invalid']) {
	test(`missing or unsupported preference uses minutes (${savedMode})`, async () => {
		const app = await openTimesheet({ savedMode });
		assert.equal(app.state.breakInputMode, 'minutes');
		assert.ok(app.toggles.every(toggle => !toggle.input.checked));
		assert.deepEqual(app.entryLoads, ['minutes']);
		assert.equal(app.requests.length, 0);
	});
}

test('switching saves only the personal preference and preserves numeric break values', async () => {
	const app = await openTimesheet();
	await app.change('hours');
	assert.deepEqual(app.requests, [{
		path: '/api/preferences/break-input-mode', method: 'PUT', keepalive: true, body: { breakInputMode: 'hours', revision: initialRevision },
	}]);
	assert.deepEqual(app.breakInputs.map(input => input.value), ['00:45', '00:30']);
	assert.equal(app.entryWrites(), 0);
	await app.change('minutes');
	assert.deepEqual(app.breakInputs.map(input => input.value), ['45', '30']);
	assert.equal(app.requests[1].body.revision, '1'.padStart(64, '0'));
	assert.ok(app.toggles.every(toggle => !toggle.input.checked));
	assert.equal(app.entryWrites(), 0);
});

test('a new page restores the value returned by account storage rather than browser memory', async () => {
	const app = await openTimesheet();
	await app.change('hours');
	const reloaded = await openTimesheet({ savedMode: app.storedMode() });
	assert.deepEqual(reloaded.entryLoads, ['hours']);
	const otherAccount = await openTimesheet({ viewer: 'bob', savedMode: 'minutes' });
	assert.deepEqual(otherAccount.entryLoads, ['minutes']);
	assert.equal(reloaded.state.currentUserId, 'alice');
	assert.equal(otherAccount.state.currentUserId, 'bob');
});

test('the HR switch changes the viewer preference and synchronizes the personal switch', async () => {
	const app = await openTimesheet({ viewer: 'hr-user' });
	await app.change('hours', 'hr');
	assert.ok(app.toggles.every(toggle => toggle.input.checked));
	assert.deepEqual(app.requests[0].body, { breakInputMode: 'hours', revision: initialRevision });
	assert.ok(!JSON.stringify(app.requests).includes('another-employee'));
	assert.equal(app.entryWrites(), 0);
});

test('quick choices are saved in order and an older success does not replace the latest selection', async () => {
	const firstResponse = deferred();
	const secondResponse = deferred();
	const app = await openTimesheet({ respond: (_request, index) => index === 1 ? firstResponse.promise : secondResponse.promise });
	const firstChange = app.change('hours');
	await flush();
	const secondChange = app.change('minutes');
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.equal(app.requests.length, 1);
	firstResponse.resolve({ breakInputMode: 'hours' });
	await firstChange;
	await flush();
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.equal(app.requests.length, 2);
	secondResponse.resolve({ breakInputMode: 'minutes' });
	await secondChange;
	assert.deepEqual(app.requests.map(request => request.body.breakInputMode), ['hours', 'minutes']);
	assert.equal(app.storedMode(), 'minutes');
});

test('a latest failure restores the last saved preference and a later retry still succeeds', async () => {
	const app = await openTimesheet({ savedMode: 'hours', respond: (request, index) => {
		if (index === 1) throw new Error('Synthetic persistence failure');
		return request.body;
	} });
	await app.change('minutes');
	assert.equal(app.state.breakInputMode, 'hours');
	assert.ok(app.toggles.every(toggle => toggle.input.checked));
	assert.equal(app.storedMode(), 'hours');
	assert.deepEqual(app.messages, ['Could not save the break display setting. Please try again.']);
	await app.change('minutes');
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.equal(app.storedMode(), 'minutes');
});

test('an old failure cannot reset newer pending choices', async () => {
	const firstResponse = deferred();
	const app = await openTimesheet({ respond: (request, index) => index === 1 ? firstResponse.promise : request.body });
	const firstChange = app.change('hours');
	await flush();
	const secondChange = app.change('minutes');
	const thirdChange = app.change('hours');
	firstResponse.reject(new Error('Synthetic persistence failure'));
	await firstChange;
	assert.equal(app.state.breakInputMode, 'hours');
	await Promise.all([secondChange, thirdChange]);
	assert.equal(app.storedMode(), 'hours');
	assert.deepEqual(app.requests.map(request => request.body.breakInputMode), ['hours', 'minutes', 'hours']);
	assert.equal(app.entryWrites(), 0);
});

test('a failed latest choice returns to the immediately preceding successful choice', async () => {
	const app = await openTimesheet({ respond: (request, index) => {
		if (index === 2) throw new Error('Synthetic persistence failure');
		return request.body;
	} });
	await app.change('hours');
	await app.change('minutes');
	assert.equal(app.state.breakInputMode, 'hours');
	assert.equal(app.storedMode(), 'hours');
	assert.ok(app.toggles.every(toggle => toggle.input.checked));
});

test('an immediate reload recovers and retries the pending choice before entries load', async () => {
	const storage = createSessionStorage();
	const firstResponse = deferred();
	const retryResponse = deferred();
	const app = await openTimesheet({ savedMode: 'minutes', storage, respond: () => firstResponse.promise });
	const firstChange = app.change('hours');
	const storageKey = 'timesheet:pending-break-input-mode:alice';
	assert.equal(JSON.parse(storage.getItem(storageKey)).mode, 'hours');
	const reloaded = await openTimesheet({ savedMode: 'minutes', storage, respond: () => retryResponse.promise });
	assert.deepEqual(reloaded.entryLoads, ['hours']);
	assert.ok(reloaded.toggles.every(toggle => toggle.input.checked));
	assert.deepEqual(reloaded.requests.map(request => request.body), [{ breakInputMode: 'hours', revision: initialRevision }]);
	assert.equal(reloaded.requests[0].keepalive, true);
	assert.equal(reloaded.entryWrites(), 0);
	firstResponse.resolve({ breakInputMode: 'hours' });
	await firstChange;
	assert.equal(JSON.parse(storage.getItem(storageKey)).mode, 'hours');
	retryResponse.resolve({ breakInputMode: 'hours' });
	await flush();
	assert.equal(storage.getItem(storageKey), null);
	const acknowledgedReload = await openTimesheet({ savedMode: 'minutes', storage });
	assert.deepEqual(acknowledgedReload.entryLoads, ['minutes']);
	assert.equal(acknowledgedReload.requests.length, 0);
});

test('a pending choice is isolated to its account and stays until the latest save succeeds', async () => {
	const storage = createSessionStorage();
	const firstResponse = deferred();
	const secondResponse = deferred();
	const app = await openTimesheet({ viewer: 'alice@example.com', storage, respond: (_request, index) => index === 1 ? firstResponse.promise : secondResponse.promise });
	const firstChange = app.change('hours');
	const storageKey = 'timesheet:pending-break-input-mode:alice%40example.com';
	assert.equal(JSON.parse(storage.getItem(storageKey)).mode, 'hours');
	const otherAccount = await openTimesheet({ viewer: 'bob', savedMode: 'minutes', storage });
	assert.deepEqual(otherAccount.entryLoads, ['minutes']);
	assert.equal(otherAccount.requests.length, 0);
	assert.equal(JSON.parse(storage.getItem(storageKey)).mode, 'hours');
	const secondChange = app.change('minutes');
	assert.equal(JSON.parse(storage.getItem(storageKey)).mode, 'minutes');
	firstResponse.resolve({ breakInputMode: 'hours' });
	await firstChange;
	assert.equal(JSON.parse(storage.getItem(storageKey)).mode, 'minutes');
	secondResponse.resolve({ breakInputMode: 'minutes' });
	await secondChange;
	assert.equal(storage.getItem(storageKey), null);
});

test('a failed recovered choice rolls back and removes its temporary record', async () => {
	const storage = createSessionStorage();
	const storageKey = 'timesheet:pending-break-input-mode:alice';
	storage.setItem(storageKey, JSON.stringify({ mode: 'hours', changeId: 'previous-page' }));
	const app = await openTimesheet({ savedMode: 'minutes', storage, respond: () => { throw new Error('Synthetic retry failure'); } });
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.ok(app.toggles.every(toggle => !toggle.input.checked));
	assert.equal(storage.getItem(storageKey), null);
	assert.deepEqual(app.messages, ['Could not save the break display setting. Please try again.']);
	assert.equal(app.entryWrites(), 0);
});

test('unavailable or quota-limited browser storage does not prevent server persistence', async () => {
	for (const storage of [null, {
		getItem() { throw new Error('Synthetic restricted storage'); },
		setItem() { throw new Error('Synthetic storage quota'); },
		removeItem() { throw new Error('Synthetic restricted storage'); },
	}]) {
		const app = await openTimesheet({ savedMode: 'minutes', storage });
		await app.change('hours');
		assert.equal(app.state.breakInputMode, 'hours');
		assert.equal(app.storedMode(), 'hours');
		assert.equal(app.requests.length, 1);
		assert.deepEqual(app.messages, []);
		assert.equal(app.entryWrites(), 0);
	}
});

test('a latest choice retries a valid revision conflict without replacing its visible mode', async () => {
	const preferenceServer = createPreferenceServer('minutes');
	const app = await openTimesheet({ savedMode: 'minutes', preferenceServer });
	preferenceServer.save({ body: { breakInputMode: 'minutes', revision: initialRevision } });
	const change = app.change('hours');
	assert.equal(app.state.breakInputMode, 'hours');
	await change;
	assert.deepEqual(app.requests.map(request => request.body.revision), [initialRevision, '1'.padStart(64, '0')]);
	assert.equal(app.storedMode(), 'hours');
	assert.equal(app.state.breakInputMode, 'hours');
	assert.deepEqual(app.messages, []);
	assert.equal(app.storage.values.size, 0);
});

test('an obsolete conflicting choice is skipped and the next choice uses the current server revision', async () => {
	const firstResponse = deferred();
	const preferenceServer = createPreferenceServer('minutes');
	const app = await openTimesheet({ preferenceServer, respond: (_request, index) => index === 1 ? firstResponse.promise : null });
	const firstChange = app.change('hours');
	await flush();
	const secondChange = app.change('minutes');
	preferenceServer.save({ body: { breakInputMode: 'hours', revision: initialRevision } });
	firstResponse.resolve();
	await Promise.all([firstChange, secondChange]);
	assert.deepEqual(app.requests.map(request => request.body), [
		{ breakInputMode: 'hours', revision: initialRevision },
		{ breakInputMode: 'minutes', revision: '1'.padStart(64, '0') },
	]);
	assert.equal(app.storedMode(), 'minutes');
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.deepEqual(app.messages, []);
});

test('revision protection prevents an old keepalive request overwriting a recovered choice after navigation', async () => {
	const preferenceServer = createPreferenceServer('minutes');
	const storage = createSessionStorage();
	const oldResponse = deferred();
	const oldPage = await openTimesheet({ savedMode: 'minutes', preferenceServer, storage, respond: () => oldResponse.promise });
	const oldHoursChange = oldPage.change('hours');
	await flush();
	const oldMinutesChange = oldPage.change('minutes');
	oldPage.navigateAway();
	const newPage = await openTimesheet({ savedMode: 'minutes', preferenceServer, storage });
	assert.deepEqual(newPage.entryLoads, ['minutes']);
	assert.equal(newPage.requests.length, 1);
	assert.equal(preferenceServer.snapshot().breakInputMode, 'minutes');
	assert.equal(preferenceServer.snapshot().revision, '1'.padStart(64, '0'));
	oldResponse.resolve();
	await Promise.all([oldHoursChange, oldMinutesChange]);
	assert.equal(oldPage.requests.length, 1);
	assert.equal(preferenceServer.snapshot().breakInputMode, 'minutes');
	assert.equal(preferenceServer.snapshot().revision, '1'.padStart(64, '0'));
	assert.equal(newPage.state.breakInputMode, 'minutes');
	assert.deepEqual(oldPage.messages, []);
	assert.equal(storage.values.size, 0);
	assert.equal(oldPage.entryWrites() + newPage.entryWrites(), 0);
});

test('repeated valid conflicts stop after three retries and restore the latest server value', async () => {
	const preferenceServer = createPreferenceServer('minutes');
	const retryDelays = [];
	const app = await openTimesheet({ preferenceServer, scheduleTimeout: (callback, delay) => {
		retryDelays.push(delay);
		return setTimeout(callback, 0);
	}, respond: () => {
		preferenceServer.save({ body: { breakInputMode: 'minutes', revision: preferenceServer.snapshot().revision } });
	} });
	await app.change('hours');
	assert.equal(app.requests.length, 4);
	assert.deepEqual(retryDelays, [25, 50, 100]);
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.equal(app.storage.values.size, 0);
	assert.deepEqual(app.messages, ['Could not save the break display setting. Please try again.']);
});

test('ordinary errors and malformed conflict payloads never trigger conflict retries', async () => {
	for (const message of [
		'Synthetic ordinary failure',
		JSON.stringify({ code: 'another_error', breakInputMode: 'minutes', revision: initialRevision }),
		JSON.stringify({ code: 'preference_conflict', breakInputMode: 'invalid', revision: initialRevision }),
		JSON.stringify({ code: 'preference_conflict', breakInputMode: 'minutes', revision: 'invalid' }),
	]) {
		const app = await openTimesheet({ respond: () => { throw new Error(message); } });
		await app.change('hours');
		assert.equal(app.requests.length, 1);
		assert.equal(app.state.breakInputMode, 'minutes');
		assert.equal(app.storage.values.size, 0);
		assert.deepEqual(app.messages, ['Could not save the break display setting. Please try again.']);
	}
});

test('a temporarily busy preference waits before retrying and succeeds after the lock is released', async () => {
	const preferenceServer = createPreferenceServer('minutes');
	const retryTimers = [];
	let lockBusy = true;
	const app = await openTimesheet({ preferenceServer,
		scheduleTimeout: (callback, delay) => retryTimers.push({ callback, delay }),
		respond: () => {
			if (lockBusy) throw new Error(JSON.stringify({ code: 'preference_conflict', ...preferenceServer.snapshot() }));
		},
	});
	const change = app.change('hours');
	await flush();
	assert.equal(app.requests.length, 1);
	assert.deepEqual(retryTimers.map(timer => timer.delay), [25]);
	assert.equal(app.state.breakInputMode, 'hours');
	lockBusy = false;
	retryTimers[0].callback();
	await change;
	assert.equal(app.requests.length, 2);
	assert.equal(app.storedMode(), 'hours');
	assert.deepEqual(app.messages, []);
	assert.equal(app.storage.values.size, 0);
});

test('navigation during conflict backoff prevents another request and preserves the pending bridge', async () => {
	const preferenceServer = createPreferenceServer('minutes');
	const retryTimers = [];
	const app = await openTimesheet({ preferenceServer,
		scheduleTimeout: (callback, delay) => retryTimers.push({ callback, delay }),
		respond: () => { throw new Error(JSON.stringify({ code: 'preference_conflict', ...preferenceServer.snapshot() })); },
	});
	const change = app.change('hours');
	await flush();
	assert.equal(retryTimers.length, 1);
	app.navigateAway();
	retryTimers[0].callback();
	await change;
	assert.equal(app.requests.length, 1);
	assert.equal(app.storedMode(), 'minutes');
	assert.equal(JSON.parse(app.storage.getItem('timesheet:pending-break-input-mode:alice')).mode, 'hours');
	assert.deepEqual(app.messages, []);
	assert.equal(app.entryWrites(), 0);
});

test('a newer choice during conflict backoff skips the obsolete retry', async () => {
	const preferenceServer = createPreferenceServer('minutes');
	const retryTimers = [];
	const app = await openTimesheet({ preferenceServer,
		scheduleTimeout: (callback, delay) => retryTimers.push({ callback, delay }),
		respond: (_request, index) => {
			if (index === 1) throw new Error(JSON.stringify({ code: 'preference_conflict', ...preferenceServer.snapshot() }));
		},
	});
	const firstChange = app.change('hours');
	await flush();
	const latestChange = app.change('minutes');
	retryTimers[0].callback();
	await Promise.all([firstChange, latestChange]);
	assert.deepEqual(app.requests.map(request => request.body.breakInputMode), ['hours', 'minutes']);
	assert.equal(app.storedMode(), 'minutes');
	assert.equal(app.state.breakInputMode, 'minutes');
	assert.deepEqual(app.messages, []);
	assert.equal(app.storage.values.size, 0);
});
