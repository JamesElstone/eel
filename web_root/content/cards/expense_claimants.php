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
        return 'Expense Claimants';
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
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $hasCompany = $companyId > 0;
        $addDisabled = $hasCompany ? '' : ' disabled';
        $addHelper = $hasCompany
            ? ''
            : 'Select or add a company before configuring expense claimants.';
        $addHelperHtml = $addHelper === ''
            ? ''
            : '<div class="helper">' . HelperFramework::escape($addHelper) . '</div>';

        return '<section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">Claimants</h3>
            </div>
            ' . $addHelperHtml . '
            ' . $this->configuredTable($context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]) . '
        </section>
        <section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">New Claimants</h3>
            </div>
            <form class="expense-claimant-add-form" method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="add_claimant">
                <div class="mini-field">
                    <label for="expense-new-claimant">New claimant</label>
                    <input class="input" id="expense-new-claimant" name="claimant_name" type="text" placeholder="Claimant\'s Name"' . $addDisabled . '>
                </div>
                <button class="button primary" type="submit"' . $addDisabled . '>Add Claimant</button>
            </form>
        </section>';
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'expenses'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Expense claimants',
                $this->paginationPageField(),
                $hiddenFields
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

        return array_values(array_filter(
            (array)($data['claimants'] ?? []),
            static fn(mixed $claimant): bool => is_array($claimant)
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

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'expense.claimants');
    }
}
