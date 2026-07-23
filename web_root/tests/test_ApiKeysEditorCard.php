<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_api_keys_editorCard::class, static function (GeneratedServiceClassTestHarness $harness, _api_keys_editorCard $card): void {
    $harness->check(_api_keys_editorCard::class, 'renders metadata and write-only secret fields', static function () use ($harness, $card): void {
        $secret = 'API-KEY-MUST-NOT-APPEAR';
        $html = $card->render([
            'page' => ['csrf_token' => 'token'],
            'services' => [
                'api_keys_editor' => [
                    'rows' => [[
                        'id' => 'row-1', 'provider' => 'COMPANIESHOUSE', 'tag' => 'COMPANY_LOOKUP',
                        'environment' => 'TEST', 'schema' => 'HTTPS', 'url' => 'https://example.invalid',
                        'api_key' => $secret,
                    ]],
                ],
            ],
        ]);
        $harness->assertSame(true, str_contains($html, 'COMPANY_LOOKUP'));
        $harness->assertSame(true, str_contains($html, 'Set/replace API key'));
        $harness->assertSame(true, str_contains($html, 'Companies House XML TEST credentials'));
        $harness->assertSame(false, str_contains($html, $secret));
    });
});
