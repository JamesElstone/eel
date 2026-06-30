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
        $paymentCandidates = (array)($data['payment_candidates'] ?? []);
        $filters = (array)($data['filters'] ?? []);

        if ($claim === []) {
            return '<section class="panel-soft">
                <div class="status-head"><h3 class="card-title">Claim Editor</h3></div>
                <div class="helper">Create or open a claim to start capturing lines and repayments.</div>
            </section>';
        }

        $claimId = (int)($claim['id'] ?? 0);
        $isPosted = !empty($claim['is_posted']);
        $dateFormat = (string)($companySettings['date_format'] ?? 'd/m/Y');
        $bulkPreview = is_array($context['expense_bulk_preview'] ?? null) && (int)($context['expense_bulk_preview']['claim_id'] ?? 0) === $claimId
            ? (array)$context['expense_bulk_preview']
            : [];

        return '<section class="panel-soft">
            <div class="status-head">
                <div>
                    <h3 class="card-title">Claim Editor</h3>
                    <div class="helper">' . HelperFramework::escape((string)($claim['claim_reference_code'] ?? '')) . ' · ' . HelperFramework::escape((string)($claim['claimant_name'] ?? '')) . ' · ' . HelperFramework::escape($this->monthLabel((int)($claim['claim_month'] ?? 0), (int)($claim['claim_year'] ?? 0))) . '</div>
                </div>
                <span class="badge ' . ($isPosted ? 'success' : 'warning') . '">' . HelperFramework::escape((string)($claim['status_label'] ?? 'Draft')) . '</span>
            </div>
            <div class="summary-grid four">
                <div class="summary-card"><div class="summary-label">A brought forward</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['A'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">B claimed</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['B'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">C paid</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['C'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">D carried forward</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['D'] ?? 0)) . '</div></div>
            </div>
            ' . ($isPosted ? '' : $this->renderBulkPastePanel($claimId, $companyId, $dateFormat, $bulkPreview)) . '
            ' . $this->renderLinesTable((array)($claim['lines'] ?? []), $nominals, $claimId, $isPosted, $companyId, $dateFormat) . '
            ' . ($isPosted ? '<div class="helper">Posted claims are locked.</div>' : $this->renderLineForm($claim, $nominals, $claimId, $companySettings, $companyId)) . '
            ' . $this->renderPaymentsPanel((array)($claim['payment_links'] ?? []), $paymentCandidates, $claim, $companySettings, $filters, $claimId, $isPosted, $companyId, $dateFormat) . '
        </section>';
    }

    private function renderBulkPastePanel(int $claimId, int $companyId, string $dateFormat, array $preview): string
    {
        $sourceText = (string)($preview['source_text'] ?? '');
        $rows = (array)($preview['rows'] ?? []);
        $previewRows = '';

        foreach ($rows as $row) {
            $previewRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['expense_date_display'] ?? $this->displayDate((string)($row['expense_date'] ?? ''), $dateFormat))) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)) . '</td>
            </tr>';
        }

        $previewHtml = '';
        if ($rows !== []) {
            $previewHtml = '<div class="table-scroll">
                <table>
                    <thead><tr><th>Date</th><th>Description</th><th>Amount</th></tr></thead>
                    <tbody>' . $previewRows . '</tbody>
                    <tfoot><tr><th colspan="2">Preview total</th><th>' . HelperFramework::escape(FormattingFramework::money($preview['total'] ?? 0)) . '</th></tr></tfoot>
                </table>
            </div>
            <form method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="bulk_save_lines">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="date_format" value="' . HelperFramework::escape($dateFormat) . '">
                <textarea name="pasted_lines" hidden>' . HelperFramework::escape($sourceText) . '</textarea>
                <div class="actions-row"><button class="button primary" type="submit">Import previewed lines</button></div>
            </form>';
        }

        return '<div class="panel-soft">
            <div class="status-head"><h4 class="card-title">Paste claim lines</h4></div>
            <div class="helper">Paste tab-delimited rows in this column order: DATE, DESCRIPTION, AMOUNT CLAIMED.</div>
            <form method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="preview_bulk_lines">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="date_format" value="' . HelperFramework::escape($dateFormat) . '">
                <div class="form-row">
                    <label for="expense-bulk-paste-' . $claimId . '">Claim lines</label>
                    <textarea class="input" id="expense-bulk-paste-' . $claimId . '" name="pasted_lines" rows="7">' . HelperFramework::escape($sourceText) . '</textarea>
                </div>
                <div class="actions-row"><button class="button" type="submit">Preview lines</button></div>
            </form>
            ' . $previewHtml . '
        </div>';
    }

    private function renderLinesTable(array $lines, array $nominals, int $claimId, bool $isPosted, int $companyId, string $dateFormat): string
    {
        $rows = '';
        foreach ($lines as $line) {
            $lineId = (int)($line['id'] ?? 0);
            $rows .= '<tr>
                <td>' . HelperFramework::escape($this->displayDate((string)($line['expense_date'] ?? ''), $dateFormat)) . '</td>
                <td>' . HelperFramework::escape((string)($line['description'] ?? '')) . '</td>
                <td>' . ($isPosted
                    ? HelperFramework::escape((string)($line['nominal_label'] ?? ''))
                    : $this->lineNominalForm($nominals, $claimId, $lineId, (int)($line['nominal_account_id'] ?? 0), $companyId)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($line['amount'] ?? 0)) . '</td>
                <td>' . ($isPosted ? '' : '<form method="post" action="?page=expenses" data-ajax="true">
                    <input type="hidden" name="card_action" value="Expense">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="intent" value="delete_line">
                    <input type="hidden" name="claim_id" value="' . $claimId . '">
                    <input type="hidden" name="line_id" value="' . $lineId . '">
                    <button class="button button-inline danger" type="submit">Remove</button>
                </form>') . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="helper">No expense lines have been added yet.</td></tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Description</th><th>Nominal</th><th>Amount</th><th></th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private function lineNominalForm(array $nominals, int $claimId, int $lineId, int $selectedNominalId, int $companyId): string
    {
        $formId = 'expense-line-nominal-form-' . $lineId;

        return '<form method="post" action="?page=expenses" id="' . $formId . '" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="update_line_nominal">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="line_id" value="' . $lineId . '">
                <button class="js-expense-line-nominal-submit" type="submit" hidden>Autosave</button>
            </form>
            <select class="select" name="nominal_account_id" form="' . $formId . '" data-autosave-submit-target=".js-expense-line-nominal-submit">' . $this->nominalOptions($nominals, $selectedNominalId, 'Unassigned') . '</select>';
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
                    <label for="expense-line-notes">Notes</label>
                    <input class="input" id="expense-line-notes" name="notes" form="' . $formId . '" type="text">
                </div>
            </div>
            <div class="actions-row">
                <button class="button primary" type="submit" form="' . $formId . '">Add line</button>
            </div>';
    }

    private function renderPaymentsPanel(array $payments, array $paymentCandidates, array $claim, array $companySettings, array $filters, int $claimId, bool $isPosted, int $companyId, string $dateFormat): string
    {
        $paymentQuery = (string)($filters['payment_query'] ?? '');

        return '<div class="panel-soft">
            <div class="status-head"><h4 class="card-title">Repayments</h4></div>
            <div class="helper">Link repayments from bank transactions in the month they were paid. The selected claim determines the claimant.</div>
            ' . $this->renderPaymentsTable($payments, $claimId, $isPosted, $companyId, $dateFormat) . '
            ' . ($isPosted ? '' : $this->renderPaymentCandidateSearch($paymentQuery, $claimId, $companyId) . $this->renderPaymentCandidatesTable($paymentCandidates, $claim, $companySettings, $claimId, $companyId, $dateFormat)) . '
        </div>';
    }

    private function renderPaymentsTable(array $payments, int $claimId, bool $isPosted, int $companyId, string $dateFormat): string
    {
        $rows = '';
        foreach ($payments as $payment) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape($this->displayDate((string)($payment['txn_date'] ?? ''), $dateFormat)) . '</td>
                <td>' . HelperFramework::escape((string)($payment['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($payment['reference'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($payment['linked_amount'] ?? 0)) . '</td>
                <td>' . ($isPosted ? '' : '<form method="post" action="?page=expenses" data-ajax="true">
                    <input type="hidden" name="card_action" value="Expense">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="intent" value="unlink_payment">
                    <input type="hidden" name="claim_id" value="' . $claimId . '">
                    <input type="hidden" name="payment_link_id" value="' . (int)($payment['id'] ?? 0) . '">
                    <button class="button button-inline danger" type="submit">Unlink</button>
                </form>') . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="helper">No repayments are linked to this claim.</td></tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Repayment</th><th>Reference</th><th>Linked</th><th></th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private function renderPaymentCandidateSearch(string $paymentQuery, int $claimId, int $companyId): string
    {
        return '<form class="toolbar expenses-toolbar" method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="filter_claims">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <div class="mini-field">
                <label for="expense-payment-query">Search repayments</label>
                <input class="input" id="expense-payment-query" name="payment_query" type="search" value="' . HelperFramework::escape($paymentQuery) . '" placeholder="Bank description or reference">
            </div>
            <button class="button" type="submit">Search</button>
        </form>';
    }

    private function renderPaymentCandidatesTable(array $paymentCandidates, array $claim, array $companySettings, int $claimId, int $companyId, string $dateFormat): string
    {
        $rows = '';
        foreach ($paymentCandidates as $candidate) {
            $availableAmount = round((float)($candidate['available_amount'] ?? 0), 2);
            $currentLinkAmount = round((float)($candidate['current_link_amount'] ?? 0), 2);
            $suggestedAmount = $currentLinkAmount > 0 ? $currentLinkAmount : $availableAmount;
            $canLink = $suggestedAmount > 0;

            $rows .= '<tr>
                <td>' . HelperFramework::escape($this->displayDate((string)($candidate['txn_date'] ?? ''), $dateFormat)) . '</td>
                <td>' . HelperFramework::escape((string)($candidate['description'] ?? '')) . '<div class="helper">' . HelperFramework::escape((string)($candidate['reference'] ?? '')) . '</div></td>
                <td>' . HelperFramework::escape(FormattingFramework::money($candidate['amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($availableAmount)) . '</td>
                <td>
                    <form method="post" action="?page=expenses" data-ajax="true">
                        <input type="hidden" name="card_action" value="Expense">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="intent" value="link_payment">
                        <input type="hidden" name="claim_id" value="' . $claimId . '">
                        <input type="hidden" name="transaction_id" value="' . (int)($candidate['id'] ?? 0) . '">
                        <input type="hidden" name="director_loan_nominal_id" value="' . (int)($companySettings['director_loan_nominal_id'] ?? 0) . '">
                        <input type="hidden" name="default_bank_nominal_id" value="' . (int)($companySettings['default_bank_nominal_id'] ?? 0) . '">
                        <div class="actions-row">
                            <input class="input" name="linked_amount" inputmode="decimal" value="' . HelperFramework::escape(number_format($suggestedAmount, 2, '.', '')) . '"' . ($canLink ? '' : ' disabled') . '>
                            <button class="button button-inline primary" type="submit"' . ($canLink ? '' : ' disabled') . '>' . ($currentLinkAmount > 0 ? 'Update' : 'Link') . '</button>
                        </div>
                    </form>
                </td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="helper">No candidate repayments were found for this claim month.</td></tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Transaction</th><th>Amount</th><th>Available</th><th>Link</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private function nominalOptions(array $nominals, int $selectedNominalId = 0, string $emptyLabel = 'Select nominal'): string
    {
        $html = '<option value="">' . HelperFramework::escape($emptyLabel) . '</option>';
        foreach ($nominals as $nominal) {
            $nominalId = (int)($nominal['id'] ?? 0);
            $html .= '<option value="' . $nominalId . '"' . ($nominalId === $selectedNominalId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return $html;
    }

    private function displayDate(string $value, string $format): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return (new DateTimeImmutable($value))->format($this->normaliseDateFormat($format));
    }

    private function normaliseDateFormat(string $format): string
    {
        return in_array($format, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'], true)
            ? $format
            : 'd/m/Y';
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
