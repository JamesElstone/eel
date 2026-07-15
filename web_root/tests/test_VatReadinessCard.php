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
$harness->run(_vat_readinessCard::class, static function (GeneratedServiceClassTestHarness $harness, _vat_readinessCard $card): void {
    $harness->check(_vat_readinessCard::class, 'treats not VAT registered as ready', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'settings' => [
                    'is_vat_registered' => false,
                    'vat_country_code' => '',
                    'vat_number' => '',
                    'vat_validation_status' => '',
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'The company is not VAT registered, so VAT accounting is not required.'));
        $harness->assertSame(5, substr_count($html, '<span class="status-indicator"><span class="status-square ok"></span>Ready</span>'));
        $harness->assertSame(false, str_contains($html, 'Needs attention'));
    });

    $harness->check(_vat_readinessCard::class, 'explains invalid VAT validation status', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'settings' => [
                    'is_vat_registered' => true,
                    'vat_country_code' => 'GB',
                    'vat_number' => '123456789',
                    'vat_validation_status' => 'invalid',
                    'vat_validation_source' => 'hmrc',
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'HMRC returned this VAT number as invalid.'));
        $harness->assertTrue(str_contains($html, 'run Check VAT Number again.'));
    });
});
