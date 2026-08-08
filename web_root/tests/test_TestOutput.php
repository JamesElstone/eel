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

/**
 * @param list<string> $statements
 * @return array{result: array<string, mixed>, exit_code: int, stderr: string}
 */
$runIsolatedTestOutput = static function (array $statements): array {
    $harnessPath = __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
    $script = implode(' ', [
        'require ' . var_export($harnessPath, true) . ';',
        ...$statements,
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

    return [
        'result' => $payload['all'] ?? [],
        'exit_code' => $exitCode,
        'stderr' => (string)$errorOutput,
    ];
};

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

$harness->check('TestOutput', 'excludes categorized skips from passed tests', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("SkipAccountingExample", "passes", static function (): void {});',
        '$harness->check("SkipAccountingExample", "skips", static function () use ($harness): void {',
        '$harness->skip("database engine is unavailable", "DATABASE-Engine");',
        '});',
    ]);
    $result = $isolated['result'];
    $skippedTest = $result['classes'][0]['tests'][1] ?? [];

    $harness->assertSame(2, $result['summary']['total_tests'] ?? null);
    $harness->assertSame(1, $result['summary']['passed_tests'] ?? null);
    $harness->assertSame(0, $result['summary']['failed_tests'] ?? null);
    $harness->assertSame(1, $result['summary']['skipped_tests'] ?? null);
    $harness->assertSame('skip', $skippedTest['result'] ?? null);
    $harness->assertSame('database engine is unavailable', $skippedTest['diagnostic'] ?? null);
    $harness->assertSame('database-engine', $skippedTest['skip_category'] ?? null);
    $harness->assertSame(0, $isolated['exit_code']);
});

$harness->check('TestOutput', 'categorizes legacy skips as unclassified without failing non-strict runs', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("LegacySkipExample", "skips", static function () use ($harness): void {',
        '$harness->skip("legacy prerequisite is unavailable");',
        '});',
    ]);
    $result = $isolated['result'];
    $skippedTest = $result['classes'][0]['tests'][0] ?? [];

    $harness->assertSame('unclassified', $skippedTest['skip_category'] ?? null);
    $harness->assertSame(1, $result['summary']['unexpected_skipped_tests'] ?? null);
    $harness->assertSame('healthy', $result['health_status'] ?? null);
    $harness->assertSame(0, $isolated['exit_code']);
});

$harness->check('TestOutput', 'fails strict runs containing unclassified skips', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        'test_output_configure_skip_policy(true);',
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("StrictSkipExample", "skips", static function () use ($harness): void {',
        '$harness->skip("legacy prerequisite is unavailable");',
        '});',
    ]);
    $result = $isolated['result'];

    $harness->assertSame(1, $result['summary']['unexpected_skipped_tests'] ?? null);
    $harness->assertSame(0, $result['summary']['allowed_skipped_tests'] ?? null);
    $harness->assertSame('failing', $result['health_status'] ?? null);
    $harness->assertTrue($isolated['exit_code'] !== 0);
});

$harness->check('TestOutput', 'keeps strict runs healthy for explicitly allowed skips', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        'test_output_configure_skip_policy(true, ["DATABASE-Engine"]);',
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("AllowedSkipExample", "skips", static function () use ($harness): void {',
        '$harness->skip("database engine differs", "database-engine");',
        '});',
    ]);
    $result = $isolated['result'];

    $harness->assertSame(1, $result['summary']['allowed_skipped_tests'] ?? null);
    $harness->assertSame(0, $result['summary']['unexpected_skipped_tests'] ?? null);
    $harness->assertSame(0, $result['summary']['passed_tests'] ?? null);
    $harness->assertSame('healthy', $result['health_status'] ?? null);
    $harness->assertSame(0, $isolated['exit_code']);
});

