<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HelperFramework
{
    private const DEFAULT_DISPLAY_DATE_FORMAT = 'd/m/y';
    private const DEFAULT_COMPANY_DATE_FORMAT = 'd/m/Y';
    private const ALLOWED_DISPLAY_DATE_FORMATS = [
        'Y-m-d',
        'd/m/Y',
        'd-m-Y',
        'd/m/y',
        'd-m-y',
    ];

    private const DEFAULT_DATE_TIME_FORMATS = [
        '!Y-m-d\TH:i:sP',
        '!Y-m-d\TH:i:s',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        '!Y-m-d',
        '!d/m/Y H:i:s',
        '!d/m/Y H:i',
        '!d/m/Y',
        '!d-m-Y H:i:s',
        '!d-m-Y H:i',
        '!d-m-Y',
    ];

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

    public static function accountingPeriodLabel(
        DateTimeInterface|string $periodStart,
        DateTimeInterface|string $periodEnd,
        ?int $companyId = null,
        ?string $dateFormat = null
    ): string
    {
        $start = $periodStart instanceof DateTimeInterface ? $periodStart : new DateTimeImmutable((string)$periodStart);
        $end = $periodEnd instanceof DateTimeInterface ? $periodEnd : new DateTimeImmutable((string)$periodEnd);
        $resolvedFormat = self::displayDateFormat($companyId, $dateFormat);

        return $start->format($resolvedFormat) . ' to ' . $end->format($resolvedFormat);
    }

    public static function displayDate(
        DateTimeInterface|string $value,
        ?int $companyId = null,
        ?string $dateFormat = null
    ): string
    {
        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value);

        return $date->format(self::displayDateFormat($companyId, $dateFormat));
    }

    public static function displayDateTime(
        DateTimeInterface|string $value,
        ?int $companyId = null,
        ?string $dateFormat = null,
        string $timeFormat = 'H:i:s'
    ): string
    {
        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value);
        $resolvedDateFormat = self::displayDateFormat($companyId, $dateFormat);

        return $date->format($resolvedDateFormat . ' ' . $timeFormat);
    }

    public static function displayMonthYear(
        DateTimeInterface|string $value,
        ?int $companyId = null,
        ?string $dateFormat = null
    ): string
    {
        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value);
        $resolvedDateFormat = self::displayDateFormat($companyId, $dateFormat);
        $separator = str_contains($resolvedDateFormat, '-') ? '-' : '/';

        return match (true) {
            str_starts_with($resolvedDateFormat, 'Y') => $date->format('Y' . $separator . 'm'),
            default => $date->format('m' . $separator . 'Y'),
        };
    }

    public static function displayDateFormat(?int $companyId = null, ?string $dateFormat = null): string
    {
        $dateFormat = trim((string)$dateFormat);

        if ($dateFormat !== '') {
            return self::normaliseDisplayDateFormat($dateFormat, $companyId);
        }

        if ($companyId === null || $companyId <= 0) {
            return self::DEFAULT_DISPLAY_DATE_FORMAT;
        }

        return self::normaliseDisplayDateFormat(
            (string)(new CompanySettingsStore($companyId))->get('date_format', self::DEFAULT_COMPANY_DATE_FORMAT),
            $companyId
        );
    }

    public static function labelFromKey(?string $value, string $separator = '_', string $fallback = ''): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return $fallback;
        }

        $words = $separator === ''
            ? preg_split('/\s+/', $value) ?: []
            : explode($separator, $value);
        $words = array_values(array_filter(array_map(
            static fn(string $word): string => trim($word),
            $words
        ), static fn(string $word): bool => $word !== ''));

        if ($words === []) {
            return $fallback;
        }

        $words = array_map(static function (string $word): string {
            $lower = strtolower($word);
            return ucfirst($lower);
        }, $words);

        return implode(' ', $words);
    }

    public static function titleCase(?string $value, string $fallback = ''): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return $fallback;
        }

        $words = preg_split('/\s+/', strtolower($value)) ?: [];
        $words = array_values(array_filter($words, static fn(string $word): bool => $word !== ''));

        if ($words === []) {
            return $fallback;
        }

        $words = array_map(static fn(string $word): string => ucfirst($word), $words);

        return implode(' ', $words);
    }

    public static function httpHeaderLabelFromServerKey(string $serverKey): string
    {
        $serverKey = trim($serverKey);

        if ($serverKey === '') {
            return '';
        }

        if (strncmp($serverKey, 'HTTP_', 5) === 0) {
            return str_replace(' ', '-', self::labelFromKey(substr($serverKey, 5), '_'));
        }

        return str_replace(' ', '-', self::labelFromKey($serverKey, '_'));
    }

    public static function parseDateTimeValue(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        foreach (self::DEFAULT_DATE_TIME_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if (!$date instanceof DateTimeImmutable) {
                continue;
            }

            $errors = DateTimeImmutable::getLastErrors();

            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    public static function normaliseDate(?string $value): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        $date = self::parseDateTimeValue($value);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : substr($value, 0, 10);
    }

    public static function normaliseUtcDateTime(?string $value): ?string
    {
        $date = self::parseDateTimeValue($value);

        if (!$date instanceof DateTimeImmutable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function normaliseDisplayDateFormat(string $dateFormat, ?int $companyId): string
    {
        $dateFormat = trim($dateFormat);

        if (in_array($dateFormat, self::ALLOWED_DISPLAY_DATE_FORMATS, true)) {
            return $dateFormat;
        }

        return ($companyId !== null && $companyId > 0)
            ? self::DEFAULT_COMPANY_DATE_FORMAT
            : self::DEFAULT_DISPLAY_DATE_FORMAT;
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

    public static function generateBootstrapCode(): string
    {
        $hex = strtoupper(bin2hex(random_bytes(12)));

        return trim(chunk_split($hex, 3, ' '));
    }

    public static function normaliseBootstrapCode(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return strtoupper($code);
    }

    public static function isValidBootstrapCodeFormat(string $code): bool
    {
        return preg_match('/^[0-9A-Fa-f\s]+$/', $code) === 1;
    }

    public static function bootstrapCodeMatches(string $enteredCode, string $storedCode): bool
    {
        return hash_equals(
            self::normaliseBootstrapCode($storedCode),
            self::normaliseBootstrapCode($enteredCode)
        );
    }
}
