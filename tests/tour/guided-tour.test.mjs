import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const mailHelpSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-mail-help.js'), 'utf8');
const headerSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-tour-launcher.js'), 'utf8');
const tourSource = readFileSync(resolve(repositoryRoot, 'js/timesheet-tour.js'), 'utf8');
const flush = () => new Promise(setImmediate);

// Exercise the real tour with a small DOM and read-only application fixtures.
function createFixture({ hr = false, hrDom = false, enabled = true, employeeCompleted = false, available = true, headerPresent = true, nestedSearch = false, helpPresent = true, freelancerControl = true, freelancerEnabled = false, viewportWidth = 1000, viewportHeight = 800, absenceTerms = { vacation: 'Vacation', timeOffInLieu: 'Time off in lieu' }, translate = message => message, respond = null, scheduleTimeout = callback => { setImmediate(callback); return 1; } } = {}) {
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
		const childNumber = selector.match(/:nth-child\((\d+)\)/)?.[1];
		selector = selector.replace(/:nth-child\(\d+\)/g, '');
		if (childNumber && element.parentElement?.children[Number(childNumber) - 1] !== element) return false;
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
		body: null, activeElement: null, scrollingElement: null, readyState: 'complete',
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
	// The page exposes only the resolved eligibility, never a separate HR tour state.
	Object.assign(app.dataset, { tourEnabled: String(enabled && !hr), tourVersion: '1', tourEmployeeCompleted: String(employeeCompleted), tourStateAvailable: String(available) });
	Object.assign(app.dataset, { absenceVacation: absenceTerms.vacation, absenceTimeOffInLieu: absenceTerms.timeOffInLieu });
	if (enabled && !hr) {
		const body = [translate('Hello Timesheet team,'), '', '', translate('Best regards,'), translate('Your name')].join('\r\n');
		const draft = subject => `mailto:karriere@xiller.com?subject=${encodeURIComponent(translate(subject))}&body=${encodeURIComponent(body)}`;
		app.dataset.tourQuestionDraft = draft('Question about the Timesheet app');
		app.dataset.tourFeedbackDraft = draft('Feedback on the Timesheet app');
	}
	const tabs = element('div', '', 'ts-tabs', app);
	if (hr || hrDom) for (const name of ['mine', 'hr']) { const tab = element('button', '', `ts-tab${name === 'mine' ? ' active' : ''}`, tabs); tab.dataset.tab = name; }
	const mine = element('div', 'tab-mine', 'ts-tabview active', app);
	for (const id of ['month-prev', 'month-display', 'month-next', 'export-mine-xlsx']) element('button', id, '', mine);
	const stats = element('div', '', 'ts-stats', mine);
	element('span', 'overtime-total', '', stats).textContent = 'original balance';
	const config = element('div', '', 'hr-config-row', mine);
	const workingTimeLabel = element('label', '', '', config);
	workingTimeLabel.setAttribute('for', 'config-daily-min-mine');
	workingTimeLabel.textContent = 'Daily working time:';
	element('input', 'config-daily-min-mine', 'config-daily-min', config).value = '07:30';
	const stateLabel = element('label', '', '', config);
	stateLabel.setAttribute('for', 'config-state-mine');
	stateLabel.textContent = 'State:';
	element('select', 'config-state-mine', 'config-state', config).value = 'BY';
	const table = element('table', 'ts-table', '', mine);
	const tableHead = element('thead', '', '', table);
	const headerRow = element('tr', '', '', tableHead);
	for (const label of ['Date', 'Status', 'Start', 'Break', 'End', 'Duration', 'Difference', 'Comment', 'Warning']) {
		element('th', '', '', headerRow).textContent = label;
	}
	const body = element('tbody', 'ts-body', '', table);
	const row = element('tr', '', '', body);
	for (const className of ['startTime', 'breakMinutes', 'endTime', 'ts-duration', 'ts-diff', 'ts-warn', 'ts-status', 'commentInput']) element(className.endsWith('Input') ? 'textarea' : className.includes('Time') || className === 'breakMinutes' ? 'input' : 'td', '', className, row);
	element('div', 'ts-break-toggle', '', mine);
	if (freelancerControl && !hr) {
		const freelancerGroup = element('div', '', 'ts-employment-toggle', mine);
		freelancerGroup.setAttribute('role', 'group');
		freelancerGroup.setAttribute('aria-labelledby', 'freelancer-mode-label');
		element('span', 'freelancer-mode-label', '', freelancerGroup).textContent = 'Freelancer mode:';
		element('span', '', '', freelancerGroup).textContent = 'Off';
		const freelancerLabel = element('label', '', 'ts-break-switch', freelancerGroup);
		const freelancerSwitch = element('input', 'freelancer-mode-switch', 'ts-freelancer-toggle-switch', freelancerLabel);
		freelancerSwitch.setAttribute('type', 'checkbox');
		freelancerSwitch.setAttribute('role', 'switch');
		freelancerSwitch.setAttribute('aria-checked', String(freelancerEnabled));
		freelancerSwitch.checked = freelancerEnabled;
		element('span', '', 'ts-break-switch-slider', freelancerLabel);
		element('span', '', '', freelancerGroup).textContent = 'On';
	}
	if (hr || hrDom) {
		const hrTab = element('div', 'tab-hr', 'ts-tabview', app);
		if (hr) {
			const request = element('a', '', 'hr-app-request', hrTab);
			request.setAttribute('href', 'mailto:info@xiller.com?subject=App%20request');
		}
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
		state: { tsHoveredRow: row },
		dom: { refresh() {} },
		notify: message => messages.push(message),
		api: async (path, options = {}) => {
			const request = { path, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null };
			requests.push(request);
			if (respond) { const response = await respond(request, requests.length); if (response !== undefined) return response; }
			if (path === '/api/preferences/tour/complete') return { version: 1, employeeCompleted: true, hrCompleted: false, isHr: false };
			throw new Error('Unexpected request: ' + path);
		},
		export: { handleExportClick(view) { copyCounts.exportChoices++; const id = `export-${view}-xlsx-choice-ui`; let choices = document.getElementById(id); if (!choices) choices = element('span', id, '', mine); choices.classList.toggle('is-visible'); } },
	};
	vm.runInContext(mailHelpSource, context, { filename: 'timesheet-mail-help.js' });
	vm.runInContext(headerSource, context, { filename: 'timesheet-tour-launcher.js' });
	vm.runInContext(tourSource, context, { filename: 'timesheet-tour.js' });
	context.Timesheet.tour.init();
	return {
		context, document, app, requests, messages, copyCounts, element, dispatch, event: makeEvent, tour: context.Timesheet.tour,
		resize(width, height) {
			context.innerWidth = width;
			context.innerHeight = height;
			for (const callback of windowEvents.get('resize') || []) callback();
		},
	};
}

