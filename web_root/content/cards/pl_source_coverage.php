<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_source_coverageCard extends CardBaseFramework
{
    public function key(): string { return 'pl_source_coverage'; }

    public function title(): string { return 'Source Coverage'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $sources = (array)($context['profit_loss']['source_coverage'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        if ($sources === []) {
            return '<div class="helper">No source coverage data is available.</div>';
        }
        $html = '';
        $coverageSummary = (array)($sources['coverage_summary'] ?? []);
        foreach ($sources as $source) {
            if (!is_array($source) || !empty($source['is_summary'])) {
                continue;
            }
            $present = !empty($source['present']);
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($source['label'] ?? $source['source_type'] ?? '')) . '</td>
                <td><span class="badge ' . ($present ? 'success' : 'info') . '">' . ($present ? 'Present' : 'None') . '</span></td>
                <td>' . (int)($source['journal_count'] ?? 0) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $source['debit_total'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $source['credit_total'] ?? 0)) . '</td>
            </tr>';
        }
        $summary = (array)($context['profit_loss']['summary'] ?? []);
        $depreciation = (float)($summary['depreciation_expense'] ?? 0);
        $previewNote = $depreciation > 0.0
            ? '<div class="helper"><span class="badge info">Close preview</span> Journal coverage below is posted-source evidence only. The P&amp;L cards also include ' . HelperFramework::escape($this->money($companySettings, $depreciation)) . ' of Year End depreciation preview.</div>'
            : '';
        $reconciled = !empty($coverageSummary['reconciled']);
        $coverageNote = '<div class="helper"><span class="badge ' . ($reconciled ? 'success' : 'warning') . '">' . ($reconciled ? 'Reconciled' : 'Review') . '</span> Covered '
            . (int)($coverageSummary['covered_journal_count'] ?? 0) . ' of '
            . (int)($coverageSummary['posted_journal_count'] ?? 0) . ' posted journals; '
            . (int)($coverageSummary['uncovered_journal_count'] ?? 0) . ' uncovered.</div>';

        return '<div class="settings-stack">' . $previewNote . $coverageNote . '<div class="table-scroll"><table><thead><tr><th>Source</th><th>Status</th><th>Journals</th><th>Debits</th><th>Credits</th></tr></thead><tbody>' . $html . '</tbody></table></div></div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
