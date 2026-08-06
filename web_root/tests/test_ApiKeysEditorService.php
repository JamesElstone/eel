<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class ApiKeysEditorTestCatalogProvider implements ApiCredentialCatalogProviderInterface
{
    public function credentialCatalog(): array
    {
        return [
            ['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST'],
            ['provider' => 'ACME', 'gateway' => 'XML', 'tag' => 'LOOKUP', 'environment' => 'TEST'],
        ];
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(ApiKeysEditorService::class);
$tmp = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tmp)) { mkdir($tmp, 0777, true); }
$harness->check(ApiKeysEditorService::class, 'creates a missing credential file with the required header', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-repair-' . bin2hex(random_bytes(8)) . '.csv';
    try {
        $result = (new ApiKeysEditorService($path))->repairFile();
        $harness->assertSame(true, $result['created']);
        $harness->assertSame("PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\n", file_get_contents($path));
        if (DIRECTORY_SEPARATOR !== '\\') { $harness->assertSame(0600, fileperms($path) & 0777); }
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});

$harness->check(ApiKeysEditorService::class, 'repairs access without replacing an existing credential file', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-permissions-' . bin2hex(random_bytes(8)) . '.csv';
    $contents = "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\n";
    file_put_contents($path, $contents);
    if (DIRECTORY_SEPARATOR !== '\\') { chmod($path, 0644); }
    try {
        $result = (new ApiKeysEditorService($path))->repairFile();
        $harness->assertSame(false, $result['created']);
        $harness->assertSame($contents, file_get_contents($path));
        if (DIRECTORY_SEPARATOR !== '\\') { $harness->assertSame(0600, fileperms($path) & 0777); }
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});

$harness->check(ApiKeysEditorService::class, 'upgrades a legacy file without losing comments credentials or backup guarantees', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-' . bin2hex(random_bytes(8)) . '.csv';
    $original = "# preserved\nPROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,rest.example,\"identity-never-rendered\",\"secret-never-rendered\"\nACME,XML,LOOKUP,TEST,HTTPS,xml.example,\"xml-identity-never-rendered\",\"xml-secret-never-rendered\"\n";
    file_put_contents($path, $original);
    try {
        $service = new ApiKeysEditorService($path, new ApiCredentialCatalogService([ApiKeysEditorTestCatalogProvider::class]));
        $listing = $service->listing();
        $harness->assertSame('', $listing['rows'][0]['software_reference'] ?? null);
        $harness->assertSame(false, str_contains(json_encode($listing), 'secret-never-rendered'));
        $harness->assertSame(false, str_contains(json_encode($listing), 'identity-never-rendered'));
        $result = $service->save('record-2', ['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => 'rest.example', 'software_reference' => '']);
        $harness->assertSame(true, $result['changed']);
        $contents = (string)file_get_contents($path);
        $harness->assertTrue(str_starts_with($contents, "# preserved\nPROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\n"));
        $harness->assertTrue(str_contains($contents, 'ACME,REST,LOOKUP,TEST,HTTPS,rest.example,,"identity-never-rendered","secret-never-rendered"'));
        $harness->assertTrue(str_contains($contents, 'secret-never-rendered'));
        $harness->assertTrue(str_contains($contents, 'identity-never-rendered'));
        $harness->assertTrue(str_contains($contents, 'xml-secret-never-rendered'));
        $harness->assertTrue(strpos($contents, 'ACME,REST,LOOKUP,TEST') < strpos($contents, 'ACME,XML,LOOKUP,TEST'));
        $backups = glob($path . '.backup.*') ?: [];
        $harness->assertCount(1, $backups);
        $harness->assertSame($original, file_get_contents($backups[0]));
        if (DIRECTORY_SEPARATOR !== '\\') {
            $harness->assertSame(0600, fileperms($path) & 0777);
            $harness->assertSame(0600, fileperms($backups[0]) & 0777);
        }
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});

$harness->check(ApiKeysEditorService::class, 'writes lists and clears canonical Software References', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-canonical-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,rest.example,,\"rest identity\",\"rest secret\"\n");
    try {
        $service = new ApiKeysEditorService($path, new ApiCredentialCatalogService([ApiKeysEditorTestCatalogProvider::class]));
        $service->save('', ['provider' => 'ACME', 'gateway' => 'XML', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => '', 'software_reference' => '  Päckage, "α"  ', 'api_identity' => "  Jöhn, \"identity\"\nnext  ", 'api_key' => "  sécret, \"key\"\nnext  "]);
        $contents = (string)file_get_contents($path);
        $harness->assertTrue(str_contains($contents, 'ACME,XML,LOOKUP,TEST,HTTPS,,"Päckage, ""α"""'));
        $harness->assertTrue(str_contains($contents, '"  Jöhn, ""identity""' . "\n" . 'next  "'));
        $harness->assertTrue(str_contains($contents, '"  sécret, ""key""' . "\n" . 'next  "'));

        $listing = $service->listing();
        $harness->assertSame('Päckage, "α"', $listing['rows'][1]['software_reference'] ?? null);
        $harness->assertSame(false, str_contains(json_encode($listing), 'sécret'));
        $service->save('record-2', ['provider' => 'ACME', 'gateway' => 'XML', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => '', 'software_reference' => '']);
        $cleared = $service->listing();
        $harness->assertSame('', $cleared['rows'][1]['software_reference'] ?? null);
        $harness->assertTrue(str_contains((string)file_get_contents($path), 'sécret'));
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});

$harness->check(ApiKeysEditorService::class, 'validates Software Reference submissions as bounded Unicode strings', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-reference-validation-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\n");
    try {
        $service = new ApiKeysEditorService($path, new ApiCredentialCatalogService([ApiKeysEditorTestCatalogProvider::class]));
        $base = ['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => '', 'api_key' => 'key'];
        foreach ([["not-a-string"], "bad\0ref", "bad\rref", "bad\nref", "\xC3\x28", str_repeat('界', 1001)] as $invalid) {
            try {
                $service->save('', $base + ['software_reference' => $invalid]);
                throw new RuntimeException('Invalid Software Reference did not throw.');
            } catch (RuntimeException $exception) { $harness->assertTrue(str_contains($exception->getMessage(), 'Software Reference')); }
        }
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});

$harness->check(ApiKeysEditorService::class, 'rejects invalid UTF-8 and NUL secret values', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-invalid-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,SOFTWARE_REFERENCE,API_IDENTITY,API_KEY\n");
    try {
        $service = new ApiKeysEditorService($path, new ApiCredentialCatalogService([ApiKeysEditorTestCatalogProvider::class]));
        foreach (["bad\0key", "\xC3\x28"] as $invalid) {
            try {
                $service->save('', ['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => 'rest.example', 'api_key' => $invalid]);
                throw new RuntimeException('Invalid secret value did not throw.');
            } catch (RuntimeException $exception) { $harness->assertTrue(str_contains($exception->getMessage(), 'API key')); }
        }
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});
