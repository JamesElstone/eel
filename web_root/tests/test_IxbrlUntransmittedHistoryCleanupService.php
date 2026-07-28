<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService $service): void {
        $harness->check($service::class, 'retains only a complete current approval during history cleanup', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'protectedCurrentApproval');

            $harness->assertSame([0, 0], $method->invoke($service, [
                'state' => 'stale',
                'approval' => ['id' => 18, 'evidence_bundle_id' => 21],
            ]));
            $harness->assertSame([18, 21], $method->invoke($service, [
                'state' => 'current',
                'approval' => ['id' => 18, 'evidence_bundle_id' => 21],
            ]));
            $harness->assertThrows(
                static fn(): mixed => $method->invoke($service, ['state' => 'current', 'approval' => ['id' => 18]]),
                RuntimeException::class
            );
        });
    }
);
