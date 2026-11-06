import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const historySource = readFileSync(resolve(root, 'js/timesheet-change-history.js'), 'utf8');
const flush = () => new Promise(setImmediate);
const copy = value => JSON.parse(JSON.stringify(value));
const preferenceRevision = number => number.toString(16).padStart(64, '0');

function createElementClass() {
	return class Element {
		constructor(tagName = 'div') {
			this.tagName = tagName.toUpperCase(); this.children = []; this.parentElement = null;
			this.className = ''; this.dataset = {}; this.attributes = new Map(); this.listeners = new Map();
			this.textContent = ''; this.value = ''; this.hidden = false; this.disabled = false;
			this.classList = { toggle: (name, active) => { const classes = new Set(this.className.split(/\s+/).filter(Boolean)); if (active) classes.add(name); else classes.delete(name); this.className = [...classes].join(' '); }, contains: name => this.className.split(/\s+/).includes(name) };
		}
		set innerHTML(value) { throw new Error('Stored history must never be rendered as HTML: ' + value); }
		append(...children) { for (const child of children) { child.parentElement = this; this.children.push(child); } }
		replaceChildren(...children) { this.children = []; this.append(...children); }
		setAttribute(name, value) { this.attributes.set(name, String(value)); }
		getAttribute(name) { return this.attributes.get(name) ?? null; }
		addEventListener(name, listener) { this.listeners.set(name, [...(this.listeners.get(name) || []), listener]); }
	};
}

function response(items = [], overrides = {}) {
	return {
		items: copy(items), nextCursor: null, recordingStartedAt: 1793664000, retentionMonths: 12,
		filters: { employees: [{ id: 'marvin-one', name: 'Marvin Beispiel' }, { id: 'marvin-two', name: 'Marvin Beispiel' }], actors: [{ id: 'anton', name: 'Anton Beispiel' }, { id: null, name: '' }] },
		...overrides,
	};
}

function item(id = 1, overrides = {}) {
	return { id, occurredAt: 1793700000, actor: { id: 'anton', name: 'Anton Beispiel' }, employee: { id: 'marvin-one', name: 'Marvin Beispiel' }, kind: 'updated', entryId: 12, workDate: '2026-11-02', changes: [{ field: 'comment', before: 'Vacation', after: 'Time off in lieu' }], ...overrides };
}

function openHistory({ present = true, now = '2026-11-03T23:30:00Z', respond = () => response(), awaitSaves = async () => true,
	preference = { historyView: 'cards', revision: preferenceRevision(0) }, preferenceAvailable = true,
	respondPreference = (_path, options, count) => ({ historyView: JSON.parse(options.body).historyView, revision: preferenceRevision(count) }),
} = {}) {
	const Element = createElementClass();
	const nodes = new Map();
	const requests = [];
	const preferenceRequests = [];
	const messages = [];
	function add(tag, id) { const node = new Element(tag); node.id = id; nodes.set(id, node); return node; }
	if (present) {
		const historyRoot = add('div', 'tab-change-history');
		historyRoot.dataset = { historyView: preference.historyView, historyViewRevision: preference.revision,
			historyViewAvailable: preferenceAvailable ? '1' : '0' };
		for (const name of ['from', 'to']) add('input', `history-${name}`);
		for (const name of ['employee', 'actor', 'kind']) add('select', `history-${name}`);
		for (const name of ['refresh', 'more', 'reset']) add('button', `history-${name}`);
		for (const name of ['cards', 'table']) add('button', `history-view-${name}`);
		for (const name of ['recording', 'message']) add('p', `history-${name}`);
		add('form', 'history-filters'); add('ol', 'history-list');
		add('div', 'history-table-container').append(add('tbody', 'history-table-body'));
	}
	class FixedDate extends Date { constructor(...values) { super(...(values.length ? values : [now])); } static now() { return new Date(now).getTime(); } }
	const document = { documentElement: { lang: 'en-GB' }, getElementById: id => nodes.get(id) || null, createElement: tag => new Element(tag) };
	const context = vm.createContext({ document, Date: FixedDate, Intl, URLSearchParams, AbortController, Set, Object,
		t: (_app, message, parameters = {}) => Object.entries(parameters).reduce((text, [key, value]) => text.replace(`{${key}}`, value), message),
	});
	context.window = context;
	context.Timesheet = {
		entries: { awaitPendingSaves: awaitSaves },
		notify: message => messages.push(message),
		api: async (path, options) => {
			if (path === '/api/preferences/change-history-view') {
				preferenceRequests.push({ path, options });
				return respondPreference(path, options, preferenceRequests.length);
			}
			requests.push({ path, options });
			return respond(path, options, requests.length);
		},
	};
	vm.runInContext(historySource, context);
	context.Timesheet.changeHistory.init();
	return { context, history: context.Timesheet.changeHistory, nodes, requests, preferenceRequests, messages,
		async fire(target, type = 'click') { const node = typeof target === 'string' ? nodes.get(target) : target; for (const listener of node.listeners.get(type) || []) listener({ preventDefault() {} }); await flush(); },
		text(node = nodes.get('history-list')) { return [node.textContent, ...node.children.map(child => this.text(child))].filter(Boolean).join('\n'); },
	};
}

