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

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenAccountingCardAuditDefects', 'includes an approved preview-only prepayment in P and L, tax, and balance-sheet cards', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $profitLoss = new \eel_accounts\Service\ProfitLossService();
        $snapshot = new \eel_accounts\Service\CompaniesHouseSnapshotService();
        $baselineAp80 = $profitLoss->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112);
        $baselineAp81 = $profitLoss->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113);
        $baselineChAp80 = goldenCardAuditFields($snapshot->fetchSnapshot(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112));

        goldenCardAuditSeedAnnualPrepayment(false);

        $scheduleService = new \eel_accounts\Service\PrepaymentScheduleService();
        $harness->assertTrue(goldenCardAuditHasPreviewOnlyReview($scheduleService->fetchPeriodContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112), 99003));
        $harness->assertTrue(goldenCardAuditHasPreviewOnlyReview($scheduleService->fetchPeriodContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113), 99003));

        // Independent inclusive-day allocation of GBP366 over 366 service days:
        // AP9112 consumes 259 days and AP9113 consumes the remaining 107 days.
        $expectedAp80Profit = round((float)$baselineAp80['profit_before_tax'] - 259.00, 2);
        $expectedAp81Profit = round((float)$baselineAp81['profit_before_tax'] - 107.00, 2);
        $expectedAp80CurrentAssets = round((float)$baselineChAp80['current_assets'] - 366.00, 2);
        $expectedAp80Prepayments = round((float)$baselineChAp80['prepayments_accrued_income'] + 107.00, 2);
        $expectedAp80NetCurrent = round((float)$baselineChAp80['net_current_assets_liabilities'] - 259.00, 2);

        $actualProfitLoss = new \eel_accounts\Service\ProfitLossService();
        $actualAp80 = $actualProfitLoss->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112);
        $actualAp81 = $actualProfitLoss->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113);
        $actualTaxAp80 = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112, 0);
        $actualTaxAp81 = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113, 0);
        $actualChAp80 = goldenCardAuditFields($snapshot->fetchSnapshot(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112));

        $harness->assertSame(
            [
                goldenCardAuditMoney($expectedAp80Profit),
                goldenCardAuditMoney($expectedAp81Profit),
                goldenCardAuditMoney($expectedAp80Profit),
                goldenCardAuditMoney($expectedAp81Profit),
                goldenCardAuditMoney($expectedAp80CurrentAssets),
                goldenCardAuditMoney($expectedAp80Prepayments),
                goldenCardAuditMoney($expectedAp80NetCurrent),
            ],
            [
                goldenCardAuditMoney($actualAp80['profit_before_tax'] ?? 0),
                goldenCardAuditMoney($actualAp81['profit_before_tax'] ?? 0),
                goldenCardAuditMoney(($actualTaxAp80['summary'] ?? [])['accounting_profit'] ?? 0),
                goldenCardAuditMoney(($actualTaxAp81['summary'] ?? [])['accounting_profit'] ?? 0),
                goldenCardAuditMoney($actualChAp80['current_assets'] ?? 0),
                goldenCardAuditMoney($actualChAp80['prepayments_accrued_income'] ?? 0),
                goldenCardAuditMoney($actualChAp80['net_current_assets_liabilities'] ?? 0),
            ]
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'uses the same pending prepayment profit in retained earnings as the P and L summary', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        goldenCardAuditSeedAnnualPrepayment(true);

        $profitLoss = (new \eel_accounts\Service\ProfitLossService())
            ->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112);
        $retained = (new \eel_accounts\Service\RetainedEarningsCloseService())
            ->fetchContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112);

        $harness->assertSame(
            goldenCardAuditMoney($profitLoss['profit_before_tax'] ?? 0),
            goldenCardAuditMoney(($retained['summary'] ?? [])['current_profit_loss'] ?? 0)
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'uses pending accumulated depreciation when a fully depreciated asset is disposed', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $lossNominalId = goldenCardAuditEnsureNominal(99030, '6210', 'Golden Test Loss on Disposal', 'expense', 'allowable');
        goldenCardAuditPostJournal(
            99020,
            9111,
            '2022-10-01',
            'bank_csv',
            'transaction:golden-audit-asset',
            91013,
            91001,
            1200.00,
            'Golden audit asset available for use on purchase'
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO asset_register (
                id, company_id, asset_code, description, category, nominal_account_id,
                accum_dep_nominal_id, purchase_date, cost, useful_life_years,
                depreciation_method, residual_value, status, linked_journal_id
             ) VALUES (
                :id, :company_id, :asset_code, :description, :category, :nominal_account_id,
                :accum_dep_nominal_id, :purchase_date, :cost, :useful_life_years,
                :depreciation_method, :residual_value, :status, :linked_journal_id
             )',
            [
                'id' => 99021,
                'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                'asset_code' => 'GOLDEN-AUDIT-FULLY-DEPRECIATED',
                'description' => 'Golden audit one-year-life asset',
                'category' => 'tools_equipment',
                'nominal_account_id' => 91013,
                'accum_dep_nominal_id' => 91014,
                'purchase_date' => '2022-10-01',
                'cost' => 1200.00,
                'useful_life_years' => 1,
                'depreciation_method' => 'straight_line',
                'residual_value' => 0.00,
                'status' => 'active',
                'linked_journal_id' => 99020,
            ]
        );

        $disposed = (new \eel_accounts\Service\AssetService())->disposeAssetAtNilValue(
            GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            99021,
            '2023-09-30',
            'scrapped_no_proceeds',
            'Golden audit disposal at the end of the one-year useful life.'
        );
        $harness->assertTrue(!empty($disposed['success']));

        $loss = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.source_ref = :source_ref
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                'source_ref' => 'asset:99021:disposal',
                'nominal_account_id' => $lossNominalId,
            ]
        );
        $accumulatedDepreciationRemoved = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.source_ref = :source_ref
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                'source_ref' => 'asset:99021:disposal',
                'nominal_account_id' => 91014,
            ]
        );

        $harness->assertSame(['0.00', '1200.00'], [
            goldenCardAuditMoney($loss),
            goldenCardAuditMoney($accumulatedDepreciationRemoved),
        ]);
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'applies one coherent straight-line day basis across a useful life', static function () use ($harness): void {
    $preview = (new \eel_accounts\Service\AssetService())
        ->previewDepreciationRun(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112);
    $vanRow = null;
    foreach ((array)($preview['rows'] ?? []) as $row) {
        if ((int)($row['asset_id'] ?? 0) === 9254) {
            $vanRow = $row;
            break;
        }
    }
    $harness->assertTrue(is_array($vanRow));

    // Exact useful-life oracle: GBP9,000 over 1,096 inclusive days, with 356
    // days consumed from 11 October 2023 through 30 September 2024.
    $expected = round(9000.00 * (356 / 1096), 2);
    $harness->assertSame(goldenCardAuditMoney($expected), goldenCardAuditMoney($vanRow['amount'] ?? 0));
});

