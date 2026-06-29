<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxPeriodService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CorporationTaxPeriodService $service): void {
        $harness->check(\eel_accounts\Service\CorporationTaxPeriodService::class, 'syncs a long first accounting period into sequential CT periods', static function () use ($harness, $service): void {
            $companyNumber = 'CTP' . substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'CT Period Fixture Limited', 'company_number' => $companyNumber]
            );

            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => '05/09/2022 to 30/09/2023',
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                ]
            );

            $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods
                 WHERE company_id = :company_id
                   AND period_start = :period_start
                   AND period_end = :period_end',
                [
                    'company_id' => $companyId,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                ]
            );

            $result = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($result['success'] ?? false));

            $periods = $service->fetchForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertCount(2, $periods);
            $harness->assertSame(1, (int)$periods[0]['sequence_no']);
            $harness->assertSame('2022-09-05', (string)$periods[0]['period_start']);
            $harness->assertSame('2023-09-04', (string)$periods[0]['period_end']);
            $harness->assertSame(2, (int)$periods[1]['sequence_no']);
            $harness->assertSame('2023-09-05', (string)$periods[1]['period_start']);
            $harness->assertSame('2023-09-30', (string)$periods[1]['period_end']);

            $gate = $service->canSubmit($companyId, (int)$periods[1]['id']);
            $harness->assertSame(false, (bool)($gate['ok'] ?? true));

            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods SET status = :status WHERE id = :id',
                ['status' => 'accepted', 'id' => (int)$periods[0]['id']]
            );

            $gate = $service->canSubmit($companyId, (int)$periods[1]['id']);
            $harness->assertSame(true, (bool)($gate['ok'] ?? false));
        });
    }
);
