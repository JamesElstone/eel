<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_stateCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'director_loan_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'directorLoanStatement',
                'service' => \eel_accounts\Service\DirectorLoanService::class,
                'method' => 'fetchStatement',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Director Loan Statement';
    }

    protected function additionalInvalidationFacts(): array
    {
        return [];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);

        if (empty($statement['success'])) {
            return $this->renderErrors((array)($statement['errors'] ?? ['Director loan statement is not available for the selected period.']));
        }

        $accountingPeriod = (array)($statement['accounting_period'] ?? []);
        $nominal = (array)($statement['director_loan_nominal'] ?? []);
        $hasMovements = !empty($statement['has_movements_in_period']);
        $rowsHtml = '';

        foreach ((array)($statement['statement_rows'] ?? []) as $row) {
            $isOpening = (string)($row['row_type'] ?? '') === 'opening_balance';
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($row['journal_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</td>
                <td>' . ($isOpening ? '<span class="helper">Opening</span>' : HelperFramework::escape(HelperFramework::labelFromKey((string)($row['source_type'] ?? ''), '_'))) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($row['signed_amount'] ?? null, '')) . '</td>
                <td>' . HelperFramework::escape($this->money($statement, $row['running_balance'] ?? 0)) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5">No director loan movements were found for this period.</td></tr>';
        }

        return '
            <section class="settings-stack">
                <div class="helper">Using ' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal, ' ')) . ' as the Director Loan nominal.</div>
            </section>
            <div class="month-grid">
                ' . $this->statCard('Opening balance', $this->money($statement, $statement['opening_balance'] ?? 0)) . '
                ' . $this->statCard('Movement in period', $this->money($statement, $statement['movement_in_period'] ?? 0)) . '
                ' . $this->statCard('Closing balance', $this->money($statement, $statement['closing_balance'] ?? 0)) . '
                ' . $this->statCard('Status', (string)($statement['balance_direction_label'] ?? '')) . '
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Date processed / transaction date</th><th>Description</th><th>Source</th><th>Amount</th><th>Balance</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
            ' . (!$hasMovements ? '<div class="helper">No Director Loan movements were found for this accounting period.</div>' : '') . '
        ';
    }

    private function statCard(string $label, string $value): string
    {
        return '<div class="stat-card"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $statement, mixed $value): string
    {
        $symbol = (string)($statement['default_currency_symbol'] ?? '£');

        return $symbol . FormattingFramework::money($value);
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