$harness->check('GoldenAccountingCardAuditDefects', 'includes completed split transactions in the bank-to-ledger validation', static function () use ($harness): void {
    $validation = (new \eel_accounts\Service\TrialBalanceValidationService())
        ->fetchValidation(GoldenAccountsFixture::COMPLETE_COMPANY_ID, 9411);
    $check = goldenCardAuditValidationCheck($validation, 'bank_ledger_reasonableness');
    $metric = (array)($check['metric_value'] ?? []);

    $harness->assertSame(
        ['pass', '-525.00', '-525.00', '0.00'],
        [
            (string)($check['status'] ?? ''),
            goldenCardAuditMoney($metric['transaction_movement'] ?? 0),
            goldenCardAuditMoney($metric['ledger_movement'] ?? 0),
            goldenCardAuditMoney($metric['difference'] ?? 0),
        ]
    );
});

$harness->check('GoldenAccountingCardAuditDefects', 'reconciles pending depreciation detail rows to the tax summary add-back', static function () use ($harness): void {
    $workings = (new \eel_accounts\Service\TaxWorkingsService())
        ->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111, 0);
    $rows = (array)($workings['depreciation_add_back'] ?? []);
    $detailTotal = array_sum(array_map(
        static fn(array $row): float => (float)($row['amount'] ?? 0),
        $rows
    ));

    $harness->assertSame(
        [3, goldenCardAuditMoney(($workings['summary'] ?? [])['depreciation_add_back'] ?? 0)],
        [count($rows), goldenCardAuditMoney($detailTotal)]
    );
});

