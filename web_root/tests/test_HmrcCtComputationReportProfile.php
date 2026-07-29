<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtComputationReportProfile::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\HmrcCtComputationReportProfile $service
    ): void {
        $harness->check($service::class, 'fails closed when a mandatory accounts mapping is absent', static function () use ($harness, $service): void {
            $thrown = false;
            try {
                $service->apply([
                    'model' => ['computation' => ['summary' => [
                        'capital_allowances' => 0,
                        'taxable_before_losses' => 0,
                    ]]],
                ], []);
            } catch (RuntimeException $exception) {
                $thrown = str_contains($exception->getMessage(), 'accounting_profit');
            }
            $harness->assertTrue($thrown);
        });

        $harness->check($service::class, 'publishes a stable versioned report profile', static function () use ($harness): void {
            $harness->assertTrue(str_starts_with(
                \eel_accounts\Service\HmrcCtComputationReportProfile::VERSION,
                'hmrc-ct-computations-format-1.1/'
            ));
        });
    }
);
