<?php

namespace OCA\Timesheet\Service;

use OCP\IL10N;

/** Localized wording and generated rows for the three weekly HR email sections. */
class HrWeeklyMailTemplates {
	public const ALERTS = ['noEntry', 'overtime', 'negative'];

	public static function editor(IL10N $translator, string $language): array {
		$originals = [];
		foreach (self::ALERTS as $alert) {
			$originals[$alert] = self::heading($translator, $alert, '{threshold}') . "\n{entries}";
		}
		return [
			'language' => $language,
			'originals' => $originals,
			'maxLength' => NotificationMailTemplates::MAX_LENGTH,
		];
	}

	public static function heading(IL10N $translator, string $alert, string $threshold): string {
		return match ($alert) {
			'noEntry' => $translator->t('No complete entry for at least %1$s days:', [$threshold]),
			'overtime' => $translator->t('Too much overtime:'),
			'negative' => $translator->t('Too many negative hours:'),
		};
	}

	public static function entry(IL10N $translator, string $alert, array $warning): string {
		return match ($alert) {
			'noEntry' => $translator->t('%1$s (%2$s days without a complete entry)', [$warning['user'], $warning['days']]),
			'overtime' => $translator->t('%1$s (%2$s overtime)', [$warning['user'], $warning['overtime']]),
			'negative' => $translator->t('%1$s (%2$s deficit)', [$warning['user'], $warning['deficit']]),
		};
	}
}
