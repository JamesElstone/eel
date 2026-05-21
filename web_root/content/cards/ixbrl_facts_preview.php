<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_facts_previewCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_facts_preview'; }

    public function title(): string { return 'iXBRL Facts Preview'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $facts = (array)($context['ixbrl']['facts'] ?? []);
        $disabled = !empty($readiness['can_build_facts']) ? '' : ' disabled';

        $rows = '';
        foreach ($facts as $fact) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($fact['fact_key'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($fact['taxonomy_concept'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($fact['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($fact['context_ref'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->value($fact)) . '</td>
                <td>' . HelperFramework::escape((string)($fact['source_json'] ?? '')) . '</td>
            </tr>';
        }

        $table = $facts === []
            ? '<div class="helper">No generated facts yet. Build facts once the readiness checks pass.</div>'
            : '<div class="table-scroll"><table class="data-table"><thead><tr><th>Fact key</th><th>Taxonomy concept</th><th>Label</th><th>Context</th><th>Value</th><th>Source</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return '<div class="settings-stack">
            <form method="post" action="?page=ixbrl_builder" data-ajax="true" class="actions-row">
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="build_ixbrl_facts">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                <button class="button primary" type="submit"' . $disabled . '>Build / Refresh Facts</button>
            </form>
            ' . $table . '
        </div>';
    }

    private function value(array $fact): string
    {
        return match ((string)($fact['value_type'] ?? 'text')) {
            'numeric' => FormattingFramework::money($fact['numeric_value'] ?? 0),
            'date' => (string)($fact['date_value'] ?? ''),
            default => (string)($fact['text_value'] ?? ''),
        };
    }
}
