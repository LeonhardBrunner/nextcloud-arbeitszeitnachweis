(function () {
	"use strict";

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}
	const appState = timesheet.state;
	const utils = timesheet.util;
	timesheet.export = timesheet.export || {};
	const exportModule = timesheet.export;
	const exportChoices = {
		mine: {
			visible: false,
			ui: null
		},
		hr: {
			visible: false,
			ui: null
		}
	};

	// Export intervals use month keys for both month and year exports.
	function getMonthExportBounds(date) {
		const year = date.getFullYear();
		const monthIndex = date.getMonth();
		return {
			from: new Date(year, monthIndex, 1),
			to: new Date(year, monthIndex, 1)
		};
	}

	function getYearExportBounds(date) {
		const year = date.getFullYear();
		return {
			from: new Date(year, 0, 1),
			to: new Date(year, 11, 1)
		};
	}

	function getExportTargetUser(viewKind) {
		if (viewKind !== 'hr') {
			return null;
		}
		return timesheet.dom.hrUserTitle?.querySelector('span')?.textContent?.trim() || null;
	}

	function hideExportChoice(viewKind) {
		const choiceState = exportChoices[viewKind];
		if (!choiceState) {
			return;
		}
		choiceState.visible = false;
		if (choiceState.ui?.container) {
			choiceState.ui.container.classList.remove('is-visible');
		}
	}

	function runExport(viewKind, exportScope) {
		const selectedMonth = viewKind === 'hr' ? appState.hrCurrentMonth : appState.currentMonth;
		const bounds = exportScope === 'year' ? getYearExportBounds(selectedMonth) : getMonthExportBounds(selectedMonth);
		const fromMonthString = utils.toLocalMonthStr(bounds.from);
		const toMonthString = utils.toLocalMonthStr(bounds.to);
		let url = `/apps/${appState.appName}/api/entries/export-xlsx?from=${encodeURIComponent(fromMonthString)}&to=${encodeURIComponent(toMonthString)}`;
		const targetUser = getExportTargetUser(viewKind);
		if (targetUser) {
			url += `&user=${encodeURIComponent(targetUser)}`;
		}
		if (utils.USER_LOCALE) {
			url += `&locale=${encodeURIComponent(utils.USER_LOCALE)}`;
		}
		hideExportChoice(viewKind);
		window.location.href = OC.generateUrl(url);
	}

	// Create the month/year controls lazily for each view.
	function ensureExportChoiceUi(viewKind) {
		const exportButtonId = viewKind === 'hr' ? 'export-hr-xlsx' : 'export-mine-xlsx';
		const exportButton = document.getElementById(exportButtonId);
		if (!exportButton) {
			return null;
		}
		const choiceContainerId = `${exportButtonId}-choice-ui`;
		let container = document.getElementById(choiceContainerId);
		if (container) {
			exportChoices[viewKind].ui = exportChoices[viewKind].ui || {
				container,
				month: container.querySelector('[data-action="month"]'),
				year: container.querySelector('[data-action="year"]')
			};
			return exportChoices[viewKind].ui;
		}
		container = document.createElement('span');
		container.id = choiceContainerId;
		container.className = 'ts-export-choice-ui';
		const createChoiceButton = (label, action) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.textContent = label;
			button.dataset.action = action;
			button.className = 'primary';
			return button;
		};
		const monthButton = createChoiceButton(t(appState.appName, 'Month export'), 'month');
		const yearButton = createChoiceButton(t(appState.appName, 'Year export'), 'year');
		container.appendChild(monthButton);
		container.appendChild(yearButton);
		exportButton.insertAdjacentElement('afterend', container);
		const choiceElements = {
			container,
			month: monthButton,
			year: yearButton
		};
		exportChoices[viewKind].ui = choiceElements;
		monthButton.addEventListener('click', () => runExport(viewKind, 'month'));
		yearButton.addEventListener('click', () => runExport(viewKind, 'year'));
		return choiceElements;
	}

	function handleExportClick(viewKind) {
		timesheet.dom.refresh();
		const choiceState = exportChoices[viewKind];
		const choiceElements = choiceState.ui || ensureExportChoiceUi(viewKind);
		if (!choiceElements) {
			return;
		}
		choiceState.visible = !choiceState.visible;
		choiceElements.container.classList.toggle('is-visible', choiceState.visible);
	}

	// Shared export interface.
	exportModule.handleExportClick = handleExportClick;
})();
