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

	// Update HR statistics summary
	function updateHrStats() {
		timesheet.dom.refresh();
		const domCache = timesheet.dom;
		const userListElement = domCache.userListEl || document.getElementById('hr-userlist');
		if (!userListElement) {
			return;
		}
		const totalHoursElement = domCache.hrStatsTotalEl || document.getElementById('hr-stat-total-hours');
		const positiveOvertimeElement = domCache.hrStatsOvertimeEl || document.getElementById('hr-stat-total-overtime');
		const employeesWithOvertimeElement = domCache.hrStatsNOvertimeEl || document.getElementById('hr-stat-employees-overtime');
		const negativeOvertimeElement = domCache.hrStatsMinusOvertimeEl || document.getElementById('hr-stat-total-negative');
		const employeesWithNegativeOvertimeElement = domCache.hrStatsNMinusOvertimeEl || document.getElementById('hr-stat-employees-negative');
		const netOvertimeElement = domCache.hrStatsSumOvertimeEl || document.getElementById('hr-stat-sum-overtimes');
		let totalWorkedMinutes = 0;
		let totalOvertimeMinutes = 0;
		let employeesWithOvertimeCount = 0;
		let totalNegativeOvertimeMinutes = 0;
		let employeesWithNegativeOvertimeCount = 0;
		userListElement.querySelectorAll('tr').forEach(userRow => {
			const monthMinutes = parseInt(userRow.dataset.totalMinutesMonth || '0', 10);
			if (!Number.isNaN(monthMinutes)) {
				totalWorkedMinutes += monthMinutes;
			}
			const balanceText = (userRow.querySelector('.hr-user-balance')?.textContent || '').trim();
			const parsedBalanceMinutes = utils.hmToMin(balanceText);
			const minutes = typeof parsedBalanceMinutes === 'number' && Number.isFinite(parsedBalanceMinutes) ? parsedBalanceMinutes : 0;
			if (minutes > 0) {
				totalOvertimeMinutes += minutes;
				employeesWithOvertimeCount++;
			}
			if (minutes < 0) {
				totalNegativeOvertimeMinutes += minutes;
				employeesWithNegativeOvertimeCount++;
			}
		});
		if (totalHoursElement) {
			totalHoursElement.textContent = utils.minToHm(totalWorkedMinutes);
		}
		if (positiveOvertimeElement) {
			positiveOvertimeElement.textContent = utils.minToHm(totalOvertimeMinutes);
		}
		if (employeesWithOvertimeElement) {
			employeesWithOvertimeElement.textContent = String(employeesWithOvertimeCount);
		}
		if (negativeOvertimeElement) {
			negativeOvertimeElement.textContent = utils.minToHm(totalNegativeOvertimeMinutes);
		}
		if (employeesWithNegativeOvertimeElement) {
			employeesWithNegativeOvertimeElement.textContent = String(employeesWithNegativeOvertimeCount);
		}
		if (netOvertimeElement) {
			netOvertimeElement.textContent = utils.minToHm(totalOvertimeMinutes + totalNegativeOvertimeMinutes);
		}
	}

	// Load HR user list and their stats
	async function loadHrUserList() {
		timesheet.dom.refresh();
		const domCache = timesheet.dom;
		if (!domCache.userListEl) {
			return;
		}
		domCache.userListEl.innerHTML = '';
		try {
			const thresholds = await ensureWarningThresholds();
			const users = await timesheet.api('/api/hr/userlist');
			const userRowsFragment = document.createDocumentFragment();
			(users || []).forEach(({
				id,
				name,
				dailyMin: dailyMinimumMinutes,
				overtimeMinutes,
				totalMinutes: totalWorkedMinutes,
				lastEntryDate
			}) => {
				const userRow = document.createElement('tr');
				userRow.innerHTML = `
          <td><button type="button" data-user="${id}" class="hr-load-user">${name}</button></td>
          <td class="hr-user-target">--:--</td>
          <td class="hr-user-balance">--:--</td>
          <td class="hr-user-last-entry">-</td>
          <td class="hr-user-days-since"></td>
          <td class="hr-user-errors"></td>
        `;
				userRowsFragment.appendChild(userRow);
				const dailyMinutes = typeof dailyMinimumMinutes === 'number' && Number.isFinite(dailyMinimumMinutes) ? dailyMinimumMinutes : 480;
				const targetCell = userRow.querySelector('.hr-user-target');
				if (targetCell) {
					targetCell.textContent = utils.minToHm(dailyMinutes);
				}
				const totalMinutesMonth = typeof totalWorkedMinutes === 'number' && Number.isFinite(totalWorkedMinutes) ? totalWorkedMinutes : 0;
				userRow.dataset.totalMinutesMonth = String(totalMinutesMonth);
				const overtimeMinutesValue = typeof overtimeMinutes === 'number' && Number.isFinite(overtimeMinutes) ? overtimeMinutes : 0;
				const balanceCell = userRow.querySelector('.hr-user-balance');
				if (balanceCell) {
					balanceCell.textContent = utils.minToHm(overtimeMinutesValue);
				}
				const lastEntryCell = userRow.querySelector('.hr-user-last-entry');
				if (lastEntryCell && lastEntryDate) {
					lastEntryCell.textContent = utils.formatDate(new Date(lastEntryDate));
				}
				const daysSinceEntryCell = userRow.querySelector('.hr-user-days-since');
				const errorCell = userRow.querySelector('.hr-user-errors');
				const errors = [];
				let daysSinceLastEntry = null;
				if (lastEntryDate) {
					const today = new Date();
					const todayDateString = today.toISOString().slice(0, 10);
					daysSinceLastEntry = Math.floor((Date.parse(todayDateString) - Date.parse(lastEntryDate)) / (1000 * 60 * 60 * 24));
					if (daysSinceLastEntry < 0) {
						daysSinceLastEntry = 0;
					}
					if (daysSinceEntryCell) {
						daysSinceEntryCell.textContent = String(daysSinceLastEntry);
					}
					if (daysSinceLastEntry >= thresholds.noEntryDays) {
						errors.push(`${t(appState.appName, 'No entry for more than')} ${thresholds.noEntryDays} ${t(appState.appName, 'days')}`);
					}
				} else {
					errors.push(`${t(appState.appName, 'No entry for more than')} ${thresholds.noEntryDays} ${t(appState.appName, 'days')}`);
				}
				if (typeof overtimeMinutesValue === 'number') {
					if (overtimeMinutesValue >= thresholds.overtimeThresholdMinutes) {
						errors.push(t(appState.appName, 'Too much overtime'));
					} else if (overtimeMinutesValue <= -thresholds.negativeOvertimeThresholdMinutes) {
						errors.push(t(appState.appName, 'Too many negative hours'));
					}
				}
				if (errorCell) {
					errorCell.textContent = errors.join(', ');
				}
			});
			domCache.userListEl.appendChild(userRowsFragment);
			updateHrStats();
		} catch (error) {
			console.warn('Failed to load HR user list:', error);
		}
	}

	// Initialize HR module
	function initializeHrHandlers() {
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
