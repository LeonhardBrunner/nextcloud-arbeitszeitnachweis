(function () {
	"use strict";

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}
	const appState = timesheet.state;
	const utils = timesheet.util;
	timesheet.config = timesheet.config || {};
	const configModule = timesheet.config;

	// Load user configuration
	async function loadUserConfig(targetUserId) {
		try {
			const userConfig = await timesheet.api(`/api/hr/config/${encodeURIComponent(targetUserId)}`);
			const dailyMinimumMinutes = utils.pickDailyMin(userConfig);
			appState.userConfig = {
				...(userConfig || {}),
				dailyMin: dailyMinimumMinutes,
				workMinutes: dailyMinimumMinutes
			};
			if (dailyMinimumMinutes != null) {
				utils.setConfigInputs(dailyMinimumMinutes, userConfig?.state ?? null);
			} else if (userConfig?.state != null) {
				utils.setConfigInputs(null, userConfig.state);
			}
		} catch (error) {
			console.warn('⚠️ Could not load user configuration', error);
		}
	}

	// The HR list and weekly email use the same personal thresholds.
	let notificationLoad = null;

	function setThreshold(input, value) {
		input.value = input.type === 'number' ? String(value) : utils.minToHm(value);
	}

	function loadHrNotificationSettings() {
		const section = document.getElementById('hr-notifications-section');
		if (!appState.currentUserId || !section) return Promise.resolve();
		if (notificationLoad) return notificationLoad;

		notificationLoad = (async () => {
			try {
				const settings = await timesheet.api('/api/hr/notifications');
				appState.hrWarningThresholds = settings.warningThresholds;
				for (const input of section.querySelectorAll('[data-warning-threshold]')) {
					setThreshold(input, settings.warningThresholds[input.dataset.warningThreshold]);
				}
				for (const input of section.querySelectorAll('[data-mail-enabled]')) {
					input.checked = settings[input.dataset.mailEnabled];
				}
				section.disabled = false;
				document.getElementById('hr-notification-load-error').hidden = true;
			} catch (error) {
				document.getElementById('hr-notification-load-error').hidden = false;
				console.warn('Could not load HR notification settings', error);
			}
		})().finally(() => { notificationLoad = null; });
		return notificationLoad;
	}

	function readThreshold(input) {
		return input.type === 'number' ? input.valueAsNumber : utils.hmToMin(input.value);
	}

	function readNotificationSettings(section) {
		const inputError = section.querySelector('#hr-notification-input-error');
		const valid = [...section.querySelectorAll('input')].every(input => input.checkValidity());
		inputError.hidden = valid;
		if (!valid) return null;

		const settings = { warningThresholds: {} };
		for (const input of section.querySelectorAll('[data-warning-threshold]')) {
			settings.warningThresholds[input.dataset.warningThreshold] = readThreshold(input);
		}
		for (const input of section.querySelectorAll('[data-mail-enabled]')) {
			settings[input.dataset.mailEnabled] = input.checked;
		}
		return settings;
	}

	function initHrNotificationSettings() {
		const section = document.getElementById('hr-notifications-section');
		if (!section) return;

		let saveTimer;
		let saving = Promise.resolve();
		section.addEventListener('input', () => {
			if (section.disabled) return;
			clearTimeout(saveTimer);
			saveTimer = setTimeout(() => {
				const settings = readNotificationSettings(section);
				if (!settings) return;
				saving = saving.then(async () => {
					try {
						const result = await timesheet.api('/api/hr/notifications', {
							method: 'PUT',
							body: JSON.stringify(settings)
						});
						appState.hrWarningThresholds = result.warningThresholds;
						timesheet.notify(t(appState.appName, 'Saved'));
						if (document.getElementById('tab-hr')?.classList.contains('active')) {
							timesheet.hr?.loadHrUserList?.();
						}
					} catch (error) {
						console.error('Failed to save HR notification settings', error);
						timesheet.notify(t(appState.appName, 'Save failed'));
					}
				});
			}, 450);
		});
		void loadHrNotificationSettings();
	}

	// Initialize configuration handlers
	function initializeConfigHandlers() {
		timesheet.dom.refresh();
		const domCache = timesheet.dom;

		// Save configuration button handlers
		(domCache.saveConfigBtns || []).forEach(saveButton => {
			saveButton.addEventListener('click', async () => {
				if (!appState.currentUserId) {
					return;
				}
				const container = saveButton.closest('.hr-config-row') || document;
				const dailyInput = container.querySelector('.config-daily-min') || domCache.dailyMinInputs?.[0];
				const stateInput = container.querySelector('.config-state') || domCache.stateInputs?.[0];
				const timeText = dailyInput?.value || '';
				const stateCode = stateInput?.value || '';
				const dailyMinimumMinutes = utils.hmToMin(timeText) ?? 480;
				const isHrView = !!saveButton.closest('#tab-hr');
				let targetUserId = appState.currentUserId;
				if (isHrView) {
					const selectedUserId = timesheet.dom.hrUserTitle?.querySelector('span')?.textContent?.trim();
					if (selectedUserId) {
						targetUserId = selectedUserId;
					}
				}
				saveButton.disabled = true;
				const saveTarget = JSON.stringify(['config', targetUserId]);
				try {
					const saveRequest = timesheet.api(`/api/hr/config/${encodeURIComponent(targetUserId)}`, {
						method: 'PUT',
						body: JSON.stringify({
							dailyMin: dailyMinimumMinutes,
							state: stateCode
						})
					});
					await (timesheet.entries.trackPendingSave ? timesheet.entries.trackPendingSave(saveRequest, saveTarget) : saveRequest);
					timesheet.entries.clearSaveFailure?.(saveTarget);
					if (targetUserId === appState.currentUserId) {
						appState.userConfig = {
							...(appState.userConfig || {}),
							dailyMin: dailyMinimumMinutes,
							workMinutes: dailyMinimumMinutes,
							state: stateCode
						};
						utils.setConfigInputs(dailyMinimumMinutes, stateCode);
						const firstRow = timesheet.dom.tsBody?.querySelector('tr');
						if (firstRow) {
							timesheet.entries.updateWorkedHours(firstRow);
						}
						await timesheet.entries.refreshOvertimeTotal(appState.currentUserId, document);
					} else {
						const firstHrRow = timesheet.dom.hrUserBody?.querySelector('tr');
						if (firstHrRow) {
							timesheet.entries.updateWorkedHours(firstHrRow);
						}
						await timesheet.entries.refreshOvertimeTotal(targetUserId, document.getElementById('tab-hr'));
					}
				} catch (error) {
					timesheet.notify(t(appState.appName, 'Changes could not be saved. Please try again.'));
				} finally {
					saveButton.disabled = false;
				}
			});
		});

		// Update worked hours when daily minimum changes
		(domCache.dailyMinInputs || []).forEach(input => {
			input.addEventListener('input', () => {
				const isPersonalView = !!input.closest('#tab-mine');
				const isHrView = !!input.closest('#tab-hr');
				if (isPersonalView && timesheet.dom.tsBody) {
					const firstRow = timesheet.dom.tsBody.querySelector('tr');
					if (firstRow) {
						timesheet.entries.updateWorkedHours(firstRow);
					}
				}
				if (isHrView && timesheet.dom.hrUserBody) {
					const firstHrRow = timesheet.dom.hrUserBody.querySelector('tr');
					if (firstHrRow) {
						timesheet.entries.updateWorkedHours(firstHrRow);
					}
				}
			});
		});

		initHrNotificationSettings();
	}

	// Shared configuration interface
	configModule.init = initializeConfigHandlers;
	configModule.loadUserConfig = loadUserConfig;
	configModule.loadHrWarningThresholds = loadHrNotificationSettings;
})();