async function advanceToEnd(fixture) {
	while (fixture.tour.getState().index < fixture.tour.getState().total - 1) {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
}

async function advanceToStep(fixture, step) {
	while (fixture.tour.getState().step !== step && fixture.tour.getState().index < fixture.tour.getState().total - 1) {
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
	}
	assert.equal(fixture.tour.getState().step, step, `The tour reaches ${step}`);
}

test('the help button opens three menu choices without starting the tour', () => {
	const fixture = createFixture();
	const launcher = fixture.document.getElementById('ts-tour-launcher');
	launcher.click();
	const menu = fixture.document.getElementById('ts-tour-help-menu');
	assert.ok(menu);
	assert.equal(menu.hidden, false);
	assert.equal(launcher.getAttribute('aria-expanded'), 'true');
	assert.equal(launcher.getAttribute('aria-haspopup'), 'menu');
	assert.equal(launcher.getAttribute('aria-controls'), menu.id);
	assert.equal(launcher.getAttribute('aria-label'), 'Help and tour');
	assert.equal(launcher.title, 'Help and tour');
	assert.deepEqual(menu.children.map(item => item.textContent), ['Tour guide', 'Questions for HR', 'Suggestions for improvement']);
	assert.deepEqual(menu.children.map(item => item.getAttribute('role')), ['menuitem', 'menuitem', 'menuitem']);
	assert.equal(fixture.tour.getState().active, false);
	assert.equal(fixture.document.getElementById('ts-tour-invitation'), null, 'Manual help does not leave the first-visit invitation behind it');
	assert.equal(fixture.requests.length, 0);
});

for (const locale of ['de', 'de_DE', 'fr', 'en_GB']) {
	test(`question and improvement choices open localized email drafts: ${locale}`, () => {
		const { translations } = JSON.parse(readFileSync(resolve(repositoryRoot, 'l10n', `${locale}.json`), 'utf8'));
		const fixture = createFixture({ translate: message => translations[message] ?? message });
		const launcher = fixture.document.getElementById('ts-tour-launcher');
		const expectedBody = [translations['Hello Timesheet team,'], '', '', translations['Best regards,'], translations['Your name']].join('\r\n');
		for (const [itemId, subject] of [
			['ts-tour-menu-questions', 'Question about the Timesheet app'],
			['ts-tour-menu-suggestions', 'Feedback on the Timesheet app'],
		]) {
			launcher.click();
			const link = fixture.document.getElementById(itemId);
			assert.equal(link.tagName, 'A');
			const email = new URL(link.getAttribute('href'));
			assert.equal(email.protocol, 'mailto:');
			assert.equal(email.pathname, 'karriere@xiller.com');
			assert.deepEqual([...email.searchParams], [
				['subject', translations[subject]],
				['body', expectedBody],
			]);
			link.click();
			assert.equal(fixture.document.getElementById('ts-tour-help-menu').hidden, true);
			assert.equal(fixture.document.activeElement, launcher);
			assert.equal(fixture.tour.getState().active, false);
			assert.equal(fixture.requests.length, 0);
		}
	});
}

test('an intercepted email link offers conditional help without exposing message details', () => {
	const scheduledCallbacks = [];
	const fixture = createFixture({ scheduleTimeout: callback => { scheduledCallbacks.push(callback); return 1; } });
	const launcher = fixture.document.getElementById('ts-tour-launcher');
	for (const itemId of ['ts-tour-menu-questions', 'ts-tour-menu-suggestions']) {
		launcher.click();
		const link = fixture.document.getElementById(itemId);
		const originalHref = link.getAttribute('href');
		const click = fixture.event('click', link);
		// Simulate a browser test suppressing an external application launch.
		click.preventDefault();
		fixture.dispatch(click);
		const hint = fixture.document.getElementById('ts-email-help');
		assert.ok(hint, 'The clicked email link leaves useful information even if no application opens');
		assert.equal(hint.getAttribute('role'), 'region');
		const status = fixture.document.getElementById('ts-email-help-status');
		assert.equal(hint.children[0], status, 'The immediate action status precedes the conditional help');
		assert.equal(status.getAttribute('role'), 'status');
		assert.equal(status.textContent, 'Opening the default mail app…');
		assert.equal(fixture.document.getElementById('ts-email-help-title').textContent, 'No mail app opened?');
		assert.equal(hint.querySelectorAll('input, label').length, 0);
		const description = hint.querySelectorAll('p').find(paragraph => paragraph !== status);
		assert.equal(description.textContent, 'Email links require a default mail app in the browser or operating system.');
		assert.doesNotMatch(hint.children.map(child => child.textContent).join(' '), /karriere@xiller\.com|Frage zum Arbeitszeitnachweis|Feedback für Arbeitszeitnachweis|copied/u);
		assert.equal(hint.querySelectorAll('button').length, 1);
		assert.equal(fixture.document.getElementById('ts-email-help-close').getAttribute('aria-label'), 'Close email help');
		assert.equal(fixture.document.querySelectorAll('#ts-email-help').length, 1);
		assert.equal(fixture.document.activeElement, launcher, 'Showing the hint does not move focus into it');
		assert.equal(link.getAttribute('href'), originalHref);
		assert.equal(fixture.requests.length, 0);
		assert.deepEqual(scheduledCallbacks, [], 'No delayed handler-detection heuristic runs');
	}
});

test('email help stays conditional across focus changes and preserves the email link default action', () => {
	const fixture = createFixture();
	fixture.document.getElementById('ts-tour-launcher').click();
	const link = fixture.document.getElementById('ts-tour-menu-questions');
	const click = fixture.dispatch(fixture.event('click', link));
	assert.equal(click.prevented, false, 'The email link keeps its normal default action');
	const hint = fixture.document.getElementById('ts-email-help');
	const title = fixture.document.getElementById('ts-email-help-title').textContent;
	const appInput = fixture.document.getElementById('config-daily-min-mine');
	appInput.focus();
	assert.equal(fixture.document.getElementById('ts-email-help'), hint, 'Focus changes do not diagnose a missing mail application');
	assert.equal(fixture.document.getElementById('ts-email-help-title').textContent, title);
	assert.equal(fixture.document.activeElement, appInput);
	assert.equal(fixture.requests.length, 0);
	assert.equal(appInput.value, '07:30');
});

test('the HR request link opens the shared email help without a tour launcher', () => {
	const fixture = createFixture({ hr: true });
	fixture.document.getElementById('tab-hr').classList.add('active');
	const link = fixture.document.querySelector('.hr-app-request');
	const href = link.getAttribute('href');
	assert.equal(fixture.document.getElementById('ts-tour-launcher'), null);
	assert.equal(fixture.document.getElementById('ts-email-help'), null);

	link.focus();
	const click = fixture.dispatch(fixture.event('click', link));
	assert.equal(click.prevented, false);
	assert.equal(link.getAttribute('href'), href);
	assert.equal(fixture.document.activeElement, link);
	assert.equal(fixture.document.getElementById('ts-email-help-status').textContent, 'Opening the default mail app…');
	assert.equal(fixture.document.getElementById('ts-email-help-title').textContent, 'No mail app opened?');

	const close = fixture.document.getElementById('ts-email-help-close');
	close.focus();
	fixture.dispatch(fixture.event('keydown', close, { key: 'Escape' }));
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	assert.equal(fixture.document.activeElement, link);
	assert.equal(fixture.requests.length, 0);
});

test('email help closes explicitly, on outside clicks, when reopening the menu and before a tour starts', async () => {
	const fixture = createFixture();
	const launcher = fixture.document.getElementById('ts-tour-launcher');
	const showHint = () => {
		launcher.click();
		fixture.document.getElementById('ts-tour-menu-suggestions').click();
		assert.ok(fixture.document.getElementById('ts-email-help'));
	};
	showHint();
	fixture.document.getElementById('ts-email-help-close').click();
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	showHint();
	const closeButton = fixture.document.getElementById('ts-email-help-close');
	closeButton.focus();
	fixture.dispatch(fixture.event('keydown', closeButton, { key: 'Escape' }));
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	assert.equal(fixture.document.activeElement, launcher);
	showHint();
	fixture.dispatch(fixture.event('pointerdown', fixture.app));
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	showHint();
	launcher.click();
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	assert.equal(fixture.document.getElementById('ts-tour-help-menu').hidden, false);
	fixture.document.getElementById('ts-tour-menu-questions').click();
	assert.ok(fixture.document.getElementById('ts-email-help'));
	await fixture.tour.start();
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	assert.equal(fixture.document.getElementById('ts-tour-help-menu').hidden, true);
	await fixture.tour.end();
	assert.equal(fixture.document.getElementById('ts-email-help'), null);
	assert.equal(fixture.requests.length, 0);
	assert.equal(createFixture().document.getElementById('ts-email-help'), null);
});

test('email help stays inside a narrow viewport and repositions without moving focus', () => {
	for (const direction of ['ltr', 'rtl']) {
		const fixture = createFixture({ viewportWidth: 320, viewportHeight: 568 });
		const launcher = fixture.document.getElementById('ts-tour-launcher');
		const originalStyle = fixture.context.getComputedStyle;
		fixture.context.getComputedStyle = element => ({ ...originalStyle(element), direction });
		launcher.click();
		fixture.document.getElementById('ts-tour-menu-questions').click();
		const hint = fixture.document.getElementById('ts-email-help');
		hint.getBoundingClientRect = () => ({ width: 296, height: 340 });
		launcher.getBoundingClientRect = () => ({ left: 300, top: 0, right: 344, bottom: 50, width: 44, height: 50 });
		const closeButton = fixture.document.getElementById('ts-email-help-close');
		closeButton.focus();
		fixture.resize(320, 568);
		assert.ok(parseFloat(hint.style.left) >= 12 && parseFloat(hint.style.left) + 296 <= 308);
		assert.ok(parseFloat(hint.style.top) >= 12 && parseFloat(hint.style.top) + 340 <= 556);
		assert.equal(fixture.document.activeElement, closeButton);
		assert.equal(fixture.document.getElementById('ts-email-help'), hint);
		assert.equal(fixture.requests.length, 0);
	}
});

test('the menu tour choice starts the existing tour and restores focus to the help button', async () => {
	const fixture = createFixture({ employeeCompleted: true });
	const launcher = fixture.document.getElementById('ts-tour-launcher');
	launcher.click();
	assert.equal(fixture.tour.getState().active, false);
	fixture.document.getElementById('ts-tour-menu-guide').click();
	await flush(); await flush();
	assert.equal(fixture.document.getElementById('ts-tour-help-menu').hidden, true);
	assert.equal(launcher.getAttribute('aria-expanded'), 'false');
	assert.equal(fixture.tour.getState().step, 'export');
	assert.equal(fixture.tour.getState().total, 9);
	await fixture.tour.end();
	assert.equal(fixture.document.activeElement, launcher);
	assert.equal(fixture.requests.length, 0);
});

test('the help menu supports keyboard navigation, Escape, Tab, outside clicks and focus leaving', () => {
	const fixture = createFixture();
	const launcher = fixture.document.getElementById('ts-tour-launcher');
	const key = value => fixture.dispatch(fixture.event('keydown', fixture.document.activeElement, { key: value }));
	launcher.focus();
	key('ArrowDown');
	const items = fixture.document.getElementById('ts-tour-help-menu').children;
	assert.equal(fixture.document.activeElement, items[0]);
	key('ArrowUp');
	assert.equal(fixture.document.activeElement, items[2]);
	key('ArrowDown');
	assert.equal(fixture.document.activeElement, items[0]);
	key('End');
	assert.equal(fixture.document.activeElement, items[2]);
	key('Home');
	assert.equal(fixture.document.activeElement, items[0]);
	key('Escape');
	assert.equal(launcher.getAttribute('aria-expanded'), 'false');
	assert.equal(fixture.document.activeElement, launcher);
	key('ArrowUp');
	assert.equal(fixture.document.activeElement, items[2]);
	const tab = key('Tab');
	assert.equal(tab.prevented, false);
	assert.equal(launcher.getAttribute('aria-expanded'), 'false');
	launcher.click();
	fixture.dispatch(fixture.event('pointerdown', fixture.app));
	assert.equal(launcher.getAttribute('aria-expanded'), 'false');
	launcher.click();
	fixture.document.getElementById('config-daily-min-mine').focus();
	assert.equal(launcher.getAttribute('aria-expanded'), 'false');
	assert.equal(fixture.document.activeElement.id, 'config-daily-min-mine');
	assert.equal(fixture.requests.length, 0);
});

test('the first-visit Tutorial still starts the tour directly while Later remains visit-local', async () => {
	const fixture = createFixture();
	const menu = fixture.document.getElementById('ts-tour-help-menu');
	assert.equal(menu.hidden, true);
	fixture.document.getElementById('ts-tour-start').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'export');
	assert.equal(menu.hidden, true);
	await fixture.tour.end();
	assert.equal(fixture.requests.length, 0);
	assert.ok(createFixture().document.getElementById('ts-tour-invitation'));
});

