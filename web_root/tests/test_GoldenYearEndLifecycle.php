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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenLedgerSpecification.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenHmrcCorporationTaxOracle.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenComparisonReporter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenYearEndLifecycle', 'does not leak stale persisted AP80 asset rows into a transient capital-allowance view', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $ap80 = 9112;

    InterfaceDB::beginTransaction();
    try {
        InterfaceDB::execute(
            'UPDATE asset_register
             SET purchase_date = :purchase_date
             WHERE id = :asset_id
               AND company_id = :company_id',
            [
                'purchase_date' => '2024-10-01',
                'asset_id' => 9254,
                'company_id' => $companyId,
            ]
        );
        InterfaceDB::execute(
            'INSERT INTO capital_allowance_asset_calculations (
                company_id, accounting_period_id, ct_period_id, asset_id,
                pool_type, allowance_type, addition_amount, allowance_amount,
                disposal_value, warning
             ) VALUES (
                :company_id, :accounting_period_id, NULL, :asset_id,
                :pool_type, :allowance_type, :addition_amount, :allowance_amount,
                0.00, :warning
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $ap80,
                'asset_id' => 9254,
                'pool_type' => 'main_pool',
                'allowance_type' => 'aia',
                'addition_amount' => 999.00,
                'allowance_amount' => 999.00,
                'warning' => 'Deliberately stale AP80 row.',
            ]
        );

        $workings = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings($companyId, $ap80, 0);
        if (empty($workings['available'])) {
            throw new RuntimeException('Transient AP80 workings failed: ' . implode(' ', (array)($workings['errors'] ?? [])));
        }
        $harness->assertSame([], (array)($workings['aia_allocation'] ?? []));
        $harness->assertSame(
            '0.00',
            number_format((float)($workings['capital_allowances_summary']['net_capital_allowances'] ?? 0), 2, '.', '')
        );
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenYearEndLifecycle', 'previews the split-period CT provision that Year End will actually post', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $accountingPeriodId = 9111;

    InterfaceDB::beginTransaction();
    try {
        // Create an intentionally uneven CT-period result: capital allowances
        // create a loss in CT period 1, while period 2 enters marginal relief.
        // Treating the 391-day period of account as one CT period understates
        // the provision, so this directly controls the AP79 close preview.
        InterfaceDB::execute(
            'UPDATE asset_register
             SET cost = cost + 57000.00
             WHERE id = :asset_id
               AND company_id = :company_id',
            [
                'asset_id' => 9251,
                'company_id' => $companyId,
            ]
        );
        InterfaceDB::execute(
            'INSERT INTO journals (
                company_id, accounting_period_id, source_type, source_ref,
                journal_date, description, is_posted
             ) VALUES (
                :company_id, :accounting_period_id, :source_type, :source_ref,
                :journal_date, :description, 1
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'source_type' => 'manual',
                'source_ref' => 'golden-split-ct-provision-preview',
                'journal_date' => '2023-09-30',
                'description' => 'Synthetic split-period CT provision control',
            ]
        );
        $journalId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_ref = :source_ref
             LIMIT 1',
            [
                'company_id' => $companyId,
                'source_ref' => 'golden-split-ct-provision-preview',
            ]
        );
        InterfaceDB::execute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, 60000.00, 0.00)',
            ['journal_id' => $journalId, 'nominal_account_id' => 91001]
        );
        InterfaceDB::execute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, 0.00, 60000.00)',
            ['journal_id' => $journalId, 'nominal_account_id' => 91002]
        );

        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $initialSync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
        $harness->assertSame(true, (bool)($initialSync['success'] ?? false));
        test_confirm_ct_period_facts($companyId, $accountingPeriodId);
        $harness->assertCount(2, (array)($initialSync['periods'] ?? []));
        $scopeService = new \eel_accounts\Service\CorporationTaxFilingScopeService();
        foreach (array_keys($scopeService->definitions()) as $scopeField) {
            $savedScope = $scopeService->saveAnswer($companyId, $accountingPeriodId, $scopeField, 'no', 'golden_preview_test');
            if (empty($savedScope['success'])) {
                throw new RuntimeException('Golden preview filing scope failed: ' . implode(' ', (array)($savedScope['errors'] ?? [])));
            }
        }
        $ct600aService = new \eel_accounts\Service\Ct600aService();
        $review = $ct600aService->saveReview(
            $companyId,
            $accountingPeriodId,
            array_fill_keys(array_keys($ct600aService->reviewQuestions()), 'no'),
            'director',
            'Golden Fixture Director',
            'No section 464A arrangements in the synthetic golden fixture.'
        );
        if (empty($review['success'])) {
            throw new RuntimeException('Golden preview CT600A review failed: ' . implode(' ', (array)($review['errors'] ?? [])));
        }
        $initialEvidence = (new \eel_accounts\Service\CorporationTaxComputationService())
            ->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
        if (empty($initialEvidence['success'])) {
            throw new RuntimeException('Initial split-period CT evidence failed: '
                . implode(' ', array_map('strval', (array)($initialEvidence['errors'] ?? []))));
        }
        $acceptedPeriod = InterfaceDB::fetchOne(
            'SELECT id, latest_computation_run_id
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND sequence_no = 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        $acceptedCtPeriodId = (int)($acceptedPeriod['id'] ?? 0);
        $acceptedRunId = (int)($acceptedPeriod['latest_computation_run_id'] ?? 0);
        $harness->assertTrue($acceptedCtPeriodId > 0);
        $harness->assertTrue($acceptedRunId > 0);
        $harness->assertSame(
            2,
            (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            )
        );

        $metrics = new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = (array)$metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $profitAndLoss = (new \eel_accounts\Service\PreTaxProfitLossService())->calculate(
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_end'],
            (string)$accountingPeriod['period_start']
        );
        $wholePeriodEstimate = (new \eel_accounts\Service\CorporationTaxComputationService())
            ->fetchCurrentPeriodEstimate(
                $companyId,
                $accountingPeriodId,
                $accountingPeriod,
                $profitAndLoss
            );

        $preview = (new \eel_accounts\Service\YearEndClosePreviewService())
            ->pendingBalanceSheetAdjustmentContext(
                $companyId,
                $accountingPeriodId,
                (string)$accountingPeriod['period_end'],
                null,
                null,
                null,
                false
            );
        if (empty($preview['reliable'])) {
            throw new RuntimeException('Split-period close preview was unreliable: '
                . implode(' ', array_map('strval', (array)($preview['errors'] ?? []))));
        }
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $liabilityNominalId = (int)($settings['corporation_tax_liability_nominal_id'] ?? 0);
        $previewedProvision = 0.0;
        foreach ((array)($preview['adjustments'] ?? []) as $adjustment) {
            if ((int)($adjustment['nominal_account_id'] ?? 0) !== $liabilityNominalId
                || !str_starts_with(
                    (string)($adjustment['source'] ?? ''),
                    'pending_corporation_tax_provision'
                )) {
                continue;
            }
            $previewedProvision += (float)($adjustment['credit'] ?? 0)
                - (float)($adjustment['debit'] ?? 0);
        }
        $harness->assertSame(
            2,
            (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            )
        );

        $postingSync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
        $harness->assertSame(true, (bool)($postingSync['success'] ?? false));
        test_confirm_ct_period_facts($companyId, $accountingPeriodId);
        $harness->assertCount(2, (array)($postingSync['periods'] ?? []));
        $postingBasis = (new \eel_accounts\Service\CorporationTaxComputationService())
            ->previewProvisionPositionForAccountingPeriod(
                $companyId,
                $accountingPeriodId,
                $accountingPeriod,
                $profitAndLoss
            );
        if (empty($postingBasis['available'])) {
            throw new RuntimeException('Split-period posting basis was unavailable: '
                . implode(' ', array_map('strval', (array)($postingBasis['errors'] ?? []))));
        }
        $finalEvidence = (new \eel_accounts\Service\CorporationTaxComputationService())
            ->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
        if (empty($finalEvidence['success'])) {
            throw new RuntimeException('Final split-period CT evidence failed: '
                . implode(' ', array_map('strval', (array)($finalEvidence['errors'] ?? []))));
        }
        $statutoryProvision = round(
            (float)($postingBasis['estimated_corporation_tax'] ?? 0),
            2
        );
        $harness->assertTrue(
            abs(
                $statutoryProvision
                - (float)($wholePeriodEstimate['estimated_corporation_tax'] ?? 0)
            ) >= 0.01
        );
        $harness->assertSame(
            number_format($statutoryProvision, 2, '.', ''),
            number_format($previewedProvision, 2, '.', '')
        );
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenYearEndLifecycle', 'keeps a following-period profit estimate stable when an open predecessor tax loss is locked', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $ap79 = 9111;
    $ap80 = 9112;

    InterfaceDB::beginTransaction();
    try {
        // Keep AP80 profitable for tax after loss relief by moving its synthetic
        // van acquisition outside the period, then create an AP79 trading loss.
        InterfaceDB::execute(
            'UPDATE asset_register
             SET purchase_date = :purchase_date
             WHERE id = :asset_id
               AND company_id = :company_id',
            [
                'purchase_date' => '2024-10-01',
                'asset_id' => 9254,
                'company_id' => $companyId,
            ]
        );
        InterfaceDB::execute(
            'INSERT INTO journals (
                company_id, accounting_period_id, source_type, source_ref,
                journal_date, description, is_posted
             ) VALUES (
                :company_id, :accounting_period_id, :source_type, :source_ref,
                :journal_date, :description, 1
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $ap79,
                'source_type' => 'manual',
                'source_ref' => 'golden-ap79-loss-to-ap80-profit',
                'journal_date' => '2023-08-31',
                'description' => 'Golden AP79 loss to AP80 profit control',
            ]
        );
        $lossJournalId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_ref = :source_ref
             LIMIT 1',
            [
                'company_id' => $companyId,
                'source_ref' => 'golden-ap79-loss-to-ap80-profit',
            ]
        );
        InterfaceDB::execute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, 8000.00, 0.00)',
            ['journal_id' => $lossJournalId, 'nominal_account_id' => 91004]
        );
        InterfaceDB::execute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, 0.00, 8000.00)',
            ['journal_id' => $lossJournalId, 'nominal_account_id' => 91001]
        );

        $beforeRaw = goldenFollowingPeriodRawState($companyId, $ap80);
        foreach ([$ap79, $ap80] as $periodId) {
            $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())
                ->syncForAccountingPeriod($companyId, $periodId);
            $harness->assertTrue(!empty($sync['success']));
            test_confirm_ct_period_facts($companyId, $periodId);
        }
        $beforeRaw = goldenFollowingPeriodRawState($companyId, $ap80);
        $before = goldenFollowingPeriodEconomicSnapshot($companyId, $ap80);
        $harness->assertSame([], (array)($beforeRaw['capital_allowance_pool_runs'] ?? []));
        $harness->assertSame([], (array)($beforeRaw['capital_allowance_asset_calculations'] ?? []));
        $harness->assertCount(1, (array)($beforeRaw['corporation_tax_periods'] ?? []));
        $beforeTax = (array)($before['reporting']['tax'] ?? []);
        $harness->assertSame(true, (float)($beforeTax['losses_brought_forward'] ?? 0) > 0);
        $harness->assertSame(true, (float)($beforeTax['losses_used'] ?? 0) > 0);
        $harness->assertSame(true, (float)($beforeTax['taxable_profit'] ?? 0) > 0);
        $harness->assertSame(true, (float)($beforeTax['estimated_corporation_tax'] ?? 0) > 0);
        goldenAssertFollowingPeriodComplete($harness, $before);

        goldenClosePeriodForFollowingPeriodControl($harness, $companyId, $ap79);

        $after = goldenFollowingPeriodEconomicSnapshot($companyId, $ap80);
        $afterRaw = goldenFollowingPeriodRawState($companyId, $ap80);
        goldenAssertFollowingPeriodInvariant($before, $after, $ap80, 'loss_to_profit_outputs');
        goldenAssertFollowingPeriodInvariant($beforeRaw, $afterRaw, $ap80, 'loss_to_profit_raw_state');
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenYearEndLifecycle', 'performs close tasks and preserves reporting semantics when completed periods are locked', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $periods = [9111, 9112, 9113];
    foreach ($periods as $periodId) {
        $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->syncForAccountingPeriod($companyId, $periodId);
        $harness->assertTrue(!empty($sync['success']));
        test_confirm_ct_period_facts($companyId, $periodId);
    }
    $hmrcExpected = GoldenHmrcCorporationTaxOracle::calculateSequence(GoldenLedgerSpecification::hmrcTaxFacts());
    $followingPeriodRawBefore = goldenFollowingPeriodRawState($companyId, 9112);
    $followingPeriodOutputsBefore = goldenFollowingPeriodEconomicSnapshot($companyId, 9112);
    $harness->assertSame([], (array)($followingPeriodRawBefore['capital_allowance_pool_runs'] ?? []));
    $harness->assertSame([], (array)($followingPeriodRawBefore['capital_allowance_asset_calculations'] ?? []));
    $harness->assertCount(1, (array)($followingPeriodRawBefore['corporation_tax_periods'] ?? []));
    $followingAiaRows = (array)($followingPeriodOutputsBefore['reporting']['tax_detail']['aia_allocation'] ?? []);
    $harness->assertCount(1, $followingAiaRows);
    $harness->assertSame(
        ['GOLDEN-VAN-001', '9000.00', '9000.00'],
        [
            (string)($followingAiaRows[0]['asset_code'] ?? ''),
            number_format((float)($followingAiaRows[0]['addition_amount'] ?? 0), 2, '.', ''),
            number_format((float)($followingAiaRows[0]['allowance_amount'] ?? 0), 2, '.', ''),
        ]
    );
    $harness->assertCount(
        1,
        (array)($followingPeriodOutputsBefore['assets']['capital_allowance_asset_calculations'] ?? [])
    );
    goldenAssertFollowingPeriodComplete($harness, $followingPeriodOutputsBefore);

    foreach ($periods as $periodId) {
        $expected = GoldenLedgerSpecification::yearEndAssetExpectations()[$periodId];
        $depreciation = (new \eel_accounts\Service\AssetService())->runDepreciation($companyId, $periodId);
        if (empty($depreciation['success'])) {
            throw new RuntimeException('AP ' . $periodId . ' depreciation failed: ' . implode(' ', (array)($depreciation['errors'] ?? [])));
        }
        $harness->assertTrue(!empty($depreciation['success']));
        $harness->assertSame((int)$expected['depreciation_entries'], (int)($depreciation['created'] ?? 0));
        $postedDepreciation = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(ade.amount), 0)
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ar.company_id = :company_id AND ade.accounting_period_id = :period_id',
            ['company_id' => $companyId, 'period_id' => $periodId]
        );
        $harness->assertSame(number_format((float)$expected['depreciation'], 2, '.', ''), number_format($postedDepreciation, 2, '.', ''));

        $provision = (new \eel_accounts\Service\CorporationTaxProvisionService())
            ->postProvisionsForAccountingPeriod($companyId, $periodId, 'golden_year_end_test');
        if (empty($provision['success'])) {
            throw new RuntimeException('AP ' . $periodId . ' CT provision failed: ' . implode(' ', (array)($provision['errors'] ?? [])));
        }
        $harness->assertTrue(!empty($provision['success']));

        goldenSaveReserveReviewForClose($companyId, $periodId);

        $checklist = new \eel_accounts\Service\YearEndChecklistService();
        $acknowledgement = $checklist->saveRetainedEarningsCloseAcknowledgement(
            $companyId,
            $periodId,
            true,
            'golden_year_end_test',
            'Golden lifecycle figures agreed after close-task calculations.'
        );
        if (empty($acknowledgement['success'])) {
            throw new RuntimeException('AP ' . $periodId . ' retained-earnings acknowledgement failed: ' . implode(' ', (array)($acknowledgement['errors'] ?? [])));
        }
        $harness->assertTrue(!empty($acknowledgement['success']));

        $retainedEarnings = (new \eel_accounts\Service\RetainedEarningsCloseService())
            ->postClose($companyId, $periodId, 'golden_year_end_test');
        if (empty($retainedEarnings['success'])) {
            throw new RuntimeException('AP ' . $periodId . ' retained-earnings close failed: ' . implode(' ', (array)($retainedEarnings['errors'] ?? [])));
        }
        $harness->assertTrue(!empty($retainedEarnings['success']));

        $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary($companyId, $periodId);
        $harness->assertSame(number_format((float)$expected['depreciation'], 2, '.', ''), number_format((float)($profitLoss['depreciation_expense'] ?? 0), 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['profit_before_tax'], 2, '.', ''), number_format((float)($profitLoss['profit_before_tax'] ?? 0), 2, '.', ''));

        $ctTax = 0.0;
        $ctTaxableProfit = 0.0;
        $ctCapitalAllowances = 0.0;
        $ctAccountingProfit = 0.0;
        $ctDisallowableAddBacks = 0.0;
        $ctDepreciationAddBack = 0.0;
        $ctAllocationBasis = [];
        $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetchForAccountingPeriod($companyId, $periodId);
        foreach ($ctPeriods as $ctPeriod) {
            $summary = (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, (int)$ctPeriod['id']);
            $ctTax += (float)($summary['estimated_corporation_tax'] ?? 0);
            $ctTaxableProfit += (float)($summary['taxable_profit'] ?? 0);
            $ctCapitalAllowances += (float)($summary['capital_allowances'] ?? 0);
            $ctAccountingProfit += (float)($summary['accounting_profit'] ?? 0);
            $ctDisallowableAddBacks += (float)($summary['disallowable_add_backs'] ?? 0);
            $ctDepreciationAddBack += (float)($summary['depreciation_add_back'] ?? 0);
            $ctAllocationBasis[] = (array)($summary['accounting_allocation_basis'] ?? []);
        }
        $hmrcFacts = GoldenLedgerSpecification::hmrcTaxFacts()[$periodId];
        $harness->assertSame(number_format((float)$hmrcFacts['accounting_profit'], 2, '.', ''), number_format($ctAccountingProfit, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcFacts['disallowable_add_backs'], 2, '.', ''), number_format($ctDisallowableAddBacks, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcFacts['depreciation_add_back'], 2, '.', ''), number_format($ctDepreciationAddBack, 2, '.', ''));
        if ($periodId === 9111) {
            $harness->assertSame(2, count($ctPeriods));
            $harness->assertTrue(!empty($ctAllocationBasis[0]['time_apportioned']));
            $harness->assertSame('whole_accounting_period_inclusive_days', (string)($ctAllocationBasis[0]['method'] ?? ''));
            $harness->assertSame(391, (int)($ctAllocationBasis[0]['accounting_period_days'] ?? 0));
            $harness->assertTrue(!empty($ctAllocationBasis[1]['final_period_residual']));
        }
        $harness->assertSame(number_format((float)$expected['capital_allowances'], 2, '.', ''), number_format($ctCapitalAllowances, 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['taxable_profit'], 2, '.', ''), number_format($ctTaxableProfit, 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['corporation_tax'], 2, '.', ''), number_format($ctTax, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcExpected[$periodId]['capital_allowances'], 2, '.', ''), number_format($ctCapitalAllowances, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcExpected[$periodId]['taxable_profit'], 2, '.', ''), number_format($ctTaxableProfit, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcExpected[$periodId]['corporation_tax'], 2, '.', ''), number_format($ctTax, 2, '.', ''));

        $scopeService = new \eel_accounts\Service\CorporationTaxFilingScopeService();
        foreach (array_keys($scopeService->definitions()) as $scopeField) {
            $savedScope = $scopeService->saveAnswer($companyId, $periodId, $scopeField, 'no', 'golden_year_end_test');
            if (empty($savedScope['success'])) {
                throw new RuntimeException('Golden filing scope failed: ' . implode(' ', (array)($savedScope['errors'] ?? [])));
            }
        }
        $ct600aService = new \eel_accounts\Service\Ct600aService();
        $review = $ct600aService->saveReview(
            $companyId,
            $periodId,
            array_fill_keys(array_keys($ct600aService->reviewQuestions()), 'no'),
            'director',
            'Golden Fixture Director',
            'No section 464A arrangements in the synthetic golden fixture.'
        );
        if (empty($review['success'])) {
            throw new RuntimeException('Golden CT600A review failed: ' . implode(' ', (array)($review['errors'] ?? [])));
        }
        $periodSync = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->syncForAccountingPeriod($companyId, $periodId);
        if (empty($periodSync['success'])) {
            throw new RuntimeException('Golden CT-period sync failed: ' . implode(' ', (array)($periodSync['errors'] ?? [])));
        }
        $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->fetchForAccountingPeriod($companyId, $periodId);

        $beforeLock = goldenYearEndReportingSnapshot($companyId, $periodId);
        InterfaceDB::beginTransaction();
        try {
            $taxReadiness = (new \eel_accounts\Service\YearEndTaxReadinessService())
                ->fetchAccountingPeriodCtSummary($companyId, $periodId);
            $approvalBasis = (new \eel_accounts\Service\YearEndTaxFreezeService())->approvalBasis($taxReadiness);
            if (!is_array($approvalBasis)) {
                throw new RuntimeException('AP ' . $periodId . ' CT basis was not ready for approval.');
            }
            $approval = (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
                $companyId,
                $periodId,
                'tax_readiness_acknowledgement',
                $approvalBasis,
                'golden_year_end_test',
                '',
                true
            );
            if (empty($approval['success'])) {
                throw new RuntimeException('AP ' . $periodId . ' CT approval failed: ' . implode(' ', (array)($approval['errors'] ?? [])));
            }
            $taxPersistence = (new \eel_accounts\Service\CorporationTaxComputationService())
                ->persistSummariesForYearEndLock($companyId, $periodId);
            if (empty($taxPersistence['success'])) {
                throw new RuntimeException('AP ' . $periodId . ' CT persistence failed: ' . implode(' ', (array)($taxPersistence['errors'] ?? [])));
            }
            $harness->assertTrue(!empty($taxPersistence['success']));
            $approvedFreezeManifestHash = (new \eel_accounts\Service\YearEndAcknowledgementService())
                ->hashBasis((array)($approvalBasis['freeze_manifest'] ?? []));
            foreach ((array)($taxPersistence['summaries'] ?? []) as $persistedSummary) {
                $harness->assertSame(
                    $approvedFreezeManifestHash,
                    (string)($persistedSummary['year_end_freeze_manifest_hash'] ?? '')
                );
            }

            $lock = (new \eel_accounts\Service\YearEndLockService())
                ->lockPeriod($companyId, $periodId, 'golden_year_end_test');
            if (empty($lock['success'])) {
                throw new RuntimeException('AP ' . $periodId . ' lock failed: ' . implode(' ', (array)($lock['errors'] ?? [])));
            }
            $harness->assertTrue(!empty($lock['success']));
            $filingSeal = (new \eel_accounts\Service\CorporationTaxComputationService())
                ->sealSummariesForYearEndLock($companyId, $periodId);
            if (empty($filingSeal['success'])) {
                throw new RuntimeException('AP ' . $periodId . ' CT filing seal failed: ' . implode(' ', (array)($filingSeal['errors'] ?? [])));
            }
            $harness->assertSame(count($ctPeriods), count((array)($filingSeal['sealed_periods'] ?? [])));
            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
            throw $exception;
        }
        $harness->assertTrue((new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $periodId));
        ixbrl_test_complete_disclosures($companyId, $periodId, 'golden_year_end_test');
        $filingApproval = (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService())
            ->approveAndBuildFacts($companyId, $periodId, 'golden_year_end_test', 'Golden lifecycle filing approval.');
        $harness->assertTrue((int)($filingApproval['approval_id'] ?? 0) > 0);
        $harness->assertTrue((int)($filingApproval['fact_run_id'] ?? 0) > 0);
        foreach ($ctPeriods as $ctPeriod) {
            $filingModel = (new \eel_accounts\Service\CtPeriodFilingModelService())
                ->build($companyId, $periodId, (int)$ctPeriod['id']);
            $harness->assertTrue(!empty($filingModel['available']));
            $harness->assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($filingModel['basis_hash'] ?? '')) === 1);
        }
        $afterLock = goldenYearEndReportingSnapshot($companyId, $periodId);

        if ($beforeLock !== $afterLock) {
            throw new RuntimeException(GoldenComparisonReporter::report([[
                'page' => 'year_end',
                'card' => 'locked_reporting_invariants',
                'period' => $periodId,
                'field' => 'tax_companies_house_dividends_profit_loss',
                'expected' => $beforeLock,
                'actual' => $afterLock,
            ]]));
        }

        if ($periodId === 9111) {
            goldenAssertFollowingPeriodInvariant(
                $followingPeriodRawBefore,
                goldenFollowingPeriodRawState($companyId, 9112),
                9112,
                'raw_state'
            );
            goldenAssertFollowingPeriodInvariant(
                $followingPeriodOutputsBefore,
                goldenFollowingPeriodEconomicSnapshot($companyId, 9112),
                9112,
                'calculated_outputs'
            );
            goldenAssertFirstPeriodLockBoundary($harness, $companyId);
        }
    }

    foreach ($periods as $periodId) {
        $unlock = (new \eel_accounts\Service\YearEndLockService())
            ->unlockPeriod($companyId, $periodId, 'golden_year_end_test_cleanup', 'Restore shared in-memory fixture state after lock assertions.');
        $harness->assertTrue(!empty($unlock['success']));
    }
});

