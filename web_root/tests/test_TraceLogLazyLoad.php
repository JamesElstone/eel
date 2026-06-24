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

$harness->check('logDetails', 'is available without eagerly loading the trace logger class', function () use ($harness): void {
    $command = [
        PHP_BINARY,
        '-r',
        implode(
            ' ',
            [
                'require ' . var_export(__DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php', true) . ';',
                '$ok = function_exists("logDetails") && !class_exists("TraceLogFramework", false);',
                'echo $ok ? "ok" : "fail";',
                'exit($ok ? 0 : 1);',
            ]
        ),
    ];

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, PROJECT_ROOT);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start isolated PHP process.');
    }

    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($process);

    if ($status !== 0 || trim((string)$output) !== 'ok') {
        throw new RuntimeException('Isolated lazy-load check failed: ' . trim((string)$output . ' ' . (string)$errorOutput));
    }

    $harness->assertTrue(true);
});