test('the open help menu remains inside the viewport and repositions on resize in both reading directions', () => {
	for (const direction of ['ltr', 'rtl']) {
		const fixture = createFixture({ viewportWidth: 320, viewportHeight: 568 });
		const launcher = fixture.document.getElementById('ts-tour-launcher');
		const menu = fixture.document.getElementById('ts-tour-help-menu');
		launcher.getBoundingClientRect = () => ({ left: 300, top: 520, right: 344, bottom: 564, width: 44, height: 44 });
		menu.getBoundingClientRect = () => ({ width: 280, height: 180 });
		const originalStyle = fixture.context.getComputedStyle;
		fixture.context.getComputedStyle = element => ({ ...originalStyle(element), direction });
		launcher.click();
		const initialLeft = parseFloat(menu.style.left);
		const initialTop = parseFloat(menu.style.top);
		assert.ok(initialLeft >= 12 && initialLeft + 280 <= 308);
		assert.ok(initialTop >= 12 && initialTop + 180 <= 556);
		fixture.resize(800, 600);
		assert.equal(menu.hidden, false);
		assert.equal(launcher.getAttribute('aria-expanded'), 'true');
		assert.ok(parseFloat(menu.style.left) >= 12 && parseFloat(menu.style.left) + 280 <= 788);
		assert.ok(parseFloat(menu.style.top) >= 12 && parseFloat(menu.style.top) + 180 <= 588);
		assert.notEqual(parseFloat(menu.style.left), initialLeft);
		assert.equal(fixture.requests.length, 0);
	}
});

