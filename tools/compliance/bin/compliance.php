<?php

/**
 * wp.org compliance engine, CLI entry point.
 *
 * php tools/compliance/bin/compliance.php --profile=wporg-free --path=dist/wpmcp
 *
 * Dev tooling: never shipped in a plugin build, and never loaded by
 * WordPress, so there is no ABSPATH guard here on purpose.
 */

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "compliance.php must be run from the command line\n");
    exit(2);
}

$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (! file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found; run composer install first\n");
    exit(2);
}
require_once $autoload;

$cwd = getcwd();
$cli = new WPMCP\Compliance\Cli(false === $cwd ? dirname(__DIR__, 3) : $cwd);
$result = $cli->run(array_slice($argv, 1));

fwrite(WPMCP\Compliance\Cli::EXIT_USAGE === $result['status'] ? STDERR : STDOUT, $result['output']);
exit($result['status']);
