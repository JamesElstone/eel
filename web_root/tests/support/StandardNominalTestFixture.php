<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class StandardNominalTestFixture
{
    private const SUBTYPES = [
        'bank' => ['Bank', 'asset', 10],
        'director_loan_asset' => ['Director Loan Asset', 'asset', 30],
        'director_loan_liability' => ['Director Loan Liability', 'liability', 50],
        'turnover' => ['Turnover', 'income', 100],
        'materials' => ['Materials', 'cost_of_sales', 200],
        'overhead' => ['Overhead Expense', 'expense', 300],
        'fixed_asset' => ['Fixed Asset', 'asset', 35],
        'trade_creditor' => ['Trade Creditor', 'liability', 45],
        'capital_reserves' => ['Capital and Reserves', 'equity', 65],
        'asset_disposal_gain' => ['Asset Disposal Gain', 'income', 420],
        'depreciation_expense' => ['Depreciation Expense', 'expense', 620],
        'asset_disposal_loss' => ['Asset Disposal Loss', 'expense', 621],
        'asset_disposal_clearing' => ['Asset Disposal Clearing', 'asset', 149],
    ];

    private const NOMINALS = [
        '1000' => ['Bank', 'asset', 'bank', 'allowable', 10],
        '1200' => ['Director Loan Asset', 'asset', 'director_loan_asset', 'allowable', 30],
        '1300' => ['Tools & Equipment (FA)', 'asset', 'fixed_asset', 'capital', 130],
        '1330' => ['Accum Dep - Tools', 'asset', 'fixed_asset', 'capital', 133],
        '1490' => ['Asset Disposal Clearing', 'asset', 'asset_disposal_clearing', 'other', 149],
        '2100' => ['Director Loan Liability', 'liability', 'director_loan_liability', 'allowable', 50],
        '3000' => ['Retained Earnings', 'equity', 'capital_reserves', 'other', 66],
        '4000' => ['Sales', 'income', 'turnover', 'allowable', 100],
        '4200' => ['Profit on Disposal', 'income', 'asset_disposal_gain', 'other', 420],
        '5000' => ['Materials', 'cost_of_sales', 'materials', 'allowable', 200],
        '6070' => ['Tools & Small Equipment', 'expense', 'overhead', 'allowable', 370],
        '6200' => ['Depreciation Expense', 'expense', 'depreciation_expense', 'disallowable', 620],
        '6210' => ['Loss on Disposal', 'expense', 'asset_disposal_loss', 'other', 621],
    ];

    public static function ensureSubtypes(array $codes): void
    {
        self::requireTables();

        foreach (array_values(array_unique($codes)) as $code) {
            $definition = self::SUBTYPES[$code] ?? null;
            if (!is_array($definition)) {
                throw new InvalidArgumentException('Unknown standard nominal subtype: ' . $code);
            }

            if (self::subtypeId($code, false) > 0) {
                continue;
            }

            InterfaceDB::prepareExecute(
                'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
                 VALUES (:code, :name, :parent_account_type, :sort_order, 1)',
                [
                    'code' => $code,
                    'name' => (string)$definition[0],
                    'parent_account_type' => (string)$definition[1],
                    'sort_order' => (int)$definition[2],
                ]
            );
        }
    }

    public static function ensureNominals(array $codes): void
    {
        self::requireTables();
        $subtypes = [];

        foreach (array_values(array_unique($codes)) as $code) {
            $definition = self::NOMINALS[$code] ?? null;
            if (!is_array($definition)) {
                throw new InvalidArgumentException('Unknown standard nominal: ' . $code);
            }
            $subtypes[] = (string)$definition[2];
        }

        self::ensureSubtypes($subtypes);

        foreach (array_values(array_unique($codes)) as $code) {
            if (self::id($code, false) > 0) {
                continue;
            }

            $definition = self::NOMINALS[$code];
            InterfaceDB::prepareExecute(
                'INSERT INTO nominal_accounts (
                    code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order
                 ) VALUES (
                    :code, :name, :account_type, :account_subtype_id, :tax_treatment, 1, :sort_order
                 )',
                [
                    'code' => $code,
                    'name' => (string)$definition[0],
                    'account_type' => (string)$definition[1],
                    'account_subtype_id' => self::subtypeId((string)$definition[2]),
                    'tax_treatment' => (string)$definition[3],
                    'sort_order' => (int)$definition[4],
                ]
            );
        }
    }

    public static function id(string $code, bool $required = true): int
    {
        self::requireTables();
        $id = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($required && $id <= 0) {
            throw new RuntimeException('Standard nominal was not seeded: ' . $code);
        }

        return $id;
    }

    public static function subtypeId(string $code, bool $required = true): int
    {
        self::requireTables();
        $id = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($required && $id <= 0) {
            throw new RuntimeException('Standard nominal subtype was not seeded: ' . $code);
        }

        return $id;
    }

    private static function requireTables(): void
    {
        foreach (['nominal_account_subtypes', 'nominal_accounts'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                throw new RuntimeException('Required nominal fixture table is unavailable: ' . $table);
            }
        }
    }
}
