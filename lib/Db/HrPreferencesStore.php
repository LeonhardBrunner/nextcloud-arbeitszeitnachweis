<?php

namespace OCA\Timesheet\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HrPreferencesStore {
	private const DEFAULT_WORK_MINUTES = 480;

	private const SETTINGS = [
		'noEntryDays' => [
			'default' => 14,
			'warningColumn' => 'warn_no_entry_days',
			'enabled' => 'noEntryEnabled',
			'enabledColumn' => 'mail_no_entry_enabled',
		],
		'overtimeThresholdMinutes' => [
			'default' => 600,
			'warningColumn' => 'warn_overtime_threshold_min',
			'enabled' => 'overtimeEnabled',
			'enabledColumn' => 'mail_overtime_enabled',
		],
		'negativeOvertimeThresholdMinutes' => [
			'default' => 600,
			'warningColumn' => 'warn_negative_threshold_min',
			'enabled' => 'negativeOvertimeEnabled',
			'enabledColumn' => 'mail_negative_enabled',
		],
	];

	public function __construct(private IDBConnection $db) {
	}

	/** Flat threshold keys remain available to clients using the previous response shape. */
	public function getNotificationSettings(string $userId): array {
		return $this->settingsFromRow($this->readRow($userId));
	}

	public function saveNotificationSettings(string $userId, array $payload): array {
		$warnings = is_array($payload['warningThresholds'] ?? null) ? $payload['warningThresholds'] : [];
		$columns = [];

		// Only the nested thresholds edit the warning limits. Older clients sent
		// email-only thresholds at the top level and can still change delivery.
		foreach (self::SETTINGS as $key => $setting) {
			if (isset($warnings[$key])) {
				$columns[$setting['warningColumn']] = $this->normalizeThreshold($key, $warnings[$key]);
			}
			if (isset($payload[$setting['enabled']])) {
				$columns[$setting['enabledColumn']] = (int)filter_var($payload[$setting['enabled']], FILTER_VALIDATE_BOOL);
			}
		}

		if ($columns !== []) {
			$this->writeColumns($userId, $columns);
		}
		return $this->getNotificationSettings($userId);
	}

	public function getWarningThresholds(string $userId): array {
		return $this->getNotificationSettings($userId)['warningThresholds'];
	}

	public function saveWarningThresholds(string $userId, array $payload): array {
		$columns = [];
		foreach (self::SETTINGS as $key => $setting) {
			$columns[$setting['warningColumn']] = $this->normalizeThreshold($key, $payload[$key] ?? $setting['default']);
		}
		$this->writeColumns($userId, $columns);
		return $this->getWarningThresholds($userId);
	}

	private function readRow(string $userId): array {
		$queryBuilder = $this->db->getQueryBuilder();
		$row = $queryBuilder->select('*')
			->from('ts_user_config')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->executeQuery()
			->fetch();
		return $row ?: [];
	}

	private function settingsFromRow(array $row): array {
		$settings = ['warningThresholds' => []];
		foreach (self::SETTINGS as $key => $setting) {
			$warningThreshold = $this->normalizeThreshold($key, $row[$setting['warningColumn']] ?? $setting['default']);
			$settings['warningThresholds'][$key] = $warningThreshold;
			$settings[$setting['enabled']] = ((int)($row[$setting['enabledColumn']] ?? 0)) === 1;
			$settings[$key] = $warningThreshold;
		}
		return $settings;
	}

	private function normalizeThreshold(string $key, mixed $value): int {
		return $key === 'noEntryDays' ? max(1, min(365, (int)$value)) : max(0, (int)$value);
	}

	private function writeColumns(string $userId, array $columns): void {
		if ($this->updateColumns($userId, $columns) > 0) {
			return;
		}

		$existing = $this->readRow($userId);
		if ($existing !== []) {
			if (array_diff_assoc($columns, $existing) !== []) {
				$this->updateColumns($userId, $columns);
			}
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$values = [
			'user_id' => $insert->createNamedParameter($userId),
			'work_minutes' => $insert->createNamedParameter(self::DEFAULT_WORK_MINUTES, IQueryBuilder::PARAM_INT),
		];
		foreach ($columns as $column => $value) {
			$values[$column] = $insert->createNamedParameter($value, IQueryBuilder::PARAM_INT);
		}
		try {
			$insert->insert('ts_user_config')->values($values)->executeStatement();
		} catch (Exception $error) {
			if ($error->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $error;
			}
			// A concurrent settings write can create the row between the update and insert.
			$this->updateColumns($userId, $columns);
		}
	}

	private function updateColumns(string $userId, array $columns): int {
		$update = $this->db->getQueryBuilder();
		$update->update('ts_user_config')
			->where($update->expr()->eq('user_id', $update->createNamedParameter($userId)));
		foreach ($columns as $column => $value) {
			$update->set($column, $update->createNamedParameter($value, IQueryBuilder::PARAM_INT));
		}
		return $update->executeStatement();
	}
}
