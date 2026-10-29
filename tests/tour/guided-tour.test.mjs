import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const headerSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-tour-header.js'), 'utf8');
const tourSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-tour.js'), 'utf8');
const flush = () => new Promise(setImmediate);

// Exercise the real tour with a small DOM and read-only application fixtures.
function createFixture({ hr = false, enabled = true, employeeCompleted = false, hrCompleted = false, available = true, headerPresent = true, nestedSearch = false, employees = [], helpPresent = true, viewportWidth = 1000, viewportHeight = 800, translate = message => message, respond = null, scheduleTimeout = callback => { setImmediate(callback); return 1; } } = {}) {
	const events = new Map();
	const windowEvents = new Map();
	const requests = [];
	const messages = [];
	const copyCounts = { exportChoices: 0, entryLoads: 0 };
	let document;

	function descendants(element) {
		return element.children.flatMap(child => [child, ...descendants(child)]);
	}
	function matchesSimple(element, selector) {
		const firstChild = selector.includes(':first-child');
		selector = selector.replace(':first-child', '');
		if (firstChild && element.parentElement?.children[0] !== element) return false;
		const attributes = [...selector.matchAll(/\[([\w-]+)(?:([*]?=)["']?([^\]"']*)["']?)?\]/g)];
		selector = selector.replace(/\[[^\]]+\]/g, '');
		for (const [, name, operator, value] of attributes) {
			const actual = element.getAttribute(name);
			if (actual === null || (operator === '=' && actual !== value) || (operator === '*=' && !actual.includes(value))) return false;
		}
		const tag = selector.match(/^[\w-]+/);
		if (tag && element.tagName !== tag[0].toUpperCase()) return false;
		for (const [, identifier] of selector.matchAll(/#([\w-]+)/g)) if (element.id !== identifier) return false;
		for (const [, className] of selector.matchAll(/\.([\w-]+)/g)) if (!element.classList.contains(className)) return false;
		return true;
	}
	function matchesSelector(element, selector) {
		const parts = selector.trim().split(/\s+/);
		if (!matchesSimple(element, parts.pop())) return false;
		let ancestor = element.parentElement;
		while (parts.length) {
			const part = parts.pop();
			while (ancestor && !matchesSimple(ancestor, part)) ancestor = ancestor.parentElement;
			if (!ancestor) return false;
			ancestor = ancestor.parentElement;
		}
		return true;
	}
	function makeEvent(type, target, values = {}) {
		return { type, target, stopped: false, immediate: false, prevented: false, ...values,
			preventDefault() { this.prevented = true; },
			stopPropagation() { this.stopped = true; },
			stopImmediatePropagation() { this.immediate = true; this.stopped = true; },
		};
	}
	function dispatch(event) {
		for (const listener of events.get(event.type) || []) {
			if (listener.capture) listener.callback(event);
			if (event.immediate) return event;
		}
		let element = event.target;
		while (element && !event.stopped) {
			for (const listener of element.listeners.get(event.type) || []) {
				listener(event);
				if (event.immediate) return event;
			}
			element = element.parentElement;
		}
		if (!event.stopped) for (const listener of events.get(event.type) || []) if (!listener.capture) listener.callback(event);
		return event;
	}

	class Element {
		constructor(tagName) {
			this.tagName = tagName.toUpperCase();
			this.id = '';
			this.className = '';
			this.children = [];
			this.parentElement = null;
			this.style = {};
			this.dataset = {};
			this.attributes = new Map();
			this.listeners = new Map();
			this.textContent = '';
			this.hidden = false;
			this.disabled = false;
			this.inert = false;
			this.scrollTop = 0;
			this.scrollLeft = 0;
			this.clientLeft = 0;
			this.clientWidth = 140;
			this.scrollWidth = 140;
			this.value = '';
			this.checked = false;
			this.classList = {
				contains: name => this.className.split(/\s+/).includes(name),
				add: name => { if (!this.classList.contains(name)) this.className = `${this.className} ${name}`.trim(); },
				remove: name => { this.className = this.className.split(/\s+/).filter(item => item !== name).join(' '); },
				toggle: (name, enabled) => { (enabled ?? !this.classList.contains(name)) ? this.classList.add(name) : this.classList.remove(name); },
			};
		}
		get childNodes() { return this.children; }
		get isConnected() { return this === document.body || !!this.parentElement?.isConnected; }
		append(...elements) { for (const element of elements) { element.remove(); this.children.push(element); element.parentElement = this; } }
		prepend(element) { element.remove(); this.children.unshift(element); element.parentElement = this; }
		insertBefore(element, next) { element.remove(); this.children.splice(this.children.indexOf(next), 0, element); element.parentElement = this; }
		remove() { if (this.parentElement) { const parent = this.parentElement; parent.children.splice(parent.children.indexOf(this), 1); this.parentElement = null; } }
		replaceChildren(...elements) { for (const child of [...this.children]) child.remove(); this.append(...elements); }
		contains(element) { return this === element || descendants(this).includes(element); }
		querySelectorAll(selector) { return descendants(this).filter(element => selector.split(',').some(part => matchesSelector(element, part))); }
		querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
		matches(selector) { return selector.split(',').some(part => matchesSelector(this, part)); }
		setAttribute(name, value) { if (name === 'id') this.id = String(value); else if (name === 'class') this.className = String(value); else this.attributes.set(name, String(value)); }
		getAttribute(name) { if (name === 'id') return this.id || null; if (name === 'class') return this.className || null; if (name.startsWith('data-')) return this.dataset[name.slice(5).replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase())] ?? null; return this.attributes.get(name) ?? null; }
		addEventListener(name, callback) { this.listeners.set(name, [...(this.listeners.get(name) || []), callback]); }
		click() { if (!this.disabled) dispatch(makeEvent('click', this)); }
		focus() { if (this.disabled || !this.isConnected) return; document.activeElement = this; dispatch(makeEvent('focusin', this)); }
		getClientRects() { let element = this; while (element) { if (element.hidden || element.style.display === 'none' || (element.classList.contains('ts-tabview') && !element.classList.contains('active'))) return []; element = element.parentElement; } return this.isConnected ? [this.getBoundingClientRect()] : []; }
		getBoundingClientRect() { const width = this.className.includes('ts-tour-dialog') || this.className.includes('ts-tour-invitation') ? parseFloat(this.style.width) || Math.min(360, viewportWidth - 24) : 140; const height = this.className.includes('ts-tour-dialog') ? Math.min(240, parseFloat(this.style.maxHeight) || 240) : 40; return { left: 160, top: 160, right: 160 + width, bottom: 160 + height, width, height }; }
		scrollIntoView() {}
	}
	document = {
		body: null, activeElement: null, scrollingElement: null,
		createElement: tag => new Element(tag), createElementNS: (_namespace, tag) => new Element(tag),
		getElementById: id => [document.body, ...descendants(document.body)].find(element => element.id === id) || null,
		querySelectorAll: selector => document.body.querySelectorAll(selector), querySelector: selector => document.body.querySelector(selector),
		addEventListener(name, callback, capture = false) { events.set(name, [...(events.get(name) || []), { callback, capture }]); },
		removeEventListener(name, callback) { events.set(name, (events.get(name) || []).filter(listener => listener.callback !== callback)); },
	};
	document.body = new Element('body');
	document.activeElement = document.body;
	document.scrollingElement = document.body;
	function element(tag, id = '', className = '', parent = document.body) { const created = new Element(tag); created.id = id; created.className = className; parent.append(created); return created; }
	if (headerPresent) {
		const header = element('header', 'header');
		header.getBoundingClientRect = () => ({ left: 0, top: 0, right: viewportWidth, bottom: 50, width: viewportWidth, height: 50 });
		const actions = element('div', '', 'header-end', header);
		const searchParent = nestedSearch ? element('div', '', 'unified-search-menu', actions) : actions;
		element('div', 'unified-search', '', searchParent);
		element('div', 'notifications', '', actions);
		const user = element('div', 'user-menu', '', actions);
		const trigger = element('button', '', 'header-menu__trigger', user);
		trigger.setAttribute('aria-expanded', 'false');
		trigger.setAttribute('aria-controls', 'header-menu-user-menu');
		const menu = element('div', 'header-menu-user-menu');
		menu.style.display = 'none';
		if (helpPresent) element('a', 'help', '', menu);
		trigger.addEventListener('click', () => { const opened = trigger.getAttribute('aria-expanded') !== 'true'; trigger.setAttribute('aria-expanded', String(opened)); menu.style.display = opened ? '' : 'none'; });
	}
	const app = element('section', 'timesheet-app');
	app.clientWidth = viewportWidth - 16;
	app.scrollWidth = viewportWidth + 700;
	app.getBoundingClientRect = () => ({ left: 8, top: 50, right: viewportWidth - 8, bottom: viewportHeight, width: viewportWidth - 16, height: viewportHeight - 50 });
	Object.assign(app.dataset, { tourEnabled: String(enabled), tourVersion: '1', tourEmployeeCompleted: String(employeeCompleted), tourHrCompleted: String(hrCompleted), tourIsHr: String(hr), tourStateAvailable: String(available) });
	const tabs = element('div', '', 'ts-tabs', app);
	if (hr) for (const name of ['mine', 'hr']) { const tab = element('button', '', `ts-tab${name === 'mine' ? ' active' : ''}`, tabs); tab.dataset.tab = name; }
	const mine = element('div', 'tab-mine', 'ts-tabview active', app);
	for (const id of ['month-prev', 'month-display', 'month-next', 'export-mine-xlsx']) element('button', id, '', mine);
	const stats = element('div', '', 'ts-stats', mine);
	element('span', 'overtime-total', '', stats).textContent = 'original balance';
	const config = element('div', '', 'hr-config-row', mine);
	element('input', 'config-daily-min-mine', 'config-daily-min', config).value = '07:30';
	const table = element('table', 'ts-table', '', mine);
	const body = element('tbody', 'ts-body', '', table);
	const row = element('tr', '', '', body);
	for (const className of ['startTime', 'breakMinutes', 'endTime', 'ts-duration', 'ts-diff', 'ts-warn', 'ts-status', 'commentInput']) element(className.endsWith('Input') ? 'textarea' : className.includes('Time') || className === 'breakMinutes' ? 'input' : 'td', '', className, row);
	element('div', 'ts-break-toggle', '', mine);
	if (hr) {
		const hrTab = element('div', 'tab-hr', 'ts-tabview', app);
		for (const id of ['hr-userlist-section', 'hr-stats-section', 'hr-notifications-section', 'hr-warning-thresholds-section', 'hr-user-entries']) {
			const section = element('div', id, 'ts-hr-section', hrTab);
			if (id === 'hr-userlist-section') element('tbody', 'hr-userlist', '', section);
			if (id === 'hr-user-entries') {
				section.style.display = 'none';
				const detailHeader = element('div', '', 'hr-user-header-bar', section);
				const title = element('h4', 'hr-user-title', '', detailHeader);
				element('span', '', '', title).textContent = 'previous-person';
				const controls = element('div', '', 'hr-user-controls-row', section);
				element('button', 'hr-month-display', '', controls).textContent = 'previous month';
				const configRow = element('div', '', 'hr-config-row', controls);
				element('input', '', 'config-daily-min', configRow).value = '06:00';
				element('select', '', 'config-state', configRow).value = 'BY';
				element('tbody', 'hr-user-body', '', section);
			}
		}
	}
	const context = vm.createContext({
		document, Element, HTMLElement: Element, Date, Set, Map,
		innerWidth: viewportWidth, innerHeight: viewportHeight, scrollX: 5, scrollY: 45,
		setTimeout: scheduleTimeout,
		requestAnimationFrame: callback => { setImmediate(callback); return 1; }, cancelAnimationFrame() {},
		getComputedStyle: element => ({ display: 'block', visibility: 'visible', overflowX: element === app ? 'auto' : 'visible' }),
		scrollTo(x, y) { context.scrollX = x; context.scrollY = y; },
		addEventListener(name, callback) { windowEvents.set(name, [...(windowEvents.get(name) || []), callback]); },
		removeEventListener(name, callback) { windowEvents.set(name, (windowEvents.get(name) || []).filter(listener => listener !== callback)); },
		t: (_app, message, parameters) => { let text = translate(message); for (const [name, value] of Object.entries(parameters || {})) text = text.replace(`{${name}}`, value); return text; },
		console: { warn() {} },
	});
	context.window = context;
	context.Timesheet = {
		state: { hrCurrentMonth: new Date(2025, 1, 1), ruleThresholds: { original: true }, tsHoveredRow: row },
		dom: { refresh() {} }, util: { minToHm: () => '08:00', pickDailyMin: () => 480 }, main: { updateHrMonthDisplay() {} },
		notify: message => messages.push(message),
		api: async (path, options = {}) => {
			const request = { path, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null };
			requests.push(request);
			if (respond) { const response = await respond(request, requests.length); if (response !== undefined) return response; }
			if (path === '/api/hr/users') return employees;
			if (path.startsWith('/api/hr/config/')) return { dailyMin: 480, state: 'BW' };
			if (path === '/api/preferences/tour/complete') return { version: 1, employeeCompleted: true, hrCompleted: request.body.includeHr || hrCompleted, isHr: hr };
			throw new Error('Unexpected request: ' + path);
		},
		export: { handleExportClick(view) { copyCounts.exportChoices++; const id = `export-${view}-xlsx-choice-ui`; let choices = document.getElementById(id); if (!choices) choices = element('span', id, '', mine); choices.classList.toggle('is-visible'); } },
		hr: { async loadHrUserList() { const list = document.getElementById('hr-userlist'); list.replaceChildren(); for (const employee of employees) { const button = element('button', '', 'hr-load-user', list); button.dataset.user = employee.id; } } },
		config: { async loadHrNotificationSettings() {}, async loadHrWarningThresholds() {} },
		entries: { async loadUserEntries() { copyCounts.entryLoads++; document.getElementById('hr-user-body').replaceChildren(element('tr', '', '', document.createElement('tbody'))); }, async refreshOvertimeTotal() {} },
	};
	vm.runInContext(headerSource, context, { filename: 'timesheet-tour-header.js' });
	vm.runInContext(tourSource, context, { filename: 'timesheet-tour.js' });
	context.Timesheet.tour.init();
	return { context, document, app, requests, messages, copyCounts, element, dispatch, event: makeEvent, tour: context.Timesheet.tour };
}

