<?php
/**
 * eelKit Framework
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

if (!class_exists('_refreshing_testCard', false)) {
    final class _refreshing_testCard extends CardBaseFramework
    {
        public function render(array $context): string
        {
            return '<p>Refresh me</p>';
        }

        public function refreshIntervalMs(array $context): ?int
        {
            return 1000;
        }
    }
}

if (!class_exists('_non_refreshing_testCard', false)) {
    final class _non_refreshing_testCard extends CardBaseFramework
    {
        public function render(array $context): string
        {
            return '<p>Leave me</p>';
        }
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

    $harness->check(CardRendererFramework::class, 'adds card refresh attributes when requested', function () use ($harness, $instance, $services): void {
        $html = $instance->render('test', 'refreshing_test', ['page' => ['page_id' => 'test']], $services);

        $harness->assertTrue(str_contains($html, 'data-card-refresh-ms="5000"'));
        $harness->assertTrue(str_contains($html, 'data-card-refresh-fact="refreshing.test"'));
    });

    $harness->check(CardRendererFramework::class, 'omits card refresh attributes by default', function () use ($harness, $instance, $services): void {
        $html = $instance->render('test', 'non_refreshing_test', ['page' => ['page_id' => 'test']], $services);

        $harness->assertSame(false, str_contains($html, 'data-card-refresh-ms='));
        $harness->assertSame(false, str_contains($html, 'data-card-refresh-fact='));
    });
});
