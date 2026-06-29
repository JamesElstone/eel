<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCorporationTaxSubmissionService $service): void {
        $harness->check(\eel_accounts\Service\HmrcCorporationTaxSubmissionService::class, 'installer-safe schema creates audit tables', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $harness->assertTrue(InterfaceDB::tableExists('hmrc_ct600_submissions'));
            $harness->assertTrue(InterfaceDB::tableExists('hmrc_submission_events'));
            $harness->assertTrue(InterfaceDB::tableExists('tax_loss_carryforwards'));
            $harness->assertTrue(InterfaceDB::tableExists('tax_loss_movement_history'));
        });

        $harness->check(\eel_accounts\Service\HmrcCorporationTaxSubmissionService::class, 'validation fails cleanly with missing selection', static function () use ($harness, $service): void {
            $result = $service->validatePackage(0, 0, 'TEST');
            $harness->assertSame(false, $result['success']);
        });
    }
);
