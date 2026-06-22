<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CLASSES . 'framework' . DIRECTORY_SEPARATOR . 'TraceLogFramework.php';

final class TraceLogFrameworkTestCaller
{
    public static function writeLine(): void
    {
        logDetails();
    }
}

$harness = new GeneratedServiceClassTestHarness();
$traceTestRoot = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'trace-log-framework';

$ensureTraceTestDirectory = static function (string $path): void {
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create trace test directory: ' . $path);
    }
};

$removeTraceFiles = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (glob($path . DIRECTORY_SEPARATOR . '*_trace.csv') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
};

$withTraceConfig = static function (mixed $logPath, callable $callback) use ($traceTestRoot): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        TraceLogFramework::resetForTests();
        AppConfigurationStore::set('trace.log_path', $logPath);
        $callback();
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
        TraceLogFramework::resetForTests();

        foreach (glob($traceTestRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
            if (is_dir($child)) {
                foreach (glob($child . DIRECTORY_SEPARATOR . '*_trace.csv') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($child);
            }
        }
    }
};

$harness->check(TraceLogFramework::class, 'exits quickly when trace path is empty or null', function () use ($harness, $withTraceConfig, $ensureTraceTestDirectory, $removeTraceFiles, $traceTestRoot): void {
    $directory = $traceTestRoot . DIRECTORY_SEPARATOR . 'empty';
    $ensureTraceTestDirectory($directory);
    $removeTraceFiles($directory);

    $withTraceConfig('', function () use ($harness, $directory): void {
        TraceLogFrameworkTestCaller::writeLine();
        $harness->assertSame([], glob($directory . DIRECTORY_SEPARATOR . '*_trace.csv') ?: []);
    });

    $withTraceConfig(null, function () use ($harness, $directory): void {
        TraceLogFrameworkTestCaller::writeLine();
        $harness->assertSame([], glob($directory . DIRECTORY_SEPARATOR . '*_trace.csv') ?: []);
    });
});

$harness->check(TraceLogFramework::class, 'caches a missing trace directory as disabled', function () use ($harness, $withTraceConfig, $ensureTraceTestDirectory, $traceTestRoot): void {
    $directory = $traceTestRoot . DIRECTORY_SEPARATOR . 'missing-cache';
    $file = $directory . DIRECTORY_SEPARATOR . date('Y-m-d') . '_trace.csv';

    $withTraceConfig($directory, function () use ($harness, $ensureTraceTestDirectory, $directory, $file): void {
        TraceLogFrameworkTestCaller::writeLine();
        $ensureTraceTestDirectory($directory);
        TraceLogFrameworkTestCaller::writeLine();

        $harness->assertTrue(!is_file($file));
    });
});

$harness->check(TraceLogFramework::class, 'writes caller details to the daily trace file', function () use ($harness, $withTraceConfig, $ensureTraceTestDirectory, $traceTestRoot): void {
    $directory = $traceTestRoot . DIRECTORY_SEPARATOR . 'absolute';
    $file = $directory . DIRECTORY_SEPARATOR . date('Y-m-d') . '_trace.csv';
    $ensureTraceTestDirectory($directory);
    @unlink($file);

    $withTraceConfig($directory, function () use ($harness, $file): void {
        TraceLogFrameworkTestCaller::writeLine();

        $harness->assertTrue(is_file($file));
        $content = (string)file_get_contents($file);

        if (preg_match('/^"\d{4}-\d{2}-\d{2}-\d{2}:\d{2}:\d{2}\.\d{3} - function: TraceLogFrameworkTestCaller::writeLine"\r?\n?$/', $content) !== 1) {
            throw new RuntimeException('Trace line did not match expected format: ' . $content);
        }
    });
});

$harness->check(TraceLogFramework::class, 'resolves relative trace paths under APP_ROOT', function () use ($harness, $withTraceConfig, $ensureTraceTestDirectory, $traceTestRoot): void {
    $directory = $traceTestRoot . DIRECTORY_SEPARATOR . 'relative';
    $file = $directory . DIRECTORY_SEPARATOR . date('Y-m-d') . '_trace.csv';
    $ensureTraceTestDirectory($directory);
    @unlink($file);

    $withTraceConfig('tests/tmp/trace-log-framework/relative', function () use ($harness, $file): void {
        TraceLogFrameworkTestCaller::writeLine();
        $harness->assertTrue(is_file($file));
    });
});

$harness->check(TraceLogFramework::class, 'reuses the cached trace directory after config changes', function () use ($harness, $withTraceConfig, $ensureTraceTestDirectory, $traceTestRoot): void {
    $firstDirectory = $traceTestRoot . DIRECTORY_SEPARATOR . 'cached-first';
    $secondDirectory = $traceTestRoot . DIRECTORY_SEPARATOR . 'cached-second';
    $firstFile = $firstDirectory . DIRECTORY_SEPARATOR . date('Y-m-d') . '_trace.csv';
    $secondFile = $secondDirectory . DIRECTORY_SEPARATOR . date('Y-m-d') . '_trace.csv';

    $ensureTraceTestDirectory($firstDirectory);
    $ensureTraceTestDirectory($secondDirectory);
    @unlink($firstFile);
    @unlink($secondFile);

    $withTraceConfig($firstDirectory, function () use ($harness, $secondDirectory, $firstFile, $secondFile): void {
        TraceLogFrameworkTestCaller::writeLine();
        AppConfigurationStore::set('trace.log_path', $secondDirectory);
        TraceLogFrameworkTestCaller::writeLine();

        $harness->assertTrue(is_file($firstFile));
        $harness->assertTrue(!is_file($secondFile));
        $harness->assertSame(2, count(file($firstFile) ?: []));
    });
});
