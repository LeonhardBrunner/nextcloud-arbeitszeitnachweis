import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const source = readFileSync(resolve(root, 'js/timesheet-update-notes.js'), 'utf8');
const mainSource = readFileSync(resolve(root, 'js/timesheet-main.js'), 'utf8');
const flush = () => new Promise(setImmediate);
const copy = value => JSON.parse(JSON.stringify(value));
const notes = {
	enabled: true, shouldShow: true, throughVersion: '1.3.0',
	releases: [{ version: '1.3.0', sections: [{ title: 'New features', items: ['Freelancer mode is available.'] }] }],
};

// Real module behavior runs against controlled DOM, visibility and transport boundaries.
function fixture({ enabled = true, url = 'http://localhost:8133/apps/timesheet/', payload = notes, respond, hidden = false, translate = message => message, failAppend = false } = {}) {
	const requests = [];
	const messages = [];
	const warnings = [];
	const frames = new Map();
	const events = new Map();
	let document;
	let frameNumber = 0;
	class Element {
		constructor(tag) {
			this.tagName = tag.toUpperCase(); this.id = ''; this.className = ''; this.children = [];
			this.parentElement = null; this.dataset = {}; this.attributes = new Map(); this.listeners = new Map();
			this.style = {}; this.inert = false; this._text = '';
		}
		set textContent(value) { this._text = String(value); this.children = []; }
		get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
		set innerHTML(_value) { throw new Error('Update content must not be parsed as HTML.'); }
		get isConnected() { return this === document.body || !!this.parentElement?.isConnected; }
		append(...children) {
			if (failAppend && this === document.body && children.some(child => child.className === 'ts-update-notes-backdrop')) throw new Error('Synthetic append failure');
			for (const child of children) { this.children.push(child); child.parentElement = this; }
		}
		remove() { if (this.parentElement) { const parent = this.parentElement; parent.children = parent.children.filter(child => child !== this); this.parentElement = null; } }
		contains(target) { return this === target || this.children.some(child => child.contains(target)); }
		setAttribute(name, value) { this.attributes.set(name, String(value)); }
		getAttribute(name) { return this.attributes.get(name) ?? null; }
		addEventListener(name, callback) { this.listeners.set(name, [...(this.listeners.get(name) || []), callback]); }
		focus() {
			document.activeElement = this;
			for (const callback of events.get('focusin') || []) callback({ target: this });
		}
		getClientRects() { return this.isConnected ? [{}] : []; }
	}
	const descendants = element => element.children.flatMap(child => [child, ...descendants(child)]);
	document = {
		visibilityState: hidden ? 'hidden' : 'visible', activeElement: null,
		createElement: tag => new Element(tag), createElementNS: (_namespace, tag) => new Element(tag),
		getElementById: id => [document.body, ...descendants(document.body)].find(element => element.id === id) || null,
		querySelectorAll: selector => selector === 'textarea.commentInput' ? [] : [],
		querySelector: () => null,
		addEventListener(name, callback) { events.set(name, [...(events.get(name) || []), callback]); },
		removeEventListener(name, callback) { events.set(name, (events.get(name) || []).filter(listener => listener !== callback)); },
	};
	document.body = new Element('body'); document.body.style.overflow = 'auto';
	const header = new Element('header');
	const preblocked = new Element('aside'); preblocked.inert = true;
	const app = new Element('section'); app.id = 'timesheet-app'; app.dataset.updateNotesEnabled = String(enabled);
	const month = new Element('button'); month.id = 'month-display'; app.append(month);
	document.body.append(header, preblocked, app); document.activeElement = month;
	const location = { href: url };
	const replacements = [];
	const historyState = { retained: 'state' };
	const context = vm.createContext({
		document, URL, location,
		history: { state: historyState, replaceState(state, title, href) { replacements.push({ state, title, href }); location.href = href; } },
		requestAnimationFrame(callback) { const id = ++frameNumber; frames.set(id, callback); return id; },
		cancelAnimationFrame(id) { frames.delete(id); },
		t: (_app, key, parameters = {}) => Object.entries(parameters).reduce((text, [name, value]) => text.replace(`{${name}}`, value), translate(key)),
		console: { warn: (...warning) => warnings.push(warning) },
		Timesheet: {
			api: async (path, options) => {
				const request = { path, method: options.method, body: JSON.parse(options.body) };
				requests.push(request);
				if (respond) { const response = await respond(request); if (response !== undefined) return response; }
				return path.endsWith('/shown') ? { throughVersion: payload.throughVersion } : copy(payload);
			},
			notify: message => messages.push(message),
		},
	});
	context.window = context;
	vm.runInContext(source, context);
	return {
		context, document, header, preblocked, app, month, requests, messages, warnings, replacements,
		init: () => context.Timesheet.updateNotes.init(),
		get: id => document.getElementById(id),
		all: className => descendants(document.body).filter(element => element.className === className),
		async paint() { const callbacks = [...frames.values()]; frames.clear(); for (const callback of callbacks) callback(); await flush(); },
		click(element) { for (const callback of element.listeners.get('click') || []) callback({ target: element }); },
		key(key, shiftKey = false) {
			const event = { key, shiftKey, prevented: false, preventDefault() { this.prevented = true; }, stopPropagation() {} };
			for (const callback of [...(events.get('keydown') || [])]) callback(event);
			return event;
		},
		visibility(value) { document.visibilityState = value; for (const callback of events.get('visibilitychange') || []) callback(); },
	};
}

