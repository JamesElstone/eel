<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlCompanyIdentityService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlCompanyIdentityService $service): void {
        $harness->check($service::class, 'reports the supported identity fields when they are absent', static function () use ($harness, $service): void {
            $errors = $service->errors($service->normalise([]));
            $harness->assertTrue($errors !== []);
            $harness->assertTrue(str_contains(implode(' ', $errors), 'legal name'));
        });
    }
);
