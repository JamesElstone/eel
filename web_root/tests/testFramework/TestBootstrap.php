<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

defined('APP_ROOT') || define('APP_ROOT', rtrim((string)(realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2)), '\\/') . DIRECTORY_SEPARATOR);
defined('PROJECT_ROOT') || define('PROJECT_ROOT', rtrim(dirname(APP_ROOT), '\\/') . DIRECTORY_SEPARATOR);
defined('APP_CLASSES') || define('APP_CLASSES', APP_ROOT . 'classes' . DIRECTORY_SEPARATOR);
defined('APP_CONFIG') || define('APP_CONFIG', APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
defined('APP_CONTENT') || define('APP_CONTENT', APP_ROOT . 'content' . DIRECTORY_SEPARATOR);
defined('APP_CARDS') || define('APP_CARDS', APP_CONTENT . 'cards' . DIRECTORY_SEPARATOR);
defined('APP_PAGES') || define('APP_PAGES', APP_CONTENT . 'pages' . DIRECTORY_SEPARATOR);
defined('APP_ACTIONS') || define('APP_ACTIONS', APP_CONTENT . 'actions' . DIRECTORY_SEPARATOR);
defined('APP_JS') || define('APP_JS', APP_ROOT . 'js' . DIRECTORY_SEPARATOR);
defined('APP_CSS') || define('APP_CSS', APP_ROOT . 'css' . DIRECTORY_SEPARATOR);

defined('AF_HEADER_PREFIX') || define('AF_HEADER_PREFIX', 'X-AntiFraud-');
defined('AF_COOKIE_PREFIX') || define('AF_COOKIE_PREFIX', 'af_');

if (!function_exists('test_write_file_contents_locked')) {
    function test_write_file_contents_locked(string $path, string $contents): void
    {
        $length = strlen($contents);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $handle = @fopen($path, 'c+b');
            if (is_resource($handle)) {
                $locked = false;
                try {
                    $locked = flock($handle, LOCK_EX);
                    if ($locked && ftruncate($handle, 0) && rewind($handle)) {
                        $written = 0;
                        while ($written < $length) {
                            $bytes = fwrite($handle, substr($contents, $written));
                            if ($bytes === false || $bytes === 0) {
                                break;
                            }
                            $written += $bytes;
                        }
                        if ($written === $length && fflush($handle)) {
                            return;
                        }
                    }
                } finally {
                    if ($locked) {
                        flock($handle, LOCK_UN);
                    }
                    fclose($handle);
                }
            }
            usleep(10000);
        }

        throw new RuntimeException('Unable to replace test file contents after retries: ' . $path);
    }
}

if (!function_exists('logDetails')) {
    function logDetails(): void
    {
        static $loaded = false;

        if (!$loaded) {
            require_once APP_CLASSES . 'framework' . DIRECTORY_SEPARATOR . 'TraceLogFramework.php';
            $loaded = true;
        }

        TraceLogFramework::logDetails();
    }
}

spl_autoload_register(
    static function (string $className): void {
        $className = ltrim($className, '\\');

        if (str_starts_with($className, '_')) {
            $name = ltrim($className, '_');

            if (str_ends_with($name, 'Card')) {
                $file = APP_CARDS . substr($name, 0, -4) . '.php';
            } else {
                $file = APP_PAGES . $name . '.php';
            }

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }

        if (str_contains($className, '\\')) {
            $parts = explode('\\', $className);
            $classBaseName = (string) array_pop($parts);

            foreach ([...$parts, $classBaseName] as $part) {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                    return;
                }
            }

            $directories = array_map('strtolower', $parts);
            $file = APP_CLASSES
                . ($directories === [] ? '' : implode(DIRECTORY_SEPARATOR, $directories) . DIRECTORY_SEPARATOR)
                . $classBaseName
                . '.php';

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }

        if (str_ends_with($className, 'Action')) {
            $actionFile = APP_ACTIONS . $className . '.php';

            if (is_file($actionFile)) {
                require_once $actionFile;
                return;
            }
        }

        $baseDirectory = APP_CLASSES;

        if (
            !preg_match_all(
                '/(?:[A-Z]+(?=[A-Z][a-z]|[0-9]|$)|[A-Z][a-z0-9]*)/',
                $className,
                $matches
            )
            || empty($matches[0])
        ) {
            return;
        }

        $type = strtolower((string) end($matches[0]));
        $file = $baseDirectory . $type . DIRECTORY_SEPARATOR . $className . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);

