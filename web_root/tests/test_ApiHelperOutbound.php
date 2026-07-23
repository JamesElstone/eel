<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(ApiHelperOutbound::class);
$harness->check(ApiHelperOutbound::class, 'requires every selector for automatic credential selection', function () use ($harness): void {
    try {
        ApiHelperOutbound::request(['provider' => 'ACME', 'tag' => 'LOOKUP', 'environment' => 'TEST']);
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'requires provider, gateway, tag, and environment'));
        return;
    }
    throw new RuntimeException('Gateway-less automatic credential lookup did not throw.');
});

$harness->check(ApiHelperOutbound::class, 'uses separate identity and key values for OAuth and basic credentials', function () use ($harness): void {
    $credential = ['api_identity' => "client: id\n", 'api_key' => "secret: key\n"];
    $harness->assertSame(["client: id\n", "secret: key\n"], ApiHelperOutbound::resolveClientCredentials([], $credential));
    $harness->assertSame(["client: id\n", "secret: key\n"], ApiHelperOutbound::resolveBasicCredentials([], $credential));
    try {
        ApiHelperOutbound::resolveClientCredentials([], ['api_key' => 'client:secret']);
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'API_IDENTITY'));
        return;
    }
    throw new RuntimeException('Colon-packed OAuth credentials did not throw.');
});
