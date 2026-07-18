<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlFilingArtifactService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlFilingArtifactService $service): void {
        $harness->check($service::class, 'reports a missing artifact for an invalid selection', static function () use ($harness, $service): void {
            $result = $service->locate(0, 0);
            $harness->assertFalse((bool)($result['ok'] ?? true));
            $harness->assertSame('missing', (string)($result['state'] ?? ''));
        });
    }
);
