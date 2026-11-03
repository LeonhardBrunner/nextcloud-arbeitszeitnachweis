(function () {
	'use strict';

	const settingsRoot = document.getElementById('timesheet-hr-settings');
	if (!settingsRoot) {
		return;
	}

	// Initial settings embedded by the HR settings template.
	function readEmbeddedJson(id) {
		const element = document.getElementById(id);
		if (!element) {
			return [];
		}
		try {
			return JSON.parse(element.textContent || "null") ?? [];
		} catch (error) {
			console.warn("Timesheet HR settings: invalid JSON in", id, error);
			return [];
		}
	}
	const allGroups = readEmbeddedJson('timesheet-all-groups-data');
	const peopleById = new Map();
	const embeddedPeople = readEmbeddedJson('timesheet-hr-people-data');
	for (const person of Array.isArray(embeddedPeople) ? embeddedPeople : []) {
		if (person && typeof person.id === 'string') peopleById.set(person.id, person);
	}
	const personSearches = new Map();
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

	function normalizeAccountIds(values) {
		return [...new Set((Array.isArray(values) ? values : [])
			.filter(value => typeof value === 'string').map(value => value.trim()).filter(Boolean))];
	}

	function normalizeMailRecipients(inputSettings) {
		if (inputSettings === undefined) return { mode: 'all', userIds: [] };
		const settings = inputSettings && typeof inputSettings === 'object' && !Array.isArray(inputSettings) ? inputSettings : {};
		const userIds = settings.userIds === undefined ? [] : settings.userIds;
		if (!['all', 'selected'].includes(settings.mode) || !Array.isArray(userIds) || userIds.some(id => typeof id !== 'string')) {
			return { mode: 'selected', userIds: [] };
		}
		return { mode: settings.mode, userIds: settings.mode === 'all' ? [] : normalizeAccountIds(userIds) };
	}

	function normalizeRuleSettings(inputRule = {}) {
		const normalizedRule = {
			id: String(inputRule.id || "").trim(),
			hrGroups: Array.isArray(inputRule.hrGroups) ? inputRule.hrGroups : [],
			hrUsers: normalizeAccountIds(inputRule.hrUsers),
			userGroups: Array.isArray(inputRule.userGroups) ? inputRule.userGroups : [],
			mailRecipients: normalizeMailRecipients(inputRule.mailRecipients),
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
			const hrGroups = Array.isArray(rule.hrGroups ?? rule.hr_groups) ? (rule.hrGroups ?? rule.hr_groups) : [];
			const userGroups = Array.isArray(rule.userGroups ?? rule.user_groups) ? (rule.userGroups ?? rule.user_groups) : [];
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
			const saveError = new Error(errorText || `HTTP ${response.status}`);
			// Only recognized validation responses replace the generic save notice.
			if (response.status === 400) {
				try {
					const errorData = JSON.parse(errorText);
					if (errorData?.message === 'At least one HR group must remain assigned.') {
						saveError.notificationMessage = t('timesheet', 'At least one HR group must remain assigned.');
					} else if (errorData?.message === 'At least one HR group or enabled HR account must remain assigned.') {
						saveError.notificationMessage = t('timesheet', 'At least one HR group or enabled HR account must remain assigned.');
					} else if (errorData?.message === 'Mail recipients must be responsible for this rule.') {
						saveError.notificationMessage = t('timesheet', 'Mail recipients must be responsible for this rule.');
					} else if (['Invalid HR account selection.', 'Invalid mail recipient settings.'].includes(errorData?.message)) {
						saveError.notificationMessage = t('timesheet', 'HR assignments or email recipients are invalid.');
					}
				} catch {
					// Non-JSON responses retain the generic save notice.
				}
			}
			throw saveError;
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

	// Account labels always include the identifier when names alone are ambiguous.
	function personLabel(person) {
		const name = typeof person?.name === 'string' && person.name.trim() ? person.name : person.id;
		return name === person.id ? name : `${name} (${person.id})`;
	}

	function renderPeopleChips(userIds, purpose) {
		return userIds.map(userId => {
			const person = peopleById.get(userId) || { id: userId, name: userId };
			const label = personLabel(person);
			const disabledLabel = person.enabled === false ? ` (${t('timesheet', 'Disabled account')})` : '';
			return `<span class="ts-chip"><span>${escapeHtml(label + disabledLabel)}</span><button type="button" class="ts-person-remove" data-person-id="${escapeHtml(userId)}" data-person-purpose="${purpose}" aria-label="${escapeHtml(t('timesheet', 'Remove') + ': ' + label)}">&times;</button></span>`;
		}).join('');
	}

	function renderPeoplePicker(rule, purpose) {
		const identifier = `ts-person-${encodeURIComponent(rule.id)}-${purpose}`;
		const isResponsible = purpose === 'responsible';
		const selectedIds = isResponsible ? rule.hrUsers : rule.mailRecipients.userIds;
		const label = t('timesheet', isResponsible ? 'Individual HR accounts' : 'Selected HR accounts');
		return `<div class="ts-person-picker" data-person-picker="${purpose}">
			<label for="${identifier}-search">${escapeHtml(label)}</label>
			<div class="ts-chips">${renderPeopleChips(selectedIds, purpose)}</div>
			<input type="search" id="${identifier}-search" class="ts-person-search" data-person-purpose="${purpose}" placeholder="${escapeHtml(t('timesheet', 'Search by name or account ID'))}" autocomplete="off" aria-describedby="${identifier}-status">
			<div class="ts-person-choice">
				<select class="ts-person-results" data-person-purpose="${purpose}" aria-label="${escapeHtml(t('timesheet', 'Choose an account'))}" disabled><option value="">${escapeHtml(t('timesheet', 'Choose an account'))}</option></select>
				<button type="button" class="ts-person-add" data-person-purpose="${purpose}" disabled>${escapeHtml(t('timesheet', 'Add account'))}</button>
			</div>
			<p id="${identifier}-status" class="ts-person-search-status ts-setting-desc" role="status">${isResponsible ? escapeHtml(t('timesheet', 'Enter at least two characters.')) : ''}</p>
		</div>`;
	}

	function renderMailRecipients(rule) {
		const selectedMode = rule.mailRecipients.mode === 'selected';
		const identifier = `ts-mail-mode-${encodeURIComponent(rule.id)}`;
		return `<div class="ts-rule-section ts-mail-recipient-settings">
			<label for="${identifier}" class="ts-rule-section-title">${escapeHtml(t('timesheet', 'Email recipients'))}</label>
			<select id="${identifier}" class="ts-mail-recipient-mode">
				<option value="all"${selectedMode ? '' : ' selected'}>${escapeHtml(t('timesheet', 'All responsible HR accounts'))}</option>
				<option value="selected"${selectedMode ? ' selected' : ''}>${escapeHtml(t('timesheet', 'Selected HR accounts'))}</option>
			</select>
			<p class="ts-setting-desc">${escapeHtml(t('timesheet', 'Email selection does not change HR access.'))}</p>
			<div class="ts-selected-mail-recipients"${selectedMode ? '' : ' hidden'}>
				<p class="ts-setting-desc">${escapeHtml(t('timesheet', 'Only selected responsible accounts receive HR summaries. Employee reminders are unchanged.'))}</p>
				${renderPeoplePicker(rule, 'mail')}
				${rule.mailRecipients.userIds.length ? '' : `<p class="ts-setting-desc">${escapeHtml(t('timesheet', 'No selected accounts means no HR summary for this rule.'))}</p>`}
			</div>
		</div>`;
	}

	// Search is read-only and scoped to a rule; rebuilt cards discard pending responses.
	function cancelPersonSearches() {
		for (const search of personSearches.values()) {
			if (search.timer !== undefined) clearTimeout(search.timer);
			search.controller?.abort();
		}
		personSearches.clear();
	}

	function personSearchControls(ruleCard, purpose) {
		const picker = ruleCard.querySelector(`[data-person-picker="${purpose}"]`);
		if (!picker) return null;
		return {
			input: picker.querySelector('.ts-person-search'),
			results: picker.querySelector('.ts-person-results'),
			addButton: picker.querySelector('.ts-person-add'),
			status: picker.querySelector('.ts-person-search-status')
		};
	}

	function resetPersonResults(controls) {
		const placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = t('timesheet', 'Choose an account');
		controls.results.replaceChildren(placeholder);
		controls.results.disabled = true;
		controls.addButton.disabled = true;
	}

	function searchPeople(ruleCard, purpose, immediate = false) {
		const rule = accessRules.find(candidate => candidate.id === ruleCard.dataset.ruleId);
		const controls = personSearchControls(ruleCard, purpose);
		if (!rule || !controls || !['responsible', 'mail'].includes(purpose)) return;
		const key = `${rule.id}:${purpose}`;
		const previous = personSearches.get(key);
		if (previous?.timer !== undefined) clearTimeout(previous.timer);
		previous?.controller?.abort();
		const query = controls.input.value.trim();
		const search = { card: ruleCard, controls, people: new Map(), controller: new AbortController() };
		personSearches.set(key, search);
		resetPersonResults(controls);
		if (purpose === 'responsible' && query.length < 2) {
			controls.status.textContent = t('timesheet', 'Enter at least two characters.');
			return;
		}
		controls.status.textContent = t('timesheet', 'Searching accounts…');
		const runSearch = async () => {
			const parameters = new URLSearchParams({ search: query, purpose });
			for (const groupId of rule.hrGroups) parameters.append('hrGroups[]', groupId);
			for (const userId of rule.hrUsers) parameters.append('hrUsers[]', userId);
			try {
				const response = await fetch(OC.generateUrl('/apps/timesheet/api/hr/people') + '?' + parameters.toString(), {
					method: 'GET', headers: { requesttoken: OC.requestToken }, signal: search.controller.signal
				});
				if (!response.ok) throw new Error(`HTTP ${response.status}`);
				const data = await response.json();
				if (!data || !Array.isArray(data.users)) throw new Error('Invalid account search response');
				if (personSearches.get(key) !== search || search.controller.signal.aborted) return;
				const selectedIds = purpose === 'responsible' ? rule.hrUsers : rule.mailRecipients.userIds;
				for (const person of data.users) {
					if (!person || typeof person.id !== 'string' || !person.id || typeof person.name !== 'string' || typeof person.enabled !== 'boolean') continue;
					peopleById.set(person.id, person);
					search.people.set(person.id, person);
					const option = document.createElement('option');
					option.value = person.id;
					option.textContent = personLabel(person) + (person.enabled ? '' : ` (${t('timesheet', 'Disabled account')})`);
					option.disabled = !person.enabled || selectedIds.includes(person.id);
					controls.results.appendChild(option);
				}
				const available = [...search.people.values()].some(person => person.enabled && !selectedIds.includes(person.id));
				controls.results.disabled = !available;
				controls.status.textContent = data.hasMore ? t('timesheet', 'More accounts found. Refine the search.') : available ? '' : t('timesheet', 'No matching accounts.');
			} catch (error) {
				if (personSearches.get(key) !== search || search.controller.signal.aborted) return;
				controls.status.textContent = t('timesheet', 'Accounts could not be loaded. Try again.');
			}
		};
		if (immediate) return runSearch();
		search.timer = setTimeout(runSearch, 250);
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
			${renderPeoplePicker(rule, 'responsible')}
			<p class="ts-setting-desc">${escapeHtml(t('timesheet', 'Assigned groups and people receive HR access to these employee groups.'))}</p>
          </div>

          <div class="ts-rule-col">
            <label>${escapeHtml(t("timesheet", "Employee groups"))}</label>
            <div class="ts-chips" data-kind="userGroups">${renderGroupChips(rule.userGroups, "userGroups")}</div>
            <select class="ts-rule-select" data-kind="userGroups">${employeeGroupOptions}</select>
          </div>
        </div>

		${renderMailRecipients(rule)}
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
		cancelPersonSearches();
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
			emptyMessage.textContent = t('timesheet', 'No rules yet. Add one to assign HR responsibility.');
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
				notify(error.notificationMessage || t('timesheet', 'Saving failed'));
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
	rulesContainer.addEventListener('input', event => {
		ruleRevision++;
		const searchInput = event.target?.closest('.ts-person-search');
		if (searchInput) searchPeople(searchInput.closest('.ts-rule'), searchInput.dataset.personPurpose);
	});
	rulesContainer.addEventListener('focusin', event => {
		const searchInput = event.target.closest('.ts-person-search');
		if (searchInput) searchPeople(searchInput.closest('.ts-rule'), searchInput.dataset.personPurpose, true);
	});
	rulesContainer.addEventListener('keydown', event => {
		const searchInput = event.target.closest('.ts-person-search');
		if (searchInput && event.key === 'Enter') {
			event.preventDefault();
			searchPeople(searchInput.closest('.ts-rule'), searchInput.dataset.personPurpose, true);
		}
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
	rulesContainer.addEventListener('change', async event => {
		const resultSelect = event.target.closest('.ts-person-results');
		if (resultSelect) {
			const card = resultSelect.closest('.ts-rule');
			const search = personSearches.get(`${card.dataset.ruleId}:${resultSelect.dataset.personPurpose}`);
			const person = search?.people.get(resultSelect.value);
			if (search) search.controls.addButton.disabled = !person?.enabled;
			return;
		}
		const modeSelect = event.target.closest('.ts-mail-recipient-mode');
		if (!modeSelect || !['all', 'selected'].includes(modeSelect.value)) return;
		const card = modeSelect.closest('.ts-rule');
		updateRule(card.dataset.ruleId, rule => ({ ...rule, mailRecipients: { mode: modeSelect.value, userIds: modeSelect.value === 'all' ? [] : rule.mailRecipients.userIds } }));
		await saveAndRenderRules(accessRules);
	});
	rulesContainer.addEventListener('click', async event => {
		const removeButton = event.target.closest('.ts-person-remove');
		const addButton = event.target.closest('.ts-person-add');
		if (!removeButton && !addButton) return;
		event.preventDefault();
		const card = event.target.closest('.ts-rule');
		const purpose = (removeButton || addButton).dataset.personPurpose;
		if (!card || !['responsible', 'mail'].includes(purpose)) return;
		const search = personSearches.get(`${card.dataset.ruleId}:${purpose}`);
		const userId = removeButton?.dataset.personId || search?.controls.results.value;
		if (!userId || addButton && (search?.card !== card || !search.people.get(userId)?.enabled)) return;
		updateRule(card.dataset.ruleId, rule => {
			const currentIds = purpose === 'responsible' ? rule.hrUsers : rule.mailRecipients.userIds;
			const updatedIds = removeButton ? currentIds.filter(id => id !== userId) : [...new Set([...currentIds, userId])];
			if (purpose === 'responsible') rule.hrUsers = updatedIds;
			else rule.mailRecipients = { ...rule.mailRecipients, userIds: updatedIds };
			return rule;
		});
		await saveAndRenderRules(accessRules);
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
