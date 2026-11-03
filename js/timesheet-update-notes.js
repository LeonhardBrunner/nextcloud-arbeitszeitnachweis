(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) return;
	const translate = (message, parameters = {}) => t('timesheet', message, parameters);
	let initialization;

	function createElement(tag, className = '', text = '') {
		const element = document.createElement(tag);
		if (className) element.className = className;
		if (text) element.textContent = text;
		return element;
	}

	function readOpenMode() {
		const url = new URL(window.location.href);
		const manual = url.searchParams.get('update-notes') === '1';
		if (manual) {
			url.searchParams.delete('update-notes');
			// A later reload follows normal once-per-version behavior.
			try { window.history.replaceState(window.history.state, '', url.href); } catch {}
		}
		return manual ? 'manual' : 'automatic';
	}

	function validateNotes(notes) {
		if (!notes || typeof notes.enabled !== 'boolean' || typeof notes.shouldShow !== 'boolean' || !Array.isArray(notes.releases)) return false;
		if (notes.releases.length === 0) return true;
		if (typeof notes.throughVersion !== 'string' || notes.throughVersion.length === 0) return false;
		return notes.releases.every(release => typeof release.version === 'string' && Array.isArray(release.sections)
			&& release.sections.length > 0 && release.sections.every(section => typeof section.title === 'string'
				&& Array.isArray(section.items) && section.items.length > 0 && section.items.every(item => typeof item === 'string')));
	}

	function addRelease(content, release) {
		const releaseSection = createElement('section', 'ts-update-notes-release');
		releaseSection.append(createElement('h3', '', translate('Version {version}', { version: release.version })));
		for (const section of release.sections) {
			releaseSection.append(createElement('h4', '', section.title));
			const items = createElement('ul');
			// Release strings are translated by the server and rendered strictly as text.
			for (const item of section.items) items.append(createElement('li', '', item));
			releaseSection.append(items);
		}
		content.append(releaseSection);
	}

	function showNotes(notes) {
		const previousFocus = document.activeElement;
		const previousOverflow = document.body.style.overflow;
		const blockedElements = [];
		const backdrop = createElement('div', 'ts-update-notes-backdrop');
		const dialog = createElement('div', 'ts-update-notes-dialog');
		dialog.id = 'ts-update-notes-dialog';
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', 'ts-update-notes-title');
		const heading = createElement('h2', '', translate("What's new in Timesheet?"));
		heading.id = 'ts-update-notes-title';
		heading.setAttribute('tabindex', '-1');
		const header = createElement('div', 'ts-update-notes-header');
		header.append(heading);
		const content = createElement('div', 'ts-update-notes-content');
		for (const release of notes.releases) addRelease(content, release);
		const footer = createElement('div', 'ts-update-notes-footer');
		const closeButton = createElement('button', 'ts-update-notes-action', translate('Close'));
		closeButton.type = 'button';
		footer.append(closeButton);
		dialog.append(header, content, footer);
		backdrop.append(dialog);

		return new Promise(resolve => {
			let closed = false;
			let displayConfirmed = false;
			let confirmationFrame;
			function confirmDisplay() {
				if (closed || displayConfirmed || document.visibilityState === 'hidden' || !dialog.isConnected || !dialog.getClientRects().length) return;
				displayConfirmed = true;
				// Closing this window never dismisses the independent native bell entry.
				timesheet.api('/api/update-notes/shown', {
					method: 'POST', body: JSON.stringify({ throughVersion: notes.throughVersion }),
				}).catch(error => console.warn('Update note display could not be saved:', error));
			}
			function close() {
				if (closed) return;
				closed = true;
				window.cancelAnimationFrame(confirmationFrame);
				document.removeEventListener('keydown', keepKeyboardInDialog, true);
				document.removeEventListener('focusin', keepFocusInDialog, true);
				document.removeEventListener('visibilitychange', confirmDisplay);
				for (const item of blockedElements) item.element.inert = item.inert;
				document.body.style.overflow = previousOverflow;
				backdrop.remove();
				const focusTarget = previousFocus?.isConnected && previousFocus !== document.body
					? previousFocus : document.getElementById('month-display');
				focusTarget?.focus({ preventScroll: true });
				resolve();
			}
			function keepKeyboardInDialog(event) {
				if (event.key === 'Escape') {
					event.preventDefault();
					event.stopPropagation();
					close();
				} else if (event.key === 'Tab') {
					// The single close control remains the keyboard focus in either direction.
					event.preventDefault();
					closeButton.focus();
				}
			}
			function keepFocusInDialog(event) {
				if (!closed && !dialog.contains(event.target)) closeButton.focus();
			}

			try {
				document.body.append(backdrop);
				for (const element of document.body.children) {
					if (element !== backdrop && element.tagName !== 'SCRIPT' && element.tagName !== 'STYLE' && element.tagName !== 'LINK') {
						blockedElements.push({ element, inert: element.inert });
						element.inert = true;
					}
				}
				document.body.style.overflow = 'hidden';
				closeButton.addEventListener('click', close);
				document.addEventListener('keydown', keepKeyboardInDialog, true);
				document.addEventListener('focusin', keepFocusInDialog, true);
				document.addEventListener('visibilitychange', confirmDisplay);
				// Start at the heading so long release notes do not scroll to the footer.
				heading.focus({ preventScroll: true });
				confirmationFrame = window.requestAnimationFrame(confirmDisplay);
			} catch (error) {
				close();
				console.warn('Update notes could not be rendered:', error);
			}
		});
	}

	async function initialize() {
		const root = document.getElementById('timesheet-app');
		if (root?.dataset.updateNotesEnabled !== 'true') return;
		let mode = 'automatic';
		try {
			mode = readOpenMode();
			const notes = await timesheet.api('/api/update-notes', { method: 'POST', body: JSON.stringify({ mode }) });
			if (!validateNotes(notes)) throw new Error('Invalid update notes response');
			if (!notes.enabled || notes.releases.length === 0 || mode === 'automatic' && !notes.shouldShow) return;
			await showNotes(notes);
		} catch (error) {
			console.warn('Update notes could not be loaded:', error);
			if (mode === 'manual') timesheet.notify(translate('The update notes could not be loaded. Please try again.'));
		}
	}

	timesheet.updateNotes = {
		init() {
			initialization ??= initialize();
			return initialization;
		},
	};
})();
