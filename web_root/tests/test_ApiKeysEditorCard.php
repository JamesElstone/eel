<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'api_keys_editor.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(_api_keys_editorCard::class, 'renders gateway metadata and never renders identity or API key values', function () use ($harness): void {
    $secret = 'API-KEY-MUST-NOT-APPEAR';
    $identity = 'API-IDENTITY-MUST-NOT-APPEAR';
    $reference = 'Réf & "α"';
    $html = (new _api_keys_editorCard())->render(['page' => ['csrf_token' => 'token'], 'services' => ['api_keys_editor' => ['rows' => [['id' => 'row-1', 'provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => 'https://example.test', 'software_reference' => $reference, 'api_identity' => $identity, 'api_key' => $secret]], 'catalog' => [['provider' => 'ACME', 'gateway' => 'REST', 'tag' => 'LOOKUP', 'environment' => 'TEST'], ['provider' => 'COMPANIESHOUSE', 'gateway' => 'XML', 'tag' => 'XML_PRESENTER_CREDENTIALS', 'environment' => 'TEST'], ['provider' => 'COMPANIESHOUSE', 'gateway' => 'XML', 'tag' => 'ACCOUNTS_FILING_PACKAGE_REFERENCE', 'environment' => 'TEST']]]]]);
    $harness->assertTrue(str_contains($html, '<th>Gateway</th>'));
    $harness->assertTrue(str_contains($html, '<th>Software Reference</th>'));
    $harness->assertTrue(str_contains($html, '<div class="api-credential-fields">'));
    $harness->assertTrue(str_contains($html, 'name="credential[gateway]"'));
    $harness->assertTrue(str_contains($html, '<select class="select" name="credential[schema]">'));
    $harness->assertTrue(str_contains($html, '<option value="HTTPS" selected>HTTPS</option><option value="HTTP">HTTP</option>'));
    $harness->assertSame(false, str_contains($html, '<input class="input" name="credential[schema]"'));
    $harness->assertTrue(str_contains($html, '<input class="input" name="credential[url]" type="text"'));
    $harness->assertTrue(str_contains($html, 'name="credential[software_reference]" type="text" value="" autocomplete="off" maxlength="1000"'));
    $harness->assertTrue(str_contains($html, 'Réf &amp; &quot;α&quot;'));
    $harness->assertTrue(str_contains($html, 'data-credential-software-reference="Réf &amp; &quot;α&quot;"'));
    $harness->assertSame(4, substr_count($html, 'data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, '<div class="api-credential-actions">'));
    $harness->assertTrue(str_contains($html, '<button class="button" type="button" data-api-credential-clear="true">Clear</button>'));
    $harness->assertTrue(str_contains($html, '<textarea'));
    $harness->assertTrue(str_contains($html, 'name="credential[api_identity]"'));
    $harness->assertTrue(str_contains($html, 'XML_PRESENTER_CREDENTIALS'));
    $harness->assertSame(false, str_contains($html, 'PREFLIGHT_BINDING_HMAC_KEY'));
    $harness->assertSame(false, str_contains($html, $secret));
    $harness->assertSame(false, str_contains($html, $identity));
});

$harness->check(_api_keys_editorCard::class, 'browser editing prefills clears and reinitialises Software Reference', function () use ($harness): void {
    $script = file_get_contents(APP_JS . 'index.js');
    if (!is_string($script)) { throw new RuntimeException('Unable to read frontend script.'); }

    $harness->assertTrue(str_contains($script, "const softwareReference = editor.querySelector('[name=\"credential[software_reference]\"]');"));
    $harness->assertTrue(str_contains($script, "softwareReference.value = String(button.dataset.credentialSoftwareReference || '');"));
    $harness->assertTrue(str_contains($script, "softwareReference.value = '';"));
    $harness->assertTrue(str_contains($script, "const fields = ['provider', 'gateway', 'tag', 'environment'];"));
    $harness->assertTrue(substr_count($script, 'initialiseApiCredentialEditors(replacement);') >= 2);
    $harness->assertTrue(str_contains($script, 'initialiseApiCredentialEditors(document);'));
});

$harness->check(_api_keys_editorCard::class, 'offers file repair only for the unreadable-file error in Developer Options', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);
    if (!is_string($original)) { throw new RuntimeException('Unable to read fixture config.'); }

    $context = [
        'page' => ['csrf_token' => 'token', 'page_cards' => ['api_keys_editor']],
        'service_errors' => ['api_keys_editor' => ['message' => 'The API key file is not readable.']],
    ];

    try {
        AppConfigurationStore::set('developer_options', true);
        $enabledHtml = (new _api_keys_editorCard())->render($context);
        AppConfigurationStore::set('developer_options', false);
        $disabledHtml = (new _api_keys_editorCard())->render($context);

        $harness->assertTrue(str_contains($enabledHtml, 'name="api_keys_editor_operation" value="repair_file"'));
        $harness->assertTrue(str_contains($enabledHtml, '>Fix error</button>'));
        $harness->assertSame(false, str_contains($disabledHtml, '>Fix error</button>'));
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});
