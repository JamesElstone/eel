<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\ParticipatorLoanService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ParticipatorLoanService $service): void {
        $harness->check(get_class($service), 'has no control nominals without a company', static function () use ($harness, $service): void {
            $controls = $service->controlNominalIds(0);
            $harness->assertSame(0, (int)$controls['asset']);
            $harness->assertSame(0, (int)$controls['liability']);
            $harness->assertSame([], $controls['all']);
        });
    }
);
