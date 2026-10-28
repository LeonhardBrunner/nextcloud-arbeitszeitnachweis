<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$files = [];

foreach (['appinfo', 'lib', 'templates', 'tests', 'tools'] as $directory) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($projectRoot . '/' . $directory, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			$files[] = $file->getPathname();
		}
	}
}

sort($files);
$failures = 0;
foreach ($files as $filename) {
	$process = proc_open(
		[PHP_BINARY, '-l', $filename],
		[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		$projectRoot
	);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start the PHP syntax check.');
	}
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0) {
		$failures++;
		fwrite(STDERR, substr($filename, strlen($projectRoot) + 1) . PHP_EOL . $output . $errors);
	}
}

if ($failures > 0) {
	fwrite(STDERR, 'PHP syntax errors: ' . $failures . ' file(s).' . PHP_EOL);
	exit(1);
}

echo 'PHP syntax valid (' . count($files) . ' files).' . PHP_EOL;
