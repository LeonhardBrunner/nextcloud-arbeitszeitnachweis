<?php

namespace OCA\Timesheet\Db;

use DateTimeImmutable;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;

/** Stores enrollment and delivery state without changing recorded working times. */
class MissingEntryReminderStore {
	private const APP_ID = 'timesheet';
	private const ENROLLMENTS_KEY = 'missing_entry_enrollments';
	private const REPEAT_DAYS = 7;

	public function __construct(
		private IConfig $userConfig,
		private IAppConfig $appConfig,
	) {
	}

	public function isRuleInitialized(string $ruleId): bool {
		return $this->appConfig->getAppValueString($this->ruleStateKey($ruleId), '0') === '1';
	}

	public function markRuleInitialized(string $ruleId): void {
		$this->appConfig->setAppValueString($this->ruleStateKey($ruleId), '1');
	}

	/** @return array{initialCohort:bool,firstDetectedOn:string} */
	public function rememberEnrollment(string $userId, string $ruleId, bool $initialCohort, string $today): array {
		$storedJson = $this->userConfig->getUserValue($userId, self::APP_ID, self::ENROLLMENTS_KEY, '{}');
		$enrollments = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($enrollments)) {
			throw new \UnexpectedValueException('Invalid missing-entry enrollment state.');
		}
		if (!array_key_exists($ruleId, $enrollments)) {
			$enrollments[$ruleId] = ['initialCohort' => $initialCohort, 'firstDetectedOn' => $today];
			$this->userConfig->setUserValue($userId, self::APP_ID, self::ENROLLMENTS_KEY, json_encode($enrollments, JSON_THROW_ON_ERROR));
		}
		$enrollment = $enrollments[$ruleId];
		if (!is_array($enrollment) || !is_bool($enrollment['initialCohort'] ?? null)
			|| !is_string($enrollment['firstDetectedOn'] ?? null)
			|| !preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrollment['firstDetectedOn'])
			|| !checkdate((int)substr($enrollment['firstDetectedOn'], 5, 2), (int)substr($enrollment['firstDetectedOn'], 8, 2), (int)substr($enrollment['firstDetectedOn'], 0, 4))) {
			throw new \UnexpectedValueException('Invalid missing-entry enrollment.');
		}
		return $enrollment;
	}

	public function isDeliveryDue(string $userId, string $deliveryKind, DateTimeImmutable $now): bool {
		$lastSent = $this->userConfig->getUserValue($userId, self::APP_ID, $this->deliveryKey($deliveryKind), '');
		if ($lastSent === '') {
			return true;
		}
		$lastSentAt = DateTimeImmutable::createFromFormat(\DATE_ATOM, $lastSent);
		if ($lastSentAt === false || $lastSentAt->format(\DATE_ATOM) !== $lastSent) {
			throw new \UnexpectedValueException('Invalid missing-entry delivery timestamp.');
		}
		return $now >= $lastSentAt->setTimezone($now->getTimezone())->modify('+' . self::REPEAT_DAYS . ' days');
	}

	public function markDelivered(string $userId, string $deliveryKind, DateTimeImmutable $now): void {
		$this->userConfig->setUserValue($userId, self::APP_ID, $this->deliveryKey($deliveryKind), $now->format(\DATE_ATOM));
	}

	private function ruleStateKey(string $ruleId): string {
		return 'missing_entry_rule_' . substr(hash('sha256', $ruleId), 0, 32);
	}

	private function deliveryKey(string $deliveryKind): string {
		if (!in_array($deliveryKind, ['employee', 'hr'], true)) {
			throw new \InvalidArgumentException('Unknown reminder delivery kind.');
		}
		return 'missing_entry_' . $deliveryKind . '_last_sent';
	}
}
