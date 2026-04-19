<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads extends BaseModulePageFramework
{
    private const HISTORY_PAGE_SIZE = 25;

    public function id(): string
    {
        return 'uploads';
    }

    public function title(): string
    {
        return 'Uploads';
    }

    public function subtitle(): string
    {
        return 'Upload bank CSV files, review mapping, and validate staged rows before committing transactions.';
    }

    public function cards(): array
    {
        return ['uploads_bank_transactions', 'uploads_details', 'uploads_field_mapping', 'uploads_flow', 'uploads_monthly_status', 'uploads_validate_commit'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $companyId = (int)($baseContext['company_id'] ?? 0);
        $taxYearId = (int)($baseContext['tax_year_id'] ?? 0);
        $repository = new DashboardRepository();
        $uploadId = max(0, (int)$request->input('upload_id', $request->query('upload_id', 0)));
        $filter = (string)$request->input('upload_history_filter', $request->query('upload_history_filter', 'all'));
        $page = max(1, (int)$request->input('upload_history_page', $request->query('upload_history_page', 1)));
        $offset = ($page - 1) * self::HISTORY_PAGE_SIZE;
        $selectedUploadPreview = [];
        if ($companyId > 0 && $uploadId > 0) {
            if (array_key_exists(AppService::class, $services->all())) {
                /** @var StatementUploadService $uploadService */
                $uploadService = $services->get(AppService::class)->get(StatementUploadService::class);
            } else {
                $uploadService = new StatementUploadService('');
            }

            $selectedUploadPreview = $uploadService->fetchUploadPreview($companyId, $uploadId);
        }
        $historyAll = ($companyId > 0) ? $repository->fetchUploadHistory($companyId, $taxYearId) : [];
        $filteredHistory = $this->filterUploadHistory($historyAll, $filter);
        $historyPage = array_slice($filteredHistory, $offset, self::HISTORY_PAGE_SIZE);
        $mappingView = is_array($selectedUploadPreview['mapping'] ?? null) ? $selectedUploadPreview['mapping'] : [];
        $mappingHeaders = array_values(array_filter(array_map(
            static fn(mixed $header): string => trim((string)$header),
            (array)($selectedUploadPreview['headers'] ?? [])
        ), static fn(string $header): bool => $header !== ''));
        $extraHeaders = [];

        foreach ($mappingView as $field) {
            if (!is_array($field)) {
                continue;
            }

            foreach ((array)($field['extra_headers'] ?? []) as $header) {
                $header = trim((string)$header);
                if ($header !== '') {
                    $extraHeaders[] = $header;
                }
            }
        }

        return [
            'selected_upload_id' => $uploadId,
            'upload_id' => $uploadId,
            'selected_upload_history_filter' => $filter,
            'upload_history_filter' => $filter,
            'selected_upload_history_page' => $page,
            'upload_history_page' => $page,
            'upload_history' => $historyPage,
            'upload_history_total' => count($filteredHistory),
            'upload_history_page_size' => self::HISTORY_PAGE_SIZE,
            'upload_history_has_previous_page' => $page > 1,
            'upload_history_has_next_page' => ($offset + self::HISTORY_PAGE_SIZE) < count($filteredHistory),
            'selected_upload_preview' => $selectedUploadPreview,
            'selected_upload_headers' => $mappingHeaders,
            'selected_upload_mapping_view' => $mappingView,
            'selected_upload_mapping_extra_headers' => array_values(array_unique($extraHeaders)),
            'selected_upload_has_account_mapping' => $mappingView !== [],
            'uploads_auto_switch_tab' => '',
            'developer_options' => false,
            'month_status' => ($companyId > 0 && $taxYearId > 0) ? $repository->buildMonthStatus($companyId, $taxYearId) : [],
        ];
    }

    private function filterUploadHistory(array $history, string $filter): array
    {
        $filter = trim($filter);

        if (!in_array($filter, ['all', 'action_required', 'ready', 'imported'], true)) {
            $filter = 'all';
        }

        if ($filter === 'all') {
            return $history;
        }

        return array_values(array_filter($history, static function (array $row) use ($filter): bool {
            $status = (string)($row['workflow_status'] ?? '');

            return match ($filter) {
                'action_required' => $status === 'uploaded',
                'ready' => in_array($status, ['mapped', 'staged'], true),
                'imported' => $status === 'completed',
                default => true,
            };
        }));
    }
}
