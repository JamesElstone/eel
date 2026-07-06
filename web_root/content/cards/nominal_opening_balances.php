<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominal_opening_balancesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominal_opening_balances';
    }

    public function title(): string
    {
        return 'Nominal Opening Balances';
    }

    public function helper(array $context): string
    {
        return 'Post one balanced opening balance journal for this accounting period. Suggestions from the immediately prior Companies House figures can be edited before saving.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'openingBalances',
                'service' => \eel_accounts\Service\OpeningBalanceService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'trial.balance.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $openingBalances = (array)($context['services']['openingBalances'] ?? []);

        if (empty($openingBalances['available'])) {
            return '<section class="settings-stack" id="opening-balances"><h3 class="card-title">Opening Balances</h3>' . $this->renderErrors((array)($openingBalances['errors'] ?? [])) . '</section>';
        }

        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($openingBalances['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $existing = is_array($openingBalances['existing_journal'] ?? null) ? (array)$openingBalances['existing_journal'] : [];
        $defaultRows = (array)($existing['lines'] ?? []);
        if ($defaultRows === []) {
            $defaultRows = (array)($openingBalances['suggestions'] ?? []);
        }

        $formId = 'nominal-opening-balance-form';
        $description = $existing !== []
            ? (string)($existing['description'] ?? '')
            : 'Opening balances for ' . (string)($accountingPeriod['label'] ?? 'selected period');

        return '<section class="settings-stack" id="opening-balances">
            <form id="' . $formId . '" method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_opening_balance">
                <input type="hidden" name="show_card" value=".self">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            </form>
            <section class="panel-soft settings-stack">
                ' . $this->lineEditorTable('opening_balance', $formId, (array)($openingBalances['nominals'] ?? []), $defaultRows, 8) . '
                ' . $this->balanceSummary($defaultRows, $companySettings) . '
            </section>
            <section class="panel-soft">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="opening-balance-description">Description</label>
                        <input class="input" id="opening-balance-description" name="opening_balance_description" form="' . $formId . '" value="' . HelperFramework::escape($description) . '">
                    </div>
                    <div class="form-row">
                        <label for="opening-balance-notes">Notes</label>
                        <input class="input" id="opening-balance-notes" name="opening_balance_notes" form="' . $formId . '" value="' . HelperFramework::escape((string)($existing['notes'] ?? '')) . '">
                    </div>
                </div>
            </section>
            <section class="panel-soft">
                <div class="actions-row">
                    <label class="checkbox-item"><input type="checkbox" id="opening-balance-system-mode" name="opening_balance_system_mode" form="' . $formId . '" value="1"><div class="checkbox-copy"><strong>Mark as system-generated</strong><span>Use when the journal is seeded from prior filed figures or another controlled source.</span></div></label>
                    <label class="checkbox-item"><input type="checkbox" id="opening-balance-replace" name="opening_balance_replace" form="' . $formId . '" value="1"' . ($existing !== [] ? ' checked' : '') . '><div class="checkbox-copy"><strong>Replace existing opening balance</strong><span>Required when an active opening balance journal already exists.</span></div></label>
                </div>
            </section>
            <section class="panel-soft settings-stack">
                <div class="helper">Total debits must equal total credits before the opening balance journal can be saved.</div>
                <div class="actions-row"><button class="button primary" type="submit" form="' . $formId . '">Save Opening Balances</button></div>
            </section>
        </section>';
    }

    private function lineEditorTable(string $prefix, string $formId, array $nominals, array $rows, int $rowCount): string
    {
        $html = '';
        for ($index = 0; $index < $rowCount; $index++) {
            $row = (array)($rows[$index] ?? []);
            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            $description = (string)($row['line_description'] ?? '');
            $html .= '<tr>
                <td><select class="select" name="' . $prefix . '_line_' . $index . '_nominal_id" form="' . $formId . '">' . $this->nominalOptions($nominals, $nominalId) . '</select></td>
                <td><input class="input" name="' . $prefix . '_line_' . $index . '_debit" form="' . $formId . '" value="' . HelperFramework::escape($this->lineAmount($row, 'debit')) . '" inputmode="decimal"></td>
                <td><input class="input" name="' . $prefix . '_line_' . $index . '_credit" form="' . $formId . '" value="' . HelperFramework::escape($this->lineAmount($row, 'credit')) . '" inputmode="decimal"></td>
                <td><input class="input" name="' . $prefix . '_line_' . $index . '_description" form="' . $formId . '" value="' . HelperFramework::escape($description) . '"></td>
            </tr>';
        }

        return '<div class="table-scroll"><table><thead><tr><th>Nominal</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }

    private function nominalOptions(array $nominals, int $selectedNominalId): string
    {
        $html = '<option value="">Select nominal</option>';
        foreach ($nominals as $nominal) {
            $nominalId = (int)($nominal['id'] ?? 0);
            $html .= '<option value="' . $nominalId . '"' . ($nominalId === $selectedNominalId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return $html;
    }

    private function lineAmount(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        if ($value === '' || $value === null) {
            return '';
        }

        return number_format((float)$value, 2, '.', '');
    }

    private function balanceSummary(array $rows, array $companySettings): string
    {
        $debitTotal = 0.0;
        $creditTotal = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $debitTotal += (float)($row['debit'] ?? 0);
            $creditTotal += (float)($row['credit'] ?? 0);
        }

        $difference = round($debitTotal - $creditTotal, 2);
        $balanced = abs($difference) < 0.005;

        return '<div class="status-head">
            <div class="helper">Debits ' . HelperFramework::escape($this->money($companySettings, $debitTotal)) . ' | Credits ' . HelperFramework::escape($this->money($companySettings, $creditTotal)) . ' | Difference ' . HelperFramework::escape($this->money($companySettings, $difference)) . '</div>
            <span class="badge ' . ($balanced ? 'success' : 'warning') . '">' . ($balanced ? 'Balanced' : 'Out of balance') . '</span>
        </div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }
}
