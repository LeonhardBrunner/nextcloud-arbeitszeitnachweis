<?php

declare(strict_types=1);

use Nextcloud\CodingStandard\Config;

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/vendor-bin/cs-fixer/vendor/autoload.php';

$config = new Config();
$config->setCacheFile($projectRoot . '/.php-cs-fixer.cache');
$config->getFinder()
	->notPath('build')
	->notPath('l10n')
	->notPath('node_modules')
	->notPath('vendor')
	->notPath('vendor-bin')
	->in($projectRoot);

return $config;
