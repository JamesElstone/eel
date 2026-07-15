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

$harness->check('TestOutput', 'reports multiline assertion failures accurately', function () use ($harness): void {
    $harnessPath = __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
    $script = implode(' ', [
        'require ' . var_export($harnessPath, true) . ';',
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("MultilineAssertionExample", "compares multiline values", static function () use ($harness): void {',
        '$harness->assertSame("expected\\nsecond line", "actual\\nsecond line");',
        '});',
        'test_output_render();',
        'exit(($GLOBALS["test_output_state"]["summary"]["status"] ?? "healthy") === "healthy" ? 0 : 1);',
    ]);

    $process = proc_open(
        [PHP_BINARY, '-r', $script],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        PROJECT_ROOT
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start isolated test-output process.');
    }

    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $payload = json_decode((string)$output, true);

    if (!is_array($payload)) {
        throw new RuntimeException('Isolated test-output process returned invalid JSON: ' . $errorOutput);
    }

    $result = $payload['all'] ?? [];
    $diagnostic = 'Assertion failed. Expected '
        . var_export("expected\nsecond line", true)
        . ' but received '
        . var_export("actual\nsecond line", true)
        . '.';

    $harness->assertSame(1, $result['summary']['failed_classes'] ?? null);
    $harness->assertSame(1, $result['summary']['failed_tests'] ?? null);
    $harness->assertSame('compares multiline values', $result['classes'][0]['tests'][0]['name'] ?? null);
    $harness->assertSame(
        'MultilineAssertionExample: compares multiline values failed. ' . $diagnostic,
        $result['messages'][0]['message'] ?? null
    );
    $harness->assertTrue($exitCode !== 0);
});
