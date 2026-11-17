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
	data-tour-enabled="<?php p($tourEnabled ? 'true' : 'false'); ?>"
	<?php if ($tourEnabled): ?>
		data-tour-question-draft="<?php p($questionDraft); ?>"
		data-tour-feedback-draft="<?php p($feedbackDraft); ?>"
		data-tour-comments-description="<?php p($l->t('Use the Comment column to record your own notes about the workday. If both start and end times are missing, only the preset reasons in the Comment column prevent a notification. Otherwise, entries with no time or only one time are marked as incomplete.')); ?>"
	<?php endif; ?>
	data-tour-version="<?php p($_['tourProgress']['version'] ?? 1); ?>"
	data-tour-employee-completed="<?php p(($_['tourProgress']['employeeCompleted'] ?? false) ? 'true' : 'false'); ?>"
	data-tour-state-available="<?php p(($_['tourStateAvailable'] ?? false) ? 'true' : 'false'); ?>">
	<?php if ($_['isHR']): ?>
		<div class="ts-app-toolbar">
			<div class="ts-tabs">
				<button class="ts-tab active" data-tab="mine"><?php p($l->t('Timesheet')) ?></button>
				<button class="ts-tab" data-tab="hr"><?php p($l->t('HR overview')) ?></button>
				<button class="ts-tab" data-tab="change-history"><?php p($l->t('Change history')) ?></button>
			</div>
			<div id="hr-overview-width-control" class="hr-overview-width-control" hidden>
				<label for="hr-overview-width"><?php p($l->t('HR overview width')); ?></label>
				<div class="hr-overview-width-field">
					<input type="range" id="hr-overview-width" min="30" max="100" step="1" value="100" aria-controls="hr-overview-panels">
					<output id="hr-overview-width-value" for="hr-overview-width" aria-hidden="true">100%</output>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<!-- Personal timesheet -->
	<div id="tab-mine" class="ts-tabview active">
		<div class="ts-month-row-main">
			<div class="ts-month-row">
				<h3><?php p($l->t('My entries')) ?></h3>

				<div class="ts-month-navigation">
					<button type="button" class="month-nav" id="month-prev"><</button>
					<button type="button" id="month-display" class="month-display month-display-btn">Month Year</button>
					<button type="button" class="month-nav" id="month-next">></button>
				</div>

				<div class="ts-entry-export">
					<button type="button" id="export-mine-xlsx" class="primary"><?php p($l->t('Export')) ?></button>
				</div>

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
		<div class="ts-entry-table-scroll" role="region" aria-label="<?php p($l->t('My entries')); ?>" tabindex="0">
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
						<th class="ts-comment-heading"><?php p($l->t('Comment')) ?></th>
						<th class="ts-warning-heading"><?php p($l->t('Warning')) ?></th>
					</tr>
				</thead>

				<tbody id="ts-body">
					<!-- Rows are rendered by JavaScript. -->
				</tbody>
			</table>
		</div>

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
		<?php require __DIR__ . '/partials/hr-help-popup.php'; ?>
		<?php
		$employeeHelpParagraphs = [
			$l->t('Time rules are managed under Profile → Settings → Timesheet. Email settings are available below under Notifications. Administrators manage HR and employee assignments.'),
			$l->t('For days with neither a start nor an end time, only the preset reasons in the Comment column prevent a reminder. Custom comments do not prevent reminders. Entries with only one time remain incomplete.'),
		];
		?>
		<div id="tab-hr" class="ts-tabview">
			<div class="hr-overview-layout">
				<div id="hr-overview-panels" class="ts-hr-row">
					<section id="hr-userlist-section" class="ts-hr-section" aria-labelledby="hr-userlist-heading">
						<div class="hr-employee-list-header">
							<div class="hr-employee-list-title">
								<h4 id="hr-userlist-heading"><?php p($l->t('Employee list')); ?></h4>
								<?php $renderHelpPopup('hr-overview-help', $l->t('Employee list'), $employeeHelpParagraphs); ?>
							</div>
							<div class="hr-employee-list-actions">
								<button type="button" id="hr-stats-button" aria-haspopup="dialog" aria-expanded="false" aria-controls="hr-stats-section"><?php p($l->t('Statistics')); ?></button>
								<button type="button" id="hr-employee-filter-button" aria-haspopup="dialog" aria-expanded="false" aria-controls="hr-employee-filter-popup"><?php p($l->t('Filter')); ?></button>
							</div>
						</div>
						<div id="hr-employee-filter-popup" class="hr-list-popup" role="dialog" aria-labelledby="hr-employee-filter-heading" hidden>
							<div class="hr-list-popup-heading">
								<h5 id="hr-employee-filter-heading"><?php p($l->t('Filter employees')); ?></h5>
								<button type="button" id="hr-employee-filter-close" aria-label="<?php p($l->t('Close employee filters')); ?>">&#215;</button>
							</div>
							<label for="hr-employee-search"><?php p($l->t('Search by name')); ?></label>
							<input type="search" id="hr-employee-search" autocomplete="off">
							<label for="hr-employee-filter-mode"><?php p($l->t('Show employees')); ?></label>
							<select id="hr-employee-filter-mode">
								<option value="all"><?php p($l->t('All employees')); ?></option>
								<option value="active"><?php p($l->t('Active employees')); ?></option>
								<option value="inactive"><?php p($l->t('Inactive employees')); ?></option>
								<option value="freelancers"><?php p($l->t('Freelancers')); ?></option>
							</select>
							<button type="button" id="hr-employee-filter-clear"><?php p($l->t('Clear filters')); ?></button>
						</div>
						<section id="hr-stats-section" class="hr-list-popup" role="dialog" aria-labelledby="hr-stats-heading" hidden>
							<div class="hr-list-popup-heading">
								<h5 id="hr-stats-heading"><?php p($l->t('Statistics')); ?></h5>
								<button type="button" id="hr-stats-close" aria-label="<?php p($l->t('Close')); ?>">&#215;</button>
							</div>
							<dl class="hr-stats">
								<div>
									<dt><?php p($l->t('Total hours of employees (this Month)')); ?></dt>
									<dd id="hr-stat-total-hours">-</dd>
								</div>
								<div>
									<dt><?php p($l->t('Employees with overtime')); ?></dt>
									<dd id="hr-stat-employees-overtime">-</dd>
								</div>
								<div>
									<dt><?php p($l->t('Employees with negative overtime')); ?></dt>
									<dd id="hr-stat-employees-negative">-</dd>
								</div>
								<div>
									<dt><?php p($l->t('Total overtime')); ?></dt>
									<dd id="hr-stat-total-overtime">-</dd>
								</div>
								<div>
									<dt><?php p($l->t('Total negative overtime')); ?></dt>
									<dd id="hr-stat-total-negative">-</dd>
								</div>
								<div>
									<dt><?php p($l->t('Sum of +/- overtimes')); ?></dt>
									<dd id="hr-stat-sum-overtimes">-</dd>
								</div>
							</dl>
						</section>
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
					</section>

					<?php
					$appRequestHref = $mailDraft(
						'kontakt@nextcloud-agentur.de',
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

				<aside id="hr-overview-help-box" class="hr-employee-help-box ts-hr-section" aria-labelledby="hr-userlist-heading" hidden>
					<?php foreach ($employeeHelpParagraphs as $paragraph): ?>
						<p><?php p($paragraph); ?></p>
					<?php endforeach; ?>
				</aside>
			</div>

			<!-- Selected employee timesheet -->
			<div id="hr-user-entries" class="ts-hr-section" style="display: none;">

				<!-- Employee heading and actions -->
				<div class="hr-user-header-bar">
					<button id="hr-back-button" class="hr-back-button"><?php p($l->t('Back')) ?></button>
					<h4 id="hr-user-title"><?php p($l->t('Entries for:')) ?> <span></span></h4>
					<div class="ts-entry-export">
						<button type="button" id="export-hr-xlsx" class="primary"><?php p($l->t('Export')) ?></button>
					</div>
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
						<div class="ts-month-navigation">
							<button type="button" class="month-nav" id="hr-month-prev"><</button>
							<button type="button" id="hr-month-display" class="month-display month-display-btn">Month Year</button>
							<button type="button" class="month-nav" id="hr-month-next">></button>
						</div>

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
				<div class="ts-entry-table-scroll" role="region" aria-labelledby="hr-user-title" tabindex="0">
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
				</div>

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
			data-employee-editor-label="<?php p($l->t('Changed by the employee')); ?>"
			data-empty-history-message="<?php p($l->t('No changes have been recorded yet.')); ?>"
			data-invalid-date-range-message="<?php p($l->t('Select a valid date range.')); ?>"
			data-history-view="<?php p($usesHistoryTable ? 'table' : 'cards'); ?>"
			data-history-view-revision="<?php p($_['historyViewRevision'] ?? ''); ?>"
			data-history-view-available="<?php p(($_['historyViewAvailable'] ?? false) ? '1' : '0'); ?>">
			<div class="ts-history-heading">
				<h3 id="history-heading"><?php p($l->t('Change history')); ?></h3>
				<div class="ts-history-toolbar">
					<button type="button" id="history-refresh"><?php p($l->t('Refresh')); ?></button>
				</div>
			</div>
			<p id="history-recording" class="ts-history-note"></p>
			<p class="ts-history-note"><?php p($l->t('Changes are kept for twelve months.')); ?></p>
			<?php
			$renderHistoryAccountPicker = static function (string $filter, string $label, string $allLabel) use ($l): void {
				$id = 'history-' . $filter;
				?>
				<div class="ts-history-account-picker">
					<label id="<?php p($id . '-label'); ?>" for="<?php p($id); ?>"><?php p($label); ?></label>
					<button type="button" id="<?php p($id); ?>" class="ts-history-account-trigger" aria-haspopup="dialog" aria-expanded="false"
						aria-controls="<?php p($id . '-popup'); ?>" aria-labelledby="<?php p($id . '-label ' . $id . '-value'); ?>">
						<span id="<?php p($id . '-value'); ?>"><?php p($allLabel); ?></span>
					</button>
					<div id="<?php p($id . '-popup'); ?>" class="ts-history-account-popup" role="dialog" aria-labelledby="<?php p($id . '-label'); ?>" hidden>
						<label for="<?php p($id . '-search'); ?>"><?php p($l->t('Search by name or account ID')); ?></label>
						<input type="search" id="<?php p($id . '-search'); ?>" autocomplete="off" aria-controls="<?php p($id . '-options'); ?>">
						<p id="<?php p($id . '-search-status'); ?>" role="status" class="ts-history-note" hidden></p>
						<ul id="<?php p($id . '-options'); ?>" class="ts-history-account-options"></ul>
					</div>
				</div>
				<?php
			};
			?>
			<div id="history-filters" class="ts-history-filters">
				<label for="history-from"><?php p($l->t('Changed from')); ?><input type="date" id="history-from" required></label>
				<label for="history-to"><?php p($l->t('Changed through')); ?><input type="date" id="history-to" required></label>
				<?php $renderHistoryAccountPicker('employee', $l->t('Employee'), $l->t('All employees')); ?>
				<div class="ts-history-editor-filters">
					<?php $renderHistoryAccountPicker('actor', $l->t('Changed by'), $l->t('All editors')); ?>
					<label class="ts-history-employee-changes" for="history-employee-changes" title="<?php p($l->t('Show only changes employees made to their own time records.')); ?>">
						<input type="checkbox" id="history-employee-changes">
						<span><?php p($l->t('Employee changes')); ?></span>
					</label>
				</div>
				<label for="history-kind"><?php p($l->t('Change type')); ?><select id="history-kind">
					<option value=""><?php p($l->t('All changes')); ?></option>
					<option value="created"><?php p($l->t('Time entry created')); ?></option>
					<option value="updated"><?php p($l->t('Time entry updated')); ?></option>
					<option value="comment"><?php p($l->t('Comment changed')); ?></option>
					<option value="deleted"><?php p($l->t('Time entry deleted')); ?></option>
					<option value="settings"><?php p($l->t('Work settings updated')); ?></option>
				</select></label>
			</div>
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
