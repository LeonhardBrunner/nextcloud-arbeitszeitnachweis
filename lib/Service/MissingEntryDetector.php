<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use DateTimeImmutable;
use InvalidArgumentException;

class MissingEntryDetector {

	private const EXEMPT_ABSENCE_COMMENTS = ['urlaub', 'stundenausgleich'];

	/**
	 * Find overdue dates without changing entries or working-time balances.
	 *
	 * @param int[] $weekdays ISO weekdays, from Monday (1) to Sunday (7).
	 * @param array<int, array{workDate:string,startMin:?int,endMin:?int,comment:?string}> $entries
	 * @param string[] $holidayDates
	 * @return array<int, array{date:string,reason:string}>
	 */
	public function findMissingEntries(
		DateTimeImmutable $today,
		string $startDate,
		int $graceDays,
		array $weekdays,
		array $entries,
		array $holidayDates,
	): array {
		$inspectionStart = $this->parseStartDate($startDate, $today);
		if ($graceDays < 1 || $graceDays > 365) {
			throw new InvalidArgumentException('The reminder delay must be between 1 and 365 days.');
		}

		// Calendar arithmetic keeps the cutoff stable across daylight-saving changes.
		$lastDueDate = $today->setTime(0, 0)->modify('-' . $graceDays . ' days');
		if ($inspectionStart > $lastDueDate || $weekdays === []) {
			return [];
		}

		$entriesByDate = [];
		foreach ($entries as $entry) {
			$entriesByDate[$entry['workDate']] = $entry;
		}
		$holidaysByDate = array_fill_keys($holidayDates, true);
		$missingEntries = [];

		// Evaluate each expected day, including gaps followed by more recent entries.
		for ($workDate = $inspectionStart; $workDate <= $lastDueDate; $workDate = $workDate->modify('+1 day')) {
			$dateString = $workDate->format('Y-m-d');
			if (!in_array((int)$workDate->format('N'), $weekdays, true) || isset($holidaysByDate[$dateString])) {
				continue;
			}

			$reason = $this->getMissingEntryReason($entriesByDate[$dateString] ?? null);
			if ($reason !== null) {
				$missingEntries[] = ['date' => $dateString, 'reason' => $reason];
			}
		}

		return $missingEntries;
	}

	private function parseStartDate(string $startDate, DateTimeImmutable $today): DateTimeImmutable {
		try {
			$inspectionStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $today->getTimezone());
		} catch (\ValueError $invalidDate) {
			throw new InvalidArgumentException('The inspection start must be a valid YYYY-MM-DD date.', 0, $invalidDate);
		}
		if ($inspectionStart === false || $inspectionStart->format('Y-m-d') !== $startDate) {
			throw new InvalidArgumentException('The inspection start must be a valid YYYY-MM-DD date.');
		}
		return $inspectionStart;
	}

	/** @param array{workDate:string,startMin:?int,endMin:?int,comment:?string}|null $entry */
	private function getMissingEntryReason(?array $entry): ?string {
		if ($entry === null) {
			return 'missing';
		}

		$hasStartTime = $entry['startMin'] !== null;
		$hasEndTime = $entry['endMin'] !== null;
		if ($hasStartTime && $hasEndTime) {
			return null;
		}
		if ($hasStartTime || $hasEndTime) {
			return 'incomplete';
		}

		// Absence comments exempt whole days only; a started entry remains incomplete.
		return $this->isExemptAbsenceComment($entry['comment']) ? null : 'missing';
	}

	private function isExemptAbsenceComment(?string $comment): bool {
		$normalizedComment = strtolower(trim($comment ?? ''));
		return in_array($normalizedComment, self::EXEMPT_ABSENCE_COMMENTS, true);
	}
}
