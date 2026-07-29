<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlArelleLogDownloadService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlArelleLogDownloadService $service
    ): void {
        $harness->check(
            $service::class,
            'rejects unscoped and browser-supplied log identities',
            static function () use ($harness, $service): void {
                $invalidContext = $service->resolve(0, 0, 'accounts', 1);
                $harness->assertFalse((bool)($invalidContext['ok'] ?? true));

                $invalidScope = $service->resolve(49, 79, 'C:\\private\\secret.txt', 1);
                $harness->assertFalse((bool)($invalidScope['ok'] ?? true));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($invalidScope['errors'] ?? [])),
                    'No Arelle diagnostic log'
                ));
            }
        );

        $harness->check(
            IxbrlAction::class,
            'streams only a persisted Arelle log resolved inside the accounting context',
            static function () use ($harness): void {
                $source = (string)file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
                    . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php'
                );
                $harness->assertTrue(str_contains($source, "\$intent === 'download_arelle_log'"));
                $harness->assertTrue(str_contains($source, 'IxbrlArelleLogDownloadService())->resolve('));
                $harness->assertTrue(str_contains($source, "header('Content-Type: text/plain; charset=utf-8')"));
                $harness->assertTrue(str_contains($source, "header('X-Content-Type-Options: nosniff')"));
                $harness->assertTrue(str_contains($source, "header('Cache-Control: private, no-store')"));
                $harness->assertFalse(str_contains($source, "\$request->input('log_path'"));
            }
        );
    }
);
