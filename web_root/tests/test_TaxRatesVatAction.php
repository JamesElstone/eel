<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TaxRatesVatAction::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(TaxRatesVatAction::class, 'reports changed and unchanged sourced refreshes', static function () use ($harness): void {
        foreach ([
            [['success' => true, 'refreshed_count' => 10, 'unchanged' => false, 'warnings' => []], '10 sourced rule(s) updated'],
            [['success' => true, 'refreshed_count' => 0, 'unchanged' => true, 'warnings' => []], 'already up to date'],
        ] as [$payload, $expected]) {
            $action = new TaxRatesVatAction(static fn(): array => $payload);
            $result = $action->handle(vatReferenceActionRequest('refresh_hmrc_vat_rates'), createTestPageServiceFramework());

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertTrue(in_array('vat.rate.rules', $result->changedFacts(), true));
            $harness->assertTrue(str_contains((string)($result->flashMessages()[0]['message'] ?? ''), $expected));
        }
    });

    $harness->check(TaxRatesVatAction::class, 'retains the current dataset when refresh reports failure', static function () use ($harness): void {
        $action = new TaxRatesVatAction(static fn(): array => [
            'success' => false,
            'errors' => ['Parser rejected the GOV.UK response.'],
            'warnings' => [],
        ]);
        $result = $action->handle(vatReferenceActionRequest('refresh_hmrc_vat_rates'), createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertTrue(str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'Parser rejected'));
    });
});

function vatReferenceActionRequest(string $intent): RequestFramework
{
    return new RequestFramework([], ['intent' => $intent], ['REQUEST_METHOD' => 'POST'], [], []);
}