set_exception_handler(static function (Throwable $exception): void {
    if (!headers_sent()) {
        http_response_code(500);
    }

    $payload = [
        'success' => false,
        'errors' => ['Unexpected server error: ' . $exception->getMessage()],
    ];

    if (
        isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        HelperFramework::json_response(500, $payload);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Server error</title></head><body>';
    echo '<h1>Server error</h1><p>' . HelperFramework::escape($exception->getMessage()) . '</p>';
    echo '</body></html>';
});

if (!function_exists('eel_public_exception_message')) {
    function eel_public_exception_message(Throwable $exception): string
    {
        $schemaHint = eel_schema_exception_hint($exception);
        $databaseDriverHint = eel_database_driver_exception_hint($exception);
        $configurationBootstrapMessage = eel_configuration_bootstrap_exception_message($exception);

        if ($configurationBootstrapMessage !== null) {
            return $configurationBootstrapMessage;
        }

        if (eel_developer_options_enabled()) {
            $message = 'Unexpected server error: ' . $exception->getMessage();
            if ($databaseDriverHint !== null) {
                $message .= ' ' . $databaseDriverHint;
            }

            return $schemaHint === null ? $message : $message . ' ' . $schemaHint;
        }

        $message = 'Sorry, something went wrong while processing your request. Please try again, or contact support if the problem continues.';
        if ($databaseDriverHint !== null) {
            $message .= ' ' . $databaseDriverHint;
        }

        return $schemaHint === null ? $message : $message . ' ' . $schemaHint;
    }
}

if (!function_exists('eel_configuration_bootstrap_exception_message')) {
    function eel_configuration_bootstrap_exception_message(Throwable $exception): ?string
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $message = $current->getMessage();

            if (
                str_starts_with($message, 'Unable to create application configuration directory:')
                || str_starts_with($message, 'Unable to write application configuration file:')
                || str_starts_with($message, 'Application configuration file path is a directory:')
                || str_starts_with($message, 'Application configuration file is not writable:')
                || str_starts_with($message, 'Application configuration directory does not exist:')
                || str_starts_with($message, 'Application configuration directory is not writable:')
            ) {
                return 'Application configuration could not be initialised. ' . $message
                    . '. Check the secure directory permissions, or create secure/app.php before loading the app.';
            }
        }

        return null;
    }
}

if (!function_exists('eel_database_driver_exception_hint')) {
    function eel_database_driver_exception_hint(Throwable $exception): ?string
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());

            if (str_contains($message, 'could not find driver')) {
                return 'This looks like a missing PDO database driver. For an ODBC DSN, enable the PHP pdo_odbc extension and install/configure the system ODBC driver manager and DSN.';
            }
        }

        return null;
    }
}

if (!function_exists('eel_schema_exception_hint')) {
    function eel_schema_exception_hint(Throwable $exception): ?string
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());

            if (
                str_contains($message, 'column not found')
                || str_contains($message, 'unknown column')
                || str_contains($message, 'table not found')
                || str_contains($message, 'base table or view not found')
                || str_contains($message, 'unknown table')
                || str_contains($message, 'no such table')
                || str_contains($message, 'duplicate column name')
                || str_contains($message, 'duplicate check constraint')
                || str_contains($message, 'duplicate key name')
                || str_contains($message, 'sqlstate[42s22]')
                || str_contains($message, 'sqlstate[42s02]')
                || str_contains($message, 'sqlstate[42s01]')
                || str_contains($message, 'sqlstate[42703]')
                || str_contains($message, 'sqlstate[42p01]')
            ) {
                return 'This looks like a database schema mismatch. Run the migration tool from the project root: php tools/php/setupDb.php --migrate-only';
            }
        }

        return null;
    }
}

if (!function_exists('eel_developer_options_enabled')) {
    function eel_developer_options_enabled(): bool
    {
        if (!is_file(APP_CONFIG . 'app.php')) {
            return false;
        }

        try {
            return (bool)AppConfigurationStore::get('developer_options', false);
        } catch (Throwable) {
            return false;
        }
    }
}