test('the warning explanation highlights the empty warning cell and its heading before freelancer mode', async () => {
	const fixture = createFixture();
	const warningCell = fixture.document.querySelector('#ts-body tr:first-child .ts-warn');
	const warningHeading = fixture.document.querySelector('#ts-table thead th:nth-child(9)');
	warningHeading.getBoundingClientRect = () => ({ left: 500, top: 120, right: 640, bottom: 145, width: 140, height: 25 });
	warningCell.getBoundingClientRect = () => ({ left: 500, top: 155, right: 640, bottom: 180, width: 140, height: 25 });
	assert.equal(warningCell.textContent, '');
	assert.equal(warningHeading.textContent, 'Warning');
	await fixture.tour.start();
	await advanceToStep(fixture, 'comments');
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'warnings');
	assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'Time entry warnings');
	assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'Breaks that exceed or fall below the limits and missing timesheets trigger warnings and should be corrected promptly.');
	const frames = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	assert.equal(frames.length, 1);
	assert.equal(Number(frames[0].getAttribute('x')), 495);
	assert.equal(Number(frames[0].getAttribute('y')), 115);
	assert.equal(Number(frames[0].getAttribute('width')), 150);
	assert.equal(Number(frames[0].getAttribute('height')), 70);
	fixture.document.getElementById('ts-tour-back').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'comments');
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'warnings');
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'freelancer-mode');
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().step, 'profile');
	assert.equal(warningCell.textContent, '');
	assert.equal(fixture.requests.length, 0);
	await fixture.tour.end();
});

test('the far-right warning column becomes visible on narrow screens and restores horizontal scrolling', async () => {
	for (const columnLeft of [1000, -1000]) {
		const fixture = createFixture({ viewportWidth: 320, viewportHeight: 568 });
		const warningCell = fixture.document.querySelector('#ts-body tr:first-child .ts-warn');
		const warningHeading = fixture.document.querySelector('#ts-table thead th:nth-child(9)');
		for (const [control, top] of [[warningHeading, 100], [warningCell, 160]]) {
			control.getBoundingClientRect = () => ({ left: columnLeft - fixture.app.scrollLeft, right: columnLeft + 150 - fixture.app.scrollLeft, top, bottom: top + 30, width: 150, height: 30 });
		}
		await fixture.tour.start();
		await advanceToStep(fixture, 'warnings');
		for (const control of [warningHeading, warningCell]) {
			const rectangle = control.getBoundingClientRect();
			assert.ok(rectangle.left >= 8 && rectangle.right <= 312, 'Both the warning heading and its empty cell remain visible');
		}
		assert.equal(fixture.document.querySelectorAll('#ts-tour-cutout g rect').length, 2);
		assert.notEqual(fixture.app.scrollLeft, 0);
		assert.equal(warningCell.textContent, '');
		assert.equal(fixture.requests.length, 0);
		await fixture.tour.end();
		assert.equal(fixture.app.scrollLeft, 0);
	}
});

