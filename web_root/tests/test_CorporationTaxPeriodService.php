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

            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET updated_at = :updated_at
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'updated_at' => '2001-02-03 04:05:06',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $beforeRead = InterfaceDB::fetchAll(
                'SELECT id, status, updated_at
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 ORDER BY sequence_no, id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            $active = (new \eel_accounts\Service\CorporationTaxComputationService())
                ->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertCount(2, (array)($active['periods'] ?? []));
            $harness->assertSame(
                $beforeRead,
                InterfaceDB::fetchAll(
                    'SELECT id, status, updated_at
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     ORDER BY sequence_no, id',
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                )
            );

            $idempotentSync = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($idempotentSync['success'] ?? false));
            $harness->assertSame(
                $beforeRead,
                InterfaceDB::fetchAll(
                    'SELECT id, status, updated_at
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     ORDER BY sequence_no, id',
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                )
            );

            $gate = $service->canSubmit($companyId, (int)$periods[1]['id']);
            $harness->assertSame(false, (bool)($gate['ok'] ?? true));

            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods SET status = :status WHERE id = :id',
                ['status' => 'accepted', 'id' => (int)$periods[0]['id']]
            );

            $gate = $service->canSubmit($companyId, (int)$periods[1]['id']);
            $harness->assertSame(true, (bool)($gate['ok'] ?? false));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxPeriodService::class, 'projects missing statutory periods without mutating metadata', static function () use ($harness, $service): void {
            $companyNumber = 'CTPP' . substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'CT Projection Fixture Limited', 'company_number' => $companyNumber]
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
                    'label' => 'Synthetic long period',
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                ]
            );
            $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                ['company_id' => $companyId, 'label' => 'Synthetic long period']
            );

            $sync = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($sync['success'] ?? false));
            InterfaceDB::prepareExecute(
                'DELETE FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND sequence_no = 2',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );

            $projection = $service->projectForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($projection['success'] ?? false));
            $harness->assertSame(true, (bool)($projection['requires_sync'] ?? false));
            $harness->assertCount(2, (array)($projection['periods'] ?? []));
            $harness->assertTrue((int)($projection['periods'][0]['id'] ?? 0) > 0);
            $harness->assertTrue((int)($projection['periods'][1]['id'] ?? 0) < 0);
            $harness->assertSame('2023-09-05', (string)($projection['periods'][1]['period_start'] ?? ''));
            $harness->assertSame('2023-09-30', (string)($projection['periods'][1]['period_end'] ?? ''));
            $harness->assertSame(
                1,
                (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id',
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                )
            );

            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET period_end = :period_end
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'period_end' => '2023-08-31',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $staleProjection = $service->projectForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($staleProjection['success'] ?? false));
            $harness->assertCount(2, (array)($staleProjection['periods'] ?? []));
            $harness->assertTrue((int)($staleProjection['periods'][0]['id'] ?? 0) < 0);
            $harness->assertTrue((int)($staleProjection['periods'][1]['id'] ?? 0) < 0);

            $repairSync = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($repairSync['success'] ?? false));
            $harness->assertCount(2, (array)($repairSync['periods'] ?? []));
            $harness->assertSame('2023-09-04', (string)($repairSync['periods'][0]['period_end'] ?? ''));
            $harness->assertSame('pending', (string)($repairSync['periods'][0]['status'] ?? ''));

            InterfaceDB::prepareExecute(
                'DELETE FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND sequence_no = 2',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET status = :status
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'status' => 'accepted',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $lockedBefore = InterfaceDB::fetchOne(
                'SELECT id, sequence_no, period_start, period_end, status, updated_at
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND sequence_no = 1',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            $lockedProjection = $service->projectForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($lockedProjection['success'] ?? false));
            $harness->assertCount(2, (array)($lockedProjection['periods'] ?? []));
            $harness->assertTrue((int)($lockedProjection['periods'][0]['id'] ?? 0) > 0);
            $harness->assertTrue((int)($lockedProjection['periods'][1]['id'] ?? 0) < 0);
            $lockedSync = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($lockedSync['success'] ?? false));
            $harness->assertCount(2, (array)($lockedSync['periods'] ?? []));
            $harness->assertSame(
                $lockedBefore,
                InterfaceDB::fetchOne(
                    'SELECT id, sequence_no, period_start, period_end, status, updated_at
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                       AND sequence_no = 1',
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                )
            );

            InterfaceDB::prepareExecute(
                'DELETE FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND sequence_no = 2',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET period_end = :period_end
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND sequence_no = 1',
                [
                    'period_end' => '2023-08-31',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $lockedStaleProjection = $service->projectForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(false, (bool)($lockedStaleProjection['success'] ?? true));
            $harness->assertTrue(str_contains(
                implode(' ', array_map('strval', (array)($lockedStaleProjection['errors'] ?? []))),
                'cannot be repaired automatically'
            ));
            $lockedStaleSync = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(false, (bool)($lockedStaleSync['success'] ?? true));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxPeriodService::class, 'adds cumulative display numbering across accounting periods', static function () use ($harness, $service): void {
            $companyNumber = 'CTPD' . substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'CT Period Display Fixture Limited', 'company_number' => $companyNumber]
            );

            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );

            $accountingPeriods = [
                ['label' => '05/09/2022 to 30/09/2023', 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                ['label' => '01/10/2023 to 30/09/2024', 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
                ['label' => '01/10/2024 to 30/09/2025', 'period_start' => '2024-10-01', 'period_end' => '2025-09-30'],
                ['label' => '01/10/2025 to 30/09/2026', 'period_start' => '2025-10-01', 'period_end' => '2026-09-30'],
            ];
            $accountingPeriodIds = [];
            foreach ($accountingPeriods as $accountingPeriod) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => $accountingPeriod['label'],
                        'period_start' => $accountingPeriod['period_start'],
                        'period_end' => $accountingPeriod['period_end'],
                    ]
                );

                $accountingPeriodIds[] = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods
                     WHERE company_id = :company_id
                       AND period_start = :period_start
                       AND period_end = :period_end',
                    [
                        'company_id' => $companyId,
                        'period_start' => $accountingPeriod['period_start'],
                        'period_end' => $accountingPeriod['period_end'],
                    ]
                );
            }

            foreach ($accountingPeriodIds as $accountingPeriodId) {
                $result = $service->syncForAccountingPeriod($companyId, $accountingPeriodId);
                $harness->assertSame(true, (bool)($result['success'] ?? false));
            }

            $expectedLocalSequences = [[1, 2], [1], [1], [1]];
            $expectedDisplaySequences = [[1, 2], [3], [4], [5]];
            foreach ($accountingPeriodIds as $index => $accountingPeriodId) {
                $periods = $service->fetchForAccountingPeriod($companyId, $accountingPeriodId);
                $harness->assertSame($expectedLocalSequences[$index], array_map(static fn(array $period): int => (int)$period['sequence_no'], $periods));
                $harness->assertSame($expectedDisplaySequences[$index], array_map(static fn(array $period): int => (int)$period['display_sequence_no'], $periods));
                $harness->assertSame(
                    array_map(static fn(int $displaySequence): string => 'CT Period ' . $displaySequence, $expectedDisplaySequences[$index]),
                    array_map(static fn(array $period): string => (string)$period['display_label'], $periods)
                );
            }

            $harness->assertSame(5, $service->displaySequenceNo($companyId, $accountingPeriodIds[3], 1));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxPeriodService::class, 'validates twelve calendar months rather than a fixed 365-day limit', static function () use ($harness, $service): void {
            $leapSpan = $service->validateMaximumPeriodLength('2023-03-01', '2024-02-29');
            $harness->assertTrue((bool)($leapSpan['valid'] ?? false));
            $harness->assertSame(366, (int)($leapSpan['days'] ?? 0));
            $harness->assertSame('2024-02-29', (string)($leapSpan['maximum_end'] ?? ''));

            $tooLong = $service->validateMaximumPeriodLength('2023-03-01', '2024-03-01');
            $harness->assertFalse((bool)($tooLong['valid'] ?? true));
            $harness->assertTrue(str_contains((string)($tooLong['error'] ?? ''), 'exceeds 12 months'));
        });
    }
);
