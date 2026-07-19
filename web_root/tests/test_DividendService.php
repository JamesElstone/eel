<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(\eel_accounts\Service\DividendService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\DividendService $service): void {
    $harness->check(\eel_accounts\Service\DividendService::class, 'prepares dividend nominal accounts with numeric codes', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('nominal_account_subtypes')) {
            $harness->skip('Nominal tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $result = $service->ensureDividendNominals(1);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame([], (array)($result['errors'] ?? []));

            $accounts = (array)($result['accounts'] ?? []);
            $harness->assertSame('3000', (string)($accounts['retained_earnings']['code'] ?? ''));
            $harness->assertSame('3100', (string)($accounts['dividends_paid']['code'] ?? ''));
            $harness->assertSame('2150', (string)($accounts['dividends_payable']['code'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'defaults capacity date to today or period end whichever is earliest', function () use ($harness, $service): void {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $futurePeriodEnd = (new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d');
        $pastPeriodEnd = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');

        $harness->assertSame($today, dividend_service_effective_as_at_date($service, null, '2000-01-01', $futurePeriodEnd));
        $harness->assertSame($pastPeriodEnd, dividend_service_effective_as_at_date($service, null, '2000-01-01', $pastPeriodEnd));
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'builds warnings from precomputed capacity without recalculating capacity', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('nominal_accounts')) {
            $harness->skip('Nominal tables are not available on the default InterfaceDB connection.');
        }

        $warnings = $service->getDividendWarningsForCapacity(1, 1, [
            'reserves_reliable' => false,
            'reserve_basis_detail' => 'Reserve basis fixture detail.',
            'available_distributable_reserves' => -1.00,
            'ledger_current_year_profit_loss' => -2.00,
            'unposted_corporation_tax_adjustment' => 3.00,
        ]);
        $titles = array_map(static fn(array $warning): string => (string)($warning['title'] ?? ''), $warnings);

        $harness->assertTrue(in_array('Reserve basis blocked', $titles, true));
        $harness->assertTrue(in_array('Insufficient reserves', $titles, true));
        $harness->assertTrue(in_array('Negative current-year profit', $titles, true));
        $harness->assertTrue(in_array('Corporation Tax estimate deducted', $titles, true));
        $harness->assertTrue(in_array('Dividend review scope', $titles, true));
    });

    $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'clears runtime caches before recalculating changed ledger profit', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('companies') || !InterfaceDB::tableExists('accounting_periods') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
            $harness->skip('Company, accounting period, and journal tables are not available on the default InterfaceDB connection.');
        }
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00);
            $taxService = new \eel_accounts\Service\CorporationTaxComputationService();
            $method = new ReflectionMethod($taxService, 'profitAndLossSummary');
            $method->setAccessible(true);

            $first = $method->invoke($taxService, $fixture['company_id'], $fixture['accounting_period_id'], '2022-01-01', '2022-12-31');
            dividend_service_add_profit_journal($fixture['company_id'], $fixture['accounting_period_id'], (string)$fixture['marker'] . 'CACHE', 25.00, '2022-11-15');
            $cached = $method->invoke($taxService, $fixture['company_id'], $fixture['accounting_period_id'], '2022-01-01', '2022-12-31');
            $taxService->clearRuntimeCaches();
            $fresh = $method->invoke($taxService, $fixture['company_id'], $fixture['accounting_period_id'], '2022-01-01', '2022-12-31');

            $harness->assertSame(round((float)($first['profit_before_tax'] ?? 0), 2), round((float)($cached['profit_before_tax'] ?? 0), 2));
            $harness->assertSame(round((float)($first['profit_before_tax'] ?? 0) + 25.00, 2), round((float)($fresh['profit_before_tax'] ?? 0), 2));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'creates dividend declaration from payable transaction once', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('transactions') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
            $harness->skip('Transaction and journal tables are not available on the default InterfaceDB connection.');
        }
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_transaction_fixture($service, -129.00, '2150');

            $result = $service->declareDividendFromTransaction($fixture['transaction_id'], $fixture['company_id'], $fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(false, (bool)($result['already_exists'] ?? false));
            $harness->assertSame('dividend:transaction:' . $fixture['transaction_id'], (string)($result['source_ref'] ?? ''));
            if (InterfaceDB::tableExists('dividend_vouchers')) {
                $harness->assertTrue((int)($result['voucher_id'] ?? 0) > 0);
            }

            $journalId = (int)($result['journal_id'] ?? 0);
            $harness->assertTrue($journalId > 0);
            $harness->assertSame('129.00', number_format((float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(jl.debit), 0)
                 FROM journal_lines jl
                 WHERE jl.journal_id = :journal_id
                   AND jl.nominal_account_id = :nominal_account_id',
                [
                    'journal_id' => $journalId,
                    'nominal_account_id' => $fixture['dividends_paid_id'],
                ]
            ), 2, '.', ''));
            $harness->assertSame('129.00', number_format((float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(jl.credit), 0)
                 FROM journal_lines jl
                 WHERE jl.journal_id = :journal_id
                   AND jl.nominal_account_id = :nominal_account_id',
                [
                    'journal_id' => $journalId,
                    'nominal_account_id' => $fixture['dividends_payable_id'],
                ]
            ), 2, '.', ''));

            $secondResult = $service->declareDividendFromTransaction($fixture['transaction_id'], $fixture['company_id'], $fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($secondResult['success'] ?? false));
            $harness->assertSame(true, (bool)($secondResult['already_exists'] ?? false));
            $harness->assertSame($journalId, (int)($secondResult['journal_id'] ?? 0));
            $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journals
                 WHERE company_id = :company_id
                   AND source_type = :source_type
                   AND source_ref = :source_ref',
                [
                    'company_id' => $fixture['company_id'],
                    'source_type' => 'manual',
                    'source_ref' => 'dividend:transaction:' . $fixture['transaction_id'],
                ]
            ));

            $history = $service->listDividends($fixture['company_id'], $fixture['accounting_period_id']);
            $harness->assertSame(true, in_array($journalId, array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $history), true));
            if (InterfaceDB::tableExists('dividend_vouchers')) {
                $vouchers = $service->listDividendVouchers($fixture['company_id'], $fixture['accounting_period_id']);
                $harness->assertSame(1, count(array_filter($vouchers, static fn(array $row): bool => (int)($row['journal_id'] ?? 0) === $journalId)));
            }
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'voids recategorised transaction dividends with notes and a reversing journal', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('transactions') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines') || !InterfaceDB::tableExists('dividend_vouchers')) {
            $harness->skip('Dividend voucher, transaction, and journal tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_transaction_fixture($service, -129.00, '2150');
            $result = $service->declareDividendFromTransaction($fixture['transaction_id'], $fixture['company_id'], $fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $journalId = (int)($result['journal_id'] ?? 0);

            $linkedHistory = $service->listDividends($fixture['company_id'], $fixture['accounting_period_id']);
            $linkedRow = dividend_service_history_row($linkedHistory, $journalId);
            $harness->assertSame('linked', (string)($linkedRow['payment_link_status'] ?? ''));
            $harness->assertSame(false, (bool)($linkedRow['can_void'] ?? true));

            $expenseNominalId = dividend_service_fixture_nominal('9' . substr((string)$fixture['transaction_id'], -8), 'Recategorised Dividend Fixture', 'expense');
            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET nominal_account_id = :nominal_account_id,
                     category_status = :category_status
                 WHERE id = :transaction_id',
                [
                    'nominal_account_id' => $expenseNominalId,
                    'category_status' => 'manual',
                    'transaction_id' => $fixture['transaction_id'],
                ]
            );

            $withoutNotesHistory = $service->listDividends($fixture['company_id'], $fixture['accounting_period_id']);
            $withoutNotesRow = dividend_service_history_row($withoutNotesHistory, $journalId);
            $harness->assertSame('recategorised', (string)($withoutNotesRow['payment_link_status'] ?? ''));
            $harness->assertSame(false, (bool)($withoutNotesRow['can_void'] ?? true));

            $blocked = $service->voidDividend($fixture['company_id'], $fixture['accounting_period_id'], $journalId, 'test');
            $harness->assertSame(false, (bool)($blocked['success'] ?? true));
            $harness->assertSame(true, str_contains(implode(' ', (array)($blocked['errors'] ?? [])), 'transaction note'));

            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET notes = :notes
                 WHERE id = :transaction_id',
                [
                    'notes' => 'Reclassified after review: the payment was a director loan repayment.',
                    'transaction_id' => $fixture['transaction_id'],
                ]
            );

            $voidableHistory = $service->listDividends($fixture['company_id'], $fixture['accounting_period_id']);
            $voidableRow = dividend_service_history_row($voidableHistory, $journalId);
            $harness->assertSame(true, (bool)($voidableRow['can_void'] ?? false));

            $voided = $service->voidDividend($fixture['company_id'], $fixture['accounting_period_id'], $journalId, 'test');
            $harness->assertSame(true, (bool)($voided['success'] ?? false));
            $reversalJournalId = (int)($voided['reversal_journal_id'] ?? 0);
            $harness->assertTrue($reversalJournalId > 0);

            $voucher = InterfaceDB::fetchOne(
                'SELECT voucher_text, minutes_text, voided_at, voided_by, void_reason, reversal_journal_id
                 FROM dividend_vouchers
                 WHERE journal_id = :journal_id
                 LIMIT 1',
                ['journal_id' => $journalId]
            );
            $harness->assertSame('test', (string)($voucher['voided_by'] ?? ''));
            $harness->assertSame('Reclassified after review: the payment was a director loan repayment.', (string)($voucher['void_reason'] ?? ''));
            $harness->assertSame($reversalJournalId, (int)($voucher['reversal_journal_id'] ?? 0));
            $harness->assertSame(true, trim((string)($voucher['voided_at'] ?? '')) !== '');
            $harness->assertSame(false, str_contains((string)($voucher['voucher_text'] ?? ''), 'Status: VOIDED'));
            $harness->assertSame(false, str_contains((string)($voucher['minutes_text'] ?? ''), 'Subsequent record: This dividend voucher was voided'));

            $netDividendPaid = (float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                 FROM journal_lines jl
                 WHERE jl.nominal_account_id = :nominal_account_id
                   AND jl.journal_id IN (:journal_id, :reversal_journal_id)',
                [
                    'nominal_account_id' => $fixture['dividends_paid_id'],
                    'journal_id' => $journalId,
                    'reversal_journal_id' => $reversalJournalId,
                ]
            );
            $harness->assertSame('0.00', number_format($netDividendPaid, 2, '.', ''));

            $voidedHistory = $service->listDividends($fixture['company_id'], $fixture['accounting_period_id']);
            $voidedRow = dividend_service_history_row($voidedHistory, $journalId);
            $harness->assertSame('voided', (string)($voidedRow['status'] ?? ''));
            $harness->assertSame('voided', (string)($voidedRow['payment_link_status'] ?? ''));
            $harness->assertSame(false, (bool)($voidedRow['can_void'] ?? true));
            $voidedVouchers = $service->listDividendVouchers($fixture['company_id'], $fixture['accounting_period_id']);
            $voidedVoucherRows = array_values(array_filter($voidedVouchers, static fn(array $row): bool => (int)($row['journal_id'] ?? 0) === $journalId));
            $harness->assertSame(1, count($voidedVoucherRows));
            $harness->assertSame(false, str_contains((string)($voidedVoucherRows[0]['voucher_text'] ?? ''), 'Status: VOIDED'));
            $harness->assertSame(false, str_contains((string)($voidedVoucherRows[0]['minutes_text'] ?? ''), 'Subsequent record: This dividend voucher was voided'));
            $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journals
                 WHERE id = :journal_id',
                ['journal_id' => $journalId]
            ));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'rejects non-payable dividend transaction shortcuts', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('transactions') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
            $harness->skip('Transaction and journal tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $positiveFixture = dividend_service_transaction_fixture($service, 129.00, '2150');
            $positiveResult = $service->declareDividendFromTransaction($positiveFixture['transaction_id'], $positiveFixture['company_id'], $positiveFixture['accounting_period_id']);
            $harness->assertSame(false, (bool)($positiveResult['success'] ?? true));

            $wrongNominalFixture = dividend_service_transaction_fixture($service, -129.00, '5000');
            $wrongNominalResult = $service->declareDividendFromTransaction($wrongNominalFixture['transaction_id'], $wrongNominalFixture['company_id'], $wrongNominalFixture['accounting_period_id']);
            $harness->assertSame(false, (bool)($wrongNominalResult['success'] ?? true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'rejects manual declarations above available distributable reserves', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('companies') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
            $harness->skip('Company and journal tables are not available on the default InterfaceDB connection.');
        }
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00);

            $result = $service->declareDividend([
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['accounting_period_id'],
                'declaration_date' => '2022-11-30',
                'amount' => '100.01',
                'description' => 'Over-capacity dividend',
                'settlement_target' => 'unpaid_dividend_liability',
            ]);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertTrue(in_array('Dividend amount exceeds available distributable reserves.', (array)($result['errors'] ?? []), true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'saves unreconciled manual declarations as draft and counts them in capacity', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('companies') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
            $harness->skip('Company and journal tables are not available on the default InterfaceDB connection.');
        }
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00);
            $result = $service->declareDividend([
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['accounting_period_id'],
                'declaration_date' => '2022-11-30',
                'amount' => '40.00',
                'description' => 'Draft dividend',
                'settlement_target' => 'unpaid_dividend_liability',
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(false, (bool)($result['posted'] ?? true));
            if (InterfaceDB::tableExists('dividend_vouchers')) {
                $harness->assertTrue((int)($result['voucher_id'] ?? 0) > 0);
            }
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT is_posted FROM journals WHERE id = :journal_id',
                ['journal_id' => (int)($result['journal_id'] ?? 0)]
            ));

            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['accounting_period_id'], '2022-11-30');
            $harness->assertSame('19.00', number_format((float)($capacity['estimated_corporation_tax'] ?? 0), 2, '.', ''));
            $harness->assertSame('19.00', number_format((float)($capacity['unposted_corporation_tax_adjustment'] ?? 0), 2, '.', ''));
            $harness->assertSame('41.00', number_format((float)($capacity['available_distributable_reserves'] ?? 0), 2, '.', ''));
            $harness->assertTrue(count((array)($capacity['tax_periods'] ?? [])) > 0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'rejects future declaration dates', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('companies') || !InterfaceDB::tableExists('accounting_periods')) {
            $harness->skip('Company and period tables are not available on the default InterfaceDB connection.');
        }
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $futureEnd = (new DateTimeImmutable('today'))->modify('+1 month')->format('Y-m-d');
            $fixture = dividend_service_manual_fixture($service, 100.00, '2022-01-01', $futureEnd, '2022-11-01');
            $result = $service->declareDividend([
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['accounting_period_id'],
                'declaration_date' => (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d'),
                'amount' => '10.00',
                'description' => 'Future-period dividend',
                'settlement_target' => 'unpaid_dividend_liability',
            ]);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertTrue(in_array('Declaration date cannot be in the future.', (array)($result['errors'] ?? []), true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'prior period unlocked blocks dividend capacity', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);
        dividend_service_require_year_end_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_two_period_fixture($service);
            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['current_period_id'], '2022-11-30');

            $harness->assertSame(false, (bool)($capacity['reserves_reliable'] ?? true));
            $harness->assertSame('prior_period_not_locked', (string)($capacity['retained_earnings_status'] ?? ''));
            $harness->assertTrue(str_contains((string)($capacity['reserve_basis_detail'] ?? ''), 'prior accounting period is locked'));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'prior close missing blocks dividend capacity', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);
        dividend_service_require_year_end_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_two_period_fixture($service);
            (new \eel_accounts\Service\YearEndLockService())->lockPeriod($fixture['company_id'], $fixture['prior_period_id'], 'test');
            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['current_period_id'], '2022-11-30');

            $harness->assertSame(false, (bool)($capacity['reserves_reliable'] ?? true));
            $harness->assertSame('prior_close_missing', (string)($capacity['retained_earnings_status'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'locked prior close and current reserve review allow dividends', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);
        dividend_service_require_year_end_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_two_period_fixture($service);
            dividend_service_lock_prior_close($fixture);
            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['current_period_id'], '2022-11-30');

            $harness->assertSame(true, (bool)($capacity['reserves_reliable'] ?? false));
            $harness->assertSame('locked_prior_distributable_snapshot', (string)($capacity['retained_earnings_status'] ?? ''));
            $harness->assertSame('current', (string)($capacity['reserve_review_status'] ?? ''));
            $harness->assertTrue((float)($capacity['available_distributable_reserves'] ?? 0) > 0.0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'stale prior close prevents declaration', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);
        dividend_service_require_year_end_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_two_period_fixture($service);
            dividend_service_lock_prior_close($fixture);
            dividend_service_add_profit_journal($fixture['company_id'], $fixture['prior_period_id'], $fixture['marker'] . 'STALE', 10.00, '2021-12-15');

            $result = $service->declareDividend([
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['current_period_id'],
                'declaration_date' => '2022-11-30',
                'amount' => '10.00',
                'description' => 'Blocked stale close dividend',
                'settlement_target' => 'unpaid_dividend_liability',
            ]);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)($result['errors'] ?? [])), 'prior period distributable reserve review is stale'));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'posted corporation tax provision reduces unposted CT deduction', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00);
            dividend_service_add_corporation_tax_provision($fixture['company_id'], $fixture['accounting_period_id'], $fixture['marker'], 10.00, '2022-11-15');
            dividend_service_save_reserve_review($fixture['company_id'], $fixture['accounting_period_id']);

            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['accounting_period_id'], '2022-11-30');

            $harness->assertSame('10.00', number_format((float)($capacity['posted_corporation_tax_charge'] ?? 0), 2, '.', ''));
            $harness->assertTrue((float)($capacity['unposted_corporation_tax_adjustment'] ?? 0) > 0.0);
            $harness->assertTrue((float)($capacity['unposted_corporation_tax_adjustment'] ?? 0) < (float)($capacity['estimated_corporation_tax'] ?? 0));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'missing corporation tax estimate blocks profitable dividend capacity', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00, '1900-01-01', '1900-12-31', '1900-11-01');
            dividend_service_save_reserve_review($fixture['company_id'], $fixture['accounting_period_id'], [], '1900-11-30');
            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['accounting_period_id'], '1900-11-30');

            $harness->assertSame(false, (bool)($capacity['reserves_reliable'] ?? true));
            $harness->assertSame('ct_estimate_unavailable', (string)($capacity['corporation_tax_status'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'unrealised gains are excluded from reviewed dividend capacity', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00);
            $incomeNominalId = dividend_service_fixture_income_nominal_for_marker((string)$fixture['marker']);
            dividend_service_save_reserve_review($fixture['company_id'], $fixture['accounting_period_id'], [
                (string)$incomeNominalId => \eel_accounts\Service\DividendReserveClassificationService::TREATMENT_UNREALISED_GAIN,
            ]);

            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['accounting_period_id'], '2022-11-30');

            $harness->assertSame('0.00', number_format((float)($capacity['classified_current_year_profit_loss'] ?? 0), 2, '.', ''));
            $harness->assertTrue((float)($capacity['available_distributable_reserves'] ?? 0) <= 0.0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'prior unrealised gains are excluded from brought-forward distributable reserves', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);
        dividend_service_require_year_end_schema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_two_period_fixture($service);
            $priorIncomeNominalId = dividend_service_fixture_income_nominal_for_marker((string)$fixture['marker'] . 'P');
            dividend_service_save_reserve_review($fixture['company_id'], $fixture['prior_period_id'], [
                (string)$priorIncomeNominalId => \eel_accounts\Service\DividendReserveClassificationService::TREATMENT_UNREALISED_GAIN,
            ], '2021-12-31');
            dividend_service_lock_prior_close($fixture);

            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['current_period_id'], '2022-11-30');

            $harness->assertSame('0.00', number_format((float)($capacity['distributable_reserves_brought_forward'] ?? -1), 2, '.', ''));
            $harness->assertTrue((float)($capacity['available_distributable_reserves'] ?? 0) < 100.00);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'reliability warnings link to upload and transaction workflows', function () use ($harness, $service): void {
        dividend_service_require_reserve_schema($harness);
        foreach (['transactions', 'statement_uploads', 'company_accounts', 'transaction_splits', 'transaction_split_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_manual_fixture($service, 100.00);
            dividend_service_add_uncategorised_transaction($fixture['company_id'], $fixture['accounting_period_id'], $fixture['marker'], '2022-11-15');
            dividend_service_add_valid_transfer_transaction($fixture['company_id'], $fixture['accounting_period_id'], $fixture['marker'], '2022-11-16');
            dividend_service_add_ready_split_transaction($fixture['company_id'], $fixture['accounting_period_id'], $fixture['marker'], '2022-11-17');

            $warnings = $service->getDividendReliabilityWarnings($fixture['company_id'], $fixture['accounting_period_id'], '2022-11-30');
            $byCode = [];
            foreach ($warnings as $warning) {
                $byCode[(string)($warning['code'] ?? '')] = $warning;
            }

            $harness->assertTrue(isset($byCode['bank_csv_coverage']));
            $harness->assertSame('Open Related Workflow', (string)($byCode['bank_csv_coverage']['action_label'] ?? ''));
            $harness->assertSame('?page=uploads', (string)($byCode['bank_csv_coverage']['action_url'] ?? ''));
            $harness->assertSame('uploads', (string)($byCode['bank_csv_coverage']['workflow_page'] ?? ''));
            $harness->assertSame((int)$fixture['company_id'], (int)(($byCode['bank_csv_coverage']['workflow_fields'] ?? [])['company_id'] ?? 0));
            $harness->assertSame((int)$fixture['accounting_period_id'], (int)(($byCode['bank_csv_coverage']['workflow_fields'] ?? [])['accounting_period_id'] ?? 0));
            $harness->assertTrue(isset($byCode['uncategorised_transactions']));
            $harness->assertSame('Open Related Workflow', (string)($byCode['uncategorised_transactions']['action_label'] ?? ''));
            $harness->assertSame('?page=transactions', (string)($byCode['uncategorised_transactions']['action_url'] ?? ''));
            $harness->assertSame('transactions', (string)($byCode['uncategorised_transactions']['workflow_page'] ?? ''));
            $harness->assertSame('uncategorised', (string)(($byCode['uncategorised_transactions']['workflow_fields'] ?? [])['category_filter'] ?? ''));
            $harness->assertSame(false, str_contains((string)($byCode['uncategorised_transactions']['action_url'] ?? ''), 'company_id='));
            $harness->assertSame('1 transaction(s)', (string)($byCode['uncategorised_transactions']['metric_value'] ?? ''));
            $harness->assertSame('Transactions dated on or before the capacity date are uncategorised or missing a nominal account.', (string)($byCode['uncategorised_transactions']['detail'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function dividend_service_effective_as_at_date(\eel_accounts\Service\DividendService $service, ?string $asAtDate, string $periodStart, string $periodEnd): string
{
    $method = (new ReflectionClass($service))->getMethod('effectiveAsAtDate');
    $method->setAccessible(true);

    return (string)$method->invoke($service, $asAtDate, $periodStart, $periodEnd);
}

function dividend_service_history_row(array $history, int $journalId): array
{
    foreach ($history as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) === $journalId) {
            return $row;
        }
    }

    throw new RuntimeException('Dividend history row was not found for journal ' . $journalId . '.');
}

function dividend_service_require_reserve_schema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['dividend_reserve_classification_rules', 'dividend_reserve_review_snapshots'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
    foreach (['as_at_date', 'brought_forward_distributable_reserves', 'dividends_declared', 'closing_distributable_reserves'] as $column) {
        if (!InterfaceDB::columnExists('dividend_reserve_review_snapshots', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }
}

function dividend_service_require_year_end_schema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['year_end_reviews', 'journal_entry_metadata'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function dividend_service_manual_fixture(\eel_accounts\Service\DividendService $service, float $profit, string $periodStart = '2022-01-01', string $periodEnd = '2022-12-31', string $profitDate = '2022-11-01'): array
{
    $marker = 'DIVMAN' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 10));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Dividend Manual Fixture ' . $marker,
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
        ]
    );

    dividend_service_ensure_ct_rate_rules();
    $nominalResult = $service->ensureDividendNominals($companyId);
    dividend_service_configure_payable_nominal($companyId, $nominalResult);
    dividend_service_add_profit_journal($companyId, $accountingPeriodId, $marker, $profit, $profitDate);
    dividend_service_prepare_ct_periods($companyId, $accountingPeriodId);
    dividend_service_save_reserve_review($companyId, $accountingPeriodId, [], dividend_service_default_review_date($periodStart, $periodEnd));

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'marker' => $marker,
    ];
}
function dividend_service_transaction_fixture(\eel_accounts\Service\DividendService $service, float $amount, string $nominalCode): array
{
    $marker = 'DIV' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Dividend Fixture ' . $marker,
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
            'period_start' => '2022-01-01',
            'period_end' => '2022-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
        ]
    );

    dividend_service_ensure_ct_rate_rules();
    $nominalResult = $service->ensureDividendNominals($companyId);
    dividend_service_configure_payable_nominal($companyId, $nominalResult);
    $nominals = (array)($nominalResult['accounts'] ?? []);
    $dividendsPayableId = (int)($nominals['dividends_payable']['id'] ?? 0);
    $dividendsPaidId = (int)($nominals['dividends_paid']['id'] ?? 0);
    $nominalId = $nominalCode === '2150'
        ? $dividendsPayableId
        : dividend_service_fixture_nominal($nominalCode, 'Fixture Nominal ' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            workflow_status
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :workflow_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_month' => '2022-11-01',
            'original_filename' => $marker . '.csv',
            'stored_filename' => $marker . '.csv',
            'file_sha256' => hash('sha256', $marker),
            'workflow_status' => 'committed',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND original_filename = :filename ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'filename' => $marker . '.csv',
        ]
    );

    $dedupeHash = hash('sha256', 'transaction-' . $marker . '-' . $amount . '-' . $nominalCode);
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id,
            accounting_period_id,
            statement_upload_id,
            txn_date,
            txn_type,
            description,
            amount,
            currency,
            source_account_label,
            dedupe_hash,
            nominal_account_id,
            category_status
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :txn_date,
            :txn_type,
            :description,
            :amount,
            :currency,
            :source_account_label,
            :dedupe_hash,
            :nominal_account_id,
            :category_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2022-11-02',
            'txn_type' => 'FP',
            'description' => 'Dividend fixture payment',
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'GBP',
            'source_account_label' => 'Fixture Current Account',
            'dedupe_hash' => $dedupeHash,
            'nominal_account_id' => $nominalId,
            'category_status' => 'manual',
        ]
    );

    dividend_service_add_profit_journal($companyId, $accountingPeriodId, $marker, max(0.0, abs($amount) + 100.00), '2022-11-01');
    dividend_service_prepare_ct_periods($companyId, $accountingPeriodId);
    // Fixture profit for transaction dividend capacity.
    dividend_service_save_reserve_review($companyId, $accountingPeriodId, [], '2022-11-02');

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'transaction_id' => (int)InterfaceDB::fetchColumn(
            'SELECT id FROM transactions WHERE company_id = :company_id AND dedupe_hash = :dedupe_hash ORDER BY id DESC LIMIT 1',
            [
                'company_id' => $companyId,
                'dedupe_hash' => $dedupeHash,
            ]
        ),
        'dividends_paid_id' => $dividendsPaidId,
        'dividends_payable_id' => $dividendsPayableId,
    ];
}

