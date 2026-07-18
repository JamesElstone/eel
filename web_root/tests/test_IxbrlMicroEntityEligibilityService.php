<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlMicroEntityEligibilityService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlMicroEntityEligibilityService $service): void {
        $harness->check($service::class, 'rejects an accounting period with an inverted date range', static function () use ($harness, $service): void {
            $thrown = false;
            try {
                $service->evaluate('2026-12-31', '2026-01-01', 0, 0, 0);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            $harness->assertTrue($thrown);
        });
    }
);
