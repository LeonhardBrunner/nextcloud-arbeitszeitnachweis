<?php

declare(strict_types=1);

use OCA\Timesheet\Service\MissingEntryDetector;
use OCA\Timesheet\Service\AbsenceCommentCatalog;

require_once __DIR__ . '/../../lib/Service/AbsenceCommentCatalog.php';
require_once __DIR__ . '/../../lib/Service/MissingEntryDetector.php';

$absenceComments = new AbsenceCommentCatalog();
$detector = new MissingEntryDetector($absenceComments);
$testCount = 0;
$failureCount = 0;

// Fixed local dates make day boundaries and daylight-saving cases reproducible.
$date = static fn(string $value): DateTimeImmutable => new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));
$entry = static fn(string $workDate, ?int $startMin = 480, ?int $endMin = 960, ?string $comment = null): array => [
	'workDate' => $workDate,
	'startMin' => $startMin,
	'endMin' => $endMin,
	'comment' => $comment,
];
$assertSame = static function (mixed $expected, mixed $actual): void {
	if ($expected !== $actual) {
		throw new RuntimeException('Expected ' . var_export($expected, true) . '; received ' . var_export($actual, true));
	}
};
$test = static function (string $name, callable $check) use (&$testCount, &$failureCount): void {
	$testCount++;
	try {
		$check();
	} catch (Throwable $failure) {
		$failureCount++;
		fwrite(STDERR, 'FAIL: ' . $name . ': ' . $failure->getMessage() . "\n");
	}
};
$weekdays = [1, 2, 3, 4, 5];
$everyDay = [1, 2, 3, 4, 5, 6, 7];

$test('An isolated Monday gap remains visible after newer entries', static function () use ($detector, $date, $entry, $assertSame, $weekdays): void {
	$assertSame([['date' => '2026-10-12', 'reason' => 'missing']], $detector->findMissingEntries(
		$date('2026-10-21'), '2026-10-12', 7, $weekdays,
		[$entry('2026-10-13'), $entry('2026-10-14')], [],
	));
});

$test('The exact calendar-day threshold is included', static function () use ($detector, $date, $assertSame, $weekdays): void {
	$assertSame([['date' => '2026-10-12', 'reason' => 'missing']], $detector->findMissingEntries($date('2026-10-19 23:59:59'), '2026-10-12', 7, $weekdays, [], []));
	$assertSame([], $detector->findMissingEntries($date('2026-10-18 23:59:59'), '2026-10-12', 7, $weekdays, [], []));
});

$test('Weekend days count toward the delay', static function () use ($detector, $date, $assertSame): void {
	$assertSame([['date' => '2026-10-16', 'reason' => 'missing']], $detector->findMissingEntries($date('2026-10-19'), '2026-10-16', 3, [5], [], []));
});

$test('Start-only and end-only entries are incomplete, including midnight', static function () use ($detector, $date, $entry, $assertSame, $weekdays): void {
	$assertSame([
		['date' => '2026-10-12', 'reason' => 'incomplete'],
		['date' => '2026-10-13', 'reason' => 'incomplete'],
	], $detector->findMissingEntries($date('2026-10-14'), '2026-10-12', 1, $weekdays, [
		$entry('2026-10-12', 0, null), $entry('2026-10-13', null, 0),
	], []));
});

$test('Both complete midnight values count as a complete entry', static function () use ($detector, $date, $entry, $assertSame, $weekdays): void {
	$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', 0, 0)], []));
});

// Whole-comment matching deliberately rejects synonyms, additions and negation.
foreach (['Urlaub', 'urlaub', ' URLAUB ', "\tUrlaub\n", 'Stundenausgleich', 'STUNDENAUSGLEICH', ' Stundenausgleich '] as $comment) {
	$test('Whole absence comment exempts a day: ' . json_encode($comment), static function () use ($detector, $date, $entry, $assertSame, $weekdays, $comment): void {
		$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
	});
}
foreach (['Vacation', ' VACATION ', 'Time off in lieu', ' time off in lieu '] as $comment) {
	$test('English absence comments exempt a whole day: ' . json_encode($comment), static function () use ($detector, $date, $entry, $assertSame, $weekdays, $comment): void {
		$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
	});
}
foreach (['Congés annuels', 'CONGÉS ANNUELS', 'Repos compensateur', '年次休暇', '代休'] as $comment) {
	$test('French and Japanese absence comments exempt a whole day: ' . json_encode($comment), static function () use ($detector, $date, $entry, $assertSame, $weekdays, $comment): void {
		$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
	});
}

