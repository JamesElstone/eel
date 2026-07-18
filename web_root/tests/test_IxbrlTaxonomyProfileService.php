<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlTaxonomyProfileService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlTaxonomyProfileService $service): void {
        $harness->check($service::class, 'exposes the required FRS 105 taxonomy profile and absence text', static function () use ($harness, $service): void {
            $mappings = $service->mappings();
            $harness->assertTrue(count($mappings) > 20);
            $harness->assertSame('The company made no advances or credits to directors.', $service->absenceStatementText('no_director_advances_or_credits'));
            $harness->assertSame('', $service->statementText('unknown'));
        });
    }
);