$harness->check('TestOutput', 'honors repeated CLI skip category options', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        '$policy = test_output_parse_skip_policy_arguments([',
        '"focused-runner.php",',
        '"--strict-skips",',
        '"--allow-skip-category=database-engine",',
        '"--allow-skip-category=external-service",',
        ']);',
        'test_output_configure_skip_policy($policy["strict"], $policy["allowed_categories"]);',
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("RepeatedOptionExample", "database skip", static function () use ($harness): void {',
        '$harness->skip("database engine differs", "database-engine");',
        '});',
        '$harness->check("RepeatedOptionExample", "service skip", static function () use ($harness): void {',
        '$harness->skip("service is unavailable", "external-service");',
        '});',
    ]);
    $result = $isolated['result'];

    $harness->assertSame(2, $result['summary']['allowed_skipped_tests'] ?? null);
    $harness->assertSame(0, $result['summary']['unexpected_skipped_tests'] ?? null);
    $harness->assertSame('healthy', $result['health_status'] ?? null);
    $harness->assertSame(0, $isolated['exit_code']);
});

$harness->check('TestOutput', 'propagates categories through direct result and skip-line APIs', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        'test_output_result("DirectResult", "records a skip", "skip", "direct reason", "DIRECT_Category");',
        'test_output_skip_line("SkipLine: records a line skipped. line reason", "LINE-Category");',
    ]);
    $result = $isolated['result'];

    $harness->assertSame('direct reason', $result['classes'][0]['tests'][0]['diagnostic'] ?? null);
    $harness->assertSame('direct_category', $result['classes'][0]['tests'][0]['skip_category'] ?? null);
    $harness->assertSame('line reason', $result['classes'][1]['tests'][0]['diagnostic'] ?? null);
    $harness->assertSame('line-category', $result['classes'][1]['tests'][0]['skip_category'] ?? null);
    $harness->assertSame(2, $result['summary']['skipped_tests'] ?? null);
});

$harness->check('TestOutput', 'turns invalid skip categories into clear test failures', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("InvalidCategoryExample", "rejects spaces", static function () use ($harness): void {',
        '$harness->skip("invalid category", "database engine");',
        '});',
        '$harness->check("InvalidCategoryExample", "rejects empty values", static function () use ($harness): void {',
        '$harness->skip("invalid category", "");',
        '});',
    ]);
    $result = $isolated['result'];

    $harness->assertSame(2, $result['summary']['failed_tests'] ?? null);
    $harness->assertSame(0, $result['summary']['skipped_tests'] ?? null);
    $harness->assertTrue(str_contains($result['messages'][0]['message'] ?? '', 'Invalid skip category'));
    $harness->assertTrue(str_contains($result['messages'][1]['message'] ?? '', 'Invalid skip category'));
    $harness->assertTrue($isolated['exit_code'] !== 0);
});

$harness->check('TestOutput', 'rejects invalid CLI policy categories clearly', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        'try {',
        '$policy = test_output_parse_skip_policy_arguments(["runner.php", "--strict-skips", "--allow-skip-category=bad category"]);',
        'test_output_configure_skip_policy($policy["strict"], $policy["allowed_categories"]);',
        '} catch (Throwable $exception) {',
        'test_output_result("TestRunner", "configures skip policy", "fail", $exception->getMessage());',
        '}',
    ]);
    $result = $isolated['result'];

    $harness->assertSame(1, $result['summary']['failed_tests'] ?? null);
    $harness->assertTrue(str_contains($result['messages'][0]['message'] ?? '', 'Invalid skip category'));
    $harness->assertTrue($isolated['exit_code'] !== 0);
});

$harness->check('TestOutput', 'keeps actual failures fatal when skips are allowed', function () use ($harness, $runIsolatedTestOutput): void {
    $isolated = $runIsolatedTestOutput([
        'test_output_configure_skip_policy(true, ["database-engine"]);',
        '$harness = new GeneratedServiceClassTestHarness();',
        '$harness->check("FailureExample", "allowed skip", static function () use ($harness): void {',
        '$harness->skip("database engine differs", "database-engine");',
        '});',
        '$harness->check("FailureExample", "actual failure", static function () use ($harness): void {',
        '$harness->assertSame(1, 2);',
        '});',
    ]);
    $result = $isolated['result'];

    $harness->assertSame(1, $result['summary']['allowed_skipped_tests'] ?? null);
    $harness->assertSame(1, $result['summary']['failed_tests'] ?? null);
    $harness->assertSame('failing', $result['health_status'] ?? null);
    $harness->assertTrue($isolated['exit_code'] !== 0);
});
