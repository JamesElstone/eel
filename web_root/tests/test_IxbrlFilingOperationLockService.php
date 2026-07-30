<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlFilingOperationLockService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlFilingOperationLockService $service
    ): void {
        $harness->check($service::class, 'rejects an invalid filing scope', static function () use ($harness, $service): void {
            $thrown = false;
            try {
                $service->execute(0, 1, static fn(): bool => true);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            $harness->assertTrue($thrown);
        });

        $harness->check($service::class, 'executes and releases a scoped operation lock', static function () use ($harness, $service): void {
            $companyId = 987654321;
            $periodId = 123456789;
            $result = $service->execute($companyId, $periodId, static fn(): string => 'completed');
            $harness->assertSame('completed', $result);

            $second = $service->execute($companyId, $periodId, static fn(): string => 'released');
            $harness->assertSame('released', $second);
            $path = rtrim(test_tmp_directory(), '\\/')
                . DIRECTORY_SEPARATOR . '.ixbrl-locks'
                . DIRECTORY_SEPARATOR . 'company-' . $companyId . '-period-' . $periodId . '.lock';
            if (is_file($path)) {
                unlink($path);
            }
        });
    }
);
