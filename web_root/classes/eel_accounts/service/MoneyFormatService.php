<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class MoneyFormatService
{
    public function defaultCurrencySymbol(array $settings): string
    {
        $symbol = html_entity_decode((string)($settings['default_currency_symbol'] ?? '&#163;'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return $symbol !== '' ? $symbol : html_entity_decode('&#163;', \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    public function format(array $settings, mixed $value, string $fallback = '-'): string
    {
        $amount = $this->parseAmount($value);
        if ($amount === null) {
            return $fallback;
        }

        $sign = $amount < 0.0 ? '-' : '';

        return $sign . $this->defaultCurrencySymbol($settings) . ' ' . \FormattingFramework::money(abs($amount));
    }

    public function formatHtml(array $settings, mixed $value, string $fallback = '-'): string
    {
        $amount = $this->parseAmount($value);
        if ($amount === null) {
            return \HelperFramework::escape($fallback);
        }

        $class = $amount > 0.0 ? 'amount-positive' : ($amount < 0.0 ? 'amount-negative' : 'amount-zero');

        return '<span class="' . $class . '">' . \HelperFramework::escape($this->format($settings, $amount, $fallback)) . '</span>';
    }

    public function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = html_entity_decode(trim($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $value = str_replace([',', "\u{00a0}"], ['', ''], $value);
            $value = preg_replace('/[\p{Sc}\s]/u', '', $value) ?? '';
            if ($value === '') {
                return null;
            }
        }

        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $amount = round((float)$value, 2);

        return abs($amount) < 0.005 ? 0.0 : $amount;
    }
}
