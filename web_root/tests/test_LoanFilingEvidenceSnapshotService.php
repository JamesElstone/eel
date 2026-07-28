<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\LoanFilingEvidenceSnapshotService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\LoanFilingEvidenceSnapshotService $service): void {
        $h->check($service::class, 'refuses any live capture outside the Year End lock transaction', static function () use ($h, $service): void {
            $threw = false;
            try {
                $service->captureForLock(1, 1);
            } catch (\RuntimeException $exception) {
                $threw = str_contains($exception->getMessage(), 'Year End lock transaction');
            }
            $h->assertTrue($threw);
        });
    }
);
