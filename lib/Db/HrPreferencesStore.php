<?php

namespace OCA\Timesheet\Db;

use OCP\IDBConnection;

class HrPreferencesStore {
	private const DEFAULT_WORK_MINUTES = 480;

	private const DEFAULT_NO_ENTRY_ENABLED = false;
	private const DEFAULT_NO_ENTRY_DAYS = 14;

	private const DEFAULT_OVERTIME_ENABLED = false;
	private const DEFAULT_OVERTIME_THRESHOLD = 600; // 10 hours

	private const DEFAULT_NEGATIVE_OVERTIME_ENABLED = false;
	private const DEFAULT_NEGATIVE_OVERTIME_THRESHOLD = 600; // 10 hours

	private const DEFAULT_WARN_NO_ENTRY_DAYS = self::DEFAULT_NO_ENTRY_DAYS;
	private const DEFAULT_WARN_OVERTIME_THRESHOLD = self::DEFAULT_OVERTIME_THRESHOLD;
	private const DEFAULT_WARN_NEGATIVE_THRESHOLD = self::DEFAULT_NEGATIVE_OVERTIME_THRESHOLD;

	public function __construct(private IDBConnection $db) {
	}

	public function getNotificationSettings(string $userId): array {
		$defaults = [
			'noEntryEnabled' => self::DEFAULT_NO_ENTRY_ENABLED,
			'noEntryDays' => self::DEFAULT_NO_ENTRY_DAYS,
			'overtimeEnabled' => self::DEFAULT_OVERTIME_ENABLED,
			'overtimeThresholdMinutes' => self::DEFAULT_OVERTIME_THRESHOLD,
			'negativeOvertimeEnabled' => self::DEFAULT_NEGATIVE_OVERTIME_ENABLED,
			'negativeOvertimeThresholdMinutes' => self::DEFAULT_NEGATIVE_OVERTIME_THRESHOLD,
		];

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select(
				'mail_no_entry_enabled',
				'mail_no_entry_days',
				'mail_overtime_enabled',
				'mail_overtime_threshold_min',
				'mail_negative_enabled',
				'mail_negative_threshold_min'
			)
			->from('ts_user_config')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)));

		$row = $queryBuilder->executeQuery()->fetch();
		if ($row === false) {
			return $defaults;
		}

		$settings = $defaults;

		if (array_key_exists('mail_no_entry_enabled', $row) && $row['mail_no_entry_enabled'] !== null) {
			$settings['noEntryEnabled'] = ((int)$row['mail_no_entry_enabled']) === 1;
		}
		if (array_key_exists('mail_no_entry_days', $row) && $row['mail_no_entry_days'] !== null) {
			$settings['noEntryDays'] = (int)$row['mail_no_entry_days'];
		}

		if (array_key_exists('mail_overtime_enabled', $row) && $row['mail_overtime_enabled'] !== null) {
			$settings['overtimeEnabled'] = ((int)$row['mail_overtime_enabled']) === 1;
		}
		if (array_key_exists('mail_overtime_threshold_min', $row) && $row['mail_overtime_threshold_min'] !== null) {
			$settings['overtimeThresholdMinutes'] = (int)$row['mail_overtime_threshold_min'];
		}

		if (array_key_exists('mail_negative_enabled', $row) && $row['mail_negative_enabled'] !== null) {
			$settings['negativeOvertimeEnabled'] = ((int)$row['mail_negative_enabled']) === 1;
		}
		if (array_key_exists('mail_negative_threshold_min', $row) && $row['mail_negative_threshold_min'] !== null) {
			$settings['negativeOvertimeThresholdMinutes'] = (int)$row['mail_negative_threshold_min'];
		}

		$settings['noEntryDays'] = max(1, min(365, (int)$settings['noEntryDays']));
		$settings['overtimeThresholdMinutes'] = max(0, (int)$settings['overtimeThresholdMinutes']);
		$settings['negativeOvertimeThresholdMinutes'] = max(0, (int)$settings['negativeOvertimeThresholdMinutes']);

		return $settings;
	}

	public function saveNotificationSettings(string $userId, array $payload): array {
		$noEntryEnabled = array_key_exists('noEntryEnabled', $payload)
			? (bool)$payload['noEntryEnabled']
			: self::DEFAULT_NO_ENTRY_ENABLED;

		$noEntryDays = array_key_exists('noEntryDays', $payload)
			? (int)$payload['noEntryDays']
			: self::DEFAULT_NO_ENTRY_DAYS;

		$overtimeEnabled = array_key_exists('overtimeEnabled', $payload)
			? (bool)$payload['overtimeEnabled']
			: self::DEFAULT_OVERTIME_ENABLED;

		$overtimeThresholdMinutes = array_key_exists('overtimeThresholdMinutes', $payload)
			? (int)$payload['overtimeThresholdMinutes']
			: self::DEFAULT_OVERTIME_THRESHOLD;

		$negativeOvertimeEnabled = array_key_exists('negativeOvertimeEnabled', $payload)
			? (bool)$payload['negativeOvertimeEnabled']
			: self::DEFAULT_NEGATIVE_OVERTIME_ENABLED;

		$negativeOvertimeThresholdMinutes = array_key_exists('negativeOvertimeThresholdMinutes', $payload)
			? (int)$payload['negativeOvertimeThresholdMinutes']
			: self::DEFAULT_NEGATIVE_OVERTIME_THRESHOLD;

		$noEntryDays = max(1, min(365, $noEntryDays));
		$overtimeThresholdMinutes = max(0, $overtimeThresholdMinutes);
		$negativeOvertimeThresholdMinutes = max(0, $negativeOvertimeThresholdMinutes);

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->update('ts_user_config')
			->set('mail_no_entry_enabled', $queryBuilder->createNamedParameter($noEntryEnabled ? 1 : 0))
			->set('mail_no_entry_days', $queryBuilder->createNamedParameter($noEntryDays))
			->set('mail_overtime_enabled', $queryBuilder->createNamedParameter($overtimeEnabled ? 1 : 0))
			->set('mail_overtime_threshold_min', $queryBuilder->createNamedParameter($overtimeThresholdMinutes))
			->set('mail_negative_enabled', $queryBuilder->createNamedParameter($negativeOvertimeEnabled ? 1 : 0))
			->set('mail_negative_threshold_min', $queryBuilder->createNamedParameter($negativeOvertimeThresholdMinutes))
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)));

		$affectedRows = $queryBuilder->executeStatement();

		if ($affectedRows === 0) {
			// Preserve the insert fallback when the update reports no affected rows.
			$insertQueryBuilder = $this->db->getQueryBuilder();
			$insertQueryBuilder->insert('ts_user_config')
				->values([
					'user_id' => $insertQueryBuilder->createNamedParameter($userId),
					'work_minutes' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_WORK_MINUTES),
					'state' => $insertQueryBuilder->createNamedParameter(null, \PDO::PARAM_NULL),

					'mail_no_entry_enabled' => $insertQueryBuilder->createNamedParameter($noEntryEnabled ? 1 : 0),
					'mail_no_entry_days' => $insertQueryBuilder->createNamedParameter($noEntryDays),
					'mail_overtime_enabled' => $insertQueryBuilder->createNamedParameter($overtimeEnabled ? 1 : 0),
					'mail_overtime_threshold_min' => $insertQueryBuilder->createNamedParameter($overtimeThresholdMinutes),
					'mail_negative_enabled' => $insertQueryBuilder->createNamedParameter($negativeOvertimeEnabled ? 1 : 0),
					'mail_negative_threshold_min' => $insertQueryBuilder->createNamedParameter($negativeOvertimeThresholdMinutes),
				]);
			$insertQueryBuilder->executeStatement();
		}

		return [
			'noEntryEnabled' => $noEntryEnabled,
			'noEntryDays' => $noEntryDays,
			'overtimeEnabled' => $overtimeEnabled,
			'overtimeThresholdMinutes' => $overtimeThresholdMinutes,
			'negativeOvertimeEnabled' => $negativeOvertimeEnabled,
			'negativeOvertimeThresholdMinutes' => $negativeOvertimeThresholdMinutes,
		];
	}

	public function getWarningThresholds(string $userId): array {
		$defaults = [
			'noEntryDays' => self::DEFAULT_WARN_NO_ENTRY_DAYS,
			'overtimeThresholdMinutes' => self::DEFAULT_WARN_OVERTIME_THRESHOLD,
			'negativeOvertimeThresholdMinutes' => self::DEFAULT_WARN_NEGATIVE_THRESHOLD,
		];

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->select(
				'warn_no_entry_days',
				'warn_overtime_threshold_min',
				'warn_negative_threshold_min'
			)
			->from('ts_user_config')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)));

		$row = $queryBuilder->executeQuery()->fetch();
		if ($row === false) {
			return $defaults;
		}

		$settings = $defaults;

		if (array_key_exists('warn_no_entry_days', $row) && $row['warn_no_entry_days'] !== null) {
			$settings['noEntryDays'] = (int)$row['warn_no_entry_days'];
		}

		if (array_key_exists('warn_overtime_threshold_min', $row) && $row['warn_overtime_threshold_min'] !== null) {
			$settings['overtimeThresholdMinutes'] = (int)$row['warn_overtime_threshold_min'];
		}

		if (array_key_exists('warn_negative_threshold_min', $row) && $row['warn_negative_threshold_min'] !== null) {
			$settings['negativeOvertimeThresholdMinutes'] = (int)$row['warn_negative_threshold_min'];
		}

		$settings['noEntryDays'] = max(1, min(365, (int)$settings['noEntryDays']));
		$settings['overtimeThresholdMinutes'] = max(0, (int)$settings['overtimeThresholdMinutes']);
		$settings['negativeOvertimeThresholdMinutes'] = max(0, (int)$settings['negativeOvertimeThresholdMinutes']);

		return $settings;
	}

	public function saveWarningThresholds(string $userId, array $payload): array {
		$noEntryDays = array_key_exists('noEntryDays', $payload)
			? (int)$payload['noEntryDays']
			: self::DEFAULT_WARN_NO_ENTRY_DAYS;

		$overtimeThresholdMinutes = array_key_exists('overtimeThresholdMinutes', $payload)
			? (int)$payload['overtimeThresholdMinutes']
			: self::DEFAULT_WARN_OVERTIME_THRESHOLD;

		$negativeOvertimeThresholdMinutes = array_key_exists('negativeOvertimeThresholdMinutes', $payload)
			? (int)$payload['negativeOvertimeThresholdMinutes']
			: self::DEFAULT_WARN_NEGATIVE_THRESHOLD;

		$noEntryDays = max(1, min(365, $noEntryDays));
		$overtimeThresholdMinutes = max(0, $overtimeThresholdMinutes);
		$negativeOvertimeThresholdMinutes = max(0, $negativeOvertimeThresholdMinutes);

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->update('ts_user_config')
			->set('warn_no_entry_days', $queryBuilder->createNamedParameter($noEntryDays))
			->set('warn_overtime_threshold_min', $queryBuilder->createNamedParameter($overtimeThresholdMinutes))
			->set('warn_negative_threshold_min', $queryBuilder->createNamedParameter($negativeOvertimeThresholdMinutes))
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)));

		$affectedRows = $queryBuilder->executeStatement();

		if ($affectedRows === 0) {
			$insertQueryBuilder = $this->db->getQueryBuilder();
			$insertQueryBuilder->insert('ts_user_config')
				->values([
					'user_id' => $insertQueryBuilder->createNamedParameter($userId),
					'work_minutes' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_WORK_MINUTES),
					'state' => $insertQueryBuilder->createNamedParameter(null, \PDO::PARAM_NULL),

					'mail_no_entry_enabled' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_NO_ENTRY_ENABLED ? 1 : 0),
					'mail_no_entry_days' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_NO_ENTRY_DAYS),
					'mail_overtime_enabled' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_OVERTIME_ENABLED ? 1 : 0),
					'mail_overtime_threshold_min' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_OVERTIME_THRESHOLD),
					'mail_negative_enabled' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_NEGATIVE_OVERTIME_ENABLED ? 1 : 0),
					'mail_negative_threshold_min' => $insertQueryBuilder->createNamedParameter(self::DEFAULT_NEGATIVE_OVERTIME_THRESHOLD),

					'warn_no_entry_days' => $insertQueryBuilder->createNamedParameter($noEntryDays),
					'warn_overtime_threshold_min' => $insertQueryBuilder->createNamedParameter($overtimeThresholdMinutes),
					'warn_negative_threshold_min' => $insertQueryBuilder->createNamedParameter($negativeOvertimeThresholdMinutes),
				]);
			$insertQueryBuilder->executeStatement();
		}

		return [
			'noEntryDays' => $noEntryDays,
			'overtimeThresholdMinutes' => $overtimeThresholdMinutes,
			'negativeOvertimeThresholdMinutes' => $negativeOvertimeThresholdMinutes,
		];
	}
}
