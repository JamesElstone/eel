<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TaxThresholdsVatAction::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(TaxThresholdsVatAction::class, 'reports imported rules and source warnings', static function () use ($harness): void {
        $action = new TaxThresholdsVatAction(static fn(): array => [
            'success' => true,
            'refreshed_count' => 98,
            'unchanged' => false,
            'warnings' => ['Published acquisition gap retained.'],
        ]);
        $result = $action->handle(vatThresholdActionRequest('refresh_hmrc_vat_thresholds'), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertTrue(in_array('vat.threshold.rules', $result->changedFacts(), true));
        $harness->assertTrue(str_contains((string)($result->flashMessages()[0]['message'] ?? ''), '98 sourced rule(s) updated'));
        $harness->assertSame('warning', (string)($result->flashMessages()[1]['type'] ?? ''));
    });

    $harness->check(TaxThresholdsVatAction::class, 'reports unchanged and failed source refreshes', static function () use ($harness): void {
        $unchanged = (new TaxThresholdsVatAction(static fn(): array => [
            'success' => true,
            'refreshed_count' => 0,
            'unchanged' => true,
            'warnings' => [],
        ]))->handle(vatThresholdActionRequest('refresh_hmrc_vat_thresholds'), createTestPageServiceFramework());
        $failed = (new TaxThresholdsVatAction(static fn(): array => [
            'success' => false,
            'errors' => ['Threshold import failed.'],
            'warnings' => [],
        ]))->handle(vatThresholdActionRequest('refresh_hmrc_vat_thresholds'), createTestPageServiceFramework());

        $harness->assertTrue(str_contains((string)($unchanged->flashMessages()[0]['message'] ?? ''), 'already up to date'));
        $harness->assertSame(false, $failed->isSuccess());
        $harness->assertTrue(str_contains((string)($failed->flashMessages()[0]['message'] ?? ''), 'Threshold import failed'));
    });
});

function vatThresholdActionRequest(string $intent): RequestFramework
{
    return new RequestFramework([], ['intent' => $intent], ['REQUEST_METHOD' => 'POST'], [], []);
}
