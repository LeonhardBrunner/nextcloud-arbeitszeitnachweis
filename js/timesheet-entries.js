(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}
	const appState = timesheet.state;
	const utils = timesheet.util;
	timesheet.entries = timesheet.entries || {};
	const entryModule = timesheet.entries;

	// Effective time rules and holiday caches
	appState.ruleThresholdsByUser = appState.ruleThresholdsByUser || new Map();

	async function loadRuleThresholds(userId = null) {
		const targetUserId = userId || appState.currentUserId;
		if (!targetUserId) {
			appState.ruleThresholds = timesheet.util.RULE_DEFAULTS;
			return appState.ruleThresholds;
		}
		if (appState.ruleThresholdsByUser.has(targetUserId)) {
			const cachedThresholds = appState.ruleThresholdsByUser.get(targetUserId);
			if (!userId || targetUserId === appState.currentUserId) {
				appState.ruleThresholds = cachedThresholds;
			}
			return cachedThresholds;
		}
		try {
			const path = userId ? `/api/rules/effective/${encodeURIComponent(targetUserId)}` : '/api/rules/effective';
			const data = await timesheet.api(path);
			if (data && typeof data === 'object') {
				appState.ruleThresholdsByUser.set(targetUserId, data);
				if (!userId || targetUserId === appState.currentUserId) {
					appState.ruleThresholds = data;
				}
				return data;
			}
		} catch (error) {
			console.warn('⚠️ Failed to load rule thresholds:', error);
		}

		// Cache the same fallback after a failed lookup.
		const fallback = timesheet.util.RULE_DEFAULTS;
		appState.ruleThresholdsByUser.set(targetUserId, fallback);
		if (!userId || targetUserId === appState.currentUserId) {
			appState.ruleThresholds = fallback;
		}
		return fallback;
	}

	function getCachedRuleThresholds(userId = null) {
		const targetUserId = userId || appState.currentUserId;
		return targetUserId && appState.ruleThresholdsByUser.get(targetUserId) || appState.ruleThresholds || timesheet.util.RULE_DEFAULTS;
	}

	// Cache for holidays data
	async function loadHolidays(year, stateCode) {
		if (!stateCode) {
			return {};
		}
		const cacheKey = `${stateCode}_${year}`;
		if (appState.holidayCache.has(cacheKey)) {
			return appState.holidayCache.get(cacheKey);
		}
		try {
			const data = await timesheet.api(`/api/holidays?year=${year}&state=${encodeURIComponent(stateCode)}`);
			if (data && typeof data === 'object') {
				appState.holidayCache.set(cacheKey, data);
				return data;
			}
		} catch (error) {
			console.warn(`⚠️ Failed to load holidays for ${year} ${stateCode}:`, error);
		}
		const emptyHolidayMap = {};
		appState.holidayCache.set(cacheKey, emptyHolidayMap);
		return emptyHolidayMap;
	}

	// Get the effective daily minimum minutes based on user config and input
	function getEffectiveDailyMinimum(contextRoot) {
		const configuredDailyMinimum = utils.pickDailyMin(appState.userConfig);
		let inputDailyMinimum = null;
		if (contextRoot) {
			const input = contextRoot.querySelector('.config-daily-min');
			if (input) {
				inputDailyMinimum = utils.hmToMin(input.value || '');
			}
		} else {
			const input = document.getElementById('config-daily-min-mine');
			if (input) {
				inputDailyMinimum = utils.hmToMin(input.value || '');
			}
		}
		return inputDailyMinimum ?? configuredDailyMinimum ?? 480;
	}

	// Entry row rendering and monthly totals
	function createEntryTableRow(date, entry, holidayMap = {}, dailyMinimumMinutes = null, specialDaysEnabled = false, thresholds = null) {
		const dateString = utils.toLocalIsoDate(date);
		const dayIndex = date.getDay();
		const isHoliday = Object.prototype.hasOwnProperty.call(holidayMap, dateString);
		const isWeekend = dayIndex === 0 || dayIndex === 6;
		const statusText = isHoliday ? t(appState.appName, 'Holiday') : isWeekend ? t(appState.appName, 'Weekend') : '';
		const startMinutes = entry?.startMin ?? null;
		const endMinutes = entry?.endMin ?? null;
		const breakMinutes = entry?.breakMinutes ?? 0;
		const isIncompleteTime = startMinutes != null && endMinutes == null || startMinutes == null && endMinutes != null;
		const durationMinutes = utils.calcWorkMinutes(startMinutes, endMinutes, breakMinutes);
		const differenceMinutes = durationMinutes == null
			? null
			: specialDaysEnabled && (isHoliday || isWeekend)
				? durationMinutes
				: dailyMinimumMinutes != null ? durationMinutes - dailyMinimumMinutes : null;
		const warning = isIncompleteTime ? t(appState.appName, 'Time incomplete') : utils.checkRules({
			startMin: startMinutes,
			endMin: endMinutes,
			breakMinutes: breakMinutes
		}, dateString, holidayMap, thresholds);
		const startText = startMinutes != null ? utils.minToHm(startMinutes) : '';
		const endText = endMinutes != null ? utils.minToHm(endMinutes) : '';
		const breakText = utils.formatBreakValue(breakMinutes, appState.breakInputMode);
		const commentText = entry?.comment ?? '';
		const durationText = utils.minToHm(durationMinutes);
		const differenceText = utils.minToHm(differenceMinutes);
		const breakMode = appState.breakInputMode === 'hours' ? 'hours' : 'minutes';
		const breakInputType = breakMode === 'hours' ? 'time' : 'text';
		const breakInputAttrs = breakMode === 'hours' ? '' : ' inputmode="text" pattern="^-?\\d+(?::\\d+)?$"';
		const entryRow = document.createElement('tr');
		entryRow.dataset.date = dateString;
		if (entry?.id) {
			entryRow.dataset.id = entry.id;
		}
		entryRow.dataset.isSpecialDay = isHoliday || isWeekend ? '1' : '0';
		entryRow.dataset.savedStart = startText;
		entryRow.dataset.savedEnd = endText;
		entryRow.dataset.savedBreak = String(breakMinutes ?? 0);
		entryRow.dataset.savedComment = commentText;
		if (isHoliday || isWeekend) {
			entryRow.classList.add('is-weekend-row');
		}
		const today = new Date();
		if (date.getDate() === today.getDate() && date.getMonth() === today.getMonth() && date.getFullYear() === today.getFullYear()) {
			entryRow.classList.add('ts-today');
		}
		entryRow.innerHTML = `
      <td>${utils.formatDate(date)}</td>
      <td>${utils.dayLabel(dayIndex)}</td>
      <td class="ts-status ${isWeekend ? 'is-weekend' : ''}">${statusText}</td>
      <td><input type="time" class="startTime" value="${startText}"></td>
      <td><input type="${breakInputType}" class="breakMinutes"${breakInputAttrs} value="${breakText}"></td>
      <td><input type="time" class="endTime" value="${endText}"></td>
      <td class="ts-duration">${durationText}</td>
      <td class="ts-diff">${differenceText}</td>
      <td><textarea class="commentInput">${commentText}</textarea></td>
      <td class="ts-warn">${warning}</td>
    `;
		return entryRow;
	}

	// Update the total worked hours and overtime for the month in the given table body
	function updateMonthTotals(anyRow) {
		const tbody = anyRow.closest('tbody');
		if (!tbody) {
			return;
		}
		let totalMinutes = 0;
		let expectedWorkdayCount = 0;
		const specialDaysEnabled = tbody.dataset.specialDays === '1';
		tbody.querySelectorAll('tr').forEach(row => {
			const durationText = row.querySelector('.ts-duration')?.textContent.trim();
			const durationMinutes = utils.hmToMin(durationText);
			if (durationMinutes != null) {
				totalMinutes += durationMinutes;
				const isSpecialDay = row.dataset.isSpecialDay === '1' || row.classList.contains('is-weekend-row');
				if (!(specialDaysEnabled && isSpecialDay)) {
					expectedWorkdayCount++;
				}
			}
		});
		const container = anyRow.closest('#hr-user-entries');
		const summaryRoot = container || document;
		const workedHoursElement = summaryRoot.querySelector('#worked-hours-month');
		const overtimeElement = summaryRoot.querySelector('#overtime-month');
		const dailyMinimumMinutes = getEffectiveDailyMinimum(container);
		const overtime = totalMinutes - expectedWorkdayCount * dailyMinimumMinutes;
		if (workedHoursElement) {
			workedHoursElement.textContent = utils.minToHm(totalMinutes);
		}
		if (overtimeElement) {
			overtimeElement.textContent = utils.minToHm(overtime);
		}
	}

	// Refresh the total overtime hours for the given user
	async function refreshOvertimeTotal(userId = null, container = document) {
		try {
			const targetUserId = userId || appState.currentUserId;
			if (!targetUserId) {
				return;
			}
			const totalOvertimeElement = container.querySelector('#overtime-total');
			if (!totalOvertimeElement) {
				return;
			}
			const data = await timesheet.api(`/api/overtime/summary?user=${encodeURIComponent(targetUserId)}`);
			totalOvertimeElement.textContent = utils.minToHm(data?.overtimeMinutes ?? 0);
		} catch (error) {
			console.error('❌ Failed to load total overtime hours:', error);
		}
	}

	// Load entries for the given user and month
	async function loadMonthEntries(userId = null, date = new Date()) {
		const {
			from,
			to
		} = utils.getMonthRange(date);
		const fromDateString = utils.toLocalIsoDate(from);
		const toDateString = utils.toLocalIsoDate(to);
		const query = userId
			? `/api/entries?user=${encodeURIComponent(userId)}&from=${fromDateString}&to=${toDateString}`
			: `/api/entries?from=${fromDateString}&to=${toDateString}`;
		const thresholds = await loadRuleThresholds(userId || appState.currentUserId);
		const entries = await timesheet.api(query).catch(error => {
			console.error('❌ Failed to load entries:', error);
			return [];
		});
		timesheet.dom.refresh();
		const tableBody = userId ? timesheet.dom.hrUserBody : timesheet.dom.tsBody;
		if (!tableBody) {
			return;
		}
		tableBody.innerHTML = '';
		const entriesByDate = {};
		(entries || []).forEach(entry => {
			if (entry?.workDate) {
				entriesByDate[entry.workDate] = entry;
			}
		});
		const year = from.getFullYear();
		let stateCode;
		if (userId) {
			const hrStateInput = document.querySelector('#tab-hr .config-state');
			stateCode = hrStateInput?.value;
		} else {
			const mineStateInput = document.querySelector('#tab-mine .config-state');
			stateCode = mineStateInput?.value || appState.userConfig?.state;
		}
		const holidayMap = await loadHolidays(year, stateCode);
		const container = userId ? document.getElementById('hr-user-entries') : null;
		const dailyMinimumMinutes = getEffectiveDailyMinimum(container);
		const specialDaysEnabled = await loadSpecialDayOvertimeSetting();
		tableBody.dataset.specialDays = specialDaysEnabled ? '1' : '0';
		const rowsFragment = document.createDocumentFragment();
		for (let currentDate = new Date(from); currentDate <= to; currentDate.setDate(currentDate.getDate() + 1)) {
			const dateKey = utils.toLocalIsoDate(currentDate);
			const entry = entriesByDate[dateKey];
			const row = createEntryTableRow(new Date(currentDate), entry, holidayMap, dailyMinimumMinutes, specialDaysEnabled, thresholds);
			rowsFragment.appendChild(row);
		}
		tableBody.appendChild(rowsFragment);
		const firstRow = tableBody.querySelector('tr');
		if (firstRow) {
			updateMonthTotals(firstRow);
		}
		await refreshMonthlyBalanceSnapshot(userId || appState.currentUserId, date, userId ? document.getElementById('hr-user-entries') : document);
	}

	// Entry editing: field values, preview and saved snapshots.
	function clearTimeField(input) {
		if (!input) {
			return;
		}
		input.value = '';
		// Force-reset segmented native time-input UI (e.g. "--:00").
		if (input.type === 'time') {
			input.type = 'text';
			input.value = '';
			input.type = 'time';
		}
	}

	// Keep selectors and saved dataset fields together for both save and delete.
	function getEntryRowFields(row) {
		return {
			startInput: row.querySelector('.startTime'),
			endInput: row.querySelector('.endTime'),
			breakInput: row.querySelector('.breakMinutes'),
			commentInput: row.querySelector('.commentInput'),
			warningCell: row.querySelector('.ts-warn'),
			durationCell: row.querySelector('.ts-duration'),
			differenceCell: row.querySelector('.ts-diff')
		};
	}

	function readSavedEntryValues(row) {
		return {
			savedStart: row.dataset.savedStart || '',
			savedEnd: row.dataset.savedEnd || '',
			savedBreak: row.dataset.savedBreak != null ? parseInt(row.dataset.savedBreak, 10) : 0,
			savedComment: String(row.dataset.savedComment ?? '').trim()
		};
	}

	function clearEntryRowSummary(fields, warning = '') {
		if (fields.warningCell) {
			fields.warningCell.textContent = warning;
		}
		if (fields.durationCell) {
			fields.durationCell.textContent = '--:--';
		}
		if (fields.differenceCell) {
			fields.differenceCell.textContent = '--:--';
		}
	}

	function updateCompleteEntryPreview(fields, entry, isHrView) {
		const startMinutes = utils.hmToMin(entry.start);
		const endMinutes = utils.hmToMin(entry.end);
		const durationMinutes = utils.calcWorkMinutes(startMinutes, endMinutes, entry.breakMinutes);
		let stateCode;
		if (isHrView) {
			const hrStateInput = document.querySelector('#tab-hr .config-state');
			stateCode = hrStateInput?.value;
		} else {
			const mineStateInput = document.querySelector('#tab-mine .config-state');
			stateCode = mineStateInput?.value || appState.userConfig?.state;
		}
		const holidayMap = appState.holidayCache.get(`${stateCode}_${entry.workDate.slice(0, 4)}`) || {};
		const baseDailyMinimumMinutes = getEffectiveDailyMinimum(isHrView ? document.getElementById('hr-user-entries') : null);
		const differenceMinutes = durationMinutes != null && baseDailyMinimumMinutes != null ? durationMinutes - baseDailyMinimumMinutes : null;
		const thresholds = getCachedRuleThresholds(isHrView ? document.querySelector('#hr-user-title span')?.textContent : appState.currentUserId);
		if (fields.warningCell) {
			fields.warningCell.textContent = utils.checkRules({
				startMin: startMinutes,
				endMin: endMinutes,
				breakMinutes: entry.breakMinutes
			}, entry.workDate, holidayMap, thresholds);
		}
		if (fields.durationCell) {
			fields.durationCell.textContent = utils.minToHm(durationMinutes);
		}
		if (fields.differenceCell) {
			fields.differenceCell.textContent = utils.minToHm(differenceMinutes);
		}
	}

	function storeSavedEntryValues(row, values, isCommentOnly) {
		if (!isCommentOnly) {
			row.dataset.savedStart = values.start;
			row.dataset.savedEnd = values.end;
			row.dataset.savedBreak = String(values.breakMinutes);
			row.dataset.savedComment = values.comment;
		} else {
			row.dataset.savedStart = '';
			row.dataset.savedEnd = '';
			row.dataset.savedBreak = '0';
			row.dataset.savedComment = values.comment;
		}
	}

	async function deleteEntryRow(row) {
		const entryId = row.dataset.id || null;
		if (!entryId) {
			return;
		}
		if (!utils.ensureWriteAllowed || !utils.ensureWriteAllowed()) {
			return;
		}
		const fields = getEntryRowFields(row);
		const {
			startInput,
			endInput,
			breakInput,
			commentInput
		} = fields;
		await timesheet.api(`/api/entries/${encodeURIComponent(entryId)}`, {
			method: 'DELETE'
		});
		delete row.dataset.id;
		clearTimeField(startInput);
		clearTimeField(endInput);
		if (breakInput) {
			breakInput.value = utils.formatBreakValue(0, appState.breakInputMode);
		}
		if (commentInput) {
			commentInput.value = '';
		}
		row.dataset.savedStart = '';
		row.dataset.savedEnd = '';
		row.dataset.savedBreak = '0';
		row.dataset.savedComment = '';
		clearEntryRowSummary(fields);
		updateMonthTotals(row);
		const isHrView = !!row.closest('#hr-user-entries');
		const targetUserId = isHrView ? document.querySelector('#hr-user-title span')?.textContent : appState.currentUserId;
		if (targetUserId) {
			await refreshOvertimeTotal(targetUserId, isHrView ? document.getElementById('tab-hr') : document);
		}
		utils.showRowSavedFeedback(row);
	}

	// Keep the lock and queued retry in this function so overlapping events share one save.
	async function saveEntryRowIfChanged(row) {
		if (row.dataset.saving === '1') {
			row.dataset.saveQueued = '1';
			return;
		}
		const isHrView = !!row.closest('#hr-user-entries');
		const fields = getEntryRowFields(row);
		const {
			startInput,
			endInput,
			breakInput,
			commentInput,
			warningCell
		} = fields;
		if (!startInput || !endInput || !breakInput) {
			return;
		}
		const workDate = row.dataset.date;
		if (!workDate) {
			return;
		}
		const startValue = (startInput.value || '').trim();
		const endValue = (endInput.value || '').trim();
		const comment = (commentInput?.value || '').trim();
		const hasStart = !!startValue;
		const hasEnd = !!endValue;
		if (!hasStart) {
			clearTimeField(startInput);
		}
		if (!hasEnd) {
			clearTimeField(endInput);
		}
		const hasBothTimes = hasStart && hasEnd;
		const hasNoTimes = !hasStart && !hasEnd;
		const isIncompleteTime = !hasNoTimes && !hasBothTimes;
		const hasEntryId = !!row.dataset.id;
		const {
			savedStart,
			savedEnd,
			savedBreak: savedBreakMinutes,
			savedComment
		} = readSavedEntryValues(row);
		if (hasNoTimes && comment.length === 0) {
			if (hasEntryId) {
				row.dataset.saving = '1';
				try {
					await deleteEntryRow(row);
				} catch (error) {
					console.error('❌ Auto-Delete failed:', error);
				} finally {
					delete row.dataset.saving;
				}
				if (row.dataset.saveQueued === '1') {
					delete row.dataset.saveQueued;
					await saveEntryRowIfChanged(row);
				}
			} else {
				clearEntryRowSummary(fields);
			}
			return;
		}

		// A comment can be saved without times; a partial time remains a separate case.
		const isCommentOnly = comment.length > 0 && hasNoTimes;
		let breakMinutes = 0;

		// Full or partial save
		if (!isCommentOnly) {
			breakMinutes = utils.parseBreakMinutesInput(breakInput.value);
			if (breakMinutes == null) {
				breakMinutes = Number.isFinite(savedBreakMinutes) ? savedBreakMinutes : 0;
				breakInput.value = utils.formatBreakValue(breakMinutes, appState.breakInputMode);
				if (!isIncompleteTime) {
					return;
				}
			} else {
				breakInput.value = utils.formatBreakValue(breakMinutes, appState.breakInputMode);
			}
			if (!isIncompleteTime) {
				updateCompleteEntryPreview(fields, {
					workDate,
					start: startValue,
					end: endValue,
					breakMinutes
				}, isHrView);

				// Check if anything changed
				if (startValue === savedStart && endValue === savedEnd && breakMinutes === savedBreakMinutes && comment === savedComment) {
					return;
				}
			} else {
				clearEntryRowSummary(fields, warningCell ? t(appState.appName, 'Time incomplete') : '');

				// Check if anything changed
				if (startValue === savedStart && endValue === savedEnd && breakMinutes === savedBreakMinutes && comment === savedComment) {
					return;
				}
			}
		} else {
			if (warningCell) {
				warningCell.textContent = '';
			}

			// Check if anything changed
			if (!(savedStart !== '' || savedEnd !== '' || (Number.isFinite(savedBreakMinutes) ? savedBreakMinutes : 0) !== 0) && comment === savedComment) {
				return;
			}
		}
		if (!utils.ensureWriteAllowed || !utils.ensureWriteAllowed()) {
			return;
		}
		row.dataset.saving = '1';
		try {
			let savedEntry;
			const payload = isCommentOnly ? {
				workDate,
				comment,
				commentOnly: 1
			} : {
				workDate,
				start: startValue,
				end: endValue,
				breakMinutes: breakMinutes,
				comment
			};
			if (hasEntryId) {
				savedEntry = await timesheet.api(`/api/entries/${encodeURIComponent(row.dataset.id)}`, {
					method: 'PUT',
					body: JSON.stringify(payload)
				});
			} else {
				const targetUserId = isHrView ? document.querySelector('#hr-user-title span')?.textContent : null;
				const createPath = isHrView && targetUserId ? `/api/entries?user=${encodeURIComponent(targetUserId)}` : `/api/entries`;
				savedEntry = await timesheet.api(createPath, {
					method: 'POST',
					body: JSON.stringify(payload)
				});
				if (savedEntry?.id) {
					row.dataset.id = savedEntry.id;
				}
			}
			if (isCommentOnly) {
				clearTimeField(startInput);
				clearTimeField(endInput);
				if (breakInput) {
					breakInput.value = utils.formatBreakValue(0, appState.breakInputMode);
				}
				clearEntryRowSummary(fields);
			}
			storeSavedEntryValues(row, {
				start: startValue,
				end: endValue,
				breakMinutes,
				comment
			}, isCommentOnly);
			updateMonthTotals(row);
			const targetUserId = isHrView ? document.querySelector('#hr-user-title span')?.textContent : appState.currentUserId;
			if (targetUserId) {
				await refreshOvertimeTotal(targetUserId, isHrView ? document.getElementById('tab-hr') : document);
			}
			utils.showRowSavedFeedback(row);
		} catch (error) {
			console.error('❌ Auto-Save failed:', error);
		} finally {
			delete row.dataset.saving;
			if (row.dataset.saveQueued === '1') {
				delete row.dataset.saveQueued;
				await saveEntryRowIfChanged(row);
			}
		}
	}

	function getCalendarMonthParts(date) {
		return {
			year: date.getFullYear(),
			month: date.getMonth() + 1
		};
	}

	// Balance snapshots and the cached special-day setting.
	async function refreshMonthlyBalanceSnapshot(userId, date, container = document) {
		if (!userId || !date) {
			return;
		}
		const {
			year,
			month
		} = getCalendarMonthParts(date);
		try {
			await timesheet.api(`/api/balances/${encodeURIComponent(userId)}/${year}/${month}`);
		} catch (error) {
			console.warn('Failed to refresh balance snapshot:', error);
		}
	}

	async function loadSpecialDayOvertimeSetting() {
		if (appState.specialDaysCheckValue != null) {
			return appState.specialDaysCheckValue;
		}
		if (appState.specialDaysCheckPromise) {
			return appState.specialDaysCheckPromise;
		}
		appState.specialDaysCheckPromise = (async () => {
			try {
				const settings = await timesheet.api('/settings/specialdays_check');
				appState.specialDaysCheckValue = !!settings?.check;
				return appState.specialDaysCheckValue;
			} catch (_) {
				appState.specialDaysCheckValue = false;
				return false;
			} finally {
				appState.specialDaysCheckPromise = null;
			}
		})();
		return appState.specialDaysCheckPromise;
	}

	// Shared entry interface
	entryModule.getHolidays = loadHolidays;
	entryModule.getEffectiveDailyMin = getEffectiveDailyMinimum;
	entryModule.createEntryRow = createEntryTableRow;
	entryModule.loadUserEntries = loadMonthEntries;
	entryModule.loadRuleThresholds = loadRuleThresholds;
	entryModule.getRuleThresholds = getCachedRuleThresholds;
	entryModule.updateWorkedHours = updateMonthTotals;
	entryModule.refreshOvertimeTotal = refreshOvertimeTotal;
	entryModule.refreshMonthlyBalanceStatus = refreshMonthlyBalanceSnapshot;
	entryModule.deleteEntryForRow = deleteEntryRow;
	entryModule.saveRowIfNeeded = saveEntryRowIfChanged;
})();
