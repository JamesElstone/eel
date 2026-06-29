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
    IxbrlBalanceSheetMetricsService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlBalanceSheetMetricsService $service): void {
        $harness->check(IxbrlBalanceSheetMetricsService::class, 'uses closing balances across prior and current journals', static function () use ($harness, $service): void {
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
