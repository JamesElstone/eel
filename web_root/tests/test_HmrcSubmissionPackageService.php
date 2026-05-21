<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    HmrcSubmissionPackageService::class,
    static function (GeneratedServiceClassTestHarness $harness, HmrcSubmissionPackageService $service): void {
        $harness->check(HmrcSubmissionPackageService::class, 'missing computations iXBRL blocks submission', static function () use ($harness, $service): void {
            $result = $service->locateComputationsIxbrl(0, 0);
            $harness->assertSame(false, $result['ok']);
            $harness->assertTrue(count($result['errors']) > 0);
        });
    }
);
