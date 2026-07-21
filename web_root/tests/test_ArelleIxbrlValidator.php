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
            $configuration = $validator->configurationStatus();
            $result = $validator->validate($fixture['ixbrl']);

            $harness->assertSame(false, $configuration['installed'] ?? true);
            $harness->assertSame('not_configured', $result['status'] ?? '');
        });

        $harness->check(ArelleIxbrlValidator::class, 'reports installation before validating an artifact', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('success');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $configuration = $validator->configurationStatus();

            $harness->assertSame(true, $configuration['installed'] ?? false);
            $harness->assertSame('installed', $configuration['status'] ?? '');
        });

        $harness->check(ArelleIxbrlValidator::class, 'passes when command exits successfully', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('success');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $result = $validator->validate($fixture['ixbrl']);

            $harness->assertSame(true, $result['ok'] ?? false);
            $harness->assertSame('passed', $result['status'] ?? '');
            $harness->assertSame('Arelle test 1.0', $result['version'] ?? '');
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
            $additionalPackage = $fixture['root'] . DIRECTORY_SEPARATOR . 'hmrc-ct.zip';
            file_put_contents($additionalPackage, 'verified HMRC package fixture');
            $result = $validator->validate($fixture['ixbrl'], [$additionalPackage]);
            $log = (string)file_get_contents((string)($result['log_path'] ?? ''));

            $harness->assertTrue(str_contains($log, '--cacheDirectory'));
            $harness->assertTrue(str_contains($log, '--internetConnectivity=offline'));
            $harness->assertTrue(str_contains($log, '--validationExitCode'));
            $harness->assertTrue(str_contains($log, '--package'));
            $harness->assertTrue(str_contains($log, 'test-taxonomy.zip'));
            $harness->assertTrue(str_contains($log, 'hmrc-ct.zip'));
        });

        $harness->check(ArelleIxbrlValidator::class, 'rejects missing or non-ZIP additional packages', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('success');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            foreach ([$fixture['root'] . DIRECTORY_SEPARATOR . 'missing.zip', 'https://www.hmrc.gov.uk/taxonomy.zip'] as $package) {
                $result = $validator->validate($fixture['ixbrl'], [$package]);
                $harness->assertSame(false, $result['ok'] ?? true);
                $harness->assertSame('error', $result['status'] ?? '');
            }
        });
    }
);

function arelleValidatorFixture(string $mode = 'success'): array
{
    $root = test_tmp_directory() . DIRECTORY_SEPARATOR . 'arelle_' . $mode . '_' . bin2hex(random_bytes(3));
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
    $body = "@echo off\r\nif \"%1\"==\"--version\" (\r\n  echo Arelle test 1.0\r\n  exit /b 0\r\n)\r\n" . match ($mode) {
        'failure' => "echo ERROR validation failed\r\nexit /b 1\r\n",
        'bracketed_exception' => "echo [Exception] taxonomy load failed\r\nexit /b 0\r\n",
        'bracketed_error' => "echo [ERROR] invalid fact\r\nexit /b 0\r\n",
        'bracketed_critical' => "echo [critical] validation aborted\r\nexit /b 0\r\n",
        'traceback' => "echo Traceback:\r\nexit /b 0\r\n",
        default => "echo validation passed\r\nexit /b 0\r\n",
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
