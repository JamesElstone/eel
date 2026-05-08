<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class CardRendererOptionalParamTestService
{
    public function filterUploadHistory(string $filter = 'all'): array
    {
        return ['filter' => $filter];
    }

    public function requireUploadHistoryFilter(string $filter): array
    {
        return ['filter' => $filter];
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CardRendererFramework::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof CardRendererFramework) {
        $harness->skip('Card renderer did not instantiate.');
    }

    $services = new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'));

    $resolveCardService = new ReflectionMethod(CardRendererFramework::class, 'resolveCardService');
    $resolveCardService->setAccessible(true);

    $harness->check(CardRendererFramework::class, 'omits missing optional context service params', function () use ($harness, $instance, $services, $resolveCardService): void {
        $result = $resolveCardService->invoke(
            $instance,
            'uploaded_filtered',
            [
                'key' => 'uploaded_filtered',
                'service' => CardRendererOptionalParamTestService::class,
                'method' => 'filterUploadHistory',
                'params' => ['filter' => ':uploads.filter'],
            ],
            [],
            $services
        );

        $harness->assertSame('ok', $result['status'] ?? null);
        $harness->assertSame(['filter' => 'all'], $result['data'] ?? null);
    });

    $harness->check(CardRendererFramework::class, 'keeps missing required context service params as errors', function () use ($harness, $instance, $services, $resolveCardService): void {
        $result = $resolveCardService->invoke(
            $instance,
            'uploaded_filtered',
            [
                'key' => 'uploaded_filtered',
                'service' => CardRendererOptionalParamTestService::class,
                'method' => 'requireUploadHistoryFilter',
                'params' => ['filter' => ':uploads.filter'],
            ],
            [],
            $services
        );

        $harness->assertSame('error', $result['status'] ?? null);
        $harness->assertSame('missing_param', $result['error']['type'] ?? null);
    });
});
