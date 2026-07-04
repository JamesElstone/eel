<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_net_profit_bridgeCard extends CardBaseFramework
{
    public function key(): string { return 'pl_net_profit_bridge'; }

    public function title(): string { return 'Net Profit Bridge'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $summary = (array)($context['profit_loss']['summary'] ?? []);
        if (empty($summary['available'])) {
            return '<div class="helper">Profit bridge is not available for the selected period.</div>';
        }
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $rows = [
            ['Income', $summary['income_total'] ?? 0, ''],
            ['Less cost of sales', -1 * (float)($summary['cost_of_sales_total'] ?? 0), ''],
            ['Gross profit', $summary['gross_profit'] ?? 0, 'strong'],
            ['Less expenses', -1 * (float)($summary['expense_total'] ?? 0), ''],
            ['Net profit / loss', $summary['net_profit'] ?? 0, 'strong'],
        ];
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . ($row[2] === 'strong' ? '<strong>' : '') . HelperFramework::escape((string)$row[0]) . ($row[2] === 'strong' ? '</strong>' : '') . '</td><td>' . HelperFramework::escape($this->money($companySettings, $row[1])) . '</td></tr>';
        }
        return '<div class="table-scroll"><table><tbody>' . $html . '</tbody></table></div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
