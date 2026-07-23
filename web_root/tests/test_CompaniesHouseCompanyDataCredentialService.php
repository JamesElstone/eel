<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService::class,
            'loads separate XML Output credentials without a filing package reference',
            static function () use ($harness): void {
                $path = tempnam(sys_get_temp_dir(), 'eel-ch-company-data-');
                if (!is_string($path)) {
                    throw new RuntimeException('Unable to create credential fixture.');
                }
                file_put_contents(
                    $path,
                    "provider,tag,environment,protocol,endpoint,api_key\n"
                    . "COMPANIESHOUSE,COMPANY_DATA_PRESENTER_ID,TEST,XML,https://example.invalid,output-presenter\n"
                    . "COMPANIESHOUSE,COMPANY_DATA_AUTHENTICATION,TEST,XML,https://example.invalid,output-secret\n"
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
