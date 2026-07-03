<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _company_minutesCard extends CardBaseFramework
{
    private const PAGE_SIZE = 25;

    public function key(): string
    {
        return 'company_minutes';
    }

    public function title(): string
    {
        return 'Company Minutes';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'company_minutes',
                'service' => \eel_accounts\Service\CompanyMinutesService::class,
                'method' => 'listMinutes',
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
        return $this->configuredTable($context)->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? ''),
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
                'Minutes',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('company-minutes')
            ->exportLimit(500)
            ->empty('No company minutes are recorded for the selected accounting period.')
            ->textColumn('date', 'Date', exportType: 'date')
            ->column(
                'minutes',
                'Minutes',
                html: static fn(array $row): string => '<pre class="helper">' . HelperFramework::escape((string)($row['minutes'] ?? '')) . '</pre>',
                export: static fn(array $row): string => (string)($row['minutes'] ?? ''),
                sort: true
            );
    }

    private function rows(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['company_minutes'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
