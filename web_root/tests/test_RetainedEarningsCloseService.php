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
$harness->run(\eel_accounts\Service\RetainedEarningsCloseService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'posts loss close to retained earnings without changing source journals', static function () use ($harness): void {
        retainedEarningsCloseRequireSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = retainedEarningsCloseCreateLossFixture();
            $service = new \eel_accounts\Service\RetainedEarningsCloseService();
            $precomputedProvision = [
                'available' => false,
                'errors' => ['Precomputed provision unavailable for this fixture.'],
                'unposted_corporation_tax_adjustment' => 0.0,
            ];
            $precomputedContext = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                $precomputedProvision
            );
            $harness->assertSame(true, (bool)($precomputedContext['available'] ?? false));
            $harness->assertSame($precomputedProvision, (array)($precomputedContext['corporation_tax_provision'] ?? []));
            $harness->assertSame(true, array_key_exists('journal_lines', $precomputedContext));
            $harness->assertSame(true, array_key_exists('depreciation_preview', $precomputedContext));
            $harness->assertSame(false, array_key_exists('preview_deferred', $precomputedContext));

            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($context['available'] ?? false));
            $harness->assertSame('-200.00', number_format((float)(($context['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));
            $harness->assertSame(
                (string)(($precomputedContext['summary'] ?? [])['current_profit_loss'] ?? ''),
                (string)(($context['summary'] ?? [])['current_profit_loss'] ?? '')
            );
            $harness->assertSame(
                (array)($precomputedContext['journal_lines'] ?? []),
                (array)($context['journal_lines'] ?? [])
            );
            $harness->assertSame(false, (bool)($context['acknowledged'] ?? true));

            $acknowledged = $service->saveAcknowledgement((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));

            $posted = $service->postClose((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($posted['success'] ?? false));

            $closeJournal = (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
            );
            $harness->assertTrue(is_array($closeJournal));

            $retainedLine = retainedEarningsCloseLineForNominal((array)$closeJournal, (int)$fixture['retained_earnings_nominal_id']);
            $harness->assertSame('200.00', number_format((float)($retainedLine['debit'] ?? 0), 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)($retainedLine['credit'] ?? 0), 2, '.', ''));

            $profit = (new \eel_accounts\Service\YearEndMetricsService())->profitAndLossSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            );
            $harness->assertSame('-200.00', number_format((float)($profit['profit_before_tax'] ?? 0), 2, '.', ''));

            $balanceSheet = (new \eel_accounts\Service\YearEndMetricsService())->fetchBalanceSheetMetricValues(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            );
            $harness->assertSame('-200.00', number_format((float)($balanceSheet['equity_capital_reserves'] ?? 0), 2, '.', ''));

            $harness->assertSame(2, retainedEarningsCloseFixtureSourceJournalCount((int)$fixture['company_id'], (string)$fixture['marker']));

            (new \eel_accounts\Service\YearEndLockService())->lockPeriod((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $unlocked = (new \eel_accounts\Service\YearEndLockService())->unlockPeriod((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($unlocked['success'] ?? false));
            $harness->assertSame(true, (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
            ) !== null);
            $harness->assertSame(2, retainedEarningsCloseFixtureSourceJournalCount((int)$fixture['company_id'], (string)$fixture['marker']));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'includes pending year-end depreciation in approval figures', static function () use ($harness): void {
        retainedEarningsCloseRequireDepreciationSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseEnsureNominal('1000', 'Bank', 'asset');
            retainedEarningsCloseEnsureNominal('1300', 'Tools', 'asset');
            retainedEarningsCloseEnsureNominal('1330', 'Accum Dep - Tools', 'asset');
            retainedEarningsCloseEnsureNominal('3000', 'Retained Earnings', 'equity');
            retainedEarningsCloseEnsureNominal('4000', 'Sales', 'income');
            retainedEarningsCloseEnsureNominal('6200', 'Depreciation Expense', 'expense');

            $fixture = retainedEarningsCloseCreateDepreciationFixture();
            $service = new \eel_accounts\Service\RetainedEarningsCloseService();
            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($context['available'] ?? false));
            $harness->assertSame(1, (int)(($context['depreciation_preview'] ?? [])['created'] ?? 0));
            $harness->assertSame('400.00', number_format((float)(($context['depreciation_preview'] ?? [])['total_amount'] ?? 0), 2, '.', ''));
            $harness->assertSame('600.00', number_format((float)(($context['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));

            $acknowledged = $service->saveAcknowledgement((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
            $harness->assertSame(false, (bool)($service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['acknowledgement_stale'] ?? true));

            $postedDepreciation = (new \eel_accounts\Service\AssetService())->runDepreciation((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($postedDepreciation['success'] ?? false));

            $afterPosting = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(0, (int)(($afterPosting['depreciation_preview'] ?? [])['created'] ?? -1));
            $harness->assertSame('600.00', number_format((float)(($afterPosting['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));
            $harness->assertSame(false, (bool)($afterPosting['acknowledgement_stale'] ?? true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'includes pending CT provision in approval figures', static function () use ($harness): void {
        $service = new \eel_accounts\Service\RetainedEarningsCloseService();
        $method = new ReflectionMethod($service, 'includePendingCorporationTaxProvisionRows');
        $method->setAccessible(true);
        $rows = [
            [
                'id' => 8500,
                'code' => '8500',
                'name' => 'Corporation Tax Expense',
                'account_type' => 'expense',
                'tax_treatment' => 'disallowable',
                'total_debit' => '100.00',
                'total_credit' => '0.00',
            ],
        ];

        $withCharge = $method->invoke($service, $rows, [
            'available' => true,
            'unposted_corporation_tax_adjustment' => 50.25,
        ]);
        $harness->assertSame('150.25', (string)$withCharge[0]['total_debit']);
        $harness->assertSame('0.00', (string)$withCharge[0]['total_credit']);
        $harness->assertSame('50.25', (string)$withCharge[0]['pending_corporation_tax_provision']);

        $withReversal = $method->invoke($service, $rows, [
            'available' => true,
            'unposted_corporation_tax_adjustment' => -25.50,
        ]);
        $harness->assertSame('100.00', (string)$withReversal[0]['total_debit']);
        $harness->assertSame('25.50', (string)$withReversal[0]['total_credit']);
        $harness->assertSame('-25.50', (string)$withReversal[0]['pending_corporation_tax_provision']);
    });
});

function retainedEarningsCloseRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'journal_entry_metadata', 'nominal_accounts', 'year_end_reviews', 'year_end_review_acknowledgements', 'year_end_audit_log'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['basis_version', 'basis_hash', 'basis_json'] as $column) {
        if (!InterfaceDB::columnExists('year_end_review_acknowledgements', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }

    foreach (['1000', '3000', '4000', '5000'] as $code) {
        if (retainedEarningsCloseNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function retainedEarningsCloseRequireDepreciationSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'journal_entry_metadata', 'nominal_accounts', 'year_end_reviews', 'year_end_review_acknowledgements', 'year_end_audit_log', 'asset_register', 'asset_depreciation_entries'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['basis_version', 'basis_hash', 'basis_json'] as $column) {
        if (!InterfaceDB::columnExists('year_end_review_acknowledgements', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }
}

function retainedEarningsCloseCreateLossFixture(): array
{
    $marker = 'retained-close-' . bin2hex(random_bytes(4));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Retained Close Fixture',
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
            'label' => '2026 retained close fixture',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => '2026 retained close fixture',
        ]
    );

    retainedEarningsCloseInsertJournal($companyId, $accountingPeriodId, $marker . '-sales', '2026-03-31', [
        ['nominal_account_id' => retainedEarningsCloseNominalId('1000'), 'debit' => '1000.00', 'credit' => '0.00', 'line_description' => 'Bank receipt'],
        ['nominal_account_id' => retainedEarningsCloseNominalId('4000'), 'debit' => '0.00', 'credit' => '1000.00', 'line_description' => 'Sales'],
    ]);
    retainedEarningsCloseInsertJournal($companyId, $accountingPeriodId, $marker . '-materials', '2026-04-30', [
        ['nominal_account_id' => retainedEarningsCloseNominalId('5000'), 'debit' => '1200.00', 'credit' => '0.00', 'line_description' => 'Materials'],
        ['nominal_account_id' => retainedEarningsCloseNominalId('1000'), 'debit' => '0.00', 'credit' => '1200.00', 'line_description' => 'Bank payment'],
    ]);

    return [
        'marker' => $marker,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'retained_earnings_nominal_id' => retainedEarningsCloseNominalId('3000'),
    ];
}

function retainedEarningsCloseCreateDepreciationFixture(): array
{
    $marker = 'retained-dep-' . bin2hex(random_bytes(4));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Retained Dep Fixture',
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
            'label' => '2025 retained dep fixture',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => '2025 retained dep fixture',
        ]
    );

    retainedEarningsCloseInsertJournal($companyId, $accountingPeriodId, $marker . '-sales', '2025-03-31', [
        ['nominal_account_id' => retainedEarningsCloseNominalId('1000'), 'debit' => '1000.00', 'credit' => '0.00', 'line_description' => 'Bank receipt'],
        ['nominal_account_id' => retainedEarningsCloseNominalId('4000'), 'debit' => '0.00', 'credit' => '1000.00', 'line_description' => 'Sales'],
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
            'asset_code' => 'RDEP-' . substr($marker, -8),
            'description' => 'Retained earnings pending depreciation fixture asset',
            'category' => 'tools_equipment',
            'nominal_account_id' => retainedEarningsCloseNominalId('1300'),
            'accum_dep_nominal_id' => retainedEarningsCloseNominalId('1330'),
            'purchase_date' => '2025-01-01',
            'cost' => 1200.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'marker' => $marker,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function retainedEarningsCloseInsertJournal(int $companyId, int $accountingPeriodId, string $sourceRef, string $date, array $lines): void
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
            'description' => 'Retained close fixture',
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
                'nominal_account_id' => (int)$line['nominal_account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'line_description' => $line['line_description'],
            ]
        );
    }
}

function retainedEarningsCloseLineForNominal(array $journal, int $nominalAccountId): array
{
    foreach ((array)($journal['lines'] ?? []) as $line) {
        if ((int)($line['nominal_account_id'] ?? 0) === $nominalAccountId) {
            return (array)$line;
        }
    }

    throw new RuntimeException('Unable to find journal line for nominal ' . $nominalAccountId);
}

function retainedEarningsCloseFixtureSourceJournalCount(int $companyId, string $marker): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM journals
         WHERE company_id = :company_id
           AND source_ref LIKE :source_ref',
        [
            'company_id' => $companyId,
            'source_ref' => $marker . '-%',
        ]
    );
}

function retainedEarningsCloseNominalId(string $code): int
{
    return (int)(InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code AND is_active = 1 LIMIT 1',
        ['code' => $code]
    ) ?: 0);
}

function retainedEarningsCloseEnsureNominal(string $code, string $name, string $accountType): int
{
    $existingId = retainedEarningsCloseNominalId($code);
    if ($existingId > 0) {
        return $existingId;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, is_active)
         VALUES (:code, :name, :account_type, 1)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
        ]
    );

    return retainedEarningsCloseNominalId($code);
}
