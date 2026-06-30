<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads_detailsCard extends CardBaseFramework
{
    private const HISTORY_PAGE_SIZE = 5;
    private const DEFAULT_UPLOAD_HISTORY_FILTER = 'ready';

    public function key(): string
    {
        return 'uploads_details';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'filter_terms',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'uploadsHistoryFilterOptions',
                'params' => [],
            ],
            [
                'key' => 'upload_history',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'filterUploadHistory',
                'params' => [
                    'filter' => ':uploads.filter',
                ],
            ],
            [
                'key' => 'upload_summary_by_accounting_period',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'fetchUploadSummaryByAccountingPeriod',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'This is a list of files already uploaded.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $uploadsContext = is_array($pageContext['uploads'] ?? null) ? $pageContext['uploads'] : [];

        if (trim((string)($uploadsContext['filter'] ?? '')) === '') {
            $uploadsContext['filter'] = self::DEFAULT_UPLOAD_HISTORY_FILTER;
        }

        $pageContext['uploads'] = $uploadsContext;

        return $pageContext;
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function title(): string
    {
        return 'Previously Uploaded Files';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {

        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '';
        }

        $uploadHistory                  = (array)  ($context['services']['upload_history'] ?? []);

        $selectedUploadHistoryFilter    = (string) ($context['uploads']['filter'] ?? self::DEFAULT_UPLOAD_HISTORY_FILTER);
        $selectedUploadId               = (int)    ($context['uploads']['id'] ?? '');
        $selectedUploadHistoryPage      = (int)    ($context['uploads']['page'] ?? 1);

        $pagination = HelperFramework::paginateArray(
            $uploadHistory,
            $selectedUploadHistoryPage,
            self::HISTORY_PAGE_SIZE
        );

        $uploadHistory = $pagination['items'];
        $selectedUploadHistoryPage = (int)$pagination['page'];
        $uploadHistoryTotal = (int)$pagination['total'];
        $uploadHistoryPageSize = (int)$pagination['page_size'];
        $uploadHistoryHasPreviousPage = (bool)$pagination['has_previous_page'];
        $uploadHistoryHasNextPage = (bool)$pagination['has_next_page'];

        $developerOptions = (bool)(AppConfigurationStore::get('developer_options', false));

        $filterOptionsHtml = '';
        $filterTerms = (array)($context['services']['filter_terms'] ?? []);
        $uploadSummaryHtml = $this->uploadSummaryTable((array)($context['services']['upload_summary_by_accounting_period'] ?? []));
        
        foreach ($filterTerms as $filterValue => $filterLabel) {
            $filterOptionsHtml .= '<option value="' . HelperFramework::escape($filterValue) . '"' . ($selectedUploadHistoryFilter === $filterValue ? ' selected' : '') . '>' . HelperFramework::escape($filterLabel) . '</option>';
        }

        if ($uploadHistory === []) {
            $historyHtml = '<div class="helper">No statement uploads match the filter for the selected company.</div>';
        } else {
            $rowsHtml = '';
            foreach ($uploadHistory as $upload) {
                if (!is_array($upload)) {
                    continue;
                }

                $mappingStatus = is_array($upload['mapping_status'] ?? null) ? $upload['mapping_status'] : [];
                $uploadExtraHeaders = is_array($mappingStatus['extra_headers'] ?? null) ? $mappingStatus['extra_headers'] : [];
                $accountType = (string)($upload['account_type'] ?? '');
                $hasParsedRows = (int)($upload['rows_parsed'] ?? 0) > 0;
                $isDuplicateFile = !empty($upload['duplicate_file']);
                $canPreviewAndValidate = $hasParsedRows && !$isDuplicateFile && !empty($mappingStatus['can_preview']);
                $statusLabel = $this->uploadWorkflowStatusLabel($upload, $mappingStatus);
                $statusClass = $this->uploadWorkflowStatusClass($upload, $mappingStatus);

                $previewActions = !$hasParsedRows
                    ? '<span class="helper">No rows to preview.</span>'
                    : ($isDuplicateFile
                        ? '<span class="helper">Duplicate file already uploaded.</span>'
                        : '<form method="post" action="?page=uploads" data-ajax="true">
                            <input type="hidden" name="card_action" value="Uploads">
                            <input type="hidden" name="intent" value="preview_upload">
                            <input type="hidden" name="upload_id" value="' . (int)($upload['id'] ?? 0) . '">
                            <input type="hidden" name="filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                            <input type="hidden" name="page" value="' . $selectedUploadHistoryPage . '">
                            <button class="button" type="submit" data-show-card="statement_field_mapping" data-page-card-switch-tab="Review Uploads">Field Mappings</button>
                        </form>
                        <form method="post" action="?page=uploads" data-ajax="true">
                            <input type="hidden" name="card_action" value="Uploads">
                            <input type="hidden" name="intent" value="stage_account_upload">
                            <input type="hidden" name="upload_id" value="' . (int)($upload['id'] ?? 0) . '">
                            <input type="hidden" name="filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                            <input type="hidden" name="page" value="' . $selectedUploadHistoryPage . '">
                            <button class="button primary" type="submit" data-show-card="uploads_validate_commit" data-processing-text="Preparing import..." data-processing-state="disabled"' . ($canPreviewAndValidate ? '' : ' disabled title="Save field mappings before previewing and validating rows."') . '>Import Transactions</button>
                        </form>');

                if ($developerOptions) {
                    $previewActions .= '<form method="post" action="?page=uploads" data-ajax="true">
                        <input type="hidden" name="card_action" value="Uploads">
                        <input type="hidden" name="intent" value="rescan_account_upload">
                        <input type="hidden" name="upload_id" value="' . (int)($upload['id'] ?? 0) . '">
                        <input type="hidden" name="filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                        <input type="hidden" name="page" value="' . $selectedUploadHistoryPage . '">
                        <button class="button danger" type="submit">Rescan</button>
                    </form>';
                }

                $rowsHtml .= '<tr>
                    <td>' . HelperFramework::escape($this->displayDateTime((string)($upload['uploaded_at'] ?? ''), 'H:i')) . '</td>
                    <td>
                        <div>' . HelperFramework::escape((string)($upload['filename'] ?? '')) . '</div>
                        <div class="helper">' . HelperFramework::escape((string)($upload['month'] ?? '')) . '</div>'
                        . ($uploadExtraHeaders !== []
                            ? '<div class="helper">Needs mapping: ' . HelperFramework::escape(implode(', ', array_map(static fn($header): string => (string)$header, $uploadExtraHeaders))) . '</div>'
                            : '') . '
                    </td>
                    <td>
                        <div>' . HelperFramework::escape((string)($upload['account_name'] ?? '') !== '' ? (string)$upload['account_name'] : 'No account selected') . '</div>'
                        . ($accountType !== '' ? '<div class="helper">' . HelperFramework::escape(\eel_accounts\Service\CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType)) . '</div>' : '') . '
                    </td>
                    <td><span class="badge ' . HelperFramework::escape($statusClass) . '">' . HelperFramework::escape($statusLabel) . '</span></td>
                    <td>' . HelperFramework::escape($this->uploadRowsLabel($upload)) . '</td>
                    <td><div class="actions-row">' . $previewActions . '</div></td>
                </tr>';
            }

            $historyHtml = '<div class="stack">
                <table>
                    <thead>
                        <tr>
                            <th>Time Uploaded</th>
                            <th>Filename</th>
                            <th>Mapping Account</th>
                            <th>Status</th>
                            <th>Rows</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
                <div class="status-head">
                    <div class="helper">' . HelperFramework::escape(HelperFramework::paginationItemsLabel($pagination, 'Uploads')) . '</div>
                    <div class="actions-row">
                        ' . $this->pagerForm('Prev', max(1, $selectedUploadHistoryPage - 1), $uploadHistoryHasPreviousPage, $selectedUploadId, $selectedUploadHistoryFilter) . '
                        ' . $this->pagerForm('Next', $selectedUploadHistoryPage + 1, $uploadHistoryHasNextPage, $selectedUploadId, $selectedUploadHistoryFilter) . '
                    </div>
                </div>
            </div>';
        }

        return ($developerOptions ? $this->developerButtons(0, '', $selectedUploadHistoryPage) : '') 
        . '
            <div class="stack">
                <div class="flex-controls">
                    <form method="post" action="?page=uploads" class="mini-form" data-ajax="true">
                        <input type="hidden" name="card_action" value="Uploads">
                        <input type="hidden" name="intent" value="filter_uploads">
                        ' . ($selectedUploadId > 0 ? '<input type="hidden" name="upload_id" value="' . $selectedUploadId . '">' : '') . '
                        <input type="hidden" name="page" value="1">
                        <div class="mini-field">
                            <label for="filter" class="helper">Filtered by:</label>
                            <select class="select" id="filter" name="filter">' . $filterOptionsHtml . '</select>
                        </div>
                    ' . $uploadSummaryHtml . '
                    </form>
                </div>
                <div class="panel-soft">' . $historyHtml . '</div>
            </div>
        ';
    }

    private function uploadSummaryTable(array $summary): string
    {
        if ($summary === []) {
            return '';
        }

        $periodCells = '';
        $uploadCells = '';
        $outstandingUploadCells = '';

        foreach ($summary as $period) {
            if (!is_array($period)) {
                continue;
            }

            $periodCells .= '<th scope="col">' . $this->accountingPeriodSummaryButton($period) . '</th>';
            $uploadCells .= '<td>' . (int)($period['upload_count'] ?? 0) . ' CSV (' . (int)($period['row_count'] ?? 0) . ' rows)</td>';
            $outstandingUploadCells .= '<td>' . (int)($period['outstanding_upload_count'] ?? 0) . ' CSV</td>';
        }

        if ($periodCells === '') {
            return '';
        }

        return '<div class="uploads-period-summary table-scroll-mini">
            <table>
                <tbody>
                    <tr>
                        <th scope="row">Accounting Period</th>
                        ' . $periodCells . '
                    </tr>
                    <tr>
                        <th scope="row">Uploads</th>
                        ' . $uploadCells . '
                    </tr>
                    <tr>
                        <th scope="row">Outstanding CSVs</th>
                        ' . $outstandingUploadCells . '
                    </tr>
                </tbody>
            </table>
        </div>';
    }

    private function accountingPeriodSummaryButton(array $period): string
    {
        $label = HelperFramework::escape((string)($period['label'] ?? ''));
        $accountingPeriodId = (int)($period['accounting_period_id'] ?? 0);

        if ($accountingPeriodId <= 0) {
            return $label;
        }

        return '<button class="uploads-period-button" type="button" data-accounting-period-summary-button="true" data-accounting-period-id="' . $accountingPeriodId . '" aria-label="Switch to accounting period ' . $label . '">' . $label . '</button>';
    }

    private function uploadRowsLabel(array $upload): string
    {
        $totalRows = (int)($upload['rows_parsed'] ?? 0);
        $readyRows = (int)($upload['rows_ready_to_import'] ?? 0);
        $committedRows = (int)($upload['inserted'] ?? 0);

        return sprintf('%d (%d ready, %d committed)', $totalRows, $readyRows, $committedRows);
    }

    private function developerButtons(int $uploadId, string $filter, int $page): string
    {
        return '<div class="actions-row">
                <form method="post" action="?page=uploads" data-ajax="true">
                    <input type="hidden" name="card_action" value="Uploads">
                    <input type="hidden" name="intent" value="recalculate_upload_checksums">
                    <input type="hidden" name="upload_id" value="' . $uploadId . '">
                    <input type="hidden" name="filter" value="' . HelperFramework::escape($filter) . '">
                    <input type="hidden" name="page" value="' . $page . '">
                    <button class="button danger" type="submit">Recalculate Checksums</button>
                </form>
                <form method="post" action="?page=uploads" data-ajax="true">
                    <input type="hidden" name="card_action" value="Uploads">
                    <input type="hidden" name="intent" value="backfill_transaction_types_from_staged_json">
                    <input type="hidden" name="upload_id" value="' . $uploadId . '">
                    <input type="hidden" name="filter" value="' . HelperFramework::escape($filter) . '">
                    <input type="hidden" name="page" value="' . $page . '">
                    <button class="button danger" type="submit">Backfill mappings from original JSON</button>
                </form>
            </div>';
    }

    private function pagerForm(string $label, int $page, bool $enabled, int $uploadId, string $filter): string
    {
        $fields = [
            'card_action' => 'Uploads',
            'filter' => $filter,
            'intent' => 'filter_uploads',
        ];

        if ($uploadId > 0) {
            $fields['upload_id'] = $uploadId;
        }

        return HelperFramework::paginationFormButton(
            $label,
            $page,
            $enabled,
            'page',
            $fields,
            '?page=uploads',
            'post',
            ['data-ajax' => 'true'],
            'button uploads-pager-button'
        );
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

    private function uploadWorkflowStatusLabel(array $upload, array $mappingStatus): string
    {
        $workflowStatus = trim((string)($upload['workflow_status'] ?? ''));

        if (in_array($workflowStatus, ['committed', 'completed'], true) || (int)($upload['inserted'] ?? 0) > 0) {
            return 'Imported';
        }

        if ((int)($upload['rows_parsed'] ?? 0) === 0) {
            return 'No Rows Found';
        }

        if (!empty($upload['duplicate_file'])) {
            return 'Duplicate File';
        }

        if ($workflowStatus === 'needs_accounting_period') {
            return 'Needs Accounting Period';
        }

        if ($workflowStatus === 'staged' || (int)($upload['rows_ready_to_import'] ?? 0) > 0) {
            return 'Preview Ready';
        }

        $mappingLabel = trim((string)($mappingStatus['mapping_label'] ?? ''));
        return $mappingLabel !== '' ? $mappingLabel : $this->uploadsImportWorkflowLabel($workflowStatus);
    }

    private function uploadWorkflowStatusClass(array $upload, array $mappingStatus): string
    {
        $workflowStatus = trim((string)($upload['workflow_status'] ?? ''));

        if (in_array($workflowStatus, ['committed', 'completed'], true) || (int)($upload['inserted'] ?? 0) > 0) {
            return 'success';
        }

        if ((int)($upload['rows_parsed'] ?? 0) === 0) {
            return 'muted';
        }

        if (!empty($upload['duplicate_file'])) {
            return 'warning';
        }

        if ($workflowStatus === 'needs_accounting_period') {
            return 'warning';
        }

        if ($workflowStatus === 'staged' || (int)($upload['rows_ready_to_import'] ?? 0) > 0) {
            return 'info';
        }

        $origin = trim((string)($mappingStatus['mapping_origin'] ?? ''));
        if ($origin === 'auto' || $origin === 'reused') {
            return !empty($mappingStatus['can_preview']) ? 'info' : 'warning';
        }

        if (!empty($mappingStatus['confirmed'])) {
            return 'info';
        }

        return 'warning';
    }

    private function displayDateTime(string $value, string $timeFormat): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDateTime($value, $timeFormat);
    }
}