test('employee pages never initialize or load the HR-only history', async () => {
	const app = openHistory({ present: false });
	await app.history.show();
	assert.equal(app.requests.length, 0);
});

test('initialization is read-only and the default interval is thirty Berlin calendar days', async () => {
	const app = openHistory();
	assert.equal(app.requests.length, 0);
	assert.equal(app.preferenceRequests.length, 0);
	assert.equal(app.nodes.get('history-from').value, '2026-10-06');
	assert.equal(app.nodes.get('history-to').value, '2026-11-04', 'Berlin is already on the following calendar day');
	await app.history.show();
	const query = new URL('http://localhost' + app.requests[0].path).searchParams;
	assert.equal(query.get('from'), '2026-10-06'); assert.equal(query.get('to'), '2026-11-04'); assert.equal(query.get('limit'), '50');
	assert.ok(app.nodes.get('history-recording').textContent.startsWith('Recording started on '));
});

test('the account preference restores table view without writing during initialization or loading', async () => {
	const app = openHistory({ preference: { historyView: 'table', revision: preferenceRevision(17) } });
	assert.equal(app.nodes.get('history-list').hidden, true);
	assert.equal(app.nodes.get('history-table-container').hidden, false);
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	assert.equal(app.preferenceRequests.length, 0);
	await app.history.show();
	assert.equal(app.preferenceRequests.length, 0);
	await app.fire('history-view-table');
	assert.equal(app.preferenceRequests.length, 0, 'The already selected view needs no write');
	await app.fire('history-view-cards');
	assert.deepEqual(JSON.parse(app.preferenceRequests[0].options.body), { historyView: 'cards', revision: preferenceRevision(17) });
	assert.equal(app.preferenceRequests[0].options.method, 'PUT');
	assert.equal(app.preferenceRequests[0].options.keepalive, true);
});