async function advanceToEnd(fixture) {
	while (fixture.tour.getState().index < fixture.tour.getState().total - 1) {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
}

test('header placement and invitation follow the app account state without duplicate launchers', async () => {
	const fixture = createFixture();
	const actions = fixture.document.getElementById('notifications').parentElement.children;
	assert.equal(actions[1].id, 'ts-tour-launcher');
	assert.equal(actions[2].id, 'notifications');
	assert.ok(fixture.document.getElementById('ts-tour-invitation'));
	fixture.tour.init();
	assert.equal(fixture.document.querySelectorAll('#ts-tour-launcher').length, 1);
	fixture.document.getElementById('ts-tour-invitation')?.querySelectorAll('button').find(button => button.dataset.tourAction === 'later').click();
	assert.equal(fixture.document.getElementById('ts-tour-invitation'), null);
	assert.equal(fixture.requests.length, 0);
	assert.ok(createFixture().document.getElementById('ts-tour-invitation'));
});

test('completed accounts retain manual access; unavailable progress never auto-starts', async () => {
	const completed = createFixture({ employeeCompleted: true });
	assert.equal(completed.document.getElementById('ts-tour-invitation'), null);
	await completed.tour.start();
	assert.equal(completed.tour.getState().step, 'month');
	assert.equal(completed.tour.getState().total, 10);
	await completed.tour.end();
	const unavailable = createFixture({ available: false });
	assert.equal(unavailable.document.getElementById('ts-tour-invitation'), null);
	assert.equal(unavailable.messages.length, 1);
	await unavailable.tour.start();
	assert.equal(unavailable.tour.getState().active, true);
	await unavailable.tour.end();
});

test('nested Nextcloud search wrappers still place the launcher between search and notifications', () => {
	const fixture = createFixture({ nestedSearch: true });
	const notifications = fixture.document.getElementById('notifications');
	const launcher = fixture.document.getElementById('ts-tour-launcher');
	assert.ok(launcher.parentElement === notifications.parentElement);
	assert.ok(notifications.parentElement.children[1] === launcher);
	assert.equal(launcher.classList.contains('ts-tour-launcher-fallback'), false);
});

test('employee tour blocks background changes, traps focus, aborts on Escape and restores the view', async () => {
	const fixture = createFixture();
	const originalInput = fixture.document.getElementById('config-daily-min-mine');
	fixture.app.scrollTop = 70;
	fixture.app.scrollLeft = 18;
	await fixture.tour.start();
	let appShortcuts = 0;
	fixture.document.addEventListener('keydown', () => { appShortcuts++; });
	fixture.document.addEventListener('copy', () => { appShortcuts++; });
	fixture.dispatch(fixture.event('keydown', fixture.document.getElementById('ts-tour-next'), { key: 'c', ctrlKey: true }));
	fixture.dispatch(fixture.event('copy', fixture.document.getElementById('ts-tour-next')));
	assert.equal(appShortcuts, 0);
	const blocked = fixture.dispatch(fixture.event('click', originalInput));
	assert.equal(blocked.prevented, true);
	assert.equal(blocked.immediate, true);
	originalInput.focus();
	assert.equal(fixture.document.activeElement.id, 'ts-tour-next');
	const tab = fixture.dispatch(fixture.event('keydown', fixture.document.activeElement, { key: 'Tab' }));
	assert.equal(tab.prevented, true);
	assert.equal(fixture.document.activeElement.id, 'ts-tour-end');
	fixture.dispatch(fixture.event('keydown', fixture.document.activeElement, { key: 'Escape' }));
	await flush(); await flush();
	assert.equal(fixture.tour.getState().active, false);
	assert.equal(fixture.app.inert, false);
	assert.equal(fixture.app.scrollTop, 70);
	assert.equal(fixture.app.scrollLeft, 18);
	assert.equal(originalInput.value, '07:30');
	assert.equal(fixture.requests.length, 0);
});

test('employee completion writes only tour progress and preserves account-language text', async () => {
	const fixture = createFixture({ translate: text => `translated:${text}` });
	await fixture.tour.start();
	assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'translated:Choose a month');
	await advanceToEnd(fixture);
	assert.equal(fixture.tour.getState().step, 'help');
	assert.equal(fixture.document.getElementById('ts-tour-dialog').dataset.stepId, 'help');
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().active, false);
	assert.equal(fixture.tour.getState().employeeCompleted, true);
	assert.deepEqual(fixture.requests, [{ path: '/api/preferences/tour/complete', method: 'POST', body: { version: 1, includeHr: false } }]);
	assert.equal(fixture.copyCounts.entryLoads, 0);
	assert.equal(fixture.document.getElementById('export-mine-xlsx-choice-ui').classList.contains('is-visible'), false);
});

