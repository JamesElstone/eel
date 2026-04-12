<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CompanyAccountService::class, function (GeneratedServiceClassTestHarness $harness, CompanyAccountService $service): void {
    $harness->check(CompanyAccountService::class, 'returns expected account types', function () use ($harness): void {
        $harness->assertSame(
            [
                CompanyAccountService::TYPE_BANK => 'Bank',
                CompanyAccountService::TYPE_TRADE => 'Trade',
            ],
            CompanyAccountService::accountTypes()
        );
    });
});
