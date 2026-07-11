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
    $harness->assertSame(17, InterfaceDB::countWhere('transactions', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    $harness->assertSame(4, InterfaceDB::countWhere('expense_claims', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    $harness->assertSame(4, InterfaceDB::countWhere('asset_register', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
    GoldenAccountsFixture::build();
    $harness->assertSame(17, InterfaceDB::countWhere('transactions', 'company_id', GoldenAccountsFixture::GOLDEN_COMPANY_ID));
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

$harness->check(GoldenAccountsFixture::class, 'registers every downstream accounting card and no missing card file', static function () use ($harness): void {
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

$harness->check(GoldenAccountsFixture::class, 'resolves declared services and renders every accounting card against the golden database', static function () use ($harness): void {
    $keys = GoldenAccountsFixture::accountingCardKeys();
    $services = createTestPageServiceFramework();
    $renderer = new CardRendererFramework(new CardFactoryFramework());
    $resolver = new ReflectionMethod(CardRendererFramework::class, 'resolveCardService');
    $resolver->setAccessible(true);
    $request = new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []);
    $baseContext = [
        'company' => [
            'id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'accounting_period_id' => 9114,
            'accounting_period_start' => '2025-10-01',
            'accounting_period_end' => '2026-09-30',
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'ct_period_id' => 0,
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