test('saved choices survive fresh pages and sessions while remaining independent between accounts', async () => {
	const accounts = new Map(['hr-a', 'hr-b'].map(account => [account, { historyView: 'cards', revision: preferenceRevision(0) }]));
	let revision = 0;
	function openAccount(account) {
		return openHistory({ preference: copy(accounts.get(account)), respondPreference: (_path, options) => {
			const submitted = JSON.parse(options.body);
			assert.equal(submitted.revision, accounts.get(account).revision);
			const updated = { historyView: submitted.historyView, revision: preferenceRevision(++revision) };
			accounts.set(account, updated);
			return copy(updated);
		} });
	}
	const firstSession = openAccount('hr-a');
	await firstSession.fire('history-view-table');
	for (const session of [openAccount('hr-a'), openAccount('hr-a')]) {
		assert.equal(session.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
		assert.equal(session.preferenceRequests.length, 0);
	}
	const otherAccount = openAccount('hr-b');
	assert.equal(otherAccount.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
	await otherAccount.fire('history-view-table');
	const laterSession = openAccount('hr-a');
	await laterSession.fire('history-view-cards');
	assert.equal(openAccount('hr-a').nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
	assert.equal(openAccount('hr-b').nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
});

test('view writes are serialized and do not block history filtering or refresh', async () => {
	let finishSave;
	const app = openHistory({ respondPreference: () => new Promise(resolve => { finishSave = resolve; }) });
	await app.history.show();
	await app.fire('history-view-table');
	assert.equal(app.nodes.get('history-list').hidden, true, 'The explicit choice appears immediately');
	for (const name of ['cards', 'table']) assert.equal(app.nodes.get(`history-view-${name}`).disabled, true);
	assert.equal(app.nodes.get('history-refresh').disabled, false);
	await app.fire('history-view-cards');
	assert.equal(app.preferenceRequests.length, 1, 'Even synthetic clicks cannot create parallel writes');
	await app.fire('history-refresh');
	assert.equal(app.requests.length, 2);
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	finishSave({ historyView: 'table', revision: preferenceRevision(1) });
	await flush();
	for (const name of ['cards', 'table']) assert.equal(app.nodes.get(`history-view-${name}`).disabled, false);
});

test('failed view saves restore the confirmed choice and allow retry with its revision', async () => {
	let fail = true;
	const app = openHistory({ preference: { historyView: 'table', revision: preferenceRevision(15) },
		respondPreference: () => {
			if (fail) throw new Error('Storage unavailable');
			return { historyView: 'cards', revision: preferenceRevision(16) };
		} });
	await app.fire('history-view-cards');
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	assert.deepEqual(app.messages, ['Changes could not be saved. Please try again.']);
	assert.equal(app.nodes.get('history-view-cards').disabled, false);
	fail = false;
	await app.fire('history-view-cards');
	assert.deepEqual(JSON.parse(app.preferenceRequests[1].options.body), { historyView: 'cards', revision: preferenceRevision(15) });
	assert.equal(app.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
	await app.fire('history-view-table');
	assert.equal(JSON.parse(app.preferenceRequests[2].options.body).revision, preferenceRevision(16));
});

test('a conflicting tab choice becomes the confirmed state before a deliberate retry', async () => {
	const app = openHistory({ respondPreference: (_path, options, count) => {
		if (count === 1) throw new Error(JSON.stringify({ code: 'preference_conflict', historyView: 'cards', revision: preferenceRevision(4) }));
		return { historyView: JSON.parse(options.body).historyView, revision: preferenceRevision(5) };
	} });
	await app.fire('history-view-table');
	assert.equal(app.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
	assert.equal(app.preferenceRequests.length, 1, 'A stale page does not automatically overwrite a newer choice');
	assert.deepEqual(app.messages, ['Changes could not be saved. Please try again.']);
	await app.fire('history-view-table');
	assert.equal(JSON.parse(app.preferenceRequests[1].options.body).revision, preferenceRevision(4));
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
});

test('invalid save responses cannot replace a confirmed choice or its revision', async () => {
	for (const invalid of [null, {}, { historyView: 'grid', revision: preferenceRevision(1) }, { historyView: 'table', revision: 'bad' }]) {
		const app = openHistory({ respondPreference: () => invalid });
		await app.fire('history-view-table');
		assert.equal(app.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
		assert.equal(app.nodes.get('history-view-table').disabled, false);
		assert.equal(app.messages.length, 1);
	}
});

test('unavailable account preferences leave history usable without unsafe preference writes', async () => {
	for (const options of [{ preferenceAvailable: false }, { preference: { historyView: 'cards', revision: 'invalid' } }]) {
		const app = openHistory(options);
		for (const name of ['cards', 'table']) assert.equal(app.nodes.get(`history-view-${name}`).disabled, true);
		await app.history.show();
		await app.fire('history-view-table');
		assert.equal(app.requests.length, 1);
		assert.equal(app.preferenceRequests.length, 0);
		assert.equal(app.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
	}
});

test('view buttons switch the loaded history without fetching or changing its order', async () => {
	const app = openHistory({ respond: () => response([item(3), item(2)]) });
	await app.history.show();
	assert.equal(app.nodes.get('history-list').hidden, false);
	assert.equal(app.nodes.get('history-table-container').hidden, true);
	assert.equal(app.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true');
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'false');
	const cards = [...app.nodes.get('history-list').children];
	await app.fire('history-view-table');
	assert.equal(app.requests.length, 1);
	assert.equal(app.nodes.get('history-list').hidden, true);
	assert.equal(app.nodes.get('history-table-container').hidden, false);
	assert.equal(app.nodes.get('history-view-cards').getAttribute('aria-pressed'), 'false');
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	assert.equal(app.nodes.get('tab-change-history').dataset.historyView, 'table');
	assert.deepEqual(app.nodes.get('history-table-body').children.filter(row => row.dataset.historyId).map(row => row.dataset.historyId), ['3', '2']);
	await app.fire('history-view-cards');
	assert.equal(app.requests.length, 1);
	assert.deepEqual(app.nodes.get('history-list').children, cards);
});

test('table details expand below their row and retain safe before/after values across view switches', async () => {
	const unsafeComment = '<img src=x onerror=alert(1)>\nSecond line';
	const app = openHistory({ respond: () => response([item(4, { changes: [
		{ field: 'comment', before: '', after: unsafeComment },
		{ field: 'startMin', before: null, after: 0 },
		{ field: 'breakMinutes', before: 30, after: -60 },
		{ field: 'dailyMin', before: 480, after: 0 },
	] })]) });
	await app.history.show(); await app.fire('history-view-table');
	const [row, details] = app.nodes.get('history-table-body').children;
	assert.equal(row.tagName, 'TR'); assert.equal(row.children.length, 6);
	assert.equal(details.tagName, 'TR'); assert.equal(details.hidden, true);
	assert.equal(details.children[0].getAttribute('colspan'), '6');
	const button = row.children[5].children[0];
	assert.equal(button.tagName, 'BUTTON'); assert.equal(button.type, 'button');
	assert.equal(button.getAttribute('aria-controls'), details.id);
	assert.equal(button.getAttribute('aria-expanded'), 'false');
	assert.ok(button.getAttribute('aria-label').includes('Marvin Beispiel (marvin-one)'));
	await app.fire(button);
	assert.equal(details.hidden, false); assert.equal(button.getAttribute('aria-expanded'), 'true');
	const text = app.text(details);
	for (const value of [unsafeComment, 'Before', 'After', 'Empty', '00:00', '-60 Minutes']) assert.ok(text.includes(value));
	await app.fire('history-view-cards'); await app.fire('history-view-table');
	assert.equal(details.hidden, false); assert.equal(button.getAttribute('aria-expanded'), 'true');
	assert.equal(app.requests.length, 1);
	await app.fire(button);
	assert.equal(details.hidden, true); assert.equal(button.getAttribute('aria-expanded'), 'false');
});

test('table settings rows preserve account identities and show an empty working date', async () => {
	const app = openHistory({ respond: () => response([item(6, { kind: 'settings', workDate: null, entryId: null,
		actor: { id: null, name: '' }, changes: [{ field: 'state', before: 'BY', after: 'HE' }],
	})]) });
	await app.history.show(); await app.fire('history-view-table');
	const row = app.nodes.get('history-table-body').children[0];
	assert.equal(row.children[1].textContent, 'Deleted account');
	assert.equal(row.children[2].textContent, 'Marvin Beispiel (marvin-one)');
	assert.equal(row.children[3].textContent, 'Empty');
	assert.equal(row.children[4].textContent, 'Work settings updated');
	const text = app.text(app.nodes.get('history-table-body').children[1]);
	assert.ok(text.includes('Federal state')); assert.ok(text.includes('BY')); assert.ok(text.includes('HE'));
});

test('view choice preserves filter selection and paged records and survives tabs and filter reset', async () => {
	const app = openHistory({ respond: (_path, _options, count) => count === 3
		? response([item(7), item(6)]) : response([item(8), item(7)], { nextCursor: 7 }) });
	await app.history.show();
	app.nodes.get('history-employee').value = 'marvin-two';
	app.nodes.get('history-kind').value = 'comment';
	await app.fire('history-filters', 'submit');
	await app.fire('history-view-table');
	assert.equal(app.requests.length, 2);
	assert.equal(app.nodes.get('history-employee').value, 'marvin-two');
	await app.fire('history-more');
	const query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('employee'), 'marvin-two'); assert.equal(query.get('kind'), 'comment'); assert.equal(query.get('beforeId'), '7');
	for (const container of ['history-list', 'history-table-body']) {
		assert.deepEqual(app.nodes.get(container).children.filter(row => row.dataset.historyId).map(row => row.dataset.historyId), ['8', '7', '6']);
	}
	await app.fire('history-view-cards'); await app.fire('history-view-table');
	assert.equal(app.requests.length, 3);
	app.history.hide(); await app.history.show();
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	assert.equal(app.nodes.get('history-list').hidden, true);
	await app.fire('history-reset');
	assert.equal(app.nodes.get('history-employee').value, '');
	assert.equal(app.nodes.get('history-kind').value, '');
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	assert.equal(openHistory().nodes.get('history-view-cards').getAttribute('aria-pressed'), 'true', 'A new page starts in tile view');
});

test('history waits for the previous row save and keeps a failed save visibly separate', async () => {
	let finishSave;
	const app = openHistory({ awaitSaves: () => new Promise(resolve => { finishSave = resolve; }) });
	const loading = app.history.show();
	assert.equal(app.requests.length, 0);
	assert.equal(app.nodes.get('history-message').textContent, 'Waiting for changes to be saved…');
	finishSave(true); await loading; assert.equal(app.requests.length, 1);
	const failed = openHistory({ awaitSaves: async () => false });
	await failed.history.show();
	assert.equal(failed.requests.length, 0);
	assert.equal(failed.nodes.get('history-message').textContent, 'The previous changes could not be saved. Check the time entry before continuing.');
	assert.equal(failed.nodes.get('tab-change-history').getAttribute('aria-busy'), 'false');
});

test('details retain literal comments, null, midnight, zero target and historical negative breaks', async () => {
	const app = openHistory({ respond: () => response([item(1, { changes: [
		{ field: 'comment', before: null, after: '<img src=x onerror=alert(1)>\nSecond line' },
		{ field: 'startMin', before: null, after: 0 }, { field: 'breakMinutes', before: 30, after: -60 },
		{ field: 'dailyMin', before: 480, after: 0 },
	] })]) });
	await app.history.show();
	const text = app.text();
	assert.ok(text.includes('<img src=x onerror=alert(1)>\nSecond line'));
	assert.ok(text.includes('Empty')); assert.ok(text.includes('00:00')); assert.ok(text.includes('-60 Minutes'));
	assert.ok(text.includes('Anton Beispiel (anton)')); assert.ok(text.includes('Marvin Beispiel (marvin-one)'));
	assert.equal(app.nodes.get('history-list').children[0].children.at(-1).tagName, 'DETAILS');
	assert.equal(app.nodes.get('history-list').children[0].children.at(-1).children[0].textContent, 'Details');
});

test('date, employee, editor and kind filters combine and survive tab changes', async () => {
	const app = openHistory(); await app.history.show();
	for (const [name, value] of Object.entries({ from: '2026-10-01', to: '2026-10-31', employee: 'marvin-two', actor: 'user:anton', kind: 'deleted' })) app.nodes.get(`history-${name}`).value = value;
	await app.fire('history-filters', 'submit');
	let query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('employee'), 'marvin-two'); assert.equal(query.get('actor'), 'anton'); assert.equal(query.get('kind'), 'deleted');
	app.history.hide(); await app.history.show();
	query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('from'), '2026-10-01'); assert.equal(query.get('employee'), 'marvin-two');
	const names = app.nodes.get('history-employee').children.map(option => option.textContent);
	assert.ok(names.includes('Marvin Beispiel (marvin-one)') && names.includes('Marvin Beispiel (marvin-two)'));
	await app.fire('history-reset');
	query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.has('employee'), false); assert.equal(query.has('actor'), false); assert.equal(query.has('kind'), false);
});

test('comment changes include additions, edits and clearing while preserving entry actions', async () => {
	const cases = [
		[item(1), 'Comment changed'],
		[item(2, { changes: [{ field: 'comment', before: '', after: 'New comment' }] }), 'Comment changed'],
		[item(3, { changes: [{ field: 'comment', before: 'Old comment', after: '' }] }), 'Comment changed'],
		[item(4, { kind: 'created', changes: [{ field: 'comment', before: null, after: 'New comment' }] }), 'Time entry created · Comment changed'],
		[item(5, { changes: [{ field: 'startMin', before: 480, after: 490 }, { field: 'comment', before: '', after: 'Late start' }] }), 'Time entry updated · Comment changed'],
		[item(6, { kind: 'created', changes: [{ field: 'comment', before: null, after: '' }] }), 'Time entry created'],
		[item(7, { kind: 'deleted', changes: [{ field: 'comment', before: 'Old comment', after: null }] }), 'Time entry deleted'],
		[item(8, { changes: [{ field: 'startMin', before: 480, after: 490 }] }), 'Time entry updated'],
	];
	const app = openHistory({ respond: () => response(cases.map(([record]) => record)) });
	await app.history.show();
	assert.deepEqual(app.nodes.get('history-list').children.map(row => row.children[0].textContent), cases.map(([, label]) => label));
});

test('the comment filter reaches the server and remains selected between pages and tabs', async () => {
	const app = openHistory({ respond: (_path, _options, count) => response([item(count)], { nextCursor: count === 2 ? 2 : null }) });
	await app.history.show();
	app.nodes.get('history-kind').value = 'comment';
	await app.fire('history-filters', 'submit');
	await app.fire('history-more');
	let query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('kind'), 'comment'); assert.equal(query.get('beforeId'), '2');
	app.history.hide(); await app.history.show();
	query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('kind'), 'comment'); assert.equal(query.has('beforeId'), false);
	assert.equal(app.nodes.get('history-kind').value, 'comment');
	assert.match(readFileSync(resolve(root, 'templates/main.php'), 'utf8'), /<option value="comment">.*Comment changed/);
});

test('deleted editors have a distinct filter without colliding with a real account ID', async () => {
	const app = openHistory({ respond: () => response([item(1, { actor: { id: null, name: '' }, kind: 'deleted' })]) });
	await app.history.show(); assert.ok(app.text().includes('Deleted account'));
	app.nodes.get('history-actor').value = 'deleted:'; await app.fire('history-filters', 'submit');
	let query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('actorDeleted'), '1'); assert.equal(query.has('actor'), false);
	app.nodes.get('history-actor').value = 'user:deleted:'; await app.fire('history-filters', 'submit');
	query = new URL('http://localhost' + app.requests.at(-1).path).searchParams;
	assert.equal(query.get('actor'), 'deleted:'); assert.equal(query.has('actorDeleted'), false);
});

test('load more uses a stable cursor, deduplicates entries and refresh starts from the newest page', async () => {
	const app = openHistory({ respond: (_path, _options, count) => count === 1 ? response([item(5), item(4)], { nextCursor: 4 }) : response([item(4), item(3)]) });
	await app.history.show(); assert.equal(app.nodes.get('history-more').hidden, false);
	await app.fire('history-more');
	assert.equal(new URL('http://localhost' + app.requests.at(-1).path).searchParams.get('beforeId'), '4');
	assert.deepEqual(app.nodes.get('history-list').children.map(row => row.dataset.historyId), ['5', '4', '3']);
	assert.equal(app.nodes.get('history-more').hidden, true);
	await app.fire('history-refresh');
	assert.equal(new URL('http://localhost' + app.requests.at(-1).path).searchParams.has('beforeId'), false);
	assert.deepEqual(app.nodes.get('history-list').children.map(row => row.dataset.historyId), ['4', '3']);
});

test('outdated responses and hidden-tab requests cannot replace a newer filter result', async () => {
	const resolvers = [];
	const app = openHistory({ respond: () => new Promise(resolve => resolvers.push(resolve)) });
	const first = app.history.show(); await flush();
	app.nodes.get('history-kind').value = 'settings'; await app.fire('history-filters', 'submit');
	assert.equal(app.requests[0].options.signal.aborted, true);
	resolvers[1](response([item(2, { kind: 'settings', workDate: null, entryId: null })])); await flush();
	resolvers[0](response([item(1)])); await first;
	assert.deepEqual(app.nodes.get('history-list').children.map(row => row.dataset.historyId), ['2']);
	assert.deepEqual(app.nodes.get('history-table-body').children.filter(row => row.dataset.historyId).map(row => row.dataset.historyId), ['2']);
	const pending = app.history.show(); await flush(); app.history.hide();
	resolvers[2](response([item(3)])); await pending;
	assert.deepEqual(app.nodes.get('history-list').children.map(row => row.dataset.historyId), ['2']);
	assert.deepEqual(app.nodes.get('history-table-body').children.filter(row => row.dataset.historyId).map(row => row.dataset.historyId), ['2']);
});

test('load errors clear previously scoped names and records and can be retried', async () => {
	let fail = false;
	const app = openHistory({ respond: () => { if (fail) throw new Error('403'); return response([item()]); } });
	await app.history.show(); fail = true; await app.fire('history-refresh');
	assert.equal(app.nodes.get('history-list').children.length, 0);
	assert.equal(app.nodes.get('history-table-body').children.length, 0);
	assert.equal(app.nodes.get('history-employee').children.length, 1);
	assert.equal(app.nodes.get('history-actor').children.length, 1);
	assert.equal(app.nodes.get('history-more').hidden, true);
	assert.equal(app.nodes.get('history-message').textContent, 'The change history could not be loaded. Please try again.');
	fail = false; await app.fire('history-refresh'); assert.equal(app.nodes.get('history-list').children.length, 1);
	assert.equal(app.nodes.get('history-table-body').children.length, 2);
});

test('a failed save clears both already loaded views without exposing stale table details', async () => {
	let savingSucceeded = true;
	const app = openHistory({ respond: () => response([item(1)]), awaitSaves: async () => savingSucceeded });
	await app.history.show(); await app.fire('history-view-table');
	savingSucceeded = false; await app.fire('history-refresh');
	assert.equal(app.requests.length, 1);
	assert.equal(app.nodes.get('history-list').children.length, 0);
	assert.equal(app.nodes.get('history-table-body').children.length, 0);
	assert.equal(app.nodes.get('history-view-table').getAttribute('aria-pressed'), 'true');
	assert.equal(app.nodes.get('history-message').textContent, 'The previous changes could not be saved. Check the time entry before continuing.');
});

test('no retained history and no matching filters have separate messages', async () => {
	const empty = openHistory({ respond: () => response([], { filters: { employees: [], actors: [] } }) });
	await empty.history.show(); assert.equal(empty.nodes.get('history-message').textContent, 'No HR changes have been recorded yet.');
	const filtered = openHistory(); await filtered.history.show();
	assert.equal(filtered.nodes.get('history-message').textContent, 'No changes match the selected filters.');
});

test('a malformed response cannot render more than fifty records', async () => {
	const app = openHistory({ respond: () => response(Array.from({ length: 51 }, (_, index) => item(index + 1))) });
	await app.history.show(); assert.equal(app.nodes.get('history-list').children.length, 0);
	assert.equal(app.nodes.get('history-message').classList.contains('is-error'), true);
});

// Use the real saving module to check that its barrier includes queued writes and failures.
function openEntries() {
	const requests = []; const messages = []; const fields = {
		'.startTime': { value: '08:00', type: 'time' }, '.endTime': { value: '16:30', type: 'time' },
		'.breakMinutes': { value: '30', type: 'text' }, '.commentInput': { value: 'Working' },
		'.ts-warn': { textContent: '' }, '.ts-duration': { textContent: '' }, '.ts-diff': { textContent: '' },
	};
	const classes = new Set();
	const row = { dataset: { date: '2026-11-02', savedStart: '', savedEnd: '', savedBreak: '0', savedComment: '' }, querySelector: selector => fields[selector] || null,
		classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) }, closest: selector => selector === 'tbody' ? tbody : null };
	const tbody = { dataset: { specialDays: '0' }, querySelectorAll: () => [row] };
	const configInputs = { '.config-daily-min': { value: '08:00' }, '.config-state': { value: 'BY' } };
	const document = { documentElement: { getAttribute: () => 'en-GB' }, querySelector: selector => configInputs[selector] || null, querySelectorAll: () => [], getElementById: id => id === 'config-daily-min-mine' ? configInputs['.config-daily-min'] : null };
	const context = vm.createContext({ document, navigator: { language: 'en-GB' }, console: { log() {}, warn() {}, error() {} }, setTimeout() {}, clearTimeout() {},
		t: (_app, message) => message,
		OC: { requestToken: 'fixture', getCurrentUser: () => ({ uid: 'alice' }), getLocale: () => 'en-GB', generateUrl: path => path, Notification: { showTemporary: text => messages.push(text) } },
		fetch: async () => ({ ok: true, json: async () => ({}) }),
	});
	context.window = context;
	for (const name of ['timesheet-core.js', 'timesheet-entries.js']) vm.runInContext(readFileSync(resolve(root, 'js', name), 'utf8'), context);
	context.Timesheet.api = async (path, options = {}) => { requests.push({ path, options }); return options.method ? { id: 101 } : { overtimeMinutes: 0 }; };
	return { context, entries: context.Timesheet.entries, row, fields, requests, messages };
}