test('HR-only introduction uses scoped employee reads, includes HR and restores original fields', async () => {
	const fixture = createFixture({ hr: true, employeeCompleted: true, employees: [{ id: 'permitted-user' }] });
	const originalTitle = fixture.document.querySelector('#hr-user-title span').textContent;
	await fixture.tour.start(true);
	assert.equal(fixture.tour.getState().total, 8);
	assert.equal(fixture.tour.getState().step, 'hr-navigation');
	await advanceToEnd(fixture);
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().hrCompleted, true);
	assert.equal(fixture.document.querySelector('#hr-user-title span').textContent, originalTitle);
	assert.equal(fixture.context.Timesheet.state.hrCurrentMonth.getMonth(), 1);
	assert.ok(fixture.requests.some(request => request.path === '/api/hr/config/permitted-user'));
	assert.deepEqual(fixture.requests.filter(request => request.method !== 'GET').map(request => request.body), [{ version: 1, includeHr: true }]);
});

test('empty HR assignments and hidden Nextcloud help have explicit explanations', async () => {
	const fixture = createFixture({ hr: true, helpPresent: false });
	await fixture.tour.start(true);
	while (fixture.tour.getState().step !== 'hr-person') {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	assert.match(fixture.document.getElementById('ts-tour-description').textContent, /^No employees are currently assigned/);
	await advanceToEnd(fixture);
	assert.match(fixture.document.getElementById('ts-tour-description').textContent, /^General help is hidden/);
	assert.equal(fixture.copyCounts.entryLoads, 0);
	await fixture.tour.end();
});

test('lost HR access removes HR steps and does not mark its introduction complete', async () => {
	const fixture = createFixture({ hr: true, respond(request) { if (request.path === '/api/hr/users') throw new Error('[]'); } });
	await fixture.tour.start(true);
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().isHr, false);
	assert.equal(fixture.tour.getState().step, 'profile');
	await advanceToEnd(fixture);
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.deepEqual(fixture.requests.filter(request => request.method === 'POST').map(request => request.body), [{ version: 1, includeHr: false }]);
});

