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
            $validator = new ArelleIxbrlValidator([], $fixture['root']);
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

        $harness->check(ArelleIxbrlValidator::class, 'captures coded Arelle warnings followed by punctuation', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('bracketed_warning');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $result = $validator->validate($fixture['ixbrl']);

            $harness->assertSame(true, $result['ok'] ?? false);
            $harness->assertSame('passed', $result['status'] ?? '');
            $harness->assertSame(1, count((array)($result['warnings'] ?? [])));
            $harness->assertTrue(str_contains(
                (string)($result['warnings'][0] ?? ''),
                'ix11.8.1.2:headerDisplayNone'
            ));
        });

        $harness->check(ArelleIxbrlValidator::class, 'keeps unqualified jurisdictional rule codes as visible errors', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('jurisdictional_rule');
            $result = (new ArelleIxbrlValidator($fixture['config'], $fixture['root']))->validate($fixture['ixbrl']);

            $harness->assertFalse($result['ok'] ?? true);
            $harness->assertTrue(str_contains(implode(' ', (array)($result['errors'] ?? [])), '[HMRC.5.3]'));
        });

        $harness->check(ArelleIxbrlValidator::class, 'can ignore HMRC.TBD without suppressing other validation contexts', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('hmrc_tbd');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $unignored = $validator->validate($fixture['ixbrl']);
            $ignored = $validator->validate($fixture['ixbrl'], [], ['HMRC.TBD']);

            $harness->assertFalse($unignored['ok'] ?? true);
            $harness->assertTrue(str_contains(implode(' ', (array)($unignored['errors'] ?? [])), '[HMRC.TBD]'));
            $harness->assertTrue($ignored['ok'] ?? false);
            $harness->assertSame([], $ignored['errors'] ?? []);
            $harness->assertSame([], $ignored['error_diagnostics'] ?? []);
        });

        $harness->check(ArelleIxbrlValidator::class, 'parses detailed diagnostics from both streams and deduplicates exact repeats', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('success');
            $validator = new ArelleIxbrlValidator($fixture['config'], $fixture['root']);
            $method = new ReflectionMethod($validator, 'parseDiagnostics');
            $method->setAccessible(true);
            $result = $method->invoke(
                $validator,
                "[xbrldie:PrimaryItemDimensionallyInvalidError] Fact tax:TradingLossesOfThisOrLaterAP context Ctx1 has invalid dimensional context\r\n"
                . "[xmlSchema:SomeError] C:\\filing\\accounts.xhtml:12:7: Fact core:Turnover is invalid\n"
                . "[xbrl.5.2.5.2:calcInconsistency] Calculation inconsistency",
                "[xbrldie:PrimaryItemDimensionallyInvalidError] Fact tax:TradingLossesOfThisOrLaterAP context Ctx1 has invalid dimensional context\n"
                . "[SomeNamespace:SomeWarning] Warning, review /tmp/report.xhtml line 9, column 2"
            );
            $diagnostics = (array)($result['diagnostics'] ?? []);

            $harness->assertSame(4, count($diagnostics));
            $harness->assertSame('xbrldie:PrimaryItemDimensionallyInvalidError', $diagnostics[0]['code'] ?? '');
            $harness->assertSame('error', $diagnostics[0]['severity'] ?? '');
            $harness->assertSame('tax:TradingLossesOfThisOrLaterAP (context Ctx1)', $diagnostics[0]['fact_reference'] ?? '');
            $harness->assertSame('xmlSchema:SomeError', $diagnostics[1]['code'] ?? '');
            $harness->assertSame('C:\\filing\\accounts.xhtml', $diagnostics[1]['source_document'] ?? '');
            $harness->assertSame(12, $diagnostics[1]['line'] ?? 0);
            $harness->assertSame(7, $diagnostics[1]['column'] ?? 0);
            $harness->assertSame('error', $diagnostics[2]['severity'] ?? '');
            $harness->assertSame('warning', $diagnostics[3]['severity'] ?? '');
        });

        $harness->check(ArelleIxbrlValidator::class, 'returns detailed errors instead of an exit-code fallback', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('dimensional_error');
            $result = (new ArelleIxbrlValidator($fixture['config'], $fixture['root']))->validate($fixture['ixbrl']);
            $stderrFixture = arelleValidatorFixture('stderr_detailed_error');
            $stderrResult = (new ArelleIxbrlValidator($stderrFixture['config'], $stderrFixture['root']))->validate($stderrFixture['ixbrl']);

            $harness->assertSame(false, $result['ok'] ?? true);
            $harness->assertSame('failed', $result['status'] ?? '');
            $harness->assertSame(3, $result['exit_code'] ?? 0);
            $harness->assertSame(1, count((array)($result['error_diagnostics'] ?? [])));
            $harness->assertTrue(str_contains((string)($result['errors'][0] ?? ''), 'PrimaryItemDimensionallyInvalidError'));
            $harness->assertFalse(str_contains(implode(' ', (array)($result['errors'] ?? [])), 'Arelle exited with code 3.'));
            $harness->assertTrue(str_contains((string)($result['raw_stdout'] ?? ''), 'TradingLossesOfThisOrLaterAP'));
            $harness->assertSame('', $result['raw_stderr'] ?? 'not empty');
            $harness->assertSame('', $stderrResult['raw_stdout'] ?? 'not empty');
            $harness->assertSame('stderr', $stderrResult['error_diagnostics'][0]['stream'] ?? '');
        });

        $harness->check(ArelleIxbrlValidator::class, 'returns schema diagnostics with non-severity codes as detailed errors', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('lxml_schema_error');
            $result = (new ArelleIxbrlValidator($fixture['config'], $fixture['root']))->validate($fixture['ixbrl']);

            $harness->assertSame(false, $result['ok'] ?? true);
            $harness->assertSame('failed', $result['status'] ?? '');
            $harness->assertSame(1, count((array)($result['error_diagnostics'] ?? [])));
            $harness->assertSame('lxml.SCHEMAV_CVC_COMPLEX_TYPE_3_2_1', $result['error_diagnostics'][0]['code'] ?? '');
            $harness->assertSame('error', $result['error_diagnostics'][0]['severity'] ?? '');
            $harness->assertTrue(str_contains(
                (string)($result['errors'][0] ?? ''),
                'attribute \'data-revision-explanation\' is not allowed'
            ));
            $harness->assertFalse(str_contains(implode(' ', (array)($result['errors'] ?? [])), 'Arelle exited with code 3.'));
        });

        $harness->check(ArelleIxbrlValidator::class, 'keeps warnings successful and fails zero-exit detailed errors', static function () use ($harness): void {
            $warning = arelleValidatorFixture('warning_only');
            $warningResult = (new ArelleIxbrlValidator($warning['config'], $warning['root']))->validate($warning['ixbrl']);
            $error = arelleValidatorFixture('detailed_error_zero');
            $errorResult = (new ArelleIxbrlValidator($error['config'], $error['root']))->validate($error['ixbrl']);

            $harness->assertSame(true, $warningResult['ok'] ?? false);
            $harness->assertSame('passed', $warningResult['status'] ?? '');
            $harness->assertSame(1, count((array)($warningResult['warning_diagnostics'] ?? [])));
            $harness->assertSame(false, $errorResult['ok'] ?? true);
            $harness->assertSame('failed', $errorResult['status'] ?? '');
            $harness->assertSame(0, $errorResult['exit_code'] ?? -1);
        });

        $harness->check(ArelleIxbrlValidator::class, 'uses the exit-code message only when no diagnostic can be parsed', static function () use ($harness): void {
            $fixture = arelleValidatorFixture('unparsed_failure');
            $result = (new ArelleIxbrlValidator($fixture['config'], $fixture['root']))->validate($fixture['ixbrl']);

            $harness->assertSame(false, $result['ok'] ?? true);
            $harness->assertSame(['Arelle exited with code 3.'], $result['errors'] ?? []);
            $harness->assertSame([], $result['diagnostics'] ?? []);
            $harness->assertTrue(str_contains((string)($result['raw_stderr'] ?? ''), 'validation terminated unexpectedly'));
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
    $root = test_register_cleanup_path(
        test_tmp_directory() . DIRECTORY_SEPARATOR . 'arelle_' . $mode . '_' . bin2hex(random_bytes(3))
    );
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
        'bracketed_warning' => "echo [ix11.8.1.2:headerDisplayNone] Warning, ix:header display recommendation\r\nexit /b 0\r\n",
        'jurisdictional_rule' => "echo [HMRC.5.3] Numeric fact has a negative value without bracketed presentation\r\nexit /b 3\r\n",
        'hmrc_tbd' => "echo [HMRC.TBD] No recognized standard taxonomy (UK GAAP, UK IFRS, Charity, or FRS).\r\nexit /b 3\r\n",
        'dimensional_error' => "echo [xbrldie:PrimaryItemDimensionallyInvalidError] Fact tax:TradingLossesOfThisOrLaterAP context Ctx1 has invalid dimensional context\r\nexit /b 3\r\n",
        'warning_only' => "echo [SomeNamespace:SomeWarning] Warning, additional review suggested\r\nexit /b 0\r\n",
        'detailed_error_zero' => "echo [xmlSchema:SomeError] validation failed\r\nexit /b 0\r\n",
        'lxml_schema_error' => "echo [lxml.SCHEMAV_CVC_COMPLEX_TYPE_3_2_1] XML file syntax error Element '{http://www.w3.org/1999/xhtml}div', attribute 'data-revision-explanation': The attribute 'data-revision-explanation' is not allowed., line 201\r\nexit /b 3\r\n",
        'stderr_detailed_error' => "echo [xbrldie:PrimaryItemDimensionallyInvalidError] Fact tax:TradingLossesOfThisOrLaterAP context Ctx1 has invalid dimensional context 1>&2\r\nexit /b 3\r\n",
        'unparsed_failure' => "echo validation terminated unexpectedly 1>&2\r\nexit /b 3\r\n",
        default => "echo validation passed\r\nexit /b 0\r\n",
    };
    file_put_contents($cmd, $body);

    $config = [
        'enabled' => true,
        'arelle_cmd' => $cmd,
        'timeout_seconds' => 5,
        'logs_path' => $logs,
        'cache_path' => $cache,
        'packages' => [$taxonomies],
        'offline' => true,
        'flags' => ['--validate'],
    ];

    return [
        'root' => $root,
        'logs' => $logs,
        'ixbrl' => $ixbrl,
        'config' => $config,
    ];
}
