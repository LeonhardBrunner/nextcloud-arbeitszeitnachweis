<?php

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\MonthlyBalance;
use OCA\Timesheet\Db\MonthlyBalanceMapper;
use OCA\Timesheet\Db\UserConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;

class MonthlyBalanceService {
	private const DEFAULT_WORK_MINUTES = 480;

	public function __construct(
		private EntryMapper $entryMapper,
		private MonthlyBalanceMapper $balanceMapper,
		private UserConfigMapper $userConfigMapper,
		private IAppConfig $appConfig,
		private HolidayService $holidayService,
	) {
	}

	public function getMonth(string $userId, int $year, int $month): ?MonthlyBalance {
		return $this->balanceMapper->findByUserMonth($userId, $year, $month);
	}

	public function ensureMonthSnapshot(string $userId, int $year, int $month): MonthlyBalance {
		[$year, $month] = $this->normalizeMonth($year, $month);
		$this->recalculateDirtyForUserUpTo($userId, $year, $month);

		$balance = $this->balanceMapper->findByUserMonth($userId, $year, $month);
		if ($balance === null || (bool)$balance->getNeedsRecalculation()) {
			return $this->calculateAndSaveMonth($userId, $year, $month);
		}

		return $balance;
	}

	public function calculateAndSaveMonth(string $userId, int $year, int $month): MonthlyBalance {
		[$year, $month] = $this->normalizeMonth($year, $month);
		$workConfig = $this->getWorkConfig($userId);
		$monthStart = $this->monthStart($year, $month);
		$monthEnd = $this->monthEnd($year, $month);

		// Carry the earlier balance forward before applying this month's delta.
		$openingBalanceMinutes = $this->calculateOpeningBalance($userId, $year, $month, $workConfig['dailyMin'], $workConfig['state']);
		$monthValues = $this->calculateRangeDelta($userId, $monthStart, $monthEnd, $workConfig['dailyMin'], $workConfig['state']);
		$deltaMinutes = $monthValues['actualMinutes'] - $monthValues['targetMinutes'];

		$existingBalance = $this->balanceMapper->findByUserMonth($userId, $year, $month);
		$balance = $existingBalance ?? new MonthlyBalance();
		$calculatedAt = time();

		if ($existingBalance === null) {
			$balance->setUserId($userId);
			$balance->setYear($year);
			$balance->setMonth($month);
		}

		$balance->setOpeningBalanceMinutes($openingBalanceMinutes);
		$balance->setTargetMinutes($monthValues['targetMinutes']);
		$balance->setActualMinutes($monthValues['actualMinutes']);
		$balance->setDeltaMinutes($deltaMinutes);
		$balance->setClosingBalanceMinutes($openingBalanceMinutes + $deltaMinutes);
		$balance->setIsClosed(false);
		$balance->setNeedsRecalculation(false);
		$balance->setCalculatedAt($calculatedAt);
		$balance->setUpdatedAt($calculatedAt);

		return $this->balanceMapper->saveCalculated($balance);
	}

	public function markDirtyFromDate(string $userId, string $workDate): void {
		$year = (int)substr($workDate, 0, 4);
		$month = (int)substr($workDate, 5, 2);
		if ($year > 0 && $month >= 1 && $month <= 12) {
			$this->markDirtyFromMonth($userId, $year, $month);
		}
	}

	public function markDirtyFromMonth(string $userId, int $year, int $month): void {
		[$year, $month] = $this->normalizeMonth($year, $month);
		$this->balanceMapper->markDirtyFromMonth($userId, $year, $month);
	}

	public function markAllDirtyForUser(string $userId): void {
		$this->balanceMapper->markAllDirtyForUser($userId);
	}

	public function markAllDirty(): void {
		$this->balanceMapper->markAllDirty();
	}

	public function recalculateDirtyForUserUpTo(string $userId, int $year, int $month): void {
		[$year, $month] = $this->normalizeMonth($year, $month);
		$dirtyBalances = $this->balanceMapper->findDirtyForUserUpTo($userId, $year, $month);
		foreach ($dirtyBalances as $balance) {
			$this->calculateAndSaveMonth($userId, (int)$balance->getYear(), (int)$balance->getMonth());
		}
	}

