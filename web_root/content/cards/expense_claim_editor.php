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
    private const PAGE_SIZE = 20;
    private const TABLE_PREVIEW = 'expense_claim_editor_preview';
    private const TABLE_LINES = 'expense_claim_editor_lines';
    private const TABLE_PAYMENTS = 'expense_claim_editor_payments';
    private const TABLE_PAYMENT_CANDIDATES = 'expense_claim_editor_payment_candidates';

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

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        foreach ([self::TABLE_PREVIEW, self::TABLE_LINES, self::TABLE_PAYMENTS, self::TABLE_PAYMENT_CANDIDATES] as $scope) {
            $pageContext = $this->applyPaginationContext($request, $pageContext, $scope);
        }

        return $pageContext;
    }

    public function tables(array $context): array
    {
        $data = (array)($context['services']['expensesPageData'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $companySettings = (array)($context['expense_page_settings'] ?? $company['settings'] ?? []);
        $claim = is_array($data['selected_claim'] ?? null) ? (array)$data['selected_claim'] : [];
        $claimId = (int)($claim['id'] ?? 0);
        $isPosted = !empty($claim['is_posted']);
        $dateFormat = (string)($companySettings['date_format'] ?? 'd/m/Y');
        $bulkPreview = $claimId > 0 ? $this->bulkPreviewFromContext($context, $claimId, $dateFormat) : [];

        return [
            $this->previewTable((array)($bulkPreview['rows'] ?? []), $dateFormat),
            $this->linesTable((array)($claim['lines'] ?? []), (array)($data['nominal_accounts'] ?? []), $claimId, $isPosted, $companyId, $dateFormat),
            $this->paymentsTable((array)($claim['payment_links'] ?? []), $claimId, $isPosted, $companyId, $dateFormat),
            $this->paymentCandidatesTable((array)($data['payment_candidates'] ?? []), $companySettings, $claimId, $companyId, $dateFormat),
        ];
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
            return '<div class="helper">Create or open a claim to start capturing lines and repayments.</div>';
        }

        $claimId = (int)($claim['id'] ?? 0);
        $isPosted = !empty($claim['is_posted']);
        $dateFormat = (string)($companySettings['date_format'] ?? 'd/m/Y');
        $bulkPreview = $this->bulkPreviewFromContext($context, $claimId, $dateFormat);
        $claimReference = (string)($claim['claim_reference_code'] ?? '');
        $claimantName = (string)($claim['claimant_name'] ?? '');
        $claimMonthLabel = $this->monthLabel((int)($claim['claim_month'] ?? 0), (int)($claim['claim_year'] ?? 0));

        return '<div class="summary-grid expense-claim-summary-grid">
                <div class="summary-card"><div class="summary-label">Claim Reference</div><div class="summary-value">' . HelperFramework::escape($claimReference) . '</div></div>
                <div class="summary-card"><div class="summary-label">Claimant</div><div class="summary-value">' . HelperFramework::escape($claimantName) . '</div></div>
                <div class="summary-card"><div class="summary-label">Claim Month</div><div class="summary-value">' . HelperFramework::escape($claimMonthLabel) . '</div></div>
                <div class="summary-card"><div class="summary-label">Brought Forwards (A)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['A'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">In this claim (B)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['B'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Paid in this period (C)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['C'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Carried Forward (D=A+B-C)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($claim['control_totals']['D'] ?? 0)) . '</div></div>
            </div>
            ' . ($isPosted ? '' : $this->renderBulkPastePanel($claimId, $companyId, $dateFormat, $bulkPreview, $context)) . '
            ' . ($isPosted ? '<div class="helper">Posted claims are locked.</div>' : $this->renderLineForm($claim, $nominals, $claimId, $companySettings, $companyId)) . '
            ' . $this->renderTablePanel(
                'Expense Lines',
                $this->configuredLinesTable((array)($claim['lines'] ?? []), $nominals, $claimId, $isPosted, $companyId, $dateFormat, $context)->render($context, $this->tableExportFields(['claim_id' => $claimId]))
            ) . '
            ' . $this->renderPaymentsPanel((array)($claim['payment_links'] ?? []), $paymentCandidates, $companySettings, $filters, $claimId, $isPosted, $companyId, $dateFormat, $context) . '
        ';
    }

    private function renderTablePanel(string $title, string $tableHtml, string $helper = ''): string
    {
        return '<div class="panel-soft">
            <div class="status-head"><h4 class="card-title">' . HelperFramework::escape($title) . '</h4></div>
            ' . ($helper === '' ? '' : '<div class="helper">' . HelperFramework::escape($helper) . '</div>') . '
            ' . $tableHtml . '
        </div>';
    }

    private function renderBulkPastePanel(int $claimId, int $companyId, string $dateFormat, array $preview, array $context): string
    {
        $sourceText = (string)($preview['source_text'] ?? '');
        $rows = (array)($preview['rows'] ?? []);
        $previewPanel = '';

        if ($rows !== []) {
            $previewPanel = '<div class="panel-soft">
                <div class="status-head"><h4 class="card-title">Import Lines</h4></div>
                <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Preview rows</div><div class="summary-value">' . count($rows) . '</div></div>
                <div class="summary-card"><div class="summary-label">Preview total</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($preview['total'] ?? 0)) . '</div></div>
            </div>
            ' . $this->configuredPreviewTable($rows, $dateFormat, $sourceText, $claimId, $context)->render($context, $this->tableExportFields([
                'claim_id' => $claimId,
                'date_format' => $dateFormat,
                'pasted_lines' => $sourceText,
            ])) . '
            <form method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="bulk_save_lines">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="date_format" value="' . HelperFramework::escape($dateFormat) . '">
                <textarea name="pasted_lines" hidden>' . HelperFramework::escape($sourceText) . '</textarea>
                <div class="actions-row"><button class="button primary" type="submit">Import previewed lines</button></div>
            </form>
        </div>';
        }

        return '<div class="panel-soft">
            <form method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="preview_bulk_lines">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="date_format" value="' . HelperFramework::escape($dateFormat) . '">
                <div class="form-row">
                    <h4 class="card-title"><label for="expense-bulk-paste-' . $claimId . '">Claims can be pasted below</label></h4>
                    <div class="helper">The expected tab-delimited format (which can be copied from a spreadsheet) is: &quot;DATE&quot;, &quot;DESCRIPTION&quot;, &quot;AMOUNT CLAIMED&quot;</div>
                    <div class="expense-bulk-paste-controls">
                        <textarea class="input" id="expense-bulk-paste-' . $claimId . '" name="pasted_lines" rows="2">' . HelperFramework::escape($sourceText) . '</textarea>
                        <button class="button primary" type="submit">Import Lines</button>
                    </div>
                </div>
            </form>
        </div>
        ' . $previewPanel;
    }

    private function configuredPreviewTable(array $rows, string $dateFormat, string $sourceText, int $claimId, array $context): TableFramework
    {
        $table = $this->previewTable($rows, $dateFormat);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context, self::TABLE_PREVIEW), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Import Lines',
                $this->paginationPageField(self::TABLE_PREVIEW),
                $this->tablePaginationFields([
                    'claim_id' => $claimId,
                    'date_format' => $dateFormat,
                    'pasted_lines' => $sourceText,
                ])
            );
    }

    private function previewTable(array $rows, string $dateFormat): TableFramework
    {
        return TableFramework::make(self::TABLE_PREVIEW, $this->previewRows($rows, $dateFormat))
            ->filename('expense-claim-preview-lines')
            ->exportLimit(1000)
            ->empty('No preview lines are available.')
            ->column('expense_date_display', 'Date')
            ->column('description', 'Description')
            ->column(
                'amount',
                'Amount',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function configuredLinesTable(array $lines, array $nominals, int $claimId, bool $isPosted, int $companyId, string $dateFormat, array $context): TableFramework
    {
        $table = $this->linesTable($lines, $nominals, $claimId, $isPosted, $companyId, $dateFormat);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context, self::TABLE_LINES), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Expense Lines',
                $this->paginationPageField(self::TABLE_LINES),
                $this->tablePaginationFields(['claim_id' => $claimId])
            );
    }

    private function linesTable(array $lines, array $nominals, int $claimId, bool $isPosted, int $companyId, string $dateFormat): TableFramework
    {
        return TableFramework::make(self::TABLE_LINES, $this->lineRows($lines, $dateFormat))
            ->filename('expense-claim-lines')
            ->exportLimit(1000)
            ->empty('No expense lines have been added yet.')
            ->column('expense_date_display', 'Date')
            ->column('description', 'Description')
            ->column(
                'nominal_label',
                'Nominal',
                html: fn(array $row): string => $isPosted
                    ? HelperFramework::escape((string)($row['nominal_label'] ?? ''))
                    : $this->lineNominalForm($nominals, $claimId, (int)($row['id'] ?? 0), (int)($row['nominal_account_id'] ?? 0), $companyId),
                export: static fn(array $row): string => (string)($row['nominal_label'] ?? '')
            )
            ->column(
                'amount',
                'Amount',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $isPosted ? '' : $this->deleteLineForm($claimId, (int)($row['id'] ?? 0), $companyId),
                exportable: false,
                cellClass: 'cell-fit'
            );
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

    private function deleteLineForm(int $claimId, int $lineId, int $companyId): string
    {
        return '<form method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="delete_line">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <input type="hidden" name="line_id" value="' . $lineId . '">
            <button class="button button-inline danger" type="submit">Remove</button>
        </form>';
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
            <div class="panel-soft">
                <div class="status-head"><h4 class="card-title">Add New Expense Line</h4></div>
                <div class="form-grid expense-line-form-grid">
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
                    <div class="form-row expense-line-form-actions">
                        <button class="button primary" type="submit" form="' . $formId . '">Add Line</button>
                    </div>
                </div>
            </div>
            ';
    }

    private function renderPaymentsPanel(array $payments, array $paymentCandidates, array $companySettings, array $filters, int $claimId, bool $isPosted, int $companyId, string $dateFormat, array $context): string
    {
        $paymentQuery = (string)($filters['payment_query'] ?? '');
        $paymentsPanel = $this->renderTablePanel(
            'Repayments',
            $this->configuredPaymentsTable($payments, $claimId, $isPosted, $companyId, $dateFormat, $context)->render($context, $this->tableExportFields(['claim_id' => $claimId])),
            'Link repayments from bank transactions in the month they were paid. The selected claim determines the claimant.'
        );

        if ($isPosted) {
            return $paymentsPanel;
        }

        return $paymentsPanel . '
            <div class="panel-soft">
                <div class="status-head"><h4 class="card-title">Candidate Repayments</h4></div>
                ' . $this->withoutEmptyActionRows($this->configuredPaymentCandidatesTable($paymentCandidates, $companySettings, $paymentQuery, $claimId, $companyId, $dateFormat, $context)->render($context, $this->tableExportFields(['claim_id' => $claimId, 'payment_query' => $paymentQuery]))) . '
            </div>';
    }

    private function withoutEmptyActionRows(string $html): string
    {
        return preg_replace('/<div class="actions-row">\s*<\/div>\s*/', '', $html) ?? $html;
    }

    private function configuredPaymentsTable(array $payments, int $claimId, bool $isPosted, int $companyId, string $dateFormat, array $context): TableFramework
    {
        $table = $this->paymentsTable($payments, $claimId, $isPosted, $companyId, $dateFormat);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context, self::TABLE_PAYMENTS), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Repayments',
                $this->paginationPageField(self::TABLE_PAYMENTS),
                $this->tablePaginationFields(['claim_id' => $claimId])
            );
    }

    private function paymentCandidateToolbarHtml(string $paymentQuery, int $claimId, int $companyId): string
    {
        return $this->renderPaymentCandidateSearch($paymentQuery, $claimId, $companyId);
    }

    private function paymentsTable(array $payments, int $claimId, bool $isPosted, int $companyId, string $dateFormat): TableFramework
    {
        return TableFramework::make(self::TABLE_PAYMENTS, $this->paymentRows($payments, $dateFormat))
            ->filename('expense-claim-repayments')
            ->exportLimit(1000)
            ->empty('No repayments are linked to this claim.')
            ->column('txn_date_display', 'Date')
            ->column('description', 'Repayment')
            ->column('reference', 'Reference')
            ->column(
                'linked_amount',
                'Linked',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['linked_amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['linked_amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $isPosted ? '' : $this->unlinkPaymentForm($claimId, (int)($row['id'] ?? 0), $companyId),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function unlinkPaymentForm(int $claimId, int $paymentLinkId, int $companyId): string
    {
        return '<form method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="unlink_payment">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <input type="hidden" name="payment_link_id" value="' . $paymentLinkId . '">
            <button class="button button-inline danger" type="submit">Unlink</button>
        </form>';
    }

    private function renderPaymentCandidateSearch(string $paymentQuery, int $claimId, int $companyId): string
    {
        return '<form class="toolbar expenses-toolbar" method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="filter_claims">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <label for="expense-payment-query">Search repayments</label>
            <input class="input" id="expense-payment-query" name="payment_query" type="search" value="' . HelperFramework::escape($paymentQuery) . '" placeholder="Bank description or reference">
            <button class="button" type="submit">Search</button>
        </form>';
    }

    private function configuredPaymentCandidatesTable(array $paymentCandidates, array $companySettings, string $paymentQuery, int $claimId, int $companyId, string $dateFormat, array $context): TableFramework
    {
        $table = $this->paymentCandidatesTable($paymentCandidates, $companySettings, $claimId, $companyId, $dateFormat);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context, self::TABLE_PAYMENT_CANDIDATES), self::PAGE_SIZE);

        return $table
            ->toolbarActions($this->paymentCandidateToolbarHtml($paymentQuery, $claimId, $companyId))
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Candidate Repayments',
                $this->paginationPageField(self::TABLE_PAYMENT_CANDIDATES),
                $this->tablePaginationFields(['claim_id' => $claimId])
            );
    }

    private function paymentCandidatesTable(array $paymentCandidates, array $companySettings, int $claimId, int $companyId, string $dateFormat): TableFramework
    {
        return TableFramework::make(self::TABLE_PAYMENT_CANDIDATES, $this->paymentCandidateRows($paymentCandidates, $dateFormat))
            ->filename('expense-claim-candidate-repayments')
            ->exportLimit(1000)
            ->empty('No candidate repayments were found for this claim month.')
            ->column('txn_date_display', 'Date')
            ->primarySecondaryColumn('description', 'Transaction', 'reference')
            ->column(
                'amount',
                'Amount',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'available_amount',
                'Available',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['available_amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['available_amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'link',
                'Link',
                html: fn(array $row): string => $this->linkPaymentForm($row, $companySettings, $claimId, $companyId),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function linkPaymentForm(array $candidate, array $companySettings, int $claimId, int $companyId): string
    {
        $availableAmount = round((float)($candidate['available_amount'] ?? 0), 2);
        $allocatedElsewhere = round((float)($candidate['allocated_elsewhere'] ?? 0), 2);
        $currentLinkAmount = round((float)($candidate['current_link_amount'] ?? 0), 2);
        $canLink = $allocatedElsewhere <= 0 && ($currentLinkAmount > 0 || $availableAmount > 0);

        return '<form method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="link_payment">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <input type="hidden" name="transaction_id" value="' . (int)($candidate['id'] ?? 0) . '">
            <input type="hidden" name="default_expense_nominal_id" value="' . (int)($companySettings['default_expense_nominal_id'] ?? 0) . '">
            <input type="hidden" name="default_bank_nominal_id" value="' . (int)($companySettings['default_bank_nominal_id'] ?? 0) . '">
            <div class="actions-row expense-payment-link-actions">
                <button class="button button-inline primary" type="submit"' . ($canLink ? '' : ' disabled') . '>' . ($currentLinkAmount > 0 ? 'Update' : 'Link') . '</button>
            </div>
        </form>';
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

    private function bulkPreviewFromContext(array $context, int $claimId, string $dateFormat): array
    {
        if (is_array($context['expense_bulk_preview'] ?? null) && (int)($context['expense_bulk_preview']['claim_id'] ?? 0) === $claimId) {
            return (array)$context['expense_bulk_preview'];
        }

        $input = (array)($context['expense_bulk_preview_input'] ?? []);
        $sourceText = trim((string)($input['pasted_lines'] ?? ''));
        if ($sourceText === '') {
            return [];
        }

        $preview = (new \eel_accounts\Service\ExpenseClaimService())->previewBulkLines(
            (int)(($context['company'] ?? [])['id'] ?? 0),
            $claimId,
            $sourceText,
            (string)($input['date_format'] ?? $dateFormat)
        );
        $preview['claim_id'] = $claimId;

        return $preview;
    }

    private function previewRows(array $rows, string $dateFormat): array
    {
        return array_map(function (array $row) use ($dateFormat): array {
            $row['expense_date_display'] = (string)($row['expense_date_display'] ?? $this->displayDate((string)($row['expense_date'] ?? ''), $dateFormat));
            return $row;
        }, array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row))));
    }

    private function lineRows(array $lines, string $dateFormat): array
    {
        return array_map(function (array $line) use ($dateFormat): array {
            $line['expense_date_display'] = $this->displayDate((string)($line['expense_date'] ?? ''), $dateFormat);
            return $line;
        }, array_values(array_filter($lines, static fn(mixed $line): bool => is_array($line))));
    }

    private function paymentRows(array $payments, string $dateFormat): array
    {
        return array_map(function (array $payment) use ($dateFormat): array {
            $payment['txn_date_display'] = $this->displayDate((string)($payment['txn_date'] ?? ''), $dateFormat);
            return $payment;
        }, array_values(array_filter($payments, static fn(mixed $payment): bool => is_array($payment))));
    }

    private function paymentCandidateRows(array $paymentCandidates, string $dateFormat): array
    {
        return array_map(function (array $candidate) use ($dateFormat): array {
            $candidate['txn_date_display'] = $this->displayDate((string)($candidate['txn_date'] ?? ''), $dateFormat);
            return $candidate;
        }, array_values(array_filter($paymentCandidates, static fn(mixed $candidate): bool => is_array($candidate))));
    }

    private function tablePaginationFields(array $extra = []): array
    {
        return array_merge([
            'page' => 'expenses',
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => $this->key(),
        ], $extra);
    }

    private function tableExportFields(array $extra = []): array
    {
        return array_merge([
            'cards[]' => [$this->key()],
        ], $extra);
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'expense.claim.editor');
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
