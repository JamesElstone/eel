<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService::class,
            'loads shared XML presenter credentials without a filing package reference',
            static function () use ($harness): void {
                $path = tempnam(sys_get_temp_dir(), 'eel-ch-company-data-');
                if (!is_string($path)) {
                    throw new RuntimeException('Unable to create credential fixture.');
                }
                file_put_contents(
                    $path,
                    "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\n"
                    . "COMPANIESHOUSE,XML,XML_PRESENTER_CREDENTIALS,TEST,HTTPS,example.invalid,output-presenter,output-secret\n"
                );
                try {
                    $service = new \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService($path);
                    $credentials = $service->load('TEST');
                    $harness->assertSame('output-presenter', $credentials['presenter_id']);
                    $harness->assertSame('output-secret', $credentials['presenter_code']);
                    $harness->assertSame('', $credentials['package_reference']);
                    $harness->assertSame(true, $service->configured('TEST'));
                    $harness->assertSame(false, $service->configured('LIVE'));
                } finally {
                    @unlink($path);
                }
            }
        );
    }
);
