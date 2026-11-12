(function () {
	"use strict";

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}
	const appState = timesheet.state;
	const utils = timesheet.util;
	timesheet.hr = timesheet.hr || {};
	const hrModule = timesheet.hr;

	// Warning settings and defaults.
	function getWarningThresholds() {
		const warningThresholds = appState.hrWarningThresholds || {};
		const noEntryDays = Number.isFinite(warningThresholds.noEntryDays) ? warningThresholds.noEntryDays : 14;
		const overtimeThresholdMinutes = Number.isFinite(warningThresholds.overtimeThresholdMinutes) ? warningThresholds.overtimeThresholdMinutes : 600;
		const negativeOvertimeThresholdMinutes = Number.isFinite(warningThresholds.negativeOvertimeThresholdMinutes)
			? warningThresholds.negativeOvertimeThresholdMinutes
			: 600;
		return {
			noEntryDays: Math.max(1, Math.min(365, noEntryDays)),
			overtimeThresholdMinutes: Math.max(0, overtimeThresholdMinutes),
			negativeOvertimeThresholdMinutes: Math.max(0, negativeOvertimeThresholdMinutes)
		};
	}

	async function ensureWarningThresholds() {
		if (appState.hrWarningThresholds) {
			return getWarningThresholds();
		}
		if (timesheet.config?.loadHrWarningThresholds) {
			await timesheet.config.loadHrWarningThresholds();
		}
		return getWarningThresholds();
	}

	let allEmployees = [];
	let visibleEmployees = [];
	let listGeneration = 0;
	let listLoaded = false;
	let initialized = false;
	const employeeFilter = { mode: 'active', name: '' };
	const translate = (message, parameters = {}) => t(appState.appName, message, parameters);
	const berlinCalendar = new Intl.DateTimeFormat('en-CA', {
		timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit',
	});

	function finiteMinutes(value, fallback = 0) {
		return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
	}

	// The list and statistics always use exactly the same filtered data.
	function updateHrStats() {
		let totalWorkedMinutes = 0;
		let positiveMinutes = 0;
		let negativeMinutes = 0;
		let positiveCount = 0;
		let negativeCount = 0;
		for (const employee of visibleEmployees) {
			totalWorkedMinutes += finiteMinutes(employee.totalMinutes);
			const balance = finiteMinutes(employee.overtimeMinutes);
			if (balance > 0) { positiveMinutes += balance; positiveCount++; }
			if (balance < 0) { negativeMinutes += balance; negativeCount++; }
		}
		const values = {
			'hr-stat-total-hours': utils.minToHm(totalWorkedMinutes),
			'hr-stat-total-overtime': utils.minToHm(positiveMinutes),
			'hr-stat-employees-overtime': String(positiveCount),
			'hr-stat-total-negative': utils.minToHm(negativeMinutes),
			'hr-stat-employees-negative': String(negativeCount),
			'hr-stat-sum-overtimes': utils.minToHm(positiveMinutes + negativeMinutes),
		};
		for (const [id, value] of Object.entries(values)) {
			const element = document.getElementById(id);
			if (element) element.textContent = value;
		}
	}

	function daysSince(date) {
		if (!date) return null;
		// Compare calendar dates in the same zone used by employment phases and reminders.
		const parts = Object.fromEntries(berlinCalendar.formatToParts(new Date()).map(part => [part.type, part.value]));
		const today = Date.parse(`${parts.year}-${parts.month}-${parts.day}`);
		const timestamp = Date.parse(date);
		return Number.isFinite(timestamp) ? Math.max(0, Math.floor((today - timestamp) / 86400000)) : null;
	}

	function employeeWarnings(employee) {
		const thresholds = getWarningThresholds();
		const warnings = [];
		const reference = employee.missingEntryReferenceDate === undefined ? employee.lastEntryDate : employee.missingEntryReferenceDate;
		const daysWithoutEntry = daysSince(reference);
		if (employee.isActive && !employee.freelancerMode && (daysWithoutEntry === null || daysWithoutEntry >= thresholds.noEntryDays)) {
			warnings.push(daysWithoutEntry === null
				? translate('No complete time entry yet')
				: translate('No entry for at least {days} days', { days: thresholds.noEntryDays }));
		}
		const balance = finiteMinutes(employee.overtimeMinutes);
		if (balance > 0 && balance >= thresholds.overtimeThresholdMinutes) warnings.push(translate('Too much overtime'));
		else if (balance < 0 && balance <= -thresholds.negativeOvertimeThresholdMinutes) warnings.push(translate('Too many negative hours'));
		return warnings.join(', ');
	}

	function appendCell(row, className, text) {
		const cell = document.createElement('td');
		cell.className = className;
		cell.textContent = text;
		row.appendChild(cell);
		return cell;
	}

	function appendBadge(cell, className, text) {
		const badge = document.createElement('span');
		badge.className = className;
		badge.textContent = text;
		cell.appendChild(badge);
	}

	function renderEmployees() {
		const body = document.getElementById('hr-userlist');
		if (!body) return;
		const nameQuery = employeeFilter.name.trim().toLowerCase();
		visibleEmployees = allEmployees.filter(employee => {
			const matchesMode = employeeFilter.mode === 'all'
				|| employeeFilter.mode === 'active' && employee.isActive === true
				|| employeeFilter.mode === 'inactive' && employee.isActive === false
				|| employeeFilter.mode === 'freelancers' && employee.freelancerMode === true;
			return matchesMode && String(employee.name || employee.id).toLowerCase().includes(nameQuery);
		});
		const nameCounts = new Map();
		for (const employee of allEmployees) {
			const name = String(employee.name || employee.id);
			nameCounts.set(name, (nameCounts.get(name) || 0) + 1);
		}
		body.replaceChildren();
		for (const employee of visibleEmployees) {
			const row = document.createElement('tr');
			row.classList.toggle('is-freelancer', employeeFilter.mode === 'all' && employee.freelancerMode === true);
			const name = String(employee.name || employee.id);
			const nameCell = appendCell(row, 'hr-employee-name', '');
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'hr-load-user';
			button.dataset.user = String(employee.id);
			button.textContent = name;
			if (nameCounts.get(name) > 1) {
				const identifier = document.createElement('small');
				identifier.className = 'hr-employee-identifier';
				identifier.textContent = String(employee.id);
				button.appendChild(identifier);
			}
			nameCell.appendChild(button);
			if (employee.freelancerMode) appendBadge(nameCell, 'ts-freelancer-badge', translate('Freelancer'));
			if (employee.isActive === false) appendBadge(nameCell, 'ts-inactive-badge', translate('Inactive'));
			appendCell(row, 'hr-user-target', utils.minToHm(finiteMinutes(employee.dailyMin, 480)));
			appendCell(row, 'hr-user-balance', utils.minToHm(finiteMinutes(employee.overtimeMinutes)));
			appendCell(row, 'hr-user-last-entry', employee.lastEntryDate ? utils.formatDate(new Date(employee.lastEntryDate)) : '-');
			const actualDays = daysSince(employee.lastEntryDate);
			appendCell(row, 'hr-user-days-since', actualDays === null ? '' : String(actualDays));
			appendCell(row, 'hr-user-errors', employeeWarnings(employee));
			body.appendChild(row);
		}
		const count = document.getElementById('hr-employee-count');
		if (count) count.textContent = translate('{visible} of {total} employees', { visible: visibleEmployees.length, total: allEmployees.length });
		const message = document.getElementById('hr-employee-list-message');
		if (message) message.textContent = !allEmployees.length ? translate('No employees are assigned.') : !visibleEmployees.length ? translate('No matching employees.') : '';
		updateHrStats();
	}

	async function loadHrUserList() {
		if (!document.getElementById('hr-userlist')) return;
		const generation = ++listGeneration;
		const message = document.getElementById('hr-employee-list-message');
		const retry = document.getElementById('hr-employee-list-retry');
		if (message) message.textContent = translate('Loading employees…');
		if (retry) retry.hidden = true;
		try {
			await ensureWarningThresholds();
			const employees = await timesheet.api('/api/hr/userlist');
			if (generation !== listGeneration) return;
			if (!Array.isArray(employees)) throw new Error('Invalid HR employee list');
			// UID, rather than display name, identifies a person across groups and edits.
			allEmployees = [...new Map(employees.filter(employee => employee && typeof employee.id === 'string' && employee.id).map(employee => [employee.id, employee])).values()];
			listLoaded = true;
			renderEmployees();
		} catch (error) {
			if (generation !== listGeneration) return;
			allEmployees = [];
			renderEmployees();
			if (message) message.textContent = translate('Employee list could not be loaded.');
			if (retry) retry.hidden = false;
			console.warn('Failed to load HR user list:', error);
		}
	}

	function closeFilters(restoreFocus = false) {
		const popup = document.getElementById('hr-employee-filter-popup');
		if (popup) popup.hidden = true;
		const button = document.getElementById('hr-employee-filter-button');
		button?.setAttribute('aria-expanded', 'false');
		if (restoreFocus) button?.focus();
	}

	function positionFilters() {
		const popup = document.getElementById('hr-employee-filter-popup');
		const button = document.getElementById('hr-employee-filter-button');
		if (!popup || popup.hidden || !button) return;
		const anchor = button.getBoundingClientRect();
		const size = popup.getBoundingClientRect();
		popup.style.left = `${Math.max(12, Math.min(window.innerWidth - size.width - 12, anchor.right - size.width))}px`;
		popup.style.top = `${Math.max(12, Math.min(window.innerHeight - size.height - 12, anchor.bottom + 8))}px`;
	}

	function initializeFilters() {
		const button = document.getElementById('hr-employee-filter-button');
		const popup = document.getElementById('hr-employee-filter-popup');
		const search = document.getElementById('hr-employee-search');
		const mode = document.getElementById('hr-employee-filter-mode');
		button?.addEventListener('click', () => {
			if (!popup) return;
			popup.hidden = !popup.hidden;
			button.setAttribute('aria-expanded', String(!popup.hidden));
			positionFilters();
			if (!popup.hidden) search?.focus();
		});
		document.getElementById('hr-employee-filter-close')?.addEventListener('click', () => closeFilters(true));
		popup?.addEventListener('keydown', event => {
			if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeFilters(true); }
		});
		document.addEventListener('click', event => {
			if (popup && !popup.hidden && !popup.contains(event.target) && !button?.contains(event.target)) closeFilters();
		});
		window.addEventListener('resize', positionFilters);
		window.addEventListener('scroll', positionFilters, true);
		search?.addEventListener('input', () => { employeeFilter.name = search.value; renderEmployees(); });
		mode?.addEventListener('change', () => { employeeFilter.mode = mode.value; renderEmployees(); });
		document.getElementById('hr-employee-filter-clear')?.addEventListener('click', () => {
			employeeFilter.mode = 'active'; employeeFilter.name = '';
			if (mode) mode.value = 'active';
			if (search) search.value = '';
			renderEmployees();
		});
		document.getElementById('hr-employee-list-retry')?.addEventListener('click', loadHrUserList);
	}

	// Initialize HR module
	function initializeHrHandlers() {
		if (initialized || !document.getElementById('hr-userlist')) return;
		initialized = true;
		initializeFilters();
		timesheet.employmentStatus?.onChange(() => { if (listLoaded) loadHrUserList(); });
		// Load HR user entries on button click
		document.addEventListener('click', async event => {
			const userButton = event.target.closest('.hr-load-user');
			if (!userButton) {
				return;
			}
			const userId = userButton.dataset.user;
			if (!userId) {
				return;
			}
			closeFilters();
			timesheet.employmentStatus?.setHrEmployee(userId, allEmployees.find(employee => employee.id === userId));
			const tabHrSection = document.getElementById('tab-hr');
			const hrConfigRow = tabHrSection?.querySelector('.hr-config-row');
			const hrDailyMinInput = hrConfigRow?.querySelector('.config-daily-min');
			const hrStateInput = hrConfigRow?.querySelector('.config-state');
			if (tabHrSection) {
				tabHrSection.querySelectorAll('.ts-hr-section').forEach(section => section.style.display = 'none');
			}
			appState.hrCurrentMonth = new Date();
			timesheet.main?.updateHrMonthDisplay?.();
			if (hrDailyMinInput) {
				hrDailyMinInput.value = '08:00';
			}
			if (hrStateInput) {
				hrStateInput.value = '';
			}
			try {
				const userConfig = await timesheet.api(`/api/hr/config/${encodeURIComponent(userId)}`);
				const dailyMinimumMinutes = utils.pickDailyMin(userConfig) ?? 480;
				const state = typeof userConfig?.state === 'string' ? userConfig.state : '';
				if (hrDailyMinInput) {
					hrDailyMinInput.value = utils.minToHm(dailyMinimumMinutes);
				}
				if (hrStateInput) {
					hrStateInput.value = state;
				}
			} catch {
				if (hrDailyMinInput) {
					hrDailyMinInput.value = '08:00';
				}
				if (hrStateInput) {
					hrStateInput.value = '';
				}
			}
			await timesheet.entries.loadUserEntries(userId, appState.hrCurrentMonth);
			await timesheet.entries.refreshOvertimeTotal(userId, document.getElementById('tab-hr'));
			timesheet.dom.refresh();
			if (timesheet.dom.hrUserEntries) {
				timesheet.dom.hrUserEntries.style.display = 'block';
			}
			if (timesheet.dom.hrUserTitle) {
				const span = timesheet.dom.hrUserTitle.querySelector('span');
				if (span) {
					span.textContent = userId;
				}
			}
		});

		// Handle HR back button
		document.getElementById('hr-back-button')?.addEventListener('click', () => {
			const tabHrSection = document.getElementById('tab-hr');
			if (!tabHrSection) {
				return;
			}
			tabHrSection.querySelectorAll('.ts-hr-section').forEach(section => {
				if (section.id === 'hr-user-entries') {
					section.style.display = 'none';
				} else {
					section.style.display = '';
				}
			});
		});
	}

	// Shared HR interface
	hrModule.init = initializeHrHandlers;
	hrModule.loadHrUserList = loadHrUserList;
	hrModule.updateHrStats = updateHrStats;
})();
