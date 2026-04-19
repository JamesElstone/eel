<?php
declare(strict_types=1);

final class _banking_field_mappingsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'banking_field_mappings';
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
            [
                'key' => 'mappingPreview',
                'service' => StatementUploadService::class,
                'method' => 'fetchAccountMappingPreview',
                'params' => [
                    'companyId' => ':company_id',
                    'accountId' => ':mapping_account_id',
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
        $bankingMappingAccountId = (int)($page['mapping_account_id'] ?? 0);
        $activeCompanyAccounts = (array)($context['services']['activeCompanyAccounts'] ?? []);
        $bankingMappingPreview = (array)($context['services']['mappingPreview'] ?? []);

        if ($bankingMappingAccountId <= 0) {
            return '<section hidden aria-hidden="true"></section>';
        }

        if ($bankingMappingPreview === []) {
            return '<div class="card">
                <div class="card-header">
                    <h2 class="card-title">Field Mappings</h2>
                </div>
                <div class="card-body">
                    <div class="helper">Upload a CSV for this account first, then use this card to manage its saved field mapping.</div>
                </div>
            </div>';
        }

        $bankingUploadHeaders = $this->decodeJsonArrayValue($bankingMappingPreview['upload']['source_headers_json'] ?? '[]');
        $bankingMappingView = isset($bankingMappingPreview['mapping']['mapping_json'])
            ? $this->decodeJsonArrayValue((string)$bankingMappingPreview['mapping']['mapping_json'])
            : [];
        $bankingSourceSample = is_array($bankingMappingPreview['source_sample'] ?? null) ? $bankingMappingPreview['source_sample'] : ['headers' => [], 'rows' => []];

        $accountOptions = '<option value="">Select account</option>';
        foreach ($activeCompanyAccounts as $account) {
            $selected = (int)($bankingMappingPreview['upload']['account_id'] ?? 0) === (int)($account['id'] ?? 0) ? ' selected' : '';
            $accountOptions .= '<option value="' . (int)($account['id'] ?? 0) . '"' . $selected . '>'
                . HelperFramework::escape((string)($account['account_name'] ?? '')) . ' ('
                . HelperFramework::escape(CompanyAccountService::accountTypes()[(string)($account['account_type'] ?? '')] ?? ucfirst((string)($account['account_type'] ?? '')))
                . ')</option>';
        }

        $mappingFieldsHtml = '';
        foreach (StatementUploadService::fieldDefinitions() as $fieldName => $definition) {
            $selectedHeader = '';
            if (is_array($bankingMappingView[$fieldName] ?? null)) {
                if ($fieldName === 'currency' && array_key_exists('default_value', $bankingMappingView[$fieldName])) {
                    $selectedHeader = (string)StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP;
                } else {
                    $selectedHeader = (string)($bankingMappingView[$fieldName]['header'] ?? '');
                }
            }

            $optionsHtml = '<option value="">Not mapped</option>';
            if ($fieldName === 'currency') {
                $optionsHtml .= '<option value="' . HelperFramework::escape(StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP) . '"' . ($selectedHeader === StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP ? ' selected' : '') . '>GBP</option>';
            }
            foreach ($bankingUploadHeaders as $header) {
                $optionsHtml .= '<option value="' . HelperFramework::escape((string)$header) . '"' . ((string)$header === $selectedHeader ? ' selected' : '') . '>' . HelperFramework::escape((string)$header) . '</option>';
            }

            $mappingFieldsHtml .= '<div class="form-row">
                <label for="banking_mapping_' . HelperFramework::escape($fieldName) . '">' . HelperFramework::escape((string)($definition['label'] ?? '')) . (!empty($definition['required']) ? ' *' : '') . '</label>
                <select class="select" id="banking_mapping_' . HelperFramework::escape($fieldName) . '" name="mapping_' . HelperFramework::escape($fieldName) . '">' . $optionsHtml . '</select>
            </div>';
        }

        $sampleHtml = '';
        if (!empty($bankingSourceSample['headers'])) {
            $thead = '';
            foreach ((array)$bankingSourceSample['headers'] as $header) {
                $thead .= '<th>' . HelperFramework::escape((string)$header) . '</th>';
            }
            $tbody = '';
            foreach ((array)$bankingSourceSample['rows'] as $row) {
                $tbody .= '<tr>';
                foreach ((array)$row as $value) {
                    $tbody .= '<td>' . HelperFramework::escape((string)$value) . '</td>';
                }
                $tbody .= '</tr>';
            }
            if (((array)$bankingSourceSample['rows']) === []) {
                $tbody .= '<tr><td colspan="' . count((array)$bankingSourceSample['headers']) . '">No example rows are available for this upload yet.</td></tr>';
            }

            $sampleHtml = '<div style="margin-bottom: 18px;">
                <div class="helper" style="margin-bottom: 8px;">CSV headings and first two rows from the uploaded file:</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead><tr>' . $thead . '</tr></thead>
                        <tbody>' . $tbody . '</tbody>
                    </table>
                </div>
            </div>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Field Mappings</h2>
            </div>
            <div class="card-body">
                <div class="helper" style="margin-bottom: 14px;">
                    Selected upload: <strong>' . HelperFramework::escape((string)($bankingMappingPreview['upload']['original_filename'] ?? '')) . '</strong>.
                    <br>
                    Field Mappings will be assigned to: <strong>' . HelperFramework::escape((string)($bankingMappingPreview['upload']['account_name'] ?? 'No account selected')) . '</strong>.
                    <br>
                    Amount and Description should be mapped, and at least one usable date field is required.
                </div>
                ' . $sampleHtml . '
                <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('banking', [
                    'company_id' => $selectedCompanyId,
                    'tax_year_id' => $selectedTaxYearId,
                    'mapping_account_id' => $bankingMappingAccountId,
                    'upload_id' => (int)($bankingMappingPreview['upload']['id'] ?? 0),
                ])) . '" data-ajax-card-form="true" data-ajax-card-update="banking-field-mappings">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                    <input type="hidden" name="upload_id" value="' . (int)($bankingMappingPreview['upload']['id'] ?? 0) . '">
                    <input type="hidden" name="mapping_account_id" value="' . $bankingMappingAccountId . '">
                    <input type="hidden" name="global_action" value="save_anna_mapping">
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="banking_mapping_account_id">Upload account</label>
                            <select class="select" id="banking_mapping_account_id" name="account_id" required>' . $accountOptions . '</select>
                        </div>
                        ' . $mappingFieldsHtml . '
                    </div>
                    <div style="margin-top: 16px;">
                        <button class="button" type="submit">Save Mapping</button>
                    </div>
                </form>
            </div>
        </div>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        $query = http_build_query(['page' => $page] + $params);
        return '?' . $query;
    }

    private function decodeJsonArrayValue(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
