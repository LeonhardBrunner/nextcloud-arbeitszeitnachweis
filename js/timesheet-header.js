(function () {
	'use strict';

	const menuSelector = '#contactsmenu .header-menu__trigger, #user-menu .header-menu__trigger';
	const assignedTitles = new WeakMap();

	// Reuse Nextcloud's account-language labels without replacing native tooltips.
	function updateMenuTitle(button) {
		const label = button.getAttribute('aria-label')?.trim();
		const currentTitle = button.getAttribute('title');
		if (label && (!currentTitle || currentTitle === assignedTitles.get(button))) {
			button.title = label;
			assignedTitles.set(button, label);
		}
	}

	function initializeHeaderTitles() {
		const header = document.getElementById('header');
		if (!header) {
			return;
		}
		header.querySelectorAll(menuSelector).forEach(updateMenuTitle);
		// Delegation also covers menu buttons that Nextcloud renders or replaces later.
		const updateHoveredMenu = event => {
			const button = event.target.closest?.(menuSelector);
			if (button && header.contains(button)) {
				updateMenuTitle(button);
			}
		};
		header.addEventListener('pointerover', updateHoveredMenu);
		header.addEventListener('focusin', updateHoveredMenu);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initializeHeaderTitles, { once: true });
	} else {
		initializeHeaderTitles();
	}
})();
