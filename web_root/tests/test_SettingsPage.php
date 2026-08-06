<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_PAGES . 'settings.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(_settings::class, 'includes the API credential editor exactly once', function () use ($harness): void {
    $cards = (new _settings())->cards();
    $harness->assertSame(1, count(array_filter(
        $cards,
        static fn(string $card): bool => $card === 'api_keys_editor'
    )));
});