test('the saving barrier waits for a comment queued behind an earlier request', async () => {
	const app = openEntries(); let finishFirst; let writes = 0;
	app.context.Timesheet.api = async (_path, options = {}) => {
		if (options.method && ++writes === 1) await new Promise(resolve => { finishFirst = resolve; });
		return options.method ? { id: 101 } : { overtimeMinutes: 0 };
	};
	const saving = app.entries.saveRowIfNeeded(app.row);
	app.fields['.commentInput'].value = 'Queued change'; await app.entries.saveRowIfNeeded(app.row);
	let barrierFinished = false;
	const waiting = app.entries.awaitPendingSaves().then(success => { barrierFinished = true; return success; });
	await flush(); assert.equal(barrierFinished, false);
	finishFirst(); await saving;
	assert.equal(await waiting, true); assert.equal(writes, 2); assert.equal(app.row.dataset.savedComment, 'Queued change');
});

test('an entry failure is visible, blocks a fresh history read, and a successful retry clears it', async () => {
	const app = openEntries(); let fail = true;
	app.context.Timesheet.api = async (_path, options = {}) => { if (options.method && fail) throw new Error('Write failed'); return options.method ? { id: 101 } : { overtimeMinutes: 0 }; };
	await app.entries.saveRowIfNeeded(app.row);
	assert.equal(await app.entries.awaitPendingSaves(), false);
	assert.deepEqual(app.messages, ['Changes could not be saved. Please try again.']);
	fail = false; await app.entries.saveRowIfNeeded(app.row);
	assert.equal(await app.entries.awaitPendingSaves(), true);
});

