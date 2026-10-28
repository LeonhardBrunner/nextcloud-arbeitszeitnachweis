(function () {
	"use strict";

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}
	const appState = timesheet.state;
	const utils = timesheet.util;
	timesheet.copy = timesheet.copy || {};
	const copyModule = timesheet.copy;

	// Copy selected field text or the hovered entry row.
	function initializeCopyHandlers() {
		// Handle pointer over to track hovered row
		document.addEventListener('pointerover', event => {
			const row = event.target.closest(appState.TS_ROW_SCOPE);
			if (row) {
				appState.tsHoveredRow = row;
			}
		});

		// Handle pointer out to clear hovered row
		document.addEventListener('pointerout', event => {
			const row = event.target.closest(appState.TS_ROW_SCOPE);
			if (row && appState.tsHoveredRow === row) {
				appState.tsHoveredRow = null;
			}
		});

		// Copy event handler
		document.addEventListener('copy', event => {
			const activeElement = document.activeElement;
			const activeTagName = (activeElement?.tagName || '').toUpperCase();
			const isInputLike = activeTagName === 'INPUT' || activeTagName === 'TEXTAREA';
			if (isInputLike && utils.isTimesheetCellField(activeElement)) {
				const hasSelection = typeof activeElement.selectionStart === 'number'
					&& typeof activeElement.selectionEnd === 'number'
					&& activeElement.selectionEnd > activeElement.selectionStart;
				if (hasSelection) {
					return;
				}
				if (!event.clipboardData) {
					return;
				}
				event.clipboardData.setData('text/plain', String(activeElement.value ?? ''));
				event.preventDefault();
				timesheet.notify(t(appState.appName, 'Copied to clipboard'));
				return;
			}
			const inEditable = isInputLike || !!activeElement?.isContentEditable;
			if (inEditable) {
				return;
			}
			if (!appState.tsHoveredRow) {
				return;
			}
			if (utils.hasSelection()) {
				return;
			}
			if (!event.clipboardData) {
				return;
			}
			const startText = (appState.tsHoveredRow.querySelector('.startTime')?.value || '').trim();
			const breakText = (appState.tsHoveredRow.querySelector('.breakMinutes')?.value || '').trim();
			const endText = (appState.tsHoveredRow.querySelector('.endTime')?.value || '').trim();
			let comment = (appState.tsHoveredRow.querySelector('.commentInput')?.value || '').trim();
			comment = comment.replace(/\t/g, ' ').replace(/\r?\n/g, ' '); // Remove tabs and new lines

			event.clipboardData.setData('text/plain', [startText, breakText, endText, comment].join('\t'));
			event.preventDefault();
			timesheet.notify(t(appState.appName, 'Row copied to clipboard'));
		});
	}

	// Shared copy interface
	copyModule.init = initializeCopyHandlers;
})();
