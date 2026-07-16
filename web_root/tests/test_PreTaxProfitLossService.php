<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PeriodLedgerTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\PreTaxProfitLossService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PreTaxProfitLossService $service): void {
    $harness->check(\eel_accounts\Service\PreTaxProfitLossService::class, 'calculates scoped pre-tax profit and tax-treatment add-backs from the ledger', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $fixture = periodLedgerTestCreateFixture();
            $result = $service->calculate(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2025-06-30'
            );

            $harness->assertSame('1000.00', number_format((float)($result['income_total'] ?? 0), 2, '.', ''));
            $harness->assertSame('200.00', number_format((float)($result['cost_of_sales_total'] ?? 0), 2, '.', ''));
            $harness->assertSame('800.00', number_format((float)($result['gross_profit'] ?? 0), 2, '.', ''));
            $harness->assertSame('150.00', number_format((float)($result['operating_expense_total'] ?? 0), 2, '.', ''));
            $harness->assertSame('650.00', number_format((float)($result['profit_before_tax'] ?? 0), 2, '.', ''));
            $harness->assertSame('50.00', number_format((float)($result['disallowable_add_backs'] ?? 0), 2, '.', ''));
            $harness->assertSame(1, (int)($result['journal_count'] ?? 0));
            $harness->assertTrue(($result['scope'] ?? null) instanceof \eel_accounts\Service\PeriodLedgerScope);
            $harness->assertTrue(($result['dataset'] ?? null) instanceof \eel_accounts\Service\PeriodLedgerDataset);
            $harness->assertSame($result, $service->calculate((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2025-06-30'));

            $service->clearRuntimeCache();
            $recalculated = $service->calculate((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2025-06-30');
            $harness->assertSame('650.00', number_format((float)($recalculated['profit_before_tax'] ?? 0), 2, '.', ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\PreTaxProfitLossService::class, 'nets expense credits against signed disallowable add-backs', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            $fixture = periodLedgerTestCreateFixture();
            periodLedgerTestInsertJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2025-04-15',
                'period-ledger-disallowable-reversal-' . (string)$fixture['accounting_period_id'],
                [
                    [(int)$fixture['asset_nominal_id'], 30.0, 0.0],
                    [(int)$fixture['disallowable_nominal_id'], 0.0, 30.0],
                ]
            );

            $result = (new \eel_accounts\Service\PreTaxProfitLossService())
                ->calculate(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    '2025-06-30',
                    null,
                    ['success' => true, 'rows' => []],
                    []
                );

            $harness->assertSame('680.00', number_format((float)($result['profit_before_tax'] ?? 0), 2, '.', ''));
            $harness->assertSame('20.00', number_format((float)($result['disallowable_add_backs'] ?? 0), 2, '.', ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
