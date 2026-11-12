<?php
$usesHoursFormat = ($_['breakInputMode'] ?? 'minutes') === 'hours';
$usesHistoryTable = ($_['historyView'] ?? 'cards') === 'table';
$absenceCommentTerms = $_['absenceCommentTerms'];
$employmentStatus = $_['employmentStatus'] ?? [];
$tourEnabled = $_['tourEnabled'] ?? true;
$mailDraft = static fn(string $recipient, string $subject, string $body): string =>
	'mailto:' . $recipient . '?' . http_build_query(['subject' => $subject, 'body' => $body], '', '&', PHP_QUERY_RFC3986);
if ($tourEnabled) {
	$contactBody = implode("\r\n", [
		$l->t('Hello Timesheet team,'),
		'',
		'',
		$l->t('Best regards,'),
		$l->t('Your name'),
	]);
	$questionDraft = $mailDraft('karriere@xiller.com', $l->t('Question about the Timesheet app'), $contactBody);
	$feedbackDraft = $mailDraft('karriere@xiller.com', $l->t('Feedback on the Timesheet app'), $contactBody);
}
?>
<section class="card" id="timesheet-app"
	data-update-notes-enabled="<?php p(($_['updateNotesEnabled'] ?? false) ? 'true' : 'false'); ?>"
	data-break-input-mode="<?php p($usesHoursFormat ? 'hours' : 'minutes'); ?>"
	data-break-input-revision="<?php p($_['breakInputRevision'] ?? ''); ?>"
	data-employment-status-available="<?php p(($_['employmentStatusAvailable'] ?? false) ? 'true' : 'false'); ?>"
	data-employment-is-active="<?php p(($employmentStatus['isActive'] ?? false) ? 'true' : 'false'); ?>"
	data-freelancer-mode="<?php p(($employmentStatus['freelancerMode'] ?? false) ? 'true' : 'false'); ?>"
	data-employment-status-revision="<?php p($employmentStatus['statusRevision'] ?? ''); ?>"
	data-absence-vacation="<?php p($absenceCommentTerms['vacation']); ?>"
	data-absence-time-off-in-lieu="<?php p($absenceCommentTerms['timeOffInLieu']); ?>"
	data-tour-enabled="<?php p($tourEnabled ? 'true' : 'false'); ?>"
	<?php if ($tourEnabled): ?>
		data-tour-question-draft="<?php p($questionDraft); ?>"
		data-tour-feedback-draft="<?php p($feedbackDraft); ?>"
	<?php endif; ?>
	data-tour-version="<?php p($_['tourProgress']['version'] ?? 1); ?>"
	data-tour-employee-completed="<?php p(($_['tourProgress']['employeeCompleted'] ?? false) ? 'true' : 'false'); ?>"
	data-tour-state-available="<?php p(($_['tourStateAvailable'] ?? false) ? 'true' : 'false'); ?>">
	<!-- Tabs -->
	<div class="ts-tabs">
		<?php if ($_['isHR']): ?>
			<button class="ts-tab active" data-tab="mine"><?php p($l->t('Timesheet')) ?></button>
			<button class="ts-tab" data-tab="hr"><?php p($l->t('HR overview')) ?></button>
			<button class="ts-tab" data-tab="change-history"><?php p($l->t('Change history')) ?></button>
		<?php endif; ?>
	</div>

	<!-- Personal timesheet -->
	<div id="tab-mine" class="ts-tabview active">
		<div class="ts-month-row-main">
			<div class="ts-month-row">
				<h3><?php p($l->t('My entries')) ?></h3>

				<button type="button" class="month-nav" id="month-prev"><</button>
				<button type="button" id="month-display" class="month-display month-display-btn">Month Year</button>
				<button type="button" class="month-nav" id="month-next">></button>

				<button type="button" id="export-mine-xlsx" class="primary"><?php p($l->t('Export')) ?></button>

				<div class="ts-stats">
					<div><strong><?php p($l->t('Worked (month):')) ?></strong> <span id="worked-hours-month">--:--</span></div>
					<div><strong><?php p($l->t('Overtime (month):')) ?></strong> <span id="overtime-month">--:--</span></div>
					<div><strong><?php p($l->t('Total overtime:')) ?></strong> <span id="overtime-total">--:--</span></div>
				</div>
			</div>

			<!-- Working time and state settings -->
			<div class="hr-config-row">
				<label for="config-daily-min-mine"><?php p($l->t('Daily working time:')) ?></label>
				<input type="time" id="config-daily-min-mine" class="config-daily-min" value="08:00" />

				<label for="config-state-mine"><?php p($l->t('State:')) ?></label>
				<select id="config-state-mine" class="config-state">
					<option value=null></option>
					<option value="BW">Baden-Württemberg</option>
					<option value="BY" selected>Bayern</option>
					<option value="BE">Berlin</option>
					<option value="BB">Brandenburg</option>
					<option value="HB">Bremen</option>
					<option value="HH">Hamburg</option>
					<option value="HE">Hessen</option>
					<option value="MV">Mecklenburg-Vorpommern</option>
					<option value="NI">Niedersachsen</option>
					<option value="NW">Nordrhein-Westfalen</option>
					<option value="RP">Rheinland-Pfalz</option>
					<option value="SL">Saarland</option>
					<option value="SN">Sachsen</option>
					<option value="ST">Sachsen-Anhalt</option>
					<option value="SH">Schleswig-Holstein</option>
					<option value="TH">Thüringen</option>
				</select>

				<button id="save-config-btn-mine" class="save-config-btn"><?php p($l->t('Save')) ?></button>
			</div>
		</div>

		<!-- Personal entries -->
		<table id="ts-table">
			<thead>
				<tr>
					<th colspan="2"><?php p($l->t('Date')) ?></th>
					<th><?php p($l->t('Status')) ?></th>
					<th><?php p($l->t('Start')) ?></th>
					<th><?php p($l->t('Break')) ?></th>
					<th><?php p($l->t('End')) ?></th>
					<th><?php p($l->t('Duration')) ?></th>
					<th><?php p($l->t('Difference')) ?></th>
					<th><?php p($l->t('Comment')) ?></th>
					<th><?php p($l->t('Warning')) ?></th>
				</tr>
			</thead>

			<tbody id="ts-body">
				<!-- Rows are rendered by JavaScript. -->
			</tbody>
		</table>

		<!-- Break input format -->
		<div class="ts-break-toggle" id="ts-break-toggle" role="group" aria-label="<?php p($l->t('Break input format')) ?>">
			<span class="ts-break-toggle-label"><?php p($l->t('Break')) ?>:</span>
			<span class="ts-break-mode-option<?php p($usesHoursFormat ? '' : ' is-active'); ?>" data-break-mode-label="minutes"><?php p($l->t('Minutes')) ?></span>
			<label class="ts-break-switch">
				<input
					type="checkbox"
					class="ts-break-toggle-switch"
					role="switch"
					data-break-mode-off="minutes"
					data-break-mode-on="hours"
					aria-label="<?php p($l->t('Break input format')) ?>"
					aria-checked="<?php p($usesHoursFormat ? 'true' : 'false'); ?>"
					<?php if ($usesHoursFormat) { print_unescaped('checked'); } ?>
				>
				<span class="ts-break-switch-slider" aria-hidden="true"></span>
			</label>
			<span class="ts-break-mode-option<?php p($usesHoursFormat ? ' is-active' : ''); ?>" data-break-mode-label="hours"><?php p($l->t('Hours')) ?></span>
		</div>
		<?php if ($_['canSetFreelancerMode'] ?? false): ?>
			<!-- Personal employment classification uses its own account preference. -->
			<div class="ts-employment-toggle" role="group" aria-labelledby="freelancer-mode-label">
				<span id="freelancer-mode-label" class="ts-break-toggle-label"><?php p($l->t('Freelancer mode')); ?>:</span>
				<span><?php p($l->t('Off')); ?></span>
				<label class="ts-break-switch">
					<input type="checkbox" id="freelancer-mode-switch" class="ts-freelancer-toggle-switch" role="switch" aria-labelledby="freelancer-mode-label" aria-describedby="freelancer-mode-feedback" aria-checked="false" disabled>
					<span class="ts-break-switch-slider" aria-hidden="true"></span>
				</label>
				<span><?php p($l->t('On')); ?></span>
			</div>
			<p id="freelancer-mode-feedback" class="ts-employment-feedback" role="status"></p>
		<?php endif; ?>
	</div>

	<!-- HR overview -->
	<?php if ($_['isHR']): ?>
		<div id="tab-hr" class="ts-tabview">
			<div class="ts-hr-row">
				<!-- HR guidance -->
				<div id="hr-infobox-section" class="ts-hr-section ts-infobox">
					<p class="ts-infobox-text">
						<?php p($l->t('Time rules are managed under Profile → Settings → Timesheet. Email settings are available below under Notifications. Administrators manage HR and employee assignments.')) ?>
					</p>
					<p class="ts-infobox-text">
						<?php p($l->t('For days without start and end times, only the exact comments "%1$s" and "%2$s" prevent a reminder. Letter case and surrounding spaces are ignored. Entries with only one time remain incomplete.', [$absenceCommentTerms['vacation'], $absenceCommentTerms['timeOffInLieu']])) ?>
					</p>
				</div>

				<!-- Employee list -->
				<div id="hr-userlist-section" class="ts-hr-section">
					<div class="hr-employee-list-header">
						<h4><?php p($l->t('Employee list')) ?></h4>
						<button type="button" id="hr-employee-filter-button" aria-haspopup="dialog" aria-expanded="false" aria-controls="hr-employee-filter-popup"><?php p($l->t('Filter')); ?></button>
					</div>
					<div id="hr-employee-filter-popup" class="hr-employee-filter-popup" role="dialog" aria-labelledby="hr-employee-filter-heading" hidden>
						<div class="hr-employee-filter-heading">
							<h5 id="hr-employee-filter-heading"><?php p($l->t('Filter employees')); ?></h5>
							<button type="button" id="hr-employee-filter-close" aria-label="<?php p($l->t('Close employee filters')); ?>">&#215;</button>
						</div>
						<label for="hr-employee-search"><?php p($l->t('Search by name')); ?></label>
						<input type="search" id="hr-employee-search" autocomplete="off">
						<label for="hr-employee-filter-mode"><?php p($l->t('Show employees')); ?></label>
						<select id="hr-employee-filter-mode">
							<option value="active"><?php p($l->t('Active employees')); ?></option>
							<option value="inactive"><?php p($l->t('Inactive employees')); ?></option>
							<option value="all"><?php p($l->t('All employees')); ?></option>
							<option value="freelancers"><?php p($l->t('Freelancers')); ?></option>
						</select>
						<button type="button" id="hr-employee-filter-clear"><?php p($l->t('Clear filters')); ?></button>
					</div>
					<p id="hr-employee-count" class="hr-employee-count" role="status"></p>
					<p id="hr-employee-list-message" class="hr-employee-list-message" role="status"></p>
					<button type="button" id="hr-employee-list-retry" hidden><?php p($l->t('Retry')); ?></button>
					<div class="hr-employee-table-scroll">
						<table class="grid hr-userlist">
							<thead>
								<tr>
									<th><?php p($l->t('Name')) ?></th>
									<th><?php p($l->t('Daily target')) ?></th>
									<th><?php p($l->t('Balance')) ?></th>
									<th><?php p($l->t('Last entry')) ?></th>
									<th><?php p($l->t('Days since last entry')) ?></th>
									<th><?php p($l->t('Warnings')) ?></th>
								</tr>
							</thead>
							<tbody id="hr-userlist">
								<!-- Rows are rendered by JavaScript. -->
							</tbody>
						</table>
					</div>
				</div>

				<!-- HR statistics -->
				<div id="hr-stats-section" class="ts-hr-section">
					<h4><?php p($l->t('Statistics')) ?></h4>
					<table class="grid hr-stats-table">
						<tbody>
							<tr>
								<th><?php p($l->t('Total hours of employees (this Month)')); ?></th>
								<td id="hr-stat-total-hours">-</td>
							</tr>
							<tr>
								<th><?php p($l->t('Employees with overtime')); ?></th>
								<td id="hr-stat-employees-overtime">-</td>
							</tr>
							<tr>
								<th><?php p($l->t('Employees with negative overtime')); ?></th>
								<td id="hr-stat-employees-negative">-</td>
							</tr>
							<tr>
								<th><?php p($l->t('Total overtime')); ?></th>
								<td id="hr-stat-total-overtime">-</td>
							</tr>
							<tr>
								<th><?php p($l->t('Total negative overtime')); ?></th>
								<td id="hr-stat-total-negative">-</td>
							</tr>
							<tr>
								<th><?php p($l->t('Sum of +/- overtimes')); ?></th>
								<td id="hr-stat-sum-overtimes">-</td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php
				$appRequestHref = $mailDraft(
					'info@xiller.com',
					$l->t('Change request for the Timesheet app'),
					implode("\r\n\r\n", [
						$l->t('Dear Timesheet team,'),
						$l->t('I would like to suggest a change.'),
						$l->t('Kind regards,'),
					]),
				);
				?>
				<div id="hr-notification-block" class="ts-hr-section">
					<a class="button hr-app-request" href="<?php p($appRequestHref); ?>">
						<?php p($l->t('Do you have any requests for the app?')); ?>
					</a>
					<?php include __DIR__ . '/partials/hr-notifications.php'; ?>
				</div>
			</div>

			<!-- Selected employee timesheet -->
			<div id="hr-user-entries" class="ts-hr-section" style="display: none;">

				<!-- Employee heading and actions -->
				<div class="hr-user-header-bar">
					<button id="hr-back-button" class="hr-back-button"><?php p($l->t('Back')) ?></button>
					<h4 id="hr-user-title"><?php p($l->t('Entries for:')) ?> <span></span></h4>
					<button type="button" id="export-hr-xlsx" class="primary"><?php p($l->t('Export')) ?></button>
				</div>

				<!-- Month controls, statistics and settings -->
				<div class="hr-employee-status-controls" role="group" aria-labelledby="hr-employment-status-label">
					<span id="hr-employment-status-label"><?php p($l->t('Employment status')); ?>:</span>
					<span id="hr-employment-status" aria-describedby="hr-employment-status-feedback">-</span>
					<span id="hr-employee-freelancer" class="ts-freelancer-badge" hidden><?php p($l->t('Freelancer')); ?></span>
					<span id="hr-employment-status-feedback" class="ts-employment-feedback" role="status"></span>
				</div>
				<div class="hr-user-controls-row">
					<div class="ts-month-row">
						<button type="button" class="month-nav" id="hr-month-prev"><</button>
						<button type="button" id="hr-month-display" class="month-display month-display-btn">Month Year</button>
						<button type="button" class="month-nav" id="hr-month-next">></button>

						<div class="ts-stats">
							<div><strong><?php p($l->t('Worked (month):')) ?></strong> <span id="worked-hours-month">--:--</span></div>
							<div><strong><?php p($l->t('Overtime (month):')) ?></strong> <span id="overtime-month">--:--</span></div>
							<div><strong><?php p($l->t('Total overtime:')) ?></strong> <span id="overtime-total">--:--</span></div>
						</div>
					</div>

					<!-- Working time and state settings -->
					<div class="hr-config-row">
						<label for="config-daily-min-hr"><?php p($l->t('Daily working time:')) ?></label>
						<input type="time" id="config-daily-min-hr" class="config-daily-min" value="08:00" />

						<label for="config-state-hr"><?php p($l->t('State:')) ?></label>
						<select id="config-state-hr" class="config-state">
							<option value=null></option>
							<option value="BW">Baden-Württemberg</option>
							<option value="BY" selected>Bayern</option>
							<option value="BE">Berlin</option>
							<option value="BB">Brandenburg</option>
							<option value="HB">Bremen</option>
							<option value="HH">Hamburg</option>
							<option value="HE">Hessen</option>
							<option value="MV">Mecklenburg-Vorpommern</option>
							<option value="NI">Niedersachsen</option>
							<option value="NW">Nordrhein-Westfalen</option>
							<option value="RP">Rheinland-Pfalz</option>
							<option value="SL">Saarland</option>
							<option value="SN">Sachsen</option>
							<option value="ST">Sachsen-Anhalt</option>
							<option value="SH">Schleswig-Holstein</option>
							<option value="TH">Thüringen</option>
						</select>

						<button id="save-config-btn-hr" class="save-config-btn"><?php p($l->t('Save')) ?></button>
					</div>
				</div>

				<!-- Employee entries -->
				<table id="hr-user-table" class="grid hr-table">
					<thead>
						<tr>
							<th colspan="2"><?php p($l->t('Date')) ?></th>
							<th><?php p($l->t('Status')) ?></th>
							<th><?php p($l->t('Start')) ?></th>
							<th><?php p($l->t('Break')) ?></th>
							<th><?php p($l->t('End')) ?></th>
							<th><?php p($l->t('Duration')) ?></th>
							<th><?php p($l->t('Difference')) ?></th>
							<th><?php p($l->t('Comment')) ?></th>
							<th><?php p($l->t('Warning')) ?></th>
						</tr>
					</thead>
					<tbody id="hr-user-body">
						<!-- Rows are rendered by JavaScript. -->
					</tbody>
				</table>

				<!-- Break input format -->
				<div class="ts-break-toggle" id="ts-break-toggle-hr" role="group" aria-label="<?php p($l->t('Break input format')) ?>">
					<span class="ts-break-toggle-label"><?php p($l->t('Break')) ?>:</span>
					<span class="ts-break-mode-option<?php p($usesHoursFormat ? '' : ' is-active'); ?>" data-break-mode-label="minutes"><?php p($l->t('Minutes')) ?></span>
					<label class="ts-break-switch">
						<input
							type="checkbox"
							class="ts-break-toggle-switch"
							role="switch"
							data-break-mode-off="minutes"
							data-break-mode-on="hours"
							aria-label="<?php p($l->t('Break input format')) ?>"
							aria-checked="<?php p($usesHoursFormat ? 'true' : 'false'); ?>"
							<?php if ($usesHoursFormat) { print_unescaped('checked'); } ?>
						>
						<span class="ts-break-switch-slider" aria-hidden="true"></span>
					</label>
					<span class="ts-break-mode-option<?php p($usesHoursFormat ? ' is-active' : ''); ?>" data-break-mode-label="hours"><?php p($l->t('Hours')) ?></span>
				</div>
			</div>
		</div>
		<!-- HR changes are read-only and limited to the current employee assignments. -->
		<div id="tab-change-history" class="ts-tabview" aria-labelledby="history-heading"
			data-history-view="<?php p($usesHistoryTable ? 'table' : 'cards'); ?>"
			data-history-view-revision="<?php p($_['historyViewRevision'] ?? ''); ?>"
			data-history-view-available="<?php p(($_['historyViewAvailable'] ?? false) ? '1' : '0'); ?>">
			<div class="ts-history-heading">
				<h3 id="history-heading"><?php p($l->t('Change history')); ?></h3>
				<div class="ts-history-toolbar">
					<button type="button" id="history-refresh"><?php p($l->t('Refresh')); ?></button>
					<button type="button" id="history-reset"><?php p($l->t('Clear filters')); ?></button>
				</div>
			</div>
			<p id="history-recording" class="ts-history-note"></p>
			<p class="ts-history-note"><?php p($l->t('Changes are kept for twelve months.')); ?></p>
			<form id="history-filters" class="ts-history-filters">
				<label for="history-from"><?php p($l->t('Changed from')); ?><input type="date" id="history-from" required></label>
				<label for="history-to"><?php p($l->t('Changed through')); ?><input type="date" id="history-to" required></label>
				<label for="history-employee"><?php p($l->t('Employee')); ?><select id="history-employee"><option value=""><?php p($l->t('All employees')); ?></option></select></label>
				<label for="history-actor"><?php p($l->t('Changed by')); ?><select id="history-actor"><option value=""><?php p($l->t('All editors')); ?></option></select></label>
				<label for="history-kind"><?php p($l->t('Change type')); ?><select id="history-kind">
					<option value=""><?php p($l->t('All changes')); ?></option>
					<option value="created"><?php p($l->t('Time entry created')); ?></option>
					<option value="updated"><?php p($l->t('Time entry updated')); ?></option>
					<option value="comment"><?php p($l->t('Comment changed')); ?></option>
					<option value="deleted"><?php p($l->t('Time entry deleted')); ?></option>
					<option value="settings"><?php p($l->t('Work settings updated')); ?></option>
				</select></label>
				<div class="ts-history-filter-actions">
					<button type="submit" id="history-apply"><?php p($l->t('Apply filters')); ?></button>
				</div>
			</form>
			<p id="history-message" class="ts-history-message" role="status" aria-live="polite"></p>
			<div class="ts-history-view-switch" role="group" aria-label="<?php p($l->t('History view')); ?>">
				<button type="button" id="history-view-cards" aria-pressed="<?php p($usesHistoryTable ? 'false' : 'true'); ?>"><?php p($l->t('Tile view')); ?></button>
				<button type="button" id="history-view-table" aria-pressed="<?php p($usesHistoryTable ? 'true' : 'false'); ?>"><?php p($l->t('Table view')); ?></button>
			</div>
			<ol id="history-list" class="ts-history-list"<?php if ($usesHistoryTable): ?> hidden<?php endif; ?>></ol>
			<div id="history-table-container" class="ts-history-table-container" role="region" aria-label="<?php p($l->t('Table view')); ?>" tabindex="0"<?php if (!$usesHistoryTable): ?> hidden<?php endif; ?>>
				<table class="ts-history-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Changed at')); ?></th>
						<th scope="col"><?php p($l->t('Changed by')); ?></th>
						<th scope="col"><?php p($l->t('Employee')); ?></th>
						<th scope="col"><?php p($l->t('Work date')); ?></th>
						<th scope="col"><?php p($l->t('Change type')); ?></th>
						<th scope="col"><?php p($l->t('Details')); ?></th>
					</tr></thead>
					<tbody id="history-table-body"></tbody>
				</table>
			</div>
			<button type="button" id="history-more" hidden><?php p($l->t('Load more')); ?></button>
		</div>
	<?php endif; ?>
</section>
