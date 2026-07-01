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

        foreach ([self::TABLE_LINES, self::TABLE_PAYMENTS, self::TABLE_PAYMENT_CANDIDATES] as $scope) {
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

        return [
            $this->linesTable((array)($claim['lines'] ?? []), (array)($data['nominal_accounts'] ?? []), (array)($data['asset_categories'] ?? []), $claimId, $isPosted, $companyId, $dateFormat, $companySettings),
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
        $assetCategories = (array)($data['asset_categories'] ?? \eel_accounts\Service\AssetService::assetCategoryOptions());
        $paymentCandidates = (array)($data['payment_candidates'] ?? []);
        $filters = (array)($data['filters'] ?? []);

        if ($claim === []) {
            return '<div class="helper">Create or open a claim to start capturing lines and repayments.</div>';
        }

        $claimId = (int)($claim['id'] ?? 0);
        $isPosted = !empty($claim['is_posted']);
        $dateFormat = (string)($companySettings['date_format'] ?? 'd/m/Y');
        $claimReference = (string)($claim['claim_reference_code'] ?? '');
        $claimantName = (string)($claim['claimant_name'] ?? '');
        $claimMonthLabel = $this->monthLabel((int)($claim['claim_month'] ?? 0), (int)($claim['claim_year'] ?? 0));
        $displayTotals = $this->displayControlTotals($claim);

        return '<div class="summary-grid expense-claim-summary-grid">
                <div class="summary-card"><div class="summary-label">Claim Reference</div><div class="summary-value">' . HelperFramework::escape($claimReference) . '</div></div>
                <div class="summary-card"><div class="summary-label">Claimant</div><div class="summary-value">' . HelperFramework::escape($claimantName) . '</div></div>
                <div class="summary-card"><div class="summary-label">Claim Month</div><div class="summary-value">' . HelperFramework::escape($claimMonthLabel) . '</div></div>
                <div class="summary-card"><div class="summary-label">Brought Forwards (A)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($displayTotals['A'])) . '</div></div>
                <div class="summary-card"><div class="summary-label">In this claim (B)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($displayTotals['B'])) . '</div></div>
                <div class="summary-card"><div class="summary-label">Paid in this period (C)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($displayTotals['C'])) . '</div></div>
                <div class="summary-card"><div class="summary-label">Carried Forward (D=A+B-C)</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($displayTotals['D'])) . '</div></div>
            </div>
            ' . ($isPosted ? '' : $this->renderBulkPastePanel($claimId, $companyId, $dateFormat)) . '
            ' . ($isPosted ? '<div class="helper">Posted claims are locked.</div>' : $this->renderLineForm($claim, $nominals, $claimId, $companySettings, $companyId)) . '
            ' . $this->renderExpenseLinesPanel(
                (array)($claim['lines'] ?? []),
                $nominals,
                $assetCategories,
                $claimId,
                $isPosted,
                $companyId,
                $dateFormat,
                $companySettings,
                $context,
                $isPosted ? '' : $this->submitClaimToolbarAction($claim, $companySettings, $companyId)
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

    private function displayControlTotals(array $claim): array
    {
        $controlTotals = (array)($claim['control_totals'] ?? []);
        $broughtForward = round((float)($controlTotals['A'] ?? 0), 2);
        $inThisClaim = $this->claimLinesTotal((array)($claim['lines'] ?? []));
        $paid = round((float)($controlTotals['C'] ?? 0), 2);

        return [
            'A' => $broughtForward,
            'B' => $inThisClaim,
            'C' => $paid,
            'D' => round($broughtForward + $inThisClaim - $paid, 2),
        ];
    }

    private function claimLinesTotal(array $lines): float
    {
        return round(array_reduce(
            $lines,
            static fn(float $total, mixed $line): float => $total + (is_array($line) ? round((float)($line['amount'] ?? 0), 2) : 0.0),
            0.0
        ), 2);
    }

    private function submitClaimToolbarAction(array $claim, array $companySettings, int $companyId): string
    {
        $claimId = (int)($claim['id'] ?? 0);
        $defaultExpenseNominalId = (int)($companySettings['default_expense_nominal_id'] ?? 0);
        $errors = $this->submitClaimErrors($claim, $companySettings);

        return '<form method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="post_claim">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="default_expense_nominal_id" value="' . $defaultExpenseNominalId . '">
                <button class="button primary" type="submit"' . ($errors === [] ? '' : ' disabled') . '>Submit Claim</button>
            </form>';
    }

    private function submitClaimErrors(array $claim, array $companySettings): array
    {
        $lines = (array)($claim['lines'] ?? []);
        $errors = [];

        if ((int)($companySettings['default_expense_nominal_id'] ?? 0) <= 0) {
            $errors[] = 'Choose an Expense claims payable nominal in company nominal defaults.';
        }
        if ($lines === []) {
            $errors[] = 'Add at least one expense line.';
        }
        foreach ($lines as $line) {
            $lineNumber = (int)($line['line_number'] ?? 0);
            if ((string)($line['line_type'] ?? 'expense') === 'asset') {
                if (trim((string)($line['asset_category'] ?? '')) === '') {
                    $errors[] = 'Line ' . $lineNumber . ' needs an asset category.';
                }
                if ((int)($line['asset_useful_life_years'] ?? 0) <= 0) {
                    $errors[] = 'Line ' . $lineNumber . ' needs a useful life.';
                }
                continue;
            }

            if ((int)($line['nominal_account_id'] ?? 0) <= 0) {
                $errors[] = 'Line ' . $lineNumber . ' needs a Charge To value.';
            }
        }

        return $errors;
    }

    private function renderBulkPastePanel(int $claimId, int $companyId, string $dateFormat): string
    {
        return '<div class="panel-soft">
            <form method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="bulk_save_lines">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="date_format" value="' . HelperFramework::escape($dateFormat) . '">
                <div class="form-row">
                    <div class="status-head"><h4 class="card-title"><label for="expense-bulk-paste-' . $claimId . '">Claim Lines can be pasted below</label></h4></div>
                    <div class="helper">The expected tab-delimited format (which can be copied from a spreadsheet) is: &quot;DATE&quot;, &quot;DESCRIPTION&quot;, &quot;AMOUNT CLAIMED&quot;</div>
                    <div class="expense-bulk-paste-controls">
                        <textarea class="input" id="expense-bulk-paste-' . $claimId . '" name="pasted_lines" rows="2"></textarea>
                        <button class="button primary" type="submit">Import Lines</button>
                    </div>
                </div>
            </form>
        </div>';
    }

    private function renderExpenseLinesPanel(array $lines, array $nominals, array $assetCategories, int $claimId, bool $isPosted, int $companyId, string $dateFormat, array $companySettings, array $context, string $primaryToolbarActionHtml): string
    {
        $table = $this->configuredLinesTable($lines, $nominals, $assetCategories, $claimId, $isPosted, $companyId, $dateFormat, $companySettings, $context);
        $exportFields = $this->tableExportFields(['claim_id' => $claimId]);

        return $this->renderTablePanel(
            'Expense Lines',
            $this->expenseLinesToolbarHtml($table, $context, $exportFields, $primaryToolbarActionHtml)
            . $table->renderTable()
            . $table->renderFooter()
        );
    }

    private function expenseLinesToolbarHtml(TableFramework $table, array $context, array $exportFields, string $primaryToolbarActionHtml): string
    {
        $builtInToolbar = $this->withoutEmptyActionRows($table->renderToolbar($context, $exportFields));
        if ($primaryToolbarActionHtml === '') {
            return $builtInToolbar;
        }

        $builtInRowsHtml = '';
        if (preg_match('/^<div class="card-toolbar">(.*)<\/div>$/s', $builtInToolbar, $matches) === 1) {
            $builtInRowsHtml = (string)$matches[1];
        }

        return '<div class="card-toolbar"><div class="actions-row">' . $primaryToolbarActionHtml . '</div>' . $builtInRowsHtml . '</div>';
    }

    private function configuredLinesTable(array $lines, array $nominals, array $assetCategories, int $claimId, bool $isPosted, int $companyId, string $dateFormat, array $companySettings, array $context): TableFramework
    {
        $table = $this->linesTable($lines, $nominals, $assetCategories, $claimId, $isPosted, $companyId, $dateFormat, $companySettings);
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

    private function linesTable(array $lines, array $nominals, array $assetCategories, int $claimId, bool $isPosted, int $companyId, string $dateFormat, array $companySettings): TableFramework
    {
        $defaultCurrencySymbol = $this->defaultCurrencySymbol($companySettings);

        return TableFramework::make(self::TABLE_LINES, $this->lineRows($lines, $dateFormat))
            ->filename('expense-claim-lines')
            ->exportLimit(1000)
            ->empty('No expense lines have been added yet.')
            ->column('expense_date_display', 'Date')
            ->column('description', 'Description')
            ->column(
                'line_type',
                'Type',
                html: fn(array $row): string => $isPosted
                    ? HelperFramework::escape(ucfirst((string)($row['line_type'] ?? 'expense')))
                    : $this->lineTypeForm($claimId, (int)($row['id'] ?? 0), (string)($row['line_type'] ?? 'expense'), $companyId),
                export: static fn(array $row): string => ucfirst((string)($row['line_type'] ?? 'expense')),
                cellClass: 'cell-fit'
            )
            ->column(
                'charge_to',
                'Charge To',
                html: fn(array $row): string => $this->lineChargeToHtml($row, $nominals, $assetCategories, $claimId, $isPosted, $companyId),
                export: static fn(array $row): string => (string)($row['line_type'] ?? 'expense') === 'asset'
                    ? (string)($row['asset_category_label'] ?? '')
                    : (string)($row['nominal_label'] ?? '')
            )
            ->column(
                'amount',
                'Amount',
                html: static fn(array $row): string => HelperFramework::escape($defaultCurrencySymbol . FormattingFramework::money($row['amount'] ?? 0)),
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

    private function lineTypeForm(int $claimId, int $lineId, string $selectedType, int $companyId): string
    {
        $formId = 'expense-line-type-form-' . $lineId;
        $selectedType = $selectedType === 'asset' ? 'asset' : 'expense';

        return '<form method="post" action="?page=expenses" id="' . $formId . '" data-ajax="true" class="segmented-control">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="update_line_type">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="line_id" value="' . $lineId . '">
                <button class="segmented-option' . ($selectedType === 'expense' ? ' is-active' : '') . '" type="submit" name="line_type" value="expense">Expense</button>
                <button class="segmented-option' . ($selectedType === 'asset' ? ' is-active' : '') . '" type="submit" name="line_type" value="asset">Asset</button>
            </form>';
    }

    private function lineChargeToHtml(array $row, array $nominals, array $assetCategories, int $claimId, bool $isPosted, int $companyId): string
    {
        if ((string)($row['line_type'] ?? 'expense') === 'asset') {
            return $isPosted
                ? $this->postedAssetChargeToHtml($row, $companyId)
                : $this->lineAssetDetailsForm($row, $assetCategories, $claimId, $companyId);
        }

        return $isPosted
            ? HelperFramework::escape((string)($row['nominal_label'] ?? ''))
            : $this->lineNominalForm($nominals, $claimId, (int)($row['id'] ?? 0), (int)($row['nominal_account_id'] ?? 0), $companyId);
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

    private function lineAssetDetailsForm(array $row, array $assetCategories, int $claimId, int $companyId): string
    {
        $lineId = (int)($row['id'] ?? 0);
        $formId = 'expense-line-asset-form-' . $lineId;
        $description = trim((string)($row['asset_description'] ?? '')) !== '' ? (string)$row['asset_description'] : (string)($row['description'] ?? '');
        $lifeYears = max(1, (int)($row['asset_useful_life_years'] ?? 3));
        $method = (string)($row['asset_depreciation_method'] ?? 'straight_line');
        $residual = number_format((float)($row['asset_residual_value'] ?? 0), 2, '.', '');

        return '<form method="post" action="?page=expenses" id="' . $formId . '" data-ajax="true" class="expense-line-asset-form">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="save_line_asset_details">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <input type="hidden" name="line_id" value="' . $lineId . '">
                <div class="form-flex-flow">
                    <div class="form-row">
                        <label for="expense-line-asset-category-' . $lineId . '">Asset category</label>
                        <select class="select" id="expense-line-asset-category-' . $lineId . '" name="asset_category">' . $this->assetCategoryOptions($assetCategories, (string)($row['asset_category'] ?? 'tools_equipment')) . '</select>
                    </div>
                    <div class="form-row">
                        <label for="expense-line-asset-description-' . $lineId . '">Asset description</label>
                        <input class="input" id="expense-line-asset-description-' . $lineId . '" name="asset_description" value="' . HelperFramework::escape($description) . '">
                    </div>
                    <div class="form-row">
                        <label for="expense-line-asset-life-' . $lineId . '">Useful life</label>
                        <input class="input" id="expense-line-asset-life-' . $lineId . '" name="asset_useful_life_years" type="number" min="1" value="' . $lifeYears . '">
                    </div>
                    <div class="form-row">
                        <label for="expense-line-asset-method-' . $lineId . '">Depreciation</label>
                        <select class="select" id="expense-line-asset-method-' . $lineId . '" name="asset_depreciation_method">' . $this->depreciationMethodOptions($method) . '</select>
                    </div>
                    <div class="form-row">
                        <label for="expense-line-asset-residual-' . $lineId . '">Residual</label>
                        <input class="input" id="expense-line-asset-residual-' . $lineId . '" name="asset_residual_value" inputmode="decimal" value="' . HelperFramework::escape($residual) . '">
                    </div>
                    <div class="form-row">
                        <button class="button button-inline primary" type="submit">Save Asset Details</button>
                    </div>
                </div>
            </form>';
    }

    private function postedAssetChargeToHtml(array $row, int $companyId): string
    {
        $label = (string)($row['asset_category_label'] ?? 'Asset');
        $assetCode = trim((string)($row['asset_code'] ?? ''));
        $assetId = (int)($row['generated_asset_id'] ?? 0);
        $assetLink = $assetCode !== '' && $assetId > 0
            ? ' <a class="text-link" href="?page=assets&amp;company_id=' . $companyId . '">Asset ' . HelperFramework::escape($assetCode) . '</a>'
            : '';

        return HelperFramework::escape($label) . $assetLink;
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
        $amountLabel = 'Amount (' . $this->defaultCurrencySymbol($companySettings) . ')';

        return '<form id="' . $formId . '" method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="save_line">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
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
                        <label for="expense-line-amount">' . HelperFramework::escape($amountLabel) . '</label>
                        <input class="input" id="expense-line-amount" name="amount" form="' . $formId . '" inputmode="decimal">
                    </div>
                    <div class="form-row">
                        <label for="expense-line-nominal">Charge To</label>
                        <select class="select" id="expense-line-nominal" name="nominal_account_id" form="' . $formId . '">' . $this->nominalOptions($nominals, 0) . '</select>
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

    private function assetCategoryOptions(array $assetCategories, string $selectedCategory): string
    {
        if ($assetCategories === []) {
            $assetCategories = \eel_accounts\Service\AssetService::assetCategoryOptions();
        }

        $html = '';
        foreach ($assetCategories as $value => $label) {
            $value = (string)$value;
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedCategory ? ' selected' : '') . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function depreciationMethodOptions(string $selectedMethod): string
    {
        $options = [
            'straight_line' => 'Straight line',
            'reducing_balance' => 'Reducing balance',
            'none' => 'None',
        ];
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedMethod ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
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

    private function defaultCurrencySymbol(array $companySettings): string
    {
        $symbol = html_entity_decode((string)($companySettings['default_currency_symbol'] ?? '&#163;'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return $symbol !== '' ? $symbol : html_entity_decode('&#163;', \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
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
