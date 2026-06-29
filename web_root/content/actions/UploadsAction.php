<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class UploadsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        return match (trim((string)$request->input('intent', ''))) {
            'upload_account_csv' => $this->uploadAccountCsv($request, $services),
            'preview_upload' => $this->previewUpload($request, $services),
            'save_account_mapping' => $this->saveAccountMapping($request, $services),
            'stage_account_upload' => $this->stageAccountUpload($request, $services),
            'rescan_account_upload' => $this->rescanAccountUpload($request, $services),
            'filter_uploads' => $this->actionResult($request, true),
            'recalculate_upload_checksums' => $this->recalculateUploadChecksums($request, $services),
            'backfill_transaction_types_from_staged_json' => $this->backfillTransactionTypesFromStagedJson($request, $services),
            'commit_account_upload' => $this->commitAccountUpload($request, $services),
            'export_csv_upload' => $this->exportCsvUpload($request, $services),
            'export_xlsx_upload' => $this->exportXlsxUpload($request, $services),
            default => ActionResultFramework::none(),
        };
    }

    private function uploadAccountCsv(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $result = $this->statementUploadService($services)->importUploadedStatements([
            'company_id' => $request->input('company_id'),
            'accounting_period_id' => $request->input('accounting_period_id'),
            'account_id' => $request->input('account_id'),
            'filter' => $request->input('filter'),
            'page' => $request->input('page'),
        ], $_FILES);

        $isBatchUpload = !empty($result['batch_upload']);
        [$flashMessages, $flashErrors] = $isBatchUpload ? [[], []] : $this->messagesFromResult($result);

        if (!empty($result['success'])) {
            $selectedUploadId = (int)($result['statement_upload_id'] ?? 0);
            $autoStaged = false;

            if (!empty($result['offline_update'])) {
                $flashMessages[] = sprintf(
                    'Offline category update applied: %d existing row(s) updated.',
                    (int)($result['offline_updated'] ?? 0)
                );
            }

            if (!$isBatchUpload && $selectedUploadId > 0 && (string)($result['workflow_status'] ?? '') === 'uploaded') {
                $autoStaged = $this->attemptAutoStageUpload($request, $services, $selectedUploadId, $flashMessages, $flashErrors);
            }

            if ($isBatchUpload && is_array($result['items'] ?? null)) {
                foreach ($result['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $itemMessages = array_values(array_map('strval', (array)($item['warnings'] ?? [])));
                    $itemErrors = array_values(array_map('strval', (array)($item['errors'] ?? [])));

                    $itemUploadId = (int)($item['statement_upload_id'] ?? 0);
                    if (!empty($item['success']) && $itemUploadId > 0 && (string)($item['workflow_status'] ?? '') === 'uploaded') {
                        $this->attemptAutoStageUpload(
                            $request,
                            $services,
                            $itemUploadId,
                            $itemMessages,
                            $itemErrors
                        );
                    }

                    [$itemFlashMessage, $itemFlashErrors] = $this->batchUploadItemFlash($item, $itemMessages, $itemErrors);
                    if ($itemFlashMessage !== '') {
                        $flashMessages[] = $itemFlashMessage;
                    }
                    if ($itemFlashErrors !== '') {
                        $flashErrors[] = $itemFlashErrors;
                    }
                }
            }

            if (!$autoStaged && !$isBatchUpload) {
                if (!empty($result['offline_update']) && $selectedUploadId <= 0) {
                    return $this->actionResult(
                        $request,
                        true,
                        $flashMessages,
                        $flashErrors,
                        ['upload_id' => 0]
                    );
                }

                $flashMessages[] = $isBatchUpload
                    ? sprintf(
                        'Batch upload complete: %d uploaded, %d already on file, %d failed.',
                        (int)($result['files_uploaded'] ?? 0),
                        (int)($result['files_already_uploaded'] ?? 0),
                        (int)($result['files_failed'] ?? 0)
                    )
                    : (!empty($result['already_uploaded'])
                        ? (!empty($result['resume_allowed'])
                            ? 'Existing staged upload reopened for review.'
                            : 'This exact Account export is already on file for the selected company.')
                        : 'CSV uploaded successfully. Review the field mapping before staging rows.');
            }
        }

        return $this->actionResult(
            $request,
            !empty($result['success']),
            $flashMessages,
            $flashErrors,
            ['upload_id' => (int)($result['statement_upload_id'] ?? 0)],
            ['page.context', 'uploads.details'],
            ['show_card' => 'uploads_details']
        );
    }

    private function batchUploadItemFlash(array $item, array $messages, array $errors): array
    {
        $filename = (string)($item['filename'] ?? 'CSV');
        $success = !empty($item['success']);
        $status = $success
            ? (!empty($item['already_uploaded'])
                ? 'already uploaded, existing record reopened.'
                : 'uploaded successfully.')
            : 'upload failed.';
        $details = array_values(array_filter(
            array_map('trim', array_map('strval', array_merge($messages, $errors))),
            static fn(string $message): bool => $message !== ''
        ));
        $message = $filename . ': ' . $status;

        if ($details !== []) {
            $message .= ' ' . implode(' ', $details);
        }

        return $success ? [$message, ''] : ['', $message];
    }

    private function previewUpload(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        $uploadId = max(0, (int)$request->input('upload_id', 0));

        if ($companyId <= 0 || $uploadId <= 0) {
            return $this->actionResult($request, false, [], ['Select an upload before previewing it.']);
        }

        $uploadService = $this->statementUploadService($services);
        if ($uploadService->fetchUploadPreview($companyId, $uploadId) === []) {
            return $this->actionResult($request, false, [], ['The selected staged upload could not be found.']);
        }

        $flashMessages = [];
        $flashErrors = [];
        $mappingStatus = $uploadService->describeUploadAccountMappingStatus($companyId, $uploadId);
        $extraHeaders = is_array($mappingStatus['extra_headers'] ?? null) ? array_values($mappingStatus['extra_headers']) : [];

        if (!empty($mappingStatus['can_preview']) && $extraHeaders === [] && $uploadService->uploadNeedsPreviewRefresh($companyId, $uploadId)) {
            $stageResult = $uploadService->stageUploadRows($companyId, $uploadId, $this->defaultCurrency());
            [$stageMessages, $stageErrors] = $this->messagesFromResult($stageResult);
            $flashMessages = array_merge($flashMessages, $stageMessages);
            $flashErrors = array_merge($flashErrors, $stageErrors);

            if (!empty($stageResult['success'])) {
                $flashMessages[] = 'Saved field mapping reused and rows staged automatically.';
                $flashMessages[] = $this->previewSummaryMessage($stageResult, 'Preview ready');
            }
        }

        return $this->actionResult($request, true, $flashMessages, $flashErrors, ['upload_id' => $uploadId]);
    }

    private function saveAccountMapping(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $payload = [
            'company_id' => $request->input('company_id'),
            'accounting_period_id' => $request->input('accounting_period_id'),
            'upload_id' => $request->input('upload_id'),
            'account_id' => $request->input('account_id'),
        ];

        foreach (array_keys(\eel_accounts\Service\StatementUploadService::fieldDefinitions()) as $fieldName) {
            $payload['mapping_' . $fieldName] = $request->input('mapping_' . $fieldName, '');
        }

        $result = $this->statementUploadService($services)->saveFieldMapping($payload);
        [$flashMessages, $flashErrors] = $this->messagesFromResult($result);

        if (!empty($result['success'])) {
            $flashMessages[] = 'Field mapping saved. You can preview and validate the staged rows now.';
        }

        return $this->actionResult(
            $request,
            !empty($result['success']),
            $flashMessages,
            $flashErrors,
            ['upload_id' => (int)($result['statement_upload_id'] ?? 0)]
        );
    }

    private function stageAccountUpload(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        return $this->stageUploadAction($request, $services, 'Preview ready');
    }

    private function rescanAccountUpload(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (!$this->developerOptionsEnabled()) {
            return $this->developerOptionsDisabledResult($request);
        }

        return $this->stageUploadAction($request, $services, 'Rescan complete');
    }

    private function recalculateUploadChecksums(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (!$this->developerOptionsEnabled()) {
            return $this->developerOptionsDisabledResult($request);
        }

        $result = $this->statementUploadService($services)->recalculateCompanyChecksums(
            (new \eel_accounts\Service\AccountingContextService())->authCompanyId()
        );
        [$flashMessages, $flashErrors] = $this->messagesFromResult($result);

        if (!empty($result['success'])) {
            $flashMessages[] = sprintf(
                'Checksums recalculated across this company: %d staged row(s) updated, %d committed transaction(s) updated.',
                (int)($result['rows_updated'] ?? 0),
                (int)($result['transactions_updated'] ?? 0)
            );
        }

        return $this->actionResult($request, !empty($result['success']), $flashMessages, $flashErrors);
    }

    private function backfillTransactionTypesFromStagedJson(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (!$this->developerOptionsEnabled()) {
            return $this->developerOptionsDisabledResult($request);
        }

        $result = $this->statementUploadService($services)->backfillTransactionTypesFromStagedImportJson(
            (new \eel_accounts\Service\AccountingContextService())->authCompanyId()
        );
        [, $flashErrors] = $this->messagesFromResult($result);
        $flashMessages = [];

        if (!empty($result['success'])) {
            $flashMessages[] = sprintf(
                'Transaction type backfill complete: %d scanned, %d updated, %d skipped, %d failed.',
                (int)($result['rows_scanned'] ?? 0),
                (int)($result['rows_updated'] ?? 0),
                (int)($result['rows_skipped'] ?? 0),
                (int)($result['rows_failed'] ?? 0)
            );
        }

        return $this->actionResult($request, !empty($result['success']), $flashMessages, $flashErrors);
    }

    private function commitAccountUpload(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $result = $this->statementUploadService($services)->commitUpload(
            (new \eel_accounts\Service\AccountingContextService())->authCompanyId(),
            max(0, (int)$request->input('upload_id', 0))
        );
        [$flashMessages, $flashErrors] = $this->messagesFromResult($result);

        if (!empty($result['success'])) {
            $documentSummary = is_array($result['document_summary'] ?? null) ? $result['document_summary'] : [];
            $flashMessages[] = (int)($result['rows_inserted'] ?? 0) > 0
                ? (int)$result['rows_inserted'] . ' transaction(s) committed from the staged upload.'
                : 'No new transactions were committed from this upload.';
            $flashMessages[] = sprintf(
                'Receipts: %d downloaded, %d pending/skipped, %d failed.',
                (int)($documentSummary['success'] ?? 0),
                (int)($documentSummary['pending'] ?? 0) + (int)($documentSummary['skipped'] ?? 0),
                (int)($documentSummary['failed'] ?? 0)
            );
        }

        return $this->actionResult(
            $request,
            !empty($result['success']),
            $flashMessages,
            $flashErrors,
            ['upload_id' => (int)($result['statement_upload_id'] ?? 0)]
        );
    }

    private function exportCsvUpload(RequestFramework $request, PageServiceFramework $services): never
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        $uploadId = max(0, (int)$request->input('upload_id', 0));
        $exportMonth = trim((string)$request->input('export_month', ''));
        $exportService = $services->get(\eel_accounts\Service\StatementCsvExportService::class);

        if (!$exportService instanceof \eel_accounts\Service\StatementCsvExportService) {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
            echo 'The CSV export service is unavailable.';
            exit;
        }

        $result = $exportService->buildExport($companyId, $uploadId, $exportMonth);

        if (empty($result['success'])) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo (string)($result['errors'][0] ?? 'The CSV export could not be created.');
            exit;
        }

        $filename = basename((string)($result['filename'] ?? 'statement-export.csv'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo (string)($result['csv'] ?? '');
        exit;
    }

    private function exportXlsxUpload(RequestFramework $request, PageServiceFramework $services): never
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        $uploadId = max(0, (int)$request->input('upload_id', 0));
        $exportMonth = trim((string)$request->input('export_month', ''));
        $exportService = $services->get(\eel_accounts\Service\StatementCsvExportService::class);

        if (!$exportService instanceof \eel_accounts\Service\StatementCsvExportService) {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
            echo 'The XLSX export service is unavailable.';
            exit;
        }

        $result = $exportService->buildXlsxExport($companyId, $uploadId, $exportMonth);

        if (empty($result['success'])) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo (string)($result['errors'][0] ?? 'The XLSX export could not be created.');
            exit;
        }

        $filename = basename((string)($result['filename'] ?? 'statement-export.xlsx'));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo (string)($result['xlsx'] ?? '');
        exit;
    }

    private function stageUploadAction(RequestFramework $request, PageServiceFramework $services, string $summaryPrefix): ActionResultFramework
    {
        $result = $this->statementUploadService($services)->stageUploadRows(
            (new \eel_accounts\Service\AccountingContextService())->authCompanyId(),
            max(0, (int)$request->input('upload_id', 0)),
            $this->defaultCurrency()
        );
        [$flashMessages, $flashErrors] = $this->messagesFromResult($result);

        if (!empty($result['success'])) {
            $flashMessages[] = $this->previewSummaryMessage($result, $summaryPrefix);
        }

        return $this->actionResult(
            $request,
            !empty($result['success']),
            $flashMessages,
            $flashErrors,
            ['upload_id' => (int)($result['statement_upload_id'] ?? 0)]
        );
    }

    private function attemptAutoStageUpload(
        RequestFramework $request,
        PageServiceFramework $services,
        int $uploadId,
        array &$flashMessages,
        array &$flashErrors,
        string $labelPrefix = ''
    ): bool {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        $uploadService = $this->statementUploadService($services);
        $mappingStatus = $uploadService->describeUploadAccountMappingStatus($companyId, $uploadId);
        $extraHeaders = is_array($mappingStatus['extra_headers'] ?? null) ? $mappingStatus['extra_headers'] : [];

        if (empty($mappingStatus['can_preview']) || $extraHeaders !== []) {
            return false;
        }

        $stageResult = $uploadService->stageUploadRows($companyId, $uploadId, $this->defaultCurrency());
        [$stageMessages, $stageErrors] = $this->messagesFromResult($stageResult);
        $flashMessages = array_merge($flashMessages, $stageMessages);
        $flashErrors = array_merge($flashErrors, $stageErrors);

        if (empty($stageResult['success'])) {
            return false;
        }

        if ($labelPrefix === '') {
            $flashMessages[] = 'Saved field mapping reused and rows staged automatically.';
        }

        $flashMessages[] = $this->previewSummaryMessage(
            $stageResult,
            ($labelPrefix !== '' ? $labelPrefix . ': Preview ready' : 'Preview ready')
        );

        return true;
    }

    private function previewSummaryMessage(array $result, string $prefix): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        return sprintf(
            '%s: %d valid, %d invalid, %d ready to import.',
            $prefix,
            (int)($summary['rows_valid'] ?? 0),
            (int)($summary['rows_invalid'] ?? 0),
            (int)($summary['rows_ready_to_import'] ?? 0)
        );
    }

    private function messagesFromResult(array $result): array
    {
        return [
            array_map('strval', (array)($result['warnings'] ?? [])),
            array_map('strval', (array)($result['errors'] ?? [])),
        ];
    }

    private function actionResult(
        RequestFramework $request,
        bool $success,
        array $flashMessages = [],
        array $flashErrors = [],
        array $extraContext = [],
        array $changedFacts = ['page.context'],
        array $query = []
    ): ActionResultFramework {
        $uploadId = array_key_exists('upload_id', $extraContext)
            ? max(0, (int)$extraContext['upload_id'])
            : max(0, (int)$request->input('upload_id', 0));

        $filter = trim((string)$request->input('filter', 'all'));

        return new ActionResultFramework(
            success: $success,
            changedFacts: $changedFacts,
            flashMessages: $this->flashMessages($flashMessages, $flashErrors),
            query: $query,
            context: [
                'uploads' => [
                    'id' => $uploadId,
                    'filter' => $filter !== '' ? $filter : 'all',
                    'page' => max(1, (int)$request->input('page', 1)),
                ],
            ]
        );
    }

    private function developerOptionsDisabledResult(RequestFramework $request): ActionResultFramework
    {
        return $this->actionResult($request, false, [], ['Developer options are disabled for this environment.']);
    }

    private function flashMessages(array $flashMessages = [], array $flashErrors = []): array
    {
        $flash = [];

        foreach ($flashMessages as $message) {
            $message = trim((string)$message);
            if ($message !== '') {
                $flash[] = $message;
            }
        }

        foreach ($flashErrors as $message) {
            $message = trim((string)$message);
            if ($message !== '') {
                $flash[] = [
                    'type' => 'error',
                    'message' => $message,
                ];
            }
        }

        return $flash;
    }

    private function defaultCurrency(): string
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : \eel_accounts\Store\CompanySettingsStore::defaults();

        return (string)($settings['default_currency'] ?? 'GBP');
    }

    private function developerOptionsEnabled(): bool
    {
        return (bool)(AppConfigurationStore::get('developer_options', false));
    }

    private function statementUploadService(PageServiceFramework $services): \eel_accounts\Service\StatementUploadService
    {
        $service = $services->get(\eel_accounts\Service\StatementUploadService::class);

        if (!$service instanceof \eel_accounts\Service\StatementUploadService) {
            throw new RuntimeException('StatementUploadService is unavailable.');
        }

        return $service;
    }
}
