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
$harness->run(_vat_registrationCard::class, static function (GeneratedServiceClassTestHarness $harness, _vat_registrationCard $card): void {
    $harness->check(_vat_registrationCard::class, 'renders VAT form with normal submit intents', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
                'settings' => [
                    'company_name' => 'Example Limited',
                    'companies_house_number' => '01234567',
                    'is_vat_registered' => true,
                    'vat_country_code' => 'GB',
                    'vat_number' => '123456789',
                    'vat_validation_status' => 'valid',
                    'vat_validated_at' => '2026-06-29 10:00:00',
                    'vat_validation_source' => 'hmrc',
                    'vat_validation_name' => 'Example Limited',
                    'vat_validation_address_line1' => '1 Example Street',
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="card_action" value="VatRegistration"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_vat"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="validate_vat"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="clear_vat_validation"'));
        $harness->assertSame(false, str_contains($html, 'data-submit-action'));
        $harness->assertTrue(str_contains($html, '1 Example Street'));
    });
});
