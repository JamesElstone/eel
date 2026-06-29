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

        $harness->check(IxbrlFactBuilderService::class, 'schema includes filing export validation metadata', static function () use ($harness, $service): void {
            $service->ensureSchema();
            foreach (['export_type', 'taxonomy_profile', 'validation_status', 'validation_errors_json', 'external_validator', 'external_validation_status', 'external_validation_errors_json', 'external_validation_warnings_json', 'external_validation_log_path', 'external_validated_at'] as $column) {
                $harness->assertTrue(InterfaceDB::columnExists('ixbrl_generation_runs', $column));
            }
        });

        $harness->check(IxbrlFactBuilderService::class, 'repairs long-term creditor and equity mapping aliases', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $creditors = InterfaceDB::fetchOne('SELECT taxonomy_concept, source_key FROM ixbrl_fact_mappings WHERE fact_key = :fact_key', ['fact_key' => 'creditors_after_one_year']);
            $equity = InterfaceDB::fetchOne('SELECT source_key FROM ixbrl_fact_mappings WHERE fact_key = :fact_key', ['fact_key' => 'equity']);

            $harness->assertSame('uk-gaap:CreditorsDueAfterMoreThanOneYear', (string)($creditors['taxonomy_concept'] ?? ''));
            $harness->assertSame('creditors_after_more_than_one_year', (string)($creditors['source_key'] ?? ''));
            $harness->assertSame('equity_capital_reserves', (string)($equity['source_key'] ?? ''));
        });
    }
);
