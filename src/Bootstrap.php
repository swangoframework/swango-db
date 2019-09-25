<?php

/**
 * Bootstrap for Swango\Db Gateway.
 */

// This sucks, but we have to try to find the composer autoloader
$paths = [
    __DIR__ . '/../../../autoload.php', // In case PhpSpreadsheet is a composer dependency.
    __DIR__ . '/../vendor/autoload.php' // In case PhpSpreadsheet is cloned directly
];

$loader = null;

foreach ($paths as $path)
    if (file_exists($path)) {
        $loader = include $path;
        break;
    }

if (! isset($loader))
    throw new \Exception(
        'Composer autoloader could not be found. Install dependencies with `composer install` and try again.');

if (defined('WORKING_MODE') && defined('WORKING_MODE_SWOOLE_COR') && WORKING_MODE === WORKING_MODE_SWOOLE_COR) {
    $loader->addClassMap([
        'Gateway' => __DIR__ . '/Gateway.php'
    ]);
} else {
    $loader->addClassMap([
        'Gateway' => __DIR__ . '/StaticGateway.php'
    ]);
}