<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_trial_balanceCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_trial_balance'; }

    public function title(): string { return 'iXBRL Trial Balance'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $rows = (array)($context['ixbrl']['trial_balance'] ?? []);
        $totals = (array)($context['ixbrl']['trial_balance_totals'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        if ($rows === []) {
            return '<div class="helper">No posted journal lines were found for this accounting period.</div>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>
                <td>' . HelperFramework::escape((string)($row['code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['subtype_code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td class="amount">' . HelperFramework::escape($this->money($companySettings, $row['total_debit'] ?? 0)) . '</td>
                <td class="amount">' . HelperFramework::escape($this->money($companySettings, $row['total_credit'] ?? 0)) . '</td>
                <td class="amount">' . HelperFramework::escape($this->money($companySettings, $row['net_movement'] ?? 0)) . '</td>
            </tr>';
        }

        $badge = !empty($totals['is_balanced']) ? '<span class="badge success">Balanced</span>' : '<span class="badge danger">Unbalanced</span>';

        return '<div class="settings-stack">
            <div class="status-head">' . $badge . '<span class="helper">Difference: ' . HelperFramework::escape($this->money($companySettings, $totals['difference'] ?? 0)) . '</span></div>
            <div class="table-scroll"><table class="data-table">
                <thead><tr><th>Code</th><th>Account</th><th>Subtype</th><th>Type</th><th>Debit</th><th>Credit</th><th>Net</th></tr></thead>
                <tbody>' . $body . '</tbody>
                <tfoot><tr><th colspan="4">Totals</th><th>' . HelperFramework::escape($this->money($companySettings, $totals['total_debit'] ?? 0)) . '</th><th>' . HelperFramework::escape($this->money($companySettings, $totals['total_credit'] ?? 0)) . '</th><th>' . HelperFramework::escape($this->money($companySettings, $totals['difference'] ?? 0)) . '</th></tr></tfoot>
            </table></div>
        </div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
