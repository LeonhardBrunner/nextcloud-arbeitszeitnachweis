<?php

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Db\UserConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TimesheetXlsxExporter {
	public function __construct(
		private EntryMapper $entryMapper,
		private UserConfigMapper $userConfigMapper,
		private HolidayService $holidayService,
	) {
	}

	/**
	 * The caller supplies an authorized user and normalized, ascending month boundaries.
	 *
	 * @return array{content:string,fileName:string}
	 */
	public function export(
		string $targetUserId,
		\DateTimeImmutable $fromMonth,
		\DateTimeImmutable $toMonth,
		string $locale,
		IL10N $l10n,
	): array {
		try {
			$userConfig = $this->userConfigMapper->findByUser($targetUserId);
		} catch (DoesNotExistException $exception) {
			$userConfig = null;
		}
		$dailyWorkMinutes = $userConfig?->getWorkMinutes() ?? 480; // Eight hours when no user setting exists.
		$state = $userConfig?->getState() ?? '';

		$exportDate = new \DateTimeImmutable('now', $fromMonth->getTimezone());
		$exportLabel = $l10n->t('Exported: %s', [$exportDate->format('d.m.Y H:i T')]);

		$spreadsheet = new Spreadsheet();
		$holidaysByYear = [];
		$sheetIndex = 0;

		for ($monthStart = $fromMonth; $monthStart <= $toMonth; $monthStart = $monthStart->modify('+1 month')) {
			$monthEnd = $monthStart->modify('last day of this month');
			$fromDate = $monthStart->format('Y-m-d');
			$toDate = $monthEnd->format('Y-m-d');

			$entries = $this->entryMapper->findByUserAndRange($targetUserId, $fromDate, $toDate);
			$entriesByDate = [];
			foreach ($entries as $entry) {
				$entriesByDate[$entry->getWorkDate()] = $entry;
			}

			// Reuse each year's holiday data across the exported months.
			$holidays = [];
			if ($state !== '') {
				$year = (int)$monthStart->format('Y');
				if (!array_key_exists($year, $holidaysByYear)) {
					$holidaysByYear[$year] = $this->holidayService->getHolidays($year, $state);
				}
				$holidays = $holidaysByYear[$year];
			}

			$sheet = ($sheetIndex === 0) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($sheetIndex);
			$sheet->setTitle($this->sheetTitleForMonth($monthStart, $locale));
			$sheetIndex++;

			$this->writeMonthSheet($sheet, $targetUserId, $monthStart, $monthEnd, $dailyWorkMinutes, $entriesByDate, $holidays, $exportLabel, $l10n);
		}

		$spreadsheet->setActiveSheetIndex(0);

		$writer = new Xlsx($spreadsheet);
		ob_start();
		$writer->save('php://output');
		$content = ob_get_clean();

		$fromLabel = $fromMonth->format('Y-m');
		$toLabel = $toMonth->format('Y-m');
		$fileName = ($fromLabel === $toLabel)
			? sprintf($l10n->t('Timesheet_%s_%s.xlsx', [$targetUserId, $fromLabel]))
			: sprintf($l10n->t('Timesheet_%s_%s_to_%s.xlsx', [$targetUserId, $fromLabel, $toLabel]));

		return ['content' => $content, 'fileName' => $fileName];
	}

	private function writeMonthSheet(
		Worksheet $sheet,
		string $targetUserId,
		\DateTimeImmutable $monthStart,
		\DateTimeImmutable $monthEnd,
		int $dailyWorkMinutes,
		array $entriesByDate,
		array $holidays,
		string $exportLabel,
		IL10N $l10n,
	): void {
		$rowNumber = 1;

		// Summary rows precede the daily table.
		$sheet->setCellValue("A{$rowNumber}", $l10n->t('Employee'));
		$sheet->mergeCells("A{$rowNumber}:B{$rowNumber}");
		$sheet->setCellValue("C{$rowNumber}", $targetUserId);
		$rowNumber++;

		$sheet->setCellValue("A{$rowNumber}", $l10n->t('Worked Hours'));
		$sheet->mergeCells("A{$rowNumber}:B{$rowNumber}");
		$workedHoursRow = $rowNumber;
		$rowNumber++;

		$sheet->setCellValue("A{$rowNumber}", $l10n->t('Overtime'));
		$sheet->mergeCells("A{$rowNumber}:B{$rowNumber}");
		$overtimeRow = $rowNumber;
		$rowNumber++;

		$sheet->setCellValue("A{$rowNumber}", $l10n->t('Daily working time'));
		$sheet->mergeCells("A{$rowNumber}:B{$rowNumber}");
		$sheet->setCellValue("C{$rowNumber}", $dailyWorkMinutes / 1440);
		$sheet->getStyle("C{$rowNumber}")
			->getNumberFormat()->setFormatCode('[HH]:MM');
		$dailyWorkTimeRow = $rowNumber;

		$sheet->getStyle("A1:C{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
		$rowNumber += 2;

		$headerRow = $rowNumber;
		$rowNumber = $this->writeTableHeader($sheet, $rowNumber, $l10n);
		$firstDataRow = $rowNumber;
		$rowNumber = $this->writeDailyRows($sheet, $monthStart, $monthEnd, $entriesByDate, $holidays, $rowNumber, $dailyWorkTimeRow, $l10n);

		$lastDataRow = $rowNumber - 1;

		$durationSumFormula = "SUM(G{$firstDataRow}:G{$lastDataRow})";
		$workedDaysFormula = "COUNT(G{$firstDataRow}:G{$lastDataRow})";
		$expectedDurationFormula = "(\$C\${$dailyWorkTimeRow}*{$workedDaysFormula})";
		$durationDifferenceFormula = "({$durationSumFormula}-{$expectedDurationFormula})";

		$sheet->setCellValue("C{$workedHoursRow}", "=SUM(G{$firstDataRow}:G{$lastDataRow})");
		$sheet->setCellValue("C{$overtimeRow}", "=IF({$workedDaysFormula}=0,\"\",IF({$durationDifferenceFormula}<0,\"-\"&TEXT(-{$durationDifferenceFormula},\"[hh]:mm\"),TEXT({$durationDifferenceFormula},\"[hh]:mm\")))");
		$sheet->getStyle("C{$workedHoursRow}:C{$overtimeRow}")->getNumberFormat()->setFormatCode('[HH]:MM');

		$sheet->getStyle("A" . ($headerRow + 1) . ":A" . ($lastDataRow))->getNumberFormat()->setFormatCode('DD.MM.YYYY');
		$sheet->getStyle("D" . $firstDataRow . ":F" . $lastDataRow)->getNumberFormat()->setFormatCode('HH:MM');
		$sheet->getStyle("E" . $firstDataRow . ":E" . $lastDataRow)->getNumberFormat()->setFormatCode('0');
		$sheet->getStyle("G" . $firstDataRow . ":H" . $lastDataRow)->getNumberFormat()->setFormatCode('[HH]:MM');

		$rowNumber++;
		$sheet->setCellValue("A{$rowNumber}", $exportLabel);
		$sheet->mergeCells("A{$rowNumber}:C{$rowNumber}");

		foreach (range('A', 'H') as $column) {
			$sheet->getColumnDimension($column)->setAutoSize(true);
		}
		$sheet->getColumnDimension('I')->setWidth(40);
		$sheet->getColumnDimension('J')->setWidth(50);
		$sheet->getStyle("I{$firstDataRow}:J{$lastDataRow}")->getAlignment()->setWrapText(true);
	}

	private function writeTableHeader(Worksheet $sheet, int $rowNumber, IL10N $l10n): int {
		$sheet->setCellValue("A{$rowNumber}", $l10n->t('Date'));
		$sheet->mergeCells("A{$rowNumber}:B{$rowNumber}");

		$sheet->setCellValue("C{$rowNumber}", $l10n->t('Status'));
		$sheet->setCellValue("D{$rowNumber}", $l10n->t('Start'));
		$sheet->setCellValue("E{$rowNumber}", $l10n->t('Break (min)'));
		$sheet->setCellValue("F{$rowNumber}", $l10n->t('End'));
		$sheet->setCellValue("G{$rowNumber}", $l10n->t('Duration'));
		$sheet->setCellValue("H{$rowNumber}", $l10n->t('Difference'));
		$sheet->setCellValue("I{$rowNumber}", $l10n->t('Comment'));
		$sheet->setCellValue("J{$rowNumber}", $l10n->t('Warning'));

		$sheet->getStyle("A{$rowNumber}:J{$rowNumber}")->getFont()->setBold(true);
		$sheet->getStyle("A{$rowNumber}:J{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
		$sheet->getStyle("A{$rowNumber}:J{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

		$rowNumber++;
		return $rowNumber;
	}

	private function writeDailyRows(
		Worksheet $sheet,
		\DateTimeImmutable $monthStart,
		\DateTimeImmutable $monthEnd,
		array $entriesByDate,
		array $holidays,
		int $rowNumber,
		int $dailyWorkTimeRow,
		IL10N $l10n,
	): int {
		for ($workDate = $monthStart; $workDate <= $monthEnd; $workDate = $workDate->modify('+1 day')) {
			$workDateString = $workDate->format('Y-m-d');
			$entry = $entriesByDate[$workDateString] ?? null;

			$sheet->setCellValue("A{$rowNumber}", Date::PHPToExcel($workDate->setTime(0, 0, 0)));
			$sheet->getStyle("A{$rowNumber}")->getNumberFormat()->setFormatCode('DD.MM.YYYY');

			$dayIndex = (int)$workDate->format('w');
			$weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
			$weekdayKey = $weekdays[$dayIndex] ?? '';
			$weekday = $weekdayKey !== '' ? $l10n->t($weekdayKey) : '';
			$sheet->setCellValue("B{$rowNumber}", $weekday);

			$isWeekend = ($dayIndex === 0 || $dayIndex === 6);
			$isHoliday = isset($holidays[$workDateString]);

			$status = '';
			if ($isHoliday) {
				$status = $l10n->t('Holiday');
			} elseif ($isWeekend) {
				$status = $l10n->t('Weekend');
			}
			$sheet->setCellValue("C{$rowNumber}", $status);

			$startMinutes = null;
			$endMinutes = null;
			$breakMinutes = 0;
			$comment = '';

			if ($entry) {
				$startMinutes = $entry->getStartMin();
				$endMinutes = $entry->getEndMin();
				$breakMinutes = $entry->getBreakMinutes() ?? 0;
				$comment = (string)$entry->getComment();
			}

			if ($startMinutes !== null && $endMinutes !== null) {
				$sheet->setCellValue("D{$rowNumber}", $startMinutes / 1440);
				$sheet->setCellValue("F{$rowNumber}", $endMinutes / 1440);
			} else {
				$sheet->setCellValue("D{$rowNumber}", null);
				$sheet->setCellValue("F{$rowNumber}", null);
			}

			$sheet->setCellValue("E{$rowNumber}", $breakMinutes);

			// Excel stores time as a fraction of a day (1440 minutes).
			$durationFormula = '=IF(AND(D' . $rowNumber . '<>"",F' . $rowNumber . '<>""),(F' . $rowNumber . '-D' . $rowNumber . '-E' . $rowNumber . '/1440),"")';
			$sheet->setCellValue("G{$rowNumber}", $durationFormula);

			$differenceFormula =
			'=IF(G' . $rowNumber . '="","",' .
			'IF(G' . $rowNumber . '<$C$' . $dailyWorkTimeRow .
			',"-"&TEXT($C$' . $dailyWorkTimeRow . '-G' . $rowNumber . ',"hh:mm"),' .
			'TEXT(G' . $rowNumber . '-$C$' . $dailyWorkTimeRow . ',"hh:mm")' .
			'))';
			$sheet->setCellValue("H{$rowNumber}", $differenceFormula);

			$sheet->setCellValue("I{$rowNumber}", $comment);

			$breakWarningFormula = 'IF(G' . $rowNumber . '>TIME(9,0,0),IF(E' . $rowNumber . '<45,"Break too short",""),IF(G' . $rowNumber . '>TIME(6,0,0),IF(E' . $rowNumber . '<30,"Break too short",""),""))';
			$warningFormula =
			'=IF(G' . $rowNumber . '="","",TEXTJOIN(", ",TRUE,' .
			'IF(G' . $rowNumber . '>TIME(10,0,0),"Above maximum time",""),' .
			$breakWarningFormula . ',' .
			'IF(AND(WEEKDAY(A' . $rowNumber . ',2)=7,G' . $rowNumber . '>0),"Sunday work not allowed",""),' .
			'IF(C' . $rowNumber . '="Holiday","Holiday work not allowed","")' .
			'))';
			$sheet->setCellValue("J{$rowNumber}", $warningFormula);

			if ($isWeekend || $isHoliday) {
				$sheet->getStyle("A{$rowNumber}:J{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9F9F9');
			}

			$sheet->getStyle("A{$rowNumber}:H{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
			$rowNumber++;
		}
		return $rowNumber;
	}

	private function sheetTitleForMonth(\DateTimeImmutable $monthStart, string $locale): string {
		$formattedMonth = '';

		if (class_exists(\IntlDateFormatter::class)) {
			$formatter = new \IntlDateFormatter(
				$locale,
				\IntlDateFormatter::NONE,
				\IntlDateFormatter::NONE,
				$monthStart->getTimezone()->getName(),
				\IntlDateFormatter::GREGORIAN,
				'LLLL yyyy'
			);
			if ($formatter) {
				$formattedMonth = (string)$formatter->format($monthStart);
			}
		}

		if ($formattedMonth === '') {
			$formattedMonth = $monthStart->format('MMMM yyyy');
		}

		$title = preg_replace('/[\\\\\\/\\?\\*\\:\\[\\]]/', ' ', $formattedMonth);
		$title = trim(preg_replace('/\\s+/', ' ', (string)$title));
		if ($title === '') {
			$title = $monthStart->format('MMMM yyyy');
		}

		return function_exists('mb_substr') ? mb_substr($title, 0, 31, 'UTF-8') : substr($title, 0, 31);
	}
}
