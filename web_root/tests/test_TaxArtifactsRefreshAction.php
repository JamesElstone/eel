<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TaxArtifactsRefreshAction::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(TaxArtifactsRefreshAction::class, 'defines every tax artefact refresh stage', static function () use ($harness): void {
        $reflection = new ReflectionClass(TaxArtifactsRefreshAction::class);
        $stages = $reflection->getConstant('STAGES');

        $harness->assertSame([
            'hmrc_ct_artifacts_refresh',
            'refresh_frc_taxonomy',
            'refresh_companies_house_accounts_schemas',
            'refresh_hmrc_vat_rates',
            'refresh_hmrc_vat_thresholds',
        ], array_column($stages, 'intent'));
    });
});