test('the warning row moves above the explanation when a short screen has no free side', async () => {
	const fixture = createFixture({ viewportWidth: 1000, viewportHeight: 400 });
	const warningCell = fixture.document.querySelector('#ts-body tr:first-child .ts-warn');
	const warningHeading = fixture.document.querySelector('#ts-table thead th:nth-child(9)');
	const startInput = fixture.document.querySelector('#ts-body tr:first-child .startTime');
	fixture.app.clientHeight = 350;
	fixture.app.scrollHeight = 1100;
	const originalStyle = fixture.context.getComputedStyle;
	fixture.context.getComputedStyle = element => ({ ...originalStyle(element), overflowY: element === fixture.app ? 'auto' : 'visible' });
	fixture.document.querySelector('#ts-table thead').getBoundingClientRect = () => ({ left: 0, right: 1000, top: 50, bottom: 74, width: 1000, height: 24 });
	startInput.getBoundingClientRect = () => ({ left: 100, right: 200, top: 200 - fixture.app.scrollTop, bottom: 230 - fixture.app.scrollTop, width: 100, height: 30 });
	warningHeading.getBoundingClientRect = () => ({ left: 300, right: 700, top: 150 - fixture.app.scrollTop, bottom: 176 - fixture.app.scrollTop, width: 400, height: 26 });
	warningCell.getBoundingClientRect = () => ({ left: 300, right: 700, top: 200 - fixture.app.scrollTop, bottom: 230 - fixture.app.scrollTop, width: 400, height: 30 });
	await fixture.tour.start();
	await advanceToStep(fixture, 'warnings');
	assert.ok(fixture.app.scrollTop > 0, 'The existing row-alignment helper also handles the warning step');
	const explanation = fixture.document.getElementById('ts-tour-dialog');
	const explanationTop = parseFloat(explanation.style.top);
	for (const frame of fixture.document.querySelectorAll('#ts-tour-cutout g rect')) {
		assert.ok(Number(frame.getAttribute('y')) + Number(frame.getAttribute('height')) < explanationTop);
	}
	assert.equal(fixture.requests.length, 0);
	await fixture.tour.end();
	assert.equal(fixture.app.scrollTop, 0);
});

test('the employee tour explains nine sections in the requested order', async () => {
	const fixture = createFixture();
	await fixture.tour.start();
	const visitedSteps = [];
	for (let index = 0; index < fixture.tour.getState().total; index++) {
		visitedSteps.push(fixture.tour.getState().step);
		assert.equal(fixture.document.getElementById('ts-tour-progress').textContent, `Step ${index + 1} of 9`);
		if (index < fixture.tour.getState().total - 1) {
			fixture.document.getElementById('ts-tour-next').click();
			await flush(); await flush();
		}
	}
	assert.deepEqual(visitedSteps, ['export', 'working-time', 'federal-state', 'comments', 'warnings', 'freelancer-mode', 'profile', 'help', 'tour-access']);
	assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'Help and tour');
	assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'Questions and suggestions for HR. The guide can be restarted using the tour icon.');
	assert.equal(fixture.requests.length, 0);
	await fixture.tour.end();
});

test('freelancer mode highlights the complete switch without changing either saved state', async () => {
	for (const freelancerEnabled of [false, true]) {
		const fixture = createFixture({ freelancerEnabled });
		const group = fixture.document.querySelector('#tab-mine .ts-employment-toggle');
		const control = fixture.document.getElementById('freelancer-mode-switch');
		group.getBoundingClientRect = () => ({ left: 220, top: 200, right: 620, bottom: 244, width: 400, height: 44 });
		let changed = 0;
		control.addEventListener('click', () => { changed++; control.checked = !control.checked; });
		control.addEventListener('change', () => { changed++; });
		await fixture.tour.start();
		await advanceToStep(fixture, 'freelancer-mode');
		assert.equal(fixture.tour.getState().index, 5, 'Freelancer mode immediately follows the fifth warning step');
		assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'Freelancer mode');
		assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'Freelancer mode disables warning emails about missing or incomplete entries, overtime and negative balances for the employee and HR.');
		assert.equal(fixture.document.getElementById('ts-tour-progress').textContent, 'Step 6 of 9');
		const holes = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
		assert.equal(holes.length, 1, 'The label and both switch states share one highlight');
		assert.deepEqual(['x', 'y', 'width', 'height'].map(attribute => Number(holes[0].getAttribute(attribute))), [215, 195, 410, 54]);
		for (const name of ['pointerdown', 'click']) {
			const event = fixture.dispatch(fixture.event(name, control));
			assert.equal(event.prevented, true, 'Highlighted controls remain read-only during the tour');
		}
		assert.equal(changed, 0);
		assert.equal(control.checked, freelancerEnabled);
		assert.equal(control.getAttribute('aria-checked'), String(freelancerEnabled));
		assert.equal(fixture.requests.length, 0);
		fixture.document.getElementById('ts-tour-back').click();
		await flush(); await flush();
		assert.equal(fixture.tour.getState().step, 'warnings');
		await advanceToStep(fixture, 'profile');
		await fixture.tour.end();
		assert.equal(control.checked, freelancerEnabled);
		assert.equal(changed, 0);
		assert.equal(fixture.requests.length, 0);
	}
});

test('a missing or hidden freelancer switch omits only its optional tour step', async () => {
	for (const hidden of [false, true]) {
		const fixture = createFixture({ freelancerControl: hidden });
		if (hidden) fixture.document.querySelector('#tab-mine .ts-employment-toggle').hidden = true;
		await fixture.tour.start();
		assert.equal(fixture.tour.getState().total, 8);
		await advanceToStep(fixture, 'warnings');
		fixture.document.getElementById('ts-tour-next').click();
		await flush(); await flush();
		assert.equal(fixture.tour.getState().step, 'profile');
		assert.equal(fixture.document.getElementById('ts-tour-progress').textContent, 'Step 6 of 8');
		await fixture.tour.end();
		assert.equal(fixture.requests.length, 0);
	}
});