test('temporary completion locks retry; storage failure keeps completion unfinished', async () => {
	let attempts = 0;
	const busy = createFixture({ respond(request) { if (request.method === 'POST' && ++attempts < 3) throw new Error('{"code":"preference_busy"}'); } });
	await busy.tour.start();
	await advanceToEnd(busy);
	busy.document.getElementById('ts-tour-next').click();
	for (let index = 0; index < 10; index++) await flush();
	assert.equal(attempts, 3);
	assert.equal(busy.tour.getState().employeeCompleted, true);
	const failed = createFixture({ respond(request) { if (request.method === 'POST') throw new Error('Synthetic storage failure'); } });
	await failed.tour.start();
	await advanceToEnd(failed);
	failed.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(failed.tour.getState().employeeCompleted, false);
	assert.equal(failed.tour.getState().active, false);
	assert.match(failed.messages.at(-1), /completion could not be saved/);
});

test('missing header yields a labeled launcher within the app', async () => {
	const fixture = createFixture({ headerPresent: false });
	const button = fixture.document.getElementById('ts-tour-launcher');
	assert.equal(button.parentElement, fixture.app);
	assert.equal(button.classList.contains('ts-tour-launcher-fallback'), true);
	assert.equal(button.getAttribute('aria-label'), 'Start the Timesheet tour');
	await fixture.tour.start();
	await advanceToEnd(fixture);
	assert.match(fixture.document.getElementById('ts-tour-description').textContent, /^General help is hidden/);
	await fixture.tour.end();
});

