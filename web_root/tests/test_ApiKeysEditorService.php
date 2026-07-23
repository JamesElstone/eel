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
$harness->check(ApiKeysEditorService::class, 'lists only metadata and writes distinct gateway credentials', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents($path, "# preserved\nPROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\nACME,REST,LOOKUP,TEST,HTTPS,rest.example,\"identity-never-rendered\",\"secret-never-rendered\"\n");
    try {
        $service = new ApiKeysEditorService($path, new ApiCredentialCatalogService([ApiKeysEditorTestCatalogProvider::class]));
        $listing = $service->listing();
        $harness->assertSame(false, str_contains(json_encode($listing), 'secret-never-rendered'));
        $harness->assertSame(false, str_contains(json_encode($listing), 'identity-never-rendered'));
        $result = $service->save('', ['provider' => 'ACME', 'gateway' => 'XML', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => 'xml.example', 'api_identity' => "  Jöhn, \"identity\"\nnext  ", 'api_key' => "  sécret, \"key\"\nnext  "]);
        $harness->assertSame(true, $result['changed']);
        $contents = (string)file_get_contents($path);
        $harness->assertTrue(str_contains($contents, 'ACME,XML,LOOKUP,TEST'));
        $harness->assertTrue(str_contains($contents, '"  Jöhn, ""identity""' . "\n" . 'next  "'));
        $harness->assertTrue(str_contains($contents, '"  sécret, ""key""' . "\n" . 'next  "'));
        $harness->assertTrue(str_contains($contents, 'secret-never-rendered'));
        $harness->assertCount(1, glob($path . '.backup.*') ?: []);
    } finally { foreach (glob($path . '*') ?: [] as $file) { @unlink($file); } }
});

$harness->check(ApiKeysEditorService::class, 'rejects invalid UTF-8 and NUL secret values', function () use ($harness, $tmp): void {
    $path = $tmp . DIRECTORY_SEPARATOR . 'api-keys-editor-invalid-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents($path, "PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY\n");
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