test('export uses one continuous frame around the button and both export choices', async () => {
	const fixture = createFixture();
	const button = fixture.document.getElementById('export-mine-xlsx');
	const choices = fixture.element('span', 'export-mine-xlsx-choice-ui', '', button.parentElement);
	button.getBoundingClientRect = () => ({ left: 100, top: 100, right: 180, bottom: 140, width: 80, height: 40 });
	choices.getBoundingClientRect = () => ({ left: 220, top: 100, right: 480, bottom: 140, width: 260, height: 40 });
	await fixture.tour.start();
	assert.equal(fixture.tour.getState().step, 'export');
	assert.equal(choices.classList.contains('is-visible'), true);
	const holes = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	const frames = fixture.document.querySelectorAll('.ts-tour-highlight rect');
	assert.equal(holes.length, 1);
	assert.equal(frames.length, 1);
	assert.equal(Number(holes[0].getAttribute('x')), 95);
	assert.equal(Number(holes[0].getAttribute('y')), 95);
	assert.equal(Number(holes[0].getAttribute('width')), 390);
	assert.equal(Number(holes[0].getAttribute('height')), 50);
	await fixture.tour.end();
	assert.equal(choices.classList.contains('is-visible'), false);
});

test('the export frame includes controls just below the fixed header', async () => {
	const fixture = createFixture();
	const button = fixture.document.getElementById('export-mine-xlsx');
	const choices = fixture.element('span', 'export-mine-xlsx-choice-ui', '', button.parentElement);
	button.getBoundingClientRect = () => ({ left: 392, top: 54, right: 457, bottom: 88, width: 65, height: 34 });
	choices.getBoundingClientRect = () => ({ left: 468, top: 51, right: 745, bottom: 91, width: 277, height: 40 });
	await fixture.tour.start();
	const frame = fixture.document.querySelector('#ts-tour-cutout g rect');
	const frameTop = Number(frame.getAttribute('y'));
	assert.ok(frameTop >= 50, 'The highlight stays below the fixed header');
	assert.ok(frameTop <= 51, 'The highlight includes the top of the open export choices');
	assert.ok(frameTop + Number(frame.getAttribute('height')) >= 91);
	await fixture.tour.end();
});

test('the combined export highlight fits narrow left-to-right and right-to-left scroll containers', async () => {
	for (const coordinates of [[50, 130], [140, -20]]) {
		const fixture = createFixture({ viewportWidth: 320, viewportHeight: 568 });
		const button = fixture.document.getElementById('export-mine-xlsx');
		const choices = fixture.element('span', 'export-mine-xlsx-choice-ui', '', button.parentElement);
		[button, choices].forEach((control, index) => {
			const width = index === 0 ? 70 : 150;
			control.getBoundingClientRect = () => ({ left: coordinates[index] - fixture.app.scrollLeft, right: coordinates[index] + width - fixture.app.scrollLeft, top: 100, bottom: 140, width, height: 40 });
		});
		await fixture.tour.start();
		assert.equal(fixture.app.classList.contains('ts-tour-export-preview'), true);
		const holes = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
		assert.equal(holes.length, 1);
		for (const control of [button, choices]) {
			const rectangle = control.getBoundingClientRect();
			assert.ok(rectangle.left >= 8 && rectangle.right <= 312, 'The export button and choices remain fully visible');
		}
		const frame = holes[0];
		assert.ok(Number(frame.getAttribute('x')) >= 4);
		assert.ok(Number(frame.getAttribute('x')) + Number(frame.getAttribute('width')) <= 316);
		const explanation = fixture.document.getElementById('ts-tour-dialog');
		assert.ok(parseFloat(explanation.style.top) >= 159, 'The explanation stays below the complete export frame');
		await fixture.tour.end();
		assert.equal(fixture.app.classList.contains('ts-tour-export-preview'), false);
		assert.equal(fixture.app.scrollLeft, 0);
	}
});

test('working time and federal state each highlight only their own field and label', async () => {
	const fixture = createFixture();
	const targetGroups = [
		['config-daily-min-mine', 30, 100],
		['config-state-mine', 400, 200],
	];
	for (const [fieldId, left, top] of targetGroups) {
		const label = fixture.document.querySelector(`label[for="${fieldId}"]`);
		const field = fixture.document.getElementById(fieldId);
		label.getBoundingClientRect = () => ({ left, right: left + 130, top, bottom: top + 40, width: 130, height: 40 });
		field.getBoundingClientRect = () => ({ left: left + 140, right: left + 220, top, bottom: top + 40, width: 80, height: 40 });
	}
	await fixture.tour.start();
	await advanceToStep(fixture, 'working-time');
	assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'Working time');
	assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'The start of work can be chosen individually in accordance with company guidelines.');
	let frame = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	assert.equal(frame.length, 1);
	assert.equal(Number(frame[0].getAttribute('x')), 25);
	assert.equal(Number(frame[0].getAttribute('y')), 95);
	assert.equal(Number(frame[0].getAttribute('width')), 230);
	await advanceToStep(fixture, 'federal-state');
	assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'Federal state');
	assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'The public holidays shown in the Status column depend on the selected federal state.');
	frame = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	assert.equal(frame.length, 1);
	assert.equal(Number(frame[0].getAttribute('x')), 395);
	assert.equal(Number(frame[0].getAttribute('y')), 195);
	assert.equal(Number(frame[0].getAttribute('width')), 230);
	assert.equal(fixture.requests.length, 0);
	await fixture.tour.end();
});

