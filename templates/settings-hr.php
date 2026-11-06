<?php
	$allGroups = $_['allGroups'] ?? [];
	$managesAssignments = ($_['settingsScope'] ?? 'policy') === 'assignments';
	$hrAccessRules = $_['hrAccessRules'] ?? [];
	$hrPeople = $_['hrPeople'] ?? [];
	$countSpecialDaysAsOvertime = (bool)($_['countSpecialDaysAsOvertime'] ?? false);
	$embeddedJsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<div id="timesheet-hr-settings" class="section"
	data-settings-scope="<?php p($managesAssignments ? 'assignments' : 'policy'); ?>"
	data-rules-revision="<?php p($_['rulesRevision'] ?? ''); ?>">
	<!-- Assignment and policy forms share the rule layout, with separate controls. -->
	<div class="ts-settings-heading">
		<h2><?php p($l->t('Timesheet settings')); ?></h2>
		<button type="button" class="ts-rules-help-toggle" id="timesheet-rules-help-toggle"
			aria-expanded="false" aria-controls="timesheet-rules-help"
			aria-label="<?php p($l->t('Group rules explained')); ?>"
			title="<?php p($l->t('Group rules explained')); ?>">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<circle cx="12" cy="12" r="9" />
				<path d="M9.5 9a2.5 2.5 0 0 1 5 .3c0 1.8-2.5 2-2.5 3.7" />
				<path d="M12 16.5h.01" />
			</svg>
		</button>
	</div>
	<!-- The help opens in the page flow so it cannot cover the rule controls. -->
	<div id="timesheet-rules-help" class="ts-rules-help" role="region" aria-label="<?php p($l->t('Group rules explained')); ?>" hidden>
		<p><?php p($l->t('Group rules apply to the assigned employees. Administrators manage HR access and employee groups.')); ?></p>
		<?php if ($managesAssignments): ?>
			<p><?php p($l->t('HR groups contain responsible HR accounts. Employee groups contain the employees they manage.')); ?></p>
		<?php endif; ?>
		<p><strong><?php p($l->t('Time rules')); ?>:</strong> <?php p($l->t('The rule with the lowest priority number applies. Equal priorities keep the first saved rule.')); ?></p>
		<p><?php p($l->t('Missing-entry reminders: checked daily; employee and HR delivery can be enabled separately. Successful reminders are at least seven days apart.')); ?></p>
		<p><?php p($l->t('Employee balance emails: sent on Mondays when the configured overtime or negative-hours threshold is exceeded.')); ?></p>
		<p><?php p($l->t('Personal HR emails are configured in the HR overview and sent on Mondays. The group reminder switches do not control these emails.')); ?></p>
		<p><?php p($l->t('Email selection does not change HR access.')); ?></p>
		<p><?php p($l->t('Freelancers and inactive employees receive no warning emails.')); ?></p>
	</div>

	<?php if ($managesAssignments && empty($allGroups)) : ?>
		<div class="ts-empty"><?php p($l->t('No groups found. Create at least one group to configure rules.')); ?></div>
	<?php endif; ?>

	<div id="timesheet-hr-rules"></div>

	<?php if ($managesAssignments): ?>
	<button type="button" class="button" id="timesheet-add-hr-rule">
		<?php p($l->t('Add new rule')); ?>
	</button>
	<?php endif; ?>

	<!-- Initial data for the HR rule editor -->
	<script id="timesheet-all-groups-data" type="application/json">
		<?php print_unescaped(json_encode(array_values($allGroups), $embeddedJsonFlags)); ?>
	</script>

	<script id="timesheet-hr-rules-data" type="application/json">
		<?php print_unescaped(json_encode(array_values($hrAccessRules), $embeddedJsonFlags)); ?>
	</script>

	<script id="timesheet-hr-people-data" type="application/json">
		<?php print_unescaped(json_encode(array_values($hrPeople), $embeddedJsonFlags)); ?>
	</script>

	<?php if (!$managesAssignments): ?>
	<hr class="ts-divider" />

	<!-- Overtime calculation setting -->
	<h2><?php p($l->t('Overtime calculation')); ?></h2>
	<p class="ts-hint">
		<?php p($l->t('Control how weekends and public holidays affect the expected working time.')); ?>
	</p>

	<div class="ts-setting">
		<input type="checkbox" class="checkbox" id="timesheet-specialdays-overtime" data-setting="countSpecialDaysAsOvertime"
			<?php if ($countSpecialDaysAsOvertime) { print_unescaped('checked="checked"'); } ?>
		/>
		<label for="timesheet-specialdays-overtime">
			<?php p($l->t('Count weekends and public holidays as overtime only')); ?>
		</label>
		<div class="ts-setting-desc">
			<?php p($l->t('If enabled, weekends and public holidays will not count as regular workdays. Any hours worked on these days are treated as overtime and do not create minus hours.')); ?>
		</div>
	</div>

	<?php endif; ?>

	<!-- Shared settings styles -->
	<style>
		/* Inline help uses neutral controls and leaves every rule visible. */
		#timesheet-hr-settings .ts-settings-heading {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 12px;
		}

		#timesheet-hr-settings .ts-settings-heading h2 {
			margin: 0;
		}

		#timesheet-hr-settings .ts-rules-help-toggle {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
			width: 36px;
			height: 36px;
			min-height: 36px;
			padding: 6px;
			margin: 0;
			background: var(--color-background-dark);
			color: var(--color-main-text);
			border: 1px solid var(--color-border);
		}

		#timesheet-hr-settings .ts-rules-help-toggle:hover {
			background: var(--color-background-hover);
		}

		#timesheet-hr-settings .ts-rules-help-toggle:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
		}

		#timesheet-hr-settings .ts-rules-help {
			box-sizing: border-box;
			max-width: 75ch;
			margin: 0 0 16px;
			padding: 12px 16px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			background: var(--color-main-background);
			line-height: 1.5;
		}

		#timesheet-hr-settings .ts-rules-help p {
			margin: 0 0 8px;
		}

		#timesheet-hr-settings .ts-rules-help p:last-child {
			margin-bottom: 0;
		}

		/* Rule list and cards */
		#timesheet-hr-rules {
			margin: 12px 0 10px;
			display: grid;
			gap: 12px;
		}

		.ts-hint {
			margin: 8px 0 12px;
			opacity: .85;
			max-width: 900px;
		}

		.ts-rule {
			border: 1px solid var(--color-border);
			border-radius: 10px;
			padding: 12px;
			background: var(--color-main-background);
		}

		/* Rule controls */
		.ts-rule-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
			margin-bottom: 8px;
		}

		.ts-rule-title {
			font-weight: 700;
		}

		.ts-rule-head-actions {
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.ts-rule-priority {
			display: flex;
			align-items: center;
			gap: 6px;
			font-weight: 600;
		}

		.ts-rule-priority input {
			width: 90px;
		}

		.ts-rule-delete {
			text-decoration: none;
			font-weight: 800;
			opacity: .7;
		}

		.ts-rule-delete:hover {
			opacity: 1;
		}

		/* Group selection */
		.ts-rule-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 14px;
		}

		@media (max-width: 980px) {
			.ts-rule-grid {
				grid-template-columns: 1fr;
			}
		}

		.ts-rule-col label {
			font-weight: 600;
			display: block;
			margin-bottom: 4px;
		}

		.ts-chips {
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
			min-height: 28px;
			margin: 4px 0 6px;
		}

		.ts-chip {
			background: var(--color-background-dark);
			border: 1px solid var(--color-border);
			border-radius: 999px;
			padding: 2px 10px;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}

		.ts-chip-remove {
			text-decoration: none;
			font-weight: 800;
			opacity: .75;
		}

		.ts-chip-remove:hover {
			opacity: 1;
		}

		.ts-rule-empty {
			opacity: .7;
			font-style: italic;
		}

		.ts-rule-select {
			max-width: 420px;
		}

		/* Searchable account assignments use native controls and neutral actions. */
		.ts-rule-col {
			min-width: 0;
		}

		.ts-person-picker {
			margin-top: 12px;
		}

		.ts-person-picker > label,
		.ts-mail-recipient-settings > label {
			display: block;
			font-weight: 600;
			margin-bottom: 4px;
		}

		.ts-person-search,
		.ts-person-choice,
		.ts-mail-recipient-mode {
			box-sizing: border-box;
			width: 100%;
			max-width: 420px;
		}

		.ts-person-choice {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px;
			margin-top: 6px;
		}

		.ts-person-results {
			min-width: 0;
			max-width: 100%;
			flex: 1 1 200px;
		}

		.ts-person-search-status {
			min-height: 1.4em;
		}

		.ts-person-picker .ts-chip {
			box-sizing: border-box;
			max-width: 100%;
		}

		.ts-person-picker .ts-chip > span {
			min-width: 0;
			overflow-wrap: anywhere;
		}

		#timesheet-hr-settings .ts-person-remove {
			width: 32px;
			min-width: 32px;
			min-height: 32px;
			padding: 0;
			margin: 0;
			border: 0;
			background: transparent;
			color: inherit;
		}

		#timesheet-hr-settings .ts-person-add {
			background: var(--color-background-dark);
			color: var(--color-main-text);
			border: 1px solid var(--color-border);
		}

		#timesheet-hr-settings .ts-person-remove:hover,
		#timesheet-hr-settings .ts-person-remove:focus-visible,
		#timesheet-hr-settings .ts-person-add:hover,
		#timesheet-hr-settings .ts-person-add:focus-visible {
			background: var(--color-background-hover);
		}

		/* Missing-entry reminder controls */
		.ts-reminder-toggle,
		.ts-reminder-weekday {
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}

		.ts-reminder-weekdays {
			display: flex;
			flex-wrap: wrap;
			gap: 8px 16px;
			margin-top: 8px;
		}

		.ts-reminder-settings input[type="number"] {
			width: 100px;
		}

		/* Balance thresholds remain compact when the rule columns stack. */
		#timesheet-hr-settings .ts-balance-settings .ts-inline {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 6px;
			margin-top: 6px;
		}

		#timesheet-hr-settings .ts-balance-settings input[type="number"] {
			box-sizing: border-box;
			width: 110px;
			max-width: 100%;
		}

		/* General settings */
		.ts-divider {
			margin: 18px 0 14px;
			border: 0;
			border-top: 1px solid var(--color-border);
		}

		.ts-setting {
			display: flex;
			align-items: flex-start;
			gap: 10px;
			padding: 10px 12px;
			border: 1px solid var(--color-border);
		}

		.ts-setting-desc {
			margin-top: 4px;
			opacity: .85;
			font-size: 13px;
			line-height: 1.4;
		}

		.ts-empty {
			padding: 10px;
			border: 1px dashed var(--color-border);
			border-radius: 8px;
		}

		/* Time rules */
		.ts-inline {
			display: flex;
			align-items: center;
			gap: 6px;
			flex-wrap: wrap;
		}

		.ts-rule-section {
			margin-top: 10px;
			border-top: 1px dashed var(--color-border);
			padding-top: 10px;
		}

		.ts-rule-section-title {
			font-weight: 700;
			margin-bottom: 6px;
		}

		.ts-rule-grid-compact {
			grid-template-columns: 1fr 1fr;
		}

		@media (max-width: 980px) {
			.ts-rule-grid-compact {
				grid-template-columns: 1fr;
			}
		}
	</style>
</div>
