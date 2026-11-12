<?php
$notificationFields = [
	['key' => 'noEntryDays', 'enabled' => 'noEntryEnabled', 'label' => 'Days since the last complete entry', 'unit' => 'calendar days', 'help' => 'This alert shows when an employee last made an entry in the timesheet. The threshold in calendar days determines when HR is notified.'],
	['key' => 'overtimeThresholdMinutes', 'enabled' => 'overtimeEnabled', 'label' => 'Overtime', 'unit' => 'hours', 'help' => 'With a threshold of 10 hours, HR can see in the employee list who has 10 or more hours of overtime.'],
	['key' => 'negativeOvertimeThresholdMinutes', 'enabled' => 'negativeOvertimeEnabled', 'label' => 'Negative hours', 'unit' => 'hours', 'help' => 'With a threshold of 10 hours, HR can see in the employee list who has a time deficit of 10 hours or more.'],
];
$helpIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" /><path d="M9.5 9a2.5 2.5 0 0 1 5 .3c0 1.8-2.5 2-2.5 3.7" /><circle cx="12" cy="16.5" r=".5" /></svg>';
?>
<section id="hr-notification-settings-section" aria-labelledby="hr-notifications-title">
	<h4 id="hr-notifications-heading" aria-labelledby="hr-notifications-title">
		<span id="hr-notifications-title"><?php p($l->t('Notifications')); ?></span>
		<button type="button" class="ts-rules-help-toggle" id="timesheet-rules-help-toggle"
			aria-expanded="false" aria-controls="timesheet-rules-help"
			aria-label="<?php p($l->t('Notification settings explained')); ?>">
			<?php print_unescaped($helpIcon); ?>
		</button>
	</h4>
	<?php include __DIR__ . '/hr-mail-help.php'; ?>

	<fieldset id="hr-notifications-section" class="hr-personal-notifications" disabled>
		<legend><?php p($l->t('Alerts for HR')); ?></legend>
		<p class="hr-notification-intro"><?php p($l->t('Each HR account sets its own thresholds for alerts about the employees it is responsible for. The same threshold applies to each alert in the employee list and the weekly email to HR.')); ?></p>
		<div class="hr-notification-row hr-notification-header" aria-hidden="true">
			<span><?php p($l->t('Alert')); ?></span>
			<span><?php p($l->t('Alert threshold')); ?></span>
			<span><?php p($l->t('Weekly email to HR')); ?></span>
		</div>
		<?php foreach ($notificationFields as $field): ?>
			<?php $key = $field['key']; $isDays = $key === 'noEntryDays'; ?>
			<div class="hr-notification-row">
				<div class="hr-notification-topic">
					<label for="hr-threshold-<?php p($key); ?>"><?php p($l->t($field['label'])); ?></label>
					<details class="hr-field-help">
						<summary aria-label="<?php p($l->t($field['label'])); ?>"><?php print_unescaped($helpIcon); ?></summary>
						<p><?php p($l->t($field['help'])); ?></p>
					</details>
				</div>
				<div class="hr-notification-value">
					<input id="hr-threshold-<?php p($key); ?>" data-warning-threshold="<?php p($key); ?>" required
						<?php if ($isDays): ?>type="number" min="1" max="365" step="1"
						<?php else: ?>type="text" placeholder="HH:MM" pattern="[0-9]{1,8}:[0-5][0-9]" maxlength="11"<?php endif; ?>>
					<span><?php p($l->t($field['unit'])); ?></span>
				</div>
				<div class="hr-notification-mail">
					<label>
						<input type="checkbox" data-mail-enabled="<?php p($field['enabled']); ?>">
						<?php p($l->t('Include in my weekly email')); ?>
					</label>
					<?php if ($isDays): ?>
						<details class="hr-field-help">
							<summary aria-label="<?php p($l->t('Weekly email to HR')); ?>"><?php print_unescaped($helpIcon); ?></summary>
							<p><?php p($l->t('When checked, this alert is also included in the weekly summary email.')); ?></p>
						</details>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		<p id="hr-notification-input-error" class="hr-mail-settings-error" role="status" hidden>
			<?php p($l->t('Enter whole days or hours and minutes in the format HH:MM.')); ?>
		</p>
	</fieldset>
	<p id="hr-notification-load-error" class="hr-mail-settings-error" role="alert" hidden>
		<?php p($l->t('Notification settings could not be loaded. Reload the page to try again.')); ?>
	</p>

	<section class="hr-group-notifications" aria-labelledby="hr-group-notifications-heading">
		<h5 id="hr-group-notifications-heading"><?php p($l->t('Notifications by group')); ?></h5>
		<?php if (is_array($_['notificationRuleSettings'] ?? null)): ?>
			<?php
			$ruleEditorData = $_['notificationRuleSettings'];
			include __DIR__ . '/hr-rule-editor.php';
			?>
		<?php else: ?>
			<p class="hr-mail-settings-error" role="alert"><?php p($l->t('Notification settings could not be loaded. Reload the page to try again.')); ?></p>
		<?php endif; ?>
	</section>
</section>
