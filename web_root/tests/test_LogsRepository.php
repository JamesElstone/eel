<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(LogsRepository::class, static function (GeneratedServiceClassTestHarness $harness, LogsRepository $repository): void {
    $harness->check(LogsRepository::class, 'returns an array for recent logon history', static function () use ($harness, $repository): void {
        $rows = $repository->fetchRecentLogonHistory(5);

        $harness->assertTrue(is_array($rows));
    });

    $harness->check(LogsRepository::class, 'returns an array for recent transaction category audit', static function () use ($harness, $repository): void {
        $rows = $repository->fetchRecentTransactionCategoryAudit(5);

        $harness->assertTrue(is_array($rows));
    });

    $harness->check(LogsRepository::class, 'returns an array for recent year end audit', static function () use ($harness, $repository): void {
        $rows = $repository->fetchRecentYearEndAudit(5);

        $harness->assertTrue(is_array($rows));
    });
});
