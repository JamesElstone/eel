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

    public function services(): array
    {
        return [
            $this->dividendContextService(),
            [
                'key' => 'dividendReconciliationCandidates',
                'service' => \eel_accounts\Service\DividendService::class,
                'method' => 'listDividendReconciliationCandidates',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $dividends = $this->dividendsContext($context);
        $capacity = (array)($dividends['capacity'] ?? []);
        $accountingPeriod = (array)($capacity['accounting_period'] ?? []);
        $candidates = (array)($dividends['reconciliation_candidates'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $defaultDate = (string)($capacity['as_at_date'] ?? date('Y-m-d'));
        $availableReserves = round((float)($capacity['available_distributable_reserves'] ?? 0), 2);
        $isLocked = array_key_exists('is_locked', $dividends)
            ? (bool)$dividends['is_locked']
            : (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);

        $disabledReason = '';
        if ($isLocked) {
            $disabledReason = 'This accounting period is locked.';
        } elseif ($companyId <= 0 || $accountingPeriodId <= 0) {
            $disabledReason = 'Select a company and accounting period before declaring a dividend.';
        } elseif (empty($capacity['available'])) {
            $capacityErrors = (array)($capacity['errors'] ?? []);
            $disabledReason = (string)($capacityErrors[0] ?? 'Dividend capacity is not available.');
        } elseif (empty($capacity['reserves_reliable'])) {
            $disabledReason = (string)($capacity['reserve_basis_detail'] ?? $capacity['retained_earnings_detail'] ?? 'Reserve basis is not ready for dividend declarations.');
        } elseif ($availableReserves < 0) {
            $disabledReason = 'Available distributable reserves are negative.';
        } elseif ($availableReserves <= 0) {
            $disabledReason = 'The selected period has no positive available reserves.';
        }

        $canDeclare = $disabledReason === '';
        $disabled = $canDeclare ? '' : ' disabled';
        $helper = $canDeclare
            ? 'Maximum currently available: ' . $this->money($companySettings, $availableReserves) . '.'
            : 'Dividend declarations can be saved only once the form is enabled.';
        $statusItems = $canDeclare
            ? ''
            : '<div class="helper">Form Disabled - Reason: ' . HelperFramework::escape($disabledReason) . '</div>';
        $statusItems .= '<div class="helper">' . HelperFramework::escape($helper) . '</div>';
        $statusPanelClass = 'panel-soft dividend-declare-status ' . ($canDeclare ? 'success' : 'warn');

        $candidateOptions = '<option value="0">Not reconciled yet - save as draft</option>';
        foreach ($candidates as $candidate) {
            $candidateId = (int)($candidate['id'] ?? 0);
            if ($candidateId <= 0) {
                continue;
            }
            $label = trim((string)($candidate['txn_date'] ?? '')
                . ' - ' . $this->money($companySettings, abs((float)($candidate['amount'] ?? 0)))
                . ' - ' . (string)($candidate['description'] ?? ''));
            $candidateOptions .= '<option value="' . $candidateId . '">' . HelperFramework::escape($label) . '</option>';
        }

        return '<div class="settings-stack">
            <div class="' . $statusPanelClass . '">' . $statusItems . '</div>
            <form method="post" action="?page=dividends" data-ajax="true" class="form-grid">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="declare_dividend">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <div class="form-row">
                    <label for="dividend_declaration_date">Declaration date</label>
                    <input class="input" id="dividend_declaration_date" type="date" name="declaration_date" value="' . HelperFramework::escape($defaultDate) . '" min="' . HelperFramework::escape($periodStart) . '" max="' . HelperFramework::escape($defaultDate !== '' ? $defaultDate : $periodEnd) . '"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_amount">Amount</label>
                    <input class="input" id="dividend_amount" type="number" name="amount" step="0.01" min="0.01" max="' . HelperFramework::escape(number_format(max(0, $availableReserves), 2, '.', '')) . '"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_reconciliation_transaction_id">Reconcile with transaction</label>
                    <select class="select" id="dividend_reconciliation_transaction_id" name="reconciliation_transaction_id"' . $disabled . '>
                        ' . $candidateOptions . '
                    </select>
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

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function dividendContextService(): array
    {
        return [
            'key' => 'dividendContext',
            'service' => \eel_accounts\Service\DividendViewDataService::class,
            'method' => 'fetchCapacityContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ];
    }

    private function dividendsContext(array $context): array
    {
        $serviceContext = $context['services']['dividendContext'] ?? null;
        if (is_array($serviceContext)) {
            $serviceContext['reconciliation_candidates'] = (array)($context['services']['dividendReconciliationCandidates'] ?? []);
            return $serviceContext;
        }

        return (array)($context['dividends'] ?? []);
    }
}