test('the automatic modal is opened once, confirmed after painting and leaves the bell untouched on close', async () => {
	const app = fixture();
	const complete = app.init();
	assert.equal(app.init(), complete);
	await flush();
	assert.equal(app.requests.length, 1);
	assert.equal(app.requests[0].body.mode, 'automatic');
	assert.ok(app.get('ts-update-notes-dialog'));
	assert.equal(app.all('ts-update-notes-close').length, 0);
	assert.equal(app.all('ts-update-notes-action').length, 1);
	assert.equal(app.header.inert, true);
	assert.equal(app.app.inert, true);
	assert.equal(app.document.body.style.overflow, 'hidden');
	await app.paint();
	assert.deepEqual(app.requests[1], { path: '/api/update-notes/shown', method: 'POST', body: { throughVersion: '1.3.0' } });
	app.click(app.all('ts-update-notes-action')[0]);
	await complete;
	assert.equal(app.get('ts-update-notes-dialog'), null);
	assert.equal(app.header.inert, false);
	assert.equal(app.app.inert, false);
	assert.equal(app.preblocked.inert, true);
	assert.equal(app.document.body.style.overflow, 'auto');
	assert.equal(app.document.activeElement, app.month);
	assert.equal(app.requests.length, 2);
	await app.init();
	assert.equal(app.requests.length, 2);
});

test('ordinary page visits without new notes do not show or confirm a popup', async () => {
	const app = fixture({ payload: { ...notes, shouldShow: false } });
	await app.init();
	assert.equal(app.get('ts-update-notes-dialog'), null);
	assert.equal(app.requests.length, 1);
});

test('an ineligible page never requests or renders update notes', async () => {
	const app = fixture({ enabled: false });
	await app.init();
	assert.deepEqual(app.requests, []);
	assert.equal(app.get('ts-update-notes-dialog'), null);
});

test('manual bell navigation reopens notes and removes only its own URL parameter', async () => {
	const app = fixture({ url: 'http://localhost:8133/apps/timesheet/?other=keep&update-notes=1#hr', payload: { ...notes, shouldShow: false } });
	const complete = app.init();
	await flush();
	assert.equal(app.requests[0].body.mode, 'manual');
	assert.equal(app.replacements[0].href, 'http://localhost:8133/apps/timesheet/?other=keep#hr');
	assert.equal(app.replacements[0].state.retained, 'state');
	assert.ok(app.get('ts-update-notes-dialog'));
	app.click(app.all('ts-update-notes-action')[0]);
	await complete;
});

test('manual transport failure has a visible message and automatic failure stays nonblocking', async () => {
	for (const manual of [false, true]) {
		const app = fixture({ url: 'http://localhost:8133/apps/timesheet/' + (manual ? '?update-notes=1' : ''), respond: () => { throw new Error('Synthetic transport failure'); } });
		await app.init();
		assert.equal(app.messages.length, manual ? 1 : 0);
		assert.equal(app.get('ts-update-notes-dialog'), null);
		assert.equal(app.header.inert, false);
		assert.equal(app.requests.length, 1);
	}
});

test('malformed release data does not create a partial popup or a false display confirmation', async () => {
	for (const payload of [null, {}, { ...notes, throughVersion: null }, { ...notes, releases: [{ version: '1.3.0', sections: [null] }] }]) {
		const app = fixture({ payload });
		await app.init();
		assert.equal(app.get('ts-update-notes-dialog'), null);
		assert.equal(app.requests.length, 1);
	}
});

test('empty and explicitly disabled responses do not open the dialog', async () => {
	for (const payload of [{ enabled: true, shouldShow: false, throughVersion: null, releases: [] }, { ...notes, enabled: false }]) {
		const app = fixture({ payload });
		await app.init();
		assert.equal(app.get('ts-update-notes-dialog'), null);
	}
});

test('release text is literal text and account translations cover the surrounding controls', async () => {
	const payload = { ...notes, releases: [{ version: '1.3.0', sections: [{ title: 'Server-translated heading', items: ['<img src=x onerror=alert(1)>'] }] }] };
	const app = fixture({ payload, translate: message => ({ "What's new in Timesheet?": 'Neues im Arbeitszeitnachweis', 'Close': 'Schließen' }[message] || message) });
	const complete = app.init(); await flush();
	const dialog = app.get('ts-update-notes-dialog');
	assert.equal(app.get('ts-update-notes-title').textContent, 'Neues im Arbeitszeitnachweis');
	assert.ok(dialog.textContent.includes('<img src=x onerror=alert(1)>'));
	assert.ok(dialog.textContent.includes('Server-translated heading'));
	assert.equal(app.all('ts-update-notes-action')[0].textContent, 'Schließen');
	app.key('Escape'); await complete;
});

