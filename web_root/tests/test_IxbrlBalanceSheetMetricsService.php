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
    \eel_accounts\Service\IxbrlBalanceSheetMetricsService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlBalanceSheetMetricsService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlBalanceSheetMetricsService::class, 'uses closing balances across prior and current journals', static function () use ($harness, $service): void {
            $fixture = ixbrlBalanceSheetMetricsFixture();
            $metrics = $service->fetchClosingMetrics((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $buckets = (array)($metrics['buckets'] ?? []);

            $harness->assertSame(1000.0, $buckets['fixed_assets'] ?? null);
            $harness->assertSame(500.0, $buckets['current_assets'] ?? null);
            $harness->assertSame(50.0, $buckets['creditors_within_one_year'] ?? null);
            $harness->assertSame(400.0, $buckets['creditors_after_more_than_one_year'] ?? null);
            $harness->assertSame(450.0, $buckets['net_current_assets_liabilities'] ?? null);
            $harness->assertSame(1050.0, $buckets['net_assets_liabilities'] ?? null);
            $harness->assertSame(true, $metrics['is_balance_sheet_balanced'] ?? false);
            $harness->assertSame(false, (bool)($metrics['reliable_closing_balance'] ?? true));
            $harness->assertSame('prior_period_unlocked', (string)(($metrics['prior_period_dependency'] ?? [])['status'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\IxbrlBalanceSheetMetricsService::class, 'splits prepayments from current assets without changing balance-sheet subtotals', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                $suffix = substr(hash('sha256', __FILE__ . ':prepayments:' . microtime(true)), 0, 10);
                $companyNumber = 'IP' . strtoupper(substr($suffix, 0, 8));
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                    ['company_name' => 'iXBRL Prepayment Split Limited', 'company_number' => $companyNumber]
                );
                $companyId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM companies WHERE company_number = :company_number',
                    ['company_number' => $companyNumber]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
                    ['company_id' => $companyId, 'label' => 'Prepayment Split FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
                );
                $periodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id',
                    ['company_id' => $companyId]
                );
                $bank = ixbrlBalanceSheetNominal(
                    'PB' . $suffix,
                    'Fixture Bank',
                    'asset',
                    ixbrlBalanceSheetSubtype('bank', 'Bank', 'asset')
                );
                $prepayment = ixbrlBalanceSheetNominal(
                    'PP' . $suffix,
                    'Fixture Prepayments',
                    'asset',
                    ixbrlBalanceSheetSubtype('prepayments', 'Prepayments', 'asset')
                );
                $creditor = ixbrlBalanceSheetNominal(
                    'PC' . $suffix,
                    'Fixture Creditor',
                    'liability',
                    ixbrlBalanceSheetSubtype('trade_creditor', 'Trade Creditor', 'liability')
                );
                $equity = ixbrlBalanceSheetNominal(
                    'PE' . $suffix,
                    'Fixture Equity',
                    'equity',
                    ixbrlBalanceSheetSubtype('capital_reserves', 'Capital and Reserves', 'equity')
                );
                $journalId = ixbrlBalanceSheetJournal(
                    $companyId,
                    $periodId,
                    'fixture-prepayment-split-' . $suffix,
                    '2026-12-31'
                );
                ixbrlBalanceSheetLine($journalId, $bank, 500.0, 0.0);
                ixbrlBalanceSheetLine($journalId, $prepayment, 75.0, 0.0);
                ixbrlBalanceSheetLine($journalId, $creditor, 0.0, 50.0);
                ixbrlBalanceSheetLine($journalId, $equity, 0.0, 525.0);

                $metrics = $service->fetchClosingMetrics($companyId, $periodId);
                $buckets = (array)($metrics['buckets'] ?? []);
                $sources = (array)($metrics['sources'] ?? []);
                $harness->assertSame(500.0, (float)($buckets['current_assets'] ?? 0));
                $harness->assertSame(75.0, (float)($buckets['prepayments_accrued_income'] ?? 0));
                $harness->assertSame(525.0, (float)($buckets['net_current_assets_liabilities'] ?? 0));
                $harness->assertSame(525.0, (float)($buckets['total_assets_less_current_liabilities'] ?? 0));
                $harness->assertSame(525.0, (float)($buckets['net_assets_liabilities'] ?? 0));
                foreach ([
                    'current_assets',
                    'prepayments_accrued_income',
                    'net_current_assets_liabilities',
                    'total_assets_less_current_liabilities',
                    'net_assets_liabilities',
                ] as $bucket) {
                    $harness->assertSame(
                        number_format((float)($buckets[$bucket] ?? 0), 2, '.', ''),
                        number_format(ixbrlBalanceSourceTotal((array)($sources[$bucket] ?? [])), 2, '.', '')
                    );
                }
                $harness->assertSame(3, count((array)($sources['net_current_assets_liabilities'] ?? [])));
                $harness->assertSame(4, count((array)($sources['total_assets_less_current_liabilities'] ?? [])));
                $harness->assertSame(2, count((array)($sources['net_assets_liabilities'] ?? [])));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlBalanceSheetMetricsService::class, 'does not synthesise equity to hide a balance sheet difference', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                    ['company_name' => 'iXBRL Imbalance Fixture Limited', 'company_number' => 'IB' . strtoupper(substr($suffix, 0, 8))]
                );
                $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'IB' . strtoupper(substr($suffix, 0, 8))]);
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
                    ['company_id' => $companyId, 'label' => 'Fixture Imbalance', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
                );
                $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'Fixture Imbalance']);
                $fixedSubtypeId = ixbrlBalanceSheetSubtype('fixed_asset', 'Fixed Asset', 'asset');
                $fixedNominalId = ixbrlBalanceSheetNominal('8I' . $suffix, 'Unbalanced Fixed Asset', 'asset', $fixedSubtypeId);
                $journalId = ixbrlBalanceSheetJournal($companyId, $periodId, 'fixture-imbalance-' . $suffix, '2026-12-31');
                ixbrlBalanceSheetLine($journalId, $fixedNominalId, 250.0, 0.0);

                $metrics = $service->fetchClosingMetrics($companyId, $periodId);
                $harness->assertSame(250.0, (float)(($metrics['buckets'] ?? [])['net_assets_liabilities'] ?? 0));
                $harness->assertSame(0.0, (float)(($metrics['buckets'] ?? [])['equity_capital_reserves'] ?? -1));
                $harness->assertSame(250.0, (float)($metrics['balance_equation_difference'] ?? 0));
                $harness->assertSame(false, (bool)($metrics['is_balance_sheet_balanced'] ?? true));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlBalanceSheetMetricsService::class, 'moves only the gross Director Loan Liability to creditors after one year for the selected period', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlDirectorLoanPresentationFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];

                $default = $service->fetchClosingMetrics($companyId, $periodId);
                $defaultBuckets = (array)($default['buckets'] ?? []);
                $harness->assertSame(1509.09, $defaultBuckets['current_assets'] ?? null);
                $harness->assertSame(1567.63, $defaultBuckets['creditors_within_one_year'] ?? null);
                $harness->assertSame(0.0, $defaultBuckets['creditors_after_more_than_one_year'] ?? null);
                $harness->assertSame(372.89, $defaultBuckets['net_assets_liabilities'] ?? null);

                $saved = (new \eel_accounts\Service\DirectorLoanReportingPresentationService())->save(
                    $companyId,
                    $periodId,
                    'after_more_than_one_year',
                    'test'
                );
                $harness->assertSame(true, (bool)($saved['success'] ?? false));

                $metrics = $service->fetchClosingMetrics($companyId, $periodId);
                $buckets = (array)($metrics['buckets'] ?? []);
                $harness->assertSame(431.43, $buckets['fixed_assets'] ?? null);
                $harness->assertSame(1509.09, $buckets['current_assets'] ?? null);
                $harness->assertSame(279.0, $buckets['creditors_within_one_year'] ?? null);
                $harness->assertSame(1288.63, $buckets['creditors_after_more_than_one_year'] ?? null);
                $harness->assertSame(1230.09, $buckets['net_current_assets_liabilities'] ?? null);
                $harness->assertSame(1661.52, $buckets['total_assets_less_current_liabilities'] ?? null);
                $harness->assertSame(372.89, $buckets['net_assets_liabilities'] ?? null);
                $harness->assertSame(true, (bool)($metrics['is_balance_sheet_balanced'] ?? false));

                $currentAssetSources = (array)(($metrics['sources'] ?? [])['current_assets'] ?? []);
                $directorLoanAssetSources = array_values(array_filter(
                    $currentAssetSources,
                    static fn(array $row): bool => str_contains((string)($row['label'] ?? ''), 'Fixture Director Loan Asset')
                ));
                $harness->assertCount(1, $directorLoanAssetSources);
                $harness->assertSame(253.0, (float)($directorLoanAssetSources[0]['amount'] ?? 0));
                $harness->assertSame(
                    'after_more_than_one_year',
                    (string)(($metrics['director_loan_reporting_presentation'] ?? [])['classification'] ?? '')
                );
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

function ixbrlBalanceSheetMetricsFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'IX' . strtoupper(substr($suffix, 0, 8));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'iXBRL Balance Fixture Limited', 'company_number' => $companyNumber]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $companyNumber]);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => 'Fixture FY 2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
    );
    $priorPeriodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'Fixture FY 2025']);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => 'Fixture FY 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
    );
    $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'Fixture FY 2026']);

    $fixedSubtypeId = ixbrlBalanceSheetSubtype('fixed_asset', 'Fixed Asset', 'asset');
    $bankSubtypeId = ixbrlBalanceSheetSubtype('bank', 'Bank', 'asset');
    $longTermSubtypeId = ixbrlBalanceSheetSubtype('long_term_liability', 'Long Term Liability', 'liability');
    $shortTermSubtypeId = ixbrlBalanceSheetSubtype('trade_creditor', 'Trade Creditor', 'liability');
    $equitySubtypeId = ixbrlBalanceSheetSubtype('capital_reserves', 'Capital and Reserves', 'equity');

    $fixed = ixbrlBalanceSheetNominal('9F' . $suffix, 'Fixture Fixed Asset', 'asset', $fixedSubtypeId);
    $bank = ixbrlBalanceSheetNominal('9B' . $suffix, 'Fixture Bank', 'asset', $bankSubtypeId);
    $short = ixbrlBalanceSheetNominal('9S' . $suffix, 'Fixture Short Creditor', 'liability', $shortTermSubtypeId);
    $long = ixbrlBalanceSheetNominal('9L' . $suffix, 'Fixture Long Creditor', 'liability', $longTermSubtypeId);
    $equity = ixbrlBalanceSheetNominal('9E' . $suffix, 'Fixture Equity', 'equity', $equitySubtypeId);

    $openingJournalId = ixbrlBalanceSheetJournal($companyId, $priorPeriodId, 'fixture-opening-' . $suffix, '2025-12-31');
    ixbrlBalanceSheetLine($openingJournalId, $fixed, 1000.0, 0.0);
    ixbrlBalanceSheetLine($openingJournalId, $bank, 300.0, 0.0);
    ixbrlBalanceSheetLine($openingJournalId, $long, 0.0, 400.0);
    ixbrlBalanceSheetLine($openingJournalId, $equity, 0.0, 900.0);

    $currentJournalId = ixbrlBalanceSheetJournal($companyId, $periodId, 'fixture-current-' . $suffix, '2026-06-30');
    ixbrlBalanceSheetLine($currentJournalId, $bank, 200.0, 0.0);
    ixbrlBalanceSheetLine($currentJournalId, $short, 0.0, 50.0);
    ixbrlBalanceSheetLine($currentJournalId, $equity, 0.0, 150.0);

    return ['company_id' => $companyId, 'accounting_period_id' => $periodId];
}

