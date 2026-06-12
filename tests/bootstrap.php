<?php

$autoloadPath = dirname(__DIR__).'/vendor/autoload.php';

require $autoloadPath;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Palliis\\SharedHostingObservabilityBundle\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});
