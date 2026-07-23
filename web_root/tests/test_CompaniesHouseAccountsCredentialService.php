<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
            'loads the three accounts-filing values from api.keys without using security facts',
            static function () use ($h): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ch-accounts-keys-' . bin2hex(random_bytes(4)) . '.csv';
                file_put_contents(
                    $path,
                    "PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\n"
                    . "COMPANIESHOUSE,ACCOUNTS_FILING_PRESENTER_ID,TEST,XML,https://example.invalid,12345678901\n"
                    . "COMPANIESHOUSE,ACCOUNTS_FILING_AUTHENTICATION,TEST,XML,https://example.invalid,test-secret\n"
                    . "COMPANIESHOUSE,ACCOUNTS_FILING_PACKAGE_REFERENCE,TEST,XML,https://example.invalid,0012\n"
                );
                try {
                    $service = new \eel_accounts\Service\CompaniesHouseAccountsCredentialService($path);
                    $credentials = $service->load('TEST');
                    $h->assertSame('12345678901', $credentials['presenter_id']);
                    $h->assertSame('test-secret', $credentials['presenter_code']);
                    $h->assertSame('0012', $credentials['package_reference']);
                    $h->assertSame(
                        hash('sha256', '12345678901'),
                        $service->presenterFingerprint('TEST')
                    );
                } finally {
                    @unlink($path);
                }
            }
        );
    }
);
