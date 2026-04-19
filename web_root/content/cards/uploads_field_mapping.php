<?php
declare(strict_types=1);

final class _uploads_field_mappingCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'uploads_field_mapping';
    }

    public function services(): array
    {
        return [];
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
        $selectedUploadId = (int)($page['selected_upload_id'] ?? $page['upload_id'] ?? 0);
        $selectedUploadPreview = (array)($page['selected_upload_preview'] ?? []);
        $selectedUploadHasAccountMapping = !empty($page['selected_upload_has_account_mapping']);
        $selectedUploadMappingExtraHeaders = (array)($page['selected_upload_mapping_extra_headers'] ?? []);
        $selectedUploadMappingView = (array)($page['selected_upload_mapping_view'] ?? []);
        $selectedUploadHeaders = (array)($page['selected_upload_headers'] ?? []);

        $badgeHtml = $selectedUploadPreview !== []
            ? '<span class="badge ' . ($selectedUploadHasAccountMapping ? 'success' : HelperFramework::escape($this->uploadsImportWorkflowBadgeClass((string)($selectedUploadPreview['upload']['workflow_status'] ?? '')))) . '">'
                . ($selectedUploadHasAccountMapping ? 'Saved' : HelperFramework::escape($this->uploadsImportWorkflowLabel((string)($selectedUploadPreview['upload']['workflow_status'] ?? ''))))
                . '</span>'
            : '<span class="badge info">Select Upload</span>';

        if ($selectedUploadPreview === []) {
            $bodyHtml = '<div class="helper">Select an upload from the Review &amp; Commit Transactions tab to view or reuse its field mappings here.</div>';
        } elseif ($selectedUploadHasAccountMapping) {
            $bodyHtml = '<div class="helper">Saved field mappings already exist for this account. Manage them from Banking if you need to change them.</div>';
        } else {
            $sourceSample = is_array($selectedUploadPreview['source_sample'] ?? null) ? $selectedUploadPreview['source_sample'] : ['headers' => [], 'rows' => []];
            $sampleHtml = '';
            if (!empty($sourceSample['headers'])) {
                $headHtml = '';
                foreach ((array)($sourceSample['headers'] ?? []) as $header) {
                    $headHtml .= '<th>' . HelperFramework::escape((string)$header) . '</th>';
                }
                $rowHtml = '';
                foreach ((array)($sourceSample['rows'] ?? []) as $row) {
                    $rowHtml .= '<tr>';
                    foreach ((array)$row as $value) {
                        $rowHtml .= '<td>' . HelperFramework::escape((string)$value) . '</td>';
                    }
                    $rowHtml .= '</tr>';
                }
                if (($sourceSample['rows'] ?? []) === []) {
                    $rowHtml .= '<tr><td colspan="' . count((array)($sourceSample['headers'] ?? [])) . '">No example rows are available for this upload yet.</td></tr>';
                }
                $sampleHtml = '<div style="margin-bottom: 18px;">
                    <div class="helper" style="margin-bottom: 8px;">CSV headings and first two rows from the uploaded file:</div>
                    <div style="overflow-x: auto;">
                        <table><thead><tr>' . $headHtml . '</tr></thead><tbody>' . $rowHtml . '</tbody></table>
                    </div>
                </div>';
            }

            $mappingFieldsHtml = '';
            foreach (StatementUploadService::fieldDefinitions() as $fieldName => $definition) {
                $selectedHeader = '';
                if (is_array($selectedUploadMappingView[$fieldName] ?? null)) {
                    $selectedHeader = $fieldName === 'currency' && array_key_exists('default_value', $selectedUploadMappingView[$fieldName])
                        ? (string)StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP
                        : (string)($selectedUploadMappingView[$fieldName]['header'] ?? '');
                }

                $optionsHtml = '<option value="">Not mapped</option>';
                if ($fieldName === 'currency') {
                    $optionsHtml .= '<option value="' . HelperFramework::escape(StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP) . '"' . ($selectedHeader === StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP ? ' selected' : '') . '>£ GBP</option>';
                }
                foreach ($selectedUploadHeaders as $header) {
                    $optionsHtml .= '<option value="' . HelperFramework::escape((string)$header) . '"' . ((string)$header === $selectedHeader ? ' selected' : '') . '>' . HelperFramework::escape((string)$header) . '</option>';
                }

                $mappingFieldsHtml .= '<div class="form-row">
                    <label for="mapping_' . HelperFramework::escape($fieldName) . '">' . HelperFramework::escape((string)$definition['label']) . (!empty($definition['required']) ? ' *' : '') . '</label>
                    <select class="select" id="mapping_' . HelperFramework::escape($fieldName) . '" name="mapping_' . HelperFramework::escape($fieldName) . '">' . $optionsHtml . '</select>
                </div>';
            }

            $bodyHtml = '<div class="helper" style="margin-bottom: 14px;">
                    Selected upload: <strong>' . HelperFramework::escape((string)($selectedUploadPreview['upload']['original_filename'] ?? '')) . '</strong>.<br>
                    Field Mappings will be assigned to: <strong>' . HelperFramework::escape((string)($selectedUploadPreview['upload']['account_name'] ?? 'No account selected')) . '</strong>.'
                    . ($selectedUploadMappingExtraHeaders !== [] ? '<br>Extra columns found in this file: <strong>' . HelperFramework::escape(implode(', ', array_map(static fn($header): string => (string)$header, $selectedUploadMappingExtraHeaders))) . '</strong>.' : '') . '
                    <br>Amount and Description should be mapped, and at least one usable date field is required.
                </div>'
                . $sampleHtml . '
                <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('uploads', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId, 'upload_id' => $selectedUploadId])) . '" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                    <input type="hidden" name="upload_id" value="' . $selectedUploadId . '">
                    <input type="hidden" name="account_id" value="' . (int)($selectedUploadPreview['upload']['account_id'] ?? 0) . '">
                    <input type="hidden" name="global_action" value="save_anna_mapping">
                    <div class="form-grid">' . $mappingFieldsHtml . '</div>
                    <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;"><button class="button" type="submit">Save Mapping</button></div>
                </form>
                <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('uploads', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId, 'upload_id' => $selectedUploadId])) . '" style="margin-top: 12px;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                    <input type="hidden" name="upload_id" value="' . $selectedUploadId . '">
                    <input type="hidden" name="account_id" value="' . (int)($selectedUploadPreview['upload']['account_id'] ?? 0) . '">
                    <input type="hidden" name="global_action" value="stage_anna_upload">
                    <button class="button primary" type="submit">Preview And Validate Rows</button>
                </form>';
        }

        return '<section class="eel-card-fragment" data-card="uploads-field-mapping">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Field Mappings</h2>
                    ' . $badgeHtml . '
                </div>
                <div class="card-body">' . $bodyHtml . '</div>
            </div>
        </section>';
    }

    private function uploadsImportWorkflowBadgeClass(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'staged', 'mapped' => 'info',
            'uploaded' => 'warning',
            default => 'muted',
        };
    }

    private function uploadsImportWorkflowLabel(string $status): string
    {
        $status = trim($status);
        return $status !== '' ? HelperFramework::labelFromKey($status, '_') : 'Unknown';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