function goldenClosePeriodForFollowingPeriodControl(
    GeneratedServiceClassTestHarness $harness,
    int $companyId,
    int $accountingPeriodId
): void {
    $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())
        ->syncForAccountingPeriod($companyId, $accountingPeriodId);
    $harness->assertTrue(!empty($sync['success']));
    test_confirm_ct_period_facts($companyId, $accountingPeriodId);
    $depreciation = (new \eel_accounts\Service\AssetService())
        ->runDepreciation($companyId, $accountingPeriodId);
    $harness->assertTrue(!empty($depreciation['success']));

    $provision = (new \eel_accounts\Service\CorporationTaxProvisionService())
        ->postProvisionsForAccountingPeriod($companyId, $accountingPeriodId, 'golden_following_period_control');
    $harness->assertTrue(!empty($provision['success']));

    goldenSaveReserveReviewForClose($companyId, $accountingPeriodId);

    $acknowledgement = (new \eel_accounts\Service\YearEndChecklistService())
        ->saveRetainedEarningsCloseAcknowledgement(
            $companyId,
            $accountingPeriodId,
            true,
            'golden_following_period_control',
            'Golden following-period control figures agreed.'
        );
    $harness->assertTrue(!empty($acknowledgement['success']));

    $retainedEarnings = (new \eel_accounts\Service\RetainedEarningsCloseService())
        ->postClose($companyId, $accountingPeriodId, 'golden_following_period_control');
    $harness->assertTrue(!empty($retainedEarnings['success']));

    $scopeService = new \eel_accounts\Service\CorporationTaxFilingScopeService();
    foreach (array_keys($scopeService->definitions()) as $scopeField) {
        $savedScope = $scopeService->saveAnswer(
            $companyId,
            $accountingPeriodId,
            $scopeField,
            'no',
            'golden_following_period_control'
        );
        if (empty($savedScope['success'])) {
            throw new RuntimeException('Following-period CT scope failed: ' . implode(' ', (array)($savedScope['errors'] ?? [])));
        }
    }
    $ct600aService = new \eel_accounts\Service\Ct600aService();
    $ct600aReview = $ct600aService->saveReview(
        $companyId,
        $accountingPeriodId,
        array_fill_keys(array_keys($ct600aService->reviewQuestions()), 'no'),
        'director',
        'Golden Fixture Director',
        'No section 464A arrangements in the synthetic golden fixture.'
    );
    if (empty($ct600aReview['success'])) {
        throw new RuntimeException('Following-period CT600A review failed: ' . implode(' ', (array)($ct600aReview['errors'] ?? [])));
    }

    $taxPersistence = (new \eel_accounts\Service\CorporationTaxComputationService())
        ->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
    if (empty($taxPersistence['success'])) {
        throw new RuntimeException('Following-period CT persistence failed: ' . implode(' ', (array)($taxPersistence['errors'] ?? [])));
    }

    $lock = (new \eel_accounts\Service\YearEndLockService())
        ->lockPeriod($companyId, $accountingPeriodId, 'golden_following_period_control');
    $harness->assertTrue(!empty($lock['success']));
}

