<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ArelleIxbrlValidator.php';

(new GeneratedServiceClassTestHarness())->run(
    ArelleIxbrlValidator::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(ArelleIxbrlValidator::class, 'reports not configured when config is missing', static function () use ($harness): void {
            $fixture = arelleValidatorFixture();
            $validator = new ArelleIxbrlValidator($fixture['missing_config'], $fixture['root']);
            $result = $validator->validate($fixture['ixbrl']);

            $harness->assertSame('not_configured', $result['status'] ?? '');
        });

        $harness->check(ArelleIxbrlValidator::class, 'passes when command exits successfully', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('success');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $result = $validator->validate($fixture['ixbrl']);

            $harness->assertSame(true, $result['ok'] ?? false);
            $harness->assertSame('passed', $result['status'] ?? '');
            $harness->assertTrue(is_file((string)($result['log_path'] ?? '')));
        });

        $harness->check(ArelleIxbrlValidator::class, 'fails when command reports validation errors', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('failure');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $result = $validator->validate($fixture['ixbrl']);

            $harness->assertSame(false, $result['ok'] ?? true);
            $harness->assertSame('failed', $result['status'] ?? '');
            $harness->assertTrue(count((array)($result['errors'] ?? [])) > 0);
        });
    }
);

function arelleValidatorFixture(string $mode = 'success'): array
{
    $root = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'arelle_' . $mode . '_' . bin2hex(random_bytes(3));
    $logs = $root . DIRECTORY_SEPARATOR . 'logs';
    mkdir($logs, 0775, true);
    $ixbrl = $root . DIRECTORY_SEPARATOR . 'sample.xhtml';
    file_put_contents($ixbrl, '<html xmlns="http://www.w3.org/1999/xhtml"><body>sample</body></html>');

    $cmd = $root . DIRECTORY_SEPARATOR . 'fake_arelle.bat';
    $body = $mode === 'failure'
        ? "@echo off\r\necho ERROR validation failed\r\nexit /b 1\r\n"
        : "@echo off\r\necho validation passed\r\nexit /b 0\r\n";
    file_put_contents($cmd, $body);

    $config = $root . DIRECTORY_SEPARATOR . 'arelle.config.php';
    file_put_contents(
        $config,
        '<?php return ' . var_export([
            'enabled' => true,
            'arelle_cmd' => $cmd,
            'timeout_seconds' => 5,
            'logs_path' => $logs,
            'packages' => [],
            'flags' => ['--validate'],
        ], true) . ';'
    );

    return [
        'root' => $root,
        'logs' => $logs,
        'ixbrl' => $ixbrl,
        'config' => $config,
        'missing_config' => $root . DIRECTORY_SEPARATOR . 'missing.config.php',
    ];
}