test('external configuration writes share the same barrier without changing their rejection contract', async () => {
	const app = openEntries(); const target = {}; let finish;
	const request = app.entries.trackPendingSave(new Promise(resolve => { finish = resolve; }), target);
	let settled = false; const waiting = app.entries.awaitPendingSaves().then(result => { settled = true; return result; });
	await flush(); assert.equal(settled, false); finish(); await request; assert.equal(await waiting, true);
	await assert.rejects(app.entries.trackPendingSave(Promise.reject(new Error('Configuration failed')), target));
	assert.equal(await app.entries.awaitPendingSaves(), false);
	app.entries.clearSaveFailure(target); assert.equal(await app.entries.awaitPendingSaves(), true);
});

test('retrying the same employee day after a DOM replacement clears its previous save failure', async () => {
	const app = openEntries(); let fail = true;
	app.context.Timesheet.api = async (_path, options = {}) => { if (options.method && fail) throw new Error('Write failed'); return options.method ? { id: 101 } : { overtimeMinutes: 0 }; };
	await app.entries.saveRowIfNeeded(app.row);
	assert.equal(await app.entries.awaitPendingSaves(), false);
	const replacementRow = { ...app.row, dataset: { ...app.row.dataset } };
	fail = false; await app.entries.saveRowIfNeeded(replacementRow);
	assert.equal(await app.entries.awaitPendingSaves(), true);
});
