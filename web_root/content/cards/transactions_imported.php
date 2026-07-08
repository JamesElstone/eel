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
            [
                'key' => 'pending_auto_approval_count',
                'service' => \eel_accounts\Service\TransactionAutoApprovalService::class,
                'method' => 'pendingPostConfirmationCount',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'monthKey' => ':page.month_key',
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
        $settings = (array)($company['settings'] ?? []);
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'not_posted');
        $interAcActiveTransactionId = max(0, (int)($page['inter_ac_transaction_id'] ?? 0));
        $selectedMonthSummary = $this->buildSelectedMonthSummary($transactionsByMonth);
        $pendingAutoApprovalCount = (int)($services['pending_auto_approval_count'] ?? $selectedMonthSummary['pending_auto_approval']);

        $monthOptions = '';
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }
            $monthOptions .= '<option value="' . HelperFramework::escape((string)($month['month_key'] ?? '')) . '"' . ((string)($month['month_key'] ?? '') === $selectedTransactionMonth ? ' selected' : '') . '>' . HelperFramework::escape((string)($month['label'] ?? '')) . '</option>';
        }
        $monthNavigation = $this->monthNavigation($monthStatus, $selectedTransactionMonth);

        $tableHtml = $this->configuredTransactionsTable(
            $transactionsByMonth,
            $companyId,
            $accountingPeriodId,
            $selectedTransactionMonth,
            $selectedTransactionFilter,
            $nominalAccounts,
            $activeTransferCompanyAccounts,
            $settings,
            $isPeriodLocked,
            $interAcActiveTransactionId,
            $context
        )->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );

        return '
            ' . $this->autoApprovalBatchFormHtml($companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter) . '
            <div class="card-toolbar transactions-imported-controls">
                <div class="transactions-imported-primary-controls">
                    ' . $this->monthNavigationButtonHtml('<', (string)($monthNavigation['previous'] ?? ''), $companyId, $accountingPeriodId, $selectedTransactionFilter, 'previous') . '
                    <form class="toolbar" method="post" action="?page=transactions" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
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
                    ' . $this->monthNavigationButtonHtml('>', (string)($monthNavigation['next'] ?? ''), $companyId, $accountingPeriodId, $selectedTransactionFilter, 'next') . '
                    ' . $this->bulkToolbarActionsHtml($companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter, $isPeriodLocked, $pendingAutoApprovalCount) . '
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
                (array)($company['settings'] ?? []),
                $isPeriodLocked,
                max(0, (int)($page['inter_ac_transaction_id'] ?? 0))
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
        array $settings,
        bool $isPeriodLocked,
        int $interAcActiveTransactionId,
        array $context
    ): TableFramework {
        $rows = array_values(array_filter($transactions, static fn(mixed $row): bool => is_array($row)));
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        $visibleRows = $this->transactionTableRows((array)$pagination['items']);

        return $this->transactionsTable(
            (array)$pagination['items'],
            $companyId,
            $accountingPeriodId,
            $selectedTransactionMonth,
            $selectedTransactionFilter,
            $nominalAccounts,
            $activeTransferCompanyAccounts,
            $settings,
            $isPeriodLocked,
            $interAcActiveTransactionId
        )
            ->visibleRows($visibleRows)
            ->pagination(
                $pagination,
                'Imported transactions',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_key' => $selectedTransactionMonth,
                    'category_filter' => $selectedTransactionFilter,
                ]
            );
    }

    private function bulkToolbarActionsHtml(
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        bool $isPeriodLocked,
        int $pendingAutoApprovalCount = 0
    ): string
    {
        $autoButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $postButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $postFormAttributes = ' data-transactions-imported-post-form="true" data-initial-pending-auto-approval-count="' . max(0, $pendingAutoApprovalCount) . '"';
        if (!$isPeriodLocked && $pendingAutoApprovalCount > 0) {
            $postButtonAttributes .= ' data-chicken-check="true"
                    data-chicken-title="Confirm checked auto decisions"
                    data-chicken-message="This will post categorised transactions and confirm ' . (int)$pendingAutoApprovalCount . ' checked auto decision(s). Unticked auto decisions will post but remain unconfirmed.<br><br>Continue?"
                    data-chicken-confirm-text="Post Transactions"
                    data-chicken-button-class="button primary"
                    data-submit-field="confirm_auto_categorisations"
                    data-submit-value="1"';
        }
        if (!$isPeriodLocked) {
            $postButtonAttributes .= ' data-post-categorised-transactions-button="true"
                    data-auto-approval-confirm-title="Confirm checked auto decisions"
                    data-auto-approval-confirm-message-template="This will post categorised transactions and confirm {count} checked auto decision(s). Unticked auto decisions will post but remain unconfirmed.<br><br>Continue?"
                    data-auto-approval-confirm-text="Post Transactions"
                    data-auto-approval-confirm-button-class="button primary"';
        }

        return '<form method="post" action="?page=transactions" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="auto_scope" value="uncategorised">
                <input type="hidden" name="global_action" value="run_auto_rules">
                <button class="button"' . $autoButtonAttributes . '>Run Auto Rules</button>
            </form>
            <form method="post" action="?page=transactions" data-ajax="true"' . $postFormAttributes . '>
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="global_action" value="post_categorised_transactions">
                <input type="hidden" name="confirm_auto_categorisations" value="0">
                <button class="button primary"' . $postButtonAttributes . '>Post Categorised Transactions</button>
            </form>';
    }

    private function monthNavigation(array $monthStatus, string $selectedTransactionMonth): array
    {
        $monthKeys = [];
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }

            $monthKey = trim((string)($month['month_key'] ?? ''));
            if ($monthKey !== '') {
                $monthKeys[] = $monthKey;
            }
        }

        $currentIndex = array_search($selectedTransactionMonth, $monthKeys, true);
        if ($currentIndex === false) {
            return [
                'previous' => '',
                'next' => '',
            ];
        }

        return [
            'previous' => $monthKeys[$currentIndex - 1] ?? '',
            'next' => $monthKeys[$currentIndex + 1] ?? '',
        ];
    }

    private function monthNavigationButtonHtml(
        string $label,
        string $monthKey,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionFilter,
        string $direction
    ): string {
        $buttonText = HelperFramework::escape($label);
        $buttonAttributes = $monthKey !== ''
            ? ' type="submit"'
            : ' type="button" disabled';

        return '<form class="toolbar" method="post" action="?page=transactions" data-ajax="true" data-month-navigation="' . HelperFramework::escape($direction) . '">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="global_action" value="select_transaction_month">
                <input type="hidden" name="selection_source" value="transactions_imported_filters">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($monthKey) . '">
                <button class="button"' . $buttonAttributes . '>' . $buttonText . '</button>
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
        array $settings,
        bool $isPeriodLocked = false,
        int $interAcActiveTransactionId = 0
    ): TableFramework {
        $rows = $this->transactionTableRows($transactions);

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
                html: fn(array $row): string => $this->isSyntheticSplitRow($row)
                    ? ''
                    : HelperFramework::escape($this->displayDate((string)($row['txn_date'] ?? '')))
            )
            ->column(
                'description',
                'Description',
                html: fn(array $row): string => $this->descriptionHtml($row, $isPeriodLocked)
            )
            ->column(
                'source_account',
                'Source',
                html: fn(array $row): string => $this->isSyntheticSplitRow($row) ? '' : $this->sourceHtml($row)
            )
            ->column(
                'amount',
                'Amount',
                html: function (array $row) use ($settings, $isPeriodLocked): string {
                    return $this->amountHtml($row, $settings, $isPeriodLocked);
                },
                cellClass: 'numeric'
            )
            ->column(
                'document',
                'Document',
                html: fn(array $row): string => $this->isSyntheticSplitRow($row) ? '' : $this->documentHtml($row, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter)
            )
            ->column(
                'categorisation',
                'Categorisation',
                html: fn(array $row): string => $this->categorisationHtml($row, $nominalAccounts, $activeTransferCompanyAccounts, $isPeriodLocked, $interAcActiveTransactionId)
            )
            ->column(
                'status',
                'Status',
                html: function (array $row): string {
                    $label = $this->transactionCategorisationStatusLabel($row);
                    return $label === ''
                        ? ''
                        : '<span class="badge ' . HelperFramework::escape($this->transactionCategorisationStatusBadgeClass($row)) . '">' . HelperFramework::escape($label) . '</span>';
                },
                cellClass: 'transactions-imported-pill-cell'
            )
            ->column(
                'auto_approval',
                'Auto Decision',
                html: fn(array $row): string => $this->autoApprovalHtml($row, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter, $isPeriodLocked),
                export: fn(array $row): string => $this->autoApprovalExport($row)
            )
            ->column(
                'flags',
                'Flags',
                html: fn(array $row): string => $this->flagsHtml($row),
                cellClass: 'transactions-imported-pill-cell'
            )
            ->column(
                'journal',
                'Journal',
                html: function (array $row): string {
                    $label = $this->transactionJournalStatusLabel($row);
                    return $label === ''
                        ? ''
                        : '<span class="badge ' . HelperFramework::escape($this->transactionJournalStatusBadgeClass($row)) . '">' . HelperFramework::escape($label) . '</span>';
                },
                cellClass: 'transactions-imported-pill-cell'
            )
            ->column(
                'notes',
                'Notes',
                html: fn(array $row): string => $this->notesHtml($row, $isPeriodLocked),
                export: static fn(array $row): string => (string)($row['notes'] ?? '')
            )
            ->column(
                'actions',
                'Create / Set',
                html: fn(array $row): string => $this->actionsHtml($row, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter, $settings, $isPeriodLocked, $activeTransferCompanyAccounts, $interAcActiveTransactionId),
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

    private function transactionTableRows(array $transactions): array
    {
        $rows = [];

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $rows[] = $transaction;
            if ((int)($transaction['has_transaction_split'] ?? 0) !== 1) {
                continue;
            }

            foreach ((array)($transaction['transaction_split_lines'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $lineRow = $transaction;
                $lineRow['_transaction_row_type'] = 'split_line';
                $lineRow['transaction_split_line_id'] = (int)($line['id'] ?? 0);
                $lineRow['split_line_number'] = (int)($line['line_number'] ?? 0);
                $lineRow['split_line_description'] = (string)($line['description'] ?? '');
                $lineRow['split_line_amount'] = (string)($line['amount'] ?? '');
                $lineRow['split_line_nominal_account_id'] = (int)($line['nominal_account_id'] ?? 0);
                $lineRow['split_line_notes'] = (string)($line['notes'] ?? '');
                $lineRow['split_line_is_deferred'] = (int)($line['is_deferred'] ?? 0);
                $lineRow['split_line_is_complete'] = (int)($line['is_complete'] ?? 0);
                $lineRow['split_line_nominal_code'] = (string)($line['nominal_code'] ?? '');
                $lineRow['split_line_nominal_name'] = (string)($line['nominal_name'] ?? '');
                $rows[] = $lineRow;
            }

            $differenceRow = $transaction;
            $differenceRow['_transaction_row_type'] = 'split_difference';
            $rows[] = $differenceRow;
        }

        return $rows;
    }

    private function isSyntheticSplitRow(array $row): bool
    {
        return in_array($this->transactionRowType($row), ['split_line', 'split_difference'], true);
    }

    private function transactionRowType(array $row): string
    {
        return (string)($row['_transaction_row_type'] ?? 'transaction');
    }

    private function descriptionHtml(array $transaction, bool $isPeriodLocked = false): string
    {
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return '';
        }

        if ($this->transactionRowType($transaction) === 'split_line') {
            $lineId = (int)($transaction['transaction_split_line_id'] ?? 0);
            $formId = 'transaction-split-line-form-' . $lineId;
            $description = (string)($transaction['split_line_description'] ?? '');
            $disabled = $isPeriodLocked ? ' disabled title="Period locked"' : '';
            $autosave = $isPeriodLocked
                ? ''
                : ' form="' . HelperFramework::escape($formId) . '" data-initial-value="' . HelperFramework::escape($description) . '" data-autosave-submit-target=".js-transaction-split-line-autosave-submit"';

            return '<input class="input transaction-split-line-input" type="text" name="split_line_description" value="' . HelperFramework::escape($description) . '" aria-label="Split line description"' . $autosave . $disabled . '>';
        }

        $helperLines = [];
        $reference = trim((string)($transaction['reference'] ?? ''));
        if ($reference !== '') {
            $helperLines[] = 'Ref: ' . HelperFramework::escape($reference);
        }

        if (!$this->transactionHasInterAccountMarker($transaction) && (int)($transaction['auto_rule_id'] ?? 0) > 0) {
            $matchValue = trim((string)($transaction['auto_rule_match_value'] ?? ''));
            $helperLines[] = 'Matched by rule #' . (int)($transaction['auto_rule_id'] ?? 0)
                . ($matchValue !== '' ? ' (' . HelperFramework::escape($matchValue) . ')' : '');
        }

        $helperHtml = $helperLines !== [] ? '<div class="helper">' . implode('<br>', $helperLines) . '</div>' : '';

        return '<div>' . HelperFramework::escape((string)($transaction['description'] ?? '')) . '</div>' . $helperHtml;
    }

    private function amountHtml(array $transaction, array $settings, bool $isPeriodLocked): string
    {
        $rowType = $this->transactionRowType($transaction);
        if ($rowType === 'split_difference') {
            $difference = (string)($transaction['transaction_split_difference'] ?? '0.00');
            $badgeClass = abs((float)$difference) < 0.005 ? 'success' : 'warning';

            return '<span class="badge ' . HelperFramework::escape($badgeClass) . ' transaction-split-difference">Difference: '
                . (new \eel_accounts\Service\CompanySettingsService())->moneyHtml($settings, $difference)
                . '</span>';
        }

        if ($rowType === 'split_line') {
            $lineId = (int)($transaction['transaction_split_line_id'] ?? 0);
            $formId = 'transaction-split-line-form-' . $lineId;
            $amount = (string)($transaction['split_line_amount'] ?? '');
            $disabled = $isPeriodLocked ? ' disabled title="Period locked"' : '';
            $autosave = $isPeriodLocked
                ? ''
                : ' form="' . HelperFramework::escape($formId) . '" data-initial-value="' . HelperFramework::escape($amount) . '" data-autosave-submit-target=".js-transaction-split-line-autosave-submit"';

            return '<input class="input transaction-split-line-amount" type="text" inputmode="decimal" pattern="[0-9]+\.[0-9]{2}" title="Enter a positive amount with exactly 2 decimal places, for example 56.37" name="split_line_amount" value="' . HelperFramework::escape($amount) . '" aria-label="Split line amount"' . $autosave . $disabled . '>';
        }

        return (new \eel_accounts\Service\CompanySettingsService())->moneyHtml($settings, $transaction['amount'] ?? null);
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
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
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
        bool $isPeriodLocked,
        int $interAcActiveTransactionId
    ): string
    {
        $transactionFormId = 'transaction-form-' . (int)($transaction['id'] ?? 0);
        $rowType = $this->transactionRowType($transaction);

        if ($rowType === 'split_difference') {
            return '';
        }

        if ($rowType === 'split_line') {
            return $this->splitLineCategorisationHtml($transaction, $nominalAccounts, $isPeriodLocked);
        }

        if ($this->interAccountControlIsActive($transaction, $interAcActiveTransactionId)) {
            return $this->interAccountCategorisationHtml($transaction, $transactionFormId, $isPeriodLocked);
        }

        if ((int)($transaction['has_transaction_split'] ?? 0) === 1) {
            if ($isPeriodLocked) {
                return $this->readonlyCategorisationHtml($transaction, $nominalAccounts, $activeTransferCompanyAccounts);
            }

            $mergeConfirmAttributes = (int)($transaction['has_derived_journal'] ?? 0) === 1
                ? ' data-chicken-check="true" data-chicken-title="Confirm journal rebuild" data-chicken-message="This will remove the split journal for this transaction.<br><br>Continue?" data-chicken-confirm-text="Continue" data-chicken-button-class="button primary" data-submit-field="confirm_rebuild_journal" data-submit-value="1"'
                : '';

            return '<div class="actions-row transaction-split-parent-actions">
                    <button class="button" type="submit" form="' . HelperFramework::escape($transactionFormId) . '" name="global_action" value="merge_transaction_split"' . $mergeConfirmAttributes . '>Merge</button>
                    <button class="button" type="submit" form="' . HelperFramework::escape($transactionFormId) . '" name="global_action" value="add_transaction_split_line">Add Split</button>
                </div>';
        }

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
                <select class="select js-transaction-transfer" name="transfer_account_id" form="' . HelperFramework::escape($transactionFormId) . '" data-autosave-submit-target=".js-transaction-autosave-submit" data-autosave-require-value="1">' . $transferOptions . '</select>';
        }

        $selectedNominalAccountId = (string)($transaction['nominal_account_id'] ?? '');
        $nominalOptions = $this->nominalSelectOptions($nominalAccounts, $selectedNominalAccountId);

        return '<div class="transaction-categorisation-control">
                <select class="select js-transaction-nominal" name="nominal_account_id" form="' . HelperFramework::escape($transactionFormId) . '" data-autosave-submit-target=".js-transaction-autosave-submit" data-autosave-require-value="1">' . $nominalOptions . '</select>
                <button class="button" type="submit" form="' . HelperFramework::escape($transactionFormId) . '" name="global_action" value="start_transaction_split">Split</button>
            </div>';
    }

    private function interAccountCategorisationHtml(array $transaction, string $transactionFormId, bool $isPeriodLocked): string
    {
        $role = trim((string)($transaction['inter_ac_marker_role'] ?? ''));
        if ((int)($transaction['inter_ac_marker_id'] ?? 0) > 0) {
            $label = $this->interAccountPeerLabel($transaction);
            $roleLabel = $role === 'matched' ? 'Inter A/C Dest' : 'Posting Source';
            $cancelButtonHtml = '';
            if ($role === 'source') {
                $buttonAttributes = $isPeriodLocked
                    ? ' type="button" disabled title="Period locked"'
                    : ' type="submit" form="' . HelperFramework::escape($transactionFormId) . '" name="global_action" value="cancel_inter_ac_transaction" data-chicken-check="true" data-chicken-title="Cancel inter-account match" data-chicken-message="This will remove the inter-account link and its bank-derived journals.<br><br>Continue?" data-chicken-confirm-text="Cancel match" data-chicken-button-class="button primary"';
                $cancelButtonHtml = '<button class="button button-inline"' . $buttonAttributes . '>cancel</button>';
            }

            return '<div class="transactions-imported-inter-ac-summary">
                    <span class="badge info">' . HelperFramework::escape($roleLabel) . '</span>
                    ' . $cancelButtonHtml . '
                    <span class="helper">' . HelperFramework::escape($label) . '</span>
                </div>';
        }

        if ($isPeriodLocked) {
            return '<span class="helper">Period locked</span>';
        }

        $options = '<option value="">Select matching transaction</option>';
        $candidates = (new \eel_accounts\Service\TransactionInterAccountMarkerService())->fetchCandidates((int)($transaction['id'] ?? 0));
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $options .= '<option value="' . (int)($candidate['id'] ?? 0) . '">' . HelperFramework::escape($this->interAccountCandidateLabel($candidate)) . '</option>';
        }

        if ($candidates === []) {
            $options .= '<option value="" disabled>No matching transactions found</option>';
        }

        return '<select class="select js-transaction-inter-ac-candidate" name="matched_transaction_id" form="' . HelperFramework::escape($transactionFormId) . '" data-autosave-submit-target=".js-transaction-inter-ac-submit" data-autosave-require-value="1">' . $options . '</select>';
    }

    private function interAccountControlIsActive(array $transaction, int $interAcActiveTransactionId): bool
    {
        return (int)($transaction['inter_ac_marker_id'] ?? 0) > 0
            || ($interAcActiveTransactionId > 0 && $interAcActiveTransactionId === (int)($transaction['id'] ?? 0));
    }

    private function interAccountPeerLabel(array $transaction): string
    {
        return $this->interAccountCandidateLabel([
            'account_name' => (string)($transaction['inter_ac_peer_account_name'] ?? ''),
            'txn_date' => (string)($transaction['inter_ac_peer_txn_date'] ?? ''),
            'description' => (string)($transaction['inter_ac_peer_description'] ?? ''),
            'amount' => (string)($transaction['inter_ac_peer_amount'] ?? '0.00'),
        ]);
    }

    private function interAccountCandidateLabel(array $transaction): string
    {
        $parts = [
            trim((string)($transaction['account_name'] ?? '')),
            $this->displayDate((string)($transaction['txn_date'] ?? '')),
            trim((string)($transaction['description'] ?? '')),
            number_format((float)($transaction['amount'] ?? 0), 2, '.', ''),
        ];

        return trim(implode(' ', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function splitLineCategorisationHtml(array $transaction, array $nominalAccounts, bool $isPeriodLocked): string
    {
        $lineId = (int)($transaction['transaction_split_line_id'] ?? 0);
        $formId = 'transaction-split-line-form-' . $lineId;
        $selectedNominalAccountId = (string)($transaction['split_line_nominal_account_id'] ?? '');

        if ($isPeriodLocked) {
            return '<div>' . HelperFramework::escape($this->splitLineNominalLabel($transaction, $nominalAccounts)) . '</div>';
        }

        return '<select class="select transaction-split-line-nominal" name="nominal_account_id" form="' . HelperFramework::escape($formId) . '" data-autosave-submit-target=".js-transaction-split-line-autosave-submit" data-autosave-require-value="1">' . $this->nominalSelectOptions($nominalAccounts, $selectedNominalAccountId) . '</select>';
    }

    private function nominalSelectOptions(array $nominalAccounts, string $selectedNominalAccountId): string
    {
        $nominalOptions = '<option value="">Unassigned</option>';
        foreach ($nominalAccounts as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }
            $nominalOptions .= '<option value="' . (int)($nominal['id'] ?? 0) . '"' . ((string)($nominal['id'] ?? '') === $selectedNominalAccountId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return $nominalOptions;
    }

    private function flagsHtml(array $transaction): string
    {
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return '';
        }

        $hasInterAccountMarker = $this->transactionHasInterAccountMarker($transaction);
        $flagsHtml = '<div class="transactions-imported-pill-wrap">';
        if (!$hasInterAccountMarker && (int)($transaction['is_auto_excluded'] ?? 0) === 1) {
            $flagsHtml .= '<span class="badge warning">Deferred</span>';
        }
        if ((int)($transaction['has_transaction_split'] ?? 0) === 1 && $this->transactionRowType($transaction) === 'transaction') {
            $flagsHtml .= '<span class="badge info">Split</span>';
        }
        if ($hasInterAccountMarker) {
            $flagsHtml .= '<span class="badge info">Inter A/C</span>';
        }
        if ($this->transactionRowType($transaction) === 'split_line' && (int)($transaction['split_line_is_deferred'] ?? 0) === 1) {
            $flagsHtml .= '<span class="badge warning">Deferred</span>';
        }
        if (!$hasInterAccountMarker && $this->autoApprovalConfirmedCurrent($transaction)) {
            $flagsHtml .= '<span class="badge success">Auto Correct</span>';
        }
        if (!$hasInterAccountMarker && !$this->transactionIsTransferMode($transaction) && (int)($transaction['auto_rule_id'] ?? 0) > 0) {
            $flagsHtml .= '<span class="badge info">Rule #' . (int)($transaction['auto_rule_id'] ?? 0) . '</span>';
        }
        if ((int)($transaction['has_dividend_declaration'] ?? 0) === 1) {
            $flagsHtml .= '<span class="badge success">Dividend created</span>';
        }

        return $flagsHtml . '</div>';
    }

    private function notesHtml(array $transaction, bool $isPeriodLocked): string
    {
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return '';
        }

        if ($this->transactionRowType($transaction) === 'split_line') {
            $lineId = (int)($transaction['transaction_split_line_id'] ?? 0);
            $notes = (string)($transaction['split_line_notes'] ?? '');
            $formId = 'transaction-split-line-form-' . $lineId;
            $disabled = $isPeriodLocked ? ' disabled title="Period locked"' : '';
            $autosaveAttributes = $isPeriodLocked
                ? ''
                : ' form="' . HelperFramework::escape($formId) . '" data-initial-value="' . HelperFramework::escape($notes) . '" data-autosave-submit-target=".js-transaction-split-line-autosave-submit"';

            return '<input class="input transaction-note-input" type="text" name="split_line_notes" value="' . HelperFramework::escape($notes) . '" aria-label="Split line note"' . $autosaveAttributes . $disabled . '>';
        }

        $transactionId = (int)($transaction['id'] ?? 0);
        $notes = (string)($transaction['notes'] ?? '');
        $formId = 'transaction-form-' . $transactionId;
        $disabled = $isPeriodLocked ? ' disabled title="Period locked"' : '';
        $autosaveAttributes = $isPeriodLocked
            ? ''
            : ' form="' . HelperFramework::escape($formId) . '" data-initial-value="' . HelperFramework::escape($notes) . '" data-autosave-submit-target=".js-transaction-note-autosave-submit"';

        return '<input class="input transaction-note-input" type="text" name="notes" value="' . HelperFramework::escape($notes) . '" aria-label="Transaction note"' . $autosaveAttributes . $disabled . '>';
    }

    private function autoApprovalHtml(
        array $transaction,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        bool $isPeriodLocked
    ): string {
        if ($this->isSyntheticSplitRow($transaction)) {
            return '<span class="helper">-</span>';
        }

        if (!$this->isRuleBasedAutoTransaction($transaction)) {
            return '<span class="helper">-</span>';
        }

        $transactionId = (int)($transaction['id'] ?? 0);
        $checked = $this->autoApprovalCheckedCurrent($transaction) ? ' checked' : '';
        $confirmed = $this->autoApprovalConfirmedCurrent($transaction);
        $pendingPostConfirmation = $checked !== '' && !$confirmed;
        $decisionLabel = $checked !== '' ? 'Correct' : 'Unconfirmed';
        $disabled = $isPeriodLocked ? ' disabled title="Period locked"' : '';

        return '<label class="checkbox-item" data-auto-approval-item="true">
                <input type="checkbox" value="1"
                    data-auto-approval-control="true"
                    data-auto-approval-transaction-id="' . $transactionId . '"
                    data-auto-approval-initial="' . ($checked !== '' ? '1' : '0') . '"
                    data-auto-approval-confirmed-initial="' . ($confirmed ? '1' : '0') . '"
                    data-auto-approval-pending-initial="' . ($pendingPostConfirmation ? '1' : '0') . '"' . $checked . $disabled . '>
                <span class="auto-approval-copy">
                    <span class="helper" data-auto-approval-status data-auto-approval-default-status="' . HelperFramework::escape($decisionLabel) . '" aria-live="polite">' . HelperFramework::escape($decisionLabel) . '</span>
                </span>
            </label>';
    }

    private function autoApprovalBatchFormHtml(
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter
    ): string {
        return '<form method="post" action="?page=transactions" data-ajax="true" data-auto-approval-batch-form="true" hidden>
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="global_action" value="sync_auto_approval_state">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <button type="submit" data-auto-approval-batch-submit hidden>Save auto approvals</button>
            </form>';
    }

    private function autoApprovalExport(array $transaction): string
    {
        if ($this->isSyntheticSplitRow($transaction)) {
            return '-';
        }

        if (!$this->isRuleBasedAutoTransaction($transaction)) {
            return '-';
        }

        return $this->autoApprovalCheckedCurrent($transaction) ? 'Correct' : 'Unconfirmed';
    }

    private function actionsHtml(
        array $transaction,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        array $settings,
        bool $isPeriodLocked,
        array $activeTransferCompanyAccounts,
        int $interAcActiveTransactionId
    ): string
    {
        $rowType = $this->transactionRowType($transaction);
        if ($rowType === 'split_difference') {
            return '';
        }
        if ($rowType === 'split_line') {
            return $this->splitLineActionsHtml($transaction, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter, $isPeriodLocked);
        }

        $transactionId = (int)($transaction['id'] ?? 0);
        $transactionFormId = 'transaction-form-' . $transactionId;
        if ($this->transactionHasInterAccountMarker($transaction)) {
            return $this->interAccountMarkedActionsHtml(
                $transactionFormId,
                $transactionId,
                $companyId,
                $accountingPeriodId,
                $selectedTransactionMonth,
                $selectedTransactionFilter
            );
        }
        $assetFormId = 'transaction-asset-form-' . $transactionId;
        $dividendFormId = 'transaction-dividend-form-' . $transactionId;
        $isTransferRow = $this->transactionIsTransferMode($transaction);
        $journalRebuildAttributes = !$isPeriodLocked && (int)($transaction['has_derived_journal'] ?? 0) === 1
            ? ' data-chicken-check="true" data-chicken-title="Confirm journal rebuild" data-chicken-message="This will rebuild the journal entry for this transaction.<br><br>Continue?" data-chicken-confirm-text="Continue" data-chicken-button-class="button primary" data-submit-field="confirm_rebuild_journal" data-submit-value="1"'
            : '';
        $lockedButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $autosaveSubmitHtml = $isPeriodLocked
            ? ''
            : '<button class="js-transaction-autosave-submit" type="submit" name="global_action" value="save_transaction_category" data-blur-scope="none" hidden' . $journalRebuildAttributes . '>Autosave</button>';
        $interAcAutosaveSubmitHtml = $isPeriodLocked
            ? ''
            : '<button class="js-transaction-inter-ac-submit" type="submit" name="global_action" value="save_inter_ac_transaction" data-blur-scope="none" hidden>Autosave Inter A/C</button>';
        $noteAutosaveSubmitHtml = $isPeriodLocked
            ? ''
            : '<button class="js-transaction-note-autosave-submit" type="submit" name="global_action" value="save_transaction_note" hidden>Autosave note</button>';
        $createAssetAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit" form="' . HelperFramework::escape($assetFormId) . '" formnovalidate';
        $createRuleHtml = $isTransferRow ? '' : $this->createRuleButtonHtml($transaction, $isPeriodLocked);
        $directorLoanButtonHtml = $this->directorLoanButtonHtml($transaction, $settings, $isPeriodLocked, $journalRebuildAttributes);
        $dividendButtonHtml = $this->dividendButtonHtml($transaction, $dividendFormId, $settings, $isPeriodLocked);
        $interAcButtonHtml = $this->interAccountButtonHtml($transaction, $activeTransferCompanyAccounts, $isPeriodLocked, $interAcActiveTransactionId);
        $isSplitParent = (int)($transaction['has_transaction_split'] ?? 0) === 1;

        if ($isSplitParent) {
            return '<form method="post" action="?page=transactions" id="' . HelperFramework::escape($transactionFormId) . '" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="confirm_rebuild_journal" value="0">
            </form>';
        }

        return '<form method="post" action="?page=assets&amp;show_card=asset_create" id="' . HelperFramework::escape($assetFormId) . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
            </form>
            <form method="post" action="?page=transactions" id="' . HelperFramework::escape($dividendFormId) . '" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="declare_dividend_from_transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
            </form>
            <form method="post" action="?page=transactions" id="' . HelperFramework::escape($transactionFormId) . '" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="confirm_rebuild_journal" value="0">
                ' . $autosaveSubmitHtml . '
                ' . $interAcAutosaveSubmitHtml . '
                ' . $noteAutosaveSubmitHtml . '
                <div class="actions-row">
                    ' . $createRuleHtml . '
                    ' . $directorLoanButtonHtml . '
                    ' . $dividendButtonHtml . '
                    ' . $interAcButtonHtml . '
                    <button class="button primary"' . $lockedButtonAttributes . ' name="global_action" value="defer_transaction"' . $journalRebuildAttributes . '>Defer</button>
                    <button class="button"' . $createAssetAttributes . '>Asset</button>
                </div>
            </form>';
    }

    private function interAccountMarkedActionsHtml(
        string $transactionFormId,
        int $transactionId,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter
    ): string {
        return '<form method="post" action="?page=transactions" id="' . HelperFramework::escape($transactionFormId) . '" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
            </form>';
    }

    private function splitLineActionsHtml(
        array $transaction,
        int $companyId,
        int $accountingPeriodId,
        string $selectedTransactionMonth,
        string $selectedTransactionFilter,
        bool $isPeriodLocked
    ): string {
        $transactionId = (int)($transaction['id'] ?? 0);
        $lineId = (int)($transaction['transaction_split_line_id'] ?? 0);
        $formId = 'transaction-split-line-form-' . $lineId;
        $assetFormId = 'transaction-split-line-asset-form-' . $lineId;
        $lockedButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $assetButtonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit" form="' . HelperFramework::escape($assetFormId) . '" formnovalidate';

        return '<form method="post" action="?page=assets&amp;show_card=asset_create" id="' . HelperFramework::escape($assetFormId) . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_split_line_id" value="' . $lineId . '">
            </form>
            <form method="post" action="?page=transactions" id="' . HelperFramework::escape($formId) . '" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                <input type="hidden" name="transaction_split_line_id" value="' . $lineId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <button class="js-transaction-split-line-autosave-submit" type="submit" name="global_action" value="save_transaction_split_line" hidden>Autosave split line</button>
                <div class="actions-row">
                    <button class="button primary"' . $lockedButtonAttributes . ' name="global_action" value="defer_transaction_split_line">Defer</button>
                    <button class="button"' . $lockedButtonAttributes . ' name="global_action" value="remove_transaction_split_line">Remove</button>
                    <button class="button"' . $assetButtonAttributes . '>Asset</button>
                </div>
            </form>';
    }

    private function interAccountButtonHtml(
        array $transaction,
        array $activeTransferCompanyAccounts,
        bool $isPeriodLocked,
        int $interAcActiveTransactionId
    ): string {
        if (count($activeTransferCompanyAccounts) < 2) {
            return '';
        }

        if ((int)($transaction['has_transaction_split'] ?? 0) === 1) {
            return '';
        }

        $isActive = $this->interAccountControlIsActive($transaction, $interAcActiveTransactionId);
        $buttonClass = $isActive ? 'button primary' : 'button';
        $buttonAttributes = $isPeriodLocked ? ' type="button" disabled title="Period locked"' : ' type="submit"';
        $pendingInput = $isActive && (int)($transaction['inter_ac_marker_id'] ?? 0) <= 0
            ? '<input type="hidden" name="inter_ac_pending" value="1">'
            : '';

        return $pendingInput . '<button class="' . HelperFramework::escape($buttonClass) . '"' . $buttonAttributes . ' name="global_action" value="toggle_inter_ac_transaction">Inter A/C Trans.</button>';
    }

    private function dividendButtonHtml(array $transaction, string $dividendFormId, array $settings, bool $isPeriodLocked): string
    {
        if ($this->transactionIsTransferMode($transaction)) {
            return '';
        }

        if ((int)($transaction['has_dividend_declaration'] ?? 0) === 1) {
            return '';
        }

        $disabledReason = $this->dividendButtonDisabledReason($transaction, $isPeriodLocked);
        if ($disabledReason !== '') {
            return '<button class="button" type="button" disabled title="' . HelperFramework::escape($disabledReason) . '">Dividend</button>';
        }

        $amount = $this->money($settings, abs((float)($transaction['amount'] ?? 0)));
        $date = $this->displayDate((string)($transaction['txn_date'] ?? ''));
        $message = 'Create a dividend declaration journal for ' . HelperFramework::escape($amount)
            . ' dated ' . HelperFramework::escape($date)
            . '.<br><br>The transaction will remain categorised to Dividends Payable.';

        return '<button class="button" type="submit" form="' . HelperFramework::escape($dividendFormId) . '" formnovalidate
                data-chicken-check="true"
                data-chicken-title="Create dividend declaration"
                data-chicken-message="' . $message . '"
                data-chicken-confirm-text="Create Dividend"
                data-chicken-button-class="button primary">Dividend</button>';
    }

    private function dividendButtonDisabledReason(array $transaction, bool $isPeriodLocked): string
    {
        if ($isPeriodLocked) {
            return 'Period locked';
        }
        if (round((float)($transaction['amount'] ?? 0), 2) >= 0) {
            return 'Dividend declarations can only be created from outgoing payments';
        }
        if ((string)($transaction['nominal_code'] ?? '') !== '2150') {
            return 'Categorise the transaction to Dividends Payable first';
        }
        if (!in_array((string)($transaction['category_status'] ?? ''), ['auto', 'manual'], true)) {
            return 'Categorise the transaction before creating a dividend declaration';
        }

        return '';
    }

    private function directorLoanButtonHtml(array $transaction, array $settings, bool $isPeriodLocked, string $journalRebuildAttributes): string
    {
        if ($this->transactionIsTransferMode($transaction)) {
            return '';
        }

        $amount = round((float)($transaction['amount'] ?? 0), 2);
        $disabledReason = '';
        if ($isPeriodLocked) {
            $disabledReason = 'Period locked';
        } elseif (abs($amount) < 0.005) {
            $disabledReason = 'Director loan shortcut requires a non-zero amount';
        } elseif ($this->directorLoanNominalId($settings, $amount) <= 0) {
            $disabledReason = $amount < 0
                ? 'Set Director Loan Asset nominal in Company Nominals'
                : 'Set Director Loan Liability nominal in Company Nominals';
        }

        if ($disabledReason !== '') {
            return '<button class="button" type="button" disabled title="' . HelperFramework::escape($disabledReason) . '">Director Loan</button>';
        }

        return '<button class="button" type="submit" name="global_action" value="mark_director_loan"' . $journalRebuildAttributes . '>Director Loan</button>';
    }

    private function directorLoanNominalId(array $settings, float $amount): int
    {
        if ($amount < 0) {
            return $this->positiveSettingId($settings['director_loan_asset_nominal_id'] ?? '');
        }

        $liabilityNominalId = $this->positiveSettingId($settings['director_loan_liability_nominal_id'] ?? '');

        return $liabilityNominalId > 0
            ? $liabilityNominalId
            : $this->positiveSettingId($settings['director_loan_nominal_id'] ?? '');
    }

    private function positiveSettingId(mixed $value): int
    {
        if (!is_scalar($value) && $value !== null) {
            return 0;
        }

        $value = trim((string)$value);

        return ctype_digit($value) ? (int)$value : 0;
    }

    private function createRuleButtonHtml(array $transaction, bool $isPeriodLocked): string
    {
        if ($isPeriodLocked) {
            return '<button class="button primary" type="button" disabled title="Period locked">Rule</button>';
        }

        return '<input type="hidden" name="transaction_reference" value="' . HelperFramework::escape((string)($transaction['reference'] ?? '')) . '">'
            . '<button class="button primary" type="submit" name="global_action" value="auto_create_transaction_rule" data-show-card="transactions_rule_form">Rule</button>';
    }

    private function readonlyCategorisationHtml(array $transaction, array $nominalAccounts, array $activeTransferCompanyAccounts): string
    {
        if ($this->transactionRowType($transaction) === 'split_line') {
            return '<div>' . HelperFramework::escape($this->splitLineNominalLabel($transaction, $nominalAccounts)) . '</div>';
        }

        if ((int)($transaction['has_transaction_split'] ?? 0) === 1) {
            return '<div class="helper">Split</div><div>' . count((array)($transaction['transaction_split_lines'] ?? [])) . ' line(s)</div>';
        }

        if ($this->transactionIsTransferMode($transaction)) {
            $transferDirectionLabel = (float)($transaction['amount'] ?? 0) < 0 ? 'Transfer to:' : 'Transfer from:';

            return '<div class="helper">' . HelperFramework::escape($transferDirectionLabel) . '</div>
                <div>' . HelperFramework::escape($this->transferAccountLabel($transaction, $activeTransferCompanyAccounts)) . '</div>';
        }

        return '<div>' . HelperFramework::escape($this->nominalAccountLabel($transaction, $nominalAccounts)) . '</div>';
    }

    private function splitLineNominalLabel(array $transaction, array $nominalAccounts): string
    {
        $nominalAccountId = (int)($transaction['split_line_nominal_account_id'] ?? 0);
        foreach ($nominalAccounts as $nominal) {
            if (is_array($nominal) && (int)($nominal['id'] ?? 0) === $nominalAccountId) {
                return FormattingFramework::nominalLabel($nominal);
            }
        }

        $code = trim((string)($transaction['split_line_nominal_code'] ?? ''));
        $name = trim((string)($transaction['split_line_nominal_name'] ?? ''));
        if ($code !== '' || $name !== '') {
            return trim($code . ' ' . $name);
        }

        return 'Unassigned';
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
            'pending_auto_approval' => 0,
            'ready_to_post' => 0,
            'posted' => 0,
        ];

        foreach ($transactionsByMonth as $row) {
            if (!is_array($row)) {
                continue;
            }

            $isMatchedNoPost = trim((string)($row['inter_ac_marker_role'] ?? '')) === 'matched';
            $status = (string)($row['category_status'] ?? 'uncategorised');
            if (!$isMatchedNoPost && isset($summary[$status])) {
                $summary[$status]++;
            }

            if (!$isMatchedNoPost && (int)($row['is_auto_excluded'] ?? 0) === 1) {
                $summary['deferred']++;
            }

            if ($isMatchedNoPost) {
                continue;
            }

            if ((int)($row['has_derived_journal'] ?? 0) === 1) {
                $summary['posted']++;
            } elseif ((int)($row['has_transaction_split'] ?? 0) === 1 && (int)($row['transaction_split_ready'] ?? 0) === 1) {
                $summary['ready_to_post']++;
            } elseif ($this->transactionIsTransferMode($row) && $this->transactionHasTransferAssignment($row)) {
                $summary['ready_to_post']++;
            } elseif (in_array($status, ['auto', 'manual'], true) && (int)($row['nominal_account_id'] ?? 0) > 0) {
                $summary['ready_to_post']++;
            }

            if ($this->isRuleBasedAutoTransaction($row) && (int)($row['nominal_account_id'] ?? 0) > 0 && !$this->autoApprovalConfirmedCurrent($row)) {
                $summary['pending_auto_approval']++;
            }
        }

        return $summary;
    }

    private function isRuleBasedAutoTransaction(array $transaction): bool
    {
        return strtolower(trim((string)($transaction['category_status'] ?? ''))) === 'auto'
            && !$this->transactionHasInterAccountMarker($transaction)
            && !$this->transactionIsTransferMode($transaction)
            && (int)($transaction['auto_rule_id'] ?? 0) > 0;
    }

    private function transactionHasInterAccountMarker(array $transaction): bool
    {
        return (int)($transaction['inter_ac_marker_id'] ?? 0) > 0;
    }

    private function autoApprovalCheckedCurrent(array $transaction): bool
    {
        return (int)($transaction['auto_approval_checked_current'] ?? 0) === 1;
    }

    private function autoApprovalConfirmedCurrent(array $transaction): bool
    {
        return (int)($transaction['auto_approval_confirmed_current'] ?? 0) === 1;
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
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return 'muted';
        }
        if ($this->transactionRowType($transaction) === 'split_line') {
            if ((int)($transaction['split_line_is_deferred'] ?? 0) === 1) {
                return 'warning';
            }

            return (int)($transaction['split_line_is_complete'] ?? 0) === 1 ? 'success' : 'warning';
        }

        if ((int)($transaction['inter_ac_marker_id'] ?? 0) > 0) {
            return 'info';
        }

        $status = strtolower(trim((string)($transaction['category_status'] ?? 'uncategorised')));
        return match ($status) {
            'auto' => 'info',
            'manual' => 'success',
            default => 'warning',
        };
    }

    private function transactionCategorisationStatusLabel(array $transaction): string
    {
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return '';
        }
        if ($this->transactionRowType($transaction) === 'split_line') {
            if ((int)($transaction['split_line_is_deferred'] ?? 0) === 1) {
                return 'Deferred';
            }

            return (int)($transaction['split_line_is_complete'] ?? 0) === 1 ? 'Ready' : 'Not ready';
        }

        $interAcRole = trim((string)($transaction['inter_ac_marker_role'] ?? ''));
        if ($interAcRole === 'matched') {
            return 'Inter A/C Dest';
        }
        if ($interAcRole === 'source') {
            return 'Inter A/C Src';
        }

        $status = strtolower(trim((string)($transaction['category_status'] ?? 'uncategorised')));
        if ($this->transactionIsTransferMode($transaction)) {
            return $this->transactionHasTransferAssignment($transaction) ? 'Transfer assigned' : 'Transfer pending';
        }

        return match ($status) {
            'auto' => 'Auto',
            'manual' => 'Manual',
            default => 'Uncategorised',
        };
    }

    private function transactionJournalStatusBadgeClass(array $transaction): string
    {
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return 'muted';
        }
        if ($this->transactionRowType($transaction) === 'split_line') {
            return (int)($transaction['transaction_split_ready'] ?? 0) === 1 ? 'info' : 'muted';
        }

        if (trim((string)($transaction['inter_ac_marker_role'] ?? '')) === 'matched') {
            return 'muted';
        }

        if ((int)($transaction['has_derived_journal'] ?? 0) === 1) {
            return 'success';
        }

        if ((int)($transaction['has_transaction_split'] ?? 0) === 1) {
            return (int)($transaction['transaction_split_ready'] ?? 0) === 1 ? 'info' : 'muted';
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
        if ($this->transactionRowType($transaction) === 'split_difference') {
            return '';
        }
        if ($this->transactionRowType($transaction) === 'split_line') {
            return (int)($transaction['transaction_split_ready'] ?? 0) === 1 ? 'Ready to post' : 'Not ready';
        }

        if (trim((string)($transaction['inter_ac_marker_role'] ?? '')) === 'matched') {
            return 'No posting';
        }

        if ((int)($transaction['has_derived_journal'] ?? 0) === 1) {
            return 'Posted';
        }

        if ((int)($transaction['has_transaction_split'] ?? 0) === 1) {
            return (int)($transaction['transaction_split_ready'] ?? 0) === 1 ? 'Ready to post' : 'Not ready';
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

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }
}
