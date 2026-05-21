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
    public function key(): string
    {
        return 'transactions_imported';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'month_status',
                'service' => StatementUploadService::class,
                'method' => 'buildMonthStatus',
                'params' => [
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
                ],
            ],
            [
                'key' => 'transactions_by_month',
                'service' => DashboardRepository::class,
                'method' => 'fetchTransactionsForMonth',
                'params' => [
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
                    'monthKey' => ':page.month_key',
                    'categoryFilter' => ':page.category_filter',
                ],
            ],
            [
                'key' => 'nominal_accounts',
                'service' => NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
            ],
            [
                'key' => 'company_accounts',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => true,
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
        return TransactionAction::withTransactionCardContext($request, $services, $pageContext, $actionResult);
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
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $taxYears = (array)($page['tax_years'] ?? []);

        if ($companyId <= 0) {
            return '<div class="helper">A company has to be added and selected before transaction categorisation can occur.</div>';
        }

        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $transactionsByMonth = (array)($services['transactions_by_month'] ?? []);
        $monthStatus = (array)($services['month_status'] ?? []);
        $nominalAccounts = (array)($services['nominal_accounts'] ?? []);
        $activeBankCompanyAccounts = array_values(array_filter(
            (array)($services['company_accounts'] ?? []),
            static fn(mixed $account): bool => is_array($account)
                && (string)($account['account_type'] ?? '') === CompanyAccountService::TYPE_BANK
        ));
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'all');
        $selectedMonthSummary = $this->buildSelectedMonthSummary($transactionsByMonth);

        $monthOptions = '';
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }
            $monthOptions .= '<option value="' . HelperFramework::escape((string)($month['month_key'] ?? '')) . '"' . ((string)($month['month_key'] ?? '') === $selectedTransactionMonth ? ' selected' : '') . '>' . HelperFramework::escape((string)($month['label'] ?? '')) . '</option>';
        }

        $rowsHtml = '';
        foreach ($transactionsByMonth as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $transactionId = (int)($transaction['id'] ?? 0);
            $transactionFormId = 'transaction-form-' . $transactionId;
            $assetFormId = 'transaction-asset-form-' . $transactionId;
            $selectedNominalAccountId = (string)($transaction['nominal_account_id'] ?? '');
            $selectedTransferAccountId = (string)($transaction['transfer_account_id'] ?? '');
            $isTransferRow = $this->transactionIsTransferMode($transaction);
            $transferDirectionLabel = (float)($transaction['amount'] ?? 0) < 0 ? 'Transfer to:' : 'Transfer from:';
            $journalRebuildAttributes = (int)($transaction['has_derived_journal'] ?? 0) === 1
                ? ' data-chicken-check="true" data-chicken-title="Confirm journal rebuild" data-chicken-message="This will rebuild the journal entry for this transaction.<br><br>Continue?" data-chicken-confirm-text="Continue" data-chicken-button-class="button primary" data-submit-field="confirm_rebuild_journal" data-submit-value="1"'
                : '';

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
                        <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
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
            $documentHtml .= '</div>';

            $categorisationHtml = '';
            if ($isTransferRow) {
                $transferOptions = '<option value="">Select owned account</option>';
                foreach ($activeBankCompanyAccounts as $account) {
                    if (!is_array($account) || (int)($account['id'] ?? 0) === (int)($transaction['account_id'] ?? 0)) {
                        continue;
                    }
                    $transferOptions .= '<option value="' . (int)($account['id'] ?? 0) . '"' . ((string)($account['id'] ?? '') === $selectedTransferAccountId ? ' selected' : '') . '>' . HelperFramework::escape((string)($account['account_name'] ?? '')) . '</option>';
                }

                $categorisationHtml = '<div class="helper">' . HelperFramework::escape($transferDirectionLabel) . '</div>
                    <select class="select js-transaction-transfer" name="transfer_account_id" form="' . HelperFramework::escape($transactionFormId) . '" data-initial-value="' . HelperFramework::escape($selectedTransferAccountId) . '" data-dirty-action-target=".js-transaction-action" data-dirty-require-value="1">' . $transferOptions . '</select>';
            } else {
                $nominalOptions = '<option value="">Unassigned</option>';
                foreach ($nominalAccounts as $nominal) {
                    if (!is_array($nominal)) {
                        continue;
                    }
                    $nominalOptions .= '<option value="' . (int)($nominal['id'] ?? 0) . '"' . ((string)($nominal['id'] ?? '') === $selectedNominalAccountId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
                }

                $categorisationHtml = '<select class="select js-transaction-nominal" name="nominal_account_id" form="' . HelperFramework::escape($transactionFormId) . '" data-initial-value="' . HelperFramework::escape($selectedNominalAccountId) . '" data-dirty-action-target=".js-transaction-action" data-dirty-require-value="1">' . $nominalOptions . '</select>';
            }

            $matchedRuleHtml = '';
            if ((int)($transaction['auto_rule_id'] ?? 0) > 0) {
                $matchedRuleHtml = '<div class="helper">Matched by rule #' . (int)($transaction['auto_rule_id'] ?? 0)
                    . (trim((string)($transaction['auto_rule_match_value'] ?? '')) !== '' ? ' (' . HelperFramework::escape((string)($transaction['auto_rule_match_value'] ?? '')) . ')' : '')
                    . '</div>';
            }

            $sourceCategory = (string)($transaction['source_category'] ?? '');
            $flagsHtml = '<div class="document-stack">';
            if ((int)($transaction['is_auto_excluded'] ?? 0) === 1) {
                $flagsHtml .= '<span class="badge warning">Deferred</span>';
            }
            if (!$isTransferRow && (int)($transaction['auto_rule_id'] ?? 0) > 0) {
                $flagsHtml .= '<span class="badge info">Rule #' . (int)($transaction['auto_rule_id'] ?? 0) . '</span>';
            }
            $flagsHtml .= '</div>';

            $rowsHtml .= '<tr id="transaction-' . $transactionId . '">
                <td>' . HelperFramework::escape($this->displayDate((string)($transaction['txn_date'] ?? ''))) . '</td>
                <td>
                    <div>' . HelperFramework::escape((string)($transaction['description'] ?? '')) . '</div>'
                    . $matchedRuleHtml . '
                </td>
                <td>
                    <div>' . HelperFramework::escape((string)($transaction['source_account'] ?? '')) . '</div>
                    <div class="helper">' . HelperFramework::escape($sourceCategory !== '' ? $sourceCategory : 'No source category') . '</div>
                </td>
                <td class="' . ((float)($transaction['amount'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative') . '">' . HelperFramework::escape(FormattingFramework::money((float)($transaction['amount'] ?? 0))) . '</td>
                <td>' . $documentHtml . '</td>
                <td>' . $categorisationHtml . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->transactionCategorisationStatusBadgeClass($transaction)) . '">' . HelperFramework::escape($this->transactionCategorisationStatusLabel($transaction)) . '</span></td>
                <td>' . $flagsHtml . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->transactionJournalStatusBadgeClass($transaction)) . '">' . HelperFramework::escape($this->transactionJournalStatusLabel($transaction)) . '</span></td>
                <td>
                    <form method="post" action="?page=assets" id="' . HelperFramework::escape($assetFormId) . '">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                        <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                    </form>
                    <form method="post" action="?page=transactions" id="' . HelperFramework::escape($transactionFormId) . '" data-ajax="true">
                        <input type="hidden" name="card_action" value="Transaction">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                        <input type="hidden" name="transaction_id" value="' . $transactionId . '">
                        <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                        <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                        <input type="hidden" name="confirm_rebuild_journal" value="0">
                        <div class="actions-row">
                            <button class="button primary js-transaction-action" type="submit" name="global_action" value="save_transaction_category" data-dirty-enable-mode="changed" disabled' . $journalRebuildAttributes . '>' . ($isTransferRow ? 'Save' : 'Manual') . '</button>'
                            . (!$isTransferRow
                                ? '<button class="button primary js-transaction-action" type="submit" name="global_action" value="auto_create_transaction_rule" data-dirty-enable-mode="selected" disabled' . $journalRebuildAttributes . '>Create Automatic Rule</button>'
                                : '') . '
                            <button class="button primary" type="submit" name="global_action" value="defer_transaction"' . $journalRebuildAttributes . '>Defer</button>
                            <button class="button" type="submit" form="' . HelperFramework::escape($assetFormId) . '" formnovalidate>Create Asset</button>
                        </div>
                    </form>
                </td>
            </tr>';
        }

        $tableHtml = $rowsHtml !== ''
            ? '<div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th>Amount</th>
                            <th>Document</th>
                            <th>Categorisation</th>
                            <th>Status</th>
                            <th>Flags</th>
                            <th>Journal</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>'
            : '<div class="helper">No imported transactions match the selected month and filter yet.</div>';

        return '
            <form class="toolbar" method="post" action="?page=transactions" data-ajax="true">
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="global_action" value="select_transaction_month">
                <input type="hidden" name="selection_source" value="transactions_imported_filters">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                <div class="mini-field">
                    <label for="transaction_month_key">Month</label>
                    <select class="select" id="transaction_month_key" name="month_key">' . $monthOptions . '</select>
                </div>
                <div class="mini-field">
                    <label for="transaction_category_filter">Category filter</label>
                    <select class="select" id="transaction_category_filter" name="category_filter">
                        <option value="all"' . ($selectedTransactionFilter === 'all' ? ' selected' : '') . '>All</option>
                        <option value="uncategorised"' . ($selectedTransactionFilter === 'uncategorised' ? ' selected' : '') . '>Uncategorised only</option>
                        <option value="auto"' . ($selectedTransactionFilter === 'auto' ? ' selected' : '') . '>Auto categorised</option>
                        <option value="manual"' . ($selectedTransactionFilter === 'manual' ? ' selected' : '') . '>Manual categorised</option>
                    </select>
                </div>
            </form>
            <div class="pill-row">
                <span class="pill">' . (int)$selectedMonthSummary['total'] . ' in month</span>
                <span class="pill">' . (int)$selectedMonthSummary['uncategorised'] . ' uncategorised</span>
                <span class="pill">' . (int)$selectedMonthSummary['ready_to_post'] . ' ready to post</span>
                <span class="pill">' . (int)$selectedMonthSummary['posted'] . ' posted</span>
                <span class="pill">' . (int)$selectedMonthSummary['deferred'] . ' deferred</span>
            </div>
            <div>
                <form method="post" action="?page=transactions" data-ajax="true">
                    <input type="hidden" name="card_action" value="Transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                    <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                    <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                    <input type="hidden" name="auto_scope" value="uncategorised">
                    <input type="hidden" name="global_action" value="run_auto_rules">
                    <button class="button" type="submit">Auto Apply</button>
                </form>
                <form method="post" action="?page=transactions" data-ajax="true">
                    <input type="hidden" name="card_action" value="Transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                    <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                    <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                    <input type="hidden" name="global_action" value="post_categorised_transactions">
                    <button class="button primary" type="submit">Post Categorised Transactions</button>
                </form>
            </div>'
            . $tableHtml . '
        ';
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
            'manual' => 'Manual categorised',
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
