(function () {
	'use strict';

	const settingsRoot = document.getElementById('timesheet-admin-settings');
	if (!settingsRoot) {
		return;
	}

	// Initial settings embedded by the admin template.
	function readEmbeddedJson(id) {
		const element = document.getElementById(id);
		if (!element) {
			return [];
		}
		try {
			return JSON.parse(element.textContent || "null") ?? [];
		} catch (error) {
			console.warn("Timesheet admin settings: invalid JSON in", id, error);
			return [];
		}
	}
	const allGroups = readEmbeddedJson('timesheet-all-groups-data');
	let accessRules = readEmbeddedJson('timesheet-hr-rules-data');
	let notifiedDuplicateEmployeeGroups = false;
	let ruleSaveQueue = Promise.resolve();
	let ruleRevision = 0;
	const rulesContainer = document.getElementById('timesheet-hr-rules');
	const addRuleButton = document.getElementById('timesheet-add-hr-rule');
	const specialDaysOvertime = document.getElementById('timesheet-specialdays-overtime');

	function notify(message) {
		try {
			if (window.OC?.Notification?.showTemporary) {
				OC.Notification.showTemporary(message);
			} else {
				console.log(message);
			}
		} catch {
			console.log(message);
		}
	}

	function createRuleId() {
		if (window.crypto?.randomUUID) {
			return crypto.randomUUID();
		}
		return 'r_' + Math.random().toString(16).slice(2) + "_" + Date.now();
	}

	// Rule defaults, numeric limits and employee-group ownership.
	const defaultRuleSettings = {
		priority: 1,
		breakShortMinutes: 30,
		breakShortHours: 6,
		breakLongMinutes: 45,
		breakLongHours: 9,
		maxHours: 10
	};
	const ruleValueRanges = {
		priority: [0, 9999],
		breakShortMinutes: [0, 600],
		breakShortHours: [0, 24],
		breakLongMinutes: [0, 600],
		breakLongHours: [0, 24],
		maxHours: [0, 24]
	};

	function clampNumericValue(value, minimumValue, maximumValue, fallback) {
		const numericValue = Number(value);
		if (!Number.isFinite(numericValue)) {
			return fallback;
		}
		return Math.min(maximumValue, Math.max(minimumValue, numericValue));
	}

	// Reminder settings stay separate from numeric time-rule thresholds.
	function normalizeReminderSettings(inputSettings = {}) {
		const settings = inputSettings && typeof inputSettings === 'object' ? inputSettings : {};
		const graceDays = Number(settings.graceDays);
		const weekdays = Array.isArray(settings.weekdays) ? settings.weekdays : [1, 2, 3, 4, 5];
		return {
			enabled: settings.enabled === true,
			graceDays: Number.isInteger(graceDays) && graceDays >= 1 && graceDays <= 365 ? graceDays : 14,
			weekdays: [...new Set(weekdays.filter(day => Number.isInteger(day) && day >= 1 && day <= 7))].sort((firstDay, secondDay) => firstDay - secondDay),
			startDate: typeof settings.startDate === 'string' ? settings.startDate.trim() : ''
		};
	}

	function currentReminderDate() {
		const dateParts = new Intl.DateTimeFormat('en-CA', {
			timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit'
		}).formatToParts(new Date());
		const values = Object.fromEntries(dateParts.map(part => [part.type, part.value]));
		return `${values.year}-${values.month}-${values.day}`;
	}

	function isValidReminderDate(value) {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
			return false;
		}
		const parsedDate = new Date(`${value}T00:00:00Z`);
		return Number.isFinite(parsedDate.getTime()) && parsedDate.toISOString().slice(0, 10) === value;
	}

	function normalizeRuleSettings(inputRule = {}) {
		const normalizedRule = {
			id: String(inputRule.id || "").trim(),
			hrGroups: Array.isArray(inputRule.hrGroups) ? inputRule.hrGroups : [],
			userGroups: Array.isArray(inputRule.userGroups) ? inputRule.userGroups : [],
			missingEntryReminders: normalizeReminderSettings(inputRule.missingEntryReminders)
		};
		Object.entries(defaultRuleSettings).forEach(([settingKey, defaultValue]) => {
			const [minimumValue, maximumValue] = ruleValueRanges[settingKey];
			normalizedRule[settingKey] = clampNumericValue(inputRule[settingKey], minimumValue, maximumValue, defaultValue);
		});
		return normalizedRule;
	}

	function nextPriorityValue(inputRules) {
		const values = (Array.isArray(inputRules) ? inputRules : []).map(rule => Number(rule?.priority)).filter(value => Number.isFinite(value));
		if (!values.length) {
			return defaultRuleSettings.priority;
		}
		return Math.max(...values) + 1;
	}

	function normalizeRules(inputRules) {
		const normalizedRules = [];
		(Array.isArray(inputRules) ? inputRules : []).forEach(rule => {
			if (!rule || typeof rule !== 'object') {
				return;
			}
			const id = String(rule.id || "").trim();
			if (!id) {
				return;
			}
			const hrGroups = Array.isArray(rule.hrGroups) ? rule.hrGroups : [];
			const userGroups = Array.isArray(rule.userGroups) ? rule.userGroups : [];
			const normalizeGroupIds = groupIds => {
				const uniqueGroupIds = new Set();
				groupIds.forEach(value => {
					const normalizedGroupId = String(value || "").trim();
					if (normalizedGroupId) {
						uniqueGroupIds.add(normalizedGroupId);
					}
				});
				return Array.from(uniqueGroupIds);
			};
			const normalizedRule = normalizeRuleSettings({
				...rule,
				id,
				hrGroups: normalizeGroupIds(hrGroups),
				userGroups: normalizeGroupIds(userGroups)
			});
			normalizedRules.push(normalizedRule);
		});
		return normalizedRules;
	}

	function removeDuplicateEmployeeGroups(inputRules) {
		const accessRules = Array.isArray(inputRules) ? inputRules : [];
		const assignedGroupIds = new Set();
		const removedGroupIds = [];
		accessRules.forEach(rule => {
			const uniqueGroupIds = [];
			(Array.isArray(rule.userGroups) ? rule.userGroups : []).forEach(groupId => {
				if (assignedGroupIds.has(groupId)) {
					removedGroupIds.push(groupId);
					return;
				}
				assignedGroupIds.add(groupId);
				uniqueGroupIds.push(groupId);
			});
			rule.userGroups = uniqueGroupIds;
		});
		return {
			rules: accessRules,
			removed: removedGroupIds
		};
	}

	function isEmployeeGroupAssignedElsewhere(inputRules, ruleId, groupId) {
		return (Array.isArray(inputRules) ? inputRules : []).some(rule => {
			if (rule.id === ruleId) {
				return false;
			}
			return Array.isArray(rule.userGroups) && rule.userGroups.includes(groupId);
		});
	}

	// Persist normalized rules using the existing form fields.
	async function saveRules(nextRules) {
		const url = OC.generateUrl('/apps/timesheet/settings/hr_access_rules');
		const formData = new FormData();
		formData.append('rules', JSON.stringify(nextRules));
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken
			},
			body: formData
		});
		if (!response.ok) {
			const errorText = await response.text().catch(() => '');
			throw new Error(errorText || `HTTP ${response.status}`);
		}
		const responseData = await response.json().catch(() => null);
		const serverRules = responseData?.rules;
		return normalizeRules(serverRules ?? nextRules);
	}

	// Rule-card markup and group controls.
	function escapeHtml(value) {
		const text = String(value);
		return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function renderHrGroupOptions() {
		return ['<option value="">' + escapeHtml(t('timesheet', 'Add group...')) + '</option>']
			.concat(allGroups.map(groupId => `<option value="${escapeHtml(groupId)}">${escapeHtml(groupId)}</option>`))
			.join('');
	}

	function renderEmployeeGroupOptions(rule, assignedEmployeeGroups) {
		const currentEmployeeGroups = new Set(Array.isArray(rule.userGroups) ? rule.userGroups : []);
		return ['<option value="">' + escapeHtml(t('timesheet', 'Add group...')) + '</option>'].concat(allGroups.map(groupId => {
			const disabled = assignedEmployeeGroups.has(groupId) && !currentEmployeeGroups.has(groupId);
			return `<option value="${escapeHtml(groupId)}"${disabled ? ' disabled' : ''}>${escapeHtml(groupId)}</option>`;
		})).join('');
	}

	function renderGroupChips(groupIds, groupField) {
		if (!groupIds.length) {
			return "";
		}
		// Keep the label/action space explicit so source formatting preserves the HTML.
		return groupIds.map(groupId => {
			return `
              <span class="ts-chip">${escapeHtml(groupId)}${' '}
                <a href="#" class="ts-chip-remove" data-group="${escapeHtml(groupId)}" data-kind="${escapeHtml(groupField)}" title="${escapeHtml(t('timesheet', 'Remove'))}" aria-label="${escapeHtml(t('timesheet', 'Remove'))}">&times;</a>
              </span>`;
		}).join('');
	}

	function renderReminderSettings(rule) {
		const settings = normalizeReminderSettings(rule.missingEntryReminders);
		const weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
		const weekdayInputs = weekdayNames.map((name, index) => {
			const weekday = index + 1;
			return `<label class="ts-reminder-weekday"><input type="checkbox" class="ts-reminder-input" data-reminder-day="${weekday}"${settings.weekdays.includes(weekday) ? ' checked' : ''}><span>${escapeHtml(t('timesheet', name))}</span></label>`;
		}).join('');
		return `
			<div class="ts-rule-section ts-reminder-settings">
				<div class="ts-rule-section-title">${escapeHtml(t('timesheet', 'Missing time entry reminders'))}</div>
				<label class="ts-reminder-toggle"><input type="checkbox" class="ts-reminder-input" data-reminder-key="enabled"${settings.enabled ? ' checked' : ''}><span>${escapeHtml(t('timesheet', 'Send reminders to employees and responsible HR'))}</span></label>
				<p class="ts-setting-desc">${escapeHtml(t('timesheet', 'Checks run daily. Open dates are collected in one email, with at least seven days between emails of the same kind.'))}</p>
				<div class="ts-rule-grid ts-rule-grid-compact">
					<div class="ts-rule-col">
						<label>${escapeHtml(t('timesheet', 'Wait before the first reminder'))}
							<span class="ts-inline"><input type="number" min="1" max="365" step="1" class="ts-reminder-input" data-reminder-key="graceDays" value="${settings.graceDays}"><span>${escapeHtml(t('timesheet', 'calendar days'))}</span></span>
						</label>
					</div>
					<div class="ts-rule-col">
						<label>${escapeHtml(t('timesheet', 'Check entries starting on'))}<input type="date" class="ts-reminder-input" data-reminder-key="startDate" value="${escapeHtml(settings.startDate)}"></label>
					</div>
				</div>
				<div class="ts-setting-desc">${escapeHtml(t('timesheet', 'Days that require a time entry; public holidays in the employee state are excluded.'))}</div>
				<div class="ts-reminder-weekdays">${weekdayInputs}</div>
				<p class="ts-setting-desc">${escapeHtml(t('timesheet', 'Existing members use the chosen start date. Later members are checked from their first detection. A state is required for the holiday check.'))}</p>
			</div>`;
	}

	function createRuleCard(rule, index, assignedEmployeeGroups) {
		const ruleCard = document.createElement('div');
		ruleCard.className = 'ts-rule';
		ruleCard.dataset.ruleId = rule.id;
		const hrGroupOptions = renderHrGroupOptions();
		const employeeGroupOptions = renderEmployeeGroupOptions(rule, assignedEmployeeGroups);
		const ruleSettings = normalizeRuleSettings(rule);
		ruleCard.innerHTML = `
        <div class="ts-rule-head">
          <div class="ts-rule-title">${escapeHtml(t("timesheet", "Group rule"))} ${index + 1}</div>
          <div class="ts-rule-head-actions">
            <label class="ts-rule-priority">
              <span>${escapeHtml(t("timesheet", "Priority"))}</span>
              <input type="number" min="0" max="9999" step="1" class="ts-rule-input ts-rule-priority-input" data-key="priority" value="${ruleSettings.priority}">
            </label>
            <a href="#" class="ts-rule-delete" title="${escapeHtml(t("timesheet", "Delete rule"))}" aria-label="${escapeHtml(t("timesheet", "Delete rule"))}">&times;</a>
          </div>
        </div>

        <div class="ts-rule-grid">
          <div class="ts-rule-col">
            <label>${escapeHtml(t("timesheet", "HR groups"))}</label>
            <div class="ts-chips" data-kind="hrGroups">${renderGroupChips(rule.hrGroups, "hrGroups")}</div>
            <select class="ts-rule-select" data-kind="hrGroups">${hrGroupOptions}</select>
          </div>

          <div class="ts-rule-col">
            <label>${escapeHtml(t("timesheet", "Employee groups"))}</label>
            <div class="ts-chips" data-kind="userGroups">${renderGroupChips(rule.userGroups, "userGroups")}</div>
            <select class="ts-rule-select" data-kind="userGroups">${employeeGroupOptions}</select>
          </div>
        </div>

        <div class="ts-rule-section">
          <div class="ts-rule-section-title">${escapeHtml(t("timesheet", "Time rules"))}</div>
          <div class="ts-rule-grid ts-rule-grid-compact">
            <div class="ts-rule-col">
              <label>${escapeHtml(t("timesheet", "Minimum break (short)"))}</label>
              <div class="ts-inline">
                <input type="number" min="0" max="600" step="5" class="ts-rule-input" data-key="breakShortMinutes" value="${ruleSettings.breakShortMinutes}">
                <span>${escapeHtml(t("timesheet", "minutes when working more than"))}</span>
                <input type="number" min="0" max="24" step="0.25" class="ts-rule-input" data-key="breakShortHours" value="${ruleSettings.breakShortHours}">
                <span>${escapeHtml(t("timesheet", "hours"))}</span>
              </div>
            </div>

            <div class="ts-rule-col">
              <label>${escapeHtml(t("timesheet", "Minimum break (long)"))}</label>
              <div class="ts-inline">
                <input type="number" min="0" max="600" step="5" class="ts-rule-input" data-key="breakLongMinutes" value="${ruleSettings.breakLongMinutes}">
                <span>${escapeHtml(t("timesheet", "minutes when working more than"))}</span>
                <input type="number" min="0" max="24" step="0.25" class="ts-rule-input" data-key="breakLongHours" value="${ruleSettings.breakLongHours}">
                <span>${escapeHtml(t("timesheet", "hours"))}</span>
              </div>
            </div>

            <div class="ts-rule-col">
              <label>${escapeHtml(t("timesheet", "No work allowed after (hours per day)"))}</label>
              <input type="number" min="0" max="24" step="0.25" class="ts-rule-input" data-key="maxHours" value="${ruleSettings.maxHours}">
            </div>
          </div>
        </div>
		${renderReminderSettings(rule)}
      `;
		return ruleCard;
	}

	function renderRules() {
		const normalizedRules = normalizeRules(accessRules);
		const uniqueGroupResult = removeDuplicateEmployeeGroups(normalizedRules);
		accessRules = uniqueGroupResult.rules;
		rulesContainer.innerHTML = '';
		if (uniqueGroupResult.removed.length && !notifiedDuplicateEmployeeGroups) {
			notifiedDuplicateEmployeeGroups = true;
			notify(t('timesheet', 'Some Employee groups were assigned to multiple rules. Duplicates were removed.'));
		}
		if (accessRules.length === 0) {
			const emptyMessage = document.createElement('div');
			emptyMessage.className = 'ts-rule-empty';
			emptyMessage.textContent = t('timesheet', 'No rules yet. Add one to restrict HR access based on groups.');
			rulesContainer.appendChild(emptyMessage);
			return;
		}
		const assignedEmployeeGroups = new Set();
		accessRules.forEach(rule => {
			(Array.isArray(rule.userGroups) ? rule.userGroups : []).forEach(groupId => assignedEmployeeGroups.add(groupId));
		});
		accessRules.forEach((rule, index) => {
			rulesContainer.appendChild(createRuleCard(rule, index, assignedEmployeeGroups));
		});
	}

	// User actions update the rules, persist them and rebuild the cards.
	function updateRule(ruleId, update) {
		const updatedRules = normalizeRules(accessRules).map(rule => rule.id === ruleId ? update({
			...rule
		}) : rule);
		accessRules = updatedRules;
	}

	async function saveAndRenderRules(nextRules) {
		const normalizedRules = normalizeRules(nextRules);
		const uniqueGroupResult = removeDuplicateEmployeeGroups(normalizedRules);
		accessRules = uniqueGroupResult.rules;
		const saveRevision = ++ruleRevision;

		// Persist changes in order; stale responses never replace more recent edits.
		const pendingSave = ruleSaveQueue.then(async () => {
			try {
				const savedRules = await saveRules(uniqueGroupResult.rules);
				if (saveRevision === ruleRevision) {
					accessRules = savedRules;
					renderRules();
				}
			} catch (error) {
				console.error('Failed to save HR access rules:', error);
				notify(t('timesheet', 'Saving failed'));
			}
		});
		ruleSaveQueue = pendingSave;
		await pendingSave;
	}

	function readReminderSettings(ruleCard, initializeStartDate = false) {
		const enabledInput = ruleCard.querySelector('[data-reminder-key="enabled"]');
		const graceDaysInput = ruleCard.querySelector('[data-reminder-key="graceDays"]');
		const startDateInput = ruleCard.querySelector('[data-reminder-key="startDate"]');
		const enabled = enabledInput.checked;
		if (enabled && initializeStartDate && !startDateInput.value) {
			startDateInput.value = currentReminderDate();
		}
		const settings = {
			enabled,
			graceDays: Number(graceDaysInput.value),
			weekdays: Array.from(ruleCard.querySelectorAll('[data-reminder-day]:checked')).map(input => Number(input.dataset.reminderDay)),
			startDate: startDateInput.value
		};
		if (!Number.isInteger(settings.graceDays) || settings.graceDays < 1 || settings.graceDays > 365) {
			throw new Error(t('timesheet', 'The reminder delay must be a whole number from 1 to 365.'));
		}
		if (settings.enabled && settings.weekdays.length === 0) {
			throw new Error(t('timesheet', 'Select at least one weekday for reminders.'));
		}
		if ((settings.enabled || settings.startDate !== '') && !isValidReminderDate(settings.startDate)) {
			throw new Error(t('timesheet', 'Select a valid start date for reminders.'));
		}
		return settings;
	}

	// Protect in-progress input from a pending save response.
	rulesContainer.addEventListener('input', () => {
		ruleRevision++;
	});
	addRuleButton?.addEventListener('click', async () => {
		const updatedRules = normalizeRules(accessRules);
		const priority = nextPriorityValue(updatedRules);
		updatedRules.push(normalizeRuleSettings({
			id: createRuleId(),
			hrGroups: [],
			userGroups: [],
			priority
		}));
		await saveAndRenderRules(updatedRules);
	});
	rulesContainer.addEventListener('click', async event => {
		const removeGroupLink = event.target.closest('.ts-chip-remove');
		const deleteRuleLink = event.target.closest('.ts-rule-delete');
		if (removeGroupLink) {
			event.preventDefault();
			const ruleCard = event.target.closest('.ts-rule');
			const ruleId = ruleCard?.dataset.ruleId;
			const groupField = removeGroupLink.dataset.kind;
			const groupId = removeGroupLink.dataset.group;
			if (!ruleId || !groupField || !groupId) {
				return;
			}
			updateRule(ruleId, rule => {
				rule[groupField] = (Array.isArray(rule[groupField]) ? rule[groupField] : []).filter(normalizedGroupId => normalizedGroupId !== groupId);
				return rule;
			});
			await saveAndRenderRules(accessRules);
			return;
		}
		if (deleteRuleLink) {
			event.preventDefault();
			const ruleCard = event.target.closest('.ts-rule');
			const ruleId = ruleCard?.dataset.ruleId;
			if (!ruleId) {
				return;
			}
			const updatedRules = normalizeRules(accessRules).filter(rule => rule.id !== ruleId);
			await saveAndRenderRules(updatedRules);
		}
	});
	rulesContainer.addEventListener('change', async event => {
		const groupSelect = event.target.closest('.ts-rule-select');
		if (!groupSelect) {
			return;
		}
		const ruleCard = event.target.closest('.ts-rule');
		const ruleId = ruleCard?.dataset.ruleId;
		const groupField = groupSelect.dataset.kind;
		const groupId = groupSelect.value;
		groupSelect.value = '';
		if (!ruleId || !groupField || !groupId) {
			return;
		}
		if (groupField === 'userGroups' && isEmployeeGroupAssignedElsewhere(accessRules, ruleId, groupId)) {
			notify(t('timesheet', 'Group already assigned to another rule.'));
			return;
		}
		updateRule(ruleId, rule => {
			const groupIds = Array.isArray(rule[groupField]) ? rule[groupField] : [];
			if (!groupIds.includes(groupId)) {
				groupIds.push(groupId);
			}
			rule[groupField] = groupIds;
			return rule;
		});
		await saveAndRenderRules(accessRules);
	});
	rulesContainer.addEventListener('change', async event => {
		const settingInput = event.target.closest('.ts-rule-input');
		if (!settingInput) {
			return;
		}
		const ruleCard = event.target.closest('.ts-rule');
		const ruleId = ruleCard?.dataset.ruleId;
		const settingKey = settingInput.dataset.key;
		if (!ruleId || !settingKey || !ruleValueRanges[settingKey]) {
			return;
		}
		const [minimumValue, maximumValue] = ruleValueRanges[settingKey];
		const fallback = defaultRuleSettings[settingKey];
		const value = clampNumericValue(settingInput.value, minimumValue, maximumValue, fallback);
		settingInput.value = value;
		updateRule(ruleId, rule => {
			rule[settingKey] = value;
			return rule;
		});
		await saveAndRenderRules(accessRules);
	});
	rulesContainer.addEventListener('change', async event => {
		const reminderInput = event.target.closest('.ts-reminder-input');
		if (!reminderInput) {
			return;
		}
		const ruleCard = reminderInput.closest('.ts-rule');
		const ruleId = ruleCard?.dataset.ruleId;
		if (!ruleId) {
			return;
		}
		try {
			const settings = readReminderSettings(ruleCard, reminderInput.dataset.reminderKey === 'enabled');
			updateRule(ruleId, rule => ({ ...rule, missingEntryReminders: settings }));
			await saveAndRenderRules(accessRules);
		} catch (error) {
			notify(error.message);
		}
	});
	specialDaysOvertime?.addEventListener('change', async event => {
		const checked = event.target.checked;
		try {
			const url = OC.generateUrl('/apps/timesheet/settings/specialdays_check');
			const formData = new FormData();
			formData.append('specialDaysCheck', JSON.stringify(checked));
			const response = await fetch(url, {
				method: 'POST',
				headers: {
					'requesttoken': OC.requestToken
				},
				body: formData
			});
			if (!response.ok) {
				const errorText = await response.text().catch(() => '');
				throw new Error(errorText || `HTTP ${response.status}`);
			}
		} catch (error) {
			console.error('Failed to save special days check setting:', error);
			notify(t('timesheet', 'Saving failed'));
		}
	});

	// Load the independently stored special-day setting.
	async function loadInitialData() {
		try {
			const url = OC.generateUrl('/apps/timesheet/settings/specialdays_check');
			const response = await fetch(url, {
				method: 'GET',
				headers: {
					'requesttoken': OC.requestToken
				}
			});
			if (response.ok) {
				const {
					check
				} = await response.json();
				if (specialDaysOvertime) {
					specialDaysOvertime.checked = check;
				}
			} else {
				console.error('Failed to load special days check setting:', response.status);
			}
		} catch (error) {
			console.error('Error loading special days check setting:', error);
		}
	}
	renderRules();
	loadInitialData();
})();
