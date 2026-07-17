<?php
/**
 * Example local Arelle configuration.
 *
 * Copy to arelle.config.php or run third_party/arelle/bin/install_arelle.bat.
 * Keep arelle.config.php local; it may contain machine-specific paths.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'enabled' => true,
    'arelle_cmd' => $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'arelleCmdLine.exe',
    'timeout_seconds' => 180,
    'logs_path' => $root . DIRECTORY_SEPARATOR . 'logs',
    'cache_path' => $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache',
    'packages' => [$root . DIRECTORY_SEPARATOR . 'taxonomies'],
    'offline' => true,
    'flags' => ['--validate', '--validationExitCode'],
];
