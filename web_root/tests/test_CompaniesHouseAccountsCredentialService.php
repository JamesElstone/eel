<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
            'loads shared XML presenter credentials and the accounts-filing package reference',
            static function () use ($h): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ch-accounts-keys-' . bin2hex(random_bytes(4)) . '.csv';
                file_put_contents(
                    $path,
                    "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\n"
                    . "COMPANIESHOUSE,XML,XML_PRESENTER_CREDENTIALS,TEST,HTTPS,example.invalid,12345678901,test-secret\n"
                    . "COMPANIESHOUSE,XML,ACCOUNTS_FILING_PACKAGE_REFERENCE,TEST,HTTPS,example.invalid,,0012\n"
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

                    $companyData = (new \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService($path))->load('TEST');
                    $h->assertSame($credentials['presenter_id'], $companyData['presenter_id']);
                    $h->assertSame($credentials['presenter_code'], $companyData['presenter_code']);
                    $h->assertSame('', $companyData['package_reference']);
                } finally {
                    @unlink($path);
                }
            }
        );
    }
);
