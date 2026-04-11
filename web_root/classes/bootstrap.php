<?php
declare(strict_types=1);

define('APP_ROOT', rtrim((string)(realpath(dirname(__DIR__)) ?: dirname(__DIR__)), '\\/') . DIRECTORY_SEPARATOR);
define('APP_CLASSES', APP_ROOT . 'classes' . DIRECTORY_SEPARATOR);
define('APP_CONFIG', APP_ROOT . 'config' . DIRECTORY_SEPARATOR);
define('APP_CONTENT', APP_ROOT . 'content' . DIRECTORY_SEPARATOR);
define('APP_PAGES', APP_CONTENT . 'pages' . DIRECTORY_SEPARATOR);
define('APP_JS', APP_ROOT . 'js' . DIRECTORY_SEPARATOR);
define('APP_CSS', APP_ROOT . 'css' . DIRECTORY_SEPARATOR);

require_once APP_CLASSES . 'helper' . DIRECTORY_SEPARATOR . 'FrameWorkHelper.php';
require_once APP_CLASSES . 'lib' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'ctrl_helpers.php';
require_once APP_ROOT . 'db' . DIRECTORY_SEPARATOR . 'db.php';

$frameworkHelpers = FrameWorkHelper::instance();

spl_autoload_register(
    static function (string $className): void {
        $className = ltrim($className, '\\');

        if (str_starts_with($className, '_')) {
            $cardFile = FrameWorkHelper::cardClassToFile($className);
            if ($cardFile !== null && is_file($cardFile)) {
                require_once $cardFile;
                return;
            }

            $pageFile = FrameWorkHelper::pageClassToFile($className);
            if ($pageFile !== null && is_file($pageFile)) {
                require_once $pageFile;
            }

            return;
        }

        foreach (['Web' => 'web'] as $prefix => $directory) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $prefixFile = APP_CLASSES . $directory . DIRECTORY_SEPARATOR . $className . '.php';
            if (is_file($prefixFile)) {
                require_once $prefixFile;
                return;
            }
        }

        foreach (['service', 'helper', 'interface', 'outbound', 'af', 'web'] as $directory) {
            $directFile = APP_CLASSES . $directory . DIRECTORY_SEPARATOR . $className . '.php';
            if (is_file($directFile)) {
                require_once $directFile;
                return;
            }
        }

        if (!preg_match_all('/(?:[A-Z]+(?=[A-Z][a-z]|[0-9]|$)|[A-Z][a-z0-9]*)/', $className, $matches) || empty($matches[0])) {
            return;
        }

        $type = strtolower((string)end($matches[0]));
        $file = APP_CLASSES . $type . DIRECTORY_SEPARATOR . $className . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);

set_exception_handler(static function (Throwable $exception) use ($frameworkHelpers): void {
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
        $frameworkHelpers->json_response(500, $payload);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Server error</title></head><body>';
    echo '<h1>Server error</h1><p>' . FrameWorkHelper::escape($exception->getMessage()) . '</p>';
    echo '</body></html>';
});
