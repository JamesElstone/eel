<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_readinessCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_readiness'; }

    public function title(): string { return 'iXBRL Readiness'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $company = (array)($readiness['company'] ?? $context['company'] ?? []);
        $accountingPeriod = (array)($readiness['accounting_period'] ?? []);
        $checks = (array)($readiness['checks'] ?? []);
        $comparison = (array)($readiness['companies_house_comparison'] ?? []);
        $companySettings = (array)($company['settings'] ?? ($context['company']['settings'] ?? []));

        $items = '';
        foreach ($checks as $check) {
            $items .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape((string)($check['label'] ?? 'Check')) . '</div>
                <div class="summary-value"><span class="badge ' . HelperFramework::escape((string)($check['status'] ?? 'warning')) . '">' . HelperFramework::escape(!empty($check['complete']) ? 'Ready' : (!empty($check['blocking']) ? 'Blocked' : 'Warning')) . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div>
            </div>';
        }

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">' . HelperFramework::escape((string)($company['company_name'] ?? 'Selected company')) . '</h3>
                    <span class="badge ' . (!empty($readiness['can_build_facts']) ? 'success' : 'danger') . '">' . HelperFramework::escape(!empty($readiness['can_build_facts']) ? 'Ready to build facts' : 'Blocked') . '</span>
                </div>
                <div class="helper">Period: ' . HelperFramework::escape((string)($accountingPeriod['period_start'] ?? '')) . ' to ' . HelperFramework::escape((string)($accountingPeriod['period_end'] ?? '')) . '</div>
                <div class="helper">This builder creates a generated FRS 105 micro-entity accounts iXBRL export for review and validation before filing.</div>
            </section>
            <section class="summary-grid">' . $items . '</section>
            ' . $this->renderCompaniesHouseComparison($comparison, $companySettings) . '
        </div>';
    }

    private function renderCompaniesHouseComparison(array $comparison, array $companySettings): string
    {
        if (empty($comparison['available'])) {
            return '<section class="panel-soft"><h3 class="card-title">Companies House Comparison</h3><div class="helper">' . HelperFramework::escape((string)($comparison['errors'][0] ?? 'No Companies House comparison is available.')) . '</div></section>';
        }

        $rows = '';
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['app_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['filed_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['variance'] ?? null)) . '</td>
                <td>' . HelperFramework::escape((string)($row['source_concept'] ?? '')) . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->badgeClass((string)($row['status'] ?? ''))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['status'] ?? ''), '_')) . '</span></td>
            </tr>';
        }

        $filing = (array)($comparison['filing'] ?? []);

        return '<section class="panel-soft" id="ixbrl-companies-house-comparison">
            <h3 class="card-title">Companies House Comparison</h3>
            <div class="helper">' . HelperFramework::escape((string)($comparison['comparison_note'] ?? '')) . '</div>
            <div class="helper">Filing date: ' . HelperFramework::escape((string)($filing['filing_date'] ?? '')) . ' | Period end: ' . HelperFramework::escape((string)($filing['period_end'] ?? '')) . '</div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Metric</th><th>Generated/app</th><th>Filed</th><th>Variance</th><th>Filed concept</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
        </section>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'matches' => 'success',
            'fail', 'differs' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
    }

    private function nullableMoney(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($companySettings, $value, '-');
    }
}
