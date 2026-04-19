<?php
declare(strict_types=1);

final class _uploads_bank_transactionsCard implements CardInterfaceFramework
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
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company_id',
                    'activeOnly' => true,
                ],
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $selectedUploadHistoryFilter = (string)($page['selected_upload_history_filter'] ?? $page['upload_history_filter'] ?? 'all');
        $selectedUploadHistoryPage = (int)($page['selected_upload_history_page'] ?? $page['upload_history_page'] ?? 1);
        $uploadsAutoSwitchTab = (string)($page['uploads_auto_switch_tab'] ?? '');
        $selectedUploadPreview = (array)($page['selected_upload_preview'] ?? []);
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
                . HelperFramework::escape(CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType))
                . ')</option>';
        }

        return '<section class="eel-card-fragment" data-card="uploads-bank-upload">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Import Bank Statements in CSV format</h2>
                </div>
                <div class="card-body">'
                    . ($uploadsAutoSwitchTab !== '' ? '<div hidden data-uploads-next-tab="' . HelperFramework::escape($uploadsAutoSwitchTab) . '"></div>' : '') . '
                    <form method="post" enctype="multipart/form-data" action="' . HelperFramework::escape($this->buildPageUrl('uploads', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId])) . '" data-ajax-card-form="true" data-ajax-card-update="uploads-bank-upload,uploads-details,uploads-field-mapping,uploads-validate">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                        <input type="hidden" name="upload_history_filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                        <input type="hidden" name="upload_history_page" value="' . $selectedUploadHistoryPage . '">
                        <input type="hidden" name="global_action" value="upload_anna_csv">
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="upload_account_id">Upload for account</label>
                                <select class="select" id="upload_account_id" name="account_id" required>
                                    <option value="">Select account</option>' . $accountOptions . '
                                </select>
                            </div>
                            <div class="form-row full">
                                <label for="statement_file">CSV files</label>
                                <input class="input" id="statement_file" type="file" name="statement_files[]" accept=".csv,text/csv" multiple required data-upload-input>
                                <div class="upload-box upload-dropzone" data-upload-dropzone data-upload-max-files="' . StatementUploadService::MAX_BATCH_UPLOAD_FILES . '">
                                    <h3>Drop CSV files here</h3>
                                    <div class="helper" data-upload-selection-summary>No files selected yet.</div>
                                    <ul class="upload-file-list" data-upload-file-list hidden></ul>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 16px;">
                            <button class="button primary" type="submit"' . ($activeCompanyAccounts === [] ? ' disabled' : '') . '>Upload CSV</button>
                        </div>'
                        . ($activeCompanyAccounts === []
                            ? '<div class="helper" style="margin-top: 12px;">Add a bank or trade account in Banking before uploading a CSV.</div>'
                            : '') . '
                    </form>
                </div>
            </div>
        </section>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
