<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $ordinary = [
            'ok' => true,
            'state' => 'ready',
            'run_id' => 80,
            'path' => 'ordinary.xhtml',
            'filename' => 'ordinary.xhtml',
            'hash' => str_repeat('a', 64),
            'basis_hash' => str_repeat('b', 64),
            'filing_approval_id' => 90,
        ];

        $harness->check(
            \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService::class,
            'uses ordinary accounts for original periods',
            static function () use ($harness, $ordinary): void {
                $revisedCalls = 0;
                $service = new \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService(
                    static fn(): array => $ordinary,
                    static fn(): string => 'original',
                    static function () use (&$revisedCalls): array {
                        $revisedCalls++;
                        return ['ok' => false];
                    }
                );
                $result = $service->locate(49, 81);
                $harness->assertSame('ordinary.xhtml', (string)$result['path']);
                $harness->assertSame(0, $revisedCalls);
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService::class,
            'uses the shared revised artifact for revised periods while preserving the approved basis',
            static function () use ($harness, $ordinary): void {
                $service = new \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService(
                    static fn(): array => $ordinary,
                    static fn(): string => 'revised',
                    static fn(): array => [
                        'ok' => true,
                        'state' => 'ready',
                        'run_id' => 80,
                        'path' => 'revised.xhtml',
                        'filename' => 'revised.xhtml',
                        'hash' => str_repeat('c', 64),
                    ]
                );
                $result = $service->locate(49, 79);
                $harness->assertSame('revised.xhtml', (string)$result['path']);
                $harness->assertSame(str_repeat('b', 64), (string)$result['basis_hash']);
                $harness->assertSame('shared_revised_accounts', (string)$result['destination']);
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService::class,
            'fails closed when a revised period has no prepared shared artifact',
            static function () use ($harness, $ordinary): void {
                $service = new \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService(
                    static fn(): array => $ordinary,
                    static fn(): string => 'revised',
                    static fn(): array => ['ok' => false, 'state' => 'missing', 'errors' => ['Prepare revised accounts.']]
                );
                $result = $service->locate(49, 80);
                $harness->assertFalse((bool)($result['ok'] ?? true));
                $harness->assertSame('missing', (string)($result['state'] ?? ''));
            }
        );
    }
);
