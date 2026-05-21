<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    HmrcCorporationTaxSubmissionService::class,
    static function (GeneratedServiceClassTestHarness $harness, HmrcCorporationTaxSubmissionService $service): void {
        $harness->check(HmrcCorporationTaxSubmissionService::class, 'installer-safe schema creates audit tables', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $harness->assertTrue(InterfaceDB::tableExists('hmrc_ct600_submissions'));
            $harness->assertTrue(InterfaceDB::tableExists('hmrc_submission_events'));
            $harness->assertTrue(InterfaceDB::tableExists('tax_loss_pools'));
        });

        $harness->check(HmrcCorporationTaxSubmissionService::class, 'validation fails cleanly with missing selection', static function () use ($harness, $service): void {
            $result = $service->validatePackage(0, 0, 'TEST');
            $harness->assertSame(false, $result['success']);
        });
    }
);
