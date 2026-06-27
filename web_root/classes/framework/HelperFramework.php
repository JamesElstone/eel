<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HelperFramework
{
    private const DEFAULT_DISPLAY_DATE_FORMAT = 'd/m/y';
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

    public static function showCallStack() {
        return '<pre class="preformatted-panel">' . HelperFramework::escape(HelperFramework::formatCallStack()) . '</pre>';
    }

    public static function formatCallStack(int $limit = 10): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);

        $lines = [];

        foreach ($trace as $i => $frame) {
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? 'unknown';
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? '?';

            $lines[] = sprintf(
                '#%d %s%s%s() called at [%s:%s]',
                $i,
                $class,
                $type,
                $function,
                $file,
                $line
            );
        }

        return implode("\n", $lines);
    }

    public static function sanitiseId(mixed $preferredValue, int $fallback = 0): int
    {
        if (is_int($preferredValue)) {
            return max(0, $preferredValue);
        }

        if (is_string($preferredValue) && ctype_digit($preferredValue)) {
            return max(0, (int)$preferredValue);
        }

        return max(0, $fallback);
    }

    public static function formatTraceShort(Throwable $e): string
    {
        $lines = [];

        foreach ($e->getTrace() as $i => $frame) {
            $file = isset($frame['file']) ? basename($frame['file']) : '[internal]';
            $line = $frame['line'] ?? '?';
            $function = $frame['function'] ?? '';

            $lines[] = "{$i}#{$file}:{$line}->{$function}()";
        }

        return implode(", \n", $lines);
    }

    public static function createPill($name): string {
        return '<span class="status-pill">Using ' . $name . '</span>';
    }

    public static function rawHtml(string $html): array
    {
        return ['__html' => $html];
    }

    public static function arrayToPre(array $data): string
    {
        return '<pre class="preformatted-panel">' . htmlspecialchars(print_r($data, true)) . '</pre>';
    }

    public static function arrayToTable(array $rows, string $class = 'table'): string
    {
        if (empty($rows)) {
            return '<p>No data</p>';
        }

        // Extract headers from first row
        $headers = array_keys(reset($rows));

        $html = '<table class="' . htmlspecialchars($class) . '">';

        // Header
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars((string)$header) . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';

                // Handle arrays nicely
                if (is_array($value)) {
                    $value = print_r($value, true);
                }

                $html .= '<td>' . htmlspecialchars((string)$value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        $html .= '</table>';

        return $html;
    }

    public static function jsonSummary(string $json, string $separator = ' | '): string
    {
        $json = trim($json);
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return '';
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $parts[] = str_replace('_', ' ', (string)$key) . ': ' . (string)$value;
        }

        return implode($separator, $parts);
    }

    public static function compactText(string $value, int $maxLength = 96): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        $maxLength = max(1, $maxLength);

        if ($value === '' || mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $maxLength - 3))) . '...';
    }

    public static function paginateArray(array $items, int $page = 1, int $pageSize = 25): array
    {
        $total = count($items);
        $pageSize = max(1, $pageSize);
        $pageCount = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        $page = max(1, $page);

        if ($pageCount > 0) {
            $page = min($page, $pageCount);
        } else {
            $page = 1;
        }

        $offset = ($page - 1) * $pageSize;

        return [
            'items' => array_slice(array_values($items), $offset, $pageSize),
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'total' => $total,
            'offset' => $offset,
            'has_previous_page' => $page > 1,
            'has_next_page' => $pageCount > 0 && $page < $pageCount,
        ];
    }

    public static function paginationItemsLabel(array $pagination, string $itemLabel = 'Items'): string
    {
        $total = max(0, (int)($pagination['total'] ?? $pagination['total_items'] ?? 0));

        if ($total === 0) {
            return $itemLabel . ' 0 of 0';
        }

        if (array_key_exists('first_item', $pagination) && array_key_exists('last_item', $pagination)) {
            $rangeStart = max(1, min($total, (int)$pagination['first_item']));
            $rangeEnd = max($rangeStart, min($total, (int)$pagination['last_item']));

            return $rangeStart === $rangeEnd
                ? $itemLabel . ' ' . (string)$rangeStart . ' of ' . (string)$total
                : $itemLabel . ' ' . (string)$rangeStart . '-' . (string)$rangeEnd . ' of ' . (string)$total;
        }

        $page = max(1, (int)($pagination['page'] ?? 1));
        $pageSize = max(1, (int)($pagination['page_size'] ?? 1));
        $itemCount = count((array)($pagination['items'] ?? []));

        if ($itemCount === 0) {
            return $itemLabel . ' 0 of 0';
        }

        $rangeStart = min($total, (($page - 1) * $pageSize) + 1);
        $rangeEnd = min($total, $rangeStart + $itemCount - 1);

        return $rangeStart === $rangeEnd
            ? $itemLabel . ' ' . (string)$rangeStart . ' of ' . (string)$total
            : $itemLabel . ' ' . (string)$rangeStart . '-' . (string)$rangeEnd . ' of ' . (string)$total;
    }

    public static function paginationFormButton(
        string $label,
        int $page,
        bool $enabled,
        string $pageField,
        array $hiddenFields = [],
        string $action = '',
        string $method = 'post',
        array $formAttributes = [],
        string $buttonClass = 'button'
    ): string {
        $label = trim($label) !== '' ? trim($label) : 'Page';
        $method = strtolower(trim($method));
        $method = in_array($method, ['get', 'post'], true) ? $method : 'post';
        $pageField = trim($pageField);

        if (!$enabled || $pageField === '') {
            return '<button class="' . self::escape($buttonClass . ' disabled') . '" type="button" aria-disabled="true">' . self::escape($label) . '</button>';
        }

        $fields = array_merge($hiddenFields, [
            $pageField => max(1, $page),
        ]);

        $hiddenHtml = '';
        foreach ($fields as $name => $value) {
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }

            $hiddenHtml .= '<input type="hidden" name="' . self::escape((string)$name) . '" value="' . self::escape((string)$value) . '">';
        }

        $attributes = [
            'method' => $method,
        ];

        if (trim($action) !== '') {
            $attributes['action'] = $action;
        }

        foreach ($formAttributes as $name => $value) {
            $name = trim((string)$name);
            if ($name === '' || is_array($value) || is_object($value) || $value === null) {
                continue;
            }

            $attributes[$name] = (string)$value;
        }

        $attributeHtml = '';
        foreach ($attributes as $name => $value) {
            $attributeHtml .= ' ' . self::escape((string)$name) . '="' . self::escape((string)$value) . '"';
        }

        return '<form' . $attributeHtml . '>' . $hiddenHtml . '<button class="' . self::escape($buttonClass) . '" type="submit">' . self::escape($label) . '</button></form>';
    }

    public static function classLookingGlass(object|string $target, bool $ownOnly = false): array
    {
        $ref = new ReflectionClass($target);
        $className = $ref->getName();

        $methodsOut = [];

        foreach ($ref->getMethods() as $method) {

            // Skip inherited methods if requested
            if ($ownOnly && $method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $signature = $method->getName() . '(';

            $params = [];
            foreach ($method->getParameters() as $param) {
                $p = '';

                // Type
                if ($param->hasType()) {
                    $type = $param->getType();
                    $p .= ($type instanceof ReflectionNamedType ? $type->getName() : (string)$type) . ' ';
                }

                // Reference
                if ($param->isPassedByReference()) {
                    $p .= '&';
                }

                // Variadic
                if ($param->isVariadic()) {
                    $p .= '...';
                }

                $p .= '$' . $param->getName();

                // Default value
                if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                    $p .= ' = ' . var_export($param->getDefaultValue(), true);
                }

                $params[] = $p;
            }

            $signature .= implode(', ', $params) . ')';

            // Return type (if any)
            if ($method->hasReturnType()) {
                $type = $method->getReturnType();
                $signature .= ': ' . ($type instanceof ReflectionNamedType ? $type->getName() : (string)$type);
            }

            // Visibility
            $visibility = $method->isPublic() ? 'public' :
                          ($method->isProtected() ? 'protected' : 'private');

            if ($method->isStatic()) {
                $visibility .= ' static';
            }

            $methodsOut[] = [
                'class'      => $method->getDeclaringClass()->getName(),
                'signature'  => $signature,
                'visibility' => $visibility,
            ];
        }

        return $methodsOut;
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

    public static function displayDate(DateTimeInterface|string $value): string
    {
        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value);

        return $date->format(self::displayDateFormat());
    }

    public static function displayDateTime(
        DateTimeInterface|string $value,
        string $timeFormat = 'H:i:s'
    ): string
    {
        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value);
        $resolvedDateFormat = self::displayDateFormat();

        return $date->format($resolvedDateFormat . ' ' . $timeFormat);
    }

    public static function accountingPeriodLabel(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate): string
    {
        return self::displayDate($startDate) . ' to ' . self::displayDate($endDate);
    }

    public static function displayMonthYear(DateTimeInterface|string $value): string
    {
        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value);
        $resolvedDateFormat = self::displayDateFormat();
        $separator = str_contains($resolvedDateFormat, '-') ? '-' : '/';

        return match (true) {
            str_starts_with($resolvedDateFormat, 'Y') => $date->format('Y' . $separator . 'm'),
            default => $date->format('m' . $separator . 'Y'),
        };
    }

    public static function displayDateFormat(): string
    {
        return self::DEFAULT_DISPLAY_DATE_FORMAT;
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

    private static function normaliseDisplayDateFormat(string $dateFormat): string
    {
        $dateFormat = trim($dateFormat);

        if (in_array($dateFormat, self::ALLOWED_DISPLAY_DATE_FORMATS, true)) {
            return $dateFormat;
        }

        return self::DEFAULT_DISPLAY_DATE_FORMAT;
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