$harness->check('GoldenAccountingCardAuditDefects', 'reconciles split CT-period depreciation pennies to each tax summary', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
    $sync = $periodService->syncForAccountingPeriod($companyId, 9111);
    $harness->assertTrue((bool)($sync['success'] ?? false));
    test_confirm_ct_period_facts($companyId, 9111);
    $periods = array_values(array_filter(
        (array)($sync['periods'] ?? []),
        static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
    ));
    $harness->assertCount(2, $periods);

    $detailAcrossPeriods = 0.0;
    $summaryAcrossPeriods = 0.0;
    foreach ($periods as $period) {
        $workings = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings($companyId, 9111, (int)($period['id'] ?? 0));
        $detail = round(array_sum(array_map(
            static fn(array $row): float => (float)($row['amount'] ?? 0),
            (array)($workings['depreciation_add_back'] ?? [])
        )), 2);
        $summary = round((float)(($workings['summary'] ?? [])['depreciation_add_back'] ?? 0), 2);
        $harness->assertSame(goldenCardAuditMoney($summary), goldenCardAuditMoney($detail));
        $detailAcrossPeriods = round($detailAcrossPeriods + $detail, 2);
        $summaryAcrossPeriods = round($summaryAcrossPeriods + $summary, 2);
    }

    $full = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings($companyId, 9111, 0);
    $harness->assertSame(
        [
            goldenCardAuditMoney(($full['summary'] ?? [])['depreciation_add_back'] ?? 0),
            goldenCardAuditMoney(($full['summary'] ?? [])['depreciation_add_back'] ?? 0),
        ],
        [
            goldenCardAuditMoney($detailAcrossPeriods),
            goldenCardAuditMoney($summaryAcrossPeriods),
        ]
    );
});

