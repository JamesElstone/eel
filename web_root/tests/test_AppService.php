<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AppService::class, function (GeneratedServiceClassTestHarness $harness, AppService $services): void {
    $harness->check(AppService::class, 'resolves the CompanyAccountService service', function () use ($harness, $services): void {
        $companyAccount = $services->get(CompanyAccountService::class);

        $harness->assertTrue($companyAccount instanceof CompanyAccountService);
    });

    $harness->check(AppService::class, 'memoises resolved services', function () use ($harness, $services): void {
        $companyAccount = $services->get(CompanyAccountService::class);
        $harness->assertSame($companyAccount, $services->get(CompanyAccountService::class));
    });
});
