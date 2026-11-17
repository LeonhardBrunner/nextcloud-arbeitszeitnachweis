<?php
$notificationFields = [
	['mailSection' => 'noEntry', 'key' => 'noEntryDays', 'enabled' => 'noEntryEnabled', 'label' => 'Days since the last complete entry', 'unit' => 'calendar days', 'help' => 'This alert shows when an employee last made an entry in the timesheet. The threshold in calendar days determines when HR is notified.'],
	['mailSection' => 'overtime', 'key' => 'overtimeThresholdMinutes', 'enabled' => 'overtimeEnabled', 'label' => 'Overtime', 'unit' => 'hours', 'help' => 'With a threshold of 10 hours, HR can see in the employee list who has 10 or more hours of overtime.'],
	['mailSection' => 'negative', 'key' => 'negativeOvertimeThresholdMinutes', 'enabled' => 'negativeOvertimeEnabled', 'label' => 'Negative hours', 'unit' => 'hours', 'help' => 'With a threshold of 10 hours, HR can see in the employee list who has a time deficit of 10 hours or more.'],
];
$weeklyMailEditor = $_['weeklyMailEditor'] ?? null;
$embeddedJsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<section id="hr-notification-settings-section" aria-labelledby="hr-notifications-title">
	<h4 id="hr-notifications-heading"><span id="hr-notifications-title"><?php p($l->t('Notifications')); ?></span></h4>

	<fieldset id="hr-notifications-section" class="hr-personal-notifications" disabled>
		<legend><?php p($l->t('Alerts for HR')); ?></legend>
		<div class="hr-notifications-grid">
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
						<label id="hr-notification-topic-<?php p($key); ?>" for="hr-threshold-<?php p($key); ?>"><?php p($l->t($field['label'])); ?></label>
						<?php $renderHelpPopup('hr-threshold-help-' . $key, $l->t($field['label']), [$l->t($field['help'])]); ?>
					</div>
					<div class="hr-notification-value">
						<input id="hr-threshold-<?php p($key); ?>" data-warning-threshold="<?php p($key); ?>" required
							<?php if ($isDays): ?>type="number" min="1" max="365" step="1"
							<?php else: ?>type="text" placeholder="HH:MM" pattern="[0-9]{1,8}:[0-5][0-9]" maxlength="11"<?php endif; ?>>
						<span><?php p($l->t($field['unit'])); ?></span>
					</div>
					<div class="hr-notification-mail">
						<div class="ts-weekly-mail-toggle">
							<label>
								<input type="checkbox" data-mail-enabled="<?php p($field['enabled']); ?>">
								<?php p($l->t('Include in my weekly email')); ?>
							</label>
							<?php if ($isDays) {
								$renderHelpPopup('hr-weekly-email-help', $l->t('Include in my weekly email'), [
									$l->t('When checked, this alert is also included in the weekly summary email.'),
									$l->t('The employee list shows the current alerts in the app. The weekly email to HR bundles the selected alerts that still apply on Monday.'),
								]);
							} ?>
						</div>
						<?php if (is_array($weeklyMailEditor)): ?>
						<button type="button" data-edit-weekly-mail="<?php p($field['mailSection']); ?>"
							aria-haspopup="dialog" aria-controls="ts-reminder-mail-dialog" aria-describedby="hr-notification-topic-<?php p($key); ?>"><?php p($l->t('Edit email text')); ?></button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
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
	<?php if (is_array($weeklyMailEditor)): ?>
	<script id="ts-weekly-mail-editor-data" type="application/json">
		<?php print_unescaped(json_encode($weeklyMailEditor, $embeddedJsonFlags)); ?>
	</script>
	<?php endif; ?>

	<dialog id="ts-reminder-mail-dialog" aria-labelledby="ts-reminder-mail-title" aria-describedby="ts-reminder-mail-audience ts-reminder-mail-scope">
		<form method="dialog">
			<h2 id="ts-reminder-mail-title"><?php p($l->t('Edit email text')); ?></h2>
			<p id="ts-reminder-mail-audience"></p>
			<p id="ts-reminder-mail-scope"
				data-reminder="<?php p($l->t('This text applies to this group rule and to recipients with your account language.')); ?>"
				data-weekly="<?php p($l->t('This text applies to your weekly email and your current account language.')); ?>"></p>
			<label for="ts-reminder-mail-text"><?php p($l->t('Email text')); ?></label>
			<textarea id="ts-reminder-mail-text" rows="9" dir="auto" autofocus required
				data-invalid-placeholder-message="<?php p($l->t('Use {entries} exactly once.')); ?>" aria-describedby="ts-reminder-mail-placeholders"></textarea>
			<p id="ts-reminder-mail-placeholders"
				data-reminder="<?php p($l->t('Use {entries} once where the open days should appear. {recipient} inserts the name, and {appUrl} inserts the link to Timesheet.')); ?>"
				data-weekly="<?php p($l->t('Use {entries} once for the names and their figures. {threshold} inserts the current threshold.')); ?>"></p>
			<p><?php p($l->t('After applying, save your notification settings.')); ?></p>
			<div class="ts-reminder-mail-actions">
				<button id="ts-reminder-mail-reset" type="button"><?php p($l->t('Reset to original')); ?></button>
				<button id="ts-reminder-mail-cancel" type="button"><?php p(\OCP\Util::getL10N('core', $l->getLanguageCode())->t('Cancel')); ?></button>
				<button id="ts-reminder-mail-apply" type="submit" class="primary"><?php p($l->t('Apply')); ?></button>
			</div>
		</form>
	</dialog>

	<div class="hr-notification-actions">
		<p id="hr-notification-save-error" class="hr-mail-settings-error" role="alert"></p>
		<button id="hr-notification-save" type="button" class="primary" disabled><?php p(\OCP\Util::getL10N('settings', $l->getLanguageCode())->t('Save')); ?></button>
	</div>
	<p id="hr-notification-saved" class="hr-notification-saved" role="status" aria-atomic="true"></p>
</section>
