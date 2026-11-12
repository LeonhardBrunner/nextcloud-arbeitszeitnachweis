<div id="timesheet-rules-help" class="ts-rules-help" role="region" aria-label="<?php p($l->t('Notification settings explained')); ?>" hidden>
	<p class="ts-rules-help-heading" role="heading" aria-level="5"><strong><?php p($l->t('Days since the last complete entry')); ?></strong></p>
	<p><?php p($l->t('This alert highlights long gaps in time tracking. At a threshold of 14 days, HR can see who has gone at least 14 days without a complete time entry.')); ?></p>

	<p class="ts-rules-help-heading" role="heading" aria-level="5"><strong><?php p($l->t('Reminder for missing or incomplete time entries')); ?></strong></p>
	<p><?php p($l->t('The reminder shows which workdays still need an entry or a correction. The group rule lets you change which HR account receives the notification email.')); ?></p>
	<p><?php p($l->t('The new rule works as in this example. Employee A records no working time for Tuesday. On Wednesday, employee B only records that he started at 8 a.m. With a two-calendar-day waiting period, employee A receives an email notification on Thursday and employee B on Friday.')); ?></p>

	<p class="ts-rules-help-heading" role="heading" aria-level="5"><strong><?php p($l->t('Employee list and weekly email to HR')); ?></strong></p>
	<p><?php p($l->t('The employee list shows the current alerts in the app. The weekly email to HR bundles the selected alerts that still apply on Monday.')); ?></p>
	<p><?php p($l->t('Employees can also get an email about their own time balance on Mondays if the group rule enables it and their balance exceeds the threshold.')); ?></p>
</div>
