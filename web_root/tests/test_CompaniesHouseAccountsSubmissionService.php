<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseAccountsSubmissionService $service): void {
        $harness->check($service::class, 'returns an empty context for an invalid selection', static function () use ($harness, $service): void {
            $context = $service->fetchContext(0, 0);
            $harness->assertFalse((bool)($context['can_prepare'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)($context['blockers'] ?? [])), 'valid company'));
        });
    }
);
