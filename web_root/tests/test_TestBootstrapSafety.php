<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check('TestBootstrapSafety', 'blocks a production configuration loaded before the test bootstrap', static function () use ($harness): void {
    $script = 'define("APP_CONFIG", ' . var_export(PROJECT_ROOT . 'secure' . DIRECTORY_SEPARATOR, true) . ');'
        . ' require ' . var_export(__DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestBootstrap.php', true) . ';';
    $result = testBootstrapSafetyRunPhp($script);

    $harness->assertTrue($result['exit_code'] !== 0);
    $harness->assertTrue(str_contains($result['output'], 'Unsafe test bootstrap blocked'));
    $harness->assertTrue(str_contains($result['output'], 'non-test configuration directory'));
});

$harness->check('TestBootstrapSafety', 'blocks an already initialised non-SQLite database driver', static function () use ($harness): void {
    $script = 'define("APP_CONFIG", '
        . var_export(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR, true)
        . '); final class InterfaceDB { public static function driverName(): string { return "mysql"; } }'
        . ' require ' . var_export(__DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestBootstrap.php', true) . ';';
    $result = testBootstrapSafetyRunPhp($script);

    $harness->assertTrue($result['exit_code'] !== 0);
    $harness->assertTrue(str_contains($result['output'], 'Unsafe test bootstrap blocked'));
    $harness->assertTrue(str_contains($result['output'], 'non-test driver'));
});

$harness->check('TestBootstrapSafety', 'allows the isolated in-memory SQLite test configuration', static function () use ($harness): void {
    $script = 'require ' . var_export(__DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestBootstrap.php', true) . ';'
        . ' echo APP_CONFIG;';
    $result = testBootstrapSafetyRunPhp($script);

    $harness->assertSame(0, $result['exit_code']);
    $harness->assertTrue(str_contains($result['output'], 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'config'));
});

/**
 * @return array{exit_code: int, output: string}
 */
function testBootstrapSafetyRunPhp(string $script): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-r', $script],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        PROJECT_ROOT
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the isolated PHP safety-check process.');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'output' => (string)$stdout . (string)$stderr,
    ];
}
