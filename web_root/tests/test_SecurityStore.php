<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class SecurityStoreTestCredentialCatalogProvider implements ApiCredentialCatalogProviderInterface
{
    public function credentialCatalog(): array
    {
        return [
            ['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST'],
            ['provider' => 'ACME', 'gateway' => 'XML', 'tag' => 'LOOKUP', 'environment' => 'TEST'],
            ['provider' => 'ACME', 'gateway' => 'CUSTOM', 'tag' => 'LOOKUP', 'environment' => 'LIVE'],
        ];
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(SecurityStore::class);
$testTempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($testTempDirectory)) { mkdir($testTempDirectory, 0777, true); }
$previousProviders = AppConfigurationStore::get('api_credentials.catalog_providers', []);
AppConfigurationStore::set('api_credentials.catalog_providers', [SecurityStoreTestCredentialCatalogProvider::class]);

try {
    $harness->check(SecurityStore::class, 'selects canonical gateway credentials with software references', function () use ($harness, $testTempDirectory): void {
        $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-api-' . bin2hex(random_bytes(8)) . '.csv';
        file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,rest.example.test,rest-ref,\"rest user\",\"rest-key\"\nACME,XML,LOOKUP,TEST,HTTPS,xml.example.test,xml-ref,\"xml user\",\"xml-key\"\nACME,CUSTOM,LOOKUP,LIVE,HTTPS,custom.example.test,,\"custom user\",\"custom-key\"\n");
        try {
            $harness->assertSame('rest-key', SecurityStore::loadCredential('ACME', 'REST', 'LOOKUP', 'TEST', $path)['api_key']);
            $harness->assertSame('xml-key', SecurityStore::loadCredential('ACME', 'XML', 'LOOKUP', 'TEST', $path)['api_key']);
            $harness->assertSame('custom-key', SecurityStore::loadCredential('ACME', 'CUSTOM', 'LOOKUP', 'LIVE', $path)['api_key']);
            $harness->assertSame('xml user', SecurityStore::loadCredential('ACME', 'XML', 'LOOKUP', 'TEST', $path)['api_identity']);
            $harness->assertSame('xml-ref', SecurityStore::loadCredential('ACME', 'XML', 'LOOKUP', 'TEST', $path)['software_reference']);
            $harness->assertSame('', SecurityStore::loadCredential('ACME', 'CUSTOM', 'LOOKUP', 'LIVE', $path)['software_reference']);
        } finally { @unlink($path); }
    });

    $harness->check(SecurityStore::class, 'accepts credentials with an empty URL', function () use ($harness, $testTempDirectory): void {
        $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-empty-url-' . bin2hex(random_bytes(8)) . '.csv';
        file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\nACME,XML,LOOKUP,TEST,HTTPS,,\"\",\"xml-key\"\n");
        try {
            $credential = SecurityStore::loadCredential('ACME', 'XML', 'LOOKUP', 'TEST', $path);
            $harness->assertSame('', $credential['url']);
            $harness->assertSame('', $credential['software_reference']);
            $harness->assertSame('xml-key', $credential['api_key']);
        } finally { @unlink($path); }
    });

    $harness->check(SecurityStore::class, 'rejects old malformed duplicate and unconfigured gateway rows', function () use ($harness, $testTempDirectory): void {
        foreach ([
            "PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\nACME,LOOKUP,TEST,HTTPS,x,key\n",
            "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,x,key\n",
            "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\nACME,SOAP,LOOKUP,TEST,HTTPS,x,identity,key\n",
            "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,x,one,identity,key\nACME,REST,LOOKUP,TEST,HTTPS,y,two,identity,key\n",
        ] as $contents) {
            $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-invalid-' . bin2hex(random_bytes(8)) . '.csv';
            file_put_contents($path, $contents);
            try {
                SecurityStore::credentialCatalog($path);
                throw new RuntimeException('Invalid credential document did not throw.');
            } catch (RuntimeException $exception) {
                $harness->assertTrue($exception->getMessage() !== '');
            } finally { @unlink($path); }
        }
    });

    $harness->check(SecurityStore::class, 'describes both accepted headers and row widths in format errors', function () use ($harness, $testTempDirectory): void {
        foreach ([
            "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\n" => 'legacy PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY',
            "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,x,ref,identity\n" => 'eight columns for legacy header',
        ] as $contents => $expected) {
            $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-format-' . bin2hex(random_bytes(8)) . '.csv';
            file_put_contents($path, $contents);
            try {
                SecurityStore::credentialCatalog($path);
                throw new RuntimeException('Invalid credential format did not throw.');
            } catch (RuntimeException $exception) {
                $harness->assertTrue(str_contains($exception->getMessage(), $expected));
                $harness->assertTrue(str_contains($exception->getMessage(), 'SOFTWARE_REFERENCE'));
            } finally { @unlink($path); }
        }
    });

    $harness->check(SecurityStore::class, 'preserves quoted UTF-8 identities and keys including line breaks', function () use ($harness, $testTempDirectory): void {
        $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-utf8-' . bin2hex(random_bytes(8)) . '.csv';
        $identity = "  Jöhn, \"Identity\"\nsecond line  ";
        $key = "  päss, \"Key\"\nsecond line  ";
        file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,x,\"  Référence, \"\"α\"\"  \",\"  Jöhn, \"\"Identity\"\"\nsecond line  \",\"  päss, \"\"Key\"\"\nsecond line  \"\n");
        try {
            $credential = SecurityStore::loadCredential('ACME', 'REST', 'LOOKUP', 'TEST', $path);
            $harness->assertSame('Référence, "α"', $credential['software_reference']);
            $harness->assertSame($identity, $credential['api_identity']);
            $harness->assertSame($key, $credential['api_key']);
        } finally { @unlink($path); }
    });

    $harness->check(SecurityStore::class, 'validates optional Software Reference as single-line Unicode text', function () use ($harness, $testTempDirectory): void {
        $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-software-reference-' . bin2hex(random_bytes(8)) . '.csv';
        $write = static function (string $reference) use ($path): void {
            $handle = fopen($path, 'wb');
            if ($handle === false) { throw new RuntimeException('Unable to create test credential file.'); }
            try {
                fputcsv($handle, ApiCredentialFileFormat::CANONICAL_HEADER, ',', '"', '');
                fputcsv($handle, ['ACME', 'REST', 'LOOKUP', 'TEST', 'HTTPS', 'x', $reference, 'identity', 'key'], ',', '"', '');
            } finally { fclose($handle); }
        };

        try {
            $maximum = str_repeat('界', 1000);
            $write($maximum);
            $harness->assertSame($maximum, SecurityStore::loadCredential('ACME', 'REST', 'LOOKUP', 'TEST', $path)['software_reference']);

            foreach (["bad\0ref", "bad\rref", "bad\nref", "\xC3\x28", str_repeat('界', 1001)] as $invalid) {
                $write($invalid);
                try {
                    SecurityStore::credentialCatalog($path);
                    throw new RuntimeException('Invalid Software Reference did not throw.');
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains($exception->getMessage(), 'Software Reference'));
                }
            }
        } finally { @unlink($path); }
    });
} finally {
    AppConfigurationStore::set('api_credentials.catalog_providers', $previousProviders);
}
