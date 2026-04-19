<?php
declare(strict_types=1);

final class LogStore
{
    /** @var array<string, resource> */
    private static array $handles = [];
    private static bool $shutdownRegistered = false;

    public function appendLine(string $path, string $message): void
    {
        $path = $this->normalisePath($path);
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

        $handle = $this->handleFor($path);
        $line = $this->normaliseLine($message);

        if (flock($handle, LOCK_EX)) {
            try {
                if (fwrite($handle, $line) === false) {
                    throw new RuntimeException('Unable to write log entry to: ' . $path);
                }

                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
            }

            return;
        }

        throw new RuntimeException('Unable to lock log file for writing: ' . $path);
    }

    private function handleFor(string $path)
    {
        $this->registerShutdownHandler();

        if (isset(self::$handles[$path]) && is_resource(self::$handles[$path])) {
            return self::$handles[$path];
        }

        $handle = fopen($path, 'ab');
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
            foreach (self::$handles as $handle) {
                if (!is_resource($handle)) {
                    continue;
                }

                fflush($handle);
                fclose($handle);
            }

            self::$handles = [];
        });

        self::$shutdownRegistered = true;
    }

    private function normalisePath(string $path): string
    {
        return trim($path);
    }

    private function normaliseLine(string $message): string
    {
        return preg_replace('/[\n\r]+ */m', " ", rtrim($message, "\r\n")) . PHP_EOL;
    }
}