function dividend_service_two_period_fixture(\eel_accounts\Service\DividendService $service): array
{
    $marker = 'DIVTWO' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 10));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Dividend Two Period Fixture ' . $marker,
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'Prior ' . $marker,
            'period_start' => '2021-01-01',
            'period_end' => '2021-12-31',
        ]
    );
    $priorPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => 'Prior ' . $marker,
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'Current ' . $marker,
            'period_start' => '2022-01-01',
            'period_end' => '2022-12-31',
        ]
    );
    $currentPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => 'Current ' . $marker,
        ]
    );

    $nominalResult = $service->ensureDividendNominals($companyId);
    dividend_service_configure_payable_nominal($companyId, $nominalResult);
    dividend_service_ensure_ct_rate_rules();
    dividend_service_add_profit_journal($companyId, $priorPeriodId, $marker . 'P', 100.00, '2021-11-01');
    dividend_service_add_profit_journal($companyId, $currentPeriodId, $marker . 'C', 100.00, '2022-11-01');
    dividend_service_prepare_ct_periods($companyId, $priorPeriodId);
    dividend_service_prepare_ct_periods($companyId, $currentPeriodId);

    return [
        'marker' => $marker,
        'company_id' => $companyId,
        'prior_period_id' => $priorPeriodId,
        'current_period_id' => $currentPeriodId,
    ];
}

