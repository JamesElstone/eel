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
        $html = $card->render([
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
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '5000'));
        $harness->assertTrue(str_contains($html, 'Materials'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Nominals"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="edit_nominal_account"'));
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
