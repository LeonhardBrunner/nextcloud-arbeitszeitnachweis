(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}

	const translate = message => t('timesheet', message);
	let emailHelp;
	let helpAnchor;
	let helpTrigger;

	function close(restoreFocus = false) {
		emailHelp?.remove();
		emailHelp = null;
		if (restoreFocus) {
			helpAnchor?.focus({ preventScroll: true });
		}
		helpAnchor = null;
		helpTrigger = null;
	}

	function positionPopup(popup, anchor) {
		if (!popup || popup.hidden || !anchor) {
			return;
		}
		const bounds = anchor.getBoundingClientRect();
		const size = popup.getBoundingClientRect();
		const isRightToLeft = window.getComputedStyle(anchor).direction === 'rtl';
		const preferredLeft = isRightToLeft ? bounds.left : bounds.right - size.width;
		const below = bounds.bottom + 8;
		const preferredTop = below + size.height <= window.innerHeight - 12 ? below : bounds.top - size.height - 8;
		popup.style.left = `${Math.max(12, Math.min(window.innerWidth - size.width - 12, preferredLeft))}px`;
		popup.style.top = `${Math.max(12, Math.min(window.innerHeight - size.height - 12, preferredTop))}px`;
	}

	function positionEmailHelp() {
		positionPopup(emailHelp, helpAnchor);
	}

	function show(anchor, trigger = anchor) {
		close();
		helpAnchor = anchor;
		helpTrigger = trigger;
		emailHelp = document.createElement('div');
		emailHelp.id = 'ts-email-help';
		emailHelp.className = 'ts-tour-email-help';
		emailHelp.setAttribute('role', 'region');
		emailHelp.setAttribute('aria-labelledby', 'ts-email-help-title');
		emailHelp.setAttribute('aria-live', 'polite');

		const status = document.createElement('p');
		status.id = 'ts-email-help-status';
		status.className = 'ts-email-help-status';
		status.setAttribute('role', 'status');
		status.textContent = translate('Opening the default mail app…');

		const heading = document.createElement('h2');
		heading.id = 'ts-email-help-title';
		heading.textContent = translate('No mail app opened?');

		const description = document.createElement('p');
		description.textContent = translate('Email links require a default mail app in the browser or operating system.');

		const closeButton = document.createElement('button');
		closeButton.id = 'ts-email-help-close';
		closeButton.className = 'ts-tour-close';
		closeButton.type = 'button';
		closeButton.setAttribute('aria-label', translate('Close email help'));
		closeButton.addEventListener('click', () => close(true));

		emailHelp.append(status, heading, description, closeButton);
		document.body.append(emailHelp);
		positionEmailHelp();
	}

	function closeOutside(event) {
		if (emailHelp && !emailHelp.contains(event.target)
			&& !helpAnchor.contains(event.target) && !helpTrigger.contains(event.target)) {
			close();
		}
	}

	// An external mail window can take focus while the hint is still useful.
	document.addEventListener('pointerdown', closeOutside);
	document.addEventListener('click', closeOutside);
	document.addEventListener('keydown', event => {
		if (emailHelp && event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			close(true);
		}
	});
	window.addEventListener('resize', positionEmailHelp);
	window.addEventListener('scroll', positionEmailHelp, true);

	function bindAppRequestLink() {
		const link = document.querySelector('.hr-app-request');
		link?.addEventListener('click', () => show(link));
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindAppRequestLink, { once: true });
	} else {
		bindAppRequestLink();
	}

	timesheet.mailHelp = { show, close, positionPopup };
})();
