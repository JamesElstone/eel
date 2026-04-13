<?php
declare(strict_types=1);

final class HelperFramework
{
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

    public static function cardKeyToClassName(string $cardKey): string
    {
        return '_' . self::normaliseCardKey($cardKey) . 'Card';
    }

    public static function cardClassToFile(string $className): ?string
    {
        if (preg_match('/^_([a-z0-9_]+)Card$/', $className, $matches) !== 1) {
            return null;
        }

        return APP_CARDS . $matches[1] . '.php';
    }

    public static function cardDomId(string $pageId, string $cardKey): string
    {
        return self::normalisePageKey($pageId) . '-' . self::normaliseCardKey($cardKey);
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public static function normaliseEnvironmentMode(?string $environment): string
    {
        $environment = strtoupper(trim((string)$environment));

        return $environment === 'LIVE' ? 'LIVE' : 'TEST';
    }

    public static function accountingPeriodLabel(DateTimeInterface|string $periodStart, DateTimeInterface|string $periodEnd): string
    {
        $start = $periodStart instanceof DateTimeInterface ? $periodStart : new DateTimeImmutable((string)$periodStart);
        $end = $periodEnd instanceof DateTimeInterface ? $periodEnd : new DateTimeImmutable((string)$periodEnd);

        return $start->format('j M Y') . ' to ' . $end->format('j M Y');
    }

    public static function companyUploadSubdirectory(int|string $companyId, string $category, string $uploadsRoot): string
    {
        $normalisedRoot = rtrim($uploadsRoot, '\\/');
        $normalisedCompanyId = preg_replace('/[^A-Za-z0-9_-]+/', '', trim((string)$companyId)) ?? '';
        $normalisedCategory = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($category)) ?? '';

        if ($normalisedCompanyId === '' || $normalisedCategory === '') {
            throw new InvalidArgumentException('Company upload path inputs must not be empty.');
        }

        return $normalisedRoot
            . DIRECTORY_SEPARATOR
            . 'company'
            . DIRECTORY_SEPARATOR
            . $normalisedCompanyId
            . DIRECTORY_SEPARATOR
            . $normalisedCategory;
    }

    public static function companyUploadRelativePath(int|string $companyId, string $category, string $filename): string
    {
        $normalisedCompanyId = preg_replace('/[^A-Za-z0-9_-]+/', '', trim((string)$companyId)) ?? '';
        $normalisedCategory = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($category)) ?? '';
        $normalisedFilename = ltrim(str_replace(['\\', '/'], '/', trim($filename)), '/');

        if ($normalisedCompanyId === '' || $normalisedCategory === '' || $normalisedFilename === '') {
            throw new InvalidArgumentException('Company upload relative path inputs must not be empty.');
        }

        return 'company/' . $normalisedCompanyId . '/' . $normalisedCategory . '/' . $normalisedFilename;
    }

    public static function json_response(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function request_method_is(string $method): bool
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === strtoupper($method);
    }
}
