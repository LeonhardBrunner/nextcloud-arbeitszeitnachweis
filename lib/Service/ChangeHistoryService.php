<?php

declare(strict_types=1);

namespace OCA\Timesheet\Service;

use OC\ForbiddenException;
use OCA\Timesheet\Db\ChangeHistoryStore;
use OCA\Timesheet\Db\ChangeHistoryAccountQueue;
use OCA\Timesheet\Db\ChangeHistoryRecordingStore;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/** Resolves current HR scope before interpreting filters or returning any historical data. */
class ChangeHistoryService {
	public const RETENTION_MONTHS = 12;

	public function __construct(
		private ChangeHistoryStore $history,
		private HrService $hrService,
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private ChangeHistoryAccountQueue $accountQueue,
		private ChangeHistoryRecordingStore $recording,
	) {
	}

	public function list(array $query): array {
		$userId = $this->userSession->getUser()?->getUID();
		if (!$userId || !$this->hrService->isHr($userId)) {
			throw new ForbiddenException();
		}
		$employees = $this->hrService->getAccessibleUsers($userId);
		$employeeIds = array_column($employees, 'id');
		$cutoff = $this->retentionCutoff();
		$actors = $this->history->actors($employeeIds, $cutoff);
		$pendingIds = $this->accountQueue->pendingAccountIds();
		if (array_intersect($pendingIds, [$userId, ...$employeeIds, ...array_column($actors, 'id')]) !== []) {
			throw new \RuntimeException('Account history cleanup is pending.');
		}
		$filters = $this->filters($query);
		if ($filters['employee'] !== '' && !in_array($filters['employee'], $employeeIds, true)) {
			throw new ForbiddenException();
		}
		if (($filters['actor'] !== '' && !in_array($filters['actor'], array_column($actors, 'id'), true))
			|| ($filters['actorDeleted'] && !in_array(null, array_column($actors, 'id'), true))) {
			throw new \InvalidArgumentException('Invalid history filter');
		}
		$limit = $this->positiveInteger($query['limit'] ?? 50, 50);
		$items = $this->history->find($employeeIds, $cutoff, $filters, $limit + 1);
		$hasMore = count($items) > $limit;
		$items = array_slice($items, 0, $limit);
		return [
			'items' => $items,
			'nextCursor' => $hasMore ? $items[count($items) - 1]['id'] : null,
			'recordingStartedAt' => $this->recording->getStartedAt(),
			'retentionMonths' => self::RETENTION_MONTHS,
			'filters' => ['employees' => $employees, 'actors' => $actors],
		];
	}

	public function retentionCutoff(): int {
		$now = \DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime('now', new \DateTimeZone('Europe/Berlin')));
		$targetMonth = $now->modify('first day of this month')->modify('-' . self::RETENTION_MONTHS . ' months');
		// Leap-day anniversaries stay in February instead of overflowing into March.
		return $targetMonth->setDate((int)$targetMonth->format('Y'), (int)$targetMonth->format('m'), min((int)$now->format('d'), (int)$targetMonth->format('t')))->getTimestamp();
	}

	private function filters(array $query): array {
		$zone = new \DateTimeZone('Europe/Berlin');
		$today = \DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime('today', $zone));
		$from = $this->date($query['from'] ?? $today->modify('-29 days')->format('Y-m-d'), $zone);
		$to = $this->date($query['to'] ?? $today->format('Y-m-d'), $zone);
		if ($from > $to) {
			throw new \InvalidArgumentException('Invalid history date range');
		}
		$employee = $this->identifier($query['employee'] ?? '');
		$actor = $this->identifier($query['actor'] ?? '');
		$actorDeleted = $query['actorDeleted'] ?? false;
		if (!in_array($actorDeleted, [false, true, 0, 1, '0', '1'], true)) {
			throw new \InvalidArgumentException('Invalid history filter');
		}
		$actorDeleted = in_array($actorDeleted, [true, 1, '1'], true);
		$kind = $this->identifier($query['kind'] ?? '');
		if (!in_array($kind, ['', 'created', 'updated', 'deleted', 'settings', 'comment'], true) || ($actorDeleted && $actor !== '')) {
			throw new \InvalidArgumentException('Invalid history filter');
		}
		$beforeId = isset($query['beforeId']) && $query['beforeId'] !== '' ? $this->positiveInteger($query['beforeId']) : null;
		return ['from' => $from->getTimestamp(), 'through' => $to->modify('+1 day')->getTimestamp(), 'employee' => $employee,
			'actor' => $actor, 'actorDeleted' => $actorDeleted, 'kind' => $kind, 'beforeId' => $beforeId];
	}

	private function date(mixed $value, \DateTimeZone $zone): \DateTimeImmutable {
		if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
			throw new \InvalidArgumentException('Invalid history date');
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
		if (!$date || $date->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException('Invalid history date');
		}
		return $date;
	}

	private function identifier(mixed $value): string {
		if (!is_string($value) || strlen($value) > 255) {
			throw new \InvalidArgumentException('Invalid history filter');
		}
		return trim($value);
	}

	private function positiveInteger(mixed $value, int $maximum = PHP_INT_MAX): int {
		if ((!is_int($value) && !is_string($value)) || preg_match('/\A[1-9]\d*\z/', (string)$value) !== 1
			|| filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value > $maximum) {
			throw new \InvalidArgumentException('Invalid history page');
		}
		return (int)$value;
	}
}
