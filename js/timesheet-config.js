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

	// HR email notification settings.
	async function loadHrNotificationSettings() {
		if (!appState.currentUserId) {
			return;
		}
		timesheet.dom.refresh();
		const domCache = timesheet.dom;
		if (!domCache.hrNotificationsSection) {
			return;
		}
		try {
			const settings = await timesheet.api('/api/hr/notifications');
			appState.hrNotificationSettings = settings || null;
			if (!domCache.hrMailNoEntryEnabled) {
				return;
			}
			domCache.hrMailNoEntryEnabled.checked = !!settings?.noEntryEnabled;
			domCache.hrMailNoEntryDays.value = String(settings?.noEntryDays ?? 14);
			domCache.hrMailOvertimeEnabled.checked = !!settings?.overtimeEnabled;
			domCache.hrMailOvertimeThreshold.value = utils.minToHm(settings?.overtimeThresholdMinutes ?? 600).replace('-', '');
			domCache.hrMailNegativeEnabled.checked = !!settings?.negativeOvertimeEnabled;
			domCache.hrMailNegativeThreshold.value = utils.minToHm(settings?.negativeOvertimeThresholdMinutes ?? 600).replace('-', '');
		} catch (error) {
			console.warn('⚠️ Could not load HR notification settings', error);
		}
	}

	async function saveHrNotificationSettings() {
		if (!appState.currentUserId) {
			return;
		}
		timesheet.dom.refresh();
		const domCache = timesheet.dom;
		if (!domCache.hrNotificationsSection) {
			return;
		}
		const noEntryDays = parseInt(domCache.hrMailNoEntryDays?.value || '14', 10);
		const overtimeThresholdMinutes = utils.hmToMin(domCache.hrMailOvertimeThreshold?.value || '10:00');
		const negativeOvertimeThresholdMinutes = utils.hmToMin(domCache.hrMailNegativeThreshold?.value || '10:00');
		const payload = {
			noEntryEnabled: !!domCache.hrMailNoEntryEnabled?.checked,
			noEntryDays: Number.isFinite(noEntryDays) ? Math.min(365, Math.max(1, noEntryDays)) : 14,
			overtimeEnabled: !!domCache.hrMailOvertimeEnabled?.checked,
			overtimeThresholdMinutes: typeof overtimeThresholdMinutes === 'number' && Number.isFinite(overtimeThresholdMinutes)
				? Math.max(0, overtimeThresholdMinutes) : 600,
			negativeOvertimeEnabled: !!domCache.hrMailNegativeEnabled?.checked,
			negativeOvertimeThresholdMinutes: typeof negativeOvertimeThresholdMinutes === 'number' && Number.isFinite(negativeOvertimeThresholdMinutes)
				? Math.max(0, negativeOvertimeThresholdMinutes) : 600
		};
		try {
			const result = await timesheet.api('/api/hr/notifications', {
				method: 'PUT',
				body: JSON.stringify(payload)
			});
			appState.hrNotificationSettings = result || payload;
			timesheet.notify(t(appState.appName, 'Saved'));
		} catch (error) {
			console.error('❌ Failed to save HR notification settings:', error);
			timesheet.notify(t(appState.appName, 'Save failed'));
		}
	}

	// HR overview warning thresholds.
	async function loadHrWarningThresholds() {
		if (!appState.currentUserId) {
			return;
		}
		timesheet.dom.refresh();
		const domCache = timesheet.dom;
		if (!domCache.hrWarningThresholdsSection) {
			return;
		}
		try {
			const settings = await timesheet.api('/api/hr/warnings');
			appState.hrWarningThresholds = settings || null;
			if (!domCache.hrWarnNoEntryDays) {
				return;
			}
			domCache.hrWarnNoEntryDays.value = String(settings?.noEntryDays ?? 14);
			domCache.hrWarnOvertimeThreshold.value = utils.minToHm(settings?.overtimeThresholdMinutes ?? 600).replace('-', '');
			domCache.hrWarnNegativeThreshold.value = utils.minToHm(settings?.negativeOvertimeThresholdMinutes ?? 600).replace('-', '');
		} catch (error) {
			console.warn('Could not load HR warning thresholds', error);
		}
	}

	async function saveHrWarningThresholds() {
		if (!appState.currentUserId) {
			return;
		}
		timesheet.dom.refresh();
		const domCache = timesheet.dom;
		if (!domCache.hrWarningThresholdsSection) {
			return;
		}
		const noEntryDays = parseInt(domCache.hrWarnNoEntryDays?.value || '14', 10);
		const overtimeThresholdMinutes = utils.hmToMin(domCache.hrWarnOvertimeThreshold?.value || '10:00');
		const negativeOvertimeThresholdMinutes = utils.hmToMin(domCache.hrWarnNegativeThreshold?.value || '10:00');
		const payload = {
			noEntryDays: Number.isFinite(noEntryDays) ? Math.min(365, Math.max(1, noEntryDays)) : 14,
			overtimeThresholdMinutes: typeof overtimeThresholdMinutes === 'number' && Number.isFinite(overtimeThresholdMinutes)
				? Math.max(0, overtimeThresholdMinutes) : 600,
			negativeOvertimeThresholdMinutes: typeof negativeOvertimeThresholdMinutes === 'number' && Number.isFinite(negativeOvertimeThresholdMinutes)
				? Math.max(0, negativeOvertimeThresholdMinutes) : 600
		};
		try {
			const result = await timesheet.api('/api/hr/warnings', {
				method: 'PUT',
				body: JSON.stringify(payload)
			});
			appState.hrWarningThresholds = result || payload;
			timesheet.notify(t(appState.appName, 'Saved'));
			const hrTabActive = document.getElementById('tab-hr')?.classList.contains('active');
			if (hrTabActive && timesheet.hr?.loadHrUserList) {
				timesheet.hr.loadHrUserList();
			}
		} catch (error) {
			console.error('Failed to save HR warning thresholds:', error);
			timesheet.notify(t(appState.appName, 'Save failed'));
		}
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
				try {
					await timesheet.api(`/api/hr/config/${encodeURIComponent(targetUserId)}`, {
						method: 'PUT',
						body: JSON.stringify({
							dailyMin: dailyMinimumMinutes,
							state: stateCode
						})
					});
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
					console.error('❌ Failed to save configuration:', error);
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

		// Notification autosave has its own loading guard and timer.
		if (domCache.hrNotificationsSection) {
			let isLoadingSettings = true;
			let saveTimer = null;
			const scheduleSave = () => {
				if (isLoadingSettings) {
					return;
				}
				clearTimeout(saveTimer);
				saveTimer = setTimeout(() => saveHrNotificationSettings(), 450);
			};
			const inputs = [
				domCache.hrMailNoEntryEnabled,
				domCache.hrMailNoEntryDays,
				domCache.hrMailOvertimeEnabled,
				domCache.hrMailOvertimeThreshold,
				domCache.hrMailNegativeEnabled,
				domCache.hrMailNegativeThreshold
			].filter(Boolean);
			inputs.forEach(settingsInput => {
				settingsInput.addEventListener('change', scheduleSave);
				settingsInput.addEventListener('input', scheduleSave);
			});
			(async () => {
				await loadHrNotificationSettings();
				isLoadingSettings = false;
			})();
		}

		// Warning autosave has its own loading guard and timer.
		if (domCache.hrWarningThresholdsSection) {
			let isLoadingSettings = true;
			let saveTimer = null;
			const scheduleSave = () => {
				if (isLoadingSettings) {
					return;
				}
				clearTimeout(saveTimer);
				saveTimer = setTimeout(() => saveHrWarningThresholds(), 450);
			};
			const inputs = [domCache.hrWarnNoEntryDays, domCache.hrWarnOvertimeThreshold, domCache.hrWarnNegativeThreshold].filter(Boolean);
			inputs.forEach(settingsInput => {
				settingsInput.addEventListener('change', scheduleSave);
				settingsInput.addEventListener('input', scheduleSave);
			});
			(async () => {
				await loadHrWarningThresholds();
				isLoadingSettings = false;
			})();
		}
	}

	// Shared configuration interface
	configModule.init = initializeConfigHandlers;
	configModule.loadUserConfig = loadUserConfig;
	configModule.loadHrWarningThresholds = loadHrWarningThresholds;
})();
