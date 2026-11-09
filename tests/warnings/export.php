<?php

declare(strict_types=1);

// The exporter needs only translation at this seam, not a running Nextcloud instance.
namespace OCP {
	interface IL10N {
		public function t(string $message, array $parameters = []): string;
	}
}

namespace {
	require dirname(__DIR__, 2) . '/vendor/autoload.php';

	use OCA\Timesheet\Service\TimesheetXlsxExporter;
	use PhpOffice\PhpSpreadsheet\Spreadsheet;

	class WarningExportTranslation implements \OCP\IL10N {
		public function __construct(private array $translations) {
		}

		public function t(string $message, array $parameters = []): string {
			return $this->translations[$message] ?? $message;
		}
	}

	class WarningExportEntry {
		public function __construct(private ?int $start, private ?int $end, private int $pause) {
		}
		public function getStartMin(): ?int { return $this->start; }
		public function getEndMin(): ?int { return $this->end; }
		public function getBreakMinutes(): int { return $this->pause; }
		public function getComment(): string { return ''; }
	}

	$checks = 0;
	$expect = static function (mixed $actual, mixed $expected, string $message) use (&$checks): void {
		$checks++;
		if ($actual !== $expected) {
			throw new RuntimeException($message . ': ' . json_encode(['actual' => $actual, 'expected' => $expected], JSON_UNESCAPED_UNICODE));
		}
	};
	$warningKeys = ['Above maximum time', 'Break too short', 'Sunday work not allowed', 'Holiday work not allowed'];
	$german = [
		'Above maximum time' => 'Maximale Arbeitszeit überschritten',
		'Break too short' => 'Pause zu kurz',
		'Sunday work not allowed' => 'Sonntagsarbeit nicht erlaubt',
		'Holiday work not allowed' => 'Arbeit an Feiertagen nicht erlaubt',
		'Holiday' => 'Feiertag',
	];
	$cases = [];
	foreach (['en_GB', 'de', 'de_DE', 'ja'] as $locale) {
		$catalog = json_decode(file_get_contents(dirname(__DIR__, 2) . '/l10n/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR)['translations'];
		$cases[$locale] = str_starts_with($locale, 'de') ? array_replace($catalog, $german) : $catalog;
	}
	$quoted = [];
	foreach ([...$warningKeys, 'Holiday'] as $key) {
		$quoted[$key] = 'Text mit "Anführungszeichen": ' . $key;
	}
	$cases['quoted'] = $quoted;
	$method = new ReflectionMethod(TimesheetXlsxExporter::class, 'writeDailyRows');
	$exporter = (new ReflectionClass(TimesheetXlsxExporter::class))->newInstanceWithoutConstructor();
	foreach ($cases as $locale => $messages) {
		$translator = new WarningExportTranslation($messages);
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('C4', 480 / 1440);
		$entries = [
			'2026-11-02' => new WarningExportEntry(480, 1140, 0),
			'2026-11-03' => new WarningExportEntry(480, 871, 0),
			'2026-11-08' => new WarningExportEntry(480, 990, 30),
			'2026-11-09' => new WarningExportEntry(480, 990, 30),
			'2026-11-10' => new WarningExportEntry(480, 990, 30),
			'2026-11-11' => new WarningExportEntry(480, null, 0),
		];
		$endRow = $method->invoke($exporter, $sheet, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), $entries, ['2026-11-09' => 'Fixture holiday'], 7, 4, $translator);
		$expect($endRow, 37, $locale . ' keeps all daily row positions');
		$expect($sheet->getCell('J8')->getCalculatedValue(), $translator->t('Above maximum time') . ', ' . $translator->t('Break too short'), $locale . ' maximum-time and break warnings use the translator');
		$expect($sheet->getCell('J9')->getCalculatedValue(), $translator->t('Break too short'), $locale . ' short-break warning uses the translator');
		$expect($sheet->getCell('J14')->getCalculatedValue(), $translator->t('Sunday work not allowed'), $locale . ' Sunday warning uses the translator');
		$expect($sheet->getCell('C15')->getValue(), $translator->t('Holiday'), $locale . ' stores the localized holiday status');
		$expect($sheet->getCell('J15')->getCalculatedValue(), $translator->t('Holiday work not allowed'), $locale . ' holiday comparison matches the written status');
		$expect($sheet->getCell('J16')->getCalculatedValue(), '', $locale . ' compliant entries remain without warnings');
		$expect($sheet->getCell('J17')->getCalculatedValue(), '', $locale . ' incomplete-export behavior remains unchanged');
		$expect($sheet->getCell('G8')->getValue(), '=IF(AND(D8<>"",F8<>""),(F8-D8-E8/1440),"")', $locale . ' work-duration formula remains unchanged');
		if ($locale === 'en_GB') {
			$expect($sheet->getCell('J8')->getValue(), '=IF(G8="","",TEXTJOIN(", ",TRUE,IF(G8>TIME(10,0,0),"Above maximum time",""),IF(G8>TIME(9,0,0),IF(E8<45,"Break too short",""),IF(G8>TIME(6,0,0),IF(E8<30,"Break too short",""),"")),IF(AND(WEEKDAY(A8,2)=7,G8>0),"Sunday work not allowed",""),IF(C8="Holiday","Holiday work not allowed","")))', 'English warning formula remains identical');
		}
		if ($locale === 'quoted') {
			$expect(str_contains($sheet->getCell('J8')->getValue(), '""Anführungszeichen""'), true, 'Excel literals escape embedded double quotes');
		}
		$spreadsheet->disconnectWorksheets();
	}
	echo 'Export warning localization: ' . $checks . " checks passed.\n";
}

