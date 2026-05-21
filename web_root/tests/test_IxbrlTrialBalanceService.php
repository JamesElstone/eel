<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    IxbrlTrialBalanceService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlTrialBalanceService $service): void {
        $harness->check(IxbrlTrialBalanceService::class, 'returns an empty trial balance for missing selections', static function () use ($harness, $service): void {
            $harness->assertSame([], $service->getTrialBalance(0, 0));
        });

        $harness->check(IxbrlTrialBalanceService::class, 'reports invalid selections as balanced with zero totals', static function () use ($harness, $service): void {
            $totals = $service->getTotals(0, 0);
            $harness->assertSame(0.0, $totals['total_debit']);
            $harness->assertSame(0.0, $totals['total_credit']);
            $harness->assertTrue($service->isBalanced(0, 0));
        });
    }
);
