(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) {
		return;
	}

	const translate = message => t('timesheet', message);
	let menu;
	const { close: closeEmailHelp, show: showEmailHelp, positionPopup } = timesheet.mailHelp;
	let menuLauncher;
	let menuItems = [];
	let beforeMenuOpen = () => {};

	// The menu only opens a tour or an email draft; it never submits app data.
	function closeMenu(restoreFocus = false) {
		if (!menu || !menuLauncher) {
			return;
		}
		menu.hidden = true;
		menuLauncher.setAttribute('aria-expanded', 'false');
		if (restoreFocus) {
			menuLauncher.focus({ preventScroll: true });
		}
	}

	function positionMenu() {
		positionPopup(menu, menuLauncher);
	}

	function openMenu(itemIndex = 0) {
		closeEmailHelp();
		beforeMenuOpen();
		menu.hidden = false;
		menuLauncher.setAttribute('aria-expanded', 'true');
		positionMenu();
		menuItems[itemIndex]?.focus({ preventScroll: true });
	}

	function closeMenuOutside(event) {
		if (menu && !menu.hidden && !menu.contains(event.target) && !menuLauncher.contains(event.target)) {
			closeMenu();
		}
	}

	function handleMenuKeyboard(event) {
		const currentIndex = menuItems.indexOf(document.activeElement);
		let nextIndex;
		switch (event.key) {
		case 'ArrowDown': nextIndex = (currentIndex + 1) % menuItems.length; break;
		case 'ArrowUp': nextIndex = (currentIndex + menuItems.length - 1) % menuItems.length; break;
		case 'Home': nextIndex = 0; break;
		case 'End': nextIndex = menuItems.length - 1; break;
		case 'Escape':
			event.preventDefault();
			event.stopPropagation();
			closeMenu(true);
			return;
		case 'Tab':
			// Native Tab movement continues from the launcher after the popup closes.
			closeMenu(true);
			return;
		case ' ':
			event.preventDefault();
			document.activeElement?.click();
			return;
		default: return;
		}
		event.preventDefault();
		event.stopPropagation();
		menuItems[nextIndex]?.focus({ preventScroll: true });
	}

	function createMenuItem(tagName, id, label, itemName) {
		const item = document.createElement(tagName);
		item.id = id;
		item.className = 'ts-tour-help-menu-item';
		item.textContent = translate(label);
		item.dataset.tourMenuItem = itemName;
		item.setAttribute('role', 'menuitem');
		item.setAttribute('tabindex', '-1');
		return item;
	}

	function createHelpMenu(button, contactDrafts, onActivate, onMenuOpen) {
		menuLauncher = button;
		beforeMenuOpen = onMenuOpen;
		menu = document.createElement('div');
		menu.id = 'ts-tour-help-menu';
		menu.className = 'ts-tour-help-menu';
		menu.hidden = true;
		menu.setAttribute('role', 'menu');
		menu.setAttribute('aria-labelledby', button.id);
		const guide = createMenuItem('button', 'ts-tour-menu-guide', 'Tour guide', 'guide');
		guide.type = 'button';
		guide.addEventListener('click', () => {
			// Tour restoration returns to the visible launcher rather than a hidden menu item.
			closeMenu(true);
			onActivate();
		});
		const questions = createMenuItem('a', 'ts-tour-menu-questions', 'Questions for HR', 'questions');
		const suggestions = createMenuItem('a', 'ts-tour-menu-suggestions', 'Suggestions for improvement', 'suggestions');
		questions.setAttribute('href', contactDrafts.tourQuestionDraft);
		suggestions.setAttribute('href', contactDrafts.tourFeedbackDraft);
		for (const link of [questions, suggestions]) {
			link.addEventListener('click', () => {
				closeMenu(true);
				showEmailHelp(menuLauncher, link);
			});
		}
		menuItems = [guide, questions, suggestions];
		menu.append(...menuItems);
		menu.addEventListener('keydown', handleMenuKeyboard);
		document.body.append(menu);
		button.addEventListener('click', () => menu.hidden ? openMenu() : closeMenu(true));
		button.addEventListener('keydown', event => {
			if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
				event.preventDefault();
				event.stopPropagation();
				if (!menu.hidden && ['Enter', ' '].includes(event.key)) {
					closeMenu(true);
				} else {
					openMenu(event.key === 'ArrowUp' ? menuItems.length - 1 : 0);
				}
			} else if (event.key === 'Escape' && !menu.hidden) {
				event.preventDefault();
				closeMenu(true);
			}
		});
		['pointerdown', 'click', 'focusin'].forEach(name => document.addEventListener(name, closeMenuOutside));
		window.addEventListener('resize', positionMenu);
		window.addEventListener('scroll', positionMenu, true);
	}

	// Keep Nextcloud's header integration separate from the tour itself.
	function mountLauncher(appRoot, onActivate, onMenuOpen = () => {}) {
		let button = document.getElementById('ts-tour-launcher');
		if (button) {
			return button;
		}
		button = document.createElement('button');
		button.id = 'ts-tour-launcher';
		button.type = 'button';
		button.className = 'ts-tour-launcher';
		button.title = translate('Help and tour');
		button.setAttribute('aria-label', translate('Help and tour'));
		button.setAttribute('aria-expanded', 'false');
		button.setAttribute('aria-haspopup', 'menu');
		button.setAttribute('aria-controls', 'ts-tour-help-menu');
		const icon = document.createElement('span');
		icon.className = 'ts-tour-launcher-icon';
		icon.setAttribute('aria-hidden', 'true');
		const label = document.createElement('span');
		label.className = 'ts-tour-launcher-text';
		label.textContent = translate('Help and tour');
		button.append(icon, label);
		createHelpMenu(button, appRoot.dataset, onActivate, onMenuOpen);

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

	timesheet.tourHeader = { mountLauncher, closeMenu, closeEmailHelp, profileTrigger, profileMenu, helpItem, isProfileOpen, setProfileOpen };
})();