	public function findLatestCleanBefore(string $userId, int $year, int $month): ?MonthlyBalance {
		[$year, $month] = $this->normalizeMonth($year, $month);
		return $this->balanceMapper->findLatestCleanBefore($userId, $year, $month);
	}

	public function calculateCurrentBalance(string $userId): array {
		$today = new \DateTimeImmutable('today');
		$nextMonth = $today->modify('first day of next month');
		$targetYear = (int)$nextMonth->format('Y');
		$targetMonth = (int)$nextMonth->format('m');

		$currentMonth = $today->modify('first day of this month');
		$this->ensureMonthSnapshot($userId, (int)$currentMonth->format('Y'), (int)$currentMonth->format('m'));

		$workConfig = $this->getWorkConfig($userId);
		// Start with the latest clean snapshot, then add the remaining entries.
		$latestBalance = $this->balanceMapper->findLatestCleanBefore($userId, $targetYear, $targetMonth);
		$openingBalanceMinutes = 0;
		$openRangeStart = null;
		$balanceSource = 'entries';

		if ($latestBalance !== null) {
			$openingBalanceMinutes = (int)$latestBalance->getClosingBalanceMinutes();
			$openRangeStart = $this->monthStart((int)$latestBalance->getYear(), (int)$latestBalance->getMonth())->modify('+1 month')->format('Y-m-d');
			$balanceSource = 'snapshot';
		}

		$openRangeValues = $this->calculateRangeDelta($userId, $openRangeStart, null, $workConfig['dailyMin'], $workConfig['state']);
		$overtimeMinutes = $openingBalanceMinutes + $openRangeValues['actualMinutes'] - $openRangeValues['targetMinutes'];
		$lastEntryDate = $this->entryMapper->getLastEntryDateForUser($userId);

		return [
			'from' => $latestBalance
				? sprintf('%04d-%02d-01', (int)$latestBalance->getYear(), (int)$latestBalance->getMonth())
				: ($openRangeValues['from'] ?? $today->format('Y-m-d')),
			'to' => $lastEntryDate ?? (
				$latestBalance
					? $this->monthEnd((int)$latestBalance->getYear(), (int)$latestBalance->getMonth())->format('Y-m-d')
					: $today->format('Y-m-d')
			),
			'totalMinutes' => $openRangeValues['actualMinutes'],
			'totalWorkdays' => $openRangeValues['workdays'],
			'targetMinutes' => $openRangeValues['targetMinutes'],
			'dailyMin' => $workConfig['dailyMin'],
			'openingBalanceMinutes' => $openingBalanceMinutes,
			'overtimeMinutes' => $overtimeMinutes,
			'lastEntryDate' => $lastEntryDate,
			'source' => $balanceSource,
			'lastSnapshot' => $latestBalance ? $latestBalance->jsonSerialize() : null,
		];
	}

	private function calculateOpeningBalance(string $userId, int $year, int $month, int $dailyMin, string $state): int {
		$monthStart = $this->monthStart($year, $month);
		$previousMonthEnd = $monthStart->modify('-1 day')->format('Y-m-d');
		$latestBalance = $this->balanceMapper->findLatestCleanBefore($userId, $year, $month);

		if ($latestBalance !== null) {
			$openingBalanceMinutes = (int)$latestBalance->getClosingBalanceMinutes();
			$uncachedRangeStart = $this->monthStart((int)$latestBalance->getYear(), (int)$latestBalance->getMonth())->modify('+1 month')->format('Y-m-d');
			if ($uncachedRangeStart > $previousMonthEnd) {
				return $openingBalanceMinutes;
			}
			$rangeValues = $this->calculateRangeDelta($userId, $uncachedRangeStart, $previousMonthEnd, $dailyMin, $state);
			return $openingBalanceMinutes + $rangeValues['actualMinutes'] - $rangeValues['targetMinutes'];
		}

		$rangeValues = $this->calculateRangeDelta($userId, null, $previousMonthEnd, $dailyMin, $state);
		return $rangeValues['actualMinutes'] - $rangeValues['targetMinutes'];
	}

