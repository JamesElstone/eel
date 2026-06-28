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

    public function authMetadata(int $userId, int $roleId): array
    {
        return [
            'user_id' => $userId,
            'role_id' => $roleId,
        ];
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

if (!class_exists('_service_metadata_testCard', false)) {
    final class _service_metadata_testCard extends CardBaseFramework
    {
        public function services(): array
        {
            return [
                [
                    'key' => 'metadata',
                    'service' => CardRendererOptionalParamTestService::class,
                    'method' => 'filterUploadHistory',
                ],
            ];
        }

        public function render(array $context): string
        {
            return '<p>Service metadata test</p>';
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

    $harness->check(CardRendererFramework::class, 'resolves auth context service params', function () use ($harness, $instance, $services, $resolveCardService): void {
        $result = $resolveCardService->invoke(
            $instance,
            'auth_metadata',
            [
                'key' => 'auth_metadata',
                'service' => CardRendererOptionalParamTestService::class,
                'method' => 'authMetadata',
                'params' => [
                    'userId' => ':auth.user_id',
                    'roleId' => ':auth.role_id',
                ],
            ],
            [
                'auth' => [
                    'user_id' => 123,
                    'role_id' => 2,
                ],
            ],
            $services
        );

        $harness->assertSame('ok', $result['status'] ?? null);
        $harness->assertSame(['user_id' => 123, 'role_id' => 2], $result['data'] ?? null);
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

    $harness->check(CardRendererFramework::class, 'renders card size toggle before developer metadata', function () use ($harness, $instance, $services): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('developer_options', true);
            $enabledHtml = $instance->render('test', 'service_metadata_test', ['page' => ['page_id' => 'test']], $services);

            AppConfigurationStore::set('developer_options', false);
            $disabledHtml = $instance->render('test', 'service_metadata_test', ['page' => ['page_id' => 'test']], $services);

            $togglePosition = strpos($enabledHtml, 'class="card_size"');
            $metadataPosition = strpos($enabledHtml, 'Card: service_metadata_test');

            $harness->assertTrue($togglePosition !== false);
            $harness->assertTrue($metadataPosition !== false);
            $harness->assertTrue($togglePosition < $metadataPosition);
            $harness->assertTrue(str_contains($enabledHtml, '<button class="card-size-toggle" type="button" data-card-size-toggle aria-label="Maximize card" aria-pressed="false">'));
            $harness->assertTrue(str_contains($enabledHtml, 'card-size-icon-maximize'));
            $harness->assertTrue(str_contains($enabledHtml, 'card-size-icon-minimize'));
            $harness->assertTrue(str_contains($disabledHtml, 'data-card-size-toggle'));
            $harness->assertSame(false, str_contains($disabledHtml, 'Card: service_metadata_test'));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });

    $harness->check(CardRendererFramework::class, 'shows service metadata only when developer options are enabled', function () use ($harness, $instance, $services): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('developer_options', true);
            $enabledHtml = $instance->render('test', 'service_metadata_test', ['page' => ['page_id' => 'test']], $services);

            AppConfigurationStore::set('developer_options', false);
            $disabledHtml = $instance->render('test', 'service_metadata_test', ['page' => ['page_id' => 'test']], $services);

            $harness->assertTrue(str_contains($enabledHtml, 'Card: service_metadata_test'));
            $harness->assertSame(1, preg_match('/Card: service_metadata_test \[s:\d+ms, h:\d+ms, r:\d+ms\]/', $enabledHtml));
            $harness->assertTrue(str_contains($enabledHtml, 'Using ' . CardRendererOptionalParamTestService::class));
            $harness->assertSame(false, str_contains($disabledHtml, 'Card: service_metadata_test'));
            $harness->assertSame(false, str_contains($disabledHtml, '[s:'));
            $harness->assertSame(false, str_contains($disabledHtml, 'Using ' . CardRendererOptionalParamTestService::class));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });
});
