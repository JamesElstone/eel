<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_tax_readinessCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_tax_readiness';
    }

    public function title(): string
    {
        return 'Year End Tax Readiness';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndTaxReadiness',
                'service' => \eel_accounts\Service\YearEndTaxReadinessService::class,
                'method' => 'fetchCurrentPeriodEstimate',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $taxReadiness = (array)($context['services']['yearEndTaxReadiness'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);

        if (empty($taxReadiness['available'])) {
            return '<section class="settings-stack" id="tax-readiness"><h3 class="card-title">Tax Readiness Snapshot</h3><div class="helper">' . HelperFramework::escape((string)($taxReadiness['errors'][0] ?? 'Tax readiness is not available.')) . '</div></section>';
        }

        $stepsHtml = '';
        foreach ((array)($taxReadiness['steps'] ?? []) as $step) {
            $stepsHtml .= '<tr><td>' . HelperFramework::escape((string)($step['label'] ?? '')) . '</td><td>' . HelperFramework::escape($this->money($companySettings, $step['amount'] ?? 0)) . '</td></tr>';
        }

        $scheduleHtml = '';
        foreach ((array)($taxReadiness['schedule'] ?? []) as $row) {
            $scheduleHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['loss_created'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['loss_brought_forward'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['loss_utilised'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['loss_carried_forward'] ?? 0)) . '</td>
            </tr>';
        }

        $review = (array)((($context['year_end'] ?? [])['checklist'] ?? [])['review'] ?? []);
        $acknowledged = trim((string)($review['tax_readiness_acknowledged_at'] ?? '')) !== '';
        $acknowledgementForm = '';
        if ($companyId > 0 && $accountingPeriodId > 0) {
            $acknowledgementForm = '<form method="post" data-ajax="true" class="panel-soft stack" data-year-end-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_tax_readiness_acknowledgement">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <label class="checkbox-row">
                    <input type="checkbox" name="tax_readiness_acknowledgement" value="1"' . ($acknowledged ? ' checked' : '') . ' required data-year-end-ack-checkbox>
                    <span>I acknowledge that the corporation tax estimate, computation steps, and loss schedule have been reviewed before closing this Accounting Period</span>
                </label>
                <button class="button primary" type="submit"
                    ' . ($acknowledged ? '' : 'disabled ') . 'data-year-end-ack-submit
                    data-chicken-check="true"
                    data-chicken-title="Save tax readiness acknowledgement"
                    data-chicken-message="This records that the corporation tax readiness snapshot has been reviewed for this accounting period.<br><br>Continue?"
                    data-chicken-confirm-text="I Agree"
                    data-chicken-button-class="button danger">I Agree</button>
            </form>';
        }

        return '<section class="settings-stack" id="tax-readiness">
            <h3 class="card-title">Tax Readiness Snapshot</h3>
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Taxable profit</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['taxable_profit'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Taxable loss</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['taxable_loss'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Estimated CT</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['estimated_corporation_tax'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Losses c/f</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['losses_carried_forward'] ?? 0)) . '</div></div>
            </div>
            <h3 class="card-title">Corporation Tax Computation</h3>
            <div class="table-scroll"><table><thead><tr><th>Step</th><th>Amount</th></tr></thead><tbody>' . $stepsHtml . '</tbody></table></div>
            <h3 class="card-title">Loss schedule</h3>
            <div class="table-scroll"><table><thead><tr><th>Period</th><th>Loss created</th><th>Brought forward</th><th>Used</th><th>Carried forward</th></tr></thead><tbody>' . $scheduleHtml . '</tbody></table></div>
            <div class="actions-row">' . $acknowledgementForm . '</div>
        </section>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
