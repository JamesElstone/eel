<?php
declare(strict_types=1);

final class GoldenComparisonReporter
{
    /** @param list<array<string, mixed>> $failures */
    public static function report(array $failures): string
    {
        $lines = ['Golden accounting comparison failed:'];
        foreach ($failures as $failure) {
            $lines[] = sprintf(
                '- %s / %s / period %s / %s: expected %s, actual %s',
                (string)($failure['page'] ?? 'unknown page'),
                (string)($failure['card'] ?? 'unknown card'),
                (string)($failure['period'] ?? 'unknown'),
                (string)($failure['field'] ?? 'unknown field'),
                self::value($failure['expected'] ?? null),
                self::value($failure['actual'] ?? null)
            );
        }

        return implode(PHP_EOL, $lines);
    }

    private static function value(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return $encoded === false ? var_export($value, true) : $encoded;
    }
}
