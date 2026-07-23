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
    $harness->assertTrue(str_contains($html, '<div class="api-credential-metadata">'));
    $harness->assertTrue(str_contains($html, 'name="credential[gateway]"'));
    $harness->assertTrue(str_contains($html, '<select class="select" name="credential[schema]">'));
    $harness->assertTrue(str_contains($html, '<option value="HTTPS" selected>HTTPS</option><option value="HTTP">HTTP</option>'));
    $harness->assertSame(false, str_contains($html, '<input class="input" name="credential[schema]"'));
    $harness->assertSame(4, substr_count($html, 'data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, '<div class="api-credential-actions">'));
    $harness->assertTrue(str_contains($html, '<button class="button" type="button" data-api-credential-clear="true">Clear</button>'));
    $harness->assertTrue(str_contains($html, '<textarea'));
    $harness->assertTrue(str_contains($html, 'name="credential[api_identity]"'));
    $harness->assertSame(false, str_contains($html, $secret));
    $harness->assertSame(false, str_contains($html, $identity));
});