function goldenSaveReserveReviewForClose(int $companyId, int $accountingPeriodId): void
{
    $period = InterfaceDB::fetchOne(
        'SELECT period_end
         FROM accounting_periods
         WHERE company_id = :company_id
           AND id = :accounting_period_id
         LIMIT 1',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]
    );
    $review = (new \eel_accounts\Service\DividendReserveClassificationService())
        ->fetchReviewContext($companyId, $accountingPeriodId, (string)($period['period_end'] ?? ''));
    if (empty($review['available'])) {
        throw new RuntimeException('AP ' . $accountingPeriodId . ' reserve review unavailable: ' . implode(' ', (array)($review['errors'] ?? [])));
    }

    $treatments = [];
    foreach ((array)($review['rows'] ?? []) as $row) {
        $nominalId = (int)($row['nominal_account_id'] ?? 0);
        if ($nominalId > 0) {
            $treatments[(string)$nominalId] = (string)($row['treatment'] ?? 'unknown');
        }
    }

    $saved = (new \eel_accounts\Service\DividendReserveClassificationService())->saveReview(
        $companyId,
        $accountingPeriodId,
        $treatments,
        'golden_year_end_test',
        (string)($period['period_end'] ?? '')
    );
    if (empty($saved['success'])) {
        throw new RuntimeException('AP ' . $accountingPeriodId . ' reserve review save failed: ' . implode(' ', (array)($saved['errors'] ?? [])));
    }
    $verified = (new \eel_accounts\Service\DividendReserveClassificationService())
        ->fetchReviewContext($companyId, $accountingPeriodId, (string)($period['period_end'] ?? ''));
    if (empty($verified['snapshot_current'])) {
        throw new RuntimeException('AP ' . $accountingPeriodId . ' reserve review did not remain current after save.');
    }
}

