<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'api_keys_editor.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(_api_keys_editorCard::class, 'renders gateway metadata and never renders identity or API key values', function () use ($harness): void {
    $secret = 'API-KEY-MUST-NOT-APPEAR';
    $identity = 'API-IDENTITY-MUST-NOT-APPEAR';
    $html = (new _api_keys_editorCard())->render(['page' => ['csrf_token' => 'token'], 'services' => ['api_keys_editor' => ['rows' => [['id' => 'row-1', 'provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => 'https://example.test']], 'catalog' => [['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST']]]]]);
    $harness->assertTrue(str_contains($html, '<th>Gateway</th>'));
    $harness->assertTrue(str_contains($html, 'name="credential[gateway]"'));
    $harness->assertTrue(str_contains($html, '<textarea'));
    $harness->assertTrue(str_contains($html, 'name="credential[api_identity]"'));
    $harness->assertSame(false, str_contains($html, $secret));
    $harness->assertSame(false, str_contains($html, $identity));
});
