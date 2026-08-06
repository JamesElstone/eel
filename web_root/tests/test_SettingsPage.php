<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_PAGES . 'settings.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(_settings::class, 'includes the API credential editor', function () use ($harness): void {
    $harness->assertTrue(in_array('api_keys_editor', (new _settings())->cards(), true));
});