test('the final launcher step closes the profile and ending restores unsaved fields, export and navigation', async () => {
	for (const initiallyOpen of [false, true]) {
		const fixture = createFixture();
		const profile = fixture.document.querySelector('#user-menu .header-menu__trigger');
		const launcher = fixture.document.getElementById('ts-tour-launcher');
		launcher.getBoundingClientRect = () => ({ left: 700, right: 744, top: 10, bottom: 50, width: 44, height: 40 });
		if (initiallyOpen) profile.click();
		if (initiallyOpen) fixture.context.Timesheet.export.handleExportClick('mine');
		const fields = ['#config-daily-min-mine', '#config-state-mine', '#ts-body .startTime', '#ts-body .breakMinutes', '#ts-body .endTime', '#ts-body .commentInput'].map(selector => fixture.document.querySelector(selector));
		const values = ['06:45', 'HE', '08:17', '23', '17:42', 'Unsaved comment'];
		fields.forEach((field, index) => { field.value = values[index]; });
		fields.at(-1).focus();
		fixture.app.scrollTop = 83;
		fixture.app.scrollLeft = 14;
		await fixture.tour.start();
		const choices = fixture.document.getElementById('export-mine-xlsx-choice-ui');
		assert.equal(choices.classList.contains('is-visible'), true);
		await advanceToStep(fixture, 'working-time');
		assert.equal(choices.classList.contains('is-visible'), false);
		assert.equal(fixture.app.classList.contains('ts-tour-export-preview'), false);
		fixture.document.getElementById('ts-tour-back').click();
		await flush(); await flush();
		assert.equal(fixture.tour.getState().step, 'export');
		assert.equal(choices.classList.contains('is-visible'), true);
		await advanceToStep(fixture, 'help');
		assert.equal(profile.getAttribute('aria-expanded'), 'true');
		assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'Questions, help and privacy information about Nextcloud in general.');
		await advanceToEnd(fixture);
		assert.equal(profile.getAttribute('aria-expanded'), 'false');
		const frame = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
		assert.equal(frame.length, 1);
		assert.equal(Number(frame[0].getAttribute('x')), 695);
		assert.equal(Number(frame[0].getAttribute('y')), 5);
		assert.equal(Number(frame[0].getAttribute('width')), 54);
		assert.deepEqual(fields.map(field => field.value), values);
		await fixture.tour.end();
		assert.equal(profile.getAttribute('aria-expanded'), String(initiallyOpen));
		assert.equal(choices.classList.contains('is-visible'), initiallyOpen);
		assert.equal(fixture.app.classList.contains('ts-tour-export-preview'), false);
		assert.deepEqual(fields.map(field => field.value), values);
		assert.equal(fixture.document.activeElement, fields.at(-1));
		assert.equal(fixture.app.scrollTop, 83);
		assert.equal(fixture.app.scrollLeft, 14);
		assert.equal(fixture.context.scrollX, 5);
		assert.equal(fixture.context.scrollY, 45);
		assert.equal(fixture.requests.length, 0);
	}
});

test('absence comment guidance uses shared account-language terms rather than fixed German labels', async () => {
	for (const absenceTerms of [
		{ vacation: 'Vacation', timeOffInLieu: 'Time off in lieu' },
		{ vacation: 'Congés annuels', timeOffInLieu: 'Repos compensateur' },
		{ vacation: '年次休暇', timeOffInLieu: '代休' },
	]) {
		const fixture = createFixture({ absenceTerms });
		await fixture.tour.start();
		while (fixture.tour.getState().step !== 'comments') {
			fixture.document.getElementById('ts-tour-next').click();
			await flush(); await flush();
		}
		const description = fixture.document.getElementById('ts-tour-description').textContent;
		assert.ok(description.includes(absenceTerms.vacation), description);
		assert.ok(description.includes(absenceTerms.timeOffInLieu), description);
		assert.doesNotMatch(description, /Urlaub|Stundenausgleich|\{vacation\}|\{timeOffInLieu\}/u);
		assert.doesNotMatch(description, /approval|warning/u);
		assert.equal(fixture.requests.length, 0);
		await fixture.tour.end();
	}
});

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
	assert.equal(completed.tour.getState().step, 'export');
	assert.equal(completed.tour.getState().total, 9);
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

test('ending the tour redraws a confirmed employment save that completed while the tour was open', async () => {
	const fixture = createFixture();
	const switchInput = fixture.element('input', 'freelancer-mode-switch', '', fixture.app);
	let confirmedMode = false;
	let refreshCount = 0;
	fixture.context.Timesheet.employmentStatus = { refresh() { refreshCount++; switchInput.checked = confirmedMode; } };
	await fixture.tour.start();
	confirmedMode = true;
	switchInput.checked = true;
	await fixture.tour.end();
	assert.equal(switchInput.checked, true, 'The old tour snapshot cannot replace a newly confirmed preference');
	assert.equal(refreshCount, 1);
	assert.equal(fixture.requests.length, 0);
});

test('employee completion writes only tour progress and preserves account-language text', async () => {
	const fixture = createFixture({ translate: text => `translated:${text}` });
	await fixture.tour.start();
	assert.equal(fixture.document.getElementById('ts-tour-title').textContent, 'translated:Month and year export');
	await advanceToEnd(fixture);
	assert.equal(fixture.tour.getState().step, 'tour-access');
	assert.equal(fixture.document.getElementById('ts-tour-dialog').dataset.stepId, 'tour-access');
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().active, false);
	assert.equal(fixture.tour.getState().employeeCompleted, true);
	assert.deepEqual(fixture.requests, [{ path: '/api/preferences/tour/complete', method: 'POST', body: { version: 1 } }]);
	assert.equal(fixture.copyCounts.entryLoads, 0);
	assert.equal(fixture.document.getElementById('export-mine-xlsx-choice-ui').classList.contains('is-visible'), false);
});

test('employee tours never load HR data even when unrelated HR markup is present', async () => {
	const fixture = createFixture({ hrDom: true });
	const originalTitle = fixture.document.querySelector('#hr-user-title span').textContent;
	await fixture.tour.start();
	assert.equal(fixture.tour.getState().total, 9);
	await advanceToEnd(fixture);
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().employeeCompleted, true);
	assert.equal(fixture.document.querySelector('#hr-user-title span').textContent, originalTitle);
	assert.equal(fixture.requests.some(request => request.path.startsWith('/api/hr/')), false);
	assert.equal('hrCompleted' in fixture.tour.getState(), false);
	assert.equal('isHr' in fixture.tour.getState(), false);
	assert.deepEqual(fixture.requests.map(request => request.body), [{ version: 1 }]);
});