function dividend_service_lock_prior_close(array $fixture): void
{
    dividend_service_save_reserve_review((int)$fixture['company_id'], (int)$fixture['prior_period_id'], [], '2021-12-31');
    $closeService = new \eel_accounts\Service\RetainedEarningsCloseService();
    $acknowledged = $closeService->saveAcknowledgement((int)$fixture['company_id'], (int)$fixture['prior_period_id'], true, 'test');
    if (empty($acknowledged['success'])) {
        throw new RuntimeException('Unable to acknowledge retained earnings close: ' . implode(' ', (array)($acknowledged['errors'] ?? [])));
    }
    $posted = $closeService->postClose((int)$fixture['company_id'], (int)$fixture['prior_period_id'], 'test');
    if (empty($posted['success'])) {
        throw new RuntimeException('Unable to post retained earnings close: ' . implode(' ', (array)($posted['errors'] ?? [])));
    }
    (new \eel_accounts\Service\YearEndLockService())->lockPeriod((int)$fixture['company_id'], (int)$fixture['prior_period_id'], 'test');
    if ((int)($fixture['current_period_id'] ?? 0) > 0) {
        dividend_service_save_reserve_review((int)$fixture['company_id'], (int)$fixture['current_period_id'], [], '2022-11-30');
    }
}

