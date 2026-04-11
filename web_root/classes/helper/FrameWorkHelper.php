<?php
declare(strict_types=1);

final class FrameWorkHelper
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function config(): array
    {
        static $config = null;

        if (is_array($config)) {
            return $config;
        }

        $loaded = require APP_CONFIG . 'app.php';
        $config = is_array($loaded) ? $loaded : [];

        return $config;
    }

    public static function normalisePageKey(?string $pageKey): string
    {
        $pageKey = strtolower(trim((string)$pageKey));
        $pageKey = str_replace('-', '_', $pageKey);

        if ($pageKey === '' || preg_match('/^[a-z0-9_]+$/', $pageKey) !== 1) {
            return 'dashboard';
        }

        return $pageKey;
    }

    public static function pageKeyToClassName(?string $pageKey): string
    {
        return '_' . self::normalisePageKey($pageKey);
    }

    public static function pageClassToFile(string $className): ?string
    {
        if (preg_match('/^_([a-z0-9_]+)$/', $className, $matches) !== 1) {
            return null;
        }

        return APP_PAGES . $matches[1] . '.php';
    }

    public static function normaliseCardKey(string $cardKey): string
    {
        $cardKey = strtolower(trim($cardKey));
        $cardKey = str_replace('-', '_', $cardKey);

        if ($cardKey === '' || preg_match('/^[a-z0-9_]+$/', $cardKey) !== 1) {
            throw new InvalidArgumentException('Invalid card key: ' . $cardKey);
        }

        return $cardKey;
    }

    public static function cardKeyToClassName(string $pageId, string $cardKey): string
    {
        return '_' . self::normalisePageKey($pageId) . '_' . self::normaliseCardKey($cardKey);
    }

    public static function cardClassToFile(string $className): ?string
    {
        if (preg_match('/^_([a-z0-9_]+)_([a-z0-9_]+)$/', $className, $matches) !== 1) {
            return null;
        }

        return APP_PAGES . $matches[1] . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . $matches[2] . '.php';
    }

    public static function cardDomId(string $pageId, string $cardKey): string
    {
        return self::normalisePageKey($pageId) . '-' . self::normaliseCardKey($cardKey);
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public function json_response(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function request_method_is(string $method): bool
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === strtoupper($method);
    }
}