function ixbrlBalanceSheetSubtype(string $code, string $name, string $type): int
{
    $id = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_account_subtypes WHERE code = :code', ['code' => $code]);
    if ($id > 0) {
        return $id;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_account_subtypes (code, name, parent_account_type) VALUES (:code, :name, :type)',
        ['code' => $code, 'name' => $name, 'type' => $type]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_account_subtypes WHERE code = :code', ['code' => $code]);
}

function ixbrlBalanceSheetNominal(string $code, string $name, string $type, int $subtypeId): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id) VALUES (:code, :name, :type, :subtype_id)',
        ['code' => $code, 'name' => $name, 'type' => $type, 'subtype_id' => $subtypeId]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $code]);
}

function ixbrlBalanceSheetJournal(int $companyId, int $periodId, string $sourceRef, string $date): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        ['company_id' => $companyId, 'period_id' => $periodId, 'source_type' => 'manual', 'source_ref' => $sourceRef, 'journal_date' => $date, 'description' => 'Fixture journal']
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref', ['company_id' => $companyId, 'source_ref' => $sourceRef]);
}

function ixbrlBalanceSheetLine(int $journalId, int $nominalId, float $debit, float $credit): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :debit, :credit, :description)',
        ['journal_id' => $journalId, 'nominal_id' => $nominalId, 'debit' => $debit, 'credit' => $credit, 'description' => 'Fixture line']
    );
}

