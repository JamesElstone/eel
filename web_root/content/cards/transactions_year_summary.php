<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_year_summaryCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'transactions_year_summary';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $monthStatus = (array)($page['month_status'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $selectedTransactionFilter = (string)($page['selected_transaction_filter'] ?? $page['category_filter'] ?? 'all');
        $transactionsCardUpdateList = 'transactions-year-summary,transactions-imported,transactions-rules,transactions-rule-form';

        $monthsHtml = '';
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }

            $monthYear = trim((string)($month['year'] ?? ''));
            $monthYearHtml = $monthYear !== ''
                ? '<div class="month-year">' . HelperFramework::escape($monthYear) . '</div>'
                : '';

            $monthsHtml .= '<a
                class="' . HelperFramework::escape($this->monthStatusClass((string)($month['status'] ?? 'idle'))) . '"
                href="' . HelperFramework::escape($this->buildPageUrl('transactions', [
                    'company_id' => $selectedCompanyId,
                    'tax_year_id' => $selectedTaxYearId,
                    'month_key' => (string)($month['month_key'] ?? ''),
                    'category_filter' => $selectedTransactionFilter,
                ])) . '"
                data-ajax-card-link="true"
                data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '"
            >
                <div class="month-head">
                    <div>
                        <div class="month-name">' . HelperFramework::escape((string)($month['month'] ?? '')) . '</div>
                        ' . $monthYearHtml . '
                    </div>
                    <span class="month-dot"></span>
                </div>
                <div class="helper"><strong>' . (int)($month['transactions'] ?? 0) . '</strong> transactions</div>
                <div class="helper">' . (int)($month['uncategorised'] ?? 0) . ' uncategorised</div>
                <div class="helper">' . (int)($month['deferred'] ?? 0) . ' deferred</div>
                <div class="helper">' . (int)($month['ready_to_post'] ?? 0) . ' unposted</div>
            </a>';
        }

        return '<section class="eel-card-fragment" data-card="transactions-year-summary" style="grid-column: 1 / -1;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Year Summary</h2>
                </div>
                <div class="card-body">
                    <div class="month-grid">' . $monthsHtml . '</div>
                </div>
            </div>
        </section>';
    }

    private function monthStatusClass(string $status): string
    {
        return match ($status) {
            'bad', 'attention', 'uncategorised' => 'month-card month-card-bad',
            'warn', 'warning', 'ready' => 'month-card month-card-warn',
            'good', 'ok', 'complete', 'posted' => 'month-card month-card-ok',
            default => 'month-card',
        };
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
