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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(GoldenAccountsFixture::class, 'builds deterministic synthetic accounts for the live accounting-period dates', static function () use ($harness): void {
    $manifest = GoldenAccountsFixture::build();
    $harness->assertSame(false, (bool)($manifest['privacy']['live_rows_copied'] ?? true));
    $harness->assertSame(4, count((array)($manifest['periods'] ?? [])));
    $harness->assertSame(4, InterfaceDB::countWhere('accounting_periods', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    $harness->assertSame(19, InterfaceDB::countWhere('transactions', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    $harness->assertSame(4, InterfaceDB::countWhere('expense_claims', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    $harness->assertSame(4, InterfaceDB::countWhere('asset_register', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    GoldenAccountsFixture::build();
    $harness->assertSame(19, InterfaceDB::countWhere('transactions', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
});

$harness->check(GoldenAccountsFixture::class, 'seeds a confirmed, transaction-backed CT600A scenario without manual tax events', static function () use ($harness): void {
    $manifest = GoldenAccountsFixture::build();
    $companyId = GoldenAccountsFixture::CT600A_COMPANY_ID;
    $periodIds = (array)($manifest['ct600a_scenario']['accounting_period_ids'] ?? []);

    $harness->assertSame([
        GoldenAccountsFixture::CT600A_ACCOUNTING_PERIOD_ID,
        GoldenAccountsFixture::CT600A_FOLLOWING_ACCOUNTING_PERIOD_ID,
        GoldenAccountsFixture::CT600A_LATER_RELIEF_ACCOUNTING_PERIOD_ID,
        GoldenAccountsFixture::CT600A_L2P_ACCOUNTING_PERIOD_ID,
    ], $periodIds);
    $harness->assertSame(4, InterfaceDB::countWhere('accounting_periods', 'company_id', $companyId));
    $harness->assertSame(4, InterfaceDB::countWhere('transactions', 'company_id', $companyId));
    $harness->assertSame(3, (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM transactions WHERE company_id = :company_id AND nominal_account_id = :nominal_id AND party_id = :party_id',
        ['company_id' => $companyId, 'nominal_id' => 91006, 'party_id' => GoldenAccountsFixture::CT600A_PARTY_ID]
    ));
    $harness->assertSame(3, (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM journal_lines jl INNER JOIN journals j ON j.id = jl.journal_id
         WHERE j.company_id = :company_id AND jl.nominal_account_id = :nominal_id AND jl.party_id = :party_id',
        ['company_id' => $companyId, 'nominal_id' => 91006, 'party_id' => GoldenAccountsFixture::CT600A_PARTY_ID]
    ));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM corporation_tax_ct600a_events WHERE company_id = :company_id',
        ['company_id' => $companyId]
    ));
});

$harness->check(GoldenAccountsFixture::class, 'apportions one cross-period prepayment by inclusive service days', static function () use ($harness): void {
    GoldenAccountsFixture::build();
    $review = InterfaceDB::fetchOne(
        'SELECT pr.*, t.amount
         FROM prepayment_reviews pr
         INNER JOIN transactions t ON t.id = pr.source_id AND pr.source_type = :source_type
         WHERE pr.company_id = :company_id AND pr.id = :review_id',
        ['source_type' => 'transaction', 'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'review_id' => 9195]
    );
    $harness->assertSame('2025-07-01', (string)$review['service_start_date']);
    $harness->assertSame('2026-06-30', (string)$review['service_end_date']);

    $totalDays = (new DateTimeImmutable((string)$review['service_start_date']))
        ->diff((new DateTimeImmutable((string)$review['service_end_date']))->modify('+1 day'))->days;
    $ap9113Days = (new DateTimeImmutable((string)$review['service_start_date']))
        ->diff((new DateTimeImmutable('2025-09-30'))->modify('+1 day'))->days;
    $cost = abs((float)$review['amount']);
    $ap9113Amount = round($cost * $ap9113Days / $totalDays, 2);
    $ap9114Amount = round($cost - $ap9113Amount, 2);

    $harness->assertSame(365, $totalDays);
    $harness->assertSame(92, $ap9113Days);
    $harness->assertSame(92.00, $ap9113Amount);
    $harness->assertSame(273.00, $ap9114Amount);

    $allocations = InterfaceDB::fetchAll(
        'SELECT psp.accounting_period_id, psp.expense_pence
         FROM prepayment_reviews pr
         INNER JOIN prepayment_schedules ps ON ps.id = pr.current_schedule_id
         INNER JOIN prepayment_schedule_periods psp ON psp.schedule_id = ps.id
         WHERE pr.id = 9195 ORDER BY psp.accounting_period_id'
    );
    $harness->assertSame([9200, 27300], array_map('intval', array_column($allocations, 'expense_pence')));

    foreach ([9113 => 457.00, 9114 => 363.00] as $periodId => $expectedExpense) {
        $expense = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id AND j.accounting_period_id = :period_id
               AND jl.nominal_account_id = :nominal_id AND j.is_posted = 1',
            ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'period_id' => $periodId, 'nominal_id' => 91019]
        );
        $harness->assertSame($expectedExpense, $expense);
    }

    $metadata = InterfaceDB::fetchAll(
        'SELECT j.id, j.source_type, j.source_ref,
                jem.journal_tag, jem.journal_key, jem.entry_mode, jem.related_journal_id
         FROM journals j
         INNER JOIN journal_entry_metadata jem ON jem.journal_id = j.id
         WHERE j.company_id = :company_id
           AND jem.journal_tag IN (:deferral_tag, :release_tag)
           AND jem.journal_key LIKE :journal_key
         ORDER BY CASE jem.journal_tag WHEN :deferral_order THEN 0 ELSE 1 END, j.id',
        [
            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'deferral_tag' => 'prepayment_deferral',
            'release_tag' => 'prepayment_release',
            'deferral_order' => 'prepayment_deferral',
            'journal_key' => 'review:9195:%',
        ]
    );
    $harness->assertSame(2, count($metadata));
    $harness->assertSame('manual', (string)$metadata[0]['source_type']);
    $harness->assertTrue(str_contains((string)$metadata[0]['source_ref'], 'prepayment_deferral'));
    $harness->assertSame('prepayment_deferral', (string)$metadata[0]['journal_tag']);
    $harness->assertTrue(str_contains((string)$metadata[0]['journal_key'], ':9113:'));
    $harness->assertSame('system_generated', (string)$metadata[0]['entry_mode']);
    $harness->assertSame(0, (int)($metadata[0]['related_journal_id'] ?? 0));
    $harness->assertSame('manual', (string)$metadata[1]['source_type']);
    $harness->assertTrue(str_contains((string)$metadata[1]['source_ref'], 'prepayment_release'));
    $harness->assertSame('prepayment_release', (string)$metadata[1]['journal_tag']);
    $harness->assertTrue(str_contains((string)$metadata[1]['journal_key'], ':9114:'));
    $harness->assertSame('system_generated', (string)$metadata[1]['entry_mode']);
    $harness->assertSame(0, (int)($metadata[1]['related_journal_id'] ?? 0));

    $assetSetting = InterfaceDB::fetchOne(
        'SELECT cs.value, nas.code AS subtype_code
         FROM company_settings cs
         INNER JOIN nominal_accounts na ON na.id = CAST(cs.value AS INTEGER)
         LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
         WHERE cs.company_id = :company_id AND cs.setting = :setting',
        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'setting' => 'prepayment_asset_nominal_id']
    );
    $harness->assertSame(91018, (int)$assetSetting['value']);
    $harness->assertSame('prepayments', (string)$assetSetting['subtype_code']);
});

$harness->check(GoldenAccountsFixture::class, 'seeds transaction-backed three-year assets and a reviewed year-two van', static function () use ($harness): void {
    GoldenAccountsFixture::build();
    $assets = InterfaceDB::fetchAll(
        'SELECT ar.*, t.accounting_period_id, vd.vehicle_type, vd.tax_review_status
         FROM asset_register ar
         INNER JOIN transactions t ON t.id = ar.linked_transaction_id
         LEFT JOIN asset_vehicle_details vd ON vd.asset_id = ar.id
         WHERE ar.company_id = :company_id ORDER BY ar.purchase_date, ar.id',
        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID]
    );
    $harness->assertSame(4, count($assets));
    foreach ($assets as $asset) {
        $harness->assertSame(3, (int)$asset['useful_life_years']);
        $harness->assertSame(0.00, (float)$asset['residual_value']);
        $harness->assertSame('straight_line', (string)$asset['depreciation_method']);
        $harness->assertSame(true, (int)$asset['linked_transaction_id'] > 0);
    }
    $harness->assertSame([9111, 9111, 9111, 9112], array_map('intval', array_column($assets, 'accounting_period_id')));
    $harness->assertSame('van', (string)$assets[3]['vehicle_type']);
    $harness->assertSame('reviewed', (string)$assets[3]['tax_review_status']);
});

$harness->check(GoldenAccountsFixture::class, 'keeps every golden period balanced and equal to its semantic manifest', static function () use ($harness): void {
    $manifest = GoldenAccountsFixture::manifest();
    foreach ((array)$manifest['periods'] as $periodId => $expected) {
        $totals = InterfaceDB::fetchOne(
            'SELECT COUNT(DISTINCT t.id) AS transaction_count,
                    COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS income,
                    COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) AS bank_spend
             FROM transactions t WHERE t.company_id = :company_id AND t.accounting_period_id = :period_id',
            ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'period_id' => (int)$periodId]
        );
        $journals = InterfaceDB::fetchOne(
            'SELECT COALESCE(SUM(jl.debit), 0) AS debits, COALESCE(SUM(jl.credit), 0) AS credits
             FROM journals j INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id AND j.accounting_period_id = :period_id AND j.is_posted = 1',
            ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'period_id' => (int)$periodId]
        );
        $harness->assertSame((int)$expected['transaction_count'], (int)($totals['transaction_count'] ?? 0));
        $harness->assertSame((float)$expected['income'], (float)($totals['income'] ?? 0));
        $harness->assertSame((float)$expected['journal_debits'], (float)($journals['debits'] ?? 0));
        $harness->assertSame((float)$expected['journal_credits'], (float)($journals['credits'] ?? 0));
    }
});

$harness->check(GoldenAccountsFixture::class, 'populates empty, warning and completed workflow variants with enforceable feature evidence', static function () use ($harness): void {
    $manifest = GoldenAccountsFixture::build();
    $coverage = (array)($manifest['feature_coverage'] ?? []);
    $harness->assertTrue($coverage !== []);

    $expectedPages = array_keys(GoldenCardComparisonRegistry::selectedPages());
    $coveredPages = [];
    foreach ($coverage as $domain => $definition) {
        foreach ((array)($definition['pages'] ?? []) as $page) {
            $coveredPages[(string)$page] = true;
        }
        foreach ((array)($definition['evidence'] ?? []) as $evidence) {
            $label = (string)($evidence['label'] ?? $domain);
            $sql = (string)($evidence['sql'] ?? '');
            $minimum = (int)($evidence['minimum'] ?? 1);
            $actual = $sql === '' ? 0 : (int)InterfaceDB::fetchColumn($sql);
            if ($actual < $minimum) {
                throw new RuntimeException($domain . ' / ' . $label . ': expected at least ' . $minimum . ' evidence rows, actual ' . $actual . '.');
            }
        }
    }
    sort($expectedPages);
    $actualPages = array_keys($coveredPages);
    sort($actualPages);
    $harness->assertSame($expectedPages, $actualPages);

    $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM transactions WHERE company_id = :company_id',
        ['company_id' => GoldenAccountsFixture::EMPTY_COMPANY_ID]
    ));
    $harness->assertTrue((int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM transactions WHERE company_id = :company_id AND category_status = :status',
        ['company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID, 'status' => 'uncategorised']
    ) > 0);
    $harness->assertTrue((int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM year_end_reviews WHERE company_id = :company_id AND is_locked = 1',
        ['company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID]
    ) > 0);
});

$harness->check(GoldenAccountsFixture::class, 'discovers every downstream accounting page card and no missing card file', static function () use ($harness): void {
    $keys = GoldenAccountsFixture::accountingCardKeys();
    $expectations = (array)(GoldenAccountsFixture::manifest()['card_expectations'] ?? []);
    $harness->assertSame(count($keys), count(array_unique($keys)));
    $harness->assertSame($keys, array_keys($expectations));
    foreach ($keys as $key) {
        $harness->assertTrue(is_file(APP_CARDS . $key . '.php'));
        $className = HelperFramework::cardKeyToClassName($key);
        $harness->assertTrue(class_exists($className));
        $card = new $className();
        $harness->assertTrue($card instanceof CardInterfaceFramework);
        $html = $card->render([
            'company' => ['id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'accounting_period_id' => 9114, 'currency' => 'GBP'],
            'auth' => ['user_id' => 1, 'role_id' => 1],
            'page' => ['page_id' => 'golden_test', 'page_cards' => $keys, 'csrf_token' => 'golden-test-token'],
            'services' => [], 'service_errors' => [],
        ]);
        $harness->assertTrue(is_string($html));
    }
});

$harness->check(GoldenAccountsFixture::class, 'keeps published card metrics tied to real golden-period rows', static function () use ($harness): void {
    $metrics = (array)(GoldenAccountsFixture::manifest()['card_expectations'] ?? []);
    $periodId = 9114;
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;

    $transactionCount = (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM transactions WHERE company_id = :company_id AND accounting_period_id = :period_id',
        ['company_id' => $companyId, 'period_id' => $periodId]
    );
    $claim = InterfaceDB::fetchOne(
        'SELECT COUNT(*) AS claim_count, COALESCE(SUM(claimed_amount), 0) AS claimed_amount
         FROM expense_claims WHERE company_id = :company_id AND accounting_period_id = :period_id',
        ['company_id' => $companyId, 'period_id' => $periodId]
    );
    $journals = InterfaceDB::fetchOne(
        'SELECT COUNT(DISTINCT j.id) AS journal_count,
                COALESCE(SUM(jl.debit), 0) AS debits,
                COALESCE(SUM(jl.credit), 0) AS credits
         FROM journals j
         INNER JOIN journal_lines jl ON jl.journal_id = j.id
         WHERE j.company_id = :company_id AND j.accounting_period_id = :period_id AND j.is_posted = 1',
        ['company_id' => $companyId, 'period_id' => $periodId]
    );

    $harness->assertSame((int)$metrics['transactions_imported']['metrics']['transaction_count'], $transactionCount);
    $harness->assertSame((int)$metrics['expense_statistics']['metrics']['claim_count'], (int)$claim['claim_count']);
    $harness->assertSame((float)$metrics['expense_statistics']['metrics']['claimed_amount'], (float)$claim['claimed_amount']);
    $harness->assertSame((int)$metrics['journals_list']['metrics']['journal_count'], (int)$journals['journal_count']);
    $harness->assertSame((float)$metrics['journals_list']['metrics']['debits'], (float)$journals['debits']);
    $harness->assertSame((float)$metrics['journals_list']['metrics']['credits'], (float)$journals['credits']);
});

$harness->check(GoldenAccountsFixture::class, 'resolves declared services and renders every accounting card against the golden database', static function () use ($harness): void {
    $keys = GoldenAccountsFixture::accountingCardKeys();
    $services = createTestPageServiceFramework();
    $renderer = new CardRendererFramework(new CardFactoryFramework());
    $resolver = new ReflectionMethod(CardRendererFramework::class, 'resolveCardService');
    $resolver->setAccessible(true);
    $request = new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []);
    $selectedCtPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM corporation_tax_periods
         WHERE company_id = :company_id AND accounting_period_id = :period_id
         ORDER BY sequence_no, id LIMIT 1',
        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'period_id' => 9114]
    );
    $baseContext = [
        'company' => [
            'id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'accounting_period_id' => 9114,
            'accounting_period_start' => '2025-10-01',
            'accounting_period_end' => '2026-09-30',
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'ct_period_id' => $selectedCtPeriodId,
            'currency' => 'GBP',
            'settings' => [
                'default_bank_nominal_id' => 91001,
                'default_sales_nominal_id' => 91002,
                'default_expense_nominal_id' => 91004,
                'default_director_loan_nominal_id' => 91005,
                'tools_small_equipment_nominal_id' => 91004,
                'potential_asset_threshold' => 100,
            ],
        ],
        'auth' => ['user_id' => 1, 'role_id' => 1],
        'page' => ['page_id' => 'golden_test', 'page_cards' => $keys, 'csrf_token' => 'golden-test-token'],
        'selected_company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
        'selected_accounting_period_id' => 9114,
        'tax' => ['selected_ct_period_id' => $selectedCtPeriodId],
        'edit_account_id' => 9120,
        'account_id' => 9120,
        'statement_upload_id' => 9143,
        'upload_id' => 9143,
        'expense_claim_id' => 9153,
        'claim_id' => 9153,
        'transaction_id' => 9192,
        'uploads' => ['id' => 9143, 'statement_upload_id' => 9143],
        'field_mapping' => ['account_id' => 9120],
        'csrf_token' => 'golden-test-token',
    ];

    foreach ($keys as $key) {
        $className = HelperFramework::cardKeyToClassName($key);
        $card = new $className();
        $context = $card->handle($request, $services, $baseContext, ActionResultFramework::none());
        $cardContext = $context + ['services' => [], 'service_errors' => []];
        foreach ($card->services() as $definition) {
            $serviceKey = (string)($definition['key'] ?? '');
            if ($serviceKey === '') {
                continue;
            }
            $resolved = $resolver->invoke($renderer, $serviceKey, $definition, $context, $services);
            if (($resolved['error'] ?? null) !== null) {
                $message = (string)($resolved['error']['message'] ?? 'service error');
                $isKnownSqliteDialectGap = str_contains($message, 'no such function:')
                    || str_contains($message, 'near "INTERVAL": syntax error');
                if ($message !== 'No records returned by service.' && !$isKnownSqliteDialectGap) {
                    throw new RuntimeException($key . '.' . $serviceKey . ': ' . $message);
                }
            }
            $cardContext['services'][$serviceKey] = $resolved['data'] ?? null;
        }
        $harness->assertTrue(is_string($card->render($cardContext)));
    }
});
