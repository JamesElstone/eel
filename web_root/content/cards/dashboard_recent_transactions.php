<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_recent_transactionsCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'dashboard_recent_transactions';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'recent_transactions',
                'service' => \eel_accounts\Repository\DashboardRepository::class,
                'method' => 'fetchRecentTransactions',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'recentLimit' => 100,
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
        return [$this->table($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $pagination = HelperFramework::paginateArray($this->rows($context), $this->paginationPage($context), self::PAGE_SIZE);

        return $this->table($context)
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Recent transactions',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('dashboard-recent-transactions')
            ->exportLimit(100)
            ->empty('No recent transactions are available.')
            ->textColumn('date', 'Date')
            ->textColumn('account', 'Account')
            ->textColumn('description', 'Description')
            ->textColumn('category', 'Category')
            ->column(
                'amount',
                'Amount',
                html: static function (array $row): string {
                    $amount = (float)($row['amount'] ?? 0);

                    return HelperFramework::escape(FormattingFramework::money($amount));
                },
                export: static fn(array $row): string => FormattingFramework::money((float)($row['amount'] ?? 0)),
                cellClass: 'numeric'
            )
            ->badgeColumn(
                'status',
                'Status',
                badgeClassFormatter: fn(array $row): string => $this->statusClass((string)($row['status'] ?? ''))
            );
    }

    private function rows(array $context): array
    {
        $services = (array)($context['services'] ?? []);
        $dashboardData = (array)($services['dashboard_data'] ?? []);

        return array_values(array_filter(
            (array)($services['recent_transactions'] ?? ($dashboardData['recent_transactions'] ?? (($context['page'] ?? [])['recent_transactions'] ?? []))),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            'Matched' => 'success',
            'Needs review' => 'warning',
            default => 'info',
        };
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

}
