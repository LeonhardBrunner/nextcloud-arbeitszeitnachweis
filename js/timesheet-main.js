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
	let savedBreakMode = 'minutes';
	let savedBreakRevision = '';
	let breakModeSaveQueue = Promise.resolve();
	let breakModeChangeVersion = 0;
	let breakModePageActive = true;

	function normalizeBreakMode(mode) {
		return mode === 'hours' ? 'hours' : 'minutes';
	}

	function pendingBreakModeStorageKey() {
		return appState.currentUserId ? `timesheet:pending-break-input-mode:${encodeURIComponent(appState.currentUserId)}` : null;
	}

	function readPendingBreakMode() {
		const storageKey = pendingBreakModeStorageKey();
		if (!storageKey) {
			return null;
		}
		try {
			const pendingPreference = JSON.parse(window.sessionStorage.getItem(storageKey) || 'null');
			if (
				(pendingPreference?.mode === 'hours' || pendingPreference?.mode === 'minutes')
				&& typeof pendingPreference.changeId === 'string'
			) {
				return pendingPreference;
			}
		} catch {
			// Server persistence remains available when browser storage is restricted.
		}
		return null;
	}

	function rememberPendingBreakMode(pendingPreference) {
		const storageKey = pendingBreakModeStorageKey();
		if (!storageKey) {
			return;
		}
		try {
			window.sessionStorage.setItem(storageKey, JSON.stringify(pendingPreference));
		} catch {
			// The temporary reload bridge is optional; saving continues on the server.
		}
	}

	function clearPendingBreakMode(changeId) {
		if (readPendingBreakMode()?.changeId !== changeId) {
			return;
		}
		try {
			window.sessionStorage.removeItem(pendingBreakModeStorageKey());
		} catch {
			// Restricted storage must not interrupt the preference update.
		}
	}

	function initializeBreakModePreference() {
		const applicationRoot = document.getElementById('timesheet-app');
		const savedMode = applicationRoot?.dataset.breakInputMode;
		savedBreakMode = normalizeBreakMode(savedMode ?? appState.breakInputMode);
		savedBreakRevision = applicationRoot?.dataset.breakInputRevision || '';
		const pendingPreference = readPendingBreakMode();
		appState.breakInputMode = pendingPreference?.mode ?? savedBreakMode;
		if (pendingPreference) {
			// A reload can finish before the previous page's request reaches the server.
			saveBreakModePreference(pendingPreference.mode);
		}
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

	function isBreakModePreference(preference) {
		return preference
			&& (preference.breakInputMode === 'hours' || preference.breakInputMode === 'minutes')
			&& typeof preference.revision === 'string'
			&& /^[a-f0-9]{64}$/i.test(preference.revision);
	}

	function readBreakModeConflict(saveError) {
		try {
			const conflict = JSON.parse(saveError.message);
			return conflict?.code === 'preference_conflict' && isBreakModePreference(conflict) ? conflict : null;
		} catch {
			return null;
		}
	}

	async function persistBreakModeChoice(mode, changeVersion, changeId) {
		const maximumConflictRetries = 3;
		let conflictRetries = 0;
		while (breakModePageActive) {
			try {
				const preference = await timesheet.api('/api/preferences/break-input-mode', {
					method: 'PUT',
					body: JSON.stringify({ breakInputMode: mode, revision: savedBreakRevision }),
					keepalive: true
				});
				if (!isBreakModePreference(preference)) {
					throw new Error('Invalid break display preference response');
				}
				savedBreakMode = preference.breakInputMode;
				savedBreakRevision = preference.revision;
				if (changeVersion === breakModeChangeVersion) {
					clearPendingBreakMode(changeId);
				}
				return;
			} catch (saveError) {
				const conflict = readBreakModeConflict(saveError);
				if (conflict) {
					savedBreakMode = conflict.breakInputMode;
					savedBreakRevision = conflict.revision;
					if (!breakModePageActive || changeVersion !== breakModeChangeVersion) {
						return;
					}
					// Rebase only the latest choice when another page saved first.
					if (conflictRetries < maximumConflictRetries) {
						const retryDelayMilliseconds = 25 * (2 ** conflictRetries);
						conflictRetries++;
						await new Promise(resolve => window.setTimeout(resolve, retryDelayMilliseconds));
						if (!breakModePageActive || changeVersion !== breakModeChangeVersion) {
							return;
						}
						continue;
					}
				}
				if (!breakModePageActive) {
					return;
				}
				console.warn('Could not save break display preference:', saveError);
				if (changeVersion === breakModeChangeVersion) {
					setBreakMode(savedBreakMode);
					clearPendingBreakMode(changeId);
				}
				timesheet.notify(t(appState.appName, 'Could not save the break display setting. Please try again.'));
				return;
			}
		}
	}

	function saveBreakModePreference(mode) {
		const normalizedMode = normalizeBreakMode(mode);
		const changeVersion = ++breakModeChangeVersion;
		const changeId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
		rememberPendingBreakMode({ mode: normalizedMode, changeId });

		// Preserve the order of quick changes without replacing a newer visible choice.
		breakModeSaveQueue = breakModeSaveQueue.then(() => persistBreakModeChoice(normalizedMode, changeVersion, changeId));
		return breakModeSaveQueue;
	}

	function bindBreakToggle() {
		window.addEventListener('pagehide', () => { breakModePageActive = false; });
		window.addEventListener('pageshow', event => {
			breakModePageActive = true;
			const pendingPreference = event.persisted ? readPendingBreakMode() : null;
			if (pendingPreference) {
				setBreakMode(pendingPreference.mode);
				saveBreakModePreference(pendingPreference.mode);
			}
		});
		document.addEventListener('change', async event => {
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
			await saveBreakModePreference(mode);
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
		initializeBreakModePreference();
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
		// Offer guidance only after the existing application is ready.
		await timesheet.tour?.init();
	}

	// Start initialization on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initializeApplication);
	} else {
		initializeApplication();
	}
})();
