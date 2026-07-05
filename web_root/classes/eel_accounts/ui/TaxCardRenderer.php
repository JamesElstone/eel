<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Ui;

final class TaxCardRenderer
{
    public static function serviceDefinition(): array
    {
        return [
            'key' => 'taxWorkings',
            'service' => \eel_accounts\Service\TaxWorkingsService::class,
            'method' => 'fetchWorkings',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'ctPeriodId' => ':tax.selected_ct_period_id',
            ],
        ];
    }

    public static function workings(array $context): array
    {
        return (array)(($context['services'] ?? [])['taxWorkings'] ?? []);
    }

    public static function emptyState(array $workings): string
    {
        $errors = (array)($workings['errors'] ?? []);
        $message = (string)($errors[0] ?? 'Select a company and accounting period to inspect tax workings.');

        return '<div class="helper">' . \HelperFramework::escape($message) . '</div>';
    }

    public static function guidanceLink(string $key): string
    {
        $url = \eel_accounts\Service\TaxGuidanceService::url($key);

        return '<a class="button button-inline" href="' . \HelperFramework::escape($url) . '" target="_blank" rel="noopener noreferrer">His Majesty\'s Revenue and Customs (HMRC) guidance</a>';
    }

    public static function money(array $context, float|int|string|null $value): string
    {
        $company = (array)($context['company'] ?? []);

        return (new \eel_accounts\Service\CompanySettingsService())->money((array)($company['settings'] ?? []), $value);
    }

    public static function percent(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return '-';
        }

        return number_format(((float)$value) * 100, 2) . '%';
    }

    public static function badge(string $status, string $label = ''): string
    {
        $class = match ($status) {
            'ready_for_review', 'pass', 'ready', 'success' => 'success',
            'warning', 'review_required', 'not_started' => 'warning',
            'danger', 'fail', 'needs_attention' => 'danger',
            default => 'info',
        };
        $label = $label !== '' ? $label : \HelperFramework::labelFromKey($status, '_');

        return '<span class="badge ' . \HelperFramework::escape($class) . '">' . \HelperFramework::escape($label) . '</span>';
    }

    public static function header(string $guidanceKey): string
    {
        return '<div class="actions-row">' . self::guidanceLink($guidanceKey) . '</div>';
    }

    public static function table(array $headers, array $rows, string $empty = 'No rows to show.'): string
    {
        if ($rows === []) {
            return '<div class="helper">' . \HelperFramework::escape($empty) . '</div>';
        }

        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . \HelperFramework::escape((string)$header) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ((array)$row as $cell) {
                $body .= '<td>' . $cell . '</td>';
            }
            $body .= '</tr>';
        }

        return '<div class="table-scroll"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    public static function summaryGrid(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<div class="summary-card"><div class="summary-label">'
                . \HelperFramework::escape((string)($item[0] ?? ''))
                . '</div><div class="summary-value">'
                . \HelperFramework::escape((string)($item[1] ?? ''))
                . '</div></div>';
        }

        return '<div class="summary-grid">' . $html . '</div>';
    }

    public static function escape(mixed $value): string
    {
        return \HelperFramework::escape((string)$value);
    }
}
