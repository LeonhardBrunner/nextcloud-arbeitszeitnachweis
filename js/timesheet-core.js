(function () {
	"use strict";

	window.Timesheet = window.Timesheet || {};
	const timesheet = window.Timesheet;
	timesheet.state = timesheet.state || {};
	const appState = timesheet.state;

	// Application state and Nextcloud session
	appState.appName = "timesheet";

	function readRequestToken() {
		try {
			if (window.OC && OC.requestToken) {
				return String(OC.requestToken);
			}
		} catch {}
		return null;
	}
	appState.token = readRequestToken();
	appState.sessionExpiredNotified = false;
	const DEFAULT_RULE_THRESHOLDS = {
		breakShortMinutes: 30,
		breakShortHours: 6,
		breakLongMinutes: 45,
		breakLongHours: 9,
		maxHours: 10
	};
	appState.ruleThresholds = {
		...DEFAULT_RULE_THRESHOLDS
	};

	// Current user ID
	appState.currentUserId = function getCurrentUserId() {
		try {
			if (window.OC && typeof OC.getCurrentUser === "function") {
				return OC.getCurrentUser()?.uid ?? null;
			}
			if (window.OC && OC.currentUser) {
				return OC.currentUser;
			}
		} catch (error) {
			console.warn("⚠️ Could not determine currentUserId:", error);
		}
		return null;
	}();
	appState.userConfig = null;
	appState.breakInputMode = 'minutes';

	// Current month tracking
	appState.currentMonth = new Date();
	appState.hrCurrentMonth = new Date();

	// Holiday cache
	appState.holidayCache = new Map();

	// Hovered row tracking for copy functionality
	appState.tsHoveredRow = null;
	appState.TS_ROW_SCOPE = '#tab-mine tbody tr, #tab-hr tbody tr, #hr-user-entries tbody tr';

	// DOM references shared by the feature modules
	timesheet.dom = timesheet.dom || {};
	timesheet.dom.refresh = function refreshDomCache() {
		this.tsBody = document.getElementById('ts-body');
		this.hrUserBody = document.getElementById('hr-user-body');
		this.hrUserEntries = document.getElementById('hr-user-entries');
		this.hrUserTitle = document.getElementById('hr-user-title');
		this.userListEl = document.getElementById('hr-userlist');
		this.hrStatsTotalEl = document.getElementById('hr-stat-total-hours');
		this.hrStatsOvertimeEl = document.getElementById('hr-stat-total-overtime');
		this.hrStatsNOvertimeEl = document.getElementById('hr-stat-employees-overime');
		this.hrStatsMinusOvertimeEl = document.getElementById('hr-stat-total-negative');
		this.hrStatsNMinusOvertimeEl = document.getElementById('hr-stat-employees-negative');
		this.hrStatsSumOvertimeEl = document.getElementById('hr-stat-sum-overtimes');
		this.dailyMinInputs = Array.from(document.querySelectorAll('.config-daily-min'));
		this.stateInputs = Array.from(document.querySelectorAll('.config-state'));
		this.saveConfigBtns = Array.from(document.querySelectorAll('.save-config-btn'));
		this.hrMailNoEntryEnabled = document.getElementById('hr-mail-no-entry-enabled');
		this.hrMailNoEntryDays = document.getElementById('hr-mail-no-entry-days');
		this.hrMailOvertimeEnabled = document.getElementById('hr-mail-overtime-enabled');
		this.hrMailOvertimeThreshold = document.getElementById('hr-mail-overtime-threshold');
		this.hrMailNegativeEnabled = document.getElementById('hr-mail-negative-enabled');
		this.hrMailNegativeThreshold = document.getElementById('hr-mail-negative-threshold');
		this.hrNotificationsSection = document.getElementById('hr-notifications-section');
		this.hrWarnNoEntryDays = document.getElementById('hr-warn-no-entry-days');
		this.hrWarnOvertimeThreshold = document.getElementById('hr-warn-overtime-threshold');
		this.hrWarnNegativeThreshold = document.getElementById('hr-warn-negative-threshold');
		this.hrWarningThresholdsSection = document.getElementById('hr-warning-thresholds-section');
		return this;
	};

	// Simple selectors
	timesheet.$ = (selector, queryRoot = document) => queryRoot.querySelector(selector);
	timesheet.$$ = (selector, queryRoot = document) => Array.from(queryRoot.querySelectorAll(selector));

	// Notification utility
	function notify(message) {
		try {
			if (window.OC?.Notification?.showTemporary) {
				OC.Notification.showTemporary(message);
			} else {
				console.log(message);
			}
		} catch (error) {
			console.log(message);
		}
	}

	function notifySessionExpired() {
		if (appState.sessionExpiredNotified) {
			return;
		}
		appState.sessionExpiredNotified = true;
		notify(t(appState.appName, 'Session expired. Please reload the page.'));
	}

	function refreshRequestToken() {
		const token = readRequestToken();
		if (token) {
			appState.token = token;
		}
		return token;
	}

	function ensureWriteAllowed() {
		const token = refreshRequestToken();
		if (!token) {
			notifySessionExpired();
			return false;
		}
		return true;
	}

	// Locale selection and date formatting
	function resolveLocale() {
		try {
			if (window.OC && typeof window.OC.getLocale === 'function') {
				return String(OC.getLocale());
			}
			if (window.OC && typeof window.OC.getLanguage === 'function') {
				return String(OC.getLanguage());
			}
		} catch {}
		return document.documentElement.getAttribute('lang') || navigator.language || undefined;
	}

	function normalizeLocale(locale) {
		if (!locale) {
			return undefined;
		}
		return String(locale).replace('_', '-');
	}
	const userLocale = normalizeLocale(resolveLocale());

	// Month formatter
	function buildMonthFormatter() {
		try {
			return new Intl.DateTimeFormat(userLocale, {
				month: 'long',
				year: 'numeric'
			});
		} catch {
			return new Intl.DateTimeFormat(undefined, {
				month: 'long',
				year: 'numeric'
			});
		}
	}
	const monthFormatter = buildMonthFormatter();
	timesheet.util = timesheet.util || {};
	const utils = timesheet.util;
	utils.USER_LOCALE = userLocale;

	// Day keys
	const WEEKDAY_TRANSLATION_KEYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

	// Day label by index
	function dayLabel(dayIndex) {
		const dayKey = WEEKDAY_TRANSLATION_KEYS[dayIndex] ?? '';
		return dayKey ? t(appState.appName, dayKey) : '';
	}

	// "DD.MM.YYYY" format
	function formatDate(date) {
		const day = String(date.getDate()).padStart(2, '0');
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const year = date.getFullYear();
		return `${day}.${month}.${year}`;
	}

	// "YYYY-MM-DD" format
	function toLocalIsoDate(date) {
		const year = date.getFullYear();
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const day = String(date.getDate()).padStart(2, '0');
		return `${year}-${month}-${day}`;
	}

	// Gets first and last date of month for given date
	function getMonthRange(date) {
		const year = date.getFullYear();
		const monthIndex = date.getMonth();
		return {
			from: new Date(year, monthIndex, 1),
			to: new Date(year, monthIndex + 1, 0)
		};
	}

	// Formats minutes to "HH:MM" format
	function formatMinutesAsTime(minutes) {
		if (minutes == null) {
			return '--:--';
		}
		const sign = minutes < 0 ? '-' : '';
		const absoluteMinutes = Math.abs(minutes);
		const hoursText = String(Math.floor(absoluteMinutes / 60)).padStart(2, '0');
		const minutesText = String(absoluteMinutes % 60).padStart(2, '0');
		return `${sign}${hoursText}:${minutesText}`;
	}

	// Parses "HH:MM" format to minutes
	function parseTimeAsMinutes(timeText) {
		if (!timeText) {
			return null;
		}
		let sign = 1;
		let unsignedTimeText = String(timeText).trim();
		if (unsignedTimeText.startsWith('-')) {
			sign = -1;
			unsignedTimeText = unsignedTimeText.slice(1);
		}
		const timeParts = unsignedTimeText.split(':');
		if (timeParts.length !== 2) {
			return null;
		}
		const hours = Number(timeParts[0]);
		const minutes = Number(timeParts[1]);
		if (Number.isNaN(hours) || Number.isNaN(minutes)) {
			return null;
		}
		return sign * (hours * 60 + minutes);
	}

	function normalizeEndMinutes(startMinutes, endMinutes) {
		if (startMinutes == null || endMinutes == null) {
			return endMinutes;
		}
		return endMinutes < startMinutes ? endMinutes + 24 * 60 : endMinutes;
	}

	function calculateWorkMinutes(startMinutes, endMinutes, breakMinutes = 0) {
		if (startMinutes == null || endMinutes == null) {
			return null;
		}
		const normalizedEndMinutes = normalizeEndMinutes(startMinutes, endMinutes);
		const normalizedBreakMinutes = typeof breakMinutes === 'number' && Number.isFinite(breakMinutes) ? breakMinutes : Number(breakMinutes) || 0;
		return Math.max(0, normalizedEndMinutes - startMinutes - normalizedBreakMinutes);
	}

	// Picks daily minimum minutes from config
	function getConfiguredDailyMinimum(userConfig) {
		if (!userConfig) {
			return null;
		}
		if (Number.isFinite(userConfig.dailyMin)) {
			return userConfig.dailyMin;
		}
		if (Number.isFinite(userConfig.workMinutes)) {
			return userConfig.workMinutes;
		}
		if (typeof userConfig.workMinutes === 'string') {
			return parseTimeAsMinutes(userConfig.workMinutes);
		}
		return null;
	}

	// Checks timesheet entry against rules, returns issues as string
	function getEntryRuleWarnings(entry, dateString = null, holidayMap = {}, thresholds = null) {
		const effectiveThresholds = thresholds || appState.ruleThresholds || DEFAULT_RULE_THRESHOLDS;
		const startMinutes = entry.startMin ?? parseTimeAsMinutes(entry.start);
		let endMinutes = entry.endMin ?? parseTimeAsMinutes(entry.end);
		if (startMinutes == null || endMinutes == null) {
			return '';
		}
		endMinutes = normalizeEndMinutes(startMinutes, endMinutes);
		const breakMinutes = entry.breakMinutes ?? 0;
		const grossMinutes = endMinutes - startMinutes;
		const workMinutes = Math.max(0, grossMinutes - breakMinutes);
		const issues = [];
		if (workMinutes > (effectiveThresholds.maxHours || DEFAULT_RULE_THRESHOLDS.maxHours) * 60) {
			issues.push(t(appState.appName, 'Above maximum time'));
		}
		if (
			workMinutes > (effectiveThresholds.breakLongHours || DEFAULT_RULE_THRESHOLDS.breakLongHours) * 60
			&& breakMinutes < (effectiveThresholds.breakLongMinutes || DEFAULT_RULE_THRESHOLDS.breakLongMinutes)
		) {
			issues.push(t(appState.appName, 'Break too short'));
		} else if (
			workMinutes > (effectiveThresholds.breakShortHours || DEFAULT_RULE_THRESHOLDS.breakShortHours) * 60
			&& breakMinutes < (effectiveThresholds.breakShortMinutes || DEFAULT_RULE_THRESHOLDS.breakShortMinutes)
		) {
			issues.push(t(appState.appName, 'Break too short'));
		}
		if (dateString) {
			const date = new Date(dateString);
			const isSunday = date.getDay() === 0;
			const isHoliday = !!(holidayMap && holidayMap[dateString]);
			if (isSunday) {
				issues.push(t(appState.appName, 'Sunday work not allowed'));
			}
			if (isHoliday) {
				issues.push(t(appState.appName, 'Holiday work not allowed'));
			}
		}
		return issues.join(', ');
	}

	// Shows visual feedback for saved row
	function showRowSavedFeedback(row) {
		row.classList.add('ts-row-saved');
		setTimeout(() => row.classList.remove('ts-row-saved'), 1200);
	}

	// Sets the configuration input fields
	function setConfigInputs(minutes, stateCode) {
		const timeText = minutes != null ? formatMinutesAsTime(minutes) : '';
		const domCache = timesheet.dom;
		(domCache.dailyMinInputs || []).forEach(input => {
			input.value = timeText;
		});
		(domCache.stateInputs || []).forEach(input => {
			input.value = stateCode ?? '';
		});
	}

	// Checks if there is any text selection
	function hasSelection() {
		const selectedText = window.getSelection?.();
		return !!selectedText && String(selectedText).trim() !== '';
	}

	// Checks if element is one of the timesheet cell input fields
	function isTimesheetCellField(element) {
		if (!element || !element.classList) {
			return false;
		}
		return element.classList.contains('startTime')
			|| element.classList.contains('endTime')
			|| element.classList.contains('breakMinutes')
			|| element.classList.contains('commentInput');
	}

	// Parses break minutes input, returns minutes or null if invalid
	function parseBreakMinutesInput(rawValue) {
		const trimmedInput = String(rawValue ?? '').trim();
		if (trimmedInput === '') {
			return 0;
		}
		let sign = 1;
		let unsignedInput = trimmedInput;
		if (unsignedInput.startsWith('-')) {
			sign = -1;
			unsignedInput = unsignedInput.slice(1).trim();
		}
		if (unsignedInput === '') {
			return 0;
		}
		if (!unsignedInput.includes(':')) {
			if (!/^\d+$/.test(unsignedInput)) {
				return null;
			}
			return sign * Number(unsignedInput);
		}
		const timeMatch = unsignedInput.match(/^(\d+)\s*:\s*(\d+)$/);
		if (!timeMatch) {
			return null;
		}
		const hours = Number(timeMatch[1]);
		const minutes = Number(timeMatch[2]);
		if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
			return null;
		}
		return sign * (hours * 60 + minutes);
	}

	function formatBreakValue(minutes, mode = appState.breakInputMode) {
		const rawValue = typeof minutes === 'number' && Number.isFinite(minutes) ? minutes : Number(minutes);
		const safeMinutes = Number.isFinite(rawValue) ? rawValue : 0;
		if (mode === 'hours') {
			return formatMinutesAsTime(safeMinutes);
		}
		return String(safeMinutes);
	}

	// "Month Year" format
	function monthLabel(date) {
		return monthFormatter.format(date);
	}

	// "YYYY-MM" format
	function toLocalMonthString(date) {
		const year = date.getFullYear();
		const month = String(date.getMonth() + 1).padStart(2, '0');
		return `${year}-${month}`;
	}

	// JSON requests, request tokens and session errors
	async function requestJson(path, options = {}) {
		const method = String(options.method || 'GET').toUpperCase();
		const isWrite = !['GET', 'HEAD', 'OPTIONS'].includes(method);
		const token = isWrite ? ensureWriteAllowed() ? appState.token : null : refreshRequestToken();
		if (isWrite && !token) {
			throw new Error('Session expired');
		}
		const url = OC.generateUrl(`/apps/${appState.appName}${path}`);
		const response = await fetch(url, {
			...options,
			headers: {
				'Content-Type': 'application/json',
				...(token ? {
					'requesttoken': token
				} : {}),
				...(options.headers || {})
			}
		});
		if (response.status === 401 || response.status === 403) {
			notifySessionExpired();
		}
		if (!response.ok) {
			const errorText = await response.text().catch(() => response.statusText);
			throw new Error(errorText || `HTTP ${response.status}`);
		}
		return response.json().catch(() => null);
	}

	// Shared interface used by the feature scripts.
	timesheet.notify = notify;
	utils.dayLabel = dayLabel;
	utils.formatDate = formatDate;
	utils.toLocalIsoDate = toLocalIsoDate;
	utils.getMonthRange = getMonthRange;
	utils.minToHm = formatMinutesAsTime;
	utils.hmToMin = parseTimeAsMinutes;
	utils.normalizeEndMin = normalizeEndMinutes;
	utils.calcWorkMinutes = calculateWorkMinutes;
	utils.pickDailyMin = getConfiguredDailyMinimum;
	utils.checkRules = getEntryRuleWarnings;
	utils.showRowSavedFeedback = showRowSavedFeedback;
	utils.setConfigInputs = setConfigInputs;
	utils.hasSelection = hasSelection;
	utils.isTimesheetCellField = isTimesheetCellField;
	utils.parseBreakMinutesInput = parseBreakMinutesInput;
	utils.formatBreakValue = formatBreakValue;
	utils.monthLabel = monthLabel;
	utils.toLocalMonthStr = toLocalMonthString;
	utils.ensureWriteAllowed = ensureWriteAllowed;
	timesheet.api = requestJson;
	utils.RULE_DEFAULTS = DEFAULT_RULE_THRESHOLDS;
})();
