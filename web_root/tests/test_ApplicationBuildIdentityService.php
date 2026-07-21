<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\ApplicationBuildIdentityService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\ApplicationBuildIdentityService $service
    ): void {
        $h->check($service::class, 'returns stable producer and calculation build identities', static function () use ($h, $service): void {
            $identity = $service->snapshot();
            $h->assertTrue(trim((string)($identity['name'] ?? '')) !== '');
            $h->assertTrue(trim((string)($identity['version'] ?? '')) !== '');
            $h->assertSame(
                \eel_accounts\Service\TaxAuditBasisService::BASIS_VERSION,
                (string)($identity['calculation_build'] ?? '')
            );
        });
    }
);
