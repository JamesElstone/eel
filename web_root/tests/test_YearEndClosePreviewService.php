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
    \eel_accounts\Service\YearEndClosePreviewService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndClosePreviewService $service): void {
        $harness->check(\eel_accounts\Service\YearEndClosePreviewService::class, 'previews year-end close postings in reporting before lock', static function () use ($harness, $service): void {
            yearEndClosePreviewRequireSchema($harness);

            InterfaceDB::beginTransaction();
            try {
                $fixture = yearEndClosePreviewCreateFixture();
                (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );

                $directorLoanAck = (new \eel_accounts\Service\YearEndLockService())->saveDirectorLoanClosingAcknowledgement(
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
                $harness->assertSame('457.00', number_format($fullDepreciation, 2, '.', ''));

                $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $harness->assertSame('457.00', number_format((float)($profitLoss['expense_total'] ?? 0), 2, '.', ''));
                $harness->assertSame('543.00', number_format((float)($profitLoss['net_profit'] ?? 0), 2, '.', ''));

                $snapshot = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $fields = yearEndClosePreviewSnapshotFields($snapshot);
                $harness->assertSame('0.00', number_format((float)($fields['fixed_assets'] ?? -1), 2, '.', ''));
                $harness->assertSame('543.00', number_format((float)($fields['current_assets'] ?? 0), 2, '.', ''));
                $harness->assertSame('0.00', number_format((float)($fields['creditors_within_one_year'] ?? -1), 2, '.', ''));
                $harness->assertSame('543.00', number_format((float)($fields['equity_capital_reserves'] ?? 0), 2, '.', ''));
                $harness->assertSame(true, (bool)($snapshot['is_balance_sheet_balanced'] ?? false));

                $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetchForAccountingPeriod(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $harness->assertSame(2, count($ctPeriods));
                $ctService = new \eel_accounts\Service\CorporationTaxComputationService();
                $firstSummary = $ctService->calculateSummaryForCtPeriodId((int)$fixture['company_id'], (int)$ctPeriods[0]['id']);
                $secondSummary = $ctService->calculateSummaryForCtPeriodId((int)$fixture['company_id'], (int)$ctPeriods[1]['id']);

                $harness->assertSame('365.00', number_format((float)($firstSummary['depreciation_add_back'] ?? 0), 2, '.', ''));
                $harness->assertSame('-365.00', number_format((float)($firstSummary['accounting_profit'] ?? 0), 2, '.', ''));
                $harness->assertSame('92.00', number_format((float)($secondSummary['depreciation_add_back'] ?? 0), 2, '.', ''));
                $harness->assertSame('908.00', number_format((float)($secondSummary['accounting_profit'] ?? 0), 2, '.', ''));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'persists CT lock snapshots from fresh calculations after retained earnings close posting', static function () use ($harness): void {
            yearEndClosePreviewRequireSchema($harness);
            if (!InterfaceDB::tableExists('corporation_tax_computation_runs')) {
                $harness->skip('Corporation Tax computation runs table is not available.');
            }
            if (!InterfaceDB::columnExists('corporation_tax_periods', 'latest_computation_run_id')) {
                $harness->skip('Corporation Tax latest computation column is not available.');
            }

            InterfaceDB::beginTransaction();
            try {
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
                $staleSummary = $ctService->fetchSummaryForCtPeriodId($companyId, $targetCtPeriodId);

                yearEndClosePreviewInsertJournal($companyId, $accountingPeriodId, (string)$fixture['company_id'] . '-extra-expense', '2025-12-15', [
                    [yearEndClosePreviewNominalId('6200'), 50.00, 0.00],
                    [yearEndClosePreviewNominalId('1000'), 0.00, 50.00],
                ]);

                $retainedEarningsService = new \eel_accounts\Service\RetainedEarningsCloseService();
                $acknowledged = $retainedEarningsService->saveAcknowledgement($companyId, $accountingPeriodId, true, 'test');
                $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
                $postedClose = $retainedEarningsService->postClose($companyId, $accountingPeriodId, 'test');
                $harness->assertSame(true, (bool)($postedClose['success'] ?? false));

                $persisted = $ctService->persistSummariesForAccountingPeriod($companyId, $accountingPeriodId);
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
                    number_format(round((float)($staleSummary['accounting_profit'] ?? 0) - 50.00, 2), 2, '.', ''),
                    number_format((float)($targetSummary['accounting_profit'] ?? 0), 2, '.', '')
                );
                $harness->assertTrue((int)($targetSummary['computation_run_id'] ?? 0) > 0);
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
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
        'year_end_reviews',
    ] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach ([
        '1000',
        '1200',
        '1300',
        '1330',
        '2100',
        '3000',
        '4000',
        '6200',
    ] as $code) {
        if (yearEndClosePreviewNominalId($code) <= 0) {
            $harness->skip('Nominal account ' . $code . ' is not available.');
        }
    }

    foreach ([
        'director_loan_closing_acknowledged_at',
        'director_loan_closing_acknowledged_by',
        'retained_earnings_close_acknowledged_at',
        'retained_earnings_close_acknowledged_by',
        'retained_earnings_close_current_profit_loss',
        'retained_earnings_close_amount',
    ] as $column) {
        if (!InterfaceDB::columnExists('year_end_reviews', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }
}

function yearEndClosePreviewCreateFixture(): array
{
    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Year End Close Preview Fixture Limited',
            'company_number' => 'YCP' . substr($marker, 0, 8),
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number',
        ['company_number' => 'YCP' . substr($marker, 0, 8)]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'YCP ' . $marker,
            'period_start' => '2024-10-01',
            'period_end' => '2025-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'YCP ' . $marker]
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
