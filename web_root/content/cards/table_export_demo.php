<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _table_export_demoCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'table_export_demo';
    }

    public function title(): string
    {
        return 'Table Export Demo';
    }

    public function helper(array $context): string
    {
        return 'A shared table definition renders paginated rows on screen while CSV and XLSX export the full dataset.';
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext = $this->applyTableSortContext($request, $pageContext, 'test_table_export_demo');
        $pageContext[$this->key()]['status_filter'] = $this->normaliseStatusFilter((string)$request->input(
            'table_export_demo_status',
            (string)($pageContext[$this->key()]['status_filter'] ?? 'all')
        ));

        return $pageContext;
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
            'table_export_demo_status' => $this->selectedStatusFilter($context),
        ]);
    }

    private function configuredTable(array $context): TableFramework
    {
        $statusFilter = $this->selectedStatusFilter($context);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'test'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'table_export_demo_status' => $statusFilter,
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $rows = $table->sortedRows();
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Demo records',
                $this->paginationPageField(),
                $hiddenFields
            )
            ->filterSelect(
                'table_export_demo_status',
                'Status',
                $this->statusFilterOptions(),
                $statusFilter,
                [
                    'page' => (string)($context['page']['page_id'] ?? 'test'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'page.reload');
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make('test_table_export_demo', $this->filteredRows($context, $this->selectedStatusFilter($context)))
            ->filename('test-table-export-demo')
            ->exportLimit(1000)
            ->empty('No demo records were found.')
            ->column('created_at', 'Created')
            ->column('owner', 'Owner')
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => '<span class="badge ' . HelperFramework::escape($this->statusBadgeClass((string)($row['status'] ?? ''))) . '">'
                    . HelperFramework::escape((string)($row['status'] ?? ''))
                    . '</span>'
            )
            ->column(
                'amount',
                'Amount',
                html: fn(array $row): string => '&pound;' . HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)),
                export: fn(array $row): string => FormattingFramework::money($row['amount'] ?? 0),
                cellClass: 'cell-fit',
                exportType: 'number'
            )
            ->column('note', 'Note')
            ->column(
                'action',
                'Screen Only',
                html: fn(array $row): string => '<button class="button disabled" type="button" aria-disabled="true">Review</button>',
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function selectedStatusFilter(array $context): string
    {
        return $this->normaliseStatusFilter((string)(($context[$this->key()] ?? [])['status_filter'] ?? 'all'));
    }

    private function normaliseStatusFilter(string $status): string
    {
        $status = strtolower(trim($status));

        return array_key_exists($status, $this->statusFilterOptions()) ? $status : 'all';
    }

    private function statusFilterOptions(): array
    {
        return [
            'all' => 'All statuses',
            'ready' => 'Ready',
            'review' => 'Review',
            'complete' => 'Complete',
            'blocked' => 'Blocked',
        ];
    }

    private function statusBadgeClass(string $status): string
    {
        return match (strtolower(trim($status))) {
            'complete' => 'success',
            'blocked' => 'danger',
            'review' => 'warning',
            default => 'info',
        };
    }

    private function rows(array $context): array
    {
        $shared = (array)(($context['test.context'] ?? [])['shared_demo_context'] ?? []);
        $preset = ucfirst((string)($shared['preset'] ?? 'alpha'));
        $rows = [];

        foreach (range(1, 12) as $index) {
            $rows[] = [
                'created_at' => '2026-05-' . str_pad((string)(($index % 9) + 1), 2, '0', STR_PAD_LEFT) . ' 09:' . str_pad((string)(10 + $index), 2, '0', STR_PAD_LEFT),
                'owner' => $preset . ' Owner ' . $index,
                'status' => ['Ready', 'Review', 'Complete', 'Blocked'][$index % 4],
                'amount' => 125 + ($index * 18.75),
                'note' => 'Export row ' . $index . ' from the unpaginated demo dataset.',
            ];
        }

        return $rows;
    }

    private function filteredRows(array $context, string $statusFilter): array
    {
        $statusFilter = $this->normaliseStatusFilter($statusFilter);
        if ($statusFilter === 'all') {
            return $this->rows($context);
        }

        return array_values(array_filter(
            $this->rows($context),
            static fn(array $row): bool => strtolower((string)($row['status'] ?? '')) === $statusFilter
        ));
    }
}
