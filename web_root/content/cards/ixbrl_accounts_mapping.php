<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_accounts_mappingCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_accounts_mapping'; }

    public function title(): string { return 'Statutory Accounts Mapping'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $mapping = (array)($context['ixbrl']['accounts_mapping'] ?? []);
        $buckets = (array)($mapping['buckets'] ?? []);
        $sources = (array)($mapping['sources'] ?? []);
        $labels = [
            'current_assets' => 'Current assets',
            'fixed_assets' => 'Fixed assets',
            'creditors_within_one_year' => 'Creditors within one year',
            'creditors_after_one_year' => 'Creditors after one year',
            'net_current_assets_liabilities' => 'Net current assets / liabilities',
            'total_assets_less_current_liabilities' => 'Total assets less current liabilities',
            'net_assets_liabilities' => 'Net assets / liabilities',
            'equity' => 'Equity / capital and reserves',
        ];

        $rows = '';
        foreach ($labels as $key => $label) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape($label) . '</td>
                <td class="amount">' . HelperFramework::escape(FormattingFramework::money($buckets[$key] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->sourceSummary((array)($sources[$key] ?? []))) . '</td>
            </tr>';
        }

        $assumptions = '';
        foreach ((array)($mapping['assumptions'] ?? []) as $assumption) {
            $assumptions .= '<div class="helper">' . HelperFramework::escape((string)$assumption) . '</div>';
        }

        return '<div class="settings-stack">
            <div class="table-scroll"><table class="data-table">
                <thead><tr><th>Bucket</th><th>Amount</th><th>Source explanation</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table></div>
            <section class="panel-soft">' . $assumptions . '</section>
        </div>';
    }

    private function sourceSummary(array $sources): string
    {
        if ($sources === []) {
            return 'Derived calculation / no direct nominal sources.';
        }

        return implode('; ', array_slice(array_map(static fn(array $row): string => (string)($row['label'] ?? ''), $sources), 0, 4));
    }
}
