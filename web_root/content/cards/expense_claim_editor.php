<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_claim_editorCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'expense_claim_editor';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expensesPageData',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company.id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Expense Claim Editor';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['expensesPageData'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $companySettings = (array)($context['expense_page_settings'] ?? $company['settings'] ?? []);
        $claim = is_array($data['selected_claim'] ?? null) ? (array)$data['selected_claim'] : [];
        $nominals = (array)($data['nominal_accounts'] ?? []);

        if ($claim === []) {
            return '<section class="panel-soft">
                <div class="status-head"><h3 class="card-title">Claim Editor</h3></div>
                <div class="helper">Create or open a claim to start capturing lines and repayments.</div>
            </section>';
        }

        $claimId = (int)($claim['id'] ?? 0);
        $isPosted = !empty($claim['is_posted']);

        return '<section class="panel-soft">
            <div class="status-head">
                <div>
                    <h3 class="card-title">Claim Editor</h3>
                    <div class="helper">' . HelperFramework::escape((string)($claim['claim_reference_code'] ?? '')) . ' · ' . HelperFramework::escape((string)($claim['claimant_name'] ?? '')) . ' · ' . HelperFramework::escape($this->monthLabel((int)($claim['claim_month'] ?? 0), (int)($claim['claim_year'] ?? 0))) . '</div>
                </div>
                <span class="badge ' . ($isPosted ? 'success' : 'warning') . '">' . HelperFramework::escape((string)($claim['status_label'] ?? 'Draft')) . '</span>
            </div>
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">A brought forward</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['A'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">B claimed</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['B'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">C paid</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['C'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">D carried forward</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['D'] ?? 0)) . '</div></div>
            </div>
            ' . $this->renderLinesTable((array)($claim['lines'] ?? []), $claimId, $isPosted, $companyId) . '
            ' . ($isPosted ? '<div class="helper">Posted claims are locked.</div>' : $this->renderLineForm($claim, $nominals, $claimId, $companySettings, $companyId)) . '
            ' . $this->renderPaymentsTable((array)($claim['payment_links'] ?? [])) . '
        </section>';
    }

    private function renderLinesTable(array $lines, int $claimId, bool $isPosted, int $companyId): string
    {
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($line['expense_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($line['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($line['nominal_label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($line['amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape((string)($line['receipt_reference'] ?? '')) . '</td>
                <td>' . ($isPosted ? '' : '<form method="post" action="?page=expenses" data-ajax="true">
                    <input type="hidden" name="card_action" value="Expense">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="intent" value="delete_line">
                    <input type="hidden" name="claim_id" value="' . $claimId . '">
                    <input type="hidden" name="line_id" value="' . (int)($line['id'] ?? 0) . '">
                    <button class="button button-inline danger" type="submit">Remove</button>
                </form>') . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="helper">No expense lines have been added yet.</td></tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Description</th><th>Nominal</th><th>Amount</th><th>Receipt</th><th></th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private function renderLineForm(array $claim, array $nominals, int $claimId, array $companySettings, int $companyId): string
    {
        $formId = 'expense-line-form-' . $claimId;
        $defaultExpenseNominalId = (int)($companySettings['default_expense_nominal_id'] ?? 0);

        return '<form id="' . $formId . '" method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="save_line">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="default_expense_nominal_id" value="' . $defaultExpenseNominalId . '">
            </form>
            <div class="form-grid">
                <div class="form-row">
                    <label for="expense-line-date">Date</label>
                    <input class="input" id="expense-line-date" name="expense_date" form="' . $formId . '" type="date" value="' . HelperFramework::escape((string)($claim['period_end'] ?? '')) . '">
                </div>
                <div class="form-row">
                    <label for="expense-line-description">Description</label>
                    <input class="input" id="expense-line-description" name="description" form="' . $formId . '" type="text">
                </div>
                <div class="form-row">
                    <label for="expense-line-amount">Amount</label>
                    <input class="input" id="expense-line-amount" name="amount" form="' . $formId . '" inputmode="decimal">
                </div>
                <div class="form-row">
                    <label for="expense-line-nominal">Nominal</label>
                    <select class="select" id="expense-line-nominal" name="nominal_account_id" form="' . $formId . '">' . $this->nominalOptions($nominals, $defaultExpenseNominalId) . '</select>
                </div>
                <div class="form-row">
                    <label for="expense-line-receipt">Receipt reference</label>
                    <input class="input" id="expense-line-receipt" name="receipt_reference" form="' . $formId . '" type="text">
                </div>
                <div class="form-row">
                    <label for="expense-line-notes">Notes</label>
                    <input class="input" id="expense-line-notes" name="notes" form="' . $formId . '" type="text">
                </div>
            </div>
            <div class="actions-row">
                <button class="button primary" type="submit" form="' . $formId . '">Add line</button>
            </div>';
    }

    private function renderPaymentsTable(array $payments): string
    {
        $rows = '';
        foreach ($payments as $payment) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($payment['txn_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($payment['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($payment['reference'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($payment['linked_amount'] ?? 0)) . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="helper">No repayments are linked to this claim.</td></tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Repayment</th><th>Reference</th><th>Linked</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private function nominalOptions(array $nominals, int $selectedNominalId = 0): string
    {
        $html = '<option value="">Select nominal</option>';
        foreach ($nominals as $nominal) {
            $nominalId = (int)($nominal['id'] ?? 0);
            $html .= '<option value="' . $nominalId . '"' . ($nominalId === $selectedNominalId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return $html;
    }

    private function monthLabel(int $month, int $year): string
    {
        if ($month < 1 || $month > 12 || $year <= 0) {
            return '';
        }

        return $this->monthName($month) . ' ' . (string)$year;
    }

    private function monthName(int $month): string
    {
        $names = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        return (string)($names[$month] ?? '');
    }
}
