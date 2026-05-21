<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    IxbrlFactBuilderService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlFactBuilderService $service): void {
        $harness->check(IxbrlFactBuilderService::class, 'handles missing company and period safely', static function () use ($harness, $service): void {
            $harness->assertSame(null, $service->getLatestRun(0, 0));
            $harness->assertSame([], $service->getFacts(0));
        });

        $harness->check(IxbrlFactBuilderService::class, 'installer-safe schema creates mapping seed rows', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $harness->assertTrue(InterfaceDB::countWhere('ixbrl_fact_mappings', 'fact_key', 'entity_name') > 0);
            $harness->assertTrue(InterfaceDB::countWhere('ixbrl_fact_mappings', 'fact_key', 'net_assets_liabilities') > 0);
        });
    }
);
