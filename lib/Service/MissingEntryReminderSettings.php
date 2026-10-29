<?php

namespace OCA\Timesheet\Service;

/**
 * Defines and validates the reminder settings stored with each group rule.
 */
class MissingEntryReminderSettings {
	private const DEFAULT_GRACE_DAYS = 14;
	private const DEFAULT_WEEKDAYS = [1, 2, 3, 4, 5];

	/** @return array{enabled:bool,graceDays:int,weekdays:int[],startDate:string} */
	public static function normalize(mixed $settings): array {
		$settings = is_array($settings) ? $settings : [];
		$graceDays = array_key_exists('graceDays', $settings) ? $settings['graceDays'] : self::DEFAULT_GRACE_DAYS;
		$weekdays = array_key_exists('weekdays', $settings) ? $settings['weekdays'] : self::DEFAULT_WEEKDAYS;
		$startDate = array_key_exists('startDate', $settings) ? $settings['startDate'] : '';

		$validGraceDays = is_int($graceDays) && $graceDays >= 1 && $graceDays <= 365;
		$validWeekdays = self::hasValidWeekdays($weekdays);
		$validStartDate = is_string($startDate) && self::isValidDate($startDate);

		// Invalid stored policies stay disabled until an administrator saves valid settings.
		return [
			'enabled' => ($settings['enabled'] ?? false) === true
				&& $validGraceDays && $validWeekdays && $weekdays !== [] && $validStartDate,
			'graceDays' => $validGraceDays ? $graceDays : self::DEFAULT_GRACE_DAYS,
			'weekdays' => $validWeekdays ? self::normalizeWeekdays($weekdays) : self::DEFAULT_WEEKDAYS,
			'startDate' => $validStartDate ? $startDate : '',
		];
	}

	/**
	 * Reject invalid submitted values rather than silently changing an active policy.
	 *
	 * @return array{enabled:bool,graceDays:int,weekdays:int[],startDate:string}
	 */
	public static function validateForSave(mixed $settings, string $todayDate, ?array $previousSettings = null): array {
		if ($settings === null) {
			return self::normalize(null);
		}
		if (!is_array($settings)) {
			throw new \InvalidArgumentException('Invalid missing-entry reminder settings');
		}

		$enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : false;
		$graceDays = array_key_exists('graceDays', $settings) ? $settings['graceDays'] : self::DEFAULT_GRACE_DAYS;
		$weekdays = array_key_exists('weekdays', $settings) ? $settings['weekdays'] : self::DEFAULT_WEEKDAYS;
		if (!is_bool($enabled)) {
			throw new \InvalidArgumentException('Reminder enabled must be a boolean');
		}
		if (!is_int($graceDays) || $graceDays < 1 || $graceDays > 365) {
			throw new \InvalidArgumentException('Reminder grace days must be an integer between 1 and 365');
		}
		if (!self::hasValidWeekdays($weekdays) || ($enabled && $weekdays === [])) {
			throw new \InvalidArgumentException('Reminder weekdays must contain numbers between 1 and 7');
		}

		// An omitted date is initialized only for a newly enabled policy.
		$startDate = array_key_exists('startDate', $settings) ? $settings['startDate'] : ($previousSettings['startDate'] ?? '');
		if (!array_key_exists('startDate', $settings) && $enabled && $startDate === '' && !($previousSettings['enabled'] ?? false)) {
			$startDate = $todayDate;
		}
		if (!is_string($startDate) || ($startDate !== '' && !self::isValidDate($startDate)) || ($enabled && $startDate === '')) {
			throw new \InvalidArgumentException('Reminder start date must be a valid date in YYYY-MM-DD format');
		}

		return [
			'enabled' => $enabled,
			'graceDays' => $graceDays,
			'weekdays' => self::normalizeWeekdays($weekdays),
			'startDate' => $startDate,
		];
	}

	private static function hasValidWeekdays(mixed $weekdays): bool {
		if (!is_array($weekdays) || !array_is_list($weekdays)) {
			return false;
		}
		foreach ($weekdays as $weekday) {
			if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
				return false;
			}
		}
		return true;
	}

	/** @param int[] $weekdays */
	private static function normalizeWeekdays(array $weekdays): array {
		$weekdays = array_values(array_unique($weekdays));
		sort($weekdays, SORT_NUMERIC);
		return $weekdays;
	}

	private static function isValidDate(string $date): bool {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return false;
		}
		return checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
	}
}
