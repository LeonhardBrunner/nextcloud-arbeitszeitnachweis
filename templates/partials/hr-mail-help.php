	<!-- The help opens in the page flow so it cannot cover the rule controls. -->
	<div id="timesheet-rules-help" class="ts-rules-help" role="region" aria-label="<?php p($l->t('Group rules explained')); ?>" hidden>
		<ol>
			<li>
				<strong><?php p($l->t('Reminder for missing or incomplete time entries')); ?></strong>
				<p><?php p($l->t('Employees receive their own outstanding dates. HR receives a summary for assigned employees. Checks run daily after the configured waiting period. Further reminders are sent no sooner than seven days later.')); ?></p>
				<p><strong><?php p($l->t('Control:')); ?></strong> <?php p($l->t('HR overview → Notifications → Reminders and employee emails → Group rule → “Missing time entry reminders”. Employee and HR delivery can be enabled separately.')); ?></p>
			</li>
			<li>
				<strong><?php p($l->t('Overtime and negative-hours email to employees')); ?></strong>
				<p><?php p($l->t('Employees receive an email on Mondays if their own working time balance exceeds the configured threshold. The email directs questions to HR.')); ?></p>
				<p><strong><?php p($l->t('Control:')); ?></strong> <?php p($l->t('In the selected group rule under “Employee balance notifications”. Overtime and negative hours can be configured separately.')); ?></p>
			</li>
			<li>
				<strong><?php p($l->t('Weekly HR summary')); ?></strong>
				<p><?php p($l->t('HR receives a combined email on Mondays about excessive overtime, excessive negative hours or a long period without a new entry.')); ?></p>
				<p><strong><?php p($l->t('Control:')); ?></strong> <?php p($l->t('HR overview → Notifications → “My HR emails”. These settings apply only to the signed-in HR account.')); ?></p>
			</li>
		</ol>
		<p><?php p($l->t('The “Email recipients” selection in the group rule determines which HR accounts receive emails.')); ?></p>
		<p><?php p($l->t('Freelancers and inactive employees receive no warning emails.')); ?></p>
	</div>

