<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads_validate_commitCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'uploads_validate_commit';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'selected_upload_preview',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'fetchUploadPreview',
                'params' => [
                    'companyId' => ':company.id',
                    'uploadId' => ':uploads.id',
                ],
            ],
            [
                'key' => 'accounting_guidance',
                'service' => \eel_accounts\Service\AccountingGuidanceService::class,
                'method' => 'build',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
            [
                'key' => 'empty_month_upload_impact',
                'service' => \eel_accounts\Service\EmptyMonthConfirmationService::class,
                'method' => 'activeConfirmationsAffectedByUpload',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'uploadId' => ':uploads.id',
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

    public function helper(array $context): string|array
    {

        $filename = (string)($context['services']['selected_upload_preview']['upload']['original_filename'] ?? '');
        if ($filename === '') {
            return 'No upload selected.';
        }
        return HelperFramework::rawHtml(
            'Working on upload: <strong>' . HelperFramework::escape($filename) . '</strong>'
        );
    }

    public function title(): string
    {
        return 'Review & Commit Transactions';
    }

    public function render(array $context): string
    {
        $placeholderMessage = trim((string)($context['uploads']['validate_placeholder_message'] ?? ''));

        if ($placeholderMessage !== '') {
            return '<div class="helper">' . HelperFramework::escape($placeholderMessage) . '</div>';
        }

        $preview = (array)($context['services']['selected_upload_preview'] ?? []);

        if ($preview === []) {
            return '<div class="helper">Click Preview in the Upload Details section to view the transactions.</div>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);

        $uploadId = (int)($context['uploads']['id'] ?? 0);
        $uploadHistoryFilter = (string)($context['uploads']['filter'] ?? 'all');
        $uploadHistoryPage = (int)($context['uploads']['page'] ?? 1);

        $upload = (array)($preview['upload'] ?? []);
        $rows = array_values(array_filter(
            (array)($preview['rows'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
        $summary = is_array($preview['summary'] ?? null) ? (array)$preview['summary'] : [];

        if ($rows === [] && (int)($summary['rows_parsed'] ?? 0) > 0) {
            return '<div class="panel-soft">
                <h3 class="card-title">Preview Not Ready</h3>
                <div class="helper">' . HelperFramework::escape($this->previewNotReadyMessage((array)($preview['mapping'] ?? []), (int)$summary['rows_parsed'])) . '</div>
            </div>';
        }

        $hasNotes = false;
        $hasMissingAccountingPeriod = false;
        $firstMissingAccountingPeriodDate = '';

        foreach ($rows as $row) {
            $notes = trim((string)($row['validation_notes'] ?? ''));

            if ($notes !== '') {
                $hasNotes = true;
            }

            if (str_contains($notes, 'No accounting period exists for the chosen transaction date.')) {
                $hasMissingAccountingPeriod = true;
                if ($firstMissingAccountingPeriodDate === '') {
                    $firstMissingAccountingPeriodDate = trim((string)($row['chosen_txn_date'] ?? ''));
                }
            }
        }

        $readyToImport = (int)($summary['rows_ready_to_import'] ?? 0);
        $accountId = (int)($upload['account_id'] ?? 0);
        $importButtonDisabled = $readyToImport <= 0 ? ' disabled' : '';
        $emptyMonthImpactHtml = $this->emptyMonthImpactHtml((array)($context['services']['empty_month_upload_impact'] ?? []));

        $importForm = '<form method="post" action="?page=uploads">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Uploads">
                <input type="hidden" name="intent" value="commit_account_upload">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="upload_id" value="' . $uploadId . '">
                <input type="hidden" name="filter" value="' . HelperFramework::escape($uploadHistoryFilter) . '">
                <input type="hidden" name="page" value="' . $uploadHistoryPage . '">
                <input type="hidden" name="account_id" value="' . $accountId . '">
                <button class="button primary" type="submit"' . $importButtonDisabled . '>Import Transactions</button>
            </form>';

        $rowsHtml = '';
        foreach ($rows as $row) {
            $amount = (float)($row['normalised_amount'] ?? 0);
            $amountClass = $amount >= 0 ? 'amount-positive' : 'amount-negative';

            $accountingPeriod = trim((string)($row['accounting_period_label'] ?? ''));
            if ($accountingPeriod === '') {
                $rowAccountingPeriodId = (int)($row['accounting_period_id'] ?? 0);
                $accountingPeriod = $rowAccountingPeriodId > 0 ? 'Period #' . $rowAccountingPeriodId : 'Missing';
            }

            $description = (string)($row['normalised_description'] ?? $row['source_description'] ?? '');
            $balance = trim((string)($row['normalised_balance'] ?? '')) !== ''
                ? (string)$row['normalised_balance']
                : (string)($row['source_balance'] ?? '');
            $sourceCategory = trim((string)($row['source_category'] ?? '')) !== ''
                ? (string)$row['source_category']
                : 'Uncategorised';

            $documentUrl = trim((string)($row['source_document_url'] ?? ''));
            $documentHtml = $documentUrl !== ''
                ? '<a class="text-link" href="' . HelperFramework::escape($documentUrl) . '" target="_blank" rel="noopener noreferrer">Source document</a>'
                : '<span class="helper">None</span>';

            $status = trim((string)($row['validation_status'] ?? 'invalid'));
            if ((int)($row['committed_transaction_id'] ?? 0) > 0) {
                $stageClass = 'success';
                $stageLabel = 'Committed';
            } elseif (!empty($row['is_duplicate_existing'])) {
                $stageClass = 'warning';
                $stageLabel = 'Already imported';
            } elseif (!empty($row['is_duplicate_within_upload'])) {
                $stageClass = 'info';
                $stageLabel = 'Duplicate in upload';
            } elseif ($status !== 'valid') {
                $stageClass = 'muted';
                $stageLabel = 'Invalid';
            } elseif ((int)($row['accounting_period_id'] ?? 0) <= 0) {
                $stageClass = 'warning';
                $stageLabel = 'Needs accounting period';
            } else {
                $stageClass = 'success';
                $stageLabel = 'Ready to import';
            }

            $statusHtml = '<span class="badge stage-badge ' . HelperFramework::escape($stageClass) . '">' . HelperFramework::escape($stageLabel) . '</span>';

            $rowsHtml .= '<tr>
                <td>' . (int)($row['row_number'] ?? 0) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($row['chosen_txn_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape($accountingPeriod) . '</td>
                <td>' . HelperFramework::escape($description) . '</td>
                <td class="' . $amountClass . '">' . HelperFramework::escape((string)($row['normalised_amount'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($balance) . '</td>
                <td>' . HelperFramework::escape((string)($row['normalised_currency'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['source_account'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($sourceCategory) . '</td>
                <td>' . $documentHtml . '</td>
                <td>' . $statusHtml . '</td>'
                . ($hasNotes ? '<td>' . HelperFramework::escape((string)($row['validation_notes'] ?? '')) . '</td>' : '') . '
            </tr>';
        }

        $notesColumn = $hasNotes ? '<th>Notes</th>' : '';
        $missingAccountingPeriodHtml = '';
        if ($hasMissingAccountingPeriod) {
            $accountingGuidance = (array)($context['services']['accounting_guidance'] ?? []);
            $suggestedPeriod = $this->resolveSuggestedPeriodForDate(
                (array)($accountingGuidance['missing_suggested_periods'] ?? []),
                $firstMissingAccountingPeriodDate
            );

            $missingAccountingPeriodHtml = '<div class="panel-soft warn full">
                <h4 class="card-title">The accounting period for this upload is missing.</h4>
                <div class="helper">At least one transaction date in this upload does not fall inside an existing accounting period.</div>';

            if ($suggestedPeriod !== []) {
                $periodStart = trim((string)($suggestedPeriod['period_start'] ?? $suggestedPeriod['start'] ?? ''));
                $periodEnd = trim((string)($suggestedPeriod['period_end'] ?? $suggestedPeriod['end'] ?? ''));
                $periodLabel = trim((string)($suggestedPeriod['label'] ?? ''));

                if ($periodStart !== '' && $periodEnd !== '') {
                    if ($periodLabel === '') {
                        $periodLabel = \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel($periodStart, $periodEnd);
                    }

                    $missingAccountingPeriodHtml .= '<div class="list">
                        <div class="list-item">
                            <strong>Suggested period</strong>
                            <span>' . HelperFramework::escape($this->periodDisplayRange($suggestedPeriod)) . '</span>
                        </div>
                    </div>
                    <div class="actions-row">
                        <form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                            <input type="hidden" name="card_action" value="AccountingPeriods">
                            <input type="hidden" name="intent" value="create_required_periods_for_upload">
                            <input type="hidden" name="company_id" value="' . $companyId . '">
                            <input type="hidden" name="required_period_end" value="' . HelperFramework::escape($periodEnd) . '">
                            <button class="button primary" type="submit">Create Required Accounting Periods</button>
                        </form>
                        ' . \eel_accounts\Renderer\WorkflowHandoffRenderer::button('companies', 'Open Companies', ['company_id' => $companyId]) . '
                    </div>';
                }
            }

            if ($suggestedPeriod === []) {
                $missingAccountingPeriodHtml .= '<div class="actions-row">'
                    . \eel_accounts\Renderer\WorkflowHandoffRenderer::button('companies', 'Open Companies', ['company_id' => $companyId])
                    . '</div>';
            }

            $missingAccountingPeriodHtml .= '</div>';
        }
        $tableHtml = $rows !== []
            ? '<div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Txn Date</th>
                            <th>Accounting Period</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Balance</th>
                            <th>Currency</th>
                            <th>Source Account</th>
                            <th>Source Category</th>
                            <th>Document</th>
                            <th>Stage</th>
                            ' . $notesColumn . '
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>'
            : '<div class="helper">Save the field mapping and preview the file to stage individual rows here.</div>';

        return '
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Total rows</div><div class="summary-value">' . (int)($summary['rows_parsed'] ?? 0) . '</div></div>
                <div class="summary-card"><div class="summary-label">Valid rows</div><div class="summary-value">' . (int)($summary['rows_valid'] ?? 0) . '</div></div>
                <div class="summary-card"><div class="summary-label">Invalid rows</div><div class="summary-value">' . (int)($summary['rows_invalid'] ?? 0) . '</div></div>
                <div class="summary-card"><div class="summary-label">Duplicate in upload</div><div class="summary-value">' . (int)($summary['rows_duplicate_within_upload'] ?? 0) . '</div></div>
                <div class="summary-card"><div class="summary-label">Already imported</div><div class="summary-value">' . (int)($summary['rows_duplicate_existing'] ?? 0) . '</div></div>
                <div class="summary-card"><div class="summary-label">Ready to import</div><div class="summary-value">' . $readyToImport . '</div></div>
            </div>
            
            ' . $emptyMonthImpactHtml . $importForm . $missingAccountingPeriodHtml . $tableHtml . $emptyMonthImpactHtml . $importForm;
    }

    private function emptyMonthImpactHtml(array $impactMonths): string
    {
        if ($impactMonths === []) {
            return '';
        }

        $items = '';
        foreach ($impactMonths as $month) {
            if (!is_array($month)) {
                continue;
            }

            $rowCount = (int)($month['row_count'] ?? 0);
            $items .= '<li>' . HelperFramework::escape((string)($month['month_label'] ?? '')) . ' - '
                . $rowCount . ' ready row' . ($rowCount === 1 ? '' : 's') . '</li>';
        }

        if ($items === '') {
            return '';
        }

        return '<div class="panel-soft warn full">
            <h4 class="card-title">This import will clear no-activity confirmation</h4>
            <div class="helper">Ready-to-import rows fall in month(s) currently approved as having no financial activity. Importing the rows will revoke those empty-month confirmations.</div>
            <ul class="helper">' . $items . '</ul>
        </div>';
    }

    private function resolveSuggestedPeriodForDate(array $periods, string $chosenTxnDate): array
    {
        $chosenTxnDate = trim($chosenTxnDate);

        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }

            $periodStart = trim((string)($period['period_start'] ?? $period['start'] ?? ''));
            $periodEnd = trim((string)($period['period_end'] ?? $period['end'] ?? ''));

            if ($periodStart === '' || $periodEnd === '') {
                continue;
            }

            if ($chosenTxnDate !== '' && $chosenTxnDate >= $periodStart && $chosenTxnDate <= $periodEnd) {
                return $period;
            }
        }

        foreach ($periods as $period) {
            if (is_array($period)) {
                return $period;
            }
        }

        return [];
    }

    private function previewNotReadyMessage(array $mapping, int $uploadedRows): string
    {
        $origin = trim((string)($mapping['mapping_origin'] ?? ''));
        $confirmedAt = trim((string)($mapping['confirmed_at'] ?? ''));

        if ($mapping === []) {
            return sprintf(
                'This CSV has %d uploaded row(s). Open Review Uploads, save the mapping, then preview and validate rows.',
                $uploadedRows
            );
        }

        if ($confirmedAt === '') {
            $label = match ($origin) {
                'auto' => 'An auto field mapping has been applied',
                'reused' => 'A saved account field mapping has been reused',
                default => 'A field mapping is available',
            };

            return sprintf(
                '%s for this CSV with %d uploaded row(s). Confirm or adjust the field mapping, then preview and validate rows.',
                $label,
                $uploadedRows
            );
        }

        return sprintf(
            'This CSV has %d uploaded row(s). Preview and validate rows to stage them before importing transactions.',
            $uploadedRows
        );
    }

    private function periodDisplayRange(array $period): string
    {
        $displayRange = trim((string)($period['display_range'] ?? ''));
        if ($displayRange !== '') {
            return $displayRange;
        }

        $start = trim((string)($period['period_start'] ?? $period['start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? $period['end'] ?? ''));

        if ($start !== '' && $end !== '') {
            return HelperFramework::displayDate($start) . ' to ' . HelperFramework::displayDate($end);
        }

        return '';
    }
}
