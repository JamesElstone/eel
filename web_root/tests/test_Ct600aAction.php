<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(Ct600aAction::class, static function (
    GeneratedServiceClassTestHarness $harness,
    Ct600aAction $action
): void {
    $harness->check(Ct600aAction::class, 'refreshes the director-loan and year-end facts when a declaration request completes', static function () use ($harness, $action): void {
        $result = $action->handle(
            new RequestFramework(
                [],
                ['card_action' => 'Ct600a', 'intent' => 'unrelated'],
                ['REQUEST_METHOD' => 'POST'],
                [],
                [],
                null
            ),
            createTestPageServiceFramework()
        );

        foreach (['director.loan.state', 'year.end.state', 'year.end.checklist'] as $fact) {
            $harness->assertTrue(in_array($fact, $result->changedFacts(), true));
        }
    });
});
