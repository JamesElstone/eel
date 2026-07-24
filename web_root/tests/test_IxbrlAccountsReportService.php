<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAccountsReportService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlAccountsReportService $service): void {
        $harness->check($service::class, 'rejects an invalid company and accounting period', static function () use ($harness, $service): void {
            $thrown = false;
            try {
                $service->build(0, 0);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            $harness->assertTrue($thrown);
        });

        $harness->check($service::class, 'declares an explicit report-basis version', static function () use ($harness, $service): void {
            $harness->assertTrue(str_starts_with($service::BASIS_VERSION, 'ixbrl-accounts-report-v'));
        });
    }
);
