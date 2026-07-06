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
        return [];
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
        $taxReadiness = (array)($context['services']['yearEndTaxReadiness'] ?? (($context['year_end'] ?? [])['checklist'] ?? [])['tax_readiness'] ?? []);
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
        $taxWorkflowButton = \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
            'tax',
            'Open Tax Workflow',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ],
            'button primary'
        );

        $review = (array)((($context['year_end'] ?? [])['checklist'] ?? [])['review'] ?? []);
        $acknowledged = trim((string)($review['tax_readiness_acknowledged_at'] ?? '')) !== '';
        $acknowledgementForm = $this->acknowledgementHtml(
            $acknowledged,
            (string)($review['tax_readiness_acknowledged_at'] ?? ''),
            (string)($review['tax_readiness_acknowledged_by'] ?? ''),
            (string)($review['tax_readiness_approval_note'] ?? ''),
            $companyId,
            $accountingPeriodId
        );

        return '<section class="settings-stack" id="tax-readiness">
            <h3 class="card-title">Tax Readiness Snapshot</h3>
            <div class="status-head">
                <span class="badge info">Estimate</span>
                <span class="badge ' . HelperFramework::escape($confidenceStatus === 'ready_for_review' ? 'success' : 'warning') . '">' . HelperFramework::escape($confidenceLabel) . '</span>
                <span class="badge ' . HelperFramework::escape($acknowledged ? 'success' : 'warning') . '">' . HelperFramework::escape($acknowledged ? 'Approved' : 'Approval pending') . '</span>
            </div>
            ' . ($warningHtml !== '' ? '<section class="panel-soft stack"><h3 class="card-title">Review warnings</h3>' . $warningHtml . '</section>' : '') . '
            ' . $this->periodSnapshots($companySettings, $taxReadiness) . '
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Taxable profit</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['taxable_profit'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Estimated Corporation Tax (CT)</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['estimated_corporation_tax'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Warnings</div><div class="summary-value">' . $warningCount . '</div></div>
                <div class="summary-card"><div class="summary-label">Losses carried forward (c/f)</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $taxReadiness['losses_carried_forward'] ?? 0)) . '</div></div>
            </div>
            <div class="actions-row">' . $taxWorkflowButton . '</div>
            ' . $acknowledgementForm . '
        </section>';
    }

    private function acknowledgementHtml(bool $acknowledged, string $acknowledgedAt, string $acknowledgedBy, string $note, int $companyId, int $accountingPeriodId): string
    {
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'corporation tax readiness',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => $acknowledged,
            'acknowledgedAt' => $acknowledgedAt,
            'acknowledgedBy' => $acknowledgedBy,
            'note' => $note,
            'intent' => 'save_tax_readiness_acknowledgement',
            'revokeIntent' => 'save_tax_readiness_acknowledgement',
            'checkboxName' => 'tax_readiness_acknowledgement',
            'approveFields' => ['tax_readiness_acknowledgement' => '1'],
            'revokeFields' => ['tax_readiness_acknowledgement' => '0'],
        ]);
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function periodSnapshots(array $companySettings, array $taxReadiness): string
    {
        $periods = array_values(array_filter(
            (array)($taxReadiness['periods'] ?? []),
            static fn(mixed $period): bool => is_array($period)
        ));
        if ($periods === []) {
            return '';
        }

        $html = '<section class="summary-grid">';
        foreach ($periods as $period) {
            $warningCount = count((array)($period['warnings'] ?? []));
            $html .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape($this->periodHeading($period)) . '</div>
                <div class="helper">Taxable profit: ' . HelperFramework::escape($this->money($companySettings, $period['taxable_profit'] ?? 0)) . '</div>
                <div class="helper">Estimated CT: ' . HelperFramework::escape($this->money($companySettings, $period['estimated_corporation_tax'] ?? 0)) . '</div>
                <div class="helper">Losses c/f: ' . HelperFramework::escape($this->money($companySettings, $period['losses_carried_forward'] ?? 0)) . '</div>
                <div><span class="badge ' . HelperFramework::escape($warningCount === 0 ? 'success' : 'warning') . '">' . HelperFramework::escape($warningCount === 0 ? 'Ready' : ($warningCount . ' warning' . ($warningCount === 1 ? '' : 's'))) . '</span></div>
            </div>';
        }

        return $html . '</section>';
    }

    private function periodHeading(array $period): string
    {
        $label = trim((string)($period['period_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        return trim($start . ' to ' . $end) !== 'to' ? trim($start . ' to ' . $end) : 'CT period';
    }
}
