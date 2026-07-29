<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlArtifactFilenameService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\IxbrlArtifactFilenameService $service
    ): void {
        $hash = '7f7e6007e08223fc' . str_repeat('a', 48);
        $h->check($service::class, 'builds the three canonical destination filenames', static function () use ($h, $service, $hash): void {
            $h->assertSame(
                'accounts_14337285_79_91_113_hmrc-computation_20220905_20230904_7f7e6007e08223fc.xhtml',
                $service->build('14337285', 79, 91, 113, $service::DESTINATION_HMRC_COMPUTATION, '20220905', '20230904', strtoupper($hash))
            );
            $h->assertSame(
                'accounts_SC123456_79_12_16_hmrc-accounting_20220905_20230930_7f7e6007e08223fc.xhtml',
                $service->build('sc-123456', 79, 12, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20220905', '20230930', $hash)
            );
            $h->assertSame(
                'accounts_14337285_79_12_16_companies-house_20220905_20230930_7f7e6007e08223fc.xhtml',
                $service->build('14337285', 79, 12, 16, $service::DESTINATION_COMPANIES_HOUSE, '20220905', '20230930', $hash)
            );
        });

        $h->check($service::class, 'rejects invalid identity destination dates and hashes', static function () use ($h, $service, $hash): void {
            foreach ([
                ['', 79, 12, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20220905', '20230930', $hash],
                ['14337285', 0, 12, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20220905', '20230930', $hash],
                ['14337285', 79, 0, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20220905', '20230930', $hash],
                ['14337285', 79, 12, 0, $service::DESTINATION_HMRC_ACCOUNTING, '20220905', '20230930', $hash],
                ['14337285', 79, 12, 16, 'hmrc-tax', '20220905', '20230930', $hash],
                ['14337285', 79, 12, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20230229', '20230930', $hash],
                ['14337285', 79, 12, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20230930', '20220905', $hash],
                ['14337285', 79, 12, 16, $service::DESTINATION_HMRC_ACCOUNTING, '20220905', '20230930', 'abc'],
            ] as $arguments) {
                $thrown = false;
                try {
                    $service->build(...$arguments);
                } catch (\InvalidArgumentException) {
                    $thrown = true;
                }
                $h->assertTrue($thrown);
            }
        });
    }
);
