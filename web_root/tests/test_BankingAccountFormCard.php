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
$harness->run(_banking_account_formCard::class, static function (GeneratedServiceClassTestHarness $harness, _banking_account_formCard $card): void {
    $baseAccount = [
        'id' => 47,
        'account_name' => 'Main Current Account',
        'account_identifier' => '12345678',
        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
        'nominal_account_id' => 1001,
        'institution_name' => 'Anna Money',
        'internal_transfer_marker' => 'P2P',
        'contact_name' => 'Accounts',
        'phone_number' => '01234 567890',
        'address_line_1' => '1 High Street',
        'address_locality' => 'Leeds',
        'address_postal_code' => 'LS1 1AA',
        'is_active' => 1,
    ];
    $baseContext = [
        'edit_account_id' => 47,
        'page' => [
            'page_cards' => ['banking_account_form'],
        ],
        'company' => [
            'id' => 42,
            'accounting_period_id' => 77,
        ],
        'services' => [
            'nominal_accounts' => [],
            'LookupCompanyAccount' => $baseAccount,
        ],
    ];

    $harness->check(_banking_account_formCard::class, 'disables transfer marker when posted source journals exist', static function () use ($harness, $card, $baseContext): void {
        $context = $baseContext;
        $context['services']['LookupCompanyAccount']['has_posted_source_journals'] = 1;

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'id="internal_transfer_marker"'));
        $harness->assertSame(true, str_contains($html, 'value="P2P" maxlength="6" size="6" placeholder="P2P" disabled title="Transactions posted, unable to change"'));
    });

    $harness->check(_banking_account_formCard::class, 'keeps transfer marker editable before source journals are posted', static function () use ($harness, $card, $baseContext): void {
        $context = $baseContext;
        $context['services']['LookupCompanyAccount']['has_posted_source_journals'] = 0;

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'id="internal_transfer_marker"'));
        $harness->assertSame(false, str_contains($html, 'Transactions posted, unable to change'));
        $harness->assertSame(false, str_contains($html, 'placeholder="P2P" disabled'));
    });
});
