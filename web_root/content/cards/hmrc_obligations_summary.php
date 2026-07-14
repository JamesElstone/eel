<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_obligations_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_obligations_summary'; }

    public function title(): string { return 'HMRC Obligations Summary'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $summary = (array)($context['hmrc_obligations']['summary'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $next = is_array($summary['next_deadline'] ?? null) ? (array)$summary['next_deadline'] : null;
        $nextLabel = $next !== null
            ? HelperFramework::labelFromKey((string)$next['obligation_type'], '_') . ' due ' . (string)$next['due_date']
            : 'No upcoming deadline';

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="summary-grid">
                    ' . $this->metric('Total currently owed', $this->money($companySettings, $summary['total_owed'] ?? 0)) . '
                    ' . $this->metric('Total overdue', $this->money($companySettings, $summary['total_overdue'] ?? 0)) . '
                    ' . $this->metric('Next HMRC deadline', $nextLabel) . '
                    ' . $this->metric('Overdue items', (string)(int)($summary['overdue_count'] ?? 0)) . '
                    ' . $this->metric('Unresolved previous periods', (string)(int)($summary['unresolved_previous_periods'] ?? 0)) . '
                    ' . $this->metric('CT600 filed / missing', (int)($summary['ct600_filed_count'] ?? 0) . ' / ' . (int)($summary['ct600_missing_count'] ?? 0)) . '
                </div>
            </section>
            <section class="panel-soft">
                <div class="helper">Corporation Tax payment is normally due 9 months and 1 day after the accounting period end. The CT600 / Company Tax Return is normally due 12 months after the period end.</div>
                <div class="helper">Companies House filing and HMRC filing are separate. HMRC fines are normally disallowable for Corporation Tax.</div>
                <div class="actions-row">
                    <a class="button button-inline" href="https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim38515" target="_blank" rel="noopener noreferrer">HMRC - BIM38515: Fines and Penalties</a>
                    <a class="button button-inline" href="https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm92190" target="_blank" rel="noopener noreferrer">HMRC - CTM92190: Late Corporation Tax Interest</a>
                    <a class="button button-inline" href="https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim45740" target="_blank" rel="noopener noreferrer">HMRC - BIM45740: Late-Paid Tax Interest</a>
                </div>
            </section>
        </div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
