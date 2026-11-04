<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OCA\Timesheet\Db\Entry;

/** Compares stored input values without changing time calculations or historical pause values. */
class HistoryChangeSet {
	public static function entry(Entry $entry): array {
		return [
			'workDate' => (string)$entry->getWorkDate(),
			'startMin' => $entry->getStartMin() === null ? null : (int)$entry->getStartMin(),
			'endMin' => $entry->getEndMin() === null ? null : (int)$entry->getEndMin(),
			'breakMinutes' => (int)$entry->getBreakMinutes(),
			'comment' => (string)($entry->getComment() ?? ''),
		];
	}

	public static function settings(array $settings): array {
		$state = trim((string)($settings['state'] ?? ''));
		return [
			'dailyMin' => isset($settings['dailyMin']) ? (int)$settings['dailyMin'] : null,
			'state' => $state === 'null' ? '' : $state,
		];
	}

	/** Comment filters also recognize older entry events without rewriting stored history. */
	public static function hasCommentChange(array $record): bool {
		if (!in_array($record['kind'], ['created', 'updated'], true)) {
			return false;
		}
		foreach ($record['changes'] as $change) {
			if ($change['field'] === 'comment' && (string)($change['before'] ?? '') !== (string)($change['after'] ?? '')) {
				return true;
			}
		}
		return false;
	}

	public static function between(?array $before, ?array $after): array {
		$changes = [];
		foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $field) {
			$oldValue = $before[$field] ?? null;
			$newValue = $after[$field] ?? null;
			// Creation and deletion keep the complete input snapshot, including empty fields.
			if ($before === null || $after === null || $oldValue !== $newValue) {
				$changes[] = ['field' => $field, 'before' => $oldValue, 'after' => $newValue];
			}
		}
		return $changes;
	}
}