	/** @return array{actualMinutes:int,targetMinutes:int,workdays:int,from:?string,to:?string} */
	private function calculateRangeDelta(
		string $userId,
		string|\DateTimeImmutable|null $from,
		string|\DateTimeImmutable|null $to,
		int $dailyMin,
		string $state
	): array {
		$fromYmd = $from instanceof \DateTimeImmutable ? $from->format('Y-m-d') : $from;
		$toYmd = $to instanceof \DateTimeImmutable ? $to->format('Y-m-d') : $to;
		if ($toYmd !== null && $fromYmd !== null && $fromYmd > $toYmd) {
			return ['actualMinutes' => 0, 'targetMinutes' => 0, 'workdays' => 0, 'from' => null, 'to' => null];
		}

		// Exclude weekends from target workdays; subtract public holidays below.
		$excludeSpecialDays = $this->appConfig->getAppValueString('specialdays_check', '0') === '1';
		$workAggregate = $this->entryMapper->calculateWorkAggregateForRange($userId, $fromYmd, $toYmd, $excludeSpecialDays);
		if (!$workAggregate) {
			return ['actualMinutes' => 0, 'targetMinutes' => 0, 'workdays' => 0, 'from' => null, 'to' => null];
		}

		$workdayCount = (int)$workAggregate['totalWorkdays'];
		if ($excludeSpecialDays && $state !== '') {
			$holidayDates = $this->getHolidayDates((string)$workAggregate['from'], (string)$workAggregate['to'], $state);
			if ($holidayDates !== []) {
				$workdayCount = max(0, $workdayCount - $this->entryMapper->countWorkdaysOnDates($userId, $holidayDates));
			}
		}

		return [
			'actualMinutes' => (int)$workAggregate['totalMinutes'],
			'targetMinutes' => $workdayCount * $dailyMin,
			'workdays' => $workdayCount,
			'from' => (string)$workAggregate['from'],
			'to' => (string)$workAggregate['to'],
		];
	}

	/** @return string[] */
	private function getHolidayDates(string $from, string $to, string $state): array {
		$fromYear = (int)substr($from, 0, 4);
		$toYear = (int)substr($to, 0, 4);
		if ($fromYear <= 0 || $toYear <= 0) {
			return [];
		}

		$holidayDates = [];
		try {
			for ($year = $fromYear; $year <= $toYear; $year++) {
				foreach ($this->holidayService->getHolidays($year, $state) as $holidayDate => $_holidayName) {
					if ($holidayDate >= $from && $holidayDate <= $to) {
						$holidayDates[] = $holidayDate;
					}
				}
			}
		} catch (\Throwable) {
			return [];
		}
		return $holidayDates;
	}

	/** @return array{dailyMin:int,state:string} */
	private function getWorkConfig(string $userId): array {
		$dailyWorkMinutes = self::DEFAULT_WORK_MINUTES;
		$stateCode = '';
		try {
			$userConfig = $this->userConfigMapper->findByUser($userId);
			$workMinutes = (int)($userConfig->getWorkMinutes() ?? self::DEFAULT_WORK_MINUTES);
			$dailyWorkMinutes = $workMinutes > 0 ? $workMinutes : self::DEFAULT_WORK_MINUTES;
			$stateCode = trim((string)($userConfig->getState() ?? ''));
			if ($stateCode === 'null') {
				$stateCode = '';
			}
		} catch (DoesNotExistException) {
			// Keep defaults when no user configuration exists.
		}
		return ['dailyMin' => $dailyWorkMinutes, 'state' => $stateCode];
	}

	/** @return array{int,int} */
	private function normalizeMonth(int $year, int $month): array {
		$monthDate = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, max(1, min(12, $month))));
		return [(int)$monthDate->format('Y'), (int)$monthDate->format('m')];
	}

	private function monthStart(int $year, int $month): \DateTimeImmutable {
		return new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
	}

	private function monthEnd(int $year, int $month): \DateTimeImmutable {
		return $this->monthStart($year, $month)->modify('last day of this month');
	}
}
