<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

/** Defines group-wide employee mail choices without changing personal HR preferences. */
class EmployeeBalanceNotificationSettings {
	public const MAX_THRESHOLD_MINUTES = 525600;
	private const DEFAULT_THRESHOLD_MINUTES = 600;
	private const ENABLED_FIELDS = ['overtimeEnabled', 'negativeOvertimeEnabled'];
	private const THRESHOLD_FIELDS = ['overtimeThresholdMinutes', 'negativeOvertimeThresholdMinutes'];

	/** @return array{overtimeEnabled:bool,overtimeThresholdMinutes:int,negativeOvertimeEnabled:bool,negativeOvertimeThresholdMinutes:int} */
	public static function normalize(mixed $settings): array {
		$settings = is_array($settings) ? $settings : [];
		$normalized = self::defaults();
		$valid = true;
		foreach (self::ENABLED_FIELDS as $field) {
			$value = array_key_exists($field, $settings) ? $settings[$field] : false;
			$valid = $valid && is_bool($value);
			$normalized[$field] = $value === true;
		}
		foreach (self::THRESHOLD_FIELDS as $field) {
			$value = array_key_exists($field, $settings) ? $settings[$field] : self::DEFAULT_THRESHOLD_MINUTES;
			$validThreshold = self::isValidThreshold($value);
			$valid = $valid && $validThreshold;
			$normalized[$field] = $validThreshold ? $value : self::DEFAULT_THRESHOLD_MINUTES;
		}
		// Corrupt stored choices must never activate an unintended delivery channel.
		if (!$valid) {
			$normalized['overtimeEnabled'] = false;
			$normalized['negativeOvertimeEnabled'] = false;
		}
		return $normalized;
	}

	/** @return array{overtimeEnabled:bool,overtimeThresholdMinutes:int,negativeOvertimeEnabled:bool,negativeOvertimeThresholdMinutes:int} */
	public static function validateForSave(mixed $settings): array {
		if ($settings === null) {
			return self::defaults();
		}
		if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
			throw new \InvalidArgumentException('Invalid employee balance notification settings.');
		}
		foreach (self::ENABLED_FIELDS as $field) {
			if (array_key_exists($field, $settings) && !is_bool($settings[$field])) {
				throw new \InvalidArgumentException('Employee balance notification choices must be booleans.');
			}
		}
		foreach (self::THRESHOLD_FIELDS as $field) {
			if (array_key_exists($field, $settings) && !self::isValidThreshold($settings[$field])) {
				throw new \InvalidArgumentException('Employee balance thresholds must be whole minutes between 0 and 525600.');
			}
		}
		return self::normalize($settings);
	}

	private static function isValidThreshold(mixed $value): bool {
		return is_int($value) && $value >= 0 && $value <= self::MAX_THRESHOLD_MINUTES;
	}

	private static function defaults(): array {
		return [
			'overtimeEnabled' => false,
			'overtimeThresholdMinutes' => self::DEFAULT_THRESHOLD_MINUTES,
			'negativeOvertimeEnabled' => false,
			'negativeOvertimeThresholdMinutes' => self::DEFAULT_THRESHOLD_MINUTES,
		];
	}
}
