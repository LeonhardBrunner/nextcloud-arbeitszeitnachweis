(function () {
	'use strict';

	const timesheet = window.Timesheet;
	if (!timesheet) return;
	const translate = message => t('timesheet', message);
	const confirmedStatuses = new Map();
	const pendingUsers = new Map();
	const listeners = new Set();
	let selectedEmployeeId = null;
	let initialized = false;
	let selfFeedback = '';
	const currentUserId = timesheet.state.currentUserId;

	function validStatus(status) {
		return status && typeof status.isActive === 'boolean' && typeof status.freelancerMode === 'boolean'
			&& typeof status.statusRevision === 'string' && status.statusRevision.length > 0;
	}

	function remember(userId, status) {
		if (validStatus(status)) {
			confirmedStatuses.set(userId, { isActive: status.isActive, freelancerMode: status.freelancerMode, statusRevision: status.statusRevision });
		}
	}

	// Render confirmed account state; this method never saves a preference.
	function refresh() {
		const selfStatus = pendingUsers.get(currentUserId) || confirmedStatuses.get(currentUserId);
		const switchInput = document.getElementById('freelancer-mode-switch');
		if (switchInput) {
			switchInput.checked = selfStatus?.freelancerMode ?? false;
			switchInput.setAttribute('aria-checked', String(switchInput.checked));
			switchInput.disabled = !selfStatus || pendingUsers.has(currentUserId);
		}
		const selfMessage = document.getElementById('freelancer-mode-feedback');
		if (selfMessage) selfMessage.textContent = selfFeedback || (!selfStatus ? translate('Employment status could not be loaded. Reload the page to try again.') : '');
		const selectedStatus = pendingUsers.get(selectedEmployeeId) || confirmedStatuses.get(selectedEmployeeId);
		const activityLabel = document.getElementById('hr-employment-status');
		if (activityLabel) {
			// Group membership determines activity; HR only reads the server-provided value.
			activityLabel.textContent = selectedStatus ? translate(selectedStatus.isActive ? 'Active' : 'Inactive') : '-';
		}
		const badge = document.getElementById('hr-employee-freelancer');
		if (badge) badge.hidden = !selectedStatus?.freelancerMode;
		const employeeMessage = document.getElementById('hr-employment-status-feedback');
		if (employeeMessage) employeeMessage.textContent = selectedEmployeeId && !selectedStatus ? translate('Employment status could not be loaded. Reload the page to try again.') : '';
	}

	// The only writable classification in this interface is the account's own freelancer mode.
	async function saveFreelancerMode(freelancerMode) {
		const userId = currentUserId;
		const previousStatus = confirmedStatuses.get(userId);
		if (!previousStatus || pendingUsers.has(userId)) return;
		pendingUsers.set(userId, { ...previousStatus, freelancerMode });
		selfFeedback = translate('Saving employment status…');
		refresh();
		try {
			const result = await timesheet.api('/api/preferences/freelancer-mode', { method: 'PUT', body: JSON.stringify({ freelancerMode, statusRevision: previousStatus.statusRevision }) });
			if (!validStatus(result)) throw new Error('Invalid employment status response');
			remember(userId, result);
			selfFeedback = '';
			for (const listener of listeners) listener(userId, confirmedStatuses.get(userId));
		} catch (error) {
			let conflict;
			try { conflict = JSON.parse(error.message); } catch {}
			const isConflict = conflict?.code === 'employment_status_conflict' && validStatus(conflict);
			if (isConflict) {
				remember(userId, conflict);
				for (const listener of listeners) listener(userId, confirmedStatuses.get(userId));
			}
			selfFeedback = translate(isConflict ? 'Employment status changed in another window. The current value has been restored.' : 'Employment status could not be saved.');
		} finally {
			pendingUsers.delete(userId);
			refresh();
		}
	}

	function setHrEmployee(userId, status) {
		selectedEmployeeId = userId;
		if (!pendingUsers.has(userId)) remember(userId, status);
		refresh();
	}

	function init() {
		if (initialized) return;
		initialized = true;
		const root = document.getElementById('timesheet-app');
		if (root?.dataset.employmentStatusAvailable === 'true') {
			remember(currentUserId, {
				isActive: root.dataset.employmentIsActive === 'true',
				freelancerMode: root.dataset.freelancerMode === 'true',
				statusRevision: root.dataset.employmentStatusRevision,
			});
		}
		document.getElementById('freelancer-mode-switch')?.addEventListener('change', async event => {
			const choice = event.target.checked;
			await saveFreelancerMode(choice);
		});
		refresh();
	}

	timesheet.employmentStatus = { init, refresh, setHrEmployee, onChange: listener => listeners.add(listener) };
})();
