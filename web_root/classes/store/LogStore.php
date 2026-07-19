<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class LogStore
{
    private const BUFFER_FLUSH_BYTES = 65536;

    /** @var array<string, resource> */
    private static array $handles = [];
    /** @var array<string, string> */
    private static array $buffers = [];
    private static bool $shutdownRegistered = false;

    public function appendLine(string $path, string $message): void
    {
        $path = $this->normalisePath($path);
        $this->ensureLogPath($path);
        $handle = $this->handleFor($path);
        $line = $this->normaliseLine($message);

        $this->writeLines($path, $handle, $line, true);
    }

    public function appendBufferedLine(string $path, string $message): void
    {
        $path = $this->normalisePath($path);
        $this->ensureLogPath($path);
        $this->handleFor($path);

        self::$buffers[$path] = (self::$buffers[$path] ?? '') . $this->normaliseLine($message);

        if (strlen(self::$buffers[$path]) >= self::BUFFER_FLUSH_BYTES) {
            $this->flush($path);
        }
    }

    public function flush(?string $path = null): void
    {
        if ($path === null) {
            foreach (array_keys(self::$buffers) as $bufferPath) {
                $this->flush($bufferPath);
            }

            return;
        }

        $path = $this->normalisePath($path);
        $buffer = self::$buffers[$path] ?? '';
        if ($buffer === '') {
            return;
        }

        $handle = $this->handleFor($path);
        $this->writeLines($path, $handle, $buffer, true);
        unset(self::$buffers[$path]);
    }

    private function handleFor(string $path)
    {
        $this->registerShutdownHandler();

        if (isset(self::$handles[$path]) && is_resource(self::$handles[$path])) {
            return self::$handles[$path];
        }

        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open log file: ' . $path);
        }

        self::$handles[$path] = $handle;

        return $handle;
    }

    private function registerShutdownHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function(static function (): void {
            $store = new self();
            try {
                $store->flush();
            } catch (Throwable) {
                // Logging must never turn shutdown into an application failure.
            }

            foreach (self::$handles as $handle) {
                if (!is_resource($handle)) {
                    continue;
                }

                fflush($handle);
                fclose($handle);
            }

            self::$handles = [];
            self::$buffers = [];
        });

        self::$shutdownRegistered = true;
    }

    private function normalisePath(string $path): string
    {
        return trim($path);
    }

    private function ensureLogPath(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Log path cannot be blank.');
        }

        $directory = dirname($path);
        if ($directory === '' || $directory === '.') {
            throw new RuntimeException('Log path must include a directory.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create log directory: ' . $directory);
        }
    }

    private function writeLines(string $path, $handle, string $lines, bool $flushImmediately): void
    {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock log file for writing: ' . $path);
        }

        try {
            if (fwrite($handle, $lines) === false) {
                throw new RuntimeException('Unable to write log entry to: ' . $path);
            }

            if ($flushImmediately) {
                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
        }
    }

    private function normaliseLine(string $message): string
    {
        return preg_replace('/[\n\r]+ */m', " ", rtrim($message, "\r\n")) . PHP_EOL;
    }
}
