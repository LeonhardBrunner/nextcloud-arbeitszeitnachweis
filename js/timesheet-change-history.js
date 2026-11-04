(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) return;
	const translate = (message, parameters = {}) => t('timesheet', message, parameters);
	const actionLabels = { created: 'Time entry created', updated: 'Time entry updated', deleted: 'Time entry deleted', settings: 'Work settings updated' };
	const fieldLabels = { workDate: 'Work date', startMin: 'Start', endMin: 'End', breakMinutes: 'Break', comment: 'Comment', dailyMin: 'Daily working time', state: 'Federal state' };
	let controls;
	let filters;
	let nextCursor = null;
	let generation = 0;
	let requestController;
	let visible = false;
	const displayedIds = new Set();

	function element(tagName, className = '', text = '') {
		const node = document.createElement(tagName);
		node.className = className;
		node.textContent = text;
		return node;
	}

	function accountLocale() {
		return String(document.documentElement.lang || window.OC?.getLocale?.() || 'en').replaceAll('_', '-');
	}

	function formatDate(value, withTime = false) {
		const date = typeof value === 'number' ? new Date(value * 1000) : new Date(`${value}T12:00:00Z`);
		if (Number.isNaN(date.getTime())) return translate('Empty');
		const options = { timeZone: 'Europe/Berlin', dateStyle: 'medium', ...(withTime ? { timeStyle: 'short' } : {}) };
		try { return new Intl.DateTimeFormat(accountLocale(), options).format(date); }
		catch { return new Intl.DateTimeFormat('en', options).format(date); }
	}

	// Calendar dates use Berlin time even when the browser is in another time zone.
	function defaultFilters() {
		const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map(part => [part.type, part.value]));
		const end = `${parts.year}-${parts.month}-${parts.day}`;
		const start = new Date(`${end}T12:00:00Z`);
		start.setUTCDate(start.getUTCDate() - 29);
		return { from: start.toISOString().slice(0, 10), to: end, employee: '', actor: '', kind: '' };
	}

	function readFilters() {
		return Object.fromEntries(['from', 'to', 'employee', 'actor', 'kind'].map(name => [name, controls[name].value]));
	}

	function writeFilters(values) {
		for (const [name, value] of Object.entries(values)) controls[name].value = value;
	}

	function showMessage(message, isError = false) {
		controls.message.textContent = message;
		controls.message.classList.toggle('is-error', isError);
	}

	function setBusy(busy) {
		controls.root.setAttribute('aria-busy', String(busy));
		controls.more.disabled = busy;
		controls.refresh.disabled = busy;
	}

	function accountLabel(account) {
		if (account.id === null) return translate('Deleted account');
		return account.name && account.name !== account.id ? `${account.name} (${account.id})` : account.id;
	}

	function updateOptions(select, accounts, emptyLabel, selectedValue, actors = false) {
		select.replaceChildren();
		const initial = element('option', '', translate(emptyLabel));
		initial.value = '';
		select.append(initial);
		for (const account of accounts) {
			const option = element('option', '', accountLabel(account));
			option.value = actors ? account.id === null ? 'deleted:' : `user:${account.id}` : account.id;
			select.append(option);
		}
		select.value = selectedValue;
	}

	function formatValue(field, value) {
		if (value === null || value === undefined || value === '') return translate('Empty');
		if (field === 'workDate') return formatDate(value);
		if (['startMin', 'endMin', 'dailyMin'].includes(field) && Number.isFinite(Number(value))) {
			const minutes = Number(value);
			return `${minutes < 0 ? '-' : ''}${String(Math.floor(Math.abs(minutes) / 60)).padStart(2, '0')}:${String(Math.abs(minutes) % 60).padStart(2, '0')}`;
		}
		if (field === 'breakMinutes') return `${value} ${translate('Minutes')}`;
		if (field === 'state') {
			const state = [...(document.getElementById('config-state-mine')?.options || [])].find(option => option.value === value);
			if (state) return state.textContent;
		}
		return String(value);
	}

	function addDescription(list, label, value) {
		const group = element('div');
		group.append(element('dt', '', translate(label)), element('dd', '', value));
		list.append(group);
	}

	// Comment edits remain identifiable when the same save also changes working times.
	function actionDescription(item) {
		const entryDescription = translate(actionLabels[item.kind]);
		const commentChanged = ['created', 'updated'].includes(item.kind) && item.changes.some(change =>
			change.field === 'comment' && String(change.before ?? '') !== String(change.after ?? ''));
		if (!commentChanged) return entryDescription;
		const commentDescription = translate('Comment changed');
		return item.kind === 'updated' && item.changes.every(change => change.field === 'comment')
			? commentDescription : `${entryDescription} · ${commentDescription}`;
	}

	// Only text nodes render stored values; comments never become markup.
	function renderItem(item) {
		const row = element('li', 'ts-history-card');
		row.dataset.historyId = String(item.id);
		row.append(element('h4', '', actionDescription(item)));
		const metadata = element('dl', 'ts-history-metadata');
		addDescription(metadata, 'Changed at', formatDate(item.occurredAt, true));
		addDescription(metadata, 'Changed by', accountLabel(item.actor));
		addDescription(metadata, 'Employee', accountLabel(item.employee));
		if (item.workDate) addDescription(metadata, 'Work date', formatDate(item.workDate));
		row.append(metadata);
		const details = element('details');
		details.append(element('summary', '', translate('Details')));
		for (const change of item.changes) {
			if (!Object.hasOwn(fieldLabels, change.field)) continue;
			const group = element('div', 'ts-history-change');
			group.append(element('h5', '', translate(fieldLabels[change.field])));
			const values = element('dl', 'ts-history-values');
			addDescription(values, 'Before', formatValue(change.field, change.before));
			addDescription(values, 'After', formatValue(change.field, change.after));
			group.append(values);
			details.append(group);
		}
		row.append(details);
		return row;
	}

	function validResponse(data) {
		return data && Array.isArray(data.items) && data.items.length <= 50 && data.filters
			&& Array.isArray(data.filters.employees) && Array.isArray(data.filters.actors)
			&& (data.nextCursor === null || Number.isSafeInteger(data.nextCursor) && data.nextCursor > 0)
			&& Number.isFinite(data.recordingStartedAt)
			&& data.items.every(item => Number.isSafeInteger(item.id) && Number.isFinite(item.occurredAt)
				&& item.actor && (item.actor.id === null || typeof item.actor.id === 'string')
				&& item.employee && typeof item.employee.id === 'string' && Object.hasOwn(actionLabels, item.kind)
				&& Array.isArray(item.changes));
	}

	async function load(append = false) {
		if (!controls || !visible) return;
		const currentGeneration = ++generation;
		requestController?.abort();
		requestController = new AbortController();
		const signal = requestController.signal;
		setBusy(true);
		showMessage(translate('Waiting for changes to be saved…'));
		try {
			const savesComplete = await timesheet.entries?.awaitPendingSaves?.();
			if (currentGeneration !== generation || !visible) return;
			if (savesComplete === false) {
				controls.list.replaceChildren();
				nextCursor = null;
				controls.more.hidden = true;
				showMessage(translate('The previous changes could not be saved. Check the time entry before continuing.'), true);
				return;
			}
			showMessage(translate('Loading changes…'));
			const parameters = new URLSearchParams({ from: filters.from, to: filters.to, limit: '50' });
			if (filters.employee) parameters.set('employee', filters.employee);
			if (filters.actor === 'deleted:') parameters.set('actorDeleted', '1');
			else if (filters.actor.startsWith('user:')) parameters.set('actor', filters.actor.slice(5));
			if (filters.kind) parameters.set('kind', filters.kind);
			if (append && nextCursor !== null) parameters.set('beforeId', String(nextCursor));
			const data = await timesheet.api('/api/hr/change-history?' + parameters.toString(), { signal });
			if (currentGeneration !== generation || !visible) return;
			if (!validResponse(data)) throw new Error('Invalid change history response');
			if (!append) { controls.list.replaceChildren(); displayedIds.clear(); }
			for (const item of data.items) {
				if (!displayedIds.has(item.id)) { controls.list.append(renderItem(item)); displayedIds.add(item.id); }
			}
			updateOptions(controls.employee, data.filters.employees, 'All employees', filters.employee);
			updateOptions(controls.actor, data.filters.actors, 'All editors', filters.actor, true);
			controls.recording.textContent = translate('Recording started on {date}. Earlier changes are not available.', { date: formatDate(data.recordingStartedAt) });
			nextCursor = data.nextCursor;
			controls.more.hidden = nextCursor === null;
			const noHistoryYet = data.filters.actors.length === 0;
			showMessage(displayedIds.size ? '' : translate(noHistoryYet ? 'No HR changes have been recorded yet.' : 'No changes match the selected filters.'));
		} catch (error) {
			if (currentGeneration !== generation || !visible || signal.aborted) return;
			controls.list.replaceChildren(); displayedIds.clear(); nextCursor = null;
			controls.more.hidden = true;
			controls.recording.textContent = '';
			updateOptions(controls.employee, [], 'All employees', '');
			updateOptions(controls.actor, [], 'All editors', '');
			filters = { ...filters, employee: '', actor: '' };
			showMessage(translate('The change history could not be loaded. Please try again.'), true);
		} finally {
			if (currentGeneration === generation) setBusy(false);
		}
	}

	function init() {
		if (controls) return;
		const root = document.getElementById('tab-change-history');
		if (!root) return;
		controls = { root };
		for (const name of ['from', 'to', 'employee', 'actor', 'kind', 'refresh', 'more', 'reset', 'filters', 'recording', 'message', 'list']) controls[name] = document.getElementById(`history-${name}`);
		filters = defaultFilters(); writeFilters(filters);
		controls.filters.addEventListener('submit', event => { event.preventDefault(); filters = readFilters(); load(); });
		controls.reset.addEventListener('click', () => { filters = defaultFilters(); writeFilters(filters); load(); });
		controls.refresh.addEventListener('click', () => load());
		controls.more.addEventListener('click', () => { if (nextCursor !== null) load(true); });
	}

	function show() { init(); if (controls) { visible = true; return load(); } }
	function hide() { visible = false; generation++; requestController?.abort(); if (controls) setBusy(false); }

	timesheet.changeHistory = { init, show, hide };
})();