test('new HR rights offer only the additional introduction; fully introduced HR accounts stay quiet', async () => {
	const promoted = createFixture({ hr: true, employeeCompleted: true });
	assert.ok(promoted.document.getElementById('ts-tour-invitation'));
	promoted.document.getElementById('ts-tour-start').click();
	await flush(); await flush();
	assert.equal(promoted.tour.getState().step, 'hr-navigation');
	assert.equal(promoted.tour.getState().total, 8);
	await promoted.tour.end();
	const completed = createFixture({ hr: true, employeeCompleted: true, hrCompleted: true });
	assert.equal(completed.document.getElementById('ts-tour-invitation'), null);
	await completed.tour.start();
	assert.equal(completed.tour.getState().step, 'month');
	assert.equal(completed.tour.getState().total, 16);
	await completed.tour.end();
});

test('ending during a temporary-lock wait cancels further completion retries', async () => {
	let resumeRetry;
	const fixture = createFixture({
		scheduleTimeout(callback) { resumeRetry = callback; return 1; },
		respond(request) { if (request.method === 'POST') throw new Error('{"code":"preference_busy"}'); },
	});
	await fixture.tour.start();
	await advanceToEnd(fixture);
	fixture.document.getElementById('ts-tour-next').click();
	await flush();
	assert.equal(typeof resumeRetry, 'function');
	await fixture.tour.end();
	await fixture.tour.start();
	resumeRetry();
	await flush(); await flush();
	assert.equal(fixture.requests.filter(request => request.method === 'POST').length, 1);
	assert.equal(fixture.tour.getState().employeeCompleted, false);
	assert.equal(fixture.tour.getState().active, true);
	assert.equal(fixture.messages.length, 0);
	await fixture.tour.end();
});

