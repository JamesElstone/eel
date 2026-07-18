<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlRevisedAccountsArtifactService $service): void {
        $harness->check($service::class, 'validates revision declarations before locating an artifact', static function () use ($harness, $service): void {
            $result = $service->prepare(0, 0, []);
            $harness->assertFalse((bool)($result['success'] ?? true));
            $harness->assertTrue((array)($result['errors'] ?? []) !== []);
        });
    }
);