function dividend_service_prepare_ct_periods(int $companyId, int $accountingPeriodId): void
{
    $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())
        ->syncForAccountingPeriod($companyId, $accountingPeriodId);
    if (empty($sync['success'])) {
        throw new RuntimeException(implode(' ', (array)($sync['errors'] ?? ['Unable to create CT periods.'])));
    }
    test_confirm_ct_period_facts($companyId, $accountingPeriodId);
}

function dividend_service_configure_payable_nominal(int $companyId, array $nominalResult): void
{
    $payableNominalId = (int)(($nominalResult['accounts'] ?? [])['dividends_payable']['id'] ?? 0);
    if ($payableNominalId <= 0) {
        throw new RuntimeException('Unable to configure the dividend payable nominal for the fixture.');
    }

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('dividends_payable_nominal_id', $payableNominalId, 'int');
    $settings->flush();
}

function dividend_service_save_reserve_review(int $companyId, int $accountingPeriodId, array $treatments = [], ?string $asAtDate = '2022-11-30'): void
{
    if (!InterfaceDB::tableExists('dividend_reserve_review_snapshots')) {
        return;
    }

    $result = (new \eel_accounts\Service\DividendReserveClassificationService())->saveReview($companyId, $accountingPeriodId, $treatments, 'test', $asAtDate);
    if (empty($result['success'])) {
        throw new RuntimeException('Unable to save dividend reserve review: ' . implode(' ', (array)($result['errors'] ?? [])));
    }
}

