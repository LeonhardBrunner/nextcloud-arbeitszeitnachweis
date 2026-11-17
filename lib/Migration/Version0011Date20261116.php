<?php

declare(strict_types=1);

namespace OCA\Timesheet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0011Date20261116 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('ts_entries');
		if (!$table->hasColumn('comment_type')) {
			// Null marks existing entries whose exemption was based on the stored comment.
			$table->addColumn('comment_type', Types::STRING, ['length' => 20, 'notnull' => false]);
		}
		return $schema;
	}
}
