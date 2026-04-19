<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(SecurityStore::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $tempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    $securityPath = $tempDirectory . DIRECTORY_SEPARATOR . 'security-store.keys';
    $credentialPath = $tempDirectory . DIRECTORY_SEPARATOR . 'security-store-api.keys';

    if (!is_dir($tempDirectory)) {
        mkdir($tempDirectory, 0777, true);
    }

    if (is_file($securityPath)) {
        unlink($securityPath);
    }

    file_put_contents(
        $credentialPath,
        implode(PHP_EOL, [
            '# provider,tag,environment,schema,url,api_key',
            'PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY',
            'COMPANIESHOUSE,COMPANY_LOOKUP,TEST,HTTPS,api-sandbox.company-information.service.gov.uk,test-key:',
        ]) . PHP_EOL
    );

    $harness->check(SecurityStore::class, 'creates a missing security.keys file with a header and generated pepper', static function () use ($harness, $securityPath): void {
        $pepper = SecurityStore::ensureFact('pepper', $securityPath);

        $harness->assertSame(64, strlen($pepper));
        $harness->assertSame($pepper, SecurityStore::loadFact('pepper', $securityPath));

        $contents = file_get_contents($securityPath);

        if ($contents === false) {
            throw new RuntimeException('Failed to read generated security key file.');
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($contents)) ?: [];
        $harness->assertSame('# keys,fact', $lines[0] ?? null);
        $harness->assertTrue(str_contains($contents, '"pepper","' . $pepper . '"'));
    });

    $harness->check(SecurityStore::class, 'reuses an existing fact instead of rotating it', static function () use ($harness, $securityPath): void {
        $first = SecurityStore::ensureFact('pepper', $securityPath);
        $second = SecurityStore::ensureFact('pepper', $securityPath);

        $harness->assertSame($first, $second);
    });

    $harness->check(SecurityStore::class, 'loads outbound credentials from api.keys-compatible csv files', static function () use ($harness, $credentialPath): void {
        $credential = SecurityStore::loadCredential('COMPANIESHOUSE', 'COMPANY_LOOKUP', 'TEST', $credentialPath);

        $harness->assertSame('COMPANIESHOUSE', $credential['provider']);
        $harness->assertSame('COMPANY_LOOKUP', $credential['tag']);
        $harness->assertSame('TEST', $credential['environment']);
        $harness->assertSame('test-key:', $credential['api_key']);
    });
});
