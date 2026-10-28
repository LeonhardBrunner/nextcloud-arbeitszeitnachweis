<?php

namespace OCA\Timesheet\Controller;

use OCA\Timesheet\Db\EntryMapper;
use OCA\Timesheet\Service\EntryService;
use OCA\Timesheet\Service\HrService;
use OCA\Timesheet\Service\TimesheetXlsxExporter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OC\ForbiddenException;

class EntryController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private EntryMapper $entryMapper,
		private EntryService $entryService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private HrService $hrService,
		private TimesheetXlsxExporter $xlsxExporter,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(?string $from = null, ?string $to = null, ?string $user = null): DataResponse {
		$from ??= date('Y-m-01');
		$to ??= date('Y-m-t');

		$currentUserId = $this->userSession->getUser()->getUID();

		if ($user !== null && $this->hrService->isHr()) {
			$entries = $this->entryMapper->findByUserAndRange($user, $from, $to);
			return new DataResponse($entries);
		}

		$entries = $this->entryMapper->findByUserAndRange($currentUserId, $from, $to);
		return new DataResponse($entries);
	}

	#[NoAdminRequired]
	public function create(string $workDate, ?string $start = null, ?string $end = null, int $breakMinutes = 0, ?string $comment = null): DataResponse {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser) {
			return new DataResponse(['error' => 'Unauthorized'], 401);
		}
		$currentUserId = $currentUser->getUID();
		$targetUserId = $currentUserId;
		$userParameter = $this->request->getParam('user');
		if ($userParameter !== null && $this->hrService->isHr($currentUserId)) {
			$targetUserId = $userParameter;
		}

		if ($comment === null) {
			$commentParameter = $this->request->getParam('comment');
			if ($commentParameter !== null) {
				$comment = (string)$commentParameter;
			}
		}
		if ($start === null) {
			$startParameter = $this->request->getParam('start');
			if ($startParameter !== null) {
				$start = (string)$startParameter;
			}
		}
		if ($end === null) {
			$endParameter = $this->request->getParam('end');
			if ($endParameter !== null) {
				$end = (string)$endParameter;
			}
		}

		$commentOnly = ((int)$this->request->getParam('commentOnly', 0) === 1);
		$trimmedComment = trim((string)($comment ?? ''));

		$startString = trim((string)($start ?? ''));
		$endString = trim((string)($end ?? ''));

		$startMinutes = self::parseOptionalTimeMinutes($start);
		$endMinutes = self::parseOptionalTimeMinutes($end);

		if ($startString !== '' && $startMinutes === null) {
			return new DataResponse(['error' => 'Invalid start time'], 400);
		}
		if ($endString !== '' && $endMinutes === null) {
			return new DataResponse(['error' => 'Invalid end time'], 400);
		}

		$hasStart = ($startMinutes !== null);
		$hasEnd = ($endMinutes !== null);

		if ($commentOnly || (!$hasStart && !$hasEnd && $trimmedComment !== '')) {
			$entry = $this->entryService->upsertCommentOnly($targetUserId, $workDate, $trimmedComment);
			return new DataResponse($entry);
		}

		if (!$hasStart && !$hasEnd) {
			return new DataResponse(['error' => 'Time incomplete'], 400);
		}

		$payload = [
			'workDate' => $workDate,
			'startMin' => $startMinutes,
			'endMin' => $endMinutes,
			'breakMinutes' => $breakMinutes,
			'comment' => $comment,
		];

		try {
			$entry = $this->entryService->create($payload, $targetUserId);
		} catch (\DomainException $exception) {
			if ($exception->getCode() === 409) {
				return new DataResponse(['error' => 'Entry already exists'], 409);
			}
			throw $exception;
		}
		return new DataResponse($entry);
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $workDate = null, ?string $start = null, ?string $end = null, ?int $breakMinutes = null, ?string $comment = null): DataResponse {
		if ($comment === null) {
			$commentParameter = $this->request->getParam('comment');
			if ($commentParameter !== null) {
				$comment = (string)$commentParameter;
			}
		}
		if ($start === null) {
			$startParameter = $this->request->getParam('start');
			if ($startParameter !== null) {
				$start = (string)$startParameter;
			}
		}
		if ($end === null) {
			$endParameter = $this->request->getParam('end');
			if ($endParameter !== null) {
				$end = (string)$endParameter;
			}
		}
		if ($workDate === null) {
			$workDateParameter = $this->request->getParam('workDate');
			if ($workDateParameter !== null) {
				$workDate = (string)$workDateParameter;
			}
		}
		if ($breakMinutes === null) {
			$breakMinutesParameter = $this->request->getParam('breakMinutes');
			if ($breakMinutesParameter !== null && $breakMinutesParameter !== '') {
				$breakMinutes = (int)$breakMinutesParameter;
			}
		}

		$commentOnly = ((int)$this->request->getParam('commentOnly', 0) === 1);
		$trimmedComment = trim((string)($comment ?? ''));

		$changes = [];
		if ($workDate !== null) {
			$changes['workDate'] = $workDate;
		}

		// Comment-only updates explicitly clear times; omitted fields otherwise remain unchanged.
		if ($commentOnly) {
			$changes['startMin'] = null;
			$changes['endMin'] = null;
			$changes['breakMinutes'] = 0;
			$changes['comment'] = $trimmedComment;
			return $this->updateEntryWithConflictHandling($id, $changes);
		}

		if ($start !== null) {
			$trimmedStart = trim((string)$start);
			if ($trimmedStart === '') {
				$changes['startMin'] = null;
			} else {
				$startMinutes = self::parseOptionalTimeMinutes($trimmedStart);
				if ($startMinutes === null) {
					return new DataResponse(['error' => 'Invalid start time'], 400);
				}
				$changes['startMin'] = $startMinutes;
			}
		}
		if ($end !== null) {
			$trimmedEnd = trim((string)$end);
			if ($trimmedEnd === '') {
				$changes['endMin'] = null;
			} else {
				$endMinutes = self::parseOptionalTimeMinutes($trimmedEnd);
				if ($endMinutes === null) {
					return new DataResponse(['error' => 'Invalid end time'], 400);
				}
				$changes['endMin'] = $endMinutes;
			}
		}
		if ($breakMinutes !== null) {
			$changes['breakMinutes'] = $breakMinutes;
		}
		if ($comment !== null) {
			$changes['comment'] = $comment;
		}

		return $this->updateEntryWithConflictHandling($id, $changes);
	}

	private function updateEntryWithConflictHandling(int $id, array $changes): DataResponse {
		try {
			$entry = $this->entryService->update($id, $changes, $this->hrService->isHr());
		} catch (\DomainException $exception) {
			if ($exception->getCode() === 409) {
				return new DataResponse(['error' => 'Entry already exists'], 409);
			}
			throw $exception;
		}
		return new DataResponse($entry);
	}

	#[NoAdminRequired]
	public function delete(int $id): DataResponse {
		$this->entryService->delete($id, $this->hrService->isHr());
		return new DataResponse(['ok' => true]);
	}

	private static function parseOptionalTimeMinutes(?string $timeString): ?int {
		$timeString = trim((string)($timeString ?? ''));
		if ($timeString === '') {
			return null;
		}

		if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $timeString, $matches)) {
			return null;
		}

		return ((int)$matches[1]) * 60 + ((int)$matches[2]);
	}

	private static function parseTimeMinutes(string $timeString): int {
		$minutes = self::parseOptionalTimeMinutes($timeString);
		if ($minutes === null) {
			throw new \InvalidArgumentException('Invalid time format: ' . $timeString);
		}
		return $minutes;
	}

	private static function parseMonthStart(?string $monthString, \DateTimeZone $timeZone): \DateTimeImmutable {
		$normalizedMonth = trim((string)($monthString ?? ''));
		if ($normalizedMonth === '') {
			$normalizedMonth = (new \DateTimeImmutable('now', $timeZone))->format('Y-m');
		}

		$monthStart = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalizedMonth . '-01', $timeZone);
		if (!$monthStart) {
			$monthStart = new \DateTimeImmutable('first day of this month', $timeZone);
			$monthStart = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), 1);
		}

		return $monthStart->setTime(0, 0, 0);
	}

	private function resolveUserLocale(?string $override = null): string {
		$locale = trim((string)($override ?? ''));

		if ($locale === '') {
			if (method_exists($this->l10n, 'getLocaleCode')) {
				$locale = (string)$this->l10n->getLocaleCode();
			} elseif (method_exists($this->l10n, 'getLanguageCode')) {
				$locale = (string)$this->l10n->getLanguageCode();
			}
		}

		if ($locale === '') {
			$acceptLanguage = (string)$this->request->getHeader('Accept-Language');
			if ($acceptLanguage !== '') {
				$firstLanguage = trim(explode(',', $acceptLanguage)[0]);
				$locale = trim(explode(';', $firstLanguage)[0]);
			}
		}

		$locale = str_replace('-', '_', $locale);
		return $locale !== '' ? $locale : 'en';
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportXlsx(?string $from = null, ?string $to = null, ?string $user = null): DataDownloadResponse {
		$timeZone = new \DateTimeZone('Europe/Berlin');

		$currentUser = $this->userSession->getUser();
		if (!$currentUser) {
			throw new ForbiddenException();
		}
		$currentUserId = $currentUser->getUID();

		$targetUserId = $currentUserId;
		if ($user !== null && $user !== '') {
			if (!$this->hrService->isHr()) {
				throw new ForbiddenException();
			}
			$targetUserId = $user;
		}

		// The legacy month parameter is used only when both range endpoints are empty.
		if (trim((string)($from ?? '')) === '' && trim((string)($to ?? '')) == '') {
			$legacyMonth = $this->request->getParam('month');
			if (is_string($legacyMonth) && trim($legacyMonth) !== '') {
				$from = $legacyMonth;
				$to = $legacyMonth;
			}
		}
		if (trim((string)($from ?? '')) === '' && trim((string)($to ?? '')) !== '') {
			$from = trim((string)$to);
		}
		if (trim((string)($to ?? '')) === '' && trim((string)($from ?? '')) !== '') {
			$to = trim((string)$from);
		}

		$fromMonth = self::parseMonthStart($from, $timeZone);
		$toMonth = self::parseMonthStart($to, $timeZone);
		if ($fromMonth > $toMonth) {
			[$fromMonth, $toMonth] = [$toMonth, $fromMonth];
		}

		$locale = $this->resolveUserLocale($this->request->getParam('locale'));
		$download = $this->xlsxExporter->export($targetUserId, $fromMonth, $toMonth, $locale, $this->l10n);

		return new DataDownloadResponse(
			$download['content'],
			$download['fileName'],
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}
}
