<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\ApiKeysEditorService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\ApiKeysEditorService::class,
            'lists metadata without returning API key values and preserves a blank replacement',
            static function () use ($harness): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'api-keys-editor-' . bin2hex(random_bytes(4)) . '.csv';
                $original = "# PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\n"
                    . "# preserved comment\n"
                    . "COMPANIESHOUSE,COMPANY_LOOKUP,TEST,HTTPS,example.invalid,secret-never-rendered\n"
                    . "HMRC,LEGACY,HTTPS,legacy.example,legacy-secret\n";
                file_put_contents($path, $original);
                try {
                    $service = new \eel_accounts\Service\ApiKeysEditorService($path);
                    $listing = $service->listing();
                    $harness->assertCount(2, $listing['rows']);
                    $harness->assertSame(false, str_contains(json_encode($listing), 'secret-never-rendered'));
                    $first = $listing['rows'][0];
                    $result = $service->save([
                        $first['id'] => [
                            'provider' => 'COMPANIESHOUSE',
                            'tag' => 'COMPANY_LOOKUP',
                            'environment' => 'TEST',
                            'schema' => 'HTTPS',
                            'url' => 'changed.example.invalid',
                            'api_key' => '',
                        ],
                    ], []);
                    $harness->assertSame(true, $result['changed']);
                    $harness->assertSame(true, $result['backup_created']);
                    $backups = glob($path . '.backup.*') ?: [];
                    $harness->assertCount(1, $backups);
                    $harness->assertSame($original, file_get_contents($backups[0]));
                    $updated = (string)file_get_contents($path);
                    $harness->assertSame(true, str_contains($updated, 'secret-never-rendered'));
                    $harness->assertSame(true, str_contains($updated, '# preserved comment'));
                    $harness->assertSame(true, str_contains($updated, 'HMRC,LEGACY,HTTPS,legacy.example,legacy-secret'));
                } finally {
                    foreach (glob($path . '*') ?: [] as $file) {
                        @unlink($file);
                    }
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\ApiKeysEditorService::class,
            'adds credentials, replaces only supplied secrets, and creates collision-safe backups',
            static function () use ($harness): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'api-keys-editor-' . bin2hex(random_bytes(4)) . '.csv';
                file_put_contents($path, "# PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\nHMRC,VAT,TEST,HTTPS,example.invalid,old\n");
                try {
                    $service = new \eel_accounts\Service\ApiKeysEditorService($path);
                    $row = $service->listing()['rows'][0];
                    $service->save([
                        $row['id'] => [
                            'provider' => 'HMRC', 'tag' => 'VAT', 'environment' => 'TEST', 'schema' => 'HTTPS',
                            'url' => 'example.invalid', 'api_key' => 'replacement',
                        ],
                    ], []);
                    $service->save([], [
                        'provider' => 'COMPANIESHOUSE', 'tag' => 'NEW_KEY', 'environment' => 'TEST',
                        'schema' => 'XML', 'url' => 'https://example.invalid', 'api_key' => 'new-secret',
                    ]);
                    $contents = (string)file_get_contents($path);
                    $harness->assertSame(true, str_contains($contents, 'replacement'));
                    $harness->assertSame(true, str_contains($contents, 'new-secret'));
                    $harness->assertCount(2, glob($path . '.backup.*') ?: []);
                } finally {
                    foreach (glob($path . '*') ?: [] as $file) {
                        @unlink($file);
                    }
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\ApiKeysEditorService::class,
            'creates the exact Companies House TEST setup without returning generated HMAC material',
            static function () use ($harness): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'api-keys-editor-' . bin2hex(random_bytes(4)) . '.csv';
                file_put_contents($path, "# PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\n");
                try {
                    $service = new \eel_accounts\Service\ApiKeysEditorService($path);
                    $result = $service->configureCompaniesHouseTest([
                        'ACCOUNTS_FILING_PRESENTER_ID' => 'presenter',
                        'ACCOUNTS_FILING_AUTHENTICATION' => 'filing-authentication',
                        'ACCOUNTS_FILING_PACKAGE_REFERENCE' => '0012',
                        'COMPANY_DATA_PRESENTER_ID' => 'output-presenter',
                        'COMPANY_DATA_AUTHENTICATION' => 'output-authentication',
                    ], true);
                    $harness->assertSame(true, $result['changed']);
                    $harness->assertSame(false, str_contains(json_encode($result), 'filing-authentication'));
                    $contents = (string)file_get_contents($path);
                    foreach ([
                        'ACCOUNTS_FILING_PRESENTER_ID', 'ACCOUNTS_FILING_AUTHENTICATION',
                        'ACCOUNTS_FILING_PACKAGE_REFERENCE', 'COMPANY_DATA_PRESENTER_ID',
                        'COMPANY_DATA_AUTHENTICATION', 'PREFLIGHT_BINDING_HMAC_KEY',
                    ] as $tag) {
                        $harness->assertSame(true, str_contains($contents, 'COMPANIESHOUSE,' . $tag . ',TEST,XML,https://xmlgw.companieshouse.gov.uk/v1-0/xmlgw/Gateway,'));
                    }
                    $harness->assertSame(true, preg_match('/PREFLIGHT_BINDING_HMAC_KEY,TEST,XML,[^,]+,([a-f0-9]{64})/', $contents) === 1);
                    $unchanged = $service->configureCompaniesHouseTest([], true);
                    $harness->assertSame(false, $unchanged['changed']);
                } finally {
                    foreach (glob($path . '*') ?: [] as $file) {
                        @unlink($file);
                    }
                }
            }
        );
    }
);