function dividend_service_default_review_date(string $periodStart, string $periodEnd): string
{
    if ($periodStart <= '2022-11-30' && $periodEnd >= '2022-11-30') {
        return '2022-11-30';
    }

    return $periodEnd;
}

function dividend_service_ensure_ct_rate_rules(): void
{
    if (!InterfaceDB::tableExists('corporation_tax_rate_rules')) {
        return;
    }

    foreach ([
        ['2020-04-01', '2021-03-31', 'test-2020', 0.19],
        ['2021-04-01', '2022-03-31', 'test-2021', 0.19],
        ['2022-04-01', '2023-03-31', 'test-2022', 0.19],
    ] as $rule) {
        $exists = (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM corporation_tax_rate_rules
             WHERE regime = :regime
               AND financial_year_start = :financial_year_start
               AND rule_version = :rule_version',
            [
                'regime' => 'non_ring_fence',
                'financial_year_start' => $rule[0],
                'rule_version' => $rule[2],
            ]
        );
        if ($exists > 0) {
            continue;
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_rate_rules (
                regime,
                financial_year_start,
                financial_year_end,
                rule_version,
                main_rate,
                source_url,
                source_checked_at,
                is_active
             ) VALUES (
                :regime,
                :financial_year_start,
                :financial_year_end,
                :rule_version,
                :main_rate,
                :source_url,
                :source_checked_at,
                1
             )',
            [
                'regime' => 'non_ring_fence',
                'financial_year_start' => $rule[0],
                'financial_year_end' => $rule[1],
                'rule_version' => $rule[2],
                'main_rate' => $rule[3],
                'source_url' => 'test-fixture',
                'source_checked_at' => '2026-01-01',
            ]
        );
    }
}

