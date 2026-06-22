<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TraceLogFramework
{
    private static bool $initialised = false;
    private static string $traceDirectory = '';

    public static function logDetails(): void
    {
        $traceDirectory = self::traceDirectory();
        if ($traceDirectory === '') {
            return;
        }

        $now = microtime(true);
        $seconds = (int)floor($now);
        $timestamp = date('Y-m-d-H:i:s', $seconds) . sprintf('.%03d', (int)(($now - $seconds) * 1000));
        $file = self::joinPath($traceDirectory, date('Y-m-d', $seconds) . '_trace.csv');
        $line = self::toCsvLine([$timestamp . ' - function: ' . self::callerName()]) . PHP_EOL;

        try {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            return;
        }
    }

    public static function resetForTests(): void
    {
        self::$initialised = false;
        self::$traceDirectory = '';
    }

    private static function traceDirectory(): string
    {
        if (self::$initialised) {
            return self::$traceDirectory;
        }

        self::$initialised = true;

        try {
            $configuredPath = trim((string)AppConfigurationStore::get('trace.log_path', ''));
        } catch (Throwable) {
            self::$traceDirectory = '';
            return self::$traceDirectory;
        }

        if ($configuredPath === '') {
            self::$traceDirectory = '';
            return self::$traceDirectory;
        }

        $directory = self::normaliseConfigPath($configuredPath);
        clearstatcache(true, $directory);

        if (!is_dir($directory)) {
            self::$traceDirectory = '';
            return self::$traceDirectory;
        }

        self::$traceDirectory = $directory;

        return self::$traceDirectory;
    }

    private static function joinPath(string $directory, string $filename): string
    {
        if (str_ends_with($directory, '/') || str_ends_with($directory, '\\')) {
            return $directory . $filename;
        }

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    private static function normaliseConfigPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2})/', $normalised) === 1) {
            return $normalised;
        }

        return APP_ROOT . ltrim($normalised, '\\/');
    }

    private static function callerName(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            $function = (string)($frame['function'] ?? '');
            $class = (string)($frame['class'] ?? '');

            if ($function === '' || $class === self::class || $function === 'logDetails') {
                continue;
            }

            return $class !== '' ? $class . '::' . $function : $function;
        }

        return 'unknown';
    }

    private static function toCsvLine(array $fields): string
    {
        $escaped = array_map(
            static fn (mixed $field): string => '"' . str_replace('"', '""', (string)$field) . '"',
            $fields
        );

        return implode(',', $escaped);
    }
}
