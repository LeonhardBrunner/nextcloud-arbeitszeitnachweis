<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

/** Describes account classification and permanently excluded calendar dates. */
class EmploymentStatus {
	private const EMPTY_REVISION = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/** @param array<int,array{from:string,to:?string}> $freelancerPeriods
	 * @param array<int,array{from:string,to:?string}> $inactivePeriods
	 */
	public function __construct(
		public readonly bool $isActive = true,
		public readonly bool $freelancerMode = false,
		public readonly string $statusRevision = self::EMPTY_REVISION,
		private readonly array $freelancerPeriods = [],
		private readonly array $inactivePeriods = [],
	) {
		$this->validatePeriods($freelancerPeriods);
		$this->validatePeriods($inactivePeriods);
	}

	/** @return array{isActive:bool,freelancerMode:bool,statusRevision:string} */
	public function toPublicArray(): array {
		return [
			'isActive' => $this->isActive,
			'freelancerMode' => $this->freelancerMode,
			'statusRevision' => $this->statusRevision,
		];
	}

	/** @return array<int,array{from:string,to:?string}> Inclusive starts and exclusive ends. */
	public function getExcludedPeriods(): array {
		$periods = array_values(array_filter(
			array_merge($this->freelancerPeriods, $this->inactivePeriods),
			static fn(array $period): bool => $period['from'] !== $period['to']
		));
		usort($periods, static fn(array $first, array $second): int => strcmp($first['from'], $second['from']));
		$mergedPeriods = [];
		foreach ($periods as $period) {
			$lastIndex = count($mergedPeriods) - 1;
			if ($lastIndex < 0 || ($mergedPeriods[$lastIndex]['to'] !== null && $period['from'] > $mergedPeriods[$lastIndex]['to'])) {
				$mergedPeriods[] = $period;
				continue;
			}
			$previousEnd = $mergedPeriods[$lastIndex]['to'];
			$mergedPeriods[$lastIndex]['to'] = $previousEnd === null || $period['to'] === null
				? null : max($previousEnd, $period['to']);
		}
		return $mergedPeriods;
	}

	/** Reset only the legacy inactivity timer, without hiding older regular missing days. */
	public function getMissingEntryReferenceDate(?string $lastEntry): ?string {
		if (!$this->isActive || $this->freelancerMode) {
			return null;
		}
		if ($lastEntry !== null && !$this->isCalendarDate($lastEntry)) {
			throw new \InvalidArgumentException('Invalid last-entry date.');
		}
		$referenceDate = $lastEntry;
		foreach ($this->getExcludedPeriods() as $period) {
			if ($period['to'] === null) {
				return null;
			}
			$referenceDate = $referenceDate === null ? $period['to'] : max($referenceDate, $period['to']);
		}
		return $referenceDate;
	}

	private function validatePeriods(array $periods): void {
		if (!array_is_list($periods)) {
			throw new \UnexpectedValueException('Invalid employment period list.');
		}
		foreach ($periods as $period) {
			if (!is_array($period) || count($period) !== 2 || !isset($period['from']) || !array_key_exists('to', $period)
				|| !is_string($period['from']) || !$this->isCalendarDate($period['from'])
				|| ($period['to'] !== null && (!is_string($period['to']) || !$this->isCalendarDate($period['to']) || $period['to'] < $period['from']))) {
				throw new \UnexpectedValueException('Invalid employment period.');
			}
		}
	}

	private function isCalendarDate(string $date): bool {
		return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
			&& checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
	}
}
