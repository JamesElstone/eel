<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600GenerationArtifactCleanupService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\Ct600GenerationArtifactCleanupService $service
    ): void {
        $h->check($service::class, 'rejects an invalid cleanup scope without changing artifacts', static function () use ($h, $service): void {
            $inspection = $service->inspectMissingArtifacts(0, 0);
            $h->assertFalse((bool)($inspection['success'] ?? true));
            $h->assertSame([], (array)($inspection['deletable_artifact_ids'] ?? ['unexpected']));

            $result = $service->removeMissingArtifacts(0, 0);

            $h->assertFalse((bool)($result['success'] ?? true));
            $h->assertSame(0, (int)($result['deleted_count'] ?? -1));
            $h->assertSame('Select a valid company and accounting period.', (string)(($result['errors'] ?? [])[0] ?? ''));
        });
    }
);
