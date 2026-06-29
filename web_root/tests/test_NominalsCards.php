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

$harness->run(_nominals_accountsCard::class, function (GeneratedServiceClassTestHarness $harness, _nominals_accountsCard $card): void {
    $harness->check(_nominals_accountsCard::class, 'declares its nominal catalog service', function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertSame('nominal_account_catalog', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(NominalAccountRepository::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchNominalAccountCatalog', (string)($services[0]['method'] ?? ''));
    });

    $harness->check(_nominals_accountsCard::class, 'renders account rows from card service context', function () use ($harness, $card): void {
        $context = [
            'page' => ['page_id' => 'portable_page'],
            'services' => [
                'nominal_account_catalog' => [[
                    'id' => 12,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                    'subtype_name' => 'Direct costs',
                    'tax_treatment' => 'allowable',
                    'sort_order' => 50,
                    'is_active' => 1,
                ], [
                    'id' => 4,
                    'code' => '1000',
                    'name' => 'Current Account',
                    'account_type' => 'asset',
                    'subtype_name' => 'Bank',
                    'tax_treatment' => 'capital',
                    'sort_order' => 500,
                    'is_active' => 1,
                ]],
            ],
        ];
        $html = $card->render($context);
        $tables = $card->tables($context);

        $harness->assertSame(true, ($tables[0] ?? null) instanceof TableFramework);
        $harness->assertTrue(str_contains($html, '<div class="table-scroll"><table>'));
        $harness->assertTrue(str_contains($html, 'Condensed View'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $harness->assertTrue(str_contains($html, '5000'));
        $harness->assertTrue(str_contains($html, 'Materials'));
        $harness->assertTrue(str_contains($html, 'Allowable'));
        $harness->assertTrue(strpos($html, '>1000<') < strpos($html, '>5000<'));
        $harness->assertTrue(str_contains($html, '<th>Tax Treatment</th>'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Nominals"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="edit_nominal_account"'));
    });

    $harness->check(_nominals_accountsCard::class, 'shows developer delete for unused account rows only', function () use ($harness, $card): void {
        $html = $card->render([
            'page' => ['page_id' => 'portable_page'],
            'services' => [
                'nominal_account_catalog' => [[
                    'id' => 12,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                    'tax_treatment' => 'allowable',
                    'sort_order' => 50,
                    'is_active' => 1,
                    'can_delete' => 1,
                ], [
                    'id' => 4,
                    'code' => '1000',
                    'name' => 'Current Account',
                    'account_type' => 'asset',
                    'tax_treatment' => 'capital',
                    'sort_order' => 500,
                    'is_active' => 1,
                    'can_delete' => 0,
                ]],
            ],
        ]);

        $harness->assertSame(1, substr_count($html, 'value="delete_nominal_account"'));
        $harness->assertSame(true, str_contains($html, 'data-chicken-message="Delete this unused nominal account?'));
    });

    $harness->check(_nominals_accountsCard::class, 'hides delete account rows when developer options are disabled', function () use ($harness, $card): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('developer_options', false);

            $html = $card->render([
                'page' => ['page_id' => 'portable_page'],
                'services' => [
                    'nominal_account_catalog' => [[
                        'id' => 12,
                        'code' => '5000',
                        'name' => 'Materials',
                        'account_type' => 'expense',
                        'tax_treatment' => 'allowable',
                        'sort_order' => 50,
                        'is_active' => 1,
                        'can_delete' => 1,
                    ]],
                ],
            ]);

            $harness->assertSame(false, str_contains($html, 'value="delete_nominal_account"'));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });
});

$harness->run(_nominals_add_accountCard::class, function (GeneratedServiceClassTestHarness $harness, _nominals_add_accountCard $card): void {
    $harness->check(_nominals_add_accountCard::class, 'hydrates editing account from portable nominals context', function () use ($harness, $card): void {
        $html = $card->render([
            'nominals' => ['editing_nominal_id' => 12],
            'services' => [
                'nominal_account_catalog' => [[
                    'id' => 12,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                    'account_subtype_id' => 7,
                    'tax_treatment' => 'allowable',
                    'sort_order' => 50,
                    'is_active' => 1,
                ]],
                'nominal_subtypes' => [[
                    'id' => 7,
                    'name' => 'Direct costs',
                    'parent_account_type' => 'expense',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'value="save_nominal_account"'));
        $harness->assertTrue(str_contains($html, 'value="5000"'));
        $harness->assertTrue(str_contains($html, 'Direct costs [expense]'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Nominals"'));
    });
});

$harness->run(_nominals_categoriesCard::class, function (GeneratedServiceClassTestHarness $harness, _nominals_categoriesCard $card): void {
    $harness->check(_nominals_categoriesCard::class, 'renders category rows from card service context', function () use ($harness, $card): void {
        $html = $card->render([
            'page' => ['page_id' => 'portable_page'],
            'services' => [
                'nominal_subtypes' => [[
                    'id' => 7,
                    'code' => 'direct_costs',
                    'name' => 'Direct costs',
                    'parent_account_type' => 'expense',
                    'sort_order' => 20,
                    'is_active' => 1,
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'direct_costs'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="edit_nominal_subtype"'));
    });
});

$harness->run(_nominals_add_categoryCard::class, function (GeneratedServiceClassTestHarness $harness, _nominals_add_categoryCard $card): void {
    $harness->check(_nominals_add_categoryCard::class, 'hydrates editing category from portable nominals context', function () use ($harness, $card): void {
        $html = $card->render([
            'nominals' => ['editing_subtype_id' => 7],
            'services' => [
                'nominal_subtypes' => [[
                    'id' => 7,
                    'code' => 'direct_costs',
                    'name' => 'Direct costs',
                    'parent_account_type' => 'expense',
                    'sort_order' => 20,
                    'is_active' => 1,
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'value="save_nominal_subtype"'));
        $harness->assertTrue(str_contains($html, 'value="direct_costs"'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Nominals"'));
    });
});
