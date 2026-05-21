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
        if ($sources === []) {
            return '<div class="helper">No source coverage data is available.</div>';
        }
        $html = '';
        foreach ($sources as $source) {
            $present = !empty($source['present']);
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($source['label'] ?? $source['source_type'] ?? '')) . '</td>
                <td><span class="badge ' . ($present ? 'success' : 'info') . '">' . ($present ? 'Present' : 'None') . '</span></td>
                <td>' . (int)($source['journal_count'] ?? 0) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($source['debit_total'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($source['credit_total'] ?? 0)) . '</td>
            </tr>';
        }
        return '<div class="table-scroll"><table><thead><tr><th>Source</th><th>Status</th><th>Journals</th><th>Debits</th><th>Credits</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }
}