// Every displayed term must be accepted identically by background checks.
$localizedTerms = json_decode(file_get_contents(__DIR__ . '/../../lib/Service/absence-comment-terms.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($localizedTerms as $language => $terms) {
	foreach ($terms as $kind => $term) {
		$test('Localized whole-day absence remains recognized: ' . $language . '/' . $kind, static function () use ($detector, $date, $entry, $assertSame, $weekdays, $term): void {
			foreach ([$term, mb_strtoupper($term, 'UTF-8'), mb_strtolower($term, 'UTF-8'), "\u{00A0}" . $term . "\u{3000}"] as $comment) {
				$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
			}
			foreach ([[0, null], [null, 0]] as [$startMinutes, $endMinutes]) {
				$assertSame([['date' => '2026-10-12', 'reason' => 'incomplete']], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', $startMinutes, $endMinutes, $term)], []));
			}
			foreach (['not ' . $term, $term . ' requested', $term . '.'] as $comment) {
				$assertSame([['date' => '2026-10-12', 'reason' => 'missing']], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
			}
		});
	}
}
$test('Language resolution uses account language, regional variants and English fallback', static function () use ($absenceComments, $assertSame): void {
	$assertSame(['vacation' => 'Vacation', 'timeOffInLieu' => 'Time off in lieu'], $absenceComments->getTerms('en'));
	$assertSame($absenceComments->getTerms('en_GB'), $absenceComments->getTerms('EN-gb'));
	$assertSame(['vacation' => 'Urlaub', 'timeOffInLieu' => 'Stundenausgleich'], $absenceComments->getTerms('de-DE'));
	$assertSame($absenceComments->getTerms('fr'), $absenceComments->getTerms('fr_CA'));
	$assertSame($absenceComments->getTerms('et_EE'), $absenceComments->getTerms('et'));
	$assertSame($absenceComments->getTerms('en'), $absenceComments->getTerms('unknown-LANGUAGE'));
	$assertSame($absenceComments->getTerms('en'), $absenceComments->getTerms(''));
});
$test('A changed viewer language cannot invalidate existing absence comments', static function () use ($absenceComments, $detector, $date, $entry, $assertSame, $weekdays): void {
	foreach (['de', 'en', 'fr', 'ja'] as $language) {
		$absenceComments->getTerms($language);
		foreach (['Urlaub', 'Stundenausgleich', 'Vacation', 'Time off in lieu', 'Congés annuels', '代休'] as $comment) {
			$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
		}
	}
});
$test('Canonical Unicode spellings share recognition without broadening the accepted words', static function () use ($absenceComments, $assertSame): void {
	$assertSame(true, $absenceComments->isExemptComment(Normalizer::normalize('Congés annuels', Normalizer::FORM_D)));
	$assertSame(false, $absenceComments->isExemptComment('Conges annuels'));
	$assertSame(false, $absenceComments->isExemptComment('Time  off in lieu'));
	$assertSame(false, $absenceComments->isExemptComment("Vacation\xff"));
});
foreach ([null, '', '  ', 'A', 'Freizeit', 'Auszeit', 'Ausgleich', 'kein Urlaub', 'Urlaub beantragt', 'Urlaub.', 'Stundenausgleich beantragt', '0'] as $comment) {
	$test('Other comments remain missing: ' . json_encode($comment), static function () use ($detector, $date, $entry, $assertSame, $weekdays, $comment): void {
		$assertSame([['date' => '2026-10-12', 'reason' => 'missing']], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', null, null, $comment)], []));
	});
}
foreach (['Urlaub', 'Stundenausgleich'] as $comment) {
	foreach ([[480, null], [null, 960]] as [$startMin, $endMin]) {
		$test('An absence comment never hides a partially entered time', static function () use ($detector, $date, $entry, $assertSame, $weekdays, $comment, $startMin, $endMin): void {
			$assertSame([['date' => '2026-10-12', 'reason' => 'incomplete']], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12', $startMin, $endMin, $comment)], []));
		});
	}
}

$test('Configured weekend workdays are checked', static function () use ($detector, $date, $assertSame): void {
	$assertSame([
		['date' => '2026-10-17', 'reason' => 'missing'],
		['date' => '2026-10-18', 'reason' => 'missing'],
	], $detector->findMissingEntries($date('2026-10-19'), '2026-10-16', 1, [6, 7], [], []));
});

$test('A public holiday is excluded from an otherwise expected workday', static function () use ($detector, $date, $assertSame, $weekdays): void {
	$assertSame([], $detector->findMissingEntries($date('2026-12-28'), '2026-12-25', 1, $weekdays, [], ['2026-12-25']));
});

$test('A person without entries still has expected days', static function () use ($detector, $date, $assertSame, $weekdays): void {
	$assertSame([
		['date' => '2026-10-12', 'reason' => 'missing'],
		['date' => '2026-10-13', 'reason' => 'missing'],
		['date' => '2026-10-14', 'reason' => 'missing'],
		['date' => '2026-10-15', 'reason' => 'missing'],
		['date' => '2026-10-16', 'reason' => 'missing'],
	], $detector->findMissingEntries($date('2026-10-17'), '2026-10-12', 1, $weekdays, [], []));
});

$test('The chosen start excludes earlier gaps and future starts are empty', static function () use ($detector, $date, $assertSame, $weekdays): void {
	$assertSame([['date' => '2026-10-14', 'reason' => 'missing']], $detector->findMissingEntries($date('2026-10-15'), '2026-10-14', 1, $weekdays, [], []));
	$assertSame([], $detector->findMissingEntries($date('2026-10-15'), '2026-10-16', 1, $weekdays, [], []));
	$assertSame([], $detector->findMissingEntries($date('2026-10-15'), '2026-10-15', 1, $weekdays, [], []));
});

$test('Year boundaries and supplied holidays preserve chronological order', static function () use ($detector, $date, $assertSame, $everyDay): void {
	$assertSame([
		['date' => '2026-12-31', 'reason' => 'missing'],
		['date' => '2027-01-02', 'reason' => 'missing'],
	], $detector->findMissingEntries($date('2027-01-03'), '2026-12-31', 1, $everyDay, [], ['2027-01-01']));
});

$test('Leap day is included', static function () use ($detector, $date, $assertSame, $everyDay): void {
	$assertSame([
		['date' => '2024-02-28', 'reason' => 'missing'],
		['date' => '2024-02-29', 'reason' => 'missing'],
		['date' => '2024-03-01', 'reason' => 'missing'],
	], $detector->findMissingEntries($date('2024-03-02'), '2024-02-28', 1, $everyDay, [], []));
});

$test('Spring daylight-saving transition uses calendar days', static function () use ($detector, $date, $assertSame, $everyDay): void {
	$assertSame([
		['date' => '2026-03-28', 'reason' => 'missing'],
		['date' => '2026-03-29', 'reason' => 'missing'],
	], $detector->findMissingEntries($date('2026-03-30 00:01:00'), '2026-03-28', 1, $everyDay, [], []));
});

$test('Autumn daylight-saving transition uses calendar days', static function () use ($detector, $date, $assertSame, $everyDay): void {
	$assertSame([
		['date' => '2026-10-24', 'reason' => 'missing'],
		['date' => '2026-10-25', 'reason' => 'missing'],
	], $detector->findMissingEntries($date('2026-10-26 00:01:00'), '2026-10-24', 1, $everyDay, [], []));
});

$test('Filling a previously missing date removes it from the result', static function () use ($detector, $date, $entry, $assertSame, $weekdays): void {
	$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, $weekdays, [$entry('2026-10-12')], []));
});

$test('No requested weekdays means no expected entries', static function () use ($detector, $date, $assertSame): void {
	$assertSame([], $detector->findMissingEntries($date('2026-10-13'), '2026-10-12', 1, [], [], []));
});

$test('Evaluation does not change its inputs', static function () use ($detector, $date, $entry, $assertSame, $weekdays): void {
	$today = $date('2026-10-13 12:30:00');
	$entries = [$entry('2026-10-12', 480, null, 'Urlaub')];
	$originalEntries = $entries;
	$detector->findMissingEntries($today, '2026-10-12', 1, $weekdays, $entries, []);
	$assertSame($originalEntries, $entries);
	$assertSame('2026-10-13 12:30:00', $today->format('Y-m-d H:i:s'));
});

foreach (['', '2026-02-30', '2026-2-01', '2026-10-12 extra', "2026-10-12\0"] as $invalidStart) {
	$test('Invalid start dates are rejected: ' . json_encode($invalidStart), static function () use ($detector, $date, $weekdays, $invalidStart): void {
		try {
			$detector->findMissingEntries($date('2026-10-13'), $invalidStart, 1, $weekdays, [], []);
		} catch (InvalidArgumentException) {
			return;
		}
		throw new RuntimeException('Expected an InvalidArgumentException.');
	});
}
foreach ([0, 366] as $invalidDelay) {
	$test('An invalid delay is rejected', static function () use ($detector, $date, $weekdays, $invalidDelay): void {
		try {
			$detector->findMissingEntries($date('2026-10-13'), '2026-10-12', $invalidDelay, $weekdays, [], []);
		} catch (InvalidArgumentException) {
			return;
		}
		throw new RuntimeException('Expected an InvalidArgumentException.');
	});
}

fwrite(STDOUT, sprintf("Missing-entry detector: %d checks, %d failures.\n", $testCount, $failureCount));
exit($failureCount === 0 ? 0 : 1);