$harness->check('GoldenAccountingCardAuditDefects', 'keeps signed disallowable and capital detail separate while reconciling split CT periods', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $accountingPeriodId = 9111;
        $before = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings($companyId, $accountingPeriodId, 0);
        $disallowableNominalId = goldenCardAuditEnsureNominal(
            99101,
            'GOLD-DIS-SIGNED',
            'Golden signed disallowable movement',
            'expense',
            'disallowable'
        );
        $capitalNominalId = goldenCardAuditEnsureNominal(
            99102,
            'GOLD-CAP-SIGNED',
            'Golden signed capital movement',
            'expense',
            'capital'
        );

        goldenCardAuditPostJournal(
            99111,
            $accountingPeriodId,
            '2022-10-10',
            'manual',
            'golden-audit-signed-disallowable-debit',
            $disallowableNominalId,
            91001,
            100.00,
            'Golden signed disallowable debit'
        );
        goldenCardAuditPostJournal(
            99112,
            $accountingPeriodId,
            '2023-03-10',
            'manual',
            'golden-audit-signed-disallowable-credit',
            91001,
            $disallowableNominalId,
            20.00,
            'Golden signed disallowable credit'
        );
        goldenCardAuditPostJournal(
            99113,
            $accountingPeriodId,
            '2022-11-10',
            'manual',
            'golden-audit-signed-capital-debit',
            $capitalNominalId,
            91001,
            50.00,
            'Golden signed capital debit'
        );
        goldenCardAuditPostJournal(
            99114,
            $accountingPeriodId,
            '2023-04-10',
            'manual',
            'golden-audit-signed-capital-credit',
            91001,
            $capitalNominalId,
            10.00,
            'Golden signed capital credit'
        );

        $full = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings($companyId, $accountingPeriodId, 0);
        $disallowableRows = array_values(array_filter(
            (array)($full['disallowable_add_backs'] ?? []),
            static fn(array $row): bool => (string)($row['nominal_code'] ?? '') === 'GOLD-DIS-SIGNED'
        ));
        $capitalRows = array_values(array_filter(
            (array)($full['capital_add_backs'] ?? []),
            static fn(array $row): bool => (string)($row['nominal_code'] ?? '') === 'GOLD-CAP-SIGNED'
        ));
        $disallowableAmounts = array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $disallowableRows);
        $capitalAmounts = array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $capitalRows);
        sort($disallowableAmounts);
        sort($capitalAmounts);

        $harness->assertSame(['-20.00', '100.00'], array_map('goldenCardAuditMoney', $disallowableAmounts));
        $harness->assertSame(['-10.00', '50.00'], array_map('goldenCardAuditMoney', $capitalAmounts));
        $harness->assertSame(
            goldenCardAuditMoney((float)(($before['summary'] ?? [])['disallowable_add_backs'] ?? 0) + 80.00),
            goldenCardAuditMoney(($full['summary'] ?? [])['disallowable_add_backs'] ?? 0)
        );
        $harness->assertSame(
            goldenCardAuditMoney((float)(($before['summary'] ?? [])['capital_add_backs'] ?? 0) + 40.00),
            goldenCardAuditMoney(($full['summary'] ?? [])['capital_add_backs'] ?? 0)
        );

        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $sync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
        $harness->assertTrue((bool)($sync['success'] ?? false));
        test_confirm_ct_period_facts($companyId, $accountingPeriodId);
        $periods = array_values(array_filter(
            (array)($sync['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $harness->assertCount(2, $periods);

        $detailAcrossPeriods = ['disallowable_add_backs' => 0.0, 'capital_add_backs' => 0.0];
        foreach ($periods as $period) {
            $workings = (new \eel_accounts\Service\TaxWorkingsService())
                ->fetchWorkings($companyId, $accountingPeriodId, (int)($period['id'] ?? 0));
            foreach (array_keys($detailAcrossPeriods) as $key) {
                $detail = round(array_sum(array_map(
                    static fn(array $row): float => (float)($row['amount'] ?? 0),
                    (array)($workings[$key] ?? [])
                )), 2);
                $summary = round((float)(($workings['summary'] ?? [])[$key] ?? 0), 2);
                $harness->assertSame(goldenCardAuditMoney($summary), goldenCardAuditMoney($detail));
                $detailAcrossPeriods[$key] = round($detailAcrossPeriods[$key] + $detail, 2);
            }
            $harness->assertSame(
                [],
                array_values(array_filter(
                    (array)($workings['disallowable_add_backs'] ?? []),
                    static fn(array $row): bool => (string)($row['tax_treatment'] ?? '') !== 'disallowable'
                ))
            );
            $harness->assertSame(
                [],
                array_values(array_filter(
                    (array)($workings['capital_add_backs'] ?? []),
                    static fn(array $row): bool => (string)($row['tax_treatment'] ?? '') !== 'capital'
                ))
            );
        }

        $harness->assertSame(
            [
                goldenCardAuditMoney(($full['summary'] ?? [])['disallowable_add_backs'] ?? 0),
                goldenCardAuditMoney(($full['summary'] ?? [])['capital_add_backs'] ?? 0),
            ],
            [
                goldenCardAuditMoney($detailAcrossPeriods['disallowable_add_backs']),
                goldenCardAuditMoney($detailAcrossPeriods['capital_add_backs']),
            ]
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'includes signed pending prepayment movements in disallowable detail and summary', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        InterfaceDB::prepareExecute(
            'UPDATE nominal_accounts SET tax_treatment = :tax_treatment WHERE id = :id',
            ['tax_treatment' => 'disallowable', 'id' => 91019]
        );
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $accountingPeriodId = 9112;
        $before = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings($companyId, $accountingPeriodId, 0);
        $beforeDetail = round(array_sum(array_map(
            static fn(array $row): float => (float)($row['amount'] ?? 0),
            (array)($before['disallowable_add_backs'] ?? [])
        )), 2);

        goldenCardAuditSeedAnnualPrepayment(false);

        $after = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings($companyId, $accountingPeriodId, 0);
        $afterDetail = round(array_sum(array_map(
            static fn(array $row): float => (float)($row['amount'] ?? 0),
            (array)($after['disallowable_add_backs'] ?? [])
        )), 2);
        $pendingRows = array_values(array_filter(
            (array)($after['disallowable_add_backs'] ?? []),
            static fn(array $row): bool => (string)($row['source'] ?? '') === 'pending_prepayment'
                && (string)($row['nominal_code'] ?? '') === 'GOLD-PREPAY-EXP'
        ));

        $harness->assertCount(1, $pendingRows);
        $harness->assertSame('2024-09-30', (string)($pendingRows[0]['journal_date'] ?? ''));
        $harness->assertSame('-107.00', goldenCardAuditMoney($pendingRows[0]['amount'] ?? 0));
        $harness->assertSame(
            goldenCardAuditMoney($beforeDetail + 259.00),
            goldenCardAuditMoney($afterDetail)
        );
        $harness->assertSame(
            goldenCardAuditMoney((float)(($before['summary'] ?? [])['disallowable_add_backs'] ?? 0) + 259.00),
            goldenCardAuditMoney(($after['summary'] ?? [])['disallowable_add_backs'] ?? 0)
        );
        $harness->assertSame(
            goldenCardAuditMoney(($after['summary'] ?? [])['disallowable_add_backs'] ?? 0),
            goldenCardAuditMoney($afterDetail)
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'adds an accounting fixed-asset disposal loss back for corporation tax', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $before = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112, 0);
        $lossNominalId = goldenCardAuditEnsureNominal(99040, '6210', 'Golden Test Loss on Disposal of Fixed Assets', 'expense', 'allowable');
        goldenCardAuditPostJournal(
            99041,
            9112,
            '2024-08-05',
            'asset_disposal',
            'asset:golden-audit:disposal',
            $lossNominalId,
            91013,
            100.00,
            'Golden audit accounting loss on disposal'
        );

        $after = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9112, 0);
        $disposalDetail = 0.0;
        $disposalTreatment = '';
        foreach ((array)($after['capital_add_backs'] ?? []) as $row) {
            if ((string)($row['nominal_code'] ?? '') === '6210') {
                $disposalDetail += (float)($row['amount'] ?? 0);
                $disposalTreatment = (string)($row['tax_treatment'] ?? '');
            }
        }

        $harness->assertSame(
            [
                goldenCardAuditMoney(($before['summary'] ?? [])['disallowable_add_backs'] ?? 0),
                goldenCardAuditMoney((float)(($before['summary'] ?? [])['capital_add_backs'] ?? 0) + 100.00),
                goldenCardAuditMoney(($before['summary'] ?? [])['taxable_before_losses'] ?? 0),
                '100.00',
                'capital',
            ],
            [
                goldenCardAuditMoney(($after['summary'] ?? [])['disallowable_add_backs'] ?? 0),
                goldenCardAuditMoney(($after['summary'] ?? [])['capital_add_backs'] ?? 0),
                goldenCardAuditMoney(($after['summary'] ?? [])['taxable_before_losses'] ?? 0),
                goldenCardAuditMoney($disposalDetail),
                $disposalTreatment,
            ]
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'keeps nil-proceeds disposal events visible in tax disposal workings', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        InterfaceDB::prepareExecute(
            'UPDATE asset_register SET disposal_proceeds = 0 WHERE id = :id',
            ['id' => 9560]
        );
        InterfaceDB::prepareExecute(
            'UPDATE capital_allowance_asset_calculations
             SET disposal_value = 0
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND asset_id = :asset_id',
            [
                'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
                'accounting_period_id' => 9411,
                'asset_id' => 9560,
            ]
        );

        $workings = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings(GoldenAccountsFixture::COMPLETE_COMPANY_ID, 9411, 0);
        $harness->assertSame(
            ['GOLDEN-COMPLETE-DISPOSAL'],
            array_values(array_map(
                static fn(array $row): string => (string)($row['asset_code'] ?? ''),
                (array)($workings['disposals_balancing'] ?? [])
            ))
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'does not set off director-loan assets and liabilities without FRS 105 paragraph 9.27 evidence', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $snapshotService = new \eel_accounts\Service\CompaniesHouseSnapshotService();
        $before = goldenCardAuditFields($snapshotService->fetchSnapshot(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111));
        goldenCardAuditPostJournal(
            99051,
            9111,
            '2023-09-20',
            'manual',
            'golden-audit-director-loan-receivable',
            91006,
            91001,
            100.00,
            'Golden audit director-loan receivable without set-off evidence'
        );
        $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
        $directorLoanSummary = (new \eel_accounts\Service\YearEndMetricsService())
            ->directorLoanSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
        $acknowledgement = $acknowledgements->save(
            GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            9111,
            'director_loan_closing_balance',
            $acknowledgements->buildBasis('director_loan_closing_balance', [
                'closing_balance' => number_format((float)($directorLoanSummary['closing_balance'] ?? 0), 2, '.', ''),
            ]),
            'golden-audit',
            'Closing balance agreed; no legal set-off evidence supplied.'
        );
        $harness->assertTrue(!empty($acknowledgement['success']));

        // Cash falls by GBP100 and the DLA receivable rises by GBP100, so gross
        // current assets and the separately recognised payable do not change.
        $after = goldenCardAuditFields($snapshotService->fetchSnapshot(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111));
        $harness->assertSame(
            [
                goldenCardAuditMoney($before['current_assets'] ?? 0),
                goldenCardAuditMoney($before['creditors_within_one_year'] ?? 0),
            ],
            [
                goldenCardAuditMoney($after['current_assets'] ?? 0),
                goldenCardAuditMoney($after['creditors_within_one_year'] ?? 0),
            ]
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'cross-foots monthly after-tax profit to the summary estimated-tax basis', static function () use ($harness): void {
    $service = new \eel_accounts\Service\ProfitLossService();
    $summary = $service->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
    $monthly = $service->getMonthlyProfitLossTrend(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
    $monthlyAfterTax = array_sum(array_map(
        static fn(array $row): float => (float)($row['profit_after_tax'] ?? 0),
        $monthly
    ));

    $harness->assertSame(
        goldenCardAuditMoney($summary['profit_after_estimated_tax'] ?? 0),
        goldenCardAuditMoney($monthlyAfterTax)
    );
});

$harness->check('GoldenAccountingCardAuditDefects', 'does not mark a journal with a nonexistent source reference as covered and reconciled', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $service = new \eel_accounts\Service\ProfitLossService();
        $baselineCoverage = $service
            ->getSourceCoverage(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
        $baselineUncovered = (int)(($baselineCoverage['coverage_summary'] ?? [])['uncovered_journal_count'] ?? 0);
        goldenCardAuditPostJournal(
            99061,
            9114,
            '2026-07-15',
            'bank_csv',
            'transaction:999999999',
            91004,
            91001,
            25.00,
            'Golden audit broken transaction source reference'
        );
        $coverage = $service->getSourceCoverage(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
        $summary = (array)($coverage['coverage_summary'] ?? []);

        $harness->assertSame(
            [$baselineUncovered + 1, false],
            [(int)($summary['uncovered_journal_count'] ?? 0), (bool)($summary['reconciled'] ?? true)]
        );
    } finally {
        goldenCardAuditRollback();
    }
});

$harness->check('GoldenAccountingCardAuditDefects', 'does not treat tagged prepayment metadata as independent proof of journal accounting', static function () use ($harness): void {
    $coverage = (new \eel_accounts\Service\ProfitLossService())
        ->getSourceCoverage(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
    $failures = array_values(array_filter(
        (array)(($coverage['coverage_summary'] ?? [])['evidence_failures'] ?? []),
        static fn(array $failure): bool =>
            str_starts_with((string)($failure['source_ref'] ?? ''), 'meta:prepayment_')
            && str_contains((string)($failure['reason'] ?? ''), 'no independent content verifier')
    ));

    $harness->assertCount(2, $failures);
    $harness->assertSame(false, (bool)(($coverage['coverage_summary'] ?? [])['reconciled'] ?? true));
});

$harness->check('GoldenAccountingCardAuditDefects', 'retains historical journal balances after a nominal is deactivated', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        InterfaceDB::prepareExecute(
            'UPDATE nominal_accounts SET is_active = 0 WHERE id = :id',
            ['id' => 91004]
        );
        $trialBalance = (new \eel_accounts\Service\TrialBalanceService())
            ->fetchTrialBalance(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
        $matchingRows = array_values(array_filter(
            (array)($trialBalance['rows'] ?? []),
            static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === 91004
        ));

        $harness->assertCount(1, $matchingRows);
    } finally {
        goldenCardAuditRollback();
    }
});

function goldenCardAuditSeedAnnualPrepayment(bool $persistSchedule): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id, company_id, accounting_period_id, statement_upload_id, account_id,
            txn_date, txn_type, description, reference, amount, currency, source_type,
            source_account_label, balance, counterparty_name, dedupe_hash,
            nominal_account_id, category_status, document_download_status
         ) VALUES (
            :id, :company_id, :accounting_period_id, :statement_upload_id, :account_id,
            :txn_date, :txn_type, :description, :reference, :amount, :currency, :source_type,
            :source_account_label, :balance, :counterparty_name, :dedupe_hash,
            :nominal_account_id, :category_status, :document_download_status
         )',
        [
            'id' => 99001,
            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'accounting_period_id' => 9112,
            'statement_upload_id' => 9141,
            'account_id' => 9120,
            'txn_date' => '2024-01-16',
            'txn_type' => 'Synthetic',
            'description' => 'Golden audit annual insurance 16 January 2024 to 15 January 2025',
            'reference' => 'GOLDEN-AUDIT-PREPAYMENT',
            'amount' => -366.00,
            'currency' => 'GBP',
            'source_type' => 'statement_csv',
            'source_account_label' => 'Golden Current Account',
            'balance' => -1566.00,
            'counterparty_name' => 'Golden Audit Insurer',
            'dedupe_hash' => hash('sha256', 'GOLDEN-AUDIT-PREPAYMENT'),
            'nominal_account_id' => 91019,
            'category_status' => 'manual',
            'document_download_status' => 'skipped',
        ]
    );
    goldenCardAuditPostJournal(
        99002,
        9112,
        '2024-01-16',
        'bank_csv',
        'transaction:99001',
        91019,
        91001,
        366.00,
        'Golden audit annual insurance'
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO prepayment_reviews (
            id, company_id, accounting_period_id, source_type, source_id, status,
            service_start_date, service_end_date, notes, reviewed_at, reviewed_by
         ) VALUES (
            :id, :company_id, :accounting_period_id, :source_type, :source_id, :status,
            :service_start_date, :service_end_date, :notes, :reviewed_at, :reviewed_by
         )',
        [
            'id' => 99003,
            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'accounting_period_id' => 9112,
            'source_type' => 'transaction',
            'source_id' => 99001,
            'status' => 'prepaid',
            'service_start_date' => '2024-01-16',
            'service_end_date' => '2025-01-15',
            'notes' => 'Golden audit approved prepayment.',
            'reviewed_at' => '2024-09-30 12:00:00',
            'reviewed_by' => 'golden-audit',
        ]
    );

    if ($persistSchedule) {
        $result = (new \eel_accounts\Service\PrepaymentScheduleService())
            ->syncReviewSchedule(99003, 'golden-audit');
        if (empty($result['success'])) {
            throw new RuntimeException('Unable to persist golden audit prepayment schedule: '
                . implode(' ', (array)($result['errors'] ?? [])));
        }
    }
}

function goldenCardAuditPostJournal(
    int $journalId,
    int $accountingPeriodId,
    string $date,
    string $sourceType,
    string $sourceRef,
    int $debitNominalId,
    int $creditNominalId,
    float $amount,
    string $description
): void {
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            id, company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :id, :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'id' => $journalId,
            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => $description,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, 0, :description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $debitNominalId,
            'debit' => $amount,
            'description' => $description,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0, :credit, :description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $creditNominalId,
            'credit' => $amount,
            'description' => $description,
        ]
    );
}

function goldenCardAuditEnsureNominal(
    int $id,
    string $code,
    string $name,
    string $accountType,
    string $taxTreatment
): int {
    $existingId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code ORDER BY id LIMIT 1',
        ['code' => $code]
    );
    if ($existingId > 0) {
        InterfaceDB::prepareExecute(
            'UPDATE nominal_accounts
             SET name = :name,
                 account_type = :account_type,
                 tax_treatment = :tax_treatment,
                 is_active = 1
             WHERE id = :id',
            [
                'id' => $existingId,
                'name' => $name,
                'account_type' => $accountType,
                'tax_treatment' => $taxTreatment,
            ]
        );

        return $existingId;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (
            id, code, name, account_type, tax_treatment, is_active, sort_order
         ) VALUES (
            :id, :code, :name, :account_type, :tax_treatment, 1, :sort_order
         )',
        [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
            'sort_order' => $id,
        ]
    );

    return $id;
}

/** @return array<string, mixed> */
function goldenCardAuditFields(array $snapshot): array
{
    $fields = [];
    foreach ((array)($snapshot['fields'] ?? []) as $field) {
        $fields[(string)($field['key'] ?? '')] = $field['value'] ?? null;
    }

    return $fields;
}

function goldenCardAuditHasPreviewOnlyReview(array $context, int $reviewId): bool
{
    foreach ((array)($context['schedules'] ?? []) as $schedule) {
        if ((int)($schedule['review_id'] ?? 0) === $reviewId && !empty($schedule['preview_only'])) {
            return true;
        }
    }

    return false;
}

/** @return array<string, mixed> */
function goldenCardAuditValidationCheck(array $validation, string $code): array
{
    foreach ((array)($validation['checks'] ?? []) as $check) {
        if ((string)($check['code'] ?? '') === $code) {
            return $check;
        }
    }

    return [];
}

function goldenCardAuditMoney(mixed $value): string
{
    return number_format(round((float)$value, 2), 2, '.', '');
}

function goldenCardAuditRollback(): void
{
    if (InterfaceDB::inTransaction()) {
        InterfaceDB::rollBack();
    }
}