function dividend_service_add_profit_journal(int $companyId, int $accountingPeriodId, string $marker, float $profit, string $journalDate): void
{
    if ($profit <= 0) {
        return;
    }

    $incomeNominalId = dividend_service_fixture_nominal('4' . substr($marker, -10), 'Fixture Income ' . $marker, 'income');
    $assetNominalId = dividend_service_fixture_nominal('1' . substr($marker, -10), 'Fixture Bank ' . $marker, 'asset');
    $sourceRef = 'fixture:profit:' . $marker;

    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id,
            accounting_period_id,
            source_type,
            source_ref,
            journal_date,
            description,
            is_posted,
            created_at,
            updated_at
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :source_type,
            :source_ref,
            :journal_date,
            :description,
            1,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => 'Fixture profit',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, company_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, NULL, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $assetNominalId,
            'debit' => number_format($profit, 2, '.', ''),
            'credit' => '0.00',
            'line_description' => 'Fixture bank debit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, company_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, NULL, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $incomeNominalId,
            'debit' => '0.00',
            'credit' => number_format($profit, 2, '.', ''),
            'line_description' => 'Fixture income credit',
        ]
    );
}

function dividend_service_add_corporation_tax_provision(int $companyId, int $accountingPeriodId, string $marker, float $amount, string $journalDate): void
{
    if ($amount <= 0) {
        return;
    }

    $expenseNominalId = dividend_service_fixture_nominal('8' . substr($marker, -10), 'Corporation Tax Expense ' . $marker, 'expense');
    $liabilityNominalId = dividend_service_fixture_nominal('2200', 'Corporation Tax', 'liability');
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('corporation_tax_expense_nominal_id', $expenseNominalId, 'int');
    $settings->set('corporation_tax_liability_nominal_id', $liabilityNominalId, 'int');
    $settings->flush();
    $sourceRef = 'fixture:ct:' . $marker . ':' . str_replace('.', '_', number_format($amount, 2, '.', ''));

    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id,
            accounting_period_id,
            source_type,
            source_ref,
            journal_date,
            description,
            is_posted,
            created_at,
            updated_at
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :source_type,
            :source_ref,
            :journal_date,
            :description,
            1,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => 'Fixture Corporation Tax provision',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, company_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, NULL, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $expenseNominalId,
            'debit' => number_format($amount, 2, '.', ''),
            'credit' => '0.00',
            'line_description' => 'Fixture CT expense',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, company_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, NULL, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $liabilityNominalId,
            'debit' => '0.00',
            'credit' => number_format($amount, 2, '.', ''),
            'line_description' => 'Fixture CT liability',
        ]
    );
}

