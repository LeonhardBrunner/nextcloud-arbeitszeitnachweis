(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet || !timesheet.tourHeader) {
		return;
	}
	const header = timesheet.tourHeader;
	const translate = (message, parameters = {}) => t('timesheet', message, parameters);
	const svgNamespace = 'http://www.w3.org/2000/svg';
	const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
	const tourVersion = 1;
	let appRoot;
	let launcher;
	let invitation;
	let overlay;
	let dialog;
	let dialogTitle;
	let dialogDescription;
	let progressLabel;
	let previousButton;
	let nextButton;
	let endButton;
	let maskHoles;
	let outlines;
	let maskSvg;
	let steps = [];
	let stepIndex = 0;
	let targets = [];
	let snapshot;
	let progress;
	let active = false;
	let preparing = false;
	let finishing = false;
	let ending = false;
	let internalAction = false;
	let pendingViewAction = Promise.resolve();
	let scheduledPosition;
	let resizeObserver;
	let accessibleUsers = [];
	let hrLoaded = false;
	let currentPreparation = 0;
	let currentRun = 0;
	const explainedHrSteps = new Set();
	const blockedBackground = [];

	function createElement(tagName, className, text) {
		const element = document.createElement(tagName);
		if (className) {
			element.className = className;
		}
		if (text) {
			element.textContent = text;
		}
		return element;
	}

	function createButton(label, className, onClick) {
		const button = createElement('button', className, label ? translate(label) : '');
		button.type = 'button';
		button.addEventListener('click', onClick);
		return button;
	}

	function isVisible(element) {
		if (!element?.isConnected || element.hidden || element.getClientRects().length === 0) {
			return false;
		}
		const style = window.getComputedStyle(element);
		return style.display !== 'none' && style.visibility !== 'hidden';
	}

	function queryTargets(selectors) {
		return selectors.flatMap(selector => Array.from(document.querySelectorAll(selector))).filter(isVisible);
	}

	function activate(action) {
		internalAction = true;
		try {
			return action();
		} finally {
			internalAction = false;
		}
	}

	// Completion belongs to the account; dismissing an invitation belongs to this visit.
	function readProgress() {
		const data = appRoot.dataset;
		return {
			version: Number(data.tourVersion) || tourVersion,
			employeeCompleted: data.tourEmployeeCompleted === 'true',
			hrCompleted: data.tourHrCompleted === 'true',
			isHr: data.tourIsHr === 'true',
			available: data.tourStateAvailable === 'true',
		};
	}

	function hideInvitation() {
		invitation?.remove();
		invitation = null;
		launcher?.classList.remove('has-invitation');
	}

	function showInvitation() {
		hideInvitation();
		const hrOnly = progress.employeeCompleted && progress.isHr && !progress.hrCompleted;
		invitation = createElement('div', 'ts-tour-invitation');
		invitation.id = 'ts-tour-invitation';
		invitation.setAttribute('role', 'dialog');
		invitation.setAttribute('aria-labelledby', 'ts-tour-invitation-title');
		invitation.setAttribute('aria-live', 'polite');
		const heading = createElement('h2', '', translate('Explore Timesheet?'));
		heading.id = 'ts-tour-invitation-title';
		const description = createElement('p', '', translate(hrOnly
			? 'The HR introduction explains the additional features available to this account.'
			: 'A short tour explains the main features.'));
		const actions = createElement('div', 'ts-tour-actions');
		const closeButton = createButton('', 'ts-tour-close', hideInvitation);
		closeButton.setAttribute('aria-label', translate('Close tour invitation'));
		closeButton.dataset.tourAction = 'later';
		const laterButton = createButton('Later', '', hideInvitation);
		laterButton.dataset.tourAction = 'later';
		const startButton = createButton('Tutorial', 'primary', () => start(hrOnly));
		startButton.id = 'ts-tour-start';
		startButton.dataset.tourAction = 'start';
		actions.append(laterButton, startButton);
		invitation.append(heading, description, closeButton, actions);
		invitation.addEventListener('keydown', event => {
			if (event.key === 'Escape') {
				event.stopPropagation();
				hideInvitation();
				launcher.focus();
			}
		});
		document.body.append(invitation);
		launcher.classList.add('has-invitation');
		positionInvitation();
	}

	function positionInvitation() {
		if (!invitation) {
			return;
		}
		const anchor = launcher.getBoundingClientRect();
		const size = invitation.getBoundingClientRect();
		invitation.style.left = `${Math.max(12, Math.min(window.innerWidth - size.width - 12, anchor.right - size.width))}px`;
		invitation.style.top = `${Math.max(12, Math.min(window.innerHeight - size.height - 12, anchor.bottom + 8))}px`;
	}

	// Keep original nodes and field values so the tour cannot replace unsaved work.
	function captureView() {
		const hr = document.getElementById('tab-hr');
		const elements = Array.from(appRoot.querySelectorAll('input, select, textarea'));
		const scrollElements = [appRoot, document.getElementById('content'), document.scrollingElement].filter(Boolean);
		return {
			focus: document.activeElement,
			windowScroll: [window.scrollX, window.scrollY],
			scrolls: scrollElements.map(element => ({ element, top: element.scrollTop, left: element.scrollLeft })),
			activeClasses: Array.from(appRoot.querySelectorAll('.ts-tab, .ts-tabview')).map(element => ({ element, active: element.classList.contains('active') })),
			hrSectionDisplays: Array.from(hr?.querySelectorAll('.ts-hr-section') || []).map(element => ({ element, display: element.style.display })),
			fields: elements.map(element => ({ element, value: element.value, checked: element.checked })),
			bodies: ['hr-userlist', 'hr-user-body'].map(id => document.getElementById(id)).filter(Boolean)
				.map(element => ({ element, children: Array.from(element.childNodes), dataset: { ...element.dataset } })),
			texts: Array.from(hr?.querySelectorAll('#hr-user-title span, #hr-month-display, .ts-stats span, #hr-stats-section td') || [])
				.map(element => ({ element, text: element.textContent })),
			hrMonth: new Date(timesheet.state.hrCurrentMonth),
			hoveredRow: timesheet.state.tsHoveredRow,
			ruleThresholds: timesheet.state.ruleThresholds,
			exportVisibility: ['mine', 'hr'].map(view => ({ view, visible: !!document.getElementById(`export-${view}-xlsx-choice-ui`)?.classList.contains('is-visible') })),
			profileOpen: header.isProfileOpen(),
		};
	}

	function setExportVisible(view, shouldShow) {
		const container = document.getElementById(`export-${view}-xlsx-choice-ui`);
		const isShown = !!container?.classList.contains('is-visible');
		if (isShown !== shouldShow && document.getElementById(`export-${view}-xlsx`)) {
			timesheet.export.handleExportClick(view);
		}
	}

	async function restoreView() {
		if (!snapshot) {
			return;
		}
		for (const item of snapshot.bodies) {
			item.element.replaceChildren(...item.children);
			Object.keys(item.element.dataset).forEach(key => delete item.element.dataset[key]);
			Object.assign(item.element.dataset, item.dataset);
		}
		for (const item of snapshot.fields) {
			item.element.value = item.value;
			item.element.checked = item.checked;
		}
		for (const item of snapshot.texts) {
			item.element.textContent = item.text;
		}
		for (const item of snapshot.hrSectionDisplays) {
			item.element.style.display = item.display;
		}
		for (const item of snapshot.activeClasses) {
			item.element.classList.toggle('active', item.active);
		}
		for (const item of snapshot.exportVisibility) {
			setExportVisible(item.view, item.visible);
		}
		timesheet.state.hrCurrentMonth = snapshot.hrMonth;
		timesheet.state.tsHoveredRow = snapshot.hoveredRow;
		timesheet.state.ruleThresholds = snapshot.ruleThresholds;
		timesheet.dom.refresh();
		await header.setProfileOpen(snapshot.profileOpen, activate);
		for (const item of snapshot.scrolls) {
			item.element.scrollTop = item.top;
			item.element.scrollLeft = item.left;
		}
		window.scrollTo(...snapshot.windowScroll);
	}

	function showTab(name) {
		appRoot.querySelectorAll('.ts-tab').forEach(button => button.classList.toggle('active', button.dataset.tab === name));
		appRoot.querySelectorAll('.ts-tabview').forEach(section => section.classList.toggle('active', section.id === `tab-${name}`));
	}

	function showHrOverview() {
		showTab('hr');
		document.querySelectorAll('#tab-hr .ts-hr-section').forEach(section => {
			section.style.display = section.id === 'hr-user-entries' ? 'none' : '';
		});
	}

	function isHrAccessError(error) {
		try {
			const response = JSON.parse(error.message);
			return (Array.isArray(response) && response.length === 0) || response.error === 'Forbidden' || response.code === 'hr_access_changed';
		} catch {
			return false;
		}
	}

	async function loadHrOverview() {
		showHrOverview();
		if (hrLoaded) {
			return;
		}
		accessibleUsers = await timesheet.api('/api/hr/users');
		if (!Array.isArray(accessibleUsers)) {
			throw new Error('Invalid HR user list');
		}
		await timesheet.hr.loadHrUserList();
		await Promise.all([
			timesheet.config.loadHrNotificationSettings?.(),
			timesheet.config.loadHrWarningThresholds?.(),
		]);
		hrLoaded = true;
	}

	async function showEmployeeDetails() {
		await loadHrOverview();
		// Resolve the target from a fresh server-scoped list, never from an arbitrary UID.
		accessibleUsers = await timesheet.api('/api/hr/users');
		const previousUser = snapshot.texts.find(item => item.element.matches('#hr-user-title span'))?.text.trim();
		const employee = accessibleUsers.find(user => user.id === previousUser) || accessibleUsers[0];
		if (!employee) {
			return false;
		}
		const userId = employee.id;
		const config = await timesheet.api(`/api/hr/config/${encodeURIComponent(userId)}`);
		const configRow = document.querySelector('#hr-user-entries .hr-config-row');
		const dailyInput = configRow?.querySelector('.config-daily-min');
		const stateInput = configRow?.querySelector('.config-state');
		if (dailyInput) {
			dailyInput.value = timesheet.util.minToHm(timesheet.util.pickDailyMin(config) ?? 480);
		}
		if (stateInput) {
			stateInput.value = typeof config?.state === 'string' ? config.state : '';
		}
		timesheet.state.hrCurrentMonth = previousUser === userId ? new Date(snapshot.hrMonth) : new Date();
		timesheet.main.updateHrMonthDisplay();
		await timesheet.entries.loadUserEntries(userId, timesheet.state.hrCurrentMonth);
		await timesheet.entries.refreshOvertimeTotal(userId, document.getElementById('tab-hr'));
		document.querySelectorAll('#tab-hr .ts-hr-section').forEach(section => {
			section.style.display = section.id === 'hr-user-entries' ? 'block' : 'none';
		});
		const title = document.querySelector('#hr-user-title span');
		if (title) {
			title.textContent = userId;
		}
		return true;
	}

	// Step content uses the same translation keys in every supported account language.
	function createSteps(hrOnly) {
		const common = [
			{ id: 'month', title: 'Choose a month', text: 'The arrows select another month. Selecting the month name returns to the current month.', selectors: ['#month-prev', '#month-display', '#month-next'] },
			{ id: 'balances', title: 'Hours and balances', text: 'The overview shows recorded hours, the current month’s balance and the total balance including carryovers.', selectors: ['#tab-mine .ts-stats'] },
			{ id: 'configuration', title: 'Working time and region', text: 'The daily working time is the target used for calculations. The federal state determines which German public holidays are shown. Changes are applied with Save.', selectors: ['#tab-mine .hr-config-row'] },
			{ id: 'entries', title: 'Record working time', text: 'Start, break and end form the daily entry. Changes are saved when a field is left or Enter is pressed.', selectors: ['#ts-body tr:first-child .startTime', '#ts-body tr:first-child .breakMinutes', '#ts-body tr:first-child .endTime', '#ts-table thead th:nth-child(3)', '#ts-table thead th:nth-child(4)', '#ts-table thead th:nth-child(5)'] },
			{ id: 'daily-values', title: 'Daily values and warnings', text: 'Duration shows recorded working time; difference compares it with the daily target. Status and warnings identify weekends, public holidays or unusual entries.', selectors: ['#ts-body tr:first-child .ts-duration', '#ts-body tr:first-child .ts-diff', '#ts-body tr:first-child .ts-warn', '#ts-body tr:first-child .ts-status', '#ts-table thead th:nth-child(6)', '#ts-table thead th:nth-child(7)', '#ts-table thead th:nth-child(9)', '#ts-table thead th:nth-child(2)'] },
			{ id: 'comments', title: 'Comments and absences', text: 'Comments add context to a day. An absence can be marked with "Urlaub" or "Stundenausgleich". Depending on the period, HR approval is required; missing approval triggers a warning.', selectors: ['#ts-body tr:first-child .commentInput', '#ts-table thead th:nth-child(8)'] },
			{ id: 'break-display', title: 'Break display', text: 'The switch changes how breaks are displayed: minutes or hours and minutes. The choice is saved for this account.', selectors: ['#ts-break-toggle'] },
			{ id: 'export', title: 'Month and year export', text: 'Export offers a file for the selected month or its calendar year. Both files use Excel format.', selectors: ['#export-mine-xlsx', '#export-mine-xlsx-choice-ui'], prepare: () => setExportVisible('mine', true) },
		];
		const hr = [
			{ id: 'hr-navigation', hr: true, title: 'Timesheet and HR overview', text: 'The Timesheet tab shows personal entries. The HR overview shows assigned employees, their balances and warnings.', selectors: ['.ts-tabs .ts-tab[data-tab="mine"]', '.ts-tabs .ts-tab[data-tab="hr"]'], prepare: () => showTab('mine') },
			{ id: 'hr-list', hr: true, title: 'HR overview', text: 'This list contains the employees assigned to this HR account. It shows daily targets, balances, latest entries and warnings.', selectors: ['#hr-userlist-section'], prepare: loadHrOverview },
			{ id: 'hr-statistics', hr: true, title: 'Team statistics', text: 'These figures summarize working hours and balances for the employees visible in this HR account.', selectors: ['#hr-stats-section'], prepare: loadHrOverview },
			{ id: 'hr-mail', hr: true, title: 'Email notifications for HR', text: 'These settings enable the existing weekly HR warning emails and set their thresholds. Group-based missing-day reminders are configured separately by an administrator.', selectors: ['#hr-notifications-section'], placement: 'left', prepare: loadHrOverview },
			{ id: 'hr-warnings', hr: true, title: 'Warnings in the overview', text: 'These thresholds control the warnings shown in the employee overview. They are separate from the email notification settings.', selectors: ['#hr-warning-thresholds-section'], placement: 'left', prepare: loadHrOverview },
			{ id: 'hr-person', hr: true, title: 'Individual employee view', text: 'Selecting an employee opens that person’s entries, settings and export. Back returns to the HR overview.', selectors: ['#hr-user-entries .hr-user-header-bar', '#hr-user-entries .hr-user-controls-row'], prepare: showEmployeeDetails },
		];
		const final = [
			{ id: 'profile', title: 'Profile menu', text: 'The profile menu provides access to personal details, account settings, appearance and accessibility options.', getTargets: () => [header.profileTrigger()].filter(Boolean) },
			{ id: 'help', title: 'Help and privacy', text: 'Help and privacy opens general Nextcloud guidance and privacy information. The compass icon can restart the Timesheet tour at any time.', prepare: () => header.setProfileOpen(true, activate), getTargets: () => [header.helpItem()].filter(Boolean) },
		];
		return [...(hrOnly ? [] : common), ...(progress.isHr ? hr : []), ...final];
	}

	function createSvgElement(name, attributes = {}) {
		const element = document.createElementNS(svgNamespace, name);
		for (const [key, value] of Object.entries(attributes)) {
			element.setAttribute(key, String(value));
		}
		return element;
	}

	function createOverlay() {
		overlay = createElement('div', 'ts-tour-overlay');
		overlay.id = 'ts-tour-overlay';
		maskSvg = createSvgElement('svg', { class: 'ts-tour-mask', 'aria-hidden': 'true' });
		const definitions = createSvgElement('defs');
		const mask = createSvgElement('mask', { id: 'ts-tour-cutout', maskUnits: 'userSpaceOnUse' });
		mask.append(createSvgElement('rect', { width: '100%', height: '100%', fill: 'white' }));
		maskHoles = createSvgElement('g', { fill: 'black' });
		mask.append(maskHoles);
		definitions.append(mask);
		outlines = createSvgElement('g', { class: 'ts-tour-highlight' });
		maskSvg.append(definitions, createSvgElement('rect', { width: '100%', height: '100%', mask: 'url(#ts-tour-cutout)', class: 'ts-tour-dim' }), outlines);
		dialog = createElement('div', 'ts-tour-dialog');
		dialog.id = 'ts-tour-dialog';
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', 'ts-tour-title');
		dialog.setAttribute('aria-describedby', 'ts-tour-description ts-tour-progress');
		const content = createElement('div', 'ts-tour-content');
		content.setAttribute('aria-live', 'polite');
		content.setAttribute('aria-atomic', 'true');
		progressLabel = createElement('span', 'ts-tour-progress');
		progressLabel.id = 'ts-tour-progress';
		dialogTitle = createElement('h2');
		dialogTitle.id = 'ts-tour-title';
		dialogDescription = createElement('p');
		dialogDescription.id = 'ts-tour-description';
		content.append(progressLabel, dialogTitle, dialogDescription);
		const actions = createElement('div', 'ts-tour-actions');
		endButton = createButton('End tour', 'ts-tour-end', () => end());
		previousButton = createButton('Back', '', () => showStep(stepIndex - 1));
		nextButton = createButton('Next', 'primary', () => stepIndex === steps.length - 1 ? finish() : showStep(stepIndex + 1));
		endButton.id = 'ts-tour-end';
		endButton.dataset.tourAction = 'end';
		previousButton.id = 'ts-tour-back';
		previousButton.dataset.tourAction = 'back';
		nextButton.id = 'ts-tour-next';
		const navigation = createElement('div', 'ts-tour-navigation');
		navigation.append(previousButton, nextButton);
		actions.append(endButton, navigation);
		dialog.append(content, actions);
		overlay.append(maskSvg, dialog);
		// Keep Nextcloud's click-outside handlers away from the tour's own controls.
		['click', 'pointerdown', 'mousedown', 'focusin', 'keydown', 'keyup', 'keypress', 'copy', 'cut', 'paste']
			.forEach(name => overlay.addEventListener(name, event => event.stopPropagation()));
		document.body.append(overlay);
		nextButton.focus({ preventScroll: true });
		for (const element of Array.from(document.body.children)) {
			if (element === overlay || ['SCRIPT', 'STYLE', 'LINK'].includes(element.tagName)) {
				continue;
			}
			blockedBackground.push({ element, inert: element.inert });
			element.inert = true;
		}
		document.addEventListener('keydown', handleKeyboard, true);
		document.addEventListener('focusin', keepDialogFocus, true);
		['click', 'pointerdown', 'mousedown', 'touchstart'].forEach(name => document.addEventListener(name, blockBackgroundInteraction, true));
		window.addEventListener('resize', schedulePosition);
		window.addEventListener('scroll', schedulePosition, true);
		if (typeof ResizeObserver === 'function') {
			resizeObserver = new ResizeObserver(schedulePosition);
			resizeObserver.observe(dialog);
			resizeObserver.observe(appRoot);
		}
	}

	function blockBackgroundInteraction(event) {
		if (active && !internalAction && !overlay?.contains(event.target)) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}

	function keepDialogFocus(event) {
		if (active && !internalAction && !dialog?.contains(event.target)) {
			event.stopImmediatePropagation();
			(!nextButton.disabled ? nextButton : endButton).focus({ preventScroll: true });
		}
	}

	function handleKeyboard(event) {
		if (!active || internalAction) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopImmediatePropagation();
			end();
			return;
		}
		if (event.key === 'Tab') {
			event.preventDefault();
			event.stopImmediatePropagation();
			const buttons = [endButton, previousButton, nextButton].filter(button => !button.disabled);
			const current = buttons.indexOf(document.activeElement);
			const next = event.shiftKey ? (current <= 0 ? buttons.length - 1 : current - 1) : (current + 1) % buttons.length;
			buttons[next]?.focus({ preventScroll: true });
		} else if (scrollExplanation(event)) {
			return;
		} else if (!dialog?.contains(event.target)) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}

	function scrollExplanation(event) {
		if (!dialog?.contains(event.target) || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) {
			return false;
		}
		const explanation = dialog.querySelector('.ts-tour-content');
		if (!explanation) {
			return false;
		}
		const maximumTop = Math.max(0, explanation.scrollHeight - explanation.clientHeight);
		const pageDistance = Math.max(1, Math.round(explanation.clientHeight * .85));
		let nextTop;
		switch (event.key) {
		case 'PageDown': nextTop = explanation.scrollTop + pageDistance; break;
		case 'PageUp': nextTop = explanation.scrollTop - pageDistance; break;
		case 'ArrowDown': nextTop = explanation.scrollTop + 40; break;
		case 'ArrowUp': nextTop = explanation.scrollTop - 40; break;
		case 'Home': nextTop = 0; break;
		case 'End': nextTop = maximumTop; break;
		default: return false;
		}
		// Reading keys scroll the explanation instead of the dimmed application.
		event.preventDefault();
		event.stopImmediatePropagation();
		explanation.scrollTop = Math.max(0, Math.min(maximumTop, nextTop));
		return true;
	}

	function schedulePosition() {
		if (!active || scheduledPosition) {
			return;
		}
		scheduledPosition = window.requestAnimationFrame(() => {
			scheduledPosition = null;
			positionStep();
		});
	}

	function positionStep() {
		if (!active || !dialog) {
			return;
		}
		centerHorizontalTargets();
		const width = window.innerWidth;
		const height = window.innerHeight;
		// Measure the uncompressed dialog before choosing a free area for this step.
		dialog.style.width = '';
		dialog.style.maxHeight = '';
		let rectangles = highlightedRectangles(width, height);
		let placement = findDialogPlacement(rectangles, dialog.getBoundingClientRect(), width, height);
		if (!placement && alignEntryRowNearTop()) {
			rectangles = highlightedRectangles(width, height);
			placement = findDialogPlacement(rectangles, dialog.getBoundingClientRect(), width, height);
		}
		if (!placement) {
			placement = fitDialogInFreeArea(rectangles, width, height);
		}
		maskSvg.setAttribute('viewBox', `0 0 ${width} ${height}`);
		maskHoles.replaceChildren();
		outlines.replaceChildren();
		for (const rectangle of rectangles) {
			const attributes = { x: rectangle.left, y: rectangle.top, width: rectangle.right - rectangle.left, height: rectangle.bottom - rectangle.top, rx: 7 };
			maskHoles.append(createSvgElement('rect', attributes));
			outlines.append(createSvgElement('rect', attributes));
		}
		dialog.style.left = `${placement.left}px`;
		dialog.style.top = `${placement.top}px`;
	}

	function highlightedRectangles(width, height) {
		const stepId = steps[stepIndex]?.id;
		const headerBottom = document.getElementById('header')?.getBoundingClientRect().bottom || 0;
		const rectangles = targets.filter(isVisible).map(element => {
			const rectangle = element.getBoundingClientRect();
			const isApplicationTarget = appRoot.contains(element) && !['profile', 'help'].includes(stepId);
			return {
				left: Math.max(4, rectangle.left - 5),
				top: Math.max(isApplicationTarget ? headerBottom + 4 : 4, rectangle.top - 5),
				right: Math.min(width - 4, rectangle.right + 5),
				bottom: Math.min(height - 4, rectangle.bottom + 5),
			};
		}).filter(rectangle => rectangle.right > rectangle.left && rectangle.bottom > rectangle.top);
		// A field and its label share one outline when their padded areas touch.
		for (let index = 0; index < rectangles.length; index++) {
			for (let nextIndex = index + 1; nextIndex < rectangles.length; nextIndex++) {
				if (rectanglesOverlap(rectangles[index], rectangles[nextIndex])) {
					rectangles[index] = rectangleBounds([rectangles[index], rectangles[nextIndex]]);
					rectangles.splice(nextIndex, 1);
					index = -1;
					break;
				}
			}
		}
		return rectangles;
	}

	function rectanglesOverlap(first, second) {
		return first.left <= second.right && first.right >= second.left
			&& first.top <= second.bottom && first.bottom >= second.top;
	}

	function rectangleBounds(rectangles) {
		return rectangles.length ? {
			left: Math.min(...rectangles.map(rectangle => rectangle.left)),
			right: Math.max(...rectangles.map(rectangle => rectangle.right)),
			top: Math.min(...rectangles.map(rectangle => rectangle.top)),
			bottom: Math.max(...rectangles.map(rectangle => rectangle.bottom)),
		} : { left: window.innerWidth / 2, right: window.innerWidth / 2, top: window.innerHeight / 3, bottom: window.innerHeight / 3 };
	}

	function preferredPlacementOrder() {
		return steps[stepIndex]?.placement === 'left' ? ['left', 'bottom', 'top', 'right'] : ['bottom', 'top', 'right', 'left'];
	}

	function findDialogPlacement(rectangles, size, width, height) {
		const bounds = rectangleBounds(rectangles);
		const horizontalCenter = Math.max(12, Math.min(width - size.width - 12, (bounds.left + bounds.right - size.width) / 2));
		const verticalCenter = Math.max(12, Math.min(height - size.height - 12, (bounds.top + bounds.bottom - size.height) / 2));
		const positions = {
			bottom: { left: horizontalCenter, top: bounds.bottom + 14 },
			top: { left: horizontalCenter, top: bounds.top - size.height - 14 },
			right: { left: bounds.right + 14, top: verticalCenter },
			left: { left: bounds.left - size.width - 14, top: verticalCenter },
		};
		for (const side of preferredPlacementOrder()) {
			const position = positions[side];
			const candidate = { ...position, right: position.left + size.width, bottom: position.top + size.height };
			if (candidate.left >= 12 && candidate.top >= 12 && candidate.right <= width - 12 && candidate.bottom <= height - 12
				&& rectangles.every(rectangle => !rectanglesOverlap(rectangle, candidate))) {
				return position;
			}
		}
		return null;
	}

	function fitDialogInFreeArea(rectangles, width, height) {
		const bounds = rectangleBounds(rectangles);
		const size = dialog.getBoundingClientRect();
		const areas = {
			bottom: { width: width - 24, height: height - bounds.bottom - 26 },
			top: { width: width - 24, height: bounds.top - 26 },
			left: { width: bounds.left - 26, height: height - 24 },
			right: { width: width - bounds.right - 26, height: height - 24 },
		};
		const actionHeight = dialog.querySelector('.ts-tour-actions')?.getBoundingClientRect().height || 44;
		const minimumHeight = Math.min(height - 24, actionHeight + 88);
		for (const side of preferredPlacementOrder()) {
			const area = areas[side];
			if (area.width < Math.min(240, width - 24) || area.height < minimumHeight) {
				continue;
			}
			dialog.style.width = `${Math.min(size.width, area.width)}px`;
			dialog.style.maxHeight = `${Math.min(size.height, area.height)}px`;
			const fitted = dialog.getBoundingClientRect();
			const placement = findDialogPlacement(rectangles, fitted, width, height);
			if (placement) {
				return placement;
			}
		}
		// Extremely small viewports still keep the controls inside the visible page.
		dialog.style.width = `${Math.min(size.width, width - 24)}px`;
		dialog.style.maxHeight = `${height - 24}px`;
		const fitted = dialog.getBoundingClientRect();
		return {
			left: Math.max(12, Math.min(width - fitted.width - 12, (bounds.left + bounds.right - fitted.width) / 2)),
			top: Math.max(12, Math.min(height - fitted.height - 12, bounds.bottom + 14)),
		};
	}

	function alignEntryRowNearTop() {
		if (!['entries', 'daily-values', 'comments'].includes(steps[stepIndex]?.id)) {
			return false;
		}
		const field = document.querySelector('#ts-body tr:first-child .startTime');
		if (!field) {
			return false;
		}
		let container = field.parentElement;
		while (container && container !== document.body) {
			const overflow = window.getComputedStyle(container).overflowY;
			if (['auto', 'scroll', 'overlay'].includes(overflow) && container.scrollHeight > container.clientHeight) {
				break;
			}
			container = container.parentElement;
		}
		if (!container || container === document.body) {
			return false;
		}
		const headerBottom = document.getElementById('header')?.getBoundingClientRect().bottom || 0;
		const tableHeaderHeight = document.querySelector('#ts-table thead')?.getBoundingClientRect().height || 24;
		const delta = field.getBoundingClientRect().top - headerBottom - tableHeaderHeight - 12;
		if (delta <= 1) {
			return false;
		}
		if (snapshot && !snapshot.scrolls.some(item => item.element === container)) {
			snapshot.scrolls.push({ element: container, top: container.scrollTop, left: container.scrollLeft });
		}
		const previousTop = container.scrollTop;
		container.scrollTop += delta;
		return container.scrollTop !== previousTop;
	}

	function centerHorizontalTargets() {
		if (!targets.length || !targets.every(element => appRoot.contains(element))) {
			return;
		}
		let container = targets[0].parentElement;
		while (container && container !== document.body) {
			const overflow = window.getComputedStyle(container).overflowX;
			if (['auto', 'scroll', 'overlay'].includes(overflow) && container.scrollWidth > container.clientWidth
				&& targets.every(element => container.contains(element))) {
				break;
			}
			container = container.parentElement;
		}
		if (!container || container === document.body) {
			return;
		}
		const rectangles = targets.filter(isVisible).map(element => element.getBoundingClientRect());
		if (!rectangles.length) {
			return;
		}
		const left = Math.min(...rectangles.map(rectangle => rectangle.left));
		const right = Math.max(...rectangles.map(rectangle => rectangle.right));
		const containerBox = container.getBoundingClientRect();
		const visibleLeft = Math.max(0, containerBox.left + container.clientLeft);
		const visibleRight = Math.min(window.innerWidth, containerBox.left + container.clientLeft + container.clientWidth);
		if (right - left > visibleRight - visibleLeft + 1) {
			return;
		}
		const delta = (left + right - visibleLeft - visibleRight) / 2;
		if (Math.abs(delta) <= 1) {
			return;
		}
		if (snapshot && !snapshot.scrolls.some(item => item.element === container)) {
			snapshot.scrolls.push({ element: container, top: container.scrollTop, left: container.scrollLeft });
		}
		// Physical coordinates also work with the negative scrollLeft values used in RTL.
		const previousBehavior = container.style.scrollBehavior;
		container.style.scrollBehavior = 'auto';
		container.scrollLeft += delta;
		container.style.scrollBehavior = previousBehavior;
	}

	function updateButtons() {
		previousButton.disabled = preparing || finishing || ending || stepIndex === 0;
		nextButton.disabled = preparing || finishing || ending;
		endButton.disabled = ending;
		nextButton.textContent = translate(finishing ? 'Saving tour completion…' : stepIndex === steps.length - 1 ? 'Finish tour' : 'Next');
		nextButton.dataset.tourAction = stepIndex === steps.length - 1 ? 'finish' : 'next';
	}

	async function showStep(index) {
		if (!active || preparing || finishing || ending || index < 0 || index >= steps.length) {
			return;
		}
		stepIndex = index;
		const step = steps[stepIndex];
		// The inactive tab stays legible while both destinations are being explained.
		appRoot.classList.toggle('ts-tour-navigation-preview', step.id === 'hr-navigation');
		let rerouteAfterPreparation = false;
		preparing = true;
		const preparation = ++currentPreparation;
		updateButtons();
		dialogTitle.textContent = translate(step.title);
		dialogDescription.textContent = translate(step.text);
		progressLabel.textContent = translate('Step {current} of {total}', { current: stepIndex + 1, total: steps.length });
		dialog.querySelector('.ts-tour-content').scrollTop = 0;
		overlay.dataset.step = step.id;
		dialog.dataset.stepId = step.id;
		pendingViewAction = (async () => {
			await header.setProfileOpen(step.id === 'help', activate);
			if (!step.hr && !['profile', 'help'].includes(step.id)) {
				showTab('mine');
			}
			const prepared = await step.prepare?.();
			if (!active || preparation !== currentPreparation) {
				return;
			}
			targets = step.getTargets ? step.getTargets().filter(isVisible) : queryTargets(step.selectors);
			if (step.id === 'hr-person' && prepared === false) {
				dialogDescription.textContent = translate('No employees are currently assigned. Individual timesheets become available when employees are assigned.');
				targets = queryTargets(['#hr-userlist-section']);
			} else if (step.id === 'help' && targets.length === 0) {
				dialogDescription.textContent = translate('General help is hidden on this Nextcloud instance. The compass icon can restart the Timesheet tour at any time.');
				targets = [header.profileMenu(), header.profileTrigger()].filter(isVisible);
			} else if (targets.length === 0) {
				dialogDescription.textContent = translate('This section is not available in the current view. The tour can continue with the next step.');
				targets = [appRoot];
			}
			if (step.hr) {
				explainedHrSteps.add(step.id);
			}
			targets[0]?.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
		})().catch(error => {
			if (step.hr && isHrAccessError(error)) {
				progress.isHr = false;
				steps = steps.filter(item => !item.hr);
				stepIndex = Math.min(stepIndex, steps.length - 2);
				rerouteAfterPreparation = true;
				showTab('mine');
				timesheet.notify(translate('HR access has changed. The tour continues with the features available to this account.'));
			} else {
				console.warn('Timesheet tour view could not be prepared:', error);
				dialogDescription.textContent = translate('This section is not available in the current view. The tour can continue with the next step.');
				targets = [appRoot];
			}
		}).finally(() => {
			preparing = false;
			if (active && !ending) {
				updateButtons();
				positionStep();
				(!nextButton.disabled ? nextButton : endButton).focus({ preventScroll: true });
			}
		});
		await pendingViewAction;
		if (rerouteAfterPreparation && active && !ending) {
			await showStep(stepIndex);
		}
	}

	async function start(hrOnly = false) {
		if (active || !appRoot) {
			return;
		}
		if (progress.version !== tourVersion) {
			timesheet.notify(translate('The tour could not be started. Please try again.'));
			return;
		}
		hideInvitation();
		const run = ++currentRun;
		try {
			snapshot = captureView();
			steps = createSteps(hrOnly);
			stepIndex = 0;
			hrLoaded = false;
			explainedHrSteps.clear();
			active = true;
			ending = false;
			createOverlay();
			await showStep(0);
		} catch (error) {
			if (run !== currentRun) {
				return;
			}
			console.warn('Timesheet tour could not start:', error);
			await end(run);
			timesheet.notify(translate('The tour could not be started. Please try again.'));
		}
	}

	async function end(run = currentRun) {
		if (!active || ending || run !== currentRun) {
			return;
		}
		ending = true;
		appRoot.classList.remove('ts-tour-navigation-preview');
		currentPreparation++;
		updateButtons();
		// Let read-only view preparation finish before putting original nodes back.
		await pendingViewAction;
		window.cancelAnimationFrame(scheduledPosition);
		scheduledPosition = null;
		resizeObserver?.disconnect();
		resizeObserver = null;
		document.removeEventListener('keydown', handleKeyboard, true);
		document.removeEventListener('focusin', keepDialogFocus, true);
		['click', 'pointerdown', 'mousedown', 'touchstart'].forEach(name => document.removeEventListener(name, blockBackgroundInteraction, true));
		window.removeEventListener('resize', schedulePosition);
		window.removeEventListener('scroll', schedulePosition, true);
		for (const item of blockedBackground.splice(0)) {
			item.element.inert = item.inert;
		}
		await restoreView();
		const focusTarget = snapshot?.focus?.isConnected && snapshot.focus !== document.body ? snapshot.focus : launcher;
		overlay?.remove();
		overlay = null;
		active = false;
		snapshot = null;
		finishing = false;
		ending = false;
		focusTarget?.focus({ preventScroll: true });
	}

	function isCurrentRun(run) {
		return active && !ending && run === currentRun;
	}

	async function finish() {
		if (!active || preparing || finishing) {
			return;
		}
		finishing = true;
		updateButtons();
		const run = currentRun;
		const includeHr = progress.isHr && steps.filter(step => step.hr).every(step => explainedHrSteps.has(step.id));
		try {
			for (let attempt = 0; ; attempt++) {
				if (!isCurrentRun(run)) {
					return;
				}
				try {
					const result = await timesheet.api('/api/preferences/tour/complete', {
						method: 'POST', body: JSON.stringify({ version: tourVersion, includeHr }),
					});
					if (!isCurrentRun(run)) {
						return;
					}
					if (!result || result.version !== tourVersion || typeof result.employeeCompleted !== 'boolean' || typeof result.hrCompleted !== 'boolean') {
						throw new Error('Invalid tour completion response');
					}
					progress = { ...progress, ...result, available: true };
					appRoot.dataset.tourEmployeeCompleted = String(result.employeeCompleted);
					appRoot.dataset.tourHrCompleted = String(result.hrCompleted);
					break;
				} catch (error) {
					if (!isCurrentRun(run)) {
						return;
					}
					let response;
					try { response = JSON.parse(error.message); } catch {}
					if (response?.code !== 'preference_busy' || attempt >= 3) {
						throw error;
					}
					await pause(25 * (2 ** attempt));
					if (!isCurrentRun(run)) {
						return;
					}
				}
			}
		} catch (error) {
			if (isCurrentRun(run)) {
				console.warn('Timesheet tour completion could not be saved:', error);
				timesheet.notify(translate('The tour is finished, but its completion could not be saved. Please try again.'));
			}
		} finally {
			if (isCurrentRun(run)) {
				await end(run);
			}
		}
	}

	function init() {
		if (appRoot) {
			return;
		}
		appRoot = document.getElementById('timesheet-app');
		if (!appRoot || appRoot.dataset.tourEnabled === 'false') {
			appRoot = null;
			return;
		}
		progress = readProgress();
		launcher = header.mountLauncher(appRoot, () => start(false));
		window.addEventListener('resize', positionInvitation);
		if (!progress.available) {
			timesheet.notify(translate('Tour progress could not be loaded. The tour can still be opened with the compass icon.'));
		} else if (!progress.employeeCompleted || (progress.isHr && !progress.hrCompleted)) {
			showInvitation();
		}
	}

	timesheet.tour = {
		init, start, end,
		getState: () => ({ active, preparing, finishing, step: steps[stepIndex]?.id || null, index: stepIndex, total: steps.length, ...progress }),
	};
})();
