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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountingOracle.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenPrepaymentFourPeriodLifecycle', 'posts the complete four-period schedule with exact previews, journals and balances', static function () use ($harness): void {
    $variant = GoldenLedgerSpecification::fourPeriodPrepaymentVariant();
    $oracle = GoldenAccountingOracle::prepaymentSchedule($variant);
    $evidence = GoldenAccountsFixture::fourPeriodPrepaymentEvidence();
    $harness->assertSame([9111, 9112, 9113, 9114], array_map('intval', array_keys($evidence)));

    $review = InterfaceDB::fetchOne('SELECT * FROM prepayment_reviews WHERE id = 9291');
    $harness->assertSame('prepaid', (string)$review['status']);
    $scheduleId = (int)$review['current_schedule_id'];
    $schedule = (new \eel_accounts\Service\PrepaymentScheduleService())->fetchSchedule($scheduleId);
    $harness->assertSame(2, (int)$schedule['calculation_version']);
    $harness->assertSame('complete', (string)$schedule['status']);
    $harness->assertCount(4, (array)$schedule['allocations']);
    $harness->assertSame(109600, array_sum(array_map(
        static fn(array $row): int => (int)$row['expense_pence'],
        (array)$schedule['allocations']
    )));

    $statuses = [];
    foreach ((array)$variant['expected'] as $periodId => $expected) {
        $periodId = (int)$periodId;
        $allocation = (array)$evidence[$periodId]['allocation'];
        $oraclePeriod = (array)$oracle['allocations'][$periodId];
        $harness->assertSame((int)$expected['overlap_days'], (int)$allocation['overlap_days']);
        $harness->assertSame((int)$expected['expense_pence'], (int)$allocation['expense_pence']);
        $harness->assertSame((int)$expected['closing_deferred_pence'], (int)$allocation['closing_deferred_pence']);
        $harness->assertSame((int)$oraclePeriod['prepayment_asset_pence'], (int)$allocation['closing_deferred_pence']);
        $harness->assertSame((string)$expected['posting_type'], (string)$allocation['posting_role']);
        $harness->assertSame((int)$expected['posting_pence'], (int)$allocation['posting_target_pence']);
        if ((int)$evidence[$periodId]['posted_count'] !== 1) {
            throw new RuntimeException('AP ' . $periodId . ' should create exactly one initial prepayment posting.');
        }
        $harness->assertSame(0, (int)$evidence[$periodId]['retry_posted_count']);
        $harness->assertSame(
            number_format((float)$evidence[$periodId]['preview_profit_before_tax'], 2, '.', ''),
            number_format((float)$evidence[$periodId]['posted_profit_before_tax'], 2, '.', '')
        );
        $statuses[] = (string)$evidence[$periodId]['schedule_status'];
    }
    $harness->assertSame(['active', 'active', 'active', 'complete'], $statuses);

    $postings = InterfaceDB::fetchAll(
        'SELECT posting.accounting_period_id, posting.posting_role, posting.posting_type,
                posting.effect_pence, posting.target_pence, j.journal_date,
                j.source_type, j.is_posted, metadata.entry_mode, metadata.journal_tag,
                metadata.journal_key
         FROM prepayment_schedules ps
         INNER JOIN prepayment_schedule_postings posting ON posting.schedule_id = ps.id
         INNER JOIN journals j ON j.id = posting.journal_id
         INNER JOIN journal_entry_metadata metadata ON metadata.journal_id = j.id
         WHERE ps.review_id = 9291
         ORDER BY posting.accounting_period_id, posting.id'
    );
    $harness->assertCount(4, $postings);
    foreach ($postings as $index => $posting) {
        $periodId = [9111, 9112, 9113, 9114][$index];
        $expected = (array)$variant['expected'][$periodId];
        $expectedRole = (string)$expected['posting_type'];
        $expectedDate = $expectedRole === 'deferral'
            ? (string)$variant['periods'][$index]['period_end']
            : (string)$schedule['allocations'][$index]['overlap_start'];
        $harness->assertSame($periodId, (int)$posting['accounting_period_id']);
        $harness->assertSame($expectedRole, (string)$posting['posting_role']);
        $harness->assertSame($expectedRole, (string)$posting['posting_type']);
        $harness->assertSame((int)$expected['posting_pence'], (int)$posting['effect_pence']);
        $harness->assertSame((int)$expected['posting_pence'], (int)$posting['target_pence']);
        $harness->assertSame($expectedDate, (string)$posting['journal_date']);
        $harness->assertSame('manual', (string)$posting['source_type']);
        if ((int)$posting['is_posted'] !== 1) {
            throw new RuntimeException('AP ' . $periodId . ' prepayment audit row should retain a posted journal.');
        }
        $harness->assertSame('system_generated', (string)$posting['entry_mode']);
        $harness->assertSame('prepayment_' . $expectedRole, (string)$posting['journal_tag']);
        $harness->assertSame('review:9291:period:' . $periodId . ':role:' . $expectedRole, (string)$posting['journal_key']);
    }

    $source = InterfaceDB::fetchOne(
        'SELECT t.amount, j.id AS journal_id,
                SUM(CASE WHEN jl.nominal_account_id = 91019 THEN jl.debit ELSE 0 END) AS expense_debit,
                SUM(CASE WHEN jl.nominal_account_id = 91001 THEN jl.credit ELSE 0 END) AS bank_credit
         FROM transactions t
         INNER JOIN journals j ON j.source_type = :source_type AND j.source_ref = :source_ref
         INNER JOIN journal_lines jl ON jl.journal_id = j.id
         WHERE t.id = 9290
         GROUP BY t.amount, j.id',
        ['source_type' => 'bank_csv', 'source_ref' => 'transaction:9290']
    );
    $harness->assertSame(-1096.00, (float)$source['amount']);
    $harness->assertSame(9292, (int)$source['journal_id']);
    $harness->assertSame(1096.00, (float)$source['expense_debit']);
    $harness->assertSame(1096.00, (float)$source['bank_credit']);

    foreach ([9111 => 821.00, 9112 => 455.00, 9113 => 363.00, 9114 => 0.00] as $periodId => $expectedBalance) {
        $balance = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN accounting_periods ap ON ap.id = j.accounting_period_id
             WHERE j.company_id = :company_id AND j.is_posted = 1
               AND jl.nominal_account_id = 91018
               AND ap.period_end <= (SELECT period_end FROM accounting_periods WHERE id = :period_id)',
            ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'period_id' => $periodId]
        );
        $harness->assertSame($expectedBalance, $balance);
    }

    foreach ([9111 => 275.00, 9112 => 366.00, 9113 => 457.00, 9114 => 363.00] as $periodId => $expectedExpense) {
        $expense = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
             FROM journals j INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id AND j.accounting_period_id = :period_id
               AND j.is_posted = 1 AND jl.nominal_account_id = 91019',
            ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'period_id' => $periodId]
        );
        $harness->assertSame($expectedExpense, $expense);
    }

    $balanceSheet = (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())
        ->fetchClosingMetrics(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
    $prepaymentAccruedIncome = array_values(array_filter(
        (array)($balanceSheet['sources']['prepayments_accrued_income'] ?? []),
        static fn(array $row): bool => str_contains((string)($row['label'] ?? ''), 'Golden Test Prepayments')
    ));
    $prepaymentFixedAsset = array_values(array_filter(
        (array)($balanceSheet['sources']['fixed_assets'] ?? []),
        static fn(array $row): bool => str_contains((string)($row['label'] ?? ''), 'Golden Test Prepayments')
    ));
    $harness->assertCount(1, $prepaymentAccruedIncome);
    $harness->assertSame(821.00, (float)$prepaymentAccruedIncome[0]['amount']);
    $harness->assertSame([], $prepaymentFixedAsset);
});
