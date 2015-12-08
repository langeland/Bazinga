#!/usr/bin/env php
<?php

if (PHP_VERSION_ID < 50400) {
	file_put_contents('php://stderr', sprintf(
		"Bazinga Installer requires PHP 5.4 version or higher and your system has\n" .
		"PHP %s version installed.\n\n" .
		"To solve this issue, upgrade your PHP installation or install Symfony manually\n" .
		"executing the following command:\n\n" .
		"composer create-project symfony/framework-standard-edition <project-name> <symfony-version>\n\n",
		PHP_VERSION
	));

	exit(1);
}

if (file_exists(__DIR__ . '/Libraries/autoload.php')) {
	require __DIR__ . '/Libraries/autoload.php';
} else {
	echo 'Missing autoload.php, update by the composer.' . PHP_EOL;
	exit(2);
}

if (is_dir(__DIR__ . '/.git')) {
	exec('git --git-dir=' . __DIR__ . '/.git rev-parse --verify HEAD 2> /dev/null', $output);
	$appVersion = substr($output[0], 0, 10);
} else {
	$appVersion = '1.1.8-DEV';
}

// Windows uses Path instead of PATH
if (!isset($_SERVER['PATH']) && isset($_SERVER['Path'])) {
	$_SERVER['PATH'] = $_SERVER['Path'];
}

$app = new Symfony\Component\Console\Application('Bazinga Installer', $appVersion);
$app->add(new Langeland\Bazinga\Command\CreateCommand());
$app->add(new Langeland\Bazinga\Command\DemoCommand());


$app->run();
