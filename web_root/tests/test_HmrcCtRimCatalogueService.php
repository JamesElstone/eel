<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimCatalogueService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimCatalogueService $service): void {
        $harness->check($service::class, 'uses a project-scoped cache directory', static function () use ($harness, $service): void {
            $directory = $service->cacheDirectory();
            $normalised = str_replace('\\', '/', $directory);
            $harness->assertTrue(str_contains($normalised, '/third_party/hmrc/'));
            $harness->assertTrue(str_ends_with($normalised, '/ct600-rim'));
        });
        $harness->check($service::class, 'normalises exact HMRC live identities without promoting future packages', static function () use ($harness, $service): void {
            $v3 = $service->effectiveLifecycle([
                'form_version' => 'V3',
                'artifact_version' => 'V1.994',
                'hmrc_status' => 'published',
                'live_from' => null,
            ]);
            $harness->assertSame('live', $v3['hmrc_status']);
            $harness->assertSame('2026-04-07 00:00:00', $v3['live_from']);
            $future = $service->effectiveLifecycle([
                'form_version' => 'V3',
                'artifact_version' => 'V1.995',
                'hmrc_status' => 'published',
                'live_from' => null,
            ]);
            $harness->assertSame('published', $future['hmrc_status']);
            $harness->assertSame(null, $future['live_from']);
        });
    }
);