test('a hidden tab confirms only when the displayed dialog becomes visible', async () => {
	const app = fixture({ hidden: true });
	const complete = app.init(); await flush(); await app.paint();
	assert.equal(app.requests.length, 1);
	app.visibility('visible'); await flush();
	assert.equal(app.requests.length, 2);
	app.visibility('visible'); await flush();
	assert.equal(app.requests.length, 2);
	app.key('Escape'); await complete;
});

test('Escape before a visible render never writes a display confirmation', async () => {
	const app = fixture(); const complete = app.init(); await flush();
	assert.equal(app.key('Escape').prevented, true);
	await app.paint(); await complete;
	assert.equal(app.requests.length, 1);
	assert.equal(app.header.inert, false);
});

test('the heading receives initial focus, the single close control traps Tab, and Escape restores the page', async () => {
	const app = fixture(); const complete = app.init(); await flush();
	const close = app.all('ts-update-notes-action')[0];
	const heading = app.get('ts-update-notes-title');
	assert.equal(app.all('ts-update-notes-close').length, 0);
	assert.equal(heading.getAttribute('tabindex'), '-1');
	assert.equal(app.document.activeElement, heading);
	assert.equal(app.key('Tab', true).prevented, true);
	assert.equal(app.document.activeElement, close);
	heading.focus();
	assert.equal(app.key('Tab').prevented, true);
	assert.equal(app.document.activeElement, close);
	assert.equal(app.key('Tab', true).prevented, true);
	assert.equal(app.document.activeElement, close);
	app.month.focus();
	assert.equal(app.document.activeElement, close);
	app.key('Escape'); await complete;
	app.month.focus();
	assert.equal(app.document.activeElement, app.month);
});

test('a failed shown write leaves the popup usable and does not attempt to dismiss the bell', async () => {
	const app = fixture({ respond: request => { if (request.path.endsWith('/shown')) throw new Error('Synthetic save failure'); } });
	const complete = app.init(); await flush(); await app.paint();
	assert.ok(app.get('ts-update-notes-dialog'));
	assert.equal(app.warnings.length, 1);
	app.click(app.all('ts-update-notes-action')[0]); await complete;
	assert.equal(app.requests.length, 2);
});

test('a rendering failure restores the page without falsely recording a displayed release', async () => {
	const app = fixture({ failAppend: true });
	await app.init();
	assert.equal(app.get('ts-update-notes-dialog'), null);
	assert.equal(app.header.inert, false);
	assert.equal(app.document.body.style.overflow, 'auto');
	assert.equal(app.requests.length, 1);
});

test('automatic and manually reopened notes show the newest release first without changing progress', async () => {
	for (const mode of ['automatic', 'manual']) {
		const payload = copy(notes);
		payload.releases.push(
			{ version: '1.3.1', sections: [{ title: 'Improvements', items: ['Second release.'] }] },
			{ version: '1.4.0', sections: [{ title: 'Bug fixes', items: ['Third release.'] }] },
		);
		payload.throughVersion = '1.4.0';
		const app = fixture({ payload,
			url: 'http://localhost:8133/apps/timesheet/' + (mode === 'manual' ? '?update-notes=1' : ''),
			respond: request => request.path === '/api/update-notes' ? payload : undefined,
		});
		const complete = app.init(); await flush(); await app.paint();
		assert.deepEqual(app.all('ts-update-notes-release').map(section => section.children[0].textContent), ['Version 1.4.0', 'Version 1.3.1', 'Version 1.3.0']);
		assert.deepEqual(payload.releases.map(release => release.version), ['1.3.0', '1.3.1', '1.4.0']);
		assert.equal(app.requests[0].body.mode, mode);
		assert.equal(app.requests[1].body.throughVersion, '1.4.0');
		app.key('Escape'); await complete;
	}
});

test('real application startup finishes update notes before offering the tour', async () => {
	const app = fixture();
	const context = app.context;
	const sequence = [];
	context.document.readyState = 'complete';
	context.document.querySelectorAll = () => [];
	context.Element = class {}; context.HTMLElement = context.Element;
	context.addEventListener = () => {};
	const timesheet = context.Timesheet;
	Object.assign(timesheet, {
		state: { currentUserId: 'employee', currentMonth: new Date(), hrCurrentMonth: new Date() },
		util: { monthLabel: () => 'Current month' }, $: () => null, $$: () => [],
		dom: { refresh() {} }, copy: { init() {} }, hr: { init() {} },
		config: { init() {}, async loadUserConfig() {} },
		entries: { async loadRuleThresholds() {}, async loadUserEntries() { sequence.push('entries'); }, async refreshOvertimeTotal() {} },
		tour: { init() { sequence.push('tour'); } },
	});
	vm.runInContext(mainSource, context);
	await flush();
	assert.deepEqual(sequence, ['entries']);
	assert.ok(app.get('ts-update-notes-dialog'));
	assert.ok(app.all('ts-update-notes-backdrop').length === 1);
	app.click(app.all('ts-update-notes-action')[0]);
	await flush();
	assert.deepEqual(sequence, ['entries', 'tour']);
});