/** @return array<string, mixed> */
function goldenFollowingPeriodEconomicSnapshot(int $companyId, int $accountingPeriodId): array
{
    $trialBalance = (new \eel_accounts\Service\TrialBalanceService())
        ->fetchStateSnapshot($companyId, $accountingPeriodId);
    $trialBalanceRows = [];
    foreach ((array)($trialBalance['rows'] ?? []) as $row) {
        $trialBalanceRows[(string)($row['nominal_code'] ?? '')] = goldenYearEndSelect($row, [
            'nominal_account_id',
            'total_debit',
            'total_credit',
            'net_movement',
            'display_debit',
            'display_credit',
            'closing_balance_nature',
            'journal_count',
        ]);
    }

    $depreciation = (new \eel_accounts\Service\AssetService())
        ->previewDepreciationRun($companyId, $accountingPeriodId);
    $capitalAllowances = (new \eel_accounts\Service\CapitalAllowanceService())
        ->fetchPeriodBreakdown($companyId, $accountingPeriodId, 0);
    $prepayments = (new \eel_accounts\Service\PrepaymentScheduleService())
        ->fetchPreviewAdjustmentContext($companyId, $accountingPeriodId);

    return [
        'reporting' => goldenYearEndReportingSnapshot($companyId, $accountingPeriodId),
        'trial_balance' => [
            'rows' => $trialBalanceRows,
            'totals' => (array)($trialBalance['totals'] ?? []),
            'summary' => (array)($trialBalance['summary'] ?? []),
        ],
        'assets' => [
            'pending_depreciation_total' => round((float)($depreciation['total_amount'] ?? 0), 2),
            'pending_depreciation_rows' => array_values((array)($depreciation['rows'] ?? [])),
            'capital_allowances' => array_values((array)($capitalAllowances['rows'] ?? [])),
            'capital_allowance_asset_calculations' => array_values(
                (array)($capitalAllowances['asset_calculations'] ?? [])
            ),
            'capital_allowance_warnings' => array_values((array)($capitalAllowances['warnings'] ?? [])),
            'capital_allowance_source' => (string)($capitalAllowances['calculation_source'] ?? ''),
        ],
        'prepayments' => [
            'reliable' => !empty($prepayments['success']),
            'adjustments' => array_values((array)($prepayments['adjustments'] ?? [])),
            'errors' => array_values((array)($prepayments['errors'] ?? [])),
        ],
    ];
}

