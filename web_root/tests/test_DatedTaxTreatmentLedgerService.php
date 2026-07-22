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

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\DatedTaxTreatmentLedgerService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\DatedTaxTreatmentLedgerService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\DatedTaxTreatmentLedgerService::class,
            'preserves actual journal dates and separates disposal-source income',
            static function () use ($harness, $service): void {
                InterfaceDB::beginTransaction();
                try {
                    $fixture = periodLedgerTestCreateFixture();
                    $companyId = (int)$fixture['company_id'];
                    $accountingPeriodId = (int)$fixture['accounting_period_id'];
                    $incomeNominalId = (int)$fixture['income_nominal_id'];
                    $assetNominalId = (int)$fixture['asset_nominal_id'];
                    $suffix = (string)$accountingPeriodId;

                    periodLedgerTestInsertJournal(
                        $companyId,
                        $accountingPeriodId,
                        '2025-04-15',
                        'dated-ledger-ordinary-' . $suffix,
                        [
                            [$assetNominalId, 40.0, 0.0],
                            [$incomeNominalId, 0.0, 40.0],
                        ]
                    );
                    $disposalJournalId = periodLedgerTestInsertJournal(
                        $companyId,
                        $accountingPeriodId,
                        '2025-04-15',
                        'dated-ledger-disposal-' . $suffix,
                        [
                            [$assetNominalId, 60.0, 0.0],
                            [$incomeNominalId, 0.0, 60.0],
                        ],
                        'asset_disposal'
                    );

                    $scope = (new \eel_accounts\Service\PeriodLedgerReadService())->scope(
                        $companyId,
                        $accountingPeriodId,
                        '2025-04-30',
                        '2025-04-01'
                    );
                    $rows = array_values(array_filter(
                        $service->fetch($scope),
                        static fn(array $row): bool =>
                            (int)($row['nominal_account_id'] ?? 0) === $incomeNominalId
                    ));

                    $harness->assertCount(2, $rows);
                    $harness->assertSame('2025-04-15', (string)($rows[0]['journal_date'] ?? ''));
                    $harness->assertSame('', (string)($rows[0]['journal_source_type'] ?? ''));
                    $harness->assertSame('40.00', number_format((float)($rows[0]['total_credit'] ?? 0), 2, '.', ''));
                    $harness->assertSame('asset_disposal', (string)($rows[1]['journal_source_type'] ?? ''));
                    $harness->assertSame('60.00', number_format((float)($rows[1]['total_credit'] ?? 0), 2, '.', ''));
                } finally {
                    if (InterfaceDB::inTransaction()) {
                        InterfaceDB::rollBack();
                    }
                }
            }
        );
    }
);
