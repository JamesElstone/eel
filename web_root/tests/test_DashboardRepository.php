<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(DashboardRepository::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(DashboardRepository::class, 'normalises month and category filters', function () use ($harness): void {
        $repository = new DashboardRepository();

        $harness->assertSame('2026-03-01', $repository->normaliseTransactionMonthFilter('2026-03-01'));
        $harness->assertSame('', $repository->normaliseTransactionMonthFilter('2026-03-15'));
        $harness->assertSame('manual', $repository->normaliseTransactionCategoryFilter('manual'));
        $harness->assertSame('all', $repository->normaliseTransactionCategoryFilter('unexpected'));
    });
});
