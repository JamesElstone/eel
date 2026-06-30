<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_importedCard extends CardBaseFramework
{
    private const PAGE_SIZE = 20;

    public function key(): string
    {
        return 'transactions_imported';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'month_status',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'buildMonthStatus',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'transactions_by_month',
                'service' => \eel_accounts\Repository\DashboardRepository::class,
                'method' => 'fetchTransactionsForMonth',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'monthKey' => ':page.month_key',
                    'categoryFilter' => ':page.category_filter',
                ],
            ],
            [
                'key' => 'nominal_accounts',
                'service' => \eel_accounts\Repository\NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
            ],
            [
                'key' => 'company_accounts',
                'service' => \eel_accounts\Service\CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => true,
                ],
            ],
            [
                'key' => 'year_end_review',
                'service' => \eel_accounts\Service\YearEndLockService::class,
                'method' => 'fetchReview',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = TransactionAction::withTransactionCardContext($request, $services, $pageContext, $actionResult);
        $pageContext['page'][$this->paginationPageField()] = max(1, (int)$request->input($this->paginationPageField(), 1));

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);

        if ($companyId <= 0) {
            return '<div class="helper">A company has to be added and selected before transaction categorisation can occur.</div>';
        }

        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $transactionsByMonth = (array)($services['transactions_by_month'] ?? []);
        $monthStatus = (array)($services['month_status'] ?? []);
        $nominalAccounts = (array)($services['nominal_accounts'] ?? []);
        $activeTransferCompanyAccounts = $this->activeTransferCompanyAccounts($services);
        $isPeriodLocked = $this->isPeriodLocked($services);
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'not_posted');
        $selectedMonthSummary = $this->buildSelectedMonthSummary($transactionsByMonth);

        $monthOptions = '';
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }
            $monthOptions .= '<option value="' . HelperFramework::escape((string)($month['month_key'] ?? '')) . '"' . ((string)($month['month_key'] ?? '') === $selectedTransactionMonth ? ' selected' : '') . '>' . HelperFramework::escape((string)($month['label'] ?? '')) . '</option>';
        }

        $tableHtml = $this->configuredTransactionsTable(
            $transactionsByMonth,
            $companyId,
            $accountingPeriodId,
            $selectedTransactionMonth,
            $selectedTransactionFilter,
            $nominalAccounts,
            $activeTransferCompanyAccounts,
            $isPeriodLocked,
            $context
        )->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );

        return '
            <div class="card-toolbar transactions-imported-controls">
                <div class="transactions-imported-primary-controls">
                    <form class="toolbar" method="post" action="?page=transactions" data-ajax="true">
                        <input type="hidden" name="card_action" value="Transaction">
                        <input type="hidden" name="global_action" value="select_transaction_month">
                        <input type="hidden" name="selection_source" value="transactions_imported_filters">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                        <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                        <div class="mini-field">
                            <label for="transaction_month_key">Month</label>
                            <select class="select" id="transaction_month_key" name="month_key">' . $monthOptions . '</select>
                        </div>
                    </form>
                    ' . $this->bulkToolbarActionsHtml($companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter, $isPeriodLocked) . '
                </div>
                ' . $this->lockedPeriodNoticeHtml($isPeriodLocked) . '
                <div class="pill-row transactions-imported-summary">
                    <span class="pill">' . (int)$selectedMonthSummary['total'] . ' in month</span>
                    <span class="pill">' . (int)$selectedMonthSummary['uncategorised'] . ' uncategorised</span>
                    <span class="pill">' . (int)$selectedMonthSummary['ready_to_post'] . ' ready to post</span>
                    <span class="pill">' . (int)$selectedMonthSummary['posted'] . ' posted</span>
                    <span class="pill">' . (int)$selectedMonthSummary['deferred'] . ' deferred</span>
                </div>
            </div>'
            . $tableHtml . '
        ';
    }

    public function tables(array $context): array
    {
        $company = (array)($context['company'] ?? []);
        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $isPeriodLocked = $this->isPeriodLocked($services);

        return [
            $this->transactionsTable(
                (array)($services['transactions_by_month'] ?? []),
                (int)($company['id'] ?? 0),
                (int)($company['accounting_period_id'] ?? 0),
                (string)($page['month_key'] ?? ''),
                (string)($page['category_filter'] ?? 'not_posted'),
                (array)($services['nominal_accounts'] ?? []),
                $this->activeTransferCompanyAccounts($services),
                $isPeriodLocked
            ),
        ];
    }

    private function configuredTransactionsTable(
        array $transactions,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        array $nominalAccounts,
        array $activeTransferCompanyAccounts,
        bool $isPeriodLocked,
        array $context
    ): TableFramework {
        $rows = array_values(array_filter($transactions, static fn(mixed $row): bool => is_array($row)));
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $this->transactionsTable(
            $transactions,
            $companyId,
            $accountingPeriodId,
            $selectedTransactionMonth,
            $selectedTransactionFilter,
            $nominalAccounts,
            $activeTransferCompanyAccounts,
            $isPeriodLocked
        )
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Imported transactions',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function bulkToolbarActionsHtml(
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        bool $isPeriodLocked
    ): string
    {
        $autoButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $postButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';

        return '<form method="post" action="?page=transactions" data-ajax="true">
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="auto_scope" value="uncategorised">
                <input type="hidden" name="global_action" value="run_auto_rules">
                <button class="button"' . $autoButtonAttributes . '>Run Auto Rules</button>
            </form>
            <form method="post" action="?page=transactions" data-ajax="true">
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="global_action" value="post_categorised_transactions">
                <button class="button primary"' . $postButtonAttributes . '>Post Categorised Transactions</button>
            </form>';
    }

    private function transactionsTable(
        array $transactions,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        array $nominalAccounts,
        array $activeTransferCompanyAccounts,
        bool $isPeriodLocked = false
    ): TableFramework {
        $rows = array_values(array_filter($transactions, static fn(mixed $row): bool => is_array($row)));

        return TableFramework::make($this->key(), $rows)
            ->filename('imported-transactions')
            ->exportLimit(1000)
            ->empty('No imported transactions match the selected month and filter yet.')
            ->filterSelect(
                'category_filter',
                'Category filter',
                [
                    'all' => 'All',
                    'not_posted' => 'Not yet Posted',
                    'uncategorised' => 'Uncategorised only',
                    'auto' => 'Auto categorised',
                    'manual' => 'Manually Categorised',
                ],
                $selectedTransactionFilter,
                [
                    'card_action' => 'Transaction',
                    'global_action' => 'select_transaction_month',
                    'selection_source' => 'transactions_imported_filters',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_key' => $selectedTransactionMonth,
                ]
            )
            ->column(
                'txn_date',
                'Date',
                html: fn(array $row): string => HelperFramework::escape($this->displayDate((string)($row['txn_date'] ?? '')))
            )
            ->column(
                'description',
                'Description',
                html: fn(array $row): string => $this->descriptionHtml($row)
            )
            ->column(
                'source_account',
                'Source',
                html: fn(array $row): string => $this->sourceHtml($row)
            )
            ->column(
                'amount',
                'Amount',
                html: static function (array $row): string {
                    $amount = (float)($row['amount'] ?? 0);

                    return '<span class="' . ($amount >= 0 ? 'amount-positive' : 'amount-negative') . '">'
                        . HelperFramework::escape(FormattingFramework::money($amount))
                        . '</span>';
                },
                cellClass: 'numeric'
            )
            ->column(
                'document',
                'Document',
                html: fn(array $row): string => $this->documentHtml($row, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter)
            )
            ->column(
                'categorisation',
                'Categorisation',
                html: fn(array $row): string => $this->categorisationHtml($row, $nominalAccounts, $activeTransferCompanyAccounts, $isPeriodLocked)
            )
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => '<span class="badge ' . HelperFramework::escape($this->transactionCategorisationStatusBadgeClass($row)) . '">'
                    . HelperFramework::escape($this->transactionCategorisationStatusLabel($row))
                    . '</span>'
            )
            ->column(
                'flags',
                'Flags',
                html: fn(array $row): string => $this->flagsHtml($row)
            )
            ->column(
                'journal',
                'Journal',
                html: fn(array $row): string => '<span class="badge ' . HelperFramework::escape($this->transactionJournalStatusBadgeClass($row)) . '">'
                    . HelperFramework::escape($this->transactionJournalStatusLabel($row))
                    . '</span>'
            )
            ->column(
                'actions',
                'Actions',
                html: fn(array $row): string => $this->actionsHtml($row, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter, $isPeriodLocked),
                exportable: false
            );
    }

    private function activeTransferCompanyAccounts(array $services): array
    {
        return array_values(array_filter(
            (array)($services['company_accounts'] ?? []),
            static fn(mixed $account): bool => is_array($account)
                && in_array((string)($account['account_type'] ?? ''), [\eel_accounts\Service\CompanyAccountService::TYPE_BANK, \eel_accounts\Service\CompanyAccountService::TYPE_TRADE], true)
                && (int)($account['is_active'] ?? 0) === 1
        ));
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

    private function descriptionHtml(array $transaction): string
    {
        $helperLines = [];
        $reference = trim((string)($transaction['reference'] ?? ''));
        if ($reference !== '') {
            $helperLines[] = 'Ref: ' . HelperFramework::escape($reference);
        }

        if ((int)($transaction['auto_rule_id'] ?? 0) > 0) {
            $matchValue = trim((string)($transaction['auto_rule_match_value'] ?? ''));
            $helperLines[] = 'Matched by rule #' . (int)($transaction['auto_rule_id'] ?? 0)
                . ($matchValue !== '' ? ' (' . HelperFramework::escape($matchValue) . ')' : '');
        }

        $helperHtml = $helperLines !== [] ? '<div class="helper">' . implode('<br>', $helperLines) . '</div>' : '';

        return '<div>' . HelperFramework::escape((string)($transaction['description'] ?? '')) . '</div>' . $helperHtml;
    }

    private function sourceHtml(array $transaction): string
    {
        $sourceCategory = (string)($transaction['source_category'] ?? '');

        return '<div>' . HelperFramework::escape((string)($transaction['source_account'] ?? '')) . '</div>
            <div class="helper">' . HelperFramework::escape($sourceCategory !== '' ? $sourceCategory : 'No source category') . '</div>';
    }

    private function documentHtml(array $transaction, int $companyId, int $accountingPeriodId, string $selectedTransactionMonth, string $selectedTransactionFilter): string
    {
        $transactionId = (int)($transaction['id'] ?? 0);
        $documentHtml = '<div class="document-stack">
            <span class="badge ' . HelperFramework::escape($this->documentStatusBadgeClass((string)($transaction['document_download_status'] ?? ''))) . '">' . HelperFramework::escape($this->documentStatusLabel((string)($transaction['document_download_status'] ?? ''))) . '</span>';

        $localDocumentPath = trim((string)($transaction['local_document_path'] ?? ''));
        $sourceDocumentUrl = trim((string)($transaction['source_document_url'] ?? ''));
        if ($localDocumentPath !== '') {
            $documentHtml .= '<a class="text-link" href="' . HelperFramework::escape($this->assetHref($localDocumentPath)) . '" target="_blank" rel="noopener noreferrer">View Receipt</a>';
        } elseif ($sourceDocumentUrl !== '') {
            $documentHtml .= '<a class="text-link" href="' . HelperFramework::escape($sourceDocumentUrl) . '" target="_blank" rel="noopener noreferrer">Source URL</a>
                <form method="post" action="?page=transactions" data-ajax="true">
                    <input type="hidden" name="card_action" value="Transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                    <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                    <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                    <input type="hidden" name="global_action" value="retry_receipt_download">
                    <button class="button button-inline" type="submit">Retry receipt</button>
                </form>';
        }

        $documentError = trim((string)($transaction['document_error'] ?? ''));
        if ($documentError !== '') {
            $documentHtml .= '<span class="helper">' . HelperFramework::escape($documentError) . '</span>';
        }

        return $documentHtml . '</div>';
    }

    private function categorisationHtml(
        array $transaction,
        array $nominalAccounts,
        array $activeTransferCompanyAccounts,
        bool $isPeriodLocked
    ): string
    {
        $transactionFormId = 'transaction-form-' . (int)($transaction['id'] ?? 0);
        $isTransferRow = $this->transactionIsTransferMode($transaction);

        if ($isPeriodLocked) {
            return $this->readonlyCategorisationHtml($transaction, $nominalAccounts, $activeTransferCompanyAccounts);
        }

        if ($isTransferRow) {
            $selectedTransferAccountId = (string)($transaction['transfer_account_id'] ?? '');
            $transferDirectionLabel = (float)($transaction['amount'] ?? 0) < 0 ? 'Transfer to:' : 'Transfer from:';
            $transferOptions = '<option value="">Select owned account</option>';
            foreach ($activeTransferCompanyAccounts as $account) {
                if (!is_array($account) || (int)($account['id'] ?? 0) === (int)($transaction['account_id'] ?? 0)) {
                    continue;
                }
                $accountType = (string)($account['account_type'] ?? '');
                $accountTypeLabel = \eel_accounts\Service\CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType);
                $transferOptions .= '<option value="' . (int)($account['id'] ?? 0) . '"' . ((string)($account['id'] ?? '') === $selectedTransferAccountId ? ' selected' : '') . '>' . HelperFramework::escape((string)($account['account_name'] ?? '') . ' [' . $accountTypeLabel . ']') . '</option>';
            }

            return '<div class="helper">' . HelperFramework::escape($transferDirectionLabel) . '</div>
                <select class="select js-transaction-transfer" name="transfer_account_id" form="' . HelperFramework::escape($transactionFormId) . '" data-initial-value="' . HelperFramework::escape($selectedTransferAccountId) . '" data-autosave-submit-target=".js-transaction-autosave-submit" data-autosave-require-value="1">' . $transferOptions . '</select>';
        }

        $selectedNominalAccountId = (string)($transaction['nominal_account_id'] ?? '');
        $nominalOptions = '<option value="">Unassigned</option>';
        foreach ($nominalAccounts as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }
            $nominalOptions .= '<option value="' . (int)($nominal['id'] ?? 0) . '"' . ((string)($nominal['id'] ?? '') === $selectedNominalAccountId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return '<select class="select js-transaction-nominal" name="nominal_account_id" form="' . HelperFramework::escape($transactionFormId) . '" data-initial-value="' . HelperFramework::escape($selectedNominalAccountId) . '" data-autosave-submit-target=".js-transaction-autosave-submit" data-autosave-require-value="1">' . $nominalOptions . '</select>';
    }

    private function flagsHtml(array $transaction): string
    {
        $flagsHtml = '<div class="document-stack">';
        if ((int)($transaction['is_auto_excluded'] ?? 0) === 1) {
            $flagsHtml .= '<span class="badge warning">Deferred</span>';
        }
        if (!$this->transactionIsTransferMode($transaction) && (int)($transaction['auto_rule_id'] ?? 0) > 0) {
            $flagsHtml .= '<span class="badge info">Rule #' . (int)($transaction['auto_rule_id'] ?? 0) . '</span>';
        }

        return $flagsHtml . '</div>';
    }

    private function actionsHtml(
        array $transaction,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        bool $isPeriodLocked
    ): string
    {
        $transactionId = (int)($transaction['id'] ?? 0);
        $transactionFormId = 'transaction-form-' . $transactionId;
        $assetFormId = 'transaction-asset-form-' . $transactionId;
        $isTransferRow = $this->transactionIsTransferMode($transaction);
        $journalRebuildAttributes = !$isPeriodLocked && (int)($transaction['has_derived_journal'] ?? 0) === 1
            ? ' data-chicken-check="true" data-chicken-title="Confirm journal rebuild" data-chicken-message="This will rebuild the journal entry for this transaction.<br><br>Continue?" data-chicken-confirm-text="Continue" data-chicken-button-class="button primary" data-submit-field="confirm_rebuild_journal" data-submit-value="1"'
            : '';
        $lockedButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $autosaveSubmitHtml = $isPeriodLocked
            ? ''
            : '<button class="js-transaction-autosave-submit" type="submit" name="global_action" value="save_transaction_category" hidden' . $journalRebuildAttributes . '>Autosave</button>';
        $createAssetAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit" form="' . HelperFramework::escape($assetFormId) . '" formnovalidate';
        $createRuleHtml = $isTransferRow ? '' : $this->createRuleButtonHtml($transaction, $isPeriodLocked);

        return '<form method="post" action="?page=assets" id="' . HelperFramework::escape($assetFormId) . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
            </form>
            <form method="post" action="?page=transactions" id="' . HelperFramework::escape($transactionFormId) . '" data-ajax="true">
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="confirm_rebuild_journal" value="0">
                ' . $autosaveSubmitHtml . '
                <div class="actions-row">
                    ' . $createRuleHtml . '
                    <button class="button primary"' . $lockedButtonAttributes . ' name="global_action" value="defer_transaction"' . $journalRebuildAttributes . '>Defer</button>
                    <button class="button"' . $createAssetAttributes . '>Create Asset</button>
                </div>
            </form>';
    }

    private function createRuleButtonHtml(array $transaction, bool $includeNominalInput): string
    {
        $nominalAccountId = (int)($transaction['nominal_account_id'] ?? 0);
        $nominalInputHtml = $includeNominalInput && $nominalAccountId > 0
            ? '<input type="hidden" name="nominal_account_id" value="' . $nominalAccountId . '">'
            : '';

        return $nominalInputHtml
            . '<input type="hidden" name="transaction_reference" value="' . HelperFramework::escape((string)($transaction['reference'] ?? '')) . '">'
            . '<button class="button primary" type="submit" name="global_action" value="auto_create_transaction_rule" data-show-card="transactions_rule_form">Create Rule</button>';
    }

    private function readonlyCategorisationHtml(array $transaction, array $nominalAccounts, array $activeTransferCompanyAccounts): string
    {
        if ($this->transactionIsTransferMode($transaction)) {
            $transferDirectionLabel = (float)($transaction['amount'] ?? 0) < 0 ? 'Transfer to:' : 'Transfer from:';

            return '<div class="helper">' . HelperFramework::escape($transferDirectionLabel) . '</div>
                <div>' . HelperFramework::escape($this->transferAccountLabel($transaction, $activeTransferCompanyAccounts)) . '</div>';
        }

        return '<div>' . HelperFramework::escape($this->nominalAccountLabel($transaction, $nominalAccounts)) . '</div>';
    }

    private function nominalAccountLabel(array $transaction, array $nominalAccounts): string
    {
        $nominalAccountId = (int)($transaction['nominal_account_id'] ?? 0);
        foreach ($nominalAccounts as $nominal) {
            if (is_array($nominal) && (int)($nominal['id'] ?? 0) === $nominalAccountId) {
                return FormattingFramework::nominalLabel($nominal);
            }
        }

        $assignedNominal = trim((string)($transaction['assigned_nominal'] ?? ''));
        return $assignedNominal !== '' ? $assignedNominal : 'Unassigned';
    }

    private function transferAccountLabel(array $transaction, array $activeTransferCompanyAccounts): string
    {
        $transferAccountId = (int)($transaction['transfer_account_id'] ?? 0);
        foreach ($activeTransferCompanyAccounts as $account) {
            if (!is_array($account) || (int)($account['id'] ?? 0) !== $transferAccountId) {
                continue;
            }

            $accountType = (string)($account['account_type'] ?? '');
            $accountTypeLabel = \eel_accounts\Service\CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType);

            return (string)($account['account_name'] ?? '') . ' [' . $accountTypeLabel . ']';
        }

        $transferAccountName = trim((string)($transaction['transfer_account_name'] ?? ''));
        return $transferAccountName !== '' ? $transferAccountName : 'Unassigned';
    }

    private function isPeriodLocked(array $services): bool
    {
        $review = (array)($services['year_end_review'] ?? []);

        return !empty($review['is_locked']);
    }

    private function lockedPeriodNoticeHtml(bool $isPeriodLocked): string
    {
        if (!$isPeriodLocked) {
            return '';
        }

        return '<div class="helper"><span class="badge warning">Period locked</span> Transactions can be reviewed but not changed.</div>';
    }

    private function buildSelectedMonthSummary(array $transactionsByMonth): array
    {
        $summary = [
            'total' => count($transactionsByMonth),
            'uncategorised' => 0,
            'auto' => 0,
            'manual' => 0,
            'deferred' => 0,
            'ready_to_post' => 0,
            'posted' => 0,
        ];

        foreach ($transactionsByMonth as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = (string)($row['category_status'] ?? 'uncategorised');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            if ((int)($row['is_auto_excluded'] ?? 0) === 1) {
                $summary['deferred']++;
            }

            if ((int)($row['has_derived_journal'] ?? 0) === 1) {
                $summary['posted']++;
            } elseif ($this->transactionIsTransferMode($row) && $this->transactionHasTransferAssignment($row)) {
                $summary['ready_to_post']++;
            } elseif (in_array($status, ['auto', 'manual'], true) && (int)($row['nominal_account_id'] ?? 0) > 0) {
                $summary['ready_to_post']++;
            }
        }

        return $summary;
    }

    private function transactionIsTransferMode(array $transaction): bool
    {
        if ((int)($transaction['is_internal_transfer'] ?? 0) === 1) {
            return true;
        }

        if ((int)($transaction['transfer_account_id'] ?? 0) > 0) {
            return true;
        }

        $mode = strtolower(trim((string)($transaction['category_mode'] ?? $transaction['categorisation_mode'] ?? '')));
        if ($mode === 'transfer') {
            return true;
        }

        $subtype = strtolower(trim((string)($transaction['source_category'] ?? '')));
        return str_contains($subtype, 'transfer');
    }

    private function transactionHasTransferAssignment(array $transaction): bool
    {
        return (int)($transaction['transfer_account_id'] ?? 0) > 0;
    }

    private function documentStatusBadgeClass(string $status): string
    {
        return match (strtolower(trim($status))) {
            'downloaded', 'stored', 'ok' => 'success',
            'pending', 'queued', 'processing' => 'info',
            'failed', 'error', 'missing' => 'warning',
            default => 'muted',
        };
    }

    private function documentStatusLabel(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'downloaded' => 'Downloaded',
            'stored' => 'Stored',
            'pending' => 'Pending',
            'queued' => 'Queued',
            'processing' => 'Processing',
            'failed' => 'Failed',
            'error' => 'Error',
            'missing', '' => 'Missing',
            default => ucfirst($status),
        };
    }

    private function transactionCategorisationStatusBadgeClass(array $transaction): string
    {
        $status = strtolower(trim((string)($transaction['category_status'] ?? 'uncategorised')));
        return match ($status) {
            'auto' => 'info',
            'manual' => 'success',
            default => 'warning',
        };
    }

    private function transactionCategorisationStatusLabel(array $transaction): string
    {
        $status = strtolower(trim((string)($transaction['category_status'] ?? 'uncategorised')));
        if ($this->transactionIsTransferMode($transaction)) {
            return $this->transactionHasTransferAssignment($transaction) ? 'Transfer assigned' : 'Transfer pending';
        }

        return match ($status) {
            'auto' => 'Auto categorised',
            'manual' => 'Manually Categorised',
            default => 'Uncategorised',
        };
    }

    private function transactionJournalStatusBadgeClass(array $transaction): string
    {
        if ((int)($transaction['has_derived_journal'] ?? 0) === 1) {
            return 'success';
        }

        if ($this->transactionIsTransferMode($transaction) && $this->transactionHasTransferAssignment($transaction)) {
            return 'info';
        }

        if (in_array((string)($transaction['category_status'] ?? ''), ['auto', 'manual'], true) && (int)($transaction['nominal_account_id'] ?? 0) > 0) {
            return 'info';
        }

        return 'muted';
    }

    private function transactionJournalStatusLabel(array $transaction): string
    {
        if ((int)($transaction['has_derived_journal'] ?? 0) === 1) {
            return 'Posted';
        }

        if ($this->transactionIsTransferMode($transaction) && $this->transactionHasTransferAssignment($transaction)) {
            return 'Ready to post';
        }

        if (in_array((string)($transaction['category_status'] ?? ''), ['auto', 'manual'], true) && (int)($transaction['nominal_account_id'] ?? 0) > 0) {
            return 'Ready to post';
        }

        return 'Not ready';
    }

    private function assetHref(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return '/' . $path;
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value);
    }
}
