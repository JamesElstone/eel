<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(_dividend_capacityCard::class, static function (GeneratedServiceClassTestHarness $harness, _dividend_capacityCard $capacityCard): void {
    $harness->check(_dividend_capacityCard::class, 'capacity review and declaration cards share one capacity service call', static function () use ($harness, $capacityCard): void {
        $capacityService = (array)($capacityCard->services()[0] ?? []);
        $reserveService = (array)((new _reserve_reviewCard())->services()[0] ?? []);
        $declareService = (array)((new _dividend_declareCard())->services()[0] ?? []);

        $harness->assertSame($capacityService, $reserveService);
        $harness->assertSame($capacityService, $declareService);
        $harness->assertSame('fetchCapacityContext', $capacityService['method'] ?? null);
    });

    $harness->check(_dividend_capacityCard::class, 'other page cards use focused database readers', static function () use ($harness): void {
        $voucher = (array)((new _dividend_vouchersCard())->services()[0] ?? []);
        $history = (array)((new _dividend_historyCard())->services()[0] ?? []);
        $declareServices = (new _dividend_declareCard())->services();
        $candidates = (array)($declareServices[1] ?? []);

        $harness->assertSame('listDividendVouchers', $voucher['method'] ?? null);
        $harness->assertSame('listDividends', $history['method'] ?? null);
        $harness->assertSame('listDividendReconciliationCandidates', $candidates['method'] ?? null);
    });
});
