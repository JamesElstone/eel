<?php
declare(strict_types=1);

define('APP_ROOT', rtrim((string)(realpath(dirname(__DIR__)) ?: dirname(__DIR__)), '\\/') . DIRECTORY_SEPARATOR);
define('APP_CLASSES', APP_ROOT . 'classes' . DIRECTORY_SEPARATOR);
define('APP_CONFIG', APP_ROOT . 'config' . DIRECTORY_SEPARATOR);
define('APP_CONTENT', APP_ROOT . 'content' . DIRECTORY_SEPARATOR);
define('APP_CARDS', APP_CONTENT . 'cards' . DIRECTORY_SEPARATOR);
define('APP_PAGES', APP_CONTENT . 'pages' . DIRECTORY_SEPARATOR);
define('APP_ACTIONS', APP_CONTENT . 'actions' . DIRECTORY_SEPARATOR);
define('APP_JS', APP_ROOT . 'js' . DIRECTORY_SEPARATOR);
define('APP_CSS', APP_ROOT . 'css' . DIRECTORY_SEPARATOR);

const AF_HEADER_PREFIX = 'X-AntiFraud-';
const AF_COOKIE_PREFIX = 'af_';

require_once APP_CLASSES . 'framework' . DIRECTORY_SEPARATOR . 'HelperFramework.php';
require_once APP_ROOT . 'db' . DIRECTORY_SEPARATOR . 'db.php';

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