/** @param list<array<string, mixed>> $rows */
function ixbrlBalanceSourceTotal(array $rows): float
{
    return round(array_sum(array_map(
        static fn(array $row): float => (float)($row['amount'] ?? 0),
        $rows
    )), 2);
}

function ixbrlDirectorLoanPresentationFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . ':director-loan:' . microtime(true)), 0, 10);
    $companyNumber = 'DL' . strtoupper(substr($suffix, 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number)
         VALUES (:company_name, :company_number)',
        ['company_name' => 'iXBRL Director Loan Fixture Limited', 'company_number' => $companyNumber]
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
            'label' => 'AP79-shaped fixture',
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-30',
        ]
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );

    $fixed = ixbrlBalanceSheetNominal(
        'DF' . $suffix,
        'Fixture Fixed Assets',
        'asset',
        ixbrlBalanceSheetSubtype('fixed_asset', 'Fixed Asset', 'asset')
    );
    $bank = ixbrlBalanceSheetNominal(
        'DB' . $suffix,
        'Fixture Bank',
        'asset',
        ixbrlBalanceSheetSubtype('bank', 'Bank', 'asset')
    );
    $directorLoanAsset = ixbrlBalanceSheetNominal(
        'DA' . $suffix,
        'Fixture Director Loan Asset',
        'asset',
        ixbrlBalanceSheetSubtype('director_loan_asset', 'Director Loan Asset', 'asset')
    );
    $otherCreditor = ixbrlBalanceSheetNominal(
        'DC' . $suffix,
        'Fixture Other Creditor',
        'liability',
        ixbrlBalanceSheetSubtype('trade_creditor', 'Trade Creditor', 'liability')
    );
    $directorLoanLiability = ixbrlBalanceSheetNominal(
        'DL' . $suffix,
        'Fixture Director Loan Liability',
        'liability',
        ixbrlBalanceSheetSubtype('director_loan_liability', 'Director Loan Liability', 'liability')
    );
    $equity = ixbrlBalanceSheetNominal(
        'DE' . $suffix,
        'Fixture Equity',
        'equity',
        ixbrlBalanceSheetSubtype('capital_reserves', 'Capital and Reserves', 'equity')
    );
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('director_loan_asset_nominal_id', $directorLoanAsset, 'int');
    $settings->set('director_loan_liability_nominal_id', $directorLoanLiability, 'int');
    $settings->flush();

    $journalId = ixbrlBalanceSheetJournal(
        $companyId,
        $periodId,
        'fixture-director-loan-' . $suffix,
        '2023-09-30'
    );
    ixbrlBalanceSheetLine($journalId, $fixed, 431.43, 0.0);
    ixbrlBalanceSheetLine($journalId, $bank, 1256.09, 0.0);
    ixbrlBalanceSheetLine($journalId, $directorLoanAsset, 253.0, 0.0);
    ixbrlBalanceSheetLine($journalId, $otherCreditor, 0.0, 279.0);
    ixbrlBalanceSheetLine($journalId, $directorLoanLiability, 0.0, 1288.63);
    ixbrlBalanceSheetLine($journalId, $equity, 0.0, 372.89);

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $periodId,
        'director_loan_asset_nominal_id' => $directorLoanAsset,
        'director_loan_liability_nominal_id' => $directorLoanLiability,
    ];
}
