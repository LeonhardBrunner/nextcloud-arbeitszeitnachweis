<?php

declare(strict_types=1);

namespace OCA\Timesheet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0010Date20261112 extends SimpleMigrationStep {
	private const LEGACY_SETTINGS = [
		'mail_no_entry_override' => ['mail_no_entry_enabled', 'mail_no_entry_days', 'warn_no_entry_days', 14],
		'mail_overtime_override' => ['mail_overtime_enabled', 'mail_overtime_threshold_min', 'warn_overtime_threshold_min', 600],
		'mail_negative_override' => ['mail_negative_enabled', 'mail_negative_threshold_min', 'warn_negative_threshold_min', 600],
	];

	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('ts_user_config')) {
			$table = $schema->getTable('ts_user_config');
			foreach (array_keys(self::LEGACY_SETTINGS) as $column) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, Types::INTEGER, ['notnull' => false]);
				}
			}
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$schemaClosure()->hasTable('ts_user_config')) {
			return;
		}
		foreach (self::LEGACY_SETTINGS as $column => [$enabled, $mail, $warning, $default]) {
			// Freeze the legacy choice before a later change to the shared threshold.
			$update = $this->db->getQueryBuilder();
			$enabledColumn = $update->getColumnName($enabled);
			$mailColumn = $update->getColumnName($mail);
			$warningColumn = $update->getColumnName($warning);
			$legacyOverride = $update->createFunction(
				"CASE WHEN $enabledColumn = 1 AND COALESCE($mailColumn, $default) <> COALESCE($warningColumn, $default) THEN 1 ELSE 0 END"
			);
			$update->update('ts_user_config')
				->set($column, $legacyOverride)
				->where($update->expr()->isNull($column))
				->executeStatement();
		}
	}
}
