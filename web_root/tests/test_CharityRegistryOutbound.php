<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$h = new GeneratedServiceClassTestHarness();
$h->run(\eel_accounts\Outbound\CharityRegistryOutbound::class, static function (GeneratedServiceClassTestHarness $h): void {
    $credentialLoader = static function (string $provider, string $gateway, string $tag, string $environment): array {
        if ($gateway !== 'REST' || $tag !== 'CHARITY_LOOKUP' || $environment !== 'LIVE') {
            throw new RuntimeException('Unexpected credential identity.');
        }
        return ['api_key' => 'secret-' . strtolower($provider)];
    };

    $captured = [];
    $transport = static function (array $request) use (&$captured): array {
        $captured[] = $request;
        return ['status_code' => 200, 'body' => '[]'];
    };

    \eel_accounts\Outbound\CharityRegistryOutbound::charityCommission(
        ['base_url' => 'https://example.test', 'path' => '/charity/123'],
        $credentialLoader,
        $transport
    );
    $h->assertSame('CHARITYCOMMISSION', (string)$captured[0]['provider']);
    $h->assertSame('secret-charitycommission', (string)$captured[0]['headers']['Ocp-Apim-Subscription-Key']);
    $h->assertSame('application/json', (string)$captured[0]['headers']['Accept']);
    $h->assertSame('/charity/123', (string)$captured[0]['path']);

    \eel_accounts\Outbound\CharityRegistryOutbound::oscr(
        ['query' => ['charitynumber' => 'SC012345']],
        $credentialLoader,
        $transport
    );
    $h->assertSame('OSCR', (string)$captured[1]['provider']);
    $h->assertSame('secret-oscr', (string)$captured[1]['headers']['x-functions-key']);
    $h->assertSame('SC012345', (string)$captured[1]['query']['charitynumber']);

    \eel_accounts\Outbound\CharityRegistryOutbound::ccni(
        ['url' => 'https://example.test/ccni'],
        $transport
    );
    $h->assertSame('none', (string)$captured[2]['auth']);
    $h->assertSame(false, (bool)$captured[2]['follow_location']);
    $h->assertSame('text/html', (string)$captured[2]['headers']['Accept']);

    try {
        \eel_accounts\Outbound\CharityRegistryOutbound::charityCommission(
            [],
            static fn(string $provider, string $gateway, string $tag, string $environment): array => ['api_key' => ''],
            static fn(array $request): array => throw new RuntimeException('Transport must not run.')
        );
        $h->assertTrue(false);
    } catch (RuntimeException $exception) {
        $h->assertTrue(str_contains($exception->getMessage(), 'API key is not configured'));
        $h->assertSame(false, str_contains($exception->getMessage(), 'secret'));
    }
});
