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

        $warningHtml = '';
        foreach ((array)($taxReadiness['warnings'] ?? []) as $warning) {
            $warningHtml .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }
        $warningCount = count((array)($taxReadiness['warnings'] ?? []));
        $confidenceStatus = (string)($taxReadiness['confidence_status'] ?? 'review_required');
        $confidenceLabel = (string)($taxReadiness['confidence_label'] ?? 'Review required');
        $taxWorkflowUrl = '?page=tax&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId;

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
                    <span>I acknowledge that the corporation tax workings have been reviewed before closing this Accounting Period</span>
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
            <div class="status-head">
                <span class="badge info">Estimate</span>
                <span class="badge ' . HelperFramework::escape($confidenceStatus === 'ready_for_review' ? 'success' : 'warning') . '">' . HelperFramework::escape($confidenceLabel) . '</span>
                <span class="badge ' . HelperFramework::escape($acknowledged ? 'success' : 'warning') . '">' . HelperFramework::escape($acknowledged ? 'Acknowledged' : 'Acknowledgement pending') . '</span>
            </div>
            ' . ($warningHtml !== '' ? '<section class="panel-soft stack"><h3 class="card-title">Review warnings</h3>' . $warningHtml . '</section>' : '') . '
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Taxable profit</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['taxable_profit'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Estimated CT</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['estimated_corporation_tax'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Warnings</div><div class="summary-value">' . $warningCount . '</div></div>
                <div class="summary-card"><div class="summary-label">Losses c/f</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['losses_carried_forward'] ?? 0)) . '</div></div>
            </div>
            <div class="actions-row"><a class="button primary" href="' . HelperFramework::escape($taxWorkflowUrl) . '">Open Tax Workflow</a></div>
            <div class="actions-row">' . $acknowledgementForm . '</div>
        </section>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
