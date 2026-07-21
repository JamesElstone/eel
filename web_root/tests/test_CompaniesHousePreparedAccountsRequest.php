<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\CompaniesHousePreparedAccountsRequest::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $request = new \eel_accounts\Client\CompaniesHousePreparedAccountsRequest('TEST','ABC123','A1','<raw/>','<redacted/>',['secret'],9,str_repeat('a',64));
        $harness->check($request::class, 'retains the exact validated request identity', static function () use ($harness, $request): void {
            $harness->assertSame('<raw/>', $request->requestXml());
            $harness->assertSame(9, $request->schemaSnapshotId());
            $harness->assertSame(str_repeat('a',64), $request->schemaManifestSha256());
        });
    }
);
