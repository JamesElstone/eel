<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\OwnershipPartyService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\OwnershipPartyService $service): void {
        $harness->check(get_class($service), 'requires a selected company', static function () use ($harness, $service): void {
            $summary = $service->fetchSummary(0);
            $harness->assertSame(false, (bool)($summary['available'] ?? true));
            $harness->assertSame([], $service->effectiveParties(0, '2026-07-19'));
        });
    }
);
