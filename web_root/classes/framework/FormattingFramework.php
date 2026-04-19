<?php
declare(strict_types=1);

final class FormattingFramework
{
    public static function money(float|int|string|null $value): string
    {
        return number_format((float)$value, 2, '.', ',');
    }

    public static function nullableMoney(mixed $value, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return self::money($value);
    }

    public static function nominalLabel(mixed $nominal, string $separator = ' - '): string
    {
        if (!is_array($nominal)) {
            return '';
        }

        $code = trim((string)($nominal['code'] ?? ''));
        $name = trim((string)($nominal['name'] ?? ''));

        if ($code === '') {
            return $name;
        }

        if ($name === '') {
            return $code;
        }

        return $code . $separator . $name;
    }

    public static function nominalTaxTreatmentLabel(string $taxTreatment): string
    {
        return match ($taxTreatment) {
            'disallowable' => 'Disallowable',
            'capital' => 'Capital',
            default => 'Allowable',
        };
    }
}