test('hidden Nextcloud help has an explicit explanation in the employee tour', async () => {
	const fixture = createFixture({ helpPresent: false });
	await fixture.tour.start();
	await advanceToStep(fixture, 'help');
	assert.equal(fixture.tour.getState().step, 'help');
	assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'General help is hidden on this Nextcloud instance.');
	assert.equal(fixture.requests.length, 0);
	await fixture.tour.end();
});

test('a role change rejected by the server never records employee completion', async () => {
	const fixture = createFixture({ respond(request) { if (request.method === 'POST') throw new Error('{"code":"tour_unavailable"}'); } });
	await fixture.tour.start();
	await advanceToEnd(fixture);
	fixture.document.getElementById('ts-tour-next').click();
	await flush(); await flush();
	assert.equal(fixture.tour.getState().employeeCompleted, false);
	assert.equal(fixture.tour.getState().active, false);
	assert.match(fixture.messages.at(-1), /completion could not be saved/);
	assert.deepEqual(fixture.requests.map(request => request.body), [{ version: 1 }]);
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
	assert.equal(button.getAttribute('aria-label'), 'Help and tour');
	await fixture.tour.start();
	await advanceToStep(fixture, 'help');
	assert.match(fixture.document.getElementById('ts-tour-description').textContent, /^General help is hidden/);
	await advanceToEnd(fixture);
	assert.equal(fixture.document.getElementById('ts-tour-description').textContent, 'Questions and suggestions for HR. The guide can be restarted using the tour icon.');
	await fixture.tour.end();
});

test('HR accounts receive neither a first invitation nor manual access, regardless of employee completion', async () => {
	for (const employeeCompleted of [false, true]) {
		const fixture = createFixture({ hr: true, employeeCompleted });
		assert.equal(fixture.document.getElementById('ts-tour-launcher'), null);
		assert.equal(fixture.document.getElementById('ts-tour-invitation'), null);
		assert.equal(fixture.document.getElementById('ts-tour-help-menu'), null);
		await fixture.tour.start();
		assert.equal(fixture.tour.getState().active, false);
		assert.equal(fixture.document.getElementById('ts-tour-overlay'), null);
		assert.equal(fixture.requests.length, 0);
	}
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
	assert.equal(fixture.tour.getState().step, 'export');
	resolveOldRequest({ version: 1, employeeCompleted: true, hrCompleted: false, isHr: false });
	await flush(); await flush();
	assert.equal(fixture.tour.getState().active, true);
	assert.equal(fixture.tour.getState().step, 'export');
	assert.equal(fixture.tour.getState().employeeCompleted, false);
	assert.equal(fixture.messages.length, 0);
	await fixture.tour.end();
});

test('working-time labels and fields fit narrow left-to-right and right-to-left scroll containers', async () => {
	for (const coordinates of [[50, 190], [140, 10]]) {
		const fixture = createFixture({ viewportWidth: 320 });
		const fields = [fixture.document.querySelector('label[for="config-daily-min-mine"]'), fixture.document.getElementById('config-daily-min-mine')];
		fields.forEach((field, index) => {
			const width = index === 0 ? 130 : 80;
			field.getBoundingClientRect = () => ({ left: coordinates[index] - fixture.app.scrollLeft, right: coordinates[index] + width - fixture.app.scrollLeft, top: 160, bottom: 190, width, height: 30 });
		});
		await fixture.tour.start();
		await advanceToStep(fixture, 'working-time');
		for (const field of fields) {
			const rectangle = field.getBoundingClientRect();
			assert.ok(rectangle.left >= 8 && rectangle.right <= 312, 'The working-time field and its label remain visible together');
		}
		await fixture.tour.end();
		assert.equal(fixture.app.scrollLeft, 0);
	}
});

test('application highlights stop below the fixed header', async () => {
	const fixture = createFixture();
	const stateSelect = fixture.document.getElementById('config-state-mine');
	stateSelect.getBoundingClientRect = () => ({ left: 10, right: 400, top: -20, bottom: 150, width: 390, height: 170 });
	await fixture.tour.start();
	await advanceToStep(fixture, 'federal-state');
	const configHole = fixture.document.querySelector('#ts-tour-cutout g rect');
	assert.ok(Number(configHole.getAttribute('y')) >= 50, 'The application spotlight does not brighten the fixed header');
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
	const fixture = createFixture({ enabled: false });
	assert.equal(fixture.document.getElementById('ts-tour-launcher'), null);
	assert.equal(fixture.document.getElementById('ts-tour-invitation'), null);
	assert.equal(fixture.document.getElementById('ts-tour-help-menu'), null);
	await fixture.tour.start();
	assert.equal(fixture.document.getElementById('ts-tour-overlay'), null);
	assert.equal(fixture.requests.length, 0);
});



test('adjacent working-time labels and fields share one frame instead of overlapping padded boxes', async () => {
	const fixture = createFixture();
	const fields = [fixture.document.querySelector('label[for="config-daily-min-mine"]'), fixture.document.getElementById('config-daily-min-mine')];
	fields.forEach((field, index) => {
		const left = 300 + index * 100;
		field.getBoundingClientRect = () => ({ left, right: left + 100, top: 180, bottom: 210, width: 100, height: 30 });
	});
	await fixture.tour.start();
	await advanceToStep(fixture, 'working-time');
	const holes = fixture.document.querySelectorAll('#ts-tour-cutout g rect');
	assert.equal(holes.length, 1);
	assert.equal(Number(holes[0].getAttribute('x')), 295);
	assert.equal(Number(holes[0].getAttribute('width')), 210);
	await fixture.tour.end();
});

test('the explanation uses free horizontal space when a short viewport cannot fit it above or below', async () => {
	const fixture = createFixture({ viewportWidth: 1000, viewportHeight: 400 });
	const fields = [fixture.document.querySelector('label[for="config-daily-min-mine"]'), fixture.document.getElementById('config-daily-min-mine')];
	fields.forEach((field, index) => {
		const left = 300 + index * 90;
		field.getBoundingClientRect = () => ({ left, right: left + 90, top: 150, bottom: 220, width: 90, height: 70 });
	});
	await fixture.tour.start();
	await advanceToStep(fixture, 'working-time');
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
