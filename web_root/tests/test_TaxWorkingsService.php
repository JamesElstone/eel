<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();
$harness->run(\eel_accounts\Service\TaxWorkingsService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TaxWorkingsService $service): void {
    $harness->check(\eel_accounts\Service\TaxWorkingsService::class, 'returns unavailable state without selected context', static function () use ($harness, $service): void {
        $result = $service->fetchWorkings(0, 0);

        $harness->assertSame(false, (bool)($result['available'] ?? true));
        $harness->assertTrue(str_contains((string)(($result['errors'] ?? [])[0] ?? ''), 'Select a company'));
    });

    $harness->check(\eel_accounts\Service\TaxGuidanceService::class, 'exposes expected HMRC guidance URLs', static function () use ($harness): void {
        $harness->assertSame('https://www.gov.uk/capital-allowances/annual-investment-allowance', \eel_accounts\Service\TaxGuidanceService::url('aia'));
        $harness->assertSame('https://www.gov.uk/capital-allowances/business-cars', \eel_accounts\Service\TaxGuidanceService::url('business_cars'));
        $harness->assertSame('https://www.gov.uk/guidance/corporation-tax-marginal-relief', \eel_accounts\Service\TaxGuidanceService::url('marginal_relief'));
    });

    $harness->check(\eel_accounts\Service\TaxWorkingsService::class, 'fails closed for a transient split CT period without persisting CT metadata', static function () use ($harness, $service): void {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $accountingPeriodId = 9111;
        $transientCtPeriodId = \eel_accounts\Service\CorporationTaxPeriodService::transientReferenceId(
            $accountingPeriodId,
            2
        );
        InterfaceDB::beginTransaction();
        try {
            InterfaceDB::prepareExecute(
                'DELETE FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $before = InterfaceDB::fetchAll(
                'SELECT *
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                 ORDER BY accounting_period_id, sequence_no, id',
                ['company_id' => $companyId]
            );

            $workings = $service->fetchWorkings(
                $companyId,
                $accountingPeriodId,
                $transientCtPeriodId
            );

            $harness->assertSame(false, (bool)($workings['available'] ?? true));
            $harness->assertTrue(trim((string)(($workings['errors'] ?? [])[0] ?? '')) !== '');
            $harness->assertSame(
                $before,
                InterfaceDB::fetchAll(
                    'SELECT *
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                     ORDER BY accounting_period_id, sequence_no, id',
                    ['company_id' => $companyId]
                )
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(_corporation_tax::class, 'GET context derives and selects transient CT periods without synchronising metadata', static function () use ($harness): void {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $accountingPeriodId = 9111;
        $transientCtPeriodId = \eel_accounts\Service\CorporationTaxPeriodService::transientReferenceId(
            $accountingPeriodId,
            2
        );
        $firstTransientCtPeriodId = \eel_accounts\Service\CorporationTaxPeriodService::transientReferenceId(
            $accountingPeriodId,
            1
        );

        InterfaceDB::beginTransaction();
        try {
            InterfaceDB::prepareExecute(
                'DELETE FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $before = InterfaceDB::fetchAll(
                'SELECT *
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                 ORDER BY accounting_period_id, sequence_no, id',
                ['company_id' => $companyId]
            );

            $page = new _corporation_tax();
            $method = new ReflectionMethod($page, 'moduleContext');
            $method->setAccessible(true);
            $context = $method->invoke(
                $page,
                new RequestFramework(
                    ['ct_period_id' => (string)$transientCtPeriodId],
                    [],
                    ['REQUEST_METHOD' => 'GET'],
                    [],
                    [],
                    null
                ),
                createTestPageServiceFramework(),
                new ActionResultFramework(true),
                ['company' => ['id' => $companyId, 'accounting_period_id' => $accountingPeriodId]]
            );

            $harness->assertCount(2, (array)($context['tax']['ct_periods'] ?? []));
            $harness->assertSame($transientCtPeriodId, (int)($context['tax']['selected_ct_period_id'] ?? 0));
            $harness->assertSame('transient', (string)($context['tax']['selected_ct_period']['status'] ?? ''));
            $harness->assertSame(
                'Showing Tax Period 2: 2023-09-05 to 2023-09-30',
                (string)($context['tax']['selected_ct_period_helper'] ?? '')
            );
            $selectorHtml = (new _tax_period_selectorCard())->render([
                'company' => [
                    'id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ],
                'tax' => (array)($context['tax'] ?? []),
            ]);
            $harness->assertTrue(str_contains(
                $selectorHtml,
                '<option value="' . $firstTransientCtPeriodId . '"'
            ));
            $harness->assertTrue(str_contains(
                $selectorHtml,
                '<option value="' . $transientCtPeriodId . '" selected>'
            ));
            $harness->assertSame(
                $before,
                InterfaceDB::fetchAll(
                    'SELECT *
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                     ORDER BY accounting_period_id, sequence_no, id',
                    ['company_id' => $companyId]
                )
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(TaxAction::class, 'selector action preserves a transient CT period reference', static function () use ($harness): void {
        $accountingPeriodId = 9111;
        $transientCtPeriodId = \eel_accounts\Service\CorporationTaxPeriodService::transientReferenceId(
            $accountingPeriodId,
            2
        );
        $result = (new TaxAction())->handle(
            new RequestFramework(
                [],
                [
                    'company_id' => (string)GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                    'accounting_period_id' => (string)$accountingPeriodId,
                    'ct_period_id' => (string)$transientCtPeriodId,
                    'intent' => 'select_ct_period',
                ],
                ['REQUEST_METHOD' => 'POST'],
                [],
                []
            ),
            createTestPageServiceFramework()
        );

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(
            (string)$transientCtPeriodId,
            (string)($result->query()['ct_period_id'] ?? '')
        );
    });

    $harness->check(_corporation_tax::class, 'read-only LIVE VAT context defaults to the first persisted CT snapshot', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('83' . $marker);
            $accountingPeriodId = (int)('84' . $marker);
            $acceptedCtPeriodId = (int)('85' . $marker);
            $pendingCtPeriodId = (int)('86' . $marker);

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'Historical CT Selection Fixture Limited',
                    'company_number' => 'HCT' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'Historical CT selection',
                    'period_start' => '2024-01-01',
                    'period_end' => '2025-01-31',
                ]
            );
            foreach ([
                [
                    'id' => $acceptedCtPeriodId,
                    'sequence_no' => 1,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                    'status' => 'accepted',
                ],
                [
                    'id' => $pendingCtPeriodId,
                    'sequence_no' => 2,
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-01-31',
                    'status' => 'pending',
                ],
            ] as $period) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_periods (
                        id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
                     ) VALUES (
                        :id, :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status
                     )',
                    [
                        'id' => (int)$period['id'],
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'sequence_no' => (int)$period['sequence_no'],
                        'period_start' => (string)$period['period_start'],
                        'period_end' => (string)$period['period_end'],
                        'status' => (string)$period['status'],
                    ]
                );
            }

            $summary = [
                'available' => true,
                'accounting_profit' => 500.00,
                'estimated_corporation_tax' => 95.00,
                'steps' => [],
                'capital_allowance_breakdown' => [
                    'rows' => [],
                    'asset_calculations' => [],
                ],
                'schedule' => [],
                'ct_rate_bands' => [],
                'warnings' => [],
            ];
            $computationHash = hash('sha256', 'historical-ct-selection-' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO corporation_tax_computation_runs (
                    company_id, accounting_period_id, ct_period_id, period_start, period_end,
                    status, computation_hash, summary_json
                 ) VALUES (
                    :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
                    :status, :computation_hash, :summary_json
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'ct_period_id' => $acceptedCtPeriodId,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                    'status' => 'generated',
                    'computation_hash' => $computationHash,
                    'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                ]
            );
            $runId = (int)InterfaceDB::fetchColumn(
                'SELECT id
                 FROM corporation_tax_computation_runs
                 WHERE company_id = :company_id
                   AND ct_period_id = :ct_period_id
                   AND computation_hash = :computation_hash
                 ORDER BY id DESC
                 LIMIT 1',
                [
                    'company_id' => $companyId,
                    'ct_period_id' => $acceptedCtPeriodId,
                    'computation_hash' => $computationHash,
                ]
            );
            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET latest_computation_run_id = :run_id
                 WHERE id = :id',
                [
                    'run_id' => $runId,
                    'id' => $acceptedCtPeriodId,
                ]
            );

            $page = new _corporation_tax();
            $method = new ReflectionMethod($page, 'moduleContext');
            $method->setAccessible(true);
            $context = $method->invoke(
                $page,
                new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], [], null),
                createTestPageServiceFramework(),
                new ActionResultFramework(true),
                ['company' => ['id' => $companyId, 'accounting_period_id' => $accountingPeriodId]]
            );

            $harness->assertSame(
                $acceptedCtPeriodId,
                (int)($context['tax']['selected_ct_period_id'] ?? 0)
            );
            $harness->assertSame(
                'accepted',
                (string)($context['tax']['selected_ct_period']['status'] ?? '')
            );
            $workings = (new \eel_accounts\Service\TaxWorkingsService())
                ->fetchWorkings($companyId, $accountingPeriodId, $acceptedCtPeriodId);
            $harness->assertSame(true, (bool)($workings['available'] ?? false));
            $harness->assertSame(true, (bool)($workings['historical_snapshot_only'] ?? false));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
