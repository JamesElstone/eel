<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxFilingScopeService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CorporationTaxFilingScopeService $service): void {
        $h->check($service::class, 'defines every unsupported CT600 supplementary page with official guidance', static function () use ($h, $service): void {
            $definitions = $service->definitions();
            $h->assertSame(['ct600b','ct600c','ct600d','ct600e','ct600f','ct600g','ct600h','ct600i','ct600j','ct600k','ct600l','ct600m','ct600n','ct600p'], array_keys($definitions));
            foreach ($definitions as $definition) {
                $h->assertTrue(str_starts_with((string)$definition['url'], 'https://www.gov.uk/'));
                $h->assertTrue(str_starts_with((string)$definition['page'], 'CT600'));
            }
        });

        $h->check($service::class, 'fails closed without an accounting context', static function () use ($h, $service): void {
            $status = $service->fetch(0, 0);
            $h->assertSame(false, (bool)$status['available']);
            $h->assertSame(false, (bool)$status['complete']);
        });
    }
);
