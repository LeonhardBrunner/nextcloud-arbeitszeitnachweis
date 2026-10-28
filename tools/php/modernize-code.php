<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$projectRoot = dirname(__DIR__, 2);

return RectorConfig::configure()
	->withPaths([
		$projectRoot . '/lib',
		$projectRoot . '/tests',
	])
	->withPhpSets(php82: true);
