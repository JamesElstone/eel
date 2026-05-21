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
$harness->run(_statement_field_mappingCard::class, static function (GeneratedServiceClassTestHarness $harness, _statement_field_mappingCard $card): void {
    $harness->check(_statement_field_mappingCard::class, 'uses banking field mapping context for account mode', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'bank_accounts',
            ],
            'company' => [
                'id' => 42,
                'tax_year_id' => 61,
            ],
            'field_mapping' => [
                'account_id' => 47,
            ],
            'uploads' => [],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 47,
                        'account_name' => 'Main Current Account',
                        'account_type' => CompanyAccountService::TYPE_BANK,
                    ],
                ],
                'account_mapping_preview' => [
                    'upload' => [
                        'id' => 123,
                        'account_id' => 47,
                        'account_name' => 'Main Current Account',
                        'original_filename' => 'statement.csv',
                        'source_headers_json' => json_encode(['Date', 'Description', 'Amount'], JSON_THROW_ON_ERROR),
                    ],
                    'mapping' => [
                        'mapping_json' => json_encode([
                            'transaction_date' => ['header' => 'Date'],
                            'description' => ['header' => 'Description'],
                            'amount' => ['header' => 'Amount'],
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'source_sample' => [
                        'headers' => ['Date', 'Description', 'Amount'],
                        'rows' => [
                            ['2026-01-01', 'Opening balance', '10.00'],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Saved account mapping source'));
        $harness->assertTrue(str_contains($html, 'name="mapping_account_id" value="47"'));
        $harness->assertTrue(str_contains($html, '<option value="47" selected>Main Current Account'));
        $harness->assertTrue(!str_contains($html, 'Select Field Mappings from an account first'));
    });

    $harness->check(_statement_field_mappingCard::class, 'renders first upload mapping from selected upload context', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'uploads',
            ],
            'company' => [
                'id' => 42,
                'tax_year_id' => 61,
            ],
            'uploads' => [
                'id' => 124,
            ],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 47,
                        'account_name' => 'Main Current Account',
                        'account_type' => CompanyAccountService::TYPE_BANK,
                    ],
                ],
                'selected_upload_preview' => [
                    'upload' => [
                        'id' => 124,
                        'account_id' => 47,
                        'account_name' => 'Main Current Account',
                        'original_filename' => 'first-statement.csv',
                        'source_headers_json' => json_encode(['Date', 'Description', 'Amount'], JSON_THROW_ON_ERROR),
                    ],
                    'mapping' => [],
                    'source_sample' => [
                        'headers' => ['Date', 'Description', 'Amount'],
                        'rows' => [
                            ['2026-01-01', 'Opening balance', '10.00'],
                        ],
                    ],
                ],
                'selected_upload_mapping_status' => [
                    'has_mapping' => false,
                    'extra_headers' => [],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Selected upload'));
        $harness->assertTrue(str_contains($html, 'first-statement.csv'));
        $harness->assertTrue(str_contains($html, 'name="upload_id" value="124"'));
        $harness->assertTrue(str_contains($html, '<option value="47" selected>Main Current Account'));
        $harness->assertTrue(str_contains($html, 'Review mapping'));
        $harness->assertTrue(!str_contains($html, 'Select an upload from Review'));
    });
});
