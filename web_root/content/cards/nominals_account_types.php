<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_account_typesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_account_types';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function tables(array $context): array
    {
        return [$this->table()];
    }

    public function render(array $context): string
    {
        return $this->table()->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    private function table(): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows())
            ->filename('nominal-account-types')
            ->exportLimit(100)
            ->empty('No nominal account types were found.')
            ->textColumn('account_type', 'Account Type')
            ->textColumn('typical_use', 'Typical Use');
    }

    private function rows(): array
    {
        return [
            [
                'account_type' => 'asset',
                'typical_use' => 'Bank, debtors, fixed assets, and other resources the company owns or controls.',
            ],
            [
                'account_type' => 'liability',
                'typical_use' => 'VAT, loans, tax, creditors, and other obligations the company owes.',
            ],
            [
                'account_type' => 'equity',
                'typical_use' => 'Share capital, reserves, retained profit, and other ownership balances.',
            ],
            [
                'account_type' => 'income',
                'typical_use' => 'Turnover, sales, and other income earned by the business.',
            ],
            [
                'account_type' => 'cost_of_sales',
                'typical_use' => 'Direct costs of delivering work or goods, such as materials and subcontract costs.',
            ],
            [
                'account_type' => 'expense',
                'typical_use' => 'Overheads and operating costs such as software, insurance, motor, and office costs.',
            ],
        ];
    }
}
