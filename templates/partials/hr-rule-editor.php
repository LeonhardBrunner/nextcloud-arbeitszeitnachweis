<?php
	$editorScope = $ruleEditorData['settingsScope'] ?? 'policy';
	$managesAssignments = $editorScope === 'assignments';
	$policySection = $ruleEditorData['policySection'] ?? ($managesAssignments ? '' : 'timeRules');
	$allGroups = $ruleEditorData['allGroups'] ?? [];
	$hrAccessRules = $ruleEditorData['hrAccessRules'] ?? [];
	$hrPeople = $ruleEditorData['hrPeople'] ?? [];
	$countSpecialDaysAsOvertime = (bool)($ruleEditorData['countSpecialDaysAsOvertime'] ?? false);
	$embeddedJsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="timesheet-hr-settings" class="ts-rule-editor"
	data-settings-scope="<?php p($editorScope); ?>"
	data-policy-section="<?php p($policySection); ?>"
	data-rules-revision="<?php p($ruleEditorData['rulesRevision'] ?? ''); ?>">
	<?php if ($policySection !== 'notifications'): ?>
	<h2><?php p($l->t('Timesheet settings')); ?></h2>
	<?php endif; ?>
	<?php if ($managesAssignments && empty($allGroups)) : ?>
		<div class="ts-empty"><?php p($l->t('No groups found. Create at least one group to configure rules.')); ?></div>
	<?php endif; ?>

	<?php if ($policySection === 'notifications'): ?>
	<div class="ts-notification-rule-choice">
		<label for="timesheet-notification-rule"><?php p($l->t('Group rule')); ?></label>
		<select id="timesheet-notification-rule"></select>
	</div>
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

	<?php if ($policySection === 'timeRules'): ?>
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

</div>
