<?php

/**
 * Standalone policy checks. Run with PHP directly, without a Nextcloud bootstrap.
 * The small test doubles expose only the public calls used by GroupRuleService.
 */
namespace OCP\AppFramework\Services {
	interface IAppConfig {
		public function getAppValueString(string $key): string;
	}
}

namespace OCP {
	interface IGroupManager {
		public function getUserGroupIds(object $user): array;
		public function groupExists(string $groupId): bool;
	}
	interface IUserManager {
		public function get(string $userId): ?object;
	}
	interface IUserSession {
		public function getUser(): ?object;
	}
}

namespace {
	use OCA\Timesheet\Service\GroupRuleService;
	use OCA\Timesheet\Service\MissingEntryReminderSettings;

	require_once __DIR__ . '/../../lib/Service/MissingEntryReminderSettings.php';
	require_once __DIR__ . '/../../lib/Service/GroupRuleService.php';

	$checks = 0;
	$expectSame = static function (mixed $expected, mixed $actual, string $description) use (&$checks): void {
		$checks++;
		if ($expected !== $actual) {
			throw new \RuntimeException($description . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
	};
	$expectInvalid = static function (mixed $settings, string $description) use (&$checks): void {
		$checks++;
		try {
			MissingEntryReminderSettings::validateForSave($settings, '2026-10-29');
		} catch (\InvalidArgumentException) {
			return;
		}
		throw new \RuntimeException($description . ': invalid settings were accepted');
	};

	// Legacy rules remain disabled and keep their familiar numeric time thresholds.
	$defaults = ['enabled' => false, 'graceDays' => 14, 'weekdays' => [1, 2, 3, 4, 5], 'startDate' => ''];
	$expectSame($defaults, MissingEntryReminderSettings::normalize(null), 'legacy defaults');
	$expectSame($defaults, MissingEntryReminderSettings::validateForSave(null, '2026-10-29'), 'legacy save');
	$activeSettings = ['enabled' => true, 'graceDays' => 7, 'weekdays' => [1, 3, 5], 'startDate' => '2026-10-01'];
	$expectSame($activeSettings, MissingEntryReminderSettings::normalize($activeSettings), 'active settings survive loading');
	$expectSame($activeSettings, MissingEntryReminderSettings::validateForSave($activeSettings, '2026-10-29'), 'active settings survive saving');
	$expectSame('2026-10-29', MissingEntryReminderSettings::validateForSave(['enabled' => true], '2026-10-29')['startDate'], 'first enable uses today');
	$expectSame('2026-10-01', MissingEntryReminderSettings::validateForSave(['enabled' => true], '2026-10-29', $activeSettings)['startDate'], 'omitted date preserves saved beginning');
	$expectSame([1, 3, 7], MissingEntryReminderSettings::validateForSave(['weekdays' => [7, 1, 3, 1]], '2026-10-29')['weekdays'], 'weekday duplicates and order are normalized');
	$expectSame('2024-02-29', MissingEntryReminderSettings::validateForSave(['startDate' => '2024-02-29'], '2026-10-29')['startDate'], 'leap date accepted');
	$expectSame(false, MissingEntryReminderSettings::normalize(array_replace($activeSettings, ['startDate' => 'invalid']))['enabled'], 'corrupt stored start disables reminders');
	$expectSame(false, MissingEntryReminderSettings::normalize(array_replace($activeSettings, ['graceDays' => null]))['enabled'], 'corrupt stored grace disables reminders');

	$invalidSettings = [
		'scalar policy' => 'enabled',
		'non-boolean enabled' => ['enabled' => 'true'],
		'null enabled' => ['enabled' => null],
		'zero grace' => ['graceDays' => 0],
		'grace above maximum' => ['graceDays' => 366],
		'fractional grace' => ['graceDays' => 7.5],
		'string grace' => ['graceDays' => '7'],
		'null grace' => ['graceDays' => null],
		'weekday zero' => ['weekdays' => [0]],
		'weekday eight' => ['weekdays' => [8]],
		'string weekday' => ['weekdays' => ['1']],
		'non-list weekdays' => ['weekdays' => ['monday' => 1]],
		'null weekdays' => ['weekdays' => null],
		'active empty weekdays' => ['enabled' => true, 'weekdays' => []],
		'active empty date' => ['enabled' => true, 'startDate' => ''],
		'null date' => ['startDate' => null],
		'invalid leap date' => ['startDate' => '2025-02-29'],
		'year zero' => ['startDate' => '0000-01-01'],
		'timestamp instead of date' => ['startDate' => '2026-10-29T00:00:00'],
	];
	foreach ($invalidSettings as $description => $settings) {
		$expectInvalid($settings, $description);
	}

	// The existing priority rule also selects reminder settings, including disabled winners.
	$appConfig = new class implements \OCP\AppFramework\Services\IAppConfig {
		public array $rules = [];
		public function getAppValueString(string $key): string {
			return json_encode($this->rules, JSON_THROW_ON_ERROR);
		}
	};
	$groupManager = new class implements \OCP\IGroupManager {
		public array $userGroups = ['alpha', 'beta'];
		public function getUserGroupIds(object $user): array {
			return $this->userGroups;
		}
		public function groupExists(string $groupId): bool {
			return in_array($groupId, ['alpha', 'beta', 'hr-alpha', 'hr-beta'], true);
		}
	};
	$userManager = new class implements \OCP\IUserManager {
		public function get(string $userId): ?object {
			return $userId === 'employee' ? (object)['id' => $userId] : null;
		}
	};
	$userSession = new class implements \OCP\IUserSession {
		public function getUser(): ?object {
			return null;
		}
	};
	$rules = new GroupRuleService($appConfig, $groupManager, $userManager, $userSession);
	$firstRule = ['id' => 'first', 'hrGroups' => ['hr-alpha'], 'userGroups' => ['alpha'], 'priority' => 1, 'maxHours' => 9, 'missingEntryReminders' => $activeSettings];
	$secondRule = ['id' => 'second', 'hrGroups' => ['hr-beta'], 'userGroups' => ['beta'], 'priority' => 2, 'maxHours' => 11, 'missingEntryReminders' => $activeSettings];
	$appConfig->rules = [$firstRule, $secondRule];
	$expectSame('first', $rules->getMissingEntryReminderPolicy('employee')['id'], 'lowest numeric priority wins');
	$expectSame(9.0, $rules->getEffectiveRules('employee')['maxHours'], 'time thresholds follow same winner');
	$expectSame(['breakShortMinutes', 'breakShortHours', 'breakLongMinutes', 'breakLongHours', 'maxHours'], array_keys($rules->getEffectiveRules('employee')), 'public time response has no reminder fields');
	$appConfig->rules[1]['priority'] = 1;
	$expectSame('first', $rules->getMissingEntryReminderPolicy('employee')['id'], 'equal priority preserves first stored rule');
	$appConfig->rules[0]['missingEntryReminders']['enabled'] = false;
	$expectSame(false, $rules->getMissingEntryReminderPolicy('employee')['missingEntryReminders']['enabled'], 'disabled winning policy does not fall through');
	$appConfig->rules[1]['priority'] = 0;
	$expectSame('second', $rules->getMissingEntryReminderPolicy('employee')['id'], 'higher-priority second rule can win');
	$expectSame(null, $rules->getMissingEntryReminderPolicy('missing-user'), 'nonexistent user has no policy');
	$groupManager->userGroups = [];
	$expectSame(null, $rules->getMissingEntryReminderPolicy('employee'), 'user without groups has no policy');
	$appConfig->rules = [['id' => 'legacy', 'userGroups' => ['alpha']]];
	$expectSame($defaults, $rules->getReminderRules()[0]['missingEntryReminders'], 'legacy rules are returned with disabled defaults');

	fwrite(STDOUT, 'Group policy: ' . $checks . " checks passed.\n");
}
