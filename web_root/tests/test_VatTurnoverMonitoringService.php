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

$harness->run(\eel_accounts\Service\VatTurnoverMonitoringService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\VatTurnoverMonitoringService $service
): void {
    $harness->check(\eel_accounts\Service\VatTurnoverMonitoringService::class, 'clips current period, rolls across periods, and preserves debit adjustments', static function () use ($harness, $service): void {
        vatTurnoverMonitoringFixture(static function (array $fixture) use ($harness, $service): void {
            $monitoring = $service->fetchMonitoring($fixture['company_id'], $fixture['current_period_id'], '2025-02-15');

            $harness->assertSame(true, (bool)$monitoring['available']);
            $harness->assertSame(false, (bool)$monitoring['not_started']);
            $harness->assertSame('2025-02-15', (string)$monitoring['effective_date']);
            $harness->assertSame(19000.00, (float)$monitoring['ap_to_date_gross_income']);
            $harness->assertSame(29000.00, (float)$monitoring['trailing_12_month_gross_income']);
            $harness->assertSame(90000.00, (float)$monitoring['threshold']['registration_threshold']);
            $harness->assertSame(61000.00, (float)$monitoring['threshold_headroom']);
            $harness->assertSame(true, (bool)$monitoring['coverage']['complete']);

            $months = array_column((array)$monitoring['months'], null, 'month');
            $harness->assertSame('2024-04-15', (string)$months['2024-04']['start_date']);
            $harness->assertSame(-2000.00, (float)$months['2024-05']['gross_income']);
            $harness->assertSame(-2000.00, (float)array_column((array)$monitoring['bar_points'], null, 'label')['May 24']['value']);
            $harness->assertSame(62000.00, (float)$months['2024-05']['threshold_headroom']);
            $harness->assertSame('Complete', (string)$months['2024-05']['coverage_label']);
        });
    });

    $harness->check(\eel_accounts\Service\VatTurnoverMonitoringService::class, 'clips past periods to period end and identifies future periods', static function () use ($harness, $service): void {
        vatTurnoverMonitoringFixture(static function (array $fixture) use ($harness, $service): void {
            $past = $service->fetchMonitoring($fixture['company_id'], $fixture['previous_period_id'], '2025-02-15');
            $future = $service->fetchMonitoring($fixture['company_id'], $fixture['future_period_id'], '2025-02-15');

            $harness->assertSame('2024-04-14', (string)$past['effective_date']);
            $harness->assertSame(10000.00, (float)$past['ap_to_date_gross_income']);
            $harness->assertSame(true, (bool)$future['available']);
            $harness->assertSame(true, (bool)$future['not_started']);
            $harness->assertSame(null, $future['effective_date']);
        });
    });

    $harness->check(\eel_accounts\Service\VatTurnoverMonitoringService::class, 'uses the sourced taxable-supplies threshold at every historic graph date', static function () use ($harness, $service): void {
        vatTurnoverMonitoringFixture(static function (array $fixture) use ($harness, $service): void {
            $beforeChange = $service->fetchMonitoring($fixture['company_id'], $fixture['previous_period_id'], '2024-03-31');
            $afterChange = $service->fetchMonitoring($fixture['company_id'], $fixture['previous_period_id'], '2024-04-01');

            $harness->assertSame(85000.00, (float)$beforeChange['threshold']['registration_threshold']);
            $harness->assertSame(90000.00, (float)$afterChange['threshold']['registration_threshold']);

            $beforePoints = array_column((array)$beforeChange['threshold_points'], 'value', 'label');
            $afterPoints = array_column((array)$afterChange['threshold_points'], 'value', 'label');
            $harness->assertSame(85000.00, (float)$beforePoints['Mar 24']);
            $harness->assertSame(90000.00, (float)$afterPoints['Apr 24']);
        });
    });
});

