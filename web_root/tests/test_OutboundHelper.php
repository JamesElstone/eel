<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(OutboundHelper::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $tempPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'outbound-helper-commented-api-keys.csv';

    if (!is_dir(dirname($tempPath))) {
        mkdir(dirname($tempPath), 0777, true);
    }

    file_put_contents(
        $tempPath,
        implode(PHP_EOL, [
            '# provider,tag,environment,schema,url,api_key',
            'PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY',
            '# this row should be ignored entirely',
            'COMPANIESHOUSE,COMPANY_LOOKUP,TEST,HTTPS,api-sandbox.company-information.service.gov.uk,test-key:',
            'HMRC,VAT_CHECK,TEST,HTTPS,test-api.service.hmrc.gov.uk,client-id:client-secret',
        ]) . PHP_EOL
    );

    $harness->check(OutboundHelper::class, 'ignores hash-prefixed comment lines in api.keys', static function () use ($harness, $tempPath): void {
        $catalog = OutboundHelper::credentialCatalog($tempPath);

        $harness->assertCount(2, $catalog);
        $harness->assertSame('test-key:', $catalog['COMPANIESHOUSE']['COMPANY_LOOKUP']['TEST']['api_key']);
        $harness->assertSame('client-id:client-secret', $catalog['HMRC']['VAT_CHECK']['TEST']['api_key']);
    });

    $harness->check(OutboundHelper::class, 'loads credentials correctly when comments precede the header row', static function () use ($harness, $tempPath): void {
        $credential = OutboundHelper::loadCredential('COMPANIESHOUSE', 'COMPANY_LOOKUP', 'TEST', $tempPath);

        $harness->assertSame('COMPANIESHOUSE', $credential['provider']);
        $harness->assertSame('COMPANY_LOOKUP', $credential['tag']);
        $harness->assertSame('TEST', $credential['environment']);
        $harness->assertSame('HTTPS', $credential['schema']);
    });
});
