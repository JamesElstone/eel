<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
            'loads environment-specific Software References with the XML presenter credentials',
            static function () use ($h): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ch-accounts-keys-' . bin2hex(random_bytes(4)) . '.csv';
                file_put_contents(
                    $path,
                    "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\n"
                    . "COMPANIESHOUSE,XML,XML_PRESENTER_CREDENTIALS,TEST,HTTPS,example.invalid,0012,12345678901,test-secret\n"
                    . "COMPANIESHOUSE,XML,XML_PRESENTER_CREDENTIALS,LIVE,HTTPS,example.invalid,LIVE-PACKAGE,10987654321,live-secret\n"
                );
                try {
                    $service = new \eel_accounts\Service\CompaniesHouseAccountsCredentialService($path);
                    $testCredentials = $service->load('TEST');
                    $liveCredentials = $service->load('LIVE');
                    $h->assertSame('12345678901', $testCredentials['presenter_id']);
                    $h->assertSame('test-secret', $testCredentials['presenter_code']);
                    $h->assertSame('0012', $testCredentials['package_reference']);
                    $h->assertSame('10987654321', $liveCredentials['presenter_id']);
                    $h->assertSame('live-secret', $liveCredentials['presenter_code']);
                    $h->assertSame('LIVE-PACKAGE', $liveCredentials['package_reference']);
                    $h->assertSame(
                        hash('sha256', '12345678901'),
                        $service->presenterFingerprint('TEST')
                    );
                } finally {
                    @unlink($path);
                }
            }
        );

        $h->check(
            \eel_accounts\Service\CompaniesHouseAccountsCredentialService::class,
            'fails closed when the selected presenter credential has no package reference',
            static function () use ($h): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ch-accounts-keys-' . bin2hex(random_bytes(4)) . '.csv';
                file_put_contents(
                    $path,
                    "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\n"
                    . "COMPANIESHOUSE,XML,XML_PRESENTER_CREDENTIALS,TEST,HTTPS,example.invalid,,12345678901,test-secret\n"
                );
                try {
                    $service = new \eel_accounts\Service\CompaniesHouseAccountsCredentialService($path);
                    $h->assertSame(false, $service->configured('TEST'));
                    $message = '';
                    try {
                        $service->load('TEST');
                    } catch (RuntimeException $exception) {
                        $message = $exception->getMessage();
                    }
                    $h->assertTrue(str_contains($message, 'package reference'));
                } finally {
                    @unlink($path);
                }
            }
        );
    }
);
