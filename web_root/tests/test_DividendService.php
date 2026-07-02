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

    $harness->check(\eel_accounts\Service\DividendService::class, 'creates dividend declaration from payable transaction once', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('transactions') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
            $harness->skip('Transaction and journal tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = dividend_service_transaction_fixture($service, -129.00, '2150');

            $result = $service->declareDividendFromTransaction($fixture['transaction_id'], $fixture['company_id'], $fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(false, (bool)($result['already_exists'] ?? false));
            $harness->assertSame('dividend:transaction:' . $fixture['transaction_id'], (string)($result['source_ref'] ?? ''));

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
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT is_posted FROM journals WHERE id = :journal_id',
                ['journal_id' => (int)($result['journal_id'] ?? 0)]
            ));

            $capacity = $service->getDividendCapacity($fixture['company_id'], $fixture['accounting_period_id'], '2022-11-30');
            $harness->assertSame('60.00', number_format((float)($capacity['available_distributable_reserves'] ?? 0), 2, '.', ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DividendService::class, 'rejects declarations while the accounting period ends in the future', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('companies') || !InterfaceDB::tableExists('accounting_periods')) {
            $harness->skip('Company and period tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $futureEnd = (new DateTimeImmutable('today'))->modify('+1 month')->format('Y-m-d');
            $fixture = dividend_service_manual_fixture($service, 100.00, '2022-01-01', $futureEnd, '2022-11-01');
            $result = $service->declareDividend([
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['accounting_period_id'],
                'declaration_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'amount' => '10.00',
                'description' => 'Future-period dividend',
                'settlement_target' => 'unpaid_dividend_liability',
            ]);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertTrue(in_array('Dividend declarations are disabled until the selected accounting period has ended.', (array)($result['errors'] ?? []), true));
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

    $service->ensureDividendNominals($companyId);
    dividend_service_add_profit_journal($companyId, $accountingPeriodId, $marker, $profit, $profitDate);

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

    $nominalResult = $service->ensureDividendNominals($companyId);
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
    // Fixture profit for transaction dividend capacity.

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
