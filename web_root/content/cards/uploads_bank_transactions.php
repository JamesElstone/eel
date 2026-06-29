<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads_bank_transactionsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'uploads_bank_transactions';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'activeCompanyAccounts',
                'service' => \eel_accounts\Service\CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => true,
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function helper(array $context) : string|array {
        if ((array)($context['services']['activeCompanyAccounts'] ?? []) === []) {
            return HelperFramework::rawHtml('<span class="warn">Add a bank or trade account in Banking before uploading a CSV.</span>');
        }

        return HelperFramework::rawHtml('Browse or drag &amp; drop a <strong>CSV</strong> file to upload.');
    }

    public function title() : string {
        return 'Upload Bank Statements (CSVs)';
    }

    public function render(array $context): string
    {

        if ((array)($context['services']['activeCompanyAccounts'] ?? []) === []) {
            return '<div class="helper">Add a company, and a bank account before uploading files.';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        
        $selectedUploadHistoryFilter = (string)($context['uploads']['filter'] ?? 'all');
        $selectedUploadHistoryPage = (int)($context['uploads']['page'] ?? 1);
        
        $uploadsAutoSwitchTab = (string)($context['uploads']['uploads_auto_switch_tab'] ?? '');
        $selectedUploadPreview = (array)($context['uploads']['selected_upload_preview'] ?? []);

        $activeCompanyAccounts = (array)($context['services']['activeCompanyAccounts'] ?? []);

        $accountOptions = '';
        foreach ($activeCompanyAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountType = (string)($account['account_type'] ?? '');
            $selected = (int)($selectedUploadPreview['upload']['account_id'] ?? 0) === (int)($account['id'] ?? 0) ? ' selected' : '';
            $accountOptions .= '<option value="' . (int)($account['id'] ?? 0) . '"' . $selected . '>'
                . HelperFramework::escape((string)($account['account_name'] ?? '')) . ' ('
                . HelperFramework::escape(\eel_accounts\Service\CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType))
                . ')</option>';
        }

        return 
            ($uploadsAutoSwitchTab !== '' ? '<div hidden data-uploads-next-tab="' . HelperFramework::escape($uploadsAutoSwitchTab) . '"></div>' : '') 
            . '
            <form method="post" enctype="multipart/form-data" action="?page=uploads">
                <input type="hidden" name="card_action" value="Uploads">
                <input type="hidden" name="intent" value="upload_account_csv">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                <input type="hidden" name="page" value="' . $selectedUploadHistoryPage . '">
                <div class="stack">
                    <p class="helper">The first row must be headings, ideally clear names like <strong>date, description, amount, balance</strong>.<br>Transactions must be in statement order, either oldest first or newest first, so balances can be checked before import.</p>
                    <div class="upload-box upload-dropzone" data-upload-dropzone data-upload-max-files="' . \eel_accounts\Service\StatementUploadService::MAX_BATCH_UPLOAD_FILES . '">
                        <div class="flex-controls">
                            <div class="form-row">
                                <label for="statement_file">Drop CSV files here</label>
                                <input class="input" id="statement_file" type="file" name="statement_files[]" accept=".csv,text/csv" multiple required data-upload-input>
                            </div>
                            <div class="form-row">
                                <label for="upload_account_id">Select account this upload is for:</label>
                                <select class="select" id="upload_account_id" name="account_id" required>
                                    <option value="">Select account</option>' . $accountOptions . '
                                </select>
                            </div>
                            <div class="form-row">
                                <label data-upload-selection-summary></label>
                                <ul class="file-list" data-upload-file-list hidden></ul>
                            </div>
                            <div class="form-row">
                                <button class="button primary" type="submit" title="Select both the account and files for upload." disabled data-upload-submit>Upload CSV</button>
                                <img class="upload-processing-icon is-hidden" src="svg/loader.svg" alt="" aria-hidden="true" data-upload-processing-icon>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        ';
    }
}
