<?php
declare(strict_types=1);

final class _uploads_detailsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'uploads_details';
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
        $developerOptions = !empty($page['developer_options']);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $selectedUploadId = (int)($page['selected_upload_id'] ?? $page['upload_id'] ?? 0);
        $selectedUploadHistoryFilter = (string)($page['selected_upload_history_filter'] ?? $page['upload_history_filter'] ?? 'all');
        $selectedUploadHistoryPage = (int)($page['selected_upload_history_page'] ?? $page['upload_history_page'] ?? 1);
        $settings = (array)($page['settings'] ?? []);
        $dateFormat = (string)($settings['date_format'] ?? '');
        $uploadHistory = (array)($page['upload_history'] ?? []);
        $uploadHistoryTotal = (int)($page['upload_history_total'] ?? count($uploadHistory));
        $uploadHistoryPageSize = (int)($page['upload_history_page_size'] ?? 25);
        $uploadHistoryHasPreviousPage = !empty($page['upload_history_has_previous_page']);
        $uploadHistoryHasNextPage = !empty($page['upload_history_has_next_page']);

        $filterOptionsHtml = '';
        foreach ($this->uploadsHistoryFilterOptions() as $filterValue => $filterLabel) {
            $filterOptionsHtml .= '<option value="' . HelperFramework::escape($filterValue) . '"' . ($selectedUploadHistoryFilter === $filterValue ? ' selected' : '') . '>' . HelperFramework::escape($filterLabel) . '</option>';
        }

        if ($uploadHistory === []) {
            $historyHtml = '<div class="helper">No statement uploads match this filter for the selected company.</div>';
        } else {
            $rowsHtml = '';
            foreach ($uploadHistory as $upload) {
                if (!is_array($upload)) {
                    continue;
                }

                $mappingStatus = is_array($upload['mapping_status'] ?? null) ? $upload['mapping_status'] : [];
                $uploadExtraHeaders = is_array($mappingStatus['extra_headers'] ?? null) ? $mappingStatus['extra_headers'] : [];
                $accountType = (string)($upload['account_type'] ?? '');

                $previewActions = '<a class="button primary" href="' . HelperFramework::escape($this->buildPageUrl('uploads', [
                    'company_id' => $selectedCompanyId,
                    'tax_year_id' => $selectedTaxYearId,
                    'upload_id' => (int)($upload['id'] ?? 0),
                    'upload_history_filter' => $selectedUploadHistoryFilter,
                    'upload_history_page' => $selectedUploadHistoryPage,
                ])) . '" data-ajax-card-link="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">Preview</a>';

                if ($developerOptions) {
                    $previewActions .= '<form method="post" action="' . HelperFramework::escape($this->buildPageUrl('uploads', [
                        'company_id' => $selectedCompanyId,
                        'tax_year_id' => $selectedTaxYearId,
                        'upload_id' => (int)($upload['id'] ?? 0),
                        'upload_history_filter' => $selectedUploadHistoryFilter,
                        'upload_history_page' => $selectedUploadHistoryPage,
                    ])) . '" style="display: inline;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                        <input type="hidden" name="upload_id" value="' . (int)($upload['id'] ?? 0) . '">
                        <input type="hidden" name="global_action" value="rescan_anna_upload">
                        <button class="button danger" type="submit">Rescan</button>
                    </form>';
                }

                $rowsHtml .= '<tr>
                    <td>' . HelperFramework::escape($this->displayDateTime((string)($upload['uploaded_at'] ?? ''), $selectedCompanyId, $dateFormat, 'H:i')) . '</td>
                    <td>
                        <div>' . HelperFramework::escape((string)($upload['filename'] ?? '')) . '</div>
                        <div class="helper">' . HelperFramework::escape((string)($upload['month'] ?? '')) . '</div>'
                        . ($uploadExtraHeaders !== []
                            ? '<div class="helper">Needs mapping: ' . HelperFramework::escape(implode(', ', array_map(static fn($header): string => (string)$header, $uploadExtraHeaders))) . '</div>'
                            : '') . '
                    </td>
                    <td>
                        <div>' . HelperFramework::escape((string)($upload['account_name'] ?? '') !== '' ? (string)$upload['account_name'] : 'No account selected') . '</div>'
                        . ($accountType !== '' ? '<div class="helper">' . HelperFramework::escape(CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType)) . '</div>' : '') . '
                    </td>
                    <td><span class="badge ' . HelperFramework::escape($this->uploadsImportWorkflowBadgeClass((string)($upload['workflow_status'] ?? ''))) . '">' . HelperFramework::escape($this->uploadsImportWorkflowLabel((string)($upload['workflow_status'] ?? ''))) . '</span></td>
                    <td>' . (int)($upload['inserted'] ?? 0) . ' committed / ' . (int)($upload['rows_ready_to_import'] ?? 0) . ' ready</td>
                    <td><div style="display: flex; gap: 8px; flex-wrap: wrap;">' . $previewActions . '</div></td>
                </tr>';
            }

            $uploadRangeStart = $uploadHistoryTotal > 0
                ? ((max(1, $selectedUploadHistoryPage) - 1) * $uploadHistoryPageSize) + 1
                : 0;
            $uploadRangeEnd = $uploadHistoryTotal > 0
                ? min($uploadHistoryTotal, $uploadRangeStart + count($uploadHistory) - 1)
                : 0;

            $historyHtml = '<table>
                <thead>
                    <tr>
                        <th>Uploaded</th>
                        <th>File</th>
                        <th>Mapping Account</th>
                        <th>Status</th>
                        <th>Rows</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px;">
                <div class="helper">Displaying Uploads ' . $uploadRangeStart . ' to ' . $uploadRangeEnd . ' (' . $uploadHistoryTotal . ' total)</div>
                <div style="display: flex; gap: 8px;">
                    <a class="button uploads-pager-button' . (!$uploadHistoryHasPreviousPage ? ' disabled' : '') . '"' . ($uploadHistoryHasPreviousPage
                        ? ' href="' . HelperFramework::escape($this->buildPageUrl('uploads', [
                            'company_id' => $selectedCompanyId,
                            'tax_year_id' => $selectedTaxYearId,
                            'upload_id' => $selectedUploadId,
                            'upload_history_filter' => $selectedUploadHistoryFilter,
                            'upload_history_page' => max(1, $selectedUploadHistoryPage - 1),
                        ])) . '" data-ajax-card-link="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate"'
                        : ' aria-disabled="true"') . '>&lt; Prev</a>
                    <a class="button uploads-pager-button' . (!$uploadHistoryHasNextPage ? ' disabled' : '') . '"' . ($uploadHistoryHasNextPage
                        ? ' href="' . HelperFramework::escape($this->buildPageUrl('uploads', [
                            'company_id' => $selectedCompanyId,
                            'tax_year_id' => $selectedTaxYearId,
                            'upload_id' => $selectedUploadId,
                            'upload_history_filter' => $selectedUploadHistoryFilter,
                            'upload_history_page' => $selectedUploadHistoryPage + 1,
                        ])) . '" data-ajax-card-link="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate"'
                        : ' aria-disabled="true"') . '>Next &gt;</a>
                </div>
            </div>';
        }

        return '<section class="eel-card-fragment" data-card="uploads-details">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Upload Details</h2>
                    <div style="display: flex; align-items: center; gap: 8px; margin-left: auto; flex-wrap: wrap;">'
                        . ($developerOptions ? $this->developerButtons($selectedCompanyId, $selectedTaxYearId, $selectedUploadId, $selectedUploadHistoryFilter, $selectedUploadHistoryPage) : '') . '
                        <form method="get" action="" style="display: flex; align-items: center; gap: 8px;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">
                            <input type="hidden" name="page" value="uploads">
                            <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">'
                            . ($selectedUploadId > 0 ? '<input type="hidden" name="upload_id" value="' . $selectedUploadId . '">' : '') . '
                            <input type="hidden" name="upload_history_page" value="1">
                            <label for="upload_history_filter" class="helper" style="margin: 0;">Show</label>
                            <select class="select" id="upload_history_filter" name="upload_history_filter" data-ajax-card-autosubmit="true">' . $filterOptionsHtml . '</select>
                        </form>
                    </div>
                </div>
                <div class="card-body">' . $historyHtml . '</div>
            </div>
        </section>';
    }

    private function developerButtons(int $companyId, int $taxYearId, int $uploadId, string $filter, int $page): string
    {
        $baseUrl = $this->buildPageUrl('uploads', [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'upload_id' => $uploadId,
            'upload_history_filter' => $filter,
            'upload_history_page' => $page,
        ]);

        return '<form method="post" action="' . HelperFramework::escape($baseUrl) . '" style="display: inline;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                <input type="hidden" name="upload_id" value="' . $uploadId . '">
                <input type="hidden" name="upload_history_filter" value="' . HelperFramework::escape($filter) . '">
                <input type="hidden" name="upload_history_page" value="' . $page . '">
                <input type="hidden" name="global_action" value="recalculate_upload_checksums">
                <button class="button danger" type="submit">Recalculate Checksums</button>
            </form>
            <form method="post" action="' . HelperFramework::escape($baseUrl) . '" style="display: inline;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                <input type="hidden" name="upload_id" value="' . $uploadId . '">
                <input type="hidden" name="upload_history_filter" value="' . HelperFramework::escape($filter) . '">
                <input type="hidden" name="upload_history_page" value="' . $page . '">
                <input type="hidden" name="global_action" value="backfill_transaction_types_from_staged_json">
                <button class="button danger" type="submit">Backfill transaction types from staged import JSON</button>
            </form>';
    }

    private function uploadsHistoryFilterOptions(): array
    {
        return [
            'all' => 'All uploads',
            'action_required' => 'Action required',
            'ready' => 'Ready to import',
            'imported' => 'Imported',
        ];
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

    private function displayDateTime(string $value, int $companyId, string $dateFormat, string $timeFormat): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDateTime($value, $companyId, $dateFormat, $timeFormat);
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
