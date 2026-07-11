<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_monthly_statusCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'transactions_monthly_status';
    }

    public function title(): string
    {
        return 'Monthly Transaction Status';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'month_status',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'buildMonthStatus',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        return TransactionAction::withTransactionCardContext($request, $services, $pageContext, $actionResult);
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'transactions.imported',
            TransactionAction::CATEGORISATION_SUMMARY_FACT,
            'year.end.empty.month.confirmations',
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $monthStatus = (array)($services['month_status'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $selectedTransactionFilter = (string)($page['selected_transaction_filter'] ?? $page['category_filter'] ?? 'all');
        $selectedAccountFilter = max(0, (int)($page['selected_account_filter'] ?? $page['account_filter'] ?? 0));

        if ($monthStatus === []) {
            return '<div class="helper">Select a company and accounting period to see monthly transaction status.</div>';
        }

        $monthsHtml = '';
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }

            $monthYear = trim((string)($month['year'] ?? ''));
            $monthYearHtml = $monthYear !== ''
                ? '<div class="month-year">' . HelperFramework::escape($monthYear) . '</div>'
                : '';
            $confirmedEmptyHtml = !empty($month['empty_month_confirmed'])
                ? '<div class="helper">Confirmed no activity</div>'
                : '';
            $monthKey = (string)($month['month_key'] ?? '');
            $canConfirmEmpty = !empty($month['can_confirm_empty_month'])
                && empty($month['empty_month_confirmed'])
                && $companyId > 0
                && $accountingPeriodId > 0;
            $confirmEmptyHtml = $canConfirmEmpty ? $this->confirmEmptyMonthHtml($monthKey, $companyId, $accountingPeriodId) : '';
            $monthCardClass = $this->monthStatusClass((string)($month['status'] ?? 'idle'));
            $monthContentHtml = '<div class="month-head">
                    <div>
                        <div class="month-name">' . HelperFramework::escape((string)($month['month'] ?? '')) . '</div>
                        ' . $monthYearHtml . '
                    </div>
                    <span class="month-dot"></span>
                </div>
                <div class="helper"><strong>' . (int)($month['transactions'] ?? 0) . ' transactions</strong></div>
                <div class="helper">' . (int)($month['auto_rows'] ?? 0) . ' auto rows, ' . (int)($month['auto_confirmed'] ?? 0) . ' confirmed, ' . (int)($month['auto_confirmed_posted'] ?? 0) . ' posted</div>
                <div class="helper">' . (int)($month['uncategorised'] ?? 0) . ' uncategorised</div>
                <div class="helper">' . (int)($month['deferred'] ?? 0) . ' deferred</div>
                <div class="helper">' . (int)($month['ready_to_post'] ?? 0) . ' unposted</div>
                <div class="helper">' . (int)($month['staged'] ?? 0) . ' staged</div>
                <div class="helper">' . (int)($month['raw_rows'] ?? 0) . ' raw rows</div>
                ' . $confirmedEmptyHtml;

            $monthsHtml .= '<div class="month-card-stack">
            <div class="' . HelperFramework::escape($monthCardClass) . '">
                ' . $this->monthSelectFormHtml($monthKey, $companyId, $accountingPeriodId, $selectedTransactionFilter, $selectedAccountFilter, $monthContentHtml) . '
                ' . $confirmEmptyHtml . '
            </div>
            </div>';
        }

        return '<div class="month-grid">' . $monthsHtml . '</div>';
    }

    private function monthSelectFormHtml(
        string $monthKey,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionFilter,
        int $selectedAccountFilter,
        string $monthContentHtml
    ): string {
        return '<form class="month-card-form month-card-select-form" method="post" action="?page=transactions" data-ajax="true">
            ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Transaction">
            <input type="hidden" name="global_action" value="select_transaction_month">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="month_key" value="' . HelperFramework::escape($monthKey) . '">
            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
            <input type="hidden" name="account_filter" value="' . $selectedAccountFilter . '">
            <button class="month-card-select" type="submit" data-page-card-switch-tab="Categorise">' . $monthContentHtml . '</button>
        </form>';
    }

    private function confirmEmptyMonthHtml(string $monthKey, int $companyId, int $accountingPeriodId): string
    {
        if ($monthKey === '') {
            return '';
        }

        return '<form class="month-card-form month-card-confirm-form" method="post" action="?page=transactions" data-ajax="true">
            ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="YearEnd">
            <input type="hidden" name="intent" value="confirm_empty_month">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="month_start" value="' . HelperFramework::escape($monthKey) . '">
            <button class="button" type="submit">Confirm no activity</button>
        </form>';
    }

    private function monthStatusClass(string $status): string
    {
        return match ($status) {
            'bad', 'attention', 'uncategorised', 'red' => 'month-card month-card-bad',
            'warn', 'warning', 'ready', 'amber' => 'month-card month-card-warn',
            'good', 'ok', 'complete', 'posted', 'green' => 'month-card month-card-ok',
            default => 'month-card',
        };
    }
}
