<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_claimantsCard extends CardBaseFramework
{
    private const PAGE_SIZE = 10;
    private const STATUS_FILTER_FIELD = 'expense_claimants_status';

    public function key(): string
    {
        return 'expense_claimants';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expensesPageData',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company.id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Existing Claimants';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext[$this->key()]['status_filter'] = $this->normaliseStatusFilter((string)$request->input(
            self::STATUS_FILTER_FIELD,
            (string)(($pageContext[$this->key()] ?? [])['status_filter'] ?? 'all')
        ));

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
                self::STATUS_FILTER_FIELD => $this->selectedStatusFilter($context),
            ]);
    }

    private function configuredTable(array $context): TableFramework
    {
        $statusFilter = $this->selectedStatusFilter($context);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'expenses'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            self::STATUS_FILTER_FIELD => $statusFilter,
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Expense Claimants',
                $this->paginationPageField(),
                $hiddenFields
            )
            ->filterSelect(
                self::STATUS_FILTER_FIELD,
                'Show',
                $this->statusFilterOptions(),
                $statusFilter,
                [
                    'page' => (string)($context['page']['page_id'] ?? 'expenses'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('expense-claimants')
            ->empty($this->hasCompany($context)
                ? 'No claimants configured yet. Add one below to enable claim creation.'
                : 'No company is selected.')
            ->textColumn('claimant_name', 'Claimant')
            ->column(
                'is_active',
                'Status',
                html: fn(array $row): string => $this->statusHtml($row),
                export: fn(array $row): string => $this->statusLabel($row)
            )
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->actionForm($row, $context),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $data = (array)($context['services']['expensesPageData'] ?? []);
        $statusFilter = $this->selectedStatusFilter($context);
        $claimants = array_values(array_filter(
            (array)($data['claimants'] ?? []),
            static fn(mixed $claimant): bool => is_array($claimant)
        ));

        if ($statusFilter === 'all') {
            return $claimants;
        }

        return array_values(array_filter(
            $claimants,
            fn(array $claimant): bool => $this->isActive($claimant) === ($statusFilter === 'active')
        ));
    }

    private function actionForm(array $claimant, array $context): string
    {
        $companyId = (int)(($context['company'] ?? [])['id'] ?? 0);
        $claimantId = (int)($claimant['id'] ?? 0);
        $isActive = $this->isActive($claimant);
        $intent = $isActive ? 'deactivate_claimant' : 'activate_claimant';
        $label = $isActive ? 'Deactivate' : 'Activate';

        if ($companyId <= 0 || $claimantId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=expenses" data-ajax="true" class="actions-row actions-row-nowrap">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="claimant_id" value="' . $claimantId . '">
            <button class="button button-inline" type="submit" name="intent" value="' . HelperFramework::escape($intent) . '">' . HelperFramework::escape($label) . '</button>
            <button class="button button-inline" type="submit" name="intent" value="filter_claims" data-page-card-switch-tab="Claims">Claims</button>
        </form>';
    }

    private function statusHtml(array $claimant): string
    {
        $isActive = $this->isActive($claimant);

        return '<span class="badge ' . ($isActive ? 'success' : 'warning') . '">' . HelperFramework::escape($this->statusLabel($claimant)) . '</span>';
    }

    private function statusLabel(array $claimant): string
    {
        return $this->isActive($claimant) ? 'Active' : 'Inactive';
    }

    private function isActive(array $claimant): bool
    {
        return (int)($claimant['is_active'] ?? 0) === 1;
    }

    private function hasCompany(array $context): bool
    {
        return (int)(($context['company'] ?? [])['id'] ?? 0) > 0;
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
            'all' => 'All',
            'active' => 'Active',
            'inactive' => 'Inactive',
        ];
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'expense.claimants');
    }
}
