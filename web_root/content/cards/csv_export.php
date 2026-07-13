<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _csv_exportCard extends CardBaseFramework
{
    private const PAGE_SIZE = 15;

    public function key(): string
    {
        return 'csv_export';
    }

    public function title(): string
    {
        return 'CSV Export';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'csv_export_months',
                'service' => \eel_accounts\Service\StatementCsvExportService::class,
                'method' => 'fetchExportMonths',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
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

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="helper">Select a company and accounting period before exporting CSV uploads.</div>';
        }

        return $this->configuredTable($context)->render($context);
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'uploads'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination($pagination, 'Uploads', $this->paginationPageField(), $hiddenFields);
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('csv-upload-exports')
            ->exportLimit(5000)
            ->empty('No uploaded CSV files are available for this accounting period.')
            ->textColumn('month_label', 'Month')
            ->textColumn('account_name', 'Account', 'No account selected')
            ->column(
                'export_rows',
                'Rows',
                html: static fn(array $row): string => (string)(int)($row['export_rows'] ?? 0),
                exportType: 'number'
            )
            ->badgeColumn('workflow_status', 'Status')
            ->column(
                'actions',
                'Action',
                html: fn(array $row): string => $this->actionHtml($row, $context),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $rows = [];
        foreach ((array)($context['services']['csv_export_months'] ?? []) as $month) {
            if (!is_array($month)) {
                continue;
            }

            foreach ((array)($month['uploads'] ?? []) as $upload) {
                if (!is_array($upload)) {
                    continue;
                }

                $rows[] = array_merge($upload, [
                    'month_label' => (string)($month['label'] ?? ''),
                    'month_key' => (string)($month['month_key'] ?? ''),
                ]);
            }
        }

        return $rows;
    }

    private function actionHtml(array $row, array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $hiddenInputs = HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Uploads">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="upload_id" value="' . (int)($row['id'] ?? 0) . '">
            <input type="hidden" name="export_month" value="' . HelperFramework::escape((string)($row['month_key'] ?? '')) . '">';

        return '<div class="actions-row actions-row-nowrap">
            <form method="post" action="?page=uploads" class="inline-form">
                ' . $hiddenInputs . '
                <input type="hidden" name="intent" value="export_csv_upload">
                <button class="button primary" type="submit">Export CSV</button>
            </form>
            <form method="post" action="?page=uploads" class="inline-form">
                ' . $hiddenInputs . '
                <input type="hidden" name="intent" value="export_xlsx_upload">
                <button class="button" type="submit">Export XLSX</button>
            </form>
        </div>';
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
