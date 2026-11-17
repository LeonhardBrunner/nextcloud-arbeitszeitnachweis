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
	let notificationSaveGeneration = 0;
	const weeklyMailData = document.getElementById('ts-weekly-mail-editor-data');
	const weeklyMailEditor = weeklyMailData ? JSON.parse(weeklyMailData.textContent) : null;
	const weeklyMailDrafts = new Map();
	let savedWeeklyMailTemplates = {};

	function setThreshold(input, value) {
		input.value = input.type === 'number' ? String(value) : utils.minToHm(value);
	}

	function loadHrNotificationSettings() {
		const section = document.getElementById('hr-notifications-section');
		if (!appState.currentUserId || !section) return Promise.resolve();
		if (notificationLoad) return notificationLoad;
		if (savedNotificationSettings && configModule.hasPendingHrNotificationSettings?.()) return Promise.resolve();
		const loadGeneration = notificationSaveGeneration;

		notificationLoad = (async () => {
			try {
				const settings = await timesheet.api('/api/hr/notifications');
				if (loadGeneration !== notificationSaveGeneration
					|| (savedNotificationSettings && configModule.hasPendingHrNotificationSettings?.())) return;
				applyHrNotificationSettings(section, settings);
				section.disabled = false;
				document.getElementById('hr-notification-load-error').hidden = true;
				configModule.updateHrNotificationSaveButton?.();
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

	let savedNotificationSettings;

	function applyHrNotificationSettings(section, settings) {
		appState.hrWarningThresholds = settings.warningThresholds;
		for (const input of section.querySelectorAll('[data-warning-threshold]')) {
			setThreshold(input, settings.warningThresholds[input.dataset.warningThreshold]);
		}
		for (const input of section.querySelectorAll('[data-mail-enabled]')) {
			input.checked = settings[input.dataset.mailEnabled];
		}
		savedWeeklyMailTemplates = settings.weeklyMailTemplates ?? {};
		savedNotificationSettings = readNotificationSettings(section);
	}

	function initNotificationMailEditor(dialog) {
		const textarea = document.getElementById('ts-reminder-mail-text');
		const audience = document.getElementById('ts-reminder-mail-audience');
		const scope = document.getElementById('ts-reminder-mail-scope');
		const placeholders = document.getElementById('ts-reminder-mail-placeholders');
		let edit;

		textarea.addEventListener('input', () => textarea.setCustomValidity(''));
		document.getElementById('ts-reminder-mail-reset').addEventListener('click', () => {
			textarea.value = edit.original;
			textarea.setCustomValidity('');
		});
		document.getElementById('ts-reminder-mail-cancel').addEventListener('click', () => dialog.close());
		dialog.addEventListener('close', () => { if (!dialog.open) edit = null; });
		dialog.querySelector('form').addEventListener('submit', event => {
			event.preventDefault();
			if (textarea.value.split('{entries}').length !== 2) {
				textarea.setCustomValidity(textarea.dataset.invalidPlaceholderMessage);
				textarea.reportValidity();
				return;
			}
			edit.apply(textarea.value === edit.original ? null : textarea.value);
			dialog.close();
		});

		return options => {
			edit = options;
			audience.textContent = edit.label;
			scope.textContent = scope.dataset[edit.mode];
			placeholders.textContent = placeholders.dataset[edit.mode];
			textarea.maxLength = edit.maxLength;
			textarea.value = edit.text ?? edit.original;
			textarea.setCustomValidity('');
			dialog.showModal();
		};
	}

	function personalNotificationChanges(settings) {
		const { warningThresholds, ...choices } = settings;
		const patch = Object.fromEntries(
			Object.entries(choices).filter(([key, value]) => value !== savedNotificationSettings[key])
		);
		const thresholds = Object.fromEntries(Object.entries(warningThresholds).filter(
			([key, value]) => value !== savedNotificationSettings.warningThresholds[key]
		));
		if (Object.keys(thresholds).length) patch.warningThresholds = thresholds;
		if (weeklyMailDrafts.size) {
			patch.weeklyMailTemplates = Object.fromEntries(Array.from(
				weeklyMailDrafts, ([alert, text]) => [alert, { [weeklyMailEditor.language]: text }]
			));
		}
		return patch;
	}

	function initHrNotificationSettings() {
		const section = document.getElementById('hr-notifications-section');
		const saveButton = document.getElementById('hr-notification-save');
		if (!section || !saveButton) return;

		const groupSection = document.querySelector('.hr-group-notifications');
		const errorStatus = document.getElementById('hr-notification-save-error');
		let saving = false;

		function hasPersonalChanges() {
			if (!savedNotificationSettings) return false;
			const draft = readNotificationSettings(section);
			return !draft || Object.keys(personalNotificationChanges(draft)).length > 0;
		}

		function updateSaveButton() {
			saveButton.disabled = saving || !(hasPersonalChanges() || timesheet.hrNotificationRules?.hasChanges());
		}
		configModule.updateHrNotificationSaveButton = updateSaveButton;
		configModule.hasPendingHrNotificationSettings = () => saving || hasPersonalChanges();

		const dialog = document.getElementById('ts-reminder-mail-dialog');
		if (dialog) {
			const openMailEditor = initNotificationMailEditor(dialog);
			configModule.openNotificationMailEditor = openMailEditor;

			if (weeklyMailEditor) section.addEventListener('click', event => {
				const button = event.target.closest('[data-edit-weekly-mail]');
				if (!button || !savedNotificationSettings) return;
				const alert = button.dataset.editWeeklyMail;
				const language = weeklyMailEditor.language;
				const persistedText = () => savedWeeklyMailTemplates[alert]?.[language] ?? null;
				openMailEditor({
					mode: 'weekly',
					label: document.getElementById(button.getAttribute('aria-describedby')).textContent,
					original: weeklyMailEditor.originals[alert],
					text: weeklyMailDrafts.has(alert) ? weeklyMailDrafts.get(alert) : persistedText(),
					maxLength: weeklyMailEditor.maxLength,
					apply(text) {
						if (text === persistedText()) weeklyMailDrafts.delete(alert);
						else weeklyMailDrafts.set(alert, text);
						errorStatus.textContent = '';
						updateSaveButton();
					}
				});
			});
		}

		section.addEventListener('input', () => {
			errorStatus.textContent = '';
			updateSaveButton();
		});
		saveButton.addEventListener('click', async () => {
			if (saveButton.disabled) return;
			const personal = savedNotificationSettings && readNotificationSettings(section);
			const group = timesheet.hrNotificationRules;
			if (savedNotificationSettings && !personal) {
				section.querySelector('input:invalid')?.reportValidity();
				return;
			}
			const personalPatch = personal ? personalNotificationChanges(personal) : {};
			const groupError = group?.validationError();
			if (groupError) {
				errorStatus.textContent = groupError;
				return;
			}

			saving = true;
			saveButton.disabled = true;
			section.inert = true;
			groupSection.inert = true;
			errorStatus.textContent = '';
			document.getElementById('hr-notification-saved').textContent = '';
			try {
				if (group?.hasChanges()) await group.save();
				if (Object.keys(personalPatch).length) {
					notificationSaveGeneration++;
					const result = await timesheet.api('/api/hr/notifications', {
						method: 'PUT',
						body: JSON.stringify(personalPatch)
					});
					weeklyMailDrafts.clear();
					applyHrNotificationSettings(section, result);
					if (document.getElementById('tab-hr')?.classList.contains('active')) {
						timesheet.hr?.loadHrUserList?.();
					}
				}
				timesheet.showHrNotificationSaved();
			} catch (error) {
				console.error('Failed to save HR notification settings', error);
				errorStatus.textContent = error.notificationMessage || t(appState.appName, 'Changes could not be saved. Please try again.');
			} finally {
				saving = false;
				section.inert = false;
				groupSection.inert = false;
				updateSaveButton();
			}
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
