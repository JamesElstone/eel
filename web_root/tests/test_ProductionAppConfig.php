<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check('secure/app.php', 'parses and returns a configuration array when present', function () use ($harness): void {
    $configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'app.php';

    if (!is_file($configPath)) {
        $harness->skip('secure/app.php is not present.');
    }

    if (!is_readable($configPath)) {
        throw new RuntimeException('secure/app.php is not readable.');
    }

    ob_start();

    try {
        $config = require $configPath;
    } finally {
        $output = ob_get_clean();
    }

    $harness->assertSame('', $output);
    $harness->assertTrue(is_array($config));
    $harness->assertTrue(array_key_exists('navigation', $config));
    $harness->assertTrue(array_key_exists('hide_collapsed_link_initials', $config['navigation']));
    $harness->assertTrue(is_bool($config['navigation']['hide_collapsed_link_initials']));

    if (array_key_exists('uploads', $config)) {
        $harness->assertTrue(is_array($config['uploads']));
    }
});
