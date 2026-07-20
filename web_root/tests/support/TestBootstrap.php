<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

$testAppRoot = rtrim((string)(realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2)), '\\/') . DIRECTORY_SEPARATOR;
$testConfigDirectory = $testAppRoot . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
$normaliseTestPath = static function (string $path): string {
    $resolved = realpath($path);
    $normalised = $resolved !== false ? $resolved : $path;

    return rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $normalised), DIRECTORY_SEPARATOR);
};
$expectedConfigPath = $normaliseTestPath($testConfigDirectory);

if (defined('APP_CONFIG')) {
    $configuredPath = $normaliseTestPath((string)constant('APP_CONFIG'));
    $pathsMatch = DIRECTORY_SEPARATOR === '\\'
        ? strcasecmp($configuredPath, $expectedConfigPath) === 0
        : strcmp($configuredPath, $expectedConfigPath) === 0;

    if (!$pathsMatch) {
        throw new RuntimeException(
            'Unsafe test bootstrap blocked: APP_CONFIG was already set to a non-test configuration directory. '
            . 'Run tests without loading web_root/classes/bootstrap.php first.'
        );
    }
}

$testConfigFile = $testConfigDirectory . 'app.php';
$testConfig = is_file($testConfigFile) ? require $testConfigFile : null;
$testDsn = is_array($testConfig) ? trim((string)($testConfig['db']['dsn'] ?? '')) : '';
if (strcasecmp($testDsn, 'sqlite::memory:') !== 0) {
    throw new RuntimeException('Unsafe test bootstrap blocked: the test database DSN must be sqlite::memory:.');
}

if (class_exists('InterfaceDB', false)) {
    $activeDriver = strtolower(trim((string)InterfaceDB::driverName()));
    if ($activeDriver !== 'sqlite') {
        throw new RuntimeException(
            'Unsafe test bootstrap blocked: InterfaceDB is already using the non-test driver "'
            . $activeDriver
            . '". Run tests in a fresh PHP process without loading web_root/classes/bootstrap.php first.'
        );
    }
}

unset(
    $activeDriver,
    $configuredPath,
    $expectedConfigPath,
    $normaliseTestPath,
    $pathsMatch,
    $testConfig,
    $testConfigDirectory,
    $testConfigFile,
    $testDsn
);

defined('APP_ROOT') || define('APP_ROOT', $testAppRoot);
unset($testAppRoot);
defined('PROJECT_ROOT') || define('PROJECT_ROOT', rtrim(dirname(APP_ROOT), '\\/') . DIRECTORY_SEPARATOR);
defined('APP_CLASSES') || define('APP_CLASSES', APP_ROOT . 'classes' . DIRECTORY_SEPARATOR);
defined('APP_CONFIG') || define('APP_CONFIG', APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
defined('APP_CONTENT') || define('APP_CONTENT', APP_ROOT . 'content' . DIRECTORY_SEPARATOR);
defined('APP_CARDS') || define('APP_CARDS', APP_CONTENT . 'cards' . DIRECTORY_SEPARATOR);
defined('APP_PAGES') || define('APP_PAGES', APP_CONTENT . 'pages' . DIRECTORY_SEPARATOR);
defined('APP_ACTIONS') || define('APP_ACTIONS', APP_CONTENT . 'actions' . DIRECTORY_SEPARATOR);
defined('APP_JS') || define('APP_JS', APP_ROOT . 'js' . DIRECTORY_SEPARATOR);
defined('APP_CSS') || define('APP_CSS', APP_ROOT . 'css' . DIRECTORY_SEPARATOR);

if (!function_exists('test_upload_base_directory')) {
    function test_upload_base_directory(): string
    {
        $configuredPath = trim((string)\AppConfigurationStore::get('uploads.upload_base_dir', ''));
        if ($configuredPath === '') {
            $configuredPath = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'files';
        }

        return rtrim($configuredPath, '\\/');
    }
}

if (!function_exists('test_tmp_directory')) {
    function test_tmp_directory(): string
    {
        return test_upload_base_directory()
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'tmp';
    }
}

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

if (!function_exists('test_confirm_ct_period_facts')) {
    /** @return list<int> */
    function test_confirm_ct_period_facts(
        int $companyId,
        int $accountingPeriodId,
        int $associatedCompanyCount = 0
    ): array {
        $periods = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        if ($periods === []) {
            throw new RuntimeException('No Corporation Tax periods are available to confirm in the test fixture.');
        }

        $factService = new \eel_accounts\Service\CorporationTaxPeriodFactService();
        $ids = [];
        foreach ($periods as $period) {
            if ((string)($period['status'] ?? '') === 'superseded') {
                continue;
            }
            $ctPeriodId = (int)($period['id'] ?? 0);
            $result = $factService->save(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $associatedCompanyCount
            );
            if (empty($result['success'])) {
                throw new RuntimeException(implode(' ', (array)($result['errors'] ?? ['Unable to confirm CT-period facts.'])));
            }
            $ids[] = $ctPeriodId;
        }

        return $ids;
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