function dividend_service_add_uncategorised_transaction(int $companyId, int $accountingPeriodId, string $marker, string $date): void
{
    dividend_service_insert_transaction(
        $companyId,
        $accountingPeriodId,
        dividend_service_add_statement_upload($companyId, $accountingPeriodId, $marker, $date, 'uncategorised'),
        $date,
        'Uncategorised dividend warning fixture',
        '-12.34',
        hash('sha256', $marker . '-uncategorised-transaction'),
        null,
        'uncategorised',
        null,
        0
    );
}

function dividend_service_add_valid_transfer_transaction(int $companyId, int $accountingPeriodId, string $marker, string $date): void
{
    $nominalId = dividend_service_fixture_nominal('1' . substr($marker, -10), 'Fixture Bank ' . $marker, 'asset');
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'company_id' => $companyId,
            'account_name' => 'Dividend Transfer Account ' . $marker,
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'nominal_account_id' => $nominalId,
        ]
    );
    $transferAccountId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'account_name' => 'Dividend Transfer Account ' . $marker,
        ]
    );

    dividend_service_insert_transaction(
        $companyId,
        $accountingPeriodId,
        dividend_service_add_statement_upload($companyId, $accountingPeriodId, $marker, $date, 'transfer'),
        $date,
        'Valid transfer dividend warning fixture',
        '-20.00',
        hash('sha256', $marker . '-transfer-transaction'),
        null,
        'manual',
        $transferAccountId,
        1
    );
}

