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
                'page_id' => 'source_accounts',
            ],
            'company' => [
                'id' => 42,
                'accounting_period_id' => 61,
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
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    ],
                    [
                        'id' => 48,
                        'account_name' => 'Savings Account',
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
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
        $harness->assertTrue(str_contains($html, 'class="statement-mapping-account-switcher"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="select_field_mapping"'));
        $harness->assertTrue(str_contains($html, 'name="field_mapping_account_id"'));
        $harness->assertTrue(str_contains($html, '<option value="" disabled></option>'));
        $harness->assertTrue(str_contains($html, '<option value="47" selected>Main Current Account'));
        $harness->assertTrue(str_contains($html, '<option value="48">Savings Account</option>'));
        $harness->assertTrue(!str_contains($html, 'Select Field Mappings from an account first'));
    });

    $harness->check(_statement_field_mappingCard::class, 'shows only account switcher before account mapping is selected', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'source_accounts',
            ],
            'company' => [
                'id' => 42,
                'accounting_period_id' => 61,
            ],
            'field_mapping' => [
                'account_id' => 0,
            ],
            'uploads' => [],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 47,
                        'account_name' => 'Main Current Account',
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    ],
                ],
                'account_mapping_preview' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'class="statement-mapping-account-switcher"'));
        $harness->assertTrue(str_contains($html, '<option value="" disabled selected></option>'));
        $harness->assertTrue(str_contains($html, '<option value="47">Main Current Account</option>'));
        $harness->assertTrue(!str_contains($html, 'Saved account mapping source'));
        $harness->assertTrue(!str_contains($html, 'Select Field Mappings from an account first'));
        $harness->assertTrue(!str_contains($html, 'CSV headings and first two rows'));
        $harness->assertTrue(!str_contains($html, 'Save Mapping'));
    });

    $harness->check(_statement_field_mappingCard::class, 'disables protected fields for committed account mappings', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'source_accounts',
            ],
            'company' => [
                'id' => 42,
                'accounting_period_id' => 61,
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
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    ],
                ],
                'account_mapping_preview' => [
                    'upload' => [
                        'id' => 123,
                        'account_id' => 47,
                        'account_name' => 'Main Current Account',
                        'original_filename' => 'committed-statement.csv',
                        'source_headers_json' => json_encode(['Date', 'Posted', 'Description', 'Reference', 'Amount', 'Balance', 'Currency'], JSON_THROW_ON_ERROR),
                        'rows_committed' => 1,
                    ],
                    'mapping' => [
                        'mapping_json' => json_encode([
                            'created' => ['header' => 'Date', 'index' => 0],
                            'processed' => ['header' => 'Posted', 'index' => 1],
                            'description' => ['header' => 'Description', 'index' => 2],
                            'reference' => null,
                            'amount' => ['header' => 'Amount', 'index' => 4],
                            'balance' => ['header' => 'Balance', 'index' => 5],
                            'currency' => ['header' => 'Currency', 'index' => 6],
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'source_sample' => [
                        'headers' => ['Date', 'Posted', 'Description', 'Reference', 'Amount', 'Balance', 'Currency'],
                        'rows' => [
                            ['2026-01-01', '2026-01-02', 'Opening balance', 'REF', '10.00', '10.00', 'GBP'],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'id="account_mapping_created" name="mapping_created" disabled data-statement-mapping-protected-field'));
        $harness->assertTrue(str_contains($html, 'id="account_mapping_processed" name="mapping_processed" disabled data-statement-mapping-protected-field'));
        $harness->assertTrue(str_contains($html, 'id="account_mapping_amount" name="mapping_amount" disabled data-statement-mapping-protected-field'));
        $harness->assertTrue(str_contains($html, 'id="account_mapping_balance" name="mapping_balance" disabled data-statement-mapping-protected-field'));
        $harness->assertTrue(str_contains($html, 'id="account_mapping_currency" name="mapping_currency" disabled data-statement-mapping-protected-field'));
        $harness->assertTrue(str_contains($html, 'id="account_mapping_reference" name="mapping_reference" data-statement-mapping-requires-account'));
        $harness->assertTrue(!str_contains($html, 'id="account_mapping_reference" name="mapping_reference" disabled'));
    });

    $harness->check(_statement_field_mappingCard::class, 'uses upload mode on uploads page without selected upload', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'uploads',
            ],
            'company' => [
                'id' => 42,
                'accounting_period_id' => 61,
            ],
            'uploads' => [],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 47,
                        'account_name' => 'Main Current Account',
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    ],
                ],
                'selected_upload_preview' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="card_action" value="Uploads"'));
        $harness->assertTrue(str_contains($html, 'name="upload_id" value="0"'));
        $harness->assertTrue(str_contains($html, 'data-statement-mapping-account-selector'));
        $harness->assertTrue(str_contains($html, '<option value="" disabled selected></option>'));
        $harness->assertTrue(str_contains($html, 'data-statement-mapping-requires-account'));
        $harness->assertTrue(!str_contains($html, 'Save Mapping'));
        $harness->assertTrue(!str_contains($html, 'Select Field Mappings from an account first'));
    });

    $harness->check(_statement_field_mappingCard::class, 'disables upload mapping controls until account is selected', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'uploads',
            ],
            'company' => [
                'id' => 42,
                'accounting_period_id' => 61,
            ],
            'uploads' => [
                'id' => 124,
            ],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 47,
                        'account_name' => 'Main Current Account',
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    ],
                ],
                'selected_upload_preview' => [
                    'upload' => [
                        'id' => 124,
                        'account_id' => 0,
                        'account_name' => '',
                        'original_filename' => 'unassigned-statement.csv',
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

        $harness->assertTrue(str_contains($html, 'unassigned-statement.csv'));
        $harness->assertTrue(str_contains($html, 'Field mappings apply to: <strong>No account selected</strong>'));
        $harness->assertTrue(str_contains($html, '<option value="" disabled selected></option>'));
        $harness->assertTrue(str_contains($html, 'data-statement-mapping-account-selector'));
        $harness->assertTrue(str_contains($html, 'name="mapping_description" disabled data-statement-mapping-requires-account'));
        $harness->assertTrue(!str_contains($html, 'data-change-submit-button'));
        $harness->assertTrue(!str_contains($html, 'Save Mapping'));
        $harness->assertTrue(!str_contains($html, 'Select Field Mappings from an account first'));
    });

    $harness->check(_statement_field_mappingCard::class, 'renders first upload mapping from selected upload context', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'uploads',
            ],
            'company' => [
                'id' => 42,
                'accounting_period_id' => 61,
            ],
            'uploads' => [
                'id' => 124,
            ],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 47,
                        'account_name' => 'Main Current Account',
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
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
        $harness->assertTrue(str_contains($html, '<option value="" disabled></option>'));
        $harness->assertTrue(str_contains($html, '<option value="47" selected>Main Current Account'));
        $harness->assertTrue(str_contains($html, 'name="account_id" required'));
        $harness->assertTrue(!str_contains($html, 'class="statement-mapping-account-switcher"'));
        $harness->assertTrue(!str_contains($html, 'name="mapping_description" disabled data-statement-mapping-requires-account'));
        $harness->assertTrue(!str_contains($html, 'data-no-submit-on-change="true" data-statement-mapping-requires-account'));
        $harness->assertTrue(str_contains($html, 'Review mapping'));
        $harness->assertTrue(!str_contains($html, 'Select an upload from Review'));
    });
});