/** @return array<string, list<array<string, mixed>>> */
function goldenFollowingPeriodRawState(int $companyId, int $accountingPeriodId): array
{
    $period = InterfaceDB::fetchOne(
        'SELECT period_start, period_end
         FROM accounting_periods
         WHERE id = :accounting_period_id
           AND company_id = :company_id
         LIMIT 1',
        [
            'accounting_period_id' => $accountingPeriodId,
            'company_id' => $companyId,
        ]
    );
    if (!is_array($period)) {
        throw new RuntimeException('The following accounting period could not be loaded for fingerprinting.');
    }

    $params = [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
    $assetParams = $params + [
        'period_start' => (string)$period['period_start'],
        'period_end' => (string)$period['period_end'],
    ];

    return [
        'accounting_periods' => goldenFollowingPeriodRows(
            'accounting_periods',
            'SELECT *
             FROM accounting_periods
             WHERE company_id = :company_id
               AND id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'statement_uploads' => goldenFollowingPeriodRows(
            'statement_uploads',
            'SELECT *
             FROM statement_uploads
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'transactions' => goldenFollowingPeriodRows(
            'transactions',
            'SELECT *
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'transaction_split_lines' => goldenFollowingPeriodRows(
            'transaction_split_lines',
            'SELECT tsl.*
             FROM transaction_split_lines tsl
             INNER JOIN transactions t ON t.id = tsl.transaction_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
             ORDER BY tsl.id',
            $params
        ),
        'expense_claims' => goldenFollowingPeriodRows(
            'expense_claims',
            'SELECT *
             FROM expense_claims
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'expense_claim_lines' => goldenFollowingPeriodRows(
            'expense_claim_lines',
            'SELECT ecl.*
             FROM expense_claim_lines ecl
             INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             WHERE ec.company_id = :company_id
               AND ec.accounting_period_id = :accounting_period_id
             ORDER BY ecl.id',
            $params
        ),
        'journals' => goldenFollowingPeriodRows(
            'journals',
            'SELECT *
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'journal_lines' => goldenFollowingPeriodRows(
            'journal_lines',
            'SELECT jl.*
             FROM journal_lines jl
             INNER JOIN journals j ON j.id = jl.journal_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
             ORDER BY jl.id',
            $params
        ),
        'journal_entry_metadata' => goldenFollowingPeriodRows(
            'journal_entry_metadata',
            'SELECT jem.*
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
             ORDER BY jem.id',
            $params
        ),
        'accounting_period_adjustments' => goldenFollowingPeriodRows(
            'accounting_period_adjustments',
            'SELECT *
             FROM accounting_period_adjustments
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'asset_register' => goldenFollowingPeriodRows(
            'asset_register',
            'SELECT *
             FROM asset_register
             WHERE company_id = :company_id
               AND purchase_date BETWEEN :period_start AND :period_end
             ORDER BY id',
            $assetParams
        ),
        'asset_vehicle_details' => goldenFollowingPeriodRows(
            'asset_vehicle_details',
            'SELECT avd.*
             FROM asset_vehicle_details avd
             INNER JOIN asset_register ar ON ar.id = avd.asset_id
             WHERE ar.company_id = :company_id
               AND ar.purchase_date BETWEEN :period_start AND :period_end
             ORDER BY avd.asset_id',
            $assetParams
        ),
        'asset_depreciation_entries' => goldenFollowingPeriodRows(
            'asset_depreciation_entries',
            'SELECT ade.*
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ar.company_id = :company_id
               AND ade.accounting_period_id = :accounting_period_id
             ORDER BY ade.id',
            $params
        ),
        'capital_allowance_pool_runs' => goldenFollowingPeriodRows(
            'capital_allowance_pool_runs',
            'SELECT *
             FROM capital_allowance_pool_runs
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'capital_allowance_asset_calculations' => goldenFollowingPeriodRows(
            'capital_allowance_asset_calculations',
            'SELECT *
             FROM capital_allowance_asset_calculations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'prepayment_schedules' => goldenFollowingPeriodRows(
            'prepayment_schedules',
            'SELECT ps.*
             FROM prepayment_schedules ps
             WHERE ps.company_id = :company_id
               AND EXISTS (
                   SELECT 1
                   FROM prepayment_schedule_periods psp
                   WHERE psp.schedule_id = ps.id
                     AND psp.accounting_period_id = :accounting_period_id
               )
             ORDER BY ps.id',
            $params
        ),
        'prepayment_schedule_periods' => goldenFollowingPeriodRows(
            'prepayment_schedule_periods',
            'SELECT psp.*
             FROM prepayment_schedule_periods psp
             INNER JOIN prepayment_schedules ps ON ps.id = psp.schedule_id
             WHERE ps.company_id = :company_id
               AND psp.accounting_period_id = :accounting_period_id
             ORDER BY psp.id',
            $params
        ),
        'prepayment_schedule_postings' => goldenFollowingPeriodRows(
            'prepayment_schedule_postings',
            'SELECT psp.*
             FROM prepayment_schedule_postings psp
             INNER JOIN prepayment_schedules ps ON ps.id = psp.schedule_id
             WHERE ps.company_id = :company_id
               AND psp.accounting_period_id = :accounting_period_id
             ORDER BY psp.id',
            $params
        ),
        'corporation_tax_periods' => goldenFollowingPeriodRows(
            'corporation_tax_periods',
            'SELECT *
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'corporation_tax_computation_runs' => goldenFollowingPeriodRows(
            'corporation_tax_computation_runs',
            'SELECT *
             FROM corporation_tax_computation_runs
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'tax_loss_carryforwards' => goldenFollowingPeriodRows(
            'tax_loss_carryforwards',
            'SELECT *
             FROM tax_loss_carryforwards
             WHERE company_id = :company_id
               AND origin_accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'tax_loss_movement_history' => goldenFollowingPeriodRows(
            'tax_loss_movement_history',
            'SELECT *
             FROM tax_loss_movement_history
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'hmrc_obligations' => goldenFollowingPeriodRows(
            'hmrc_obligations',
            'SELECT *
             FROM hmrc_obligations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'accounting_period_month_confirmations' => goldenFollowingPeriodRows(
            'accounting_period_month_confirmations',
            'SELECT *
             FROM accounting_period_month_confirmations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'year_end_reviews' => goldenFollowingPeriodRows(
            'year_end_reviews',
            'SELECT *
             FROM year_end_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'year_end_review_acknowledgements' => goldenFollowingPeriodRows(
            'year_end_review_acknowledgements',
            'SELECT *
             FROM year_end_review_acknowledgements
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
        'year_end_audit_log' => goldenFollowingPeriodRows(
            'year_end_audit_log',
            'SELECT *
             FROM year_end_audit_log
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            $params
        ),
    ];
}

/** @return list<array<string, mixed>> */
function goldenFollowingPeriodRows(string $table, string $sql, array $params): array
{
    if (!InterfaceDB::tableExists($table)) {
        return [];
    }

    return array_values(InterfaceDB::fetchAll($sql, $params) ?: []);
}

function goldenAssertFollowingPeriodInvariant(
    array $expected,
    array $actual,
    int $accountingPeriodId,
    string $field
): void {
    // Closing a predecessor correctly changes opening distributable reserves
    // in later periods. It must not change that later period's own ledger,
    // tax calculation, or reporting basis, so omit only the inherited reserve
    // presentation from this immutability comparison.
    foreach (['retained_earnings_brought_forward', 'distributable_reserves_brought_forward', 'available_distributable_reserves'] as $key) {
        unset($expected['reporting']['dividends'][$key], $actual['reporting']['dividends'][$key]);
    }

    if ($expected === $actual) {
        return;
    }

    throw new RuntimeException(GoldenComparisonReporter::report([[
        'page' => 'year_end',
        'card' => 'following_period_immutability',
        'period' => $accountingPeriodId,
        'field' => $field,
        'expected' => $expected,
        'actual' => $actual,
    ]]));
}

function goldenAssertFollowingPeriodComplete(
    GeneratedServiceClassTestHarness $harness,
    array $snapshot
): void {
    $reporting = (array)($snapshot['reporting'] ?? []);
    $tax = (array)($reporting['tax'] ?? []);
    $profitLoss = (array)($reporting['profit_loss'] ?? []);
    $companiesHouse = (array)($reporting['companies_house'] ?? []);
    $trialBalance = (array)($snapshot['trial_balance'] ?? []);
    $trialBalanceSummary = (array)($trialBalance['summary'] ?? []);
    $trialBalanceStatus = (array)($trialBalanceSummary['trial_balance_status'] ?? []);
    $taxDetail = (array)($reporting['tax_detail'] ?? []);
    $capitalAllowanceSummary = (array)($taxDetail['capital_allowances_summary'] ?? []);

    $harness->assertSame(
        number_format((float)($tax['estimated_corporation_tax'] ?? 0), 2, '.', ''),
        number_format((float)($profitLoss['estimated_corporation_tax'] ?? 0), 2, '.', '')
    );
    $harness->assertSame(
        number_format(
            (float)($profitLoss['profit_before_tax'] ?? 0)
                - (float)($tax['estimated_corporation_tax'] ?? 0),
            2,
            '.',
            ''
        ),
        number_format((float)($profitLoss['profit_after_estimated_tax'] ?? 0), 2, '.', '')
    );
    $harness->assertTrue(!empty($companiesHouse['is_balance_sheet_balanced']));
    $harness->assertTrue(!empty($trialBalanceStatus['is_balanced']));
    $harness->assertSame(
        number_format((float)($companiesHouse['net_assets_liabilities'] ?? 0), 2, '.', ''),
        number_format((float)($trialBalanceSummary['net_assets'] ?? 0), 2, '.', '')
    );
    $harness->assertSame(
        number_format((float)($tax['capital_allowances'] ?? 0), 2, '.', ''),
        number_format((float)($capitalAllowanceSummary['net_capital_allowances'] ?? 0), 2, '.', '')
    );
    $harness->assertSame(
        'main_pool',
        (string)($taxDetail['main_rate_pool']['pool_type'] ?? '')
    );
    $harness->assertSame(
        'special_rate_pool',
        (string)($taxDetail['special_rate_pool']['pool_type'] ?? '')
    );
}

function goldenAssertFirstPeriodLockBoundary(GeneratedServiceClassTestHarness $harness, int $companyId): void
{
    $lockService = new \eel_accounts\Service\YearEndLockService();
    $harness->assertTrue($lockService->isLocked($companyId, 9111));
    foreach ([9112, 9113, 9114] as $openPeriodId) {
        $harness->assertFalse($lockService->isLocked($companyId, $openPeriodId));
        $lockService->assertUnlocked($companyId, $openPeriodId, 'edit this later golden period');
    }

    $periodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();
    $lockedPeriodBefore = $periodRepository->fetchAccountingPeriod($companyId, 9111);
    $periodEditRejected = false;
    try {
        $periodRepository->updatePeriod($companyId, 9111, 'Tampered locked period', '2022-09-06', '2023-09-29');
    } catch (RuntimeException $exception) {
        $periodEditRejected = str_contains($exception->getMessage(), 'locked');
    }
    $harness->assertTrue($periodEditRejected);
    $harness->assertSame($lockedPeriodBefore, $periodRepository->fetchAccountingPeriod($companyId, 9111));

    $openPeriodBefore = $periodRepository->fetchAccountingPeriod($companyId, 9112);
    $periodRepository->updatePeriod(
        $companyId,
        9112,
        (string)$openPeriodBefore['label'] . ' edit check',
        (string)$openPeriodBefore['period_start'],
        (string)$openPeriodBefore['period_end']
    );
    $harness->assertSame((string)$openPeriodBefore['label'] . ' edit check', (string)$periodRepository->fetchAccountingPeriod($companyId, 9112)['label']);
    $periodRepository->updatePeriod(
        $companyId,
        9112,
        (string)$openPeriodBefore['label'],
        (string)$openPeriodBefore['period_start'],
        (string)$openPeriodBefore['period_end']
    );

    $reviewBefore = $lockService->fetchReview($companyId, 9111);
    foreach ([
        static fn(): array => $lockService->saveNotes($companyId, 9111, 'Tampered notes', 'golden_lock_test'),
        static fn(): array => (new \eel_accounts\Service\YearEndChecklistService())->recalculateChecklist($companyId, 9111, 'golden_lock_test'),
    ] as $lockedMutation) {
        $rejected = false;
        try {
            $lockedMutation();
        } catch (RuntimeException $exception) {
            $rejected = str_contains($exception->getMessage(), 'locked');
        }
        $harness->assertTrue($rejected);
    }
    $harness->assertSame($reviewBefore, $lockService->fetchReview($companyId, 9111));

    $uploadBase = testPageServiceUploadBasePath();
    $csvPath = tempnam(sys_get_temp_dir(), 'golden-lock-');
    if (!is_string($csvPath)) {
        throw new RuntimeException('Unable to create the golden upload control file.');
    }
    file_put_contents($csvPath, "Date,Description,Amount,Balance\n2023-10-12,Unlocked upload control,12.34,12.34\n");
    $file = ['name' => 'golden-lock-control.csv', 'tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'type' => 'text/csv'];
    $uploadService = new \eel_accounts\Service\StatementUploadService($uploadBase);
    $lockedUpload = $uploadService->createUploadFromHttpRequest(
        ['company_id' => $companyId, 'account_id' => 9120, 'accounting_period_id' => 9111],
        ['statement_file' => $file]
    );
    $harness->assertFalse(!empty($lockedUpload['success']));
    $harness->assertSame(409, (int)($lockedUpload['http_status'] ?? 0));

    @unlink($csvPath);
    $openUploadHash = hash('sha256', 'golden-open-upload-control');
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, account_id, source_type, workflow_status,
            statement_month, original_filename, stored_filename, file_sha256, rows_parsed
        ) VALUES (
            :company_id, :accounting_period_id, :account_id, :source_type, :workflow_status,
            :statement_month, :original_filename, :stored_filename, :file_sha256, 0
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => 9112,
            'account_id' => 9120,
            'source_type' => 'bank_account',
            'workflow_status' => 'uploaded',
            'statement_month' => '2023-10-01',
            'original_filename' => 'golden-open-control.csv',
            'stored_filename' => 'golden-open-control.csv',
            'file_sha256' => $openUploadHash,
        ]
    );
    $openUploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND file_sha256 = :file_sha256 ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId, 'file_sha256' => $openUploadHash]
    );
    $harness->assertTrue($openUploadId > 0);
    $harness->assertFalse(!empty($uploadService->fetchUploadLockState($companyId, $openUploadId)['is_locked']));

    InterfaceDB::execute(
        'INSERT INTO statement_import_rows (upload_id, `row_number`, raw_json, accounting_period_id, chosen_txn_date, normalised_description, normalised_amount, normalised_currency, row_hash, validation_status, is_duplicate_within_upload, is_duplicate_existing)
         VALUES (:upload_id, 999, :raw_json, 9111, :txn_date, :description, :amount, :currency, :row_hash, :status, 0, 0)',
        [
            'upload_id' => $openUploadId,
            'raw_json' => '{}',
            'txn_date' => '2023-09-20',
            'description' => 'Locked mixed-period row',
            'amount' => '1.00',
            'currency' => 'GBP',
            'row_hash' => hash('sha256', 'golden-locked-mixed-period-row'),
            'status' => 'valid',
        ]
    );
    $mixedLockState = $uploadService->fetchUploadLockState($companyId, $openUploadId);
    $harness->assertTrue(!empty($mixedLockState['is_locked']));
    $harness->assertSame([9111, 9112], array_map('intval', (array)$mixedLockState['accounting_period_ids']));
    $mixedCommit = $uploadService->commitUpload($companyId, $openUploadId);
    $harness->assertFalse(!empty($mixedCommit['success']));
    $harness->assertSame(409, (int)($mixedCommit['http_status'] ?? 0));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM transactions WHERE statement_upload_id = :upload_id', ['upload_id' => $openUploadId]));
    $checksumBefore = (string)InterfaceDB::fetchColumn(
        'SELECT row_hash FROM statement_import_rows WHERE upload_id = :upload_id AND `row_number` = 999',
        ['upload_id' => $openUploadId]
    );
    $checksumResult = $uploadService->recalculateCompanyChecksums($companyId);
    $harness->assertTrue((int)($checksumResult['rows_locked_skipped'] ?? 0) >= 1);
    $harness->assertSame(
        $checksumBefore,
        (string)InterfaceDB::fetchColumn('SELECT row_hash FROM statement_import_rows WHERE upload_id = :upload_id AND `row_number` = 999', ['upload_id' => $openUploadId])
    );

    InterfaceDB::execute('DELETE FROM statement_import_rows WHERE upload_id = :upload_id', ['upload_id' => $openUploadId]);
    InterfaceDB::execute('DELETE FROM statement_import_mappings WHERE upload_id = :upload_id', ['upload_id' => $openUploadId]);
    InterfaceDB::execute('DELETE FROM statement_uploads WHERE id = :upload_id', ['upload_id' => $openUploadId]);

    $cardLockMechanisms = [
        'accounting_periods' => 'selected_period_locked', 'uploads_bank_transactions' => 'isLocked', 'uploads_details' => 'lock_state',
        'uploads_validate_commit' => 'selected_upload_lock_state', 'transactions_imported' => 'is_locked',
        'year_end_empty_month_confirmations' => 'YearEndApprovalRenderer', 'year_end_transaction_tail' => 'YearEndApprovalRenderer',
        'expense_claim_editor' => 'isLocked', 'expense_claim_create' => 'isLocked', 'year_end_expenses_confirmation' => 'YearEndApprovalRenderer',
        'year_end_loan_confirmation' => 'locked', 'dividend_declare' => 'locked', 'reserve_review' => 'locked',
        'asset_create' => 'isLocked', 'asset_reconcile_manual' => 'selected_period_locked', 'not_an_asset' => 'nonAssetReview',
        'prepayments_review' => 'isLocked', 'year_end_prepayment_approvals' => 'locked', 'journal_cut_off_create' => 'isLocked', 'journal_manual_entry' => 'isLocked',
        'journal_cut_off_confirmation' => 'YearEndApprovalRenderer', 'year_end_profit_loss_confirm' => 'locked',
        'year_end_companies_house_comparison' => 'YearEndApprovalRenderer', 'year_end_tax_readiness' => 'YearEndApprovalRenderer',
        'year_end_notes' => 'isLocked', 'year_end_state' => 'isLocked',
    ];
    foreach ($cardLockMechanisms as $cardKey => $mechanism) {
        $source = (string)file_get_contents(APP_CARDS . $cardKey . '.php');
        $harness->assertTrue(str_contains($source, $mechanism));
    }
}

/** @return array<string, mixed> */
function goldenYearEndReportingSnapshot(int $companyId, int $periodId): array
{
    $tax = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings($companyId, $periodId, 0);
    $companiesHouse = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot($companyId, $periodId);
    $dividends = (new \eel_accounts\Service\DividendViewDataService())->fetchCapacityContext($companyId, $periodId);
    $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary($companyId, $periodId);
    $companiesHouseFields = goldenYearEndFieldsByKey((array)($companiesHouse['fields'] ?? []));
    $capacity = (array)($dividends['capacity'] ?? []);

    return [
        'tax' => goldenYearEndSelect((array)($tax['summary'] ?? []), [
            'accounting_profit', 'disallowable_add_backs', 'depreciation_add_back', 'capital_allowances',
            'taxable_before_losses', 'taxable_profit', 'taxable_loss', 'estimated_corporation_tax',
            'losses_brought_forward', 'losses_used', 'losses_carried_forward',
        ]),
        'tax_detail' => [
            'capital_allowances_summary' => (array)($tax['capital_allowances_summary'] ?? []),
            'aia_allocation' => array_values((array)($tax['aia_allocation'] ?? [])),
            'main_rate_pool' => (array)($tax['main_rate_pool'] ?? []),
            'special_rate_pool' => (array)($tax['special_rate_pool'] ?? []),
            'car_co2_treatment' => array_values((array)($tax['car_co2_treatment'] ?? [])),
            'disposals_balancing' => array_values((array)($tax['disposals_balancing'] ?? [])),
            'warnings' => array_values((array)($tax['warnings'] ?? [])),
        ],
        'companies_house' => [
            'fixed_assets' => $companiesHouseFields['fixed_assets'] ?? null,
            'current_assets' => $companiesHouseFields['current_assets'] ?? null,
            'creditors_within_one_year' => $companiesHouseFields['creditors_within_one_year'] ?? null,
            'creditors_after_more_than_one_year' => $companiesHouseFields['creditors_after_more_than_one_year'] ?? null,
            'net_assets_liabilities' => $companiesHouseFields['net_assets_liabilities'] ?? null,
            'equity_capital_reserves' => $companiesHouseFields['equity_capital_reserves'] ?? null,
            'balance_equation_difference' => $companiesHouse['balance_equation_difference'] ?? null,
            'is_balance_sheet_balanced' => $companiesHouse['is_balance_sheet_balanced'] ?? null,
        ],
        'dividends' => goldenYearEndSelect($capacity, [
            'retained_earnings_brought_forward', 'distributable_reserves_brought_forward',
            'ledger_current_year_profit_loss', 'posted_corporation_tax_charge',
            'estimated_corporation_tax', 'unposted_corporation_tax_adjustment',
            'current_year_profit_loss_after_tax', 'dividends_declared', 'available_distributable_reserves',
        ]),
        'profit_loss' => goldenYearEndSelect($profitLoss, [
            'income_total', 'cost_of_sales_total', 'gross_profit', 'operating_expense_total',
            'depreciation_expense', 'profit_before_tax', 'posted_corporation_tax_charge',
            'estimated_corporation_tax', 'profit_after_posted_tax', 'profit_after_estimated_tax', 'net_profit',
        ]),
    ];
}

/** @param array<string, mixed> $source @param list<string> $keys @return array<string, mixed> */
function goldenYearEndSelect(array $source, array $keys): array
{
    $selected = [];
    foreach ($keys as $key) {
        $selected[$key] = $source[$key] ?? null;
    }
    return $selected;
}

/** @param list<array<string, mixed>> $fields @return array<string, mixed> */
function goldenYearEndFieldsByKey(array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $values[(string)($field['key'] ?? '')] = $field['value'] ?? null;
    }
    return $values;
}
