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

        $harness->check(ArelleIxbrlValidator::class, 'treats bracketed severities and tracebacks as failures even with exit code zero', static function () use ($harness): void {
            foreach (['bracketed_exception', 'bracketed_error', 'bracketed_critical', 'traceback'] as $mode) {
                $fixture = arelleValidatorFixture($mode);
                $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
                $result = $validator->validate($fixture['ixbrl']);

                $harness->assertSame(false, $result['ok'] ?? true);
                $harness->assertSame('failed', $result['status'] ?? '');
                $harness->assertTrue(count((array)($result['errors'] ?? [])) > 0);
            }
        });

        $harness->check(ArelleIxbrlValidator::class, 'uses project-local package cache and offline flags', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('success');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $result = $validator->validate($fixture['ixbrl']);
            $log = (string)file_get_contents((string)($result['log_path'] ?? ''));

            $harness->assertTrue(str_contains($log, '--cacheDirectory'));
            $harness->assertTrue(str_contains($log, '--internetConnectivity=offline'));
            $harness->assertTrue(str_contains($log, '--validationExitCode'));
            $harness->assertTrue(str_contains($log, '--package'));
            $harness->assertTrue(str_contains($log, 'test-taxonomy.zip'));
        });
    }
);

function arelleValidatorFixture(string $mode = 'success'): array
{
    $root = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'arelle_' . $mode . '_' . bin2hex(random_bytes(3));
    $logs = $root . DIRECTORY_SEPARATOR . 'logs';
    $cache = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache';
    $taxonomies = $root . DIRECTORY_SEPARATOR . 'taxonomies';
    mkdir($logs, 0775, true);
    mkdir($cache, 0775, true);
    mkdir($taxonomies, 0775, true);
    file_put_contents($taxonomies . DIRECTORY_SEPARATOR . 'test-taxonomy.zip', 'test package');
    $ixbrl = $root . DIRECTORY_SEPARATOR . 'sample.xhtml';
    file_put_contents($ixbrl, '<html xmlns="http://www.w3.org/1999/xhtml"><body>sample</body></html>');

    $cmd = $root . DIRECTORY_SEPARATOR . 'fake_arelle.bat';
    $body = match ($mode) {
        'failure' => "@echo off\r\necho ERROR validation failed\r\nexit /b 1\r\n",
        'bracketed_exception' => "@echo off\r\necho [Exception] taxonomy load failed\r\nexit /b 0\r\n",
        'bracketed_error' => "@echo off\r\necho [ERROR] invalid fact\r\nexit /b 0\r\n",
        'bracketed_critical' => "@echo off\r\necho [critical] validation aborted\r\nexit /b 0\r\n",
        'traceback' => "@echo off\r\necho Traceback:\r\nexit /b 0\r\n",
        default => "@echo off\r\necho validation passed\r\nexit /b 0\r\n",
    };
    file_put_contents($cmd, $body);

    $config = $root . DIRECTORY_SEPARATOR . 'arelle.config.php';
    file_put_contents(
        $config,
        '<?php return ' . var_export([
            'enabled' => true,
            'arelle_cmd' => $cmd,
            'timeout_seconds' => 5,
            'logs_path' => $logs,
            'cache_path' => $cache,
            'packages' => [$taxonomies],
            'offline' => true,
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
