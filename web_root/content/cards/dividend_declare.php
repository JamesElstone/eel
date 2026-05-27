<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_declareCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_declare';
    }

    public function title(): string
    {
        return 'Declare Dividend';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $capacity = (array)($context['dividends']['capacity'] ?? []);
        $accountingPeriod = (array)($capacity['accounting_period'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $defaultDate = (string)($capacity['as_at_date'] ?? date('Y-m-d'));
        $availableReserves = round((float)($capacity['available_distributable_reserves'] ?? 0), 2);
        $canDeclare = !empty($capacity['available']) && $availableReserves > 0 && $companyId > 0 && $accountingPeriodId > 0;
        $disabled = $canDeclare ? '' : ' disabled';

        $helper = $canDeclare
            ? 'Maximum currently available: ' . FormattingFramework::money($availableReserves) . '.'
            : 'Dividend declaration is blocked until the selected period has positive available reserves.';

        return '<div class="settings-stack">
            <div class="helper">' . HelperFramework::escape($helper) . '</div>
            <form method="post" action="?page=dividends" data-ajax="true" class="form-grid">
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="declare_dividend">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <div class="form-row">
                    <label for="dividend_declaration_date">Declaration date</label>
                    <input class="input" id="dividend_declaration_date" type="date" name="declaration_date" value="' . HelperFramework::escape($defaultDate) . '" min="' . HelperFramework::escape($periodStart) . '" max="' . HelperFramework::escape($periodEnd) . '"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_amount">Amount</label>
                    <input class="input" id="dividend_amount" type="number" name="amount" step="0.01" min="0.01" max="' . HelperFramework::escape(number_format(max(0, $availableReserves), 2, '.', '')) . '"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_description">Description</label>
                    <input class="input" id="dividend_description" name="description" value="Interim dividend"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_settlement_target">Settlement target</label>
                    <select class="select" id="dividend_settlement_target" name="settlement_target"' . $disabled . '>
                        <option value="unpaid_dividend_liability">Unpaid dividend liability</option>
                        <option value="director_loan_liability">Director loan liability</option>
                    </select>
                </div>
                <div class="actions-row">
                    <button class="button primary" type="submit"' . $disabled . '>Declare Dividend</button>
                </div>
            </form>
        </div>';
    }
}
