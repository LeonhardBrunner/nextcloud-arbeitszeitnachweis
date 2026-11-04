<?php

declare(strict_types=1);

namespace OCA\Timesheet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0009Date20261103 extends SimpleMigrationStep {
	public function __construct(private IConfig $config, private ITimeFactory $timeFactory) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_change_history')) {
			$table = $schema->createTable('ts_change_history');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addColumn('occurred_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('actor_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('actor_name', Types::TEXT, ['notnull' => true]);
			$table->addColumn('employee_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('employee_name', Types::TEXT, ['notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('entry_id', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('work_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$table->addColumn('changes', Types::TEXT, ['notnull' => true]);
			$table->addIndex(['employee_id', 'id'], 'ts_ch_employee_id');
			$table->addIndex(['actor_id', 'id'], 'ts_ch_actor_id');
			$table->addIndex(['occurred_at'], 'ts_ch_occurred');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->config->getAppValue('timesheet', 'change_history_started_at', '') === '') {
			$this->config->setAppValue('timesheet', 'change_history_started_at', (string)$this->timeFactory->getTime());
		}
	}
}
