(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}

	const translate = message => t('timesheet', message);

	// Keep Nextcloud's header integration separate from the tour itself.
	function mountLauncher(appRoot, onActivate) {
		let button = document.getElementById('ts-tour-launcher');
		if (button) {
			return button;
		}
		button = document.createElement('button');
		button.id = 'ts-tour-launcher';
		button.type = 'button';
		button.className = 'ts-tour-launcher';
		button.title = translate('Start the Timesheet tour');
		button.setAttribute('aria-label', translate('Start the Timesheet tour'));
		const icon = document.createElement('span');
		icon.className = 'ts-tour-launcher-icon';
		icon.setAttribute('aria-hidden', 'true');
		const label = document.createElement('span');
		label.className = 'ts-tour-launcher-text';
		label.textContent = translate('Timesheet tour');
		button.append(icon, label);
		button.addEventListener('click', onActivate);

		const search = document.getElementById('unified-search');
		const notifications = document.getElementById('notifications');
		const nextcloudHeader = document.getElementById('header');
		let searchBranch = search;
		// Vue may wrap the search trigger while leaving notifications in the header row.
		while (searchBranch?.parentElement && searchBranch.parentElement !== notifications?.parentElement && searchBranch !== nextcloudHeader) {
			searchBranch = searchBranch.parentElement;
		}
		if (nextcloudHeader?.contains(search) && nextcloudHeader.contains(notifications)
			&& searchBranch?.parentElement === notifications.parentElement) {
			notifications.parentElement.insertBefore(button, notifications);
		} else {
			button.classList.add('ts-tour-launcher-fallback');
			appRoot.prepend(button);
		}
		return button;
	}

	function profileTrigger() {
		return document.querySelector('#user-menu .header-menu__trigger')
			|| document.querySelector('#user-menu button[aria-expanded]');
	}

	function isProfileOpen() {
		return profileTrigger()?.getAttribute('aria-expanded') === 'true';
	}

	function profileMenu() {
		const menuId = profileTrigger()?.getAttribute('aria-controls');
		return menuId ? document.getElementById(menuId) : null;
	}

	function helpItem() {
		return document.getElementById('help')
			|| document.querySelector('#user-menu a[href*="/settings/help"]')
			|| profileMenu()?.querySelector('a[href*="/settings/help"]');
	}

	async function setProfileOpen(shouldOpen, activate) {
		const trigger = profileTrigger();
		if (!trigger) {
			return false;
		}
		if (isProfileOpen() !== shouldOpen) {
			activate(() => trigger.click());
		}
		const deadline = Date.now() + 1200;
		while (Date.now() < deadline) {
			if (isProfileOpen() === shouldOpen && (!shouldOpen || profileMenu() || helpItem())) {
				return true;
			}
			await new Promise(resolve => setTimeout(resolve, 25));
		}
		return isProfileOpen() === shouldOpen;
	}

	timesheet.tourHeader = { mountLauncher, profileTrigger, profileMenu, helpItem, isProfileOpen, setProfileOpen };
})();
