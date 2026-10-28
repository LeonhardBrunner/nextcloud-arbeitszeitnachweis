(function () {
	"use strict";

	const timesheet = window.Timesheet;
	if (!timesheet) {
		console.error('Timesheet: core not loaded');
		return;
	}
	const appState = timesheet.state;
	const utils = timesheet.util;
	timesheet.main = timesheet.main || {};
	const mainModule = timesheet.main;

	// Update month display
	function updateMonthDisplay() {
		const monthDisplay = document.getElementById('month-display');
		if (monthDisplay) {
			monthDisplay.textContent = utils.monthLabel(appState.currentMonth);
		}
	}

	// Update HR month display
	function updateHrMonthDisplay() {
		const hrMonthDisplay = document.getElementById('hr-month-display');
		if (hrMonthDisplay) {
			hrMonthDisplay.textContent = utils.monthLabel(appState.hrCurrentMonth);
		}
	}

	// Expose functions
	mainModule.updateMonthDisplay = updateMonthDisplay;
	mainModule.updateHrMonthDisplay = updateHrMonthDisplay;

	// Bind month navigation buttons
	function bindMonthNavigation() {
		// Previous month button
		timesheet.$('#month-prev')?.addEventListener('click', () => {
			appState.currentMonth.setMonth(appState.currentMonth.getMonth() - 1);
			updateMonthDisplay();
			timesheet.entries.loadUserEntries(null, appState.currentMonth);
		});

		// Next month button
		timesheet.$('#month-next')?.addEventListener('click', () => {
			appState.currentMonth.setMonth(appState.currentMonth.getMonth() + 1);
			updateMonthDisplay();
			timesheet.entries.loadUserEntries(null, appState.currentMonth);
		});

		// Previous month button for HR view
		timesheet.$('#hr-month-prev')?.addEventListener('click', () => {
			appState.hrCurrentMonth.setMonth(appState.hrCurrentMonth.getMonth() - 1);
			updateHrMonthDisplay();
			const targetUserId = timesheet.dom.hrUserTitle?.querySelector('span')?.textContent;
			if (targetUserId) {
				timesheet.entries.loadUserEntries(targetUserId, appState.hrCurrentMonth);
			}
		});

		// Next month button for HR view
		timesheet.$('#hr-month-next')?.addEventListener('click', () => {
			appState.hrCurrentMonth.setMonth(appState.hrCurrentMonth.getMonth() + 1);
			updateHrMonthDisplay();
			const targetUserId = timesheet.dom.hrUserTitle?.querySelector('span')?.textContent;
			if (targetUserId) {
				timesheet.entries.loadUserEntries(targetUserId, appState.hrCurrentMonth);
			}
		});

		// Month display click to go to current month
		timesheet.$('#month-display')?.addEventListener('click', () => {
			const today = new Date();
			if (appState.currentMonth.getFullYear() === today.getFullYear() && appState.currentMonth.getMonth() === today.getMonth()) {
				return;
			}
			appState.currentMonth = today;
			updateMonthDisplay();
			timesheet.entries.loadUserEntries(null, appState.currentMonth);
		});

		// HR month display click to go to current month
		timesheet.$('#hr-month-display')?.addEventListener('click', () => {
			const today = new Date();
			if (appState.hrCurrentMonth.getFullYear() === today.getFullYear() && appState.hrCurrentMonth.getMonth() === today.getMonth()) {
				return;
			}
			appState.hrCurrentMonth = today;
			updateHrMonthDisplay();
			const targetUserId = timesheet.dom.hrUserTitle?.querySelector('span')?.textContent;
			if (targetUserId) {
				timesheet.entries.loadUserEntries(targetUserId, appState.hrCurrentMonth);
			}
		});
	}

	// Bind tab switching
	function bindTabSwitching() {
		timesheet.$$('.ts-tab').forEach(tabButton => {
			tabButton.addEventListener('click', () => {
				timesheet.$$('.ts-tab').forEach(tabButton => tabButton.classList.remove('active'));
				timesheet.$$('.ts-tabview').forEach(view => view.classList.remove('active'));
				tabButton.classList.add('active');
				const targetView = document.getElementById(`tab-${tabButton.dataset.tab}`);
				if (targetView) {
					targetView.classList.add('active');
				}
				if (tabButton.dataset.tab === 'hr') {
					timesheet.hr.loadHrUserList();
				}
			});
		});
	}

	// Bind auto-save on input blur and Enter key
	function bindAutoSave() {
		// Handle blur event for saving
		document.addEventListener('blur', async event => {
			const targetElement = event.target;
			if (!(targetElement instanceof HTMLElement)) {
				return;
			}
			if (
				!targetElement.classList.contains('startTime')
				&& !targetElement.classList.contains('endTime')
				&& !targetElement.classList.contains('breakMinutes')
				&& !targetElement.classList.contains('commentInput')
			) {
				return;
			}
			const row = targetElement.closest('tr');
			if (!row) {
				return;
			}
			await timesheet.entries.saveRowIfNeeded(row);
		}, true);

		// Handle Enter key for saving
		document.addEventListener('keydown', async event => {
			if (event.key !== 'Enter' || event.shiftKey) {
				return;
			}
			const targetElement = event.target;
			if (
				!targetElement.classList.contains('startTime')
				&& !targetElement.classList.contains('endTime')
				&& !targetElement.classList.contains('breakMinutes')
				&& !targetElement.classList.contains('commentInput')
			) {
				return;
			}
			event.preventDefault();
			const row = targetElement.closest('tr');
			if (!row) {
				return;
			}
			await timesheet.entries.saveRowIfNeeded(row);
		});
	}

	// Bind export buttons
	function bindExportButtons() {
		document.getElementById('export-mine-xlsx')?.addEventListener('click', () => timesheet.export.handleExportClick('mine'));
		document.getElementById('export-hr-xlsx')?.addEventListener('click', () => timesheet.export.handleExportClick('hr'));
	}

	// Bind auto-grow for comment textareas
	function bindCommentTextareaGrowth() {
		document.addEventListener('input', event => {
			const targetElement = event.target;
			if (targetElement && targetElement.matches('textarea.commentInput')) {
				resizeCommentTextarea(targetElement);
			}
		});
	}

	// Break input format and its matching controls.
	function normalizeBreakMode(mode) {
		return mode === 'hours' ? 'hours' : 'minutes';
	}

	function updateBreakInputs(mode) {
		const usesHoursFormat = mode === 'hours';
		document.querySelectorAll('input.breakMinutes').forEach(input => {
			const parsedMinutes = utils.parseBreakMinutesInput(input.value);
			if (parsedMinutes == null) {
				return;
			}
			input.type = usesHoursFormat ? 'time' : 'text';
			if (usesHoursFormat) {
				input.removeAttribute('inputmode');
				input.removeAttribute('pattern');
			} else {
				input.setAttribute('inputmode', 'text');
				input.setAttribute('pattern', '^-?\\d+(?::\\d+)?$');
			}
			input.value = utils.formatBreakValue(parsedMinutes, mode);
		});
	}

	function updateBreakToggleUI(mode) {
		const usesHoursFormat = mode === 'hours';
		document.querySelectorAll('.ts-break-toggle').forEach(toggle => {
			const switchInput = toggle.querySelector('.ts-break-toggle-switch');
			if (switchInput) {
				switchInput.checked = usesHoursFormat;
				switchInput.setAttribute('aria-checked', usesHoursFormat ? 'true' : 'false');
			}
			toggle.querySelectorAll('.ts-break-mode-option').forEach(label => {
				const active = label.dataset.breakModeLabel === mode;
				label.classList.toggle('is-active', active);
			});
		});
	}

	function setBreakMode(mode) {
		const normalizedMode = normalizeBreakMode(mode);
		appState.breakInputMode = normalizedMode;
		updateBreakInputs(normalizedMode);
		updateBreakToggleUI(normalizedMode);
	}

	function bindBreakToggle() {
		document.addEventListener('change', event => {
			const targetElement = event.target;
			if (!(targetElement instanceof Element)) {
				return;
			}
			const input = targetElement.closest('.ts-break-toggle-switch');
			if (!input) {
				return;
			}
			const mode = input.checked ? input.dataset.breakModeOn || 'hours' : input.dataset.breakModeOff || 'minutes';
			setBreakMode(mode);
		});
		setBreakMode(appState.breakInputMode || 'minutes');
	}

	// Auto-grow textarea function
	function resizeCommentTextarea(textarea) {
		textarea.style.height = '21px';
		textarea.style.height = `${textarea.scrollHeight}px`;
	}

	// Application startup: keep configuration, rules and entries in this order.
	async function initializeApplication() {
		timesheet.dom.refresh();
		timesheet.copy.init();
		timesheet.hr.init();
		timesheet.config.init();
		bindMonthNavigation();
		bindTabSwitching();
		bindAutoSave();
		bindExportButtons();
		bindCommentTextareaGrowth();
		bindBreakToggle();
		updateMonthDisplay();
		updateHrMonthDisplay();
		const userConfigReady = appState.currentUserId ? timesheet.config.loadUserConfig(appState.currentUserId) : Promise.resolve();
		const ruleThresholdsReady = timesheet.entries.loadRuleThresholds();
		await ruleThresholdsReady;
		await timesheet.entries.loadUserEntries(null, appState.currentMonth);
		await userConfigReady;
		await timesheet.entries.refreshOvertimeTotal(appState.currentUserId, document);
		document.querySelectorAll('textarea.commentInput').forEach(resizeCommentTextarea);
		const initialRow = document.querySelector('#ts-body tr');
		if (initialRow) {
			timesheet.entries.updateWorkedHours(initialRow);
		}
	}

	// Start initialization on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initializeApplication);
	} else {
		initializeApplication();
	}
})();
