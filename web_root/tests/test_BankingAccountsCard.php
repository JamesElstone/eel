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
$harness->run(_banking_accountsCard::class, static function (GeneratedServiceClassTestHarness $harness, _banking_accountsCard $card): void {
    $context = [
        'page' => [
            'page_cards' => ['banking_accounts'],
        ],
        'company' => [
            'id' => 42,
        ],
        'services' => [
            'companyAccounts' => [
                [
                    'id' => 47,
                    'account_name' => 'Main Current Account',
                    'account_identifier' => '12345678',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'nominal_code' => '1001',
                    'nominal_name' => 'Main Current Account',
                    'institution_name' => 'Example Bank',
                    'internal_transfer_marker' => 'BANK-MAIN',
                    'phone_number' => '01234 567890',
                    'address_line_1' => '1 High Street',
                    'address_locality' => 'Leeds',
                    'address_postal_code' => 'LS1 1AA',
                    'is_active' => 1,
                ],
            ],
        ],
    ];

    $harness->check(_banking_accountsCard::class, 'renders company accounts with framework table controls', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, '<div class="card-toolbar">'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, '<div class="table-scroll"><table>'));
        $harness->assertTrue(str_contains($html, 'Main Current Account'));
        $harness->assertTrue(str_contains($html, '12345678'));
        $harness->assertTrue(str_contains($html, '1001 Main Current Account'));
        $harness->assertTrue(str_contains($html, 'BANK-MAIN'));
        $harness->assertTrue(str_contains($html, 'Field Mappings'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
    });

    $harness->check(_banking_accountsCard::class, 'disables missing nominal assignment when all accounts have nominals', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'Assign Missing Nominals'));
        $harness->assertTrue(str_contains($html, 'type="submit" disabled title="All account sources already have a nominal assigned." data-chicken-check="true"'));
    });

    $harness->check(_banking_accountsCard::class, 'enables missing nominal assignment when any account has no nominal', static function () use ($harness, $card, $context): void {
        $contextWithMissingNominal = $context;
        $contextWithMissingNominal['services']['companyAccounts'][] = [
            'id' => 48,
            'account_name' => 'Trade Creditor Account',
            'account_identifier' => 'CR-001',
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
            'nominal_code' => '',
            'nominal_name' => '',
            'institution_name' => 'Supplier',
            'phone_number' => '01234 567891',
            'address_line_1' => '2 High Street',
            'address_locality' => 'Leeds',
            'address_postal_code' => 'LS1 1AB',
            'is_active' => 1,
        ];

        $html = $card->render($contextWithMissingNominal);

        $harness->assertTrue(str_contains($html, 'Assign Missing Nominals'));
        $harness->assertTrue(!str_contains($html, 'type="submit" disabled title="All account sources already have a nominal assigned." data-chicken-check="true"'));
    });

    $harness->check(_banking_accountsCard::class, 'registers exportable table without row actions', static function () use ($harness, $card, $context): void {
        $tables = $card->tables($context);

        $harness->assertCount(1, $tables);
        $harness->assertTrue($tables[0] instanceof TableFramework);

        $csv = $tables[0]->exportCsv();

        $harness->assertTrue(str_contains($csv, 'Main Current Account | 12345678'));
        $harness->assertTrue(str_contains($csv, '1001 Main Current Account'));
        $harness->assertTrue(str_contains($csv, '1 High Street, Leeds, LS1 1AA'));
        $harness->assertTrue(!str_contains($csv, 'Field Mappings'));
    });
});
