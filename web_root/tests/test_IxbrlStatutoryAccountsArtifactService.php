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
            'uses the HMRC accounts artifact independently of the Companies House filing kind',
            static function () use ($harness, $ordinary): void {
                $filingKindCalls = 0;
                $revisedCalls = 0;
                $service = new \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService(
                    static fn(): array => $ordinary,
                    static function () use (&$filingKindCalls): string {
                        $filingKindCalls++;
                        return 'revised';
                    },
                    static function () use (&$revisedCalls): array {
                        $revisedCalls++;
                        return ['ok' => false];
                    }
                );
                $result = $service->locate(49, 79);
                $harness->assertSame('ordinary.xhtml', (string)$result['path']);
                $harness->assertSame(str_repeat('b', 64), (string)$result['basis_hash']);
                $harness->assertSame('hmrc_accounts', (string)$result['destination']);
                $harness->assertSame(0, $filingKindCalls);
                $harness->assertSame(0, $revisedCalls);
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService::class,
            'does not block HMRC when Companies House has no prepared revised artifact',
            static function () use ($harness, $ordinary): void {
                $service = new \eel_accounts\Service\IxbrlStatutoryAccountsArtifactService(
                    static fn(): array => $ordinary,
                    static fn(): string => 'revised',
                    static fn(): array => ['ok' => false, 'state' => 'missing', 'errors' => ['Prepare revised accounts.']]
                );
                $result = $service->locate(49, 80);
                $harness->assertTrue((bool)($result['ok'] ?? false));
                $harness->assertSame('ordinary.xhtml', (string)($result['path'] ?? ''));
                $harness->assertSame('hmrc_accounts', (string)($result['destination'] ?? ''));
            }
        );
    }
);
