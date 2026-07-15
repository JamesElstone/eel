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

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\PeriodLedgerReadService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PeriodLedgerReadService $service): void {
    $harness->check(\eel_accounts\Service\PeriodLedgerReadService::class, 'rejects an accounting period outside the selected company', static function () use ($harness, $service): void {
        $thrown = false;
        try {
            $service->scope(99999991, 99999992);
        } catch (InvalidArgumentException $exception) {
            $thrown = str_contains($exception->getMessage(), 'could not be found');
        }
        $harness->assertTrue($thrown);
    });

    $harness->check(\eel_accounts\Service\PeriodLedgerReadService::class, 'scopes aggregates filters and request-caches posted ledger rows', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $fixture = periodLedgerTestCreateFixture();
            $scope = $service->scope(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2025-06-30',
                '2025-02-01'
            );
            $harness->assertSame('2025-02-01', $scope->periodStart);
            $harness->assertSame('2025-06-30', $scope->asAtDate);

            $dataset = $service->fetch($scope);
            $harness->assertSame(1, $dataset->journalCount);
            $harness->assertSame(4, count($dataset->rows));

            $rowsByNominal = [];
            foreach ($dataset->rows as $row) {
                $rowsByNominal[(int)($row['nominal_account_id'] ?? 0)] = $row;
            }
            $harness->assertSame('1000.00', number_format((float)($rowsByNominal[(int)$fixture['income_nominal_id']]['total_credit'] ?? 0), 2, '.', ''));
            $harness->assertSame('200.00', number_format((float)($rowsByNominal[(int)$fixture['cost_nominal_id']]['total_debit'] ?? 0), 2, '.', ''));
            $harness->assertSame(false, isset($rowsByNominal[(int)$fixture['asset_nominal_id']]));
            $harness->assertTrue($service->fetch($scope) === $dataset);

            $service->clearRuntimeCache();
            $harness->assertTrue($service->fetch($scope) !== $dataset);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
