<?php
declare(strict_types=1);

final class _uploads_validate_commitCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'uploads_validate_commit';
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
        $uploadsValidatePlaceholderMessage = (string)($page['uploads_validate_placeholder_message'] ?? '');
        $selectedUploadPreview = (array)($page['selected_upload_preview'] ?? []);
        $selectedUploadSummary = (array)($page['selected_upload_summary'] ?? []);
        $selectedUploadHasNotes = !empty($page['selected_upload_has_notes']);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $settings = (array)($page['settings'] ?? []);
        $dateFormat = (string)($settings['date_format'] ?? '');
        $selectedUploadId = (int)($page['selected_upload_id'] ?? $page['upload_id'] ?? 0);
        $selectedUploadHistoryFilter = (string)($page['selected_upload_history_filter'] ?? $page['upload_history_filter'] ?? 'all');
        $selectedUploadHistoryPage = (int)($page['selected_upload_history_page'] ?? $page['upload_history_page'] ?? 1);

        $badgeHtml = ($uploadsValidatePlaceholderMessage === '' && $selectedUploadSummary !== [])
            ? '<span class="badge info">' . (int)($selectedUploadSummary['rows_parsed'] ?? 0) . ' row(s)</span>'
            : '';

        if ($uploadsValidatePlaceholderMessage !== '') {
            $bodyHtml = '<div class="helper">' . HelperFramework::escape($uploadsValidatePlaceholderMessage) . '</div>';
        } elseif ($selectedUploadPreview !== []) {
            $hasMissingAccountingPeriod = false;
            foreach ((array)($selectedUploadPreview['rows'] ?? []) as $row) {
                if (str_contains((string)($row['validation_notes'] ?? ''), 'No accounting period exists for the chosen transaction date.')) {
                    $hasMissingAccountingPeriod = true;
                    break;
                }
            }

            $rowsHtml = '';
            foreach ((array)($selectedUploadPreview['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowsHtml .= '<tr>
                    <td>' . (int)($row['row_number'] ?? 0) . '</td>
                    <td>' . HelperFramework::escape($this->displayDate((string)($row['chosen_txn_date'] ?? ''), $selectedCompanyId, $dateFormat)) . '</td>
                    <td>' . HelperFramework::escape(trim((string)($row['tax_year_label'] ?? '')) !== '' ? (string)$row['tax_year_label'] : ((int)($row['tax_year_id'] ?? 0) > 0 ? ('Period #' . (int)$row['tax_year_id']) : 'Missing')) . '</td>
                    <td>' . HelperFramework::escape((string)($row['normalised_description'] ?? $row['source_description'] ?? '')) . '</td>
                    <td class="' . (((float)($row['normalised_amount'] ?? 0) >= 0) ? 'amount-positive' : 'amount-negative') . '">' . HelperFramework::escape((string)($row['normalised_amount'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape(trim((string)($row['normalised_balance'] ?? '')) !== '' ? (string)$row['normalised_balance'] : (string)($row['source_balance'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape((string)($row['normalised_currency'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape((string)($row['source_account'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape(trim((string)($row['source_category'] ?? '')) !== '' ? (string)$row['source_category'] : 'Uncategorised') . '</td>
                    <td>' . (trim((string)($row['source_document_url'] ?? '')) !== ''
                        ? '<a class="text-link" href="' . HelperFramework::escape((string)$row['source_document_url']) . '" target="_blank" rel="noopener noreferrer">Source document</a>'
                        : '<span class="helper">None</span>') . '</td>
                    <td>
                        <div><span class="badge ' . HelperFramework::escape($this->uploadsStagedBalanceBadgeClass((array)$row)) . '">Balance ' . HelperFramework::escape($this->uploadsStagedBalanceLabel((array)$row)) . '</span></div>
                        <div style="margin-top: 6px;"><span class="badge ' . HelperFramework::escape($this->uploadsImportRowStatusBadgeClass((array)$row)) . '">' . HelperFramework::escape($this->uploadsImportRowStatusLabel((array)$row)) . '</span></div>
                        <div style="margin-top: 6px;"><span class="badge ' . HelperFramework::escape($this->uploadsDuplicateBadgeClass((array)$row)) . '">' . HelperFramework::escape($this->uploadsDuplicateLabel((array)$row)) . '</span></div>
                    </td>'
                    . ($selectedUploadHasNotes ? '<td>' . HelperFramework::escape((string)($row['validation_notes'] ?? '')) . '</td>' : '') . '
                </tr>';
            }

            $notesColumn = $selectedUploadHasNotes ? '<th>Notes</th>' : '';
            $notesCell = '';

            $importForm = '<form method="post" action="' . HelperFramework::escape($this->buildPageUrl('uploads', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId, 'upload_id' => $selectedUploadId])) . '" style="margin: 0 0 14px;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-validate,uploads-monthly-status">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                    <input type="hidden" name="upload_id" value="' . $selectedUploadId . '">
                    <input type="hidden" name="upload_history_filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                    <input type="hidden" name="upload_history_page" value="' . $selectedUploadHistoryPage . '">
                    <input type="hidden" name="account_id" value="' . (int)($selectedUploadPreview['upload']['account_id'] ?? 0) . '">
                    <input type="hidden" name="global_action" value="commit_anna_upload">
                    <button class="button primary" type="submit"' . ((int)($selectedUploadSummary['rows_ready_to_import'] ?? 0) <= 0 ? ' disabled' : '') . '>Import Transactions</button>
                </form>';

            $bodyHtml = '<div class="helper" style="margin-bottom: 14px;">Working on upload: <strong>' . HelperFramework::escape((string)($selectedUploadPreview['upload']['original_filename'] ?? '')) . '</strong>.</div>
                <div class="summary-grid">
                    <div class="summary-card"><div class="summary-label">Total rows</div><div class="summary-value">' . (int)($selectedUploadSummary['rows_parsed'] ?? 0) . '</div></div>
                    <div class="summary-card"><div class="summary-label">Valid rows</div><div class="summary-value">' . (int)($selectedUploadSummary['rows_valid'] ?? 0) . '</div></div>
                    <div class="summary-card"><div class="summary-label">Invalid rows</div><div class="summary-value">' . (int)($selectedUploadSummary['rows_invalid'] ?? 0) . '</div></div>
                    <div class="summary-card"><div class="summary-label">Duplicate in upload</div><div class="summary-value">' . (int)($selectedUploadSummary['rows_duplicate_within_upload'] ?? 0) . '</div></div>
                    <div class="summary-card"><div class="summary-label">Already imported</div><div class="summary-value">' . (int)($selectedUploadSummary['rows_duplicate_existing'] ?? 0) . '</div></div>
                    <div class="summary-card"><div class="summary-label">Ready to import</div><div class="summary-value">' . (int)($selectedUploadSummary['rows_ready_to_import'] ?? 0) . '</div></div>
                </div>
                <div class="helper" style="margin: 14px 0;">ANNA category is shown as <strong>Source category</strong> only. It does not set the bookkeeping nominal account by default. Accounting periods are assigned per row from the chosen transaction date.</div>
                ' . $importForm
                . ($hasMissingAccountingPeriod ? '<div class="helper" style="margin-bottom: 12px;">Some rows could not be assigned to an accounting period. <a class="text-link" href="' . HelperFramework::escape($this->buildPageUrl('companies', ['company_id' => $selectedCompanyId])) . '">Open Companies -&gt; Accounting Periods</a></div>' : '')
                . (((array)($selectedUploadPreview['rows'] ?? [])) !== []
                    ? '<div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Row</th><th>Txn Date</th><th>Accounting Period</th><th>Description</th><th>Amount</th><th>Balance</th><th>Currency</th><th>Source Account</th><th>Source Category</th><th>Document</th><th>Status</th>' . $notesColumn . '
                                </tr>
                            </thead>
                            <tbody>' . $rowsHtml . '</tbody>
                        </table>
                    </div>'
                    : '<div class="helper">Save the field mapping and preview the file to stage individual rows here.</div>')
                . '<form method="post" action="' . HelperFramework::escape($this->buildPageUrl('uploads', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId, 'upload_id' => $selectedUploadId])) . '" style="margin-top: 16px;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-validate,uploads-monthly-status">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                    <input type="hidden" name="upload_id" value="' . $selectedUploadId . '">
                    <input type="hidden" name="upload_history_filter" value="' . HelperFramework::escape($selectedUploadHistoryFilter) . '">
                    <input type="hidden" name="upload_history_page" value="' . $selectedUploadHistoryPage . '">
                    <input type="hidden" name="account_id" value="' . (int)($selectedUploadPreview['upload']['account_id'] ?? 0) . '">
                    <input type="hidden" name="global_action" value="commit_anna_upload">
                    <button class="button primary" type="submit"' . ((int)($selectedUploadSummary['rows_ready_to_import'] ?? 0) <= 0 ? ' disabled' : '') . '>Import Transactions</button>
                </form>';
        } else {
            $bodyHtml = '<div class="helper">Click Preview in the Upload Details section to view the transactions.</div>';
        }

        return '<section class="eel-card-fragment" data-card="uploads-validate">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Validate and Commit Transactions</h2>
                    ' . $badgeHtml . '
                </div>
                <div class="card-body">' . $bodyHtml . '</div>
            </div>
        </section>';
    }

    private function uploadsStagedBalanceBadgeClass(array $row): string
    {
        return trim((string)($row['normalised_balance'] ?? '')) !== '' || trim((string)($row['source_balance'] ?? '')) !== '' ? 'success' : 'muted';
    }

    private function uploadsStagedBalanceLabel(array $row): string
    {
        return trim((string)($row['normalised_balance'] ?? '')) !== '' || trim((string)($row['source_balance'] ?? '')) !== '' ? 'Present' : 'Missing';
    }

    private function uploadsImportRowStatusBadgeClass(array $row): string
    {
        return match ((string)($row['validation_status'] ?? 'invalid')) {
            'valid' => 'success',
            'warning' => 'warning',
            default => 'muted',
        };
    }

    private function uploadsImportRowStatusLabel(array $row): string
    {
        $status = trim((string)($row['validation_status'] ?? 'invalid'));
        return $status !== '' ? HelperFramework::labelFromKey($status, '_') : 'Invalid';
    }

    private function uploadsDuplicateBadgeClass(array $row): string
    {
        if (!empty($row['is_duplicate_existing'])) {
            return 'warning';
        }
        if (!empty($row['is_duplicate_within_upload'])) {
            return 'info';
        }
        return 'success';
    }

    private function uploadsDuplicateLabel(array $row): string
    {
        if (!empty($row['is_duplicate_existing'])) {
            return 'Already imported';
        }
        if (!empty($row['is_duplicate_within_upload'])) {
            return 'Duplicate in upload';
        }
        return 'Unique';
    }

    private function displayDate(string $value, int $companyId, string $dateFormat): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value, $companyId, $dateFormat);
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