test('a delayed completion from an ended tour cannot finish or update a restarted tour', async () => {
	let resolveOldRequest;
	const oldRequest = new Promise(resolve => { resolveOldRequest = resolve; });
	const fixture = createFixture({ respond(request) { if (request.method === 'POST') return oldRequest; } });
	await fixture.tour.start();
	await advanceToEnd(fixture);
	fixture.document.getElementById('ts-tour-next').click();
	await flush();
	await fixture.tour.end();
	await fixture.tour.start();
	assert.equal(fixture.tour.getState().step, 'month');
	resolveOldRequest({ version: 1, employeeCompleted: true, hrCompleted: false, isHr: false });
	await flush(); await flush();
	assert.equal(fixture.tour.getState().active, true);
	assert.equal(fixture.tour.getState().step, 'month');
	assert.equal(fixture.tour.getState().employeeCompleted, false);
	assert.equal(fixture.messages.length, 0);
	await fixture.tour.end();
});

test('entry fields fit the narrow viewport as a group in left-to-right and right-to-left scroll containers', async () => {
	for (const coordinates of [[50, 150, 230], [140, 60, -40]]) {
		const fixture = createFixture({ viewportWidth: 320 });
		const fields = ['startTime', 'breakMinutes', 'endTime'].map(className => fixture.document.querySelector(`#ts-body .${className}`));
		fields.forEach((field, index) => {
			const width = index === 1 ? 80 : 100;
			field.getBoundingClientRect = () => ({ left: coordinates[index] - fixture.app.scrollLeft, right: coordinates[index] + width - fixture.app.scrollLeft, top: 160, bottom: 190, width, height: 30 });
		});
		await fixture.tour.start();
		while (fixture.tour.getState().step !== 'entries') {
			fixture.document.getElementById('ts-tour-next').click();
			await flush(); await flush();
		}
		for (const field of fields) {
			const rectangle = field.getBoundingClientRect();
			assert.ok(rectangle.left >= 8 && rectangle.right <= 312, 'All three entry fields remain visible together');
		}
		await fixture.tour.end();
		assert.equal(fixture.app.scrollLeft, 0);
	}
});