function dividend_service_add_ready_split_transaction(int $companyId, int $accountingPeriodId, string $marker, string $date): void
{
    $nominalId = dividend_service_fixture_nominal('6' . substr($marker, -10), 'Fixture Expense ' . $marker, 'expense');
    $dedupeHash = hash('sha256', $marker . '-split-transaction');
    dividend_service_insert_transaction(
        $companyId,
        $accountingPeriodId,
        dividend_service_add_statement_upload($companyId, $accountingPeriodId, $marker, $date, 'split'),
        $date,
        'Ready split dividend warning fixture',
        '-30.00',
        $dedupeHash,
        null,
        'manual',
        null,
        0
    );

    $transactionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE dedupe_hash = :dedupe_hash ORDER BY id DESC LIMIT 1',
        ['dedupe_hash' => $dedupeHash]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transaction_splits (transaction_id)
         VALUES (:transaction_id)',
        ['transaction_id' => $transactionId]
    );
    $splitId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transaction_splits WHERE transaction_id = :transaction_id ORDER BY id DESC LIMIT 1',
        ['transaction_id' => $transactionId]
    );
    foreach ([[1, '15.00'], [2, '15.00']] as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO transaction_split_lines (split_id, line_number, amount, nominal_account_id, is_deferred)
             VALUES (:split_id, :line_number, :amount, :nominal_account_id, 0)',
            [
                'split_id' => $splitId,
                'line_number' => (int)$line[0],
                'amount' => (string)$line[1],
                'nominal_account_id' => $nominalId,
            ]
        );
    }
}

function dividend_service_add_statement_upload(int $companyId, int $accountingPeriodId, string $marker, string $date, string $suffix): int
{
    $filename = $marker . '-' . $suffix . '.csv';
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            workflow_status
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :workflow_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_month' => substr($date, 0, 7) . '-01',
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'file_sha256' => hash('sha256', $marker . '-' . $suffix),
            'workflow_status' => 'committed',
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND original_filename = :filename ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'filename' => $filename,
        ]
    );
}

function dividend_service_insert_transaction(
    int $companyId,
    int $accountingPeriodId,
    int $uploadId,
    string $date,
    string $description,
    string $amount,
    string $dedupeHash,
    ?int $nominalAccountId,
    string $categoryStatus,
    ?int $transferAccountId,
    int $isInternalTransfer
): void {
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id,
            accounting_period_id,
            statement_upload_id,
            txn_date,
            txn_type,
            description,
            amount,
            currency,
            source_account_label,
            dedupe_hash,
            nominal_account_id,
            transfer_account_id,
            is_internal_transfer,
            category_status
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :txn_date,
            :txn_type,
            :description,
            :amount,
            :currency,
            :source_account_label,
            :dedupe_hash,
            :nominal_account_id,
            :transfer_account_id,
            :is_internal_transfer,
            :category_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => $date,
            'txn_type' => 'FP',
            'description' => $description,
            'amount' => $amount,
            'currency' => 'GBP',
            'source_account_label' => 'Fixture Current Account',
            'dedupe_hash' => $dedupeHash,
            'nominal_account_id' => $nominalAccountId,
            'transfer_account_id' => $transferAccountId,
            'is_internal_transfer' => $isInternalTransfer,
            'category_status' => $categoryStatus,
        ]
    );
}

function dividend_service_fixture_income_nominal_for_marker(string $marker): int
{
    return dividend_service_fixture_nominal('4' . substr($marker, -10), 'Fixture Income ' . $marker, 'income');
}

function dividend_service_fixture_nominal(string $code, string $name, string $accountType = 'expense'): int
{
    $existing = (int)(InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    ) ?: 0);
    if ($existing > 0) {
        return $existing;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, 999)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => 'other',
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}
