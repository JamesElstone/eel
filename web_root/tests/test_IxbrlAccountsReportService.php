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
            $harness->assertSame('ixbrl-accounts-report-v3', $service::BASIS_VERSION);
        });

        $harness->check($service::class, 'freezes the selected director id with the officer-name snapshot', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'disclosureBasis');
            $method->setAccessible(true);
            $basis = $method->invoke($service, [
                'accounts_approval_date' => '2026-07-24',
                'approving_director_id' => 17,
                'approving_director_name' => 'James Elstone',
                'updated_at' => '2026-07-24 12:00:00',
            ]);

            $harness->assertSame(17, (int)($basis['approving_director_id'] ?? 0));
            $harness->assertSame('James Elstone', (string)($basis['approving_director_name'] ?? ''));
            $harness->assertFalse(array_key_exists('updated_at', $basis));
        });
    }
);