test('application highlights stop below the fixed header and HR details reveal only their controls', async () => {
	const fixture = createFixture({ hr: true, employees: [{ id: 'permitted-user' }] });
	const ownConfig = fixture.document.querySelector('#tab-mine .hr-config-row');
	ownConfig.getBoundingClientRect = () => ({ left: 10, right: 400, top: -20, bottom: 150, width: 390, height: 170 });
	await fixture.tour.start();
	while (fixture.tour.getState().step !== 'configuration') {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	const configHole = fixture.document.querySelector('#ts-tour-cutout g rect');
	assert.ok(Number(configHole.getAttribute('y')) >= 50, 'The application spotlight does not brighten the fixed header');
	const detail = fixture.document.getElementById('hr-user-entries');
	detail.getBoundingClientRect = () => ({ left: 8, right: 992, top: -600, bottom: 1800, width: 984, height: 2400 });
	while (fixture.tour.getState().step !== 'hr-person') {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	const detailHoles = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	assert.ok(detailHoles.length >= 1 && detailHoles.length <= 2);
	assert.ok(detailHoles.every(hole => Number(hole.getAttribute('height')) < 200), 'Only the compact employee header and controls are revealed');
	await fixture.tour.end();
});

test('keyboard reading keys scroll an overflowing explanation while focus stays on tour controls', async () => {
	const fixture = createFixture({ viewportWidth: 320, viewportHeight: 400 });
	await fixture.tour.start();
	const explanation = fixture.document.querySelector('.ts-tour-content');
	explanation.clientHeight = 100;
	explanation.scrollHeight = 500;
	const nextButton = fixture.document.getElementById('ts-tour-next');
	const pageDown = fixture.dispatch(fixture.event('keydown', nextButton, { key: 'PageDown' }));
	assert.ok(explanation.scrollTop > 0, 'PageDown reaches clipped explanatory text');
	assert.equal(pageDown.prevented, true);
	assert.equal(fixture.document.activeElement, nextButton);
	const previousTop = explanation.scrollTop;
	fixture.dispatch(fixture.event('keydown', nextButton, { key: 'ArrowDown' }));
	assert.ok(explanation.scrollTop > previousTop);
	fixture.dispatch(fixture.event('keydown', nextButton, { key: 'End' }));
	assert.equal(explanation.scrollTop, 400);
	fixture.dispatch(fixture.event('keydown', nextButton, { key: 'PageUp' }));
	assert.ok(explanation.scrollTop < 400);
	fixture.dispatch(fixture.event('keydown', nextButton, { key: 'Home' }));
	assert.equal(explanation.scrollTop, 0);
	for (const modifier of ['ctrlKey', 'altKey', 'metaKey', 'shiftKey']) {
		const modified = fixture.dispatch(fixture.event('keydown', nextButton, { key: 'PageDown', [modifier]: true }));
		assert.equal(explanation.scrollTop, 0);
		assert.equal(modified.prevented, false);
	}
	fixture.dispatch(fixture.event('keydown', nextButton, { key: 'PageDown' }));
	nextButton.click();
	await flush(); await flush();
	assert.equal(explanation.scrollTop, 0, 'The next explanation begins at its heading');
	assert.equal(fixture.context.scrollY, 45);
	await fixture.tour.end();
});

test('administrator-disabled tours create neither an invitation nor a launcher', async () => {
	const fixture = createFixture({ enabled: false, hr: true });
	assert.equal(fixture.document.getElementById('ts-tour-launcher'), null);
	assert.equal(fixture.document.getElementById('ts-tour-invitation'), null);
	await fixture.tour.start();
	assert.equal(fixture.document.getElementById('ts-tour-overlay'), null);
	assert.equal(fixture.requests.length, 0);
});

test('the HR introduction explains the two tabs before opening protected HR views', async () => {
	const fixture = createFixture({ hr: true, employeeCompleted: true });
	await fixture.tour.start(true);
	assert.equal(fixture.tour.getState().step, 'hr-navigation');
	assert.equal(fixture.tour.getState().total, 8);
	assert.equal(fixture.app.classList.contains('ts-tour-navigation-preview'), true);
	assert.equal(fixture.requests.length, 0);
	assert.equal(fixture.document.getElementById('tab-mine').classList.contains('active'), true);
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'hr-list');
	assert.equal(fixture.app.classList.contains('ts-tour-navigation-preview'), false);
	assert.equal(fixture.document.getElementById('tab-hr').classList.contains('active'), true);
	assert.ok(fixture.requests.some(request => request.path === '/api/hr/users'));
	await fixture.tour.end();
});

test('ending the HR navigation explanation restores the original tab appearance', async () => {
	const fixture = createFixture({ hr: true, employeeCompleted: true });
	await fixture.tour.start(true);
	assert.equal(fixture.app.classList.contains('ts-tour-navigation-preview'), true);
	fixture.dispatch(fixture.event('keydown', fixture.document.activeElement, { key: 'Escape' }));
	await flush(); await flush();
	assert.equal(fixture.tour.getState().active, false);
	assert.equal(fixture.app.classList.contains('ts-tour-navigation-preview'), false);
});

test('adjacent entry highlights share one frame instead of overlapping padded boxes', async () => {
	const fixture = createFixture();
	['startTime', 'breakMinutes', 'endTime'].forEach((className, index) => {
		const left = 300 + index * 60;
		fixture.document.querySelector(`#ts-body .${className}`).getBoundingClientRect = () => ({ left, right: left + 60, top: 180, bottom: 210, width: 60, height: 30 });
	});
	await fixture.tour.start();
	while (fixture.tour.getState().step !== 'entries') {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	const holes = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	assert.equal(holes.length, 1);
	assert.equal(Number(holes[0].getAttribute('x')), 295);
	assert.equal(Number(holes[0].getAttribute('width')), 190);
	await fixture.tour.end();
});

test('the explanation uses free horizontal space when a short viewport cannot fit it above or below', async () => {
	const fixture = createFixture({ viewportWidth: 1000, viewportHeight: 400 });
	['startTime', 'breakMinutes', 'endTime'].forEach((className, index) => {
		const left = 300 + index * 60;
		fixture.document.querySelector(`#ts-body .${className}`).getBoundingClientRect = () => ({ left, right: left + 60, top: 150, bottom: 220, width: 60, height: 70 });
	});
	await fixture.tour.start();
	while (fixture.tour.getState().step !== 'entries') {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	const box = fixture.document.getElementById('ts-tour-dialog');
	const rectangle = { left: parseFloat(box.style.left), top: parseFloat(box.style.top), width: 360, height: 240 };
	for (const hole of fixture.document.querySelectorAll('#ts-tour-cutout g rect')) {
		const left = Number(hole.getAttribute('x'));
		const top = Number(hole.getAttribute('y'));
		const right = left + Number(hole.getAttribute('width'));
		const bottom = top + Number(hole.getAttribute('height'));
		assert.equal(rectangle.left < right && rectangle.left + rectangle.width > left && rectangle.top < bottom && rectangle.top + rectangle.height > top, false, 'The explanation does not cover highlighted fields');
	}
	await fixture.tour.end();
});

test('HR email and warning explanations prefer the left side when the desktop has room', async () => {
	const fixture = createFixture({ hr: true, employeeCompleted: true });
	for (const id of ['hr-notifications-section', 'hr-warning-thresholds-section']) {
		fixture.document.getElementById(id).getBoundingClientRect = () => ({ left: 550, right: 900, top: 180, bottom: 500, width: 350, height: 320 });
	}
	await fixture.tour.start(true);
	while (fixture.tour.getState().step !== 'hr-warnings') {
		if (fixture.tour.getState().step === 'hr-mail') {
			assert.ok(parseFloat(fixture.document.getElementById('ts-tour-dialog').style.left) + 360 <= 536);
		}
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	assert.ok(parseFloat(fixture.document.getElementById('ts-tour-dialog').style.left) + 360 <= 536);
	await fixture.tour.end();
});
