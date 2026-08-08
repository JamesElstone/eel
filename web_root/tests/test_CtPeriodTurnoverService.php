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
    \eel_accounts\Service\CtPeriodTurnoverService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\CtPeriodTurnoverService $service
    ): void {
        $h->check($service::class, 'assigns the AP79 whole-pound residual to the shortest CT period', static function () use ($h, $service): void {
            $method = new ReflectionMethod($service, 'applyBox145Rounding');
            $method->setAccessible(true);
            $result = $method->invoke($service, [
                [
                    'ct_period_id' => 6,
                    'sequence_no' => 1,
                    'period_end' => '2023-09-04',
                    'inclusive_days' => 365,
                    'actual_turnover_pence' => 939364,
                    'ct600_box_145_whole_pounds' => 9394,
                    'ct600_rounding_adjustment_whole_pounds' => 0,
                ],
                [
                    'ct_period_id' => 7,
                    'sequence_no' => 2,
                    'period_end' => '2023-09-30',
                    'inclusive_days' => 26,
                    'actual_turnover_pence' => 63180,
                    'ct600_box_145_whole_pounds' => 632,
                    'ct600_rounding_adjustment_whole_pounds' => 0,
                ],
            ], 1002544);

            $periods = (array)$result['periods'];
            $h->assertSame(9394, (int)$periods[0]['ct600_box_145_whole_pounds']);
            $h->assertSame(631, (int)$periods[1]['ct600_box_145_whole_pounds']);
            $h->assertSame(-1, (int)$periods[1]['ct600_rounding_adjustment_whole_pounds']);
            $h->assertSame(true, (bool)$periods[1]['handles_ct600_rounding_residual']);
            $h->assertSame(10025, array_sum(array_column($periods, 'ct600_box_145_whole_pounds')));
        });

        $h->check($service::class, 'uses the latest period as a deterministic tie-break for equal lengths', static function () use ($h, $service): void {
            $method = new ReflectionMethod($service, 'applyBox145Rounding');
            $method->setAccessible(true);
            $result = $method->invoke($service, [
                [
                    'ct_period_id' => 10, 'sequence_no' => 1, 'period_end' => '2025-06-30',
                    'inclusive_days' => 100, 'actual_turnover_pence' => 150,
                    'ct600_box_145_whole_pounds' => 2, 'ct600_rounding_adjustment_whole_pounds' => 0,
                ],
                [
                    'ct_period_id' => 11, 'sequence_no' => 2, 'period_end' => '2025-10-08',
                    'inclusive_days' => 100, 'actual_turnover_pence' => 150,
                    'ct600_box_145_whole_pounds' => 2, 'ct600_rounding_adjustment_whole_pounds' => 0,
                ],
            ], 300);

            $periods = (array)$result['periods'];
            $h->assertSame(2, (int)$periods[0]['ct600_box_145_whole_pounds']);
            $h->assertSame(1, (int)$periods[1]['ct600_box_145_whole_pounds']);
            $h->assertSame(true, (bool)$periods[1]['handles_ct600_rounding_residual']);
        });

        $h->check($service::class, 'classifies statutory turnover separately from other income', static function () use ($h): void {
            $classifier = new \eel_accounts\Service\IncomeStatementClassificationService();
            $h->assertSame('turnover', $classifier->incomeBucket([
                'account_subtype_code' => 'turnover', 'name' => 'Sales',
            ]));
            $h->assertSame('other_income', $classifier->incomeBucket([
                'account_subtype_code' => 'interest_income', 'name' => 'Bank interest',
            ]));
            $h->assertSame('other_income', $classifier->incomeBucket([
                'name' => 'Profit on asset disposal',
            ]));
        });

        $h->check($service::class, 'classifies subcontractor costs by subtype or nominal name', static function () use ($h): void {
            $classifier = new \eel_accounts\Service\IncomeStatementClassificationService();
            $h->assertSame(true, $classifier->isSubcontractorCost([
                'account_subtype_code' => 'subcontractor_labour',
                'name' => 'Direct labour',
            ]));
            $h->assertSame(true, $classifier->isSubcontractorCost([
                'name' => 'Electrical subcontracting',
            ]));
            $h->assertSame(true, $classifier->isSubcontractorCost([
                'name' => 'Sub-contractor labour',
            ]));
            $h->assertSame(false, $classifier->isSubcontractorCost([
                'name' => 'Materials and consumables',
            ]));
        });

        $h->check($service::class, 'derives the reconciled AP79 turnover from the posted ledger', static function () use ($h, $service): void {
            $h->assertTrue(\InterfaceDB::tableExists('corporation_tax_periods'));

            \InterfaceDB::beginTransaction();
            $marker = strtoupper(substr(hash('sha256', __FILE__ . microtime(true)), 0, 10));
            $companyNumber = 'CTT' . $marker;
            \InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                [
                    'company_name' => 'CT Turnover Fixture ' . $marker,
                    'company_number' => $companyNumber,
                ]
            );
            $companyId = (int)\InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );
            $h->assertTrue($companyId > 0);

            \InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'AP79 turnover fixture',
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                ]
            );
            $accountingPeriodId = (int)\InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId]
            );
            $h->assertTrue($accountingPeriodId > 0);

            $turnoverNominalId = periodLedgerTestInsertNominal(
                'CTI' . $marker,
                'Turnover ' . $marker,
                'income',
                'allowable'
            );
            $bankNominalId = periodLedgerTestInsertNominal(
                'CTA' . $marker,
                'Bank ' . $marker,
                'asset',
                'other'
            );
            periodLedgerTestInsertJournal(
                $companyId,
                $accountingPeriodId,
                '2023-09-04',
                'ct-turnover-first-' . $marker,
                [
                    [$bankNominalId, 9393.64, 0.0],
                    [$turnoverNominalId, 0.0, 9393.64],
                ]
            );
            periodLedgerTestInsertJournal(
                $companyId,
                $accountingPeriodId,
                '2023-09-30',
                'ct-turnover-second-' . $marker,
                [
                    [$bankNominalId, 631.80, 0.0],
                    [$turnoverNominalId, 0.0, 631.80],
                ]
            );

            $synced = (new \eel_accounts\Service\CorporationTaxPeriodService())
                ->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $h->assertSame(true, (bool)($synced['success'] ?? false));
            $periods = (array)($synced['periods'] ?? []);
            $h->assertCount(2, $periods);

            $result = $service->fetch($companyId, $accountingPeriodId, $periods);
            $facts = (array)($result['periods'] ?? []);

            $h->assertSame(true, (bool)($result['available'] ?? false));
            $h->assertSame(10025.44, (float)$result['accounting_period_turnover']);
            $h->assertSame(9393.64, (float)$facts[0]['actual_turnover']);
            $h->assertSame(631.80, (float)$facts[1]['actual_turnover']);
            $h->assertSame(9394, (int)$facts[0]['ct600_box_145_whole_pounds']);
            $h->assertSame(631, (int)$facts[1]['ct600_box_145_whole_pounds']);
            $h->assertSame((int)$periods[1]['id'], (int)$result['rounding_residual_ct_period_id']);
        });
    }
);
