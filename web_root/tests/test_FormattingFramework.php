<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(FormattingFramework::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(FormattingFramework::class, 'formats money values with two decimal places', static function () use ($harness): void {
        $harness->assertSame('1,234.50', FormattingFramework::money(1234.5));
        $harness->assertSame('-42.00', FormattingFramework::money('-42'));
    });

    $harness->check(FormattingFramework::class, 'uses a fallback for nullable money values', static function () use ($harness): void {
        $harness->assertSame('-', FormattingFramework::nullableMoney(null));
        $harness->assertSame('n/a', FormattingFramework::nullableMoney('', 'n/a'));
        $harness->assertSame('12.00', FormattingFramework::nullableMoney(12));
    });

    $harness->check(FormattingFramework::class, 'builds nominal labels from code and name parts', static function () use ($harness): void {
        $harness->assertSame('4000 - Sales', FormattingFramework::nominalLabel(['code' => '4000', 'name' => 'Sales']));
        $harness->assertSame('4000', FormattingFramework::nominalLabel(['code' => '4000', 'name' => '']));
        $harness->assertSame('Sales', FormattingFramework::nominalLabel(['code' => '', 'name' => 'Sales']));
        $harness->assertSame('', FormattingFramework::nominalLabel(null));
    });

    $harness->check(FormattingFramework::class, 'maps nominal tax treatment labels', static function () use ($harness): void {
        $harness->assertSame('Disallowable', FormattingFramework::nominalTaxTreatmentLabel('disallowable'));
        $harness->assertSame('Capital', FormattingFramework::nominalTaxTreatmentLabel('capital'));
        $harness->assertSame('Allowable', FormattingFramework::nominalTaxTreatmentLabel('allowable'));
    });
});
