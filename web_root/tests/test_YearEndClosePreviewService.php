<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\YearEndClosePreviewService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndClosePreviewService $service): void {
        $harness->check(\eel_accounts\Service\YearEndClosePreviewService::class, 'previews year-end close postings in reporting before lock', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                yearEndClosePreviewRequireSchema($harness);
                StandardNominalTestFixture::ensureNominals(['1000', '1200', '1300', '1330', '2100', '3000', '4000', '6200']);
                $fixture = yearEndClosePreviewCreateFixture();
                (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );

                $directorLoanAck = (new \eel_accounts\Service\YearEndChecklistService())->saveDirectorLoanClosingAcknowledgement(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    true,
                    'test'
                );
                $harness->assertSame(true, (bool)($directorLoanAck['success'] ?? false));

                $retainedEarningsAck = (new \eel_accounts\Service\RetainedEarningsCloseService())->saveAcknowledgement(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    true,
                    'test'
                );
                $harness->assertSame(true, (bool)($retainedEarningsAck['success'] ?? false));

                $fullDepreciation = $service->depreciationExpenseForPeriod(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    '2024-10-01',
                    '2025-12-31'
                );
                $harness->assertSame('455.75', number_format($fullDepreciation, 2, '.', ''));

                $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $harness->assertSame('455.75', number_format((float)($profitLoss['expense_total'] ?? 0), 2, '.', ''));
                $harness->assertSame('544.25', number_format((float)($profitLoss['net_profit'] ?? 0), 2, '.', ''));

                $monthlyTrend = (new \eel_accounts\Service\ProfitLossService())->getMonthlyProfitLossTrend(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $harness->assertSame(
                    '455.75',
                    number_format(array_sum(array_column($monthlyTrend, 'depreciation_expense')), 2, '.', '')
                );
                $harness->assertSame(
                    number_format((float)($profitLoss['operating_expense_total'] ?? 0), 2, '.', ''),
                    number_format(array_sum(array_column($monthlyTrend, 'operating_expense_total')), 2, '.', '')
                );
                $harness->assertSame(
                    number_format((float)($profitLoss['profit_before_tax'] ?? 0), 2, '.', ''),
                    number_format(array_sum(array_column($monthlyTrend, 'profit_before_tax')), 2, '.', '')
                );

                $reserveReview = (new \eel_accounts\Service\DividendReserveClassificationService())->fetchReviewContext(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    '2025-12-31'
                );
                $harness->assertSame(true, !empty($reserveReview['available']));
                $harness->assertSame(
                    '544.25',
                    number_format((float)($reserveReview['summary']['ledger_profit_loss'] ?? 0), 2, '.', '')
                );
                $previewRows = array_values(array_filter(
                    (array)($reserveReview['rows'] ?? []),
                    static fn(array $row): bool => !empty($row['is_close_preview'])
                ));
                $harness->assertSame(1, count($previewRows));
                $harness->assertSame('-455.75', number_format((float)($previewRows[0]['profit_effect'] ?? 0), 2, '.', ''));

                $snapshot = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $fields = yearEndClosePreviewSnapshotFields($snapshot);
                $fixedAssets = (float)($fields['fixed_assets'] ?? -1);
                $currentAssets = (float)($fields['current_assets'] ?? 0);
                $currentCreditors = (float)($fields['creditors_within_one_year'] ?? 0);
                $netCurrentAssets = (float)($fields['net_current_assets_liabilities'] ?? 0);
                $totalAssets = (float)($fields['total_assets_less_current_liabilities'] ?? 0);
                $longTermCreditors = (float)($fields['creditors_after_more_than_one_year'] ?? 0);
                $netAssets = (float)($fields['net_assets_liabilities'] ?? 0);
                $equity = (float)($fields['equity_capital_reserves'] ?? 0);
                $harness->assertTrue($fixedAssets >= 0.0);
                $harness->assertSame(number_format($currentAssets - $currentCreditors, 2, '.', ''), number_format($netCurrentAssets, 2, '.', ''));
                $harness->assertSame(number_format($fixedAssets + $netCurrentAssets, 2, '.', ''), number_format($totalAssets, 2, '.', ''));
                $harness->assertSame(number_format($totalAssets - $longTermCreditors, 2, '.', ''), number_format($netAssets, 2, '.', ''));
                $harness->assertSame(number_format($netAssets, 2, '.', ''), number_format($equity, 2, '.', ''));
                $harness->assertSame(true, (bool)($snapshot['is_balance_sheet_balanced'] ?? false));

                $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetchForAccountingPeriod(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $harness->assertSame(2, count($ctPeriods));
                $ctService = new \eel_accounts\Service\CorporationTaxComputationService();
                $firstSummary = $ctService->calculateSummaryForCtPeriodId((int)$fixture['company_id'], (int)$ctPeriods[0]['id']);
                $secondSummary = $ctService->calculateSummaryForCtPeriodId((int)$fixture['company_id'], (int)$ctPeriods[1]['id']);
                $ctService->fetchSummaryForCtPeriodId((int)$fixture['company_id'], (int)$ctPeriods[0]['id']);

                $harness->assertSame('364.00', number_format((float)($firstSummary['depreciation_add_back'] ?? 0), 2, '.', ''));
                $harness->assertSame('434.69', number_format((float)($firstSummary['accounting_profit'] ?? 0), 2, '.', ''));
                $harness->assertSame('91.75', number_format((float)($secondSummary['depreciation_add_back'] ?? 0), 2, '.', ''));
                $harness->assertSame('109.56', number_format((float)($secondSummary['accounting_profit'] ?? 0), 2, '.', ''));
                $harness->assertSame(0, InterfaceDB::countWhere('corporation_tax_computation_runs', [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                ]));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'persists CT lock snapshots from fresh calculations after retained earnings close posting', static function () use ($harness): void {
            if (!InterfaceDB::tableExists('corporation_tax_computation_runs')) {
                $harness->skip('Corporation Tax computation runs table is not available.');
            }
            if (!InterfaceDB::columnExists('corporation_tax_periods', 'latest_computation_run_id')) {
                $harness->skip('Corporation Tax latest computation column is not available.');
            }

            InterfaceDB::beginTransaction();
            try {
                yearEndClosePreviewRequireSchema($harness);
                StandardNominalTestFixture::ensureNominals(['1000', '1200', '1300', '1330', '2100', '3000', '4000', '6200']);
                $fixture = yearEndClosePreviewCreateFixture();
                $companyId = (int)$fixture['company_id'];
                $accountingPeriodId = (int)$fixture['accounting_period_id'];
                (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);
                $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);
                if (count($ctPeriods) < 2) {
                    $harness->skip('The fixture did not create multiple CT periods.');
                }
                $targetCtPeriodId = (int)$ctPeriods[1]['id'];

                $ctService = new \eel_accounts\Service\CorporationTaxComputationService();
                $initialSummary = $ctService->calculateSummaryForCtPeriodId($companyId, $targetCtPeriodId);
                $harness->assertSame(0, InterfaceDB::countWhere('corporation_tax_computation_runs', [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]));

                yearEndClosePreviewInsertJournal($companyId, $accountingPeriodId, (string)$fixture['company_id'] . '-extra-expense', '2025-12-15', [
                    [yearEndClosePreviewNominalId('6200'), 50.00, 0.00],
                    [yearEndClosePreviewNominalId('1000'), 0.00, 50.00],
                ]);

                $changedLiveSummary = (new \eel_accounts\Service\CorporationTaxComputationService())
                    ->fetchSummaryForCtPeriodId($companyId, $targetCtPeriodId);
                $harness->assertSame('not_persisted', (string)($changedLiveSummary['computation_persistence']['status'] ?? ''));
                $harness->assertSame(false, !empty($changedLiveSummary['computation_persistence']['current']));
                $harness->assertSame(false, in_array(
                    'No CT computation snapshot has been persisted for the current live inputs.',
                    (array)($changedLiveSummary['warnings'] ?? []),
                    true
                ));

                $retainedEarningsService = new \eel_accounts\Service\RetainedEarningsCloseService();
                $acknowledged = $retainedEarningsService->saveAcknowledgement($companyId, $accountingPeriodId, true, 'test');
                $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
                $postedClose = $retainedEarningsService->postClose($companyId, $accountingPeriodId, 'test');
                $harness->assertSame(true, (bool)($postedClose['success'] ?? false));

                $persisted = (new \eel_accounts\Service\CorporationTaxComputationService())
                    ->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
                $harness->assertSame(true, (bool)($persisted['success'] ?? false));

                $targetSummary = null;
                foreach ((array)($persisted['summaries'] ?? []) as $summary) {
                    if ((int)($summary['ct_period_id'] ?? 0) === $targetCtPeriodId) {
                        $targetSummary = $summary;
                        break;
                    }
                }

                $harness->assertTrue(is_array($targetSummary));
                $harness->assertSame(
                    number_format((float)($changedLiveSummary['accounting_profit'] ?? 0), 2, '.', ''),
                    number_format((float)($targetSummary['accounting_profit'] ?? 0), 2, '.', '')
                );
                $harness->assertTrue((int)($targetSummary['computation_run_id'] ?? 0) > 0);
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'requires the lock transaction and rolls generated CT evidence back with a failed lock', static function () use ($harness): void {
            if (!InterfaceDB::tableExists('corporation_tax_computation_runs')) {
                $harness->skip('Corporation Tax computation runs table is not available.');
            }

            $service = new \eel_accounts\Service\CorporationTaxComputationService();
            $refused = $service->persistSummariesForYearEndLock(1, 1);
            $harness->assertSame(false, (bool)($refused['success'] ?? true));

            $companyId = 0;
            $accountingPeriodId = 0;
            InterfaceDB::beginTransaction();
            try {
                yearEndClosePreviewRequireSchema($harness);
                StandardNominalTestFixture::ensureNominals(['1000', '1200', '1300', '1330', '2100', '3000', '4000', '6200']);
                $fixture = yearEndClosePreviewCreateFixture();
                $companyId = (int)$fixture['company_id'];
                $accountingPeriodId = (int)$fixture['accounting_period_id'];
                (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);

                $persisted = $service->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
                $harness->assertSame(true, (bool)($persisted['success'] ?? false));
                $harness->assertTrue(InterfaceDB::countWhere('corporation_tax_computation_runs', [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]) > 0);

                // Simulate a later failure in the Year End lock workflow.
                InterfaceDB::rollBack();
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }

            $harness->assertSame(0, InterfaceDB::countWhere('corporation_tax_computation_runs', [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]));
        });
    }
);

function yearEndClosePreviewRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach ([
        'companies',
        'accounting_periods',
        'corporation_tax_periods',
        'journals',
        'journal_lines',
        'journal_entry_metadata',
        'nominal_accounts',
        'nominal_account_subtypes',
        'asset_register',
        'asset_depreciation_entries',
        'dividend_reserve_classification_rules',
        'dividend_reserve_review_snapshots',
    ] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

}

function yearEndClosePreviewCreateFixture(): array
{
    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
    $numericMarker = (string)random_int(100000, 999999);
    $companyId = (int)('71' . $numericMarker);
    $accountingPeriodId = (int)('72' . $numericMarker);
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Close Preview Fixture Limited',
            'company_number' => 'YCP' . substr($marker, 0, 8),
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YCP ' . $marker,
            'period_start' => '2024-10-01',
            'period_end' => '2025-12-31',
        ]
    );

    yearEndClosePreviewInsertJournal($companyId, $accountingPeriodId, $marker . '-sales', '2025-12-31', [
        [yearEndClosePreviewNominalId('1000'), 1000.00, 0.00],
        [yearEndClosePreviewNominalId('4000'), 0.00, 1000.00],
    ]);
    yearEndClosePreviewInsertJournal($companyId, $accountingPeriodId, $marker . '-asset', '2024-10-01', [
        [yearEndClosePreviewNominalId('1300'), 457.00, 0.00],
        [yearEndClosePreviewNominalId('1000'), 0.00, 457.00],
    ]);
    yearEndClosePreviewInsertJournal($companyId, $accountingPeriodId, $marker . '-director-loan', '2025-12-31', [
        [yearEndClosePreviewNominalId('1200'), 100.00, 0.00],
        [yearEndClosePreviewNominalId('2100'), 0.00, 100.00],
    ]);

    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'company_id' => $companyId,
            'asset_code' => 'YCP-' . substr($marker, 0, 8),
            'description' => 'Year-end close preview fixture asset',
            'category' => 'tools_equipment',
            'nominal_account_id' => yearEndClosePreviewNominalId('1300'),
            'accum_dep_nominal_id' => yearEndClosePreviewNominalId('1330'),
            'purchase_date' => '2024-10-01',
            'cost' => 457.00,
            'useful_life_years' => 1,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function yearEndClosePreviewInsertJournal(int $companyId, int $accountingPeriodId, string $sourceRef, string $date, array $lines): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => 'Year-end close preview fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );

    foreach ($lines as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)$line[0],
                'debit' => number_format((float)$line[1], 2, '.', ''),
                'credit' => number_format((float)$line[2], 2, '.', ''),
                'line_description' => 'Year-end close preview fixture',
            ]
        );
    }
}

function yearEndClosePreviewNominalId(string $code): int
{
    return (int)(InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code AND is_active = 1 LIMIT 1',
        ['code' => $code]
    ) ?: 0);
}

function yearEndClosePreviewSnapshotFields(array $snapshot): array
{
    $fields = [];
    foreach ((array)($snapshot['fields'] ?? []) as $field) {
        $fields[(string)($field['key'] ?? '')] = round((float)($field['value'] ?? 0), 2);
    }

    return $fields;
}