function vatTurnoverMonitoringFixture(callable $callback): void
{
    GoldenAccountsFixture::build();
    InterfaceDB::beginTransaction();

    try {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('95' . $marker);
        $previousPeriodId = (int)('96' . $marker);
        $currentPeriodId = (int)('97' . $marker);
        $futurePeriodId = (int)('98' . $marker);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, incorporation_date, company_status, is_active)
             VALUES (:id, :company_name, :company_number, :incorporation_date, :company_status, 1)',
            [
                'id' => $companyId,
                'company_name' => 'VAT Turnover Fixture Limited',
                'company_number' => 'VTM' . $marker,
                'incorporation_date' => '2023-04-15',
                'company_status' => 'active',
            ]
        );
        foreach ([
            [$previousPeriodId, 'Previous ' . $marker, '2023-04-15', '2024-04-14'],
            [$currentPeriodId, 'Current ' . $marker, '2024-04-15', '2025-04-14'],
            [$futurePeriodId, 'Future ' . $marker, '2026-04-15', '2027-04-14'],
        ] as [$periodId, $label, $start, $end]) {
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                ['id' => $periodId, 'company_id' => $companyId, 'label' => $label, 'period_start' => $start, 'period_end' => $end]
            );
        }

        vatTurnoverInsertThresholdRules($marker);

        vatTurnoverInsertIncomeJournal($marker, $companyId, $previousPeriodId, '2024-03-01', 0.00, 10000.00, 'prior-income');
        vatTurnoverInsertIncomeJournal($marker, $companyId, $currentPeriodId, '2024-04-20', 0.00, 20000.00, 'april-income');
        vatTurnoverInsertIncomeJournal($marker, $companyId, $currentPeriodId, '2024-05-10', 0.00, 5000.00, 'may-income');
        vatTurnoverInsertIncomeJournal($marker, $companyId, $currentPeriodId, '2024-05-20', 7000.00, 0.00, 'may-debit-adjustment');
        vatTurnoverInsertIncomeJournal($marker, $companyId, $currentPeriodId, '2025-02-15', 0.00, 1000.00, 'february-income');
        vatTurnoverInsertIncomeJournal($marker, $companyId, $currentPeriodId, '2025-01-10', 0.00, 99000.00, 'unposted-income', false);
        $retainedJournalId = vatTurnoverInsertIncomeJournal($marker, $companyId, $currentPeriodId, '2025-01-31', 0.00, 50000.00, 'retained-close');
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_entry_metadata (journal_id, company_id, accounting_period_id, journal_tag, journal_key, entry_mode)
             VALUES (:journal_id, :company_id, :accounting_period_id, :journal_tag, :journal_key, :entry_mode)',
            [
                'journal_id' => $retainedJournalId,
                'company_id' => $companyId,
                'accounting_period_id' => $currentPeriodId,
                'journal_tag' => 'year_end_retained_earnings_close',
                'journal_key' => 'fixture',
                'entry_mode' => 'system_generated',
            ]
        );

        $callback([
            'company_id' => $companyId,
            'previous_period_id' => $previousPeriodId,
            'current_period_id' => $currentPeriodId,
            'future_period_id' => $futurePeriodId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function vatTurnoverInsertThresholdRules(string $marker): void
{
    $datasetHash = hash('sha256', 'vat-monitoring-thresholds:' . $marker);
    foreach ([
        ['2017-04-01', '2024-03-31', 85000.00, '1 April 2017 to 31 March 2024'],
        ['2024-04-01', null, 90000.00, 'Current threshold'],
    ] as $index => [$effectiveFrom, $effectiveTo, $amount, $periodText]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO vat_threshold_rules (
                threshold_type,
                jurisdiction,
                effective_from,
                effective_to,
                original_period_text,
                registration_threshold,
                deregistration_threshold,
                source_url,
                source_content_id,
                source_updated_at,
                source_checked_at,
                dataset_hash,
                row_hash,
                is_active,
                audit_notes
             ) VALUES (
                :threshold_type,
                :jurisdiction,
                :effective_from,
                :effective_to,
                :original_period_text,
                :registration_threshold,
                NULL,
                :source_url,
                :source_content_id,
                :source_updated_at,
                :source_checked_at,
                :dataset_hash,
                :row_hash,
                1,
                :audit_notes
             )',
            [
                'threshold_type' => \eel_accounts\Service\VatThresholdRuleService::TYPE_TAXABLE_SUPPLIES,
                'jurisdiction' => 'united_kingdom',
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'original_period_text' => $periodText,
                'registration_threshold' => number_format((float)$amount, 2, '.', ''),
                'source_url' => \eel_accounts\Service\VatThresholdRuleService::NOTICE_URL,
                'source_content_id' => '11111111-1111-4111-8111-111111111111',
                'source_updated_at' => '2024-04-18 00:00:00',
                'source_checked_at' => '2026-07-14 00:00:00',
                'dataset_hash' => $datasetHash,
                'row_hash' => hash('sha256', $datasetHash . ':' . $index),
                'audit_notes' => 'Deterministic VAT turnover monitoring fixture.',
            ]
        );
    }
}

function vatTurnoverInsertIncomeJournal(
    string $marker,
    int $companyId,
    int $accountingPeriodId,
    string $date,
    float $debit,
    float $credit,
    string $key,
    bool $posted = true
): int {
    $sourceRef = 'vat-monitoring:' . $marker . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, :is_posted)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => 'VAT monitoring fixture ' . $key,
            'is_posted' => $posted ? 1 : 0,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => $companyId, 'source_ref' => $sourceRef]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => 91002,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'description' => 'VAT monitoring income',
        ]
    );

    return $journalId;
}
