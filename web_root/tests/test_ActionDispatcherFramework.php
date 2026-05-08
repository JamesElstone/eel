<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(ActionDispatcherFramework::class);

if (!class_exists('NotAnAction', false)) {
    final class NotAnAction
    {
    }
}

$pageServices = new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'));

$harness->check(ActionDispatcherFramework::class, 'runs page action before card action', function () use ($harness, $pageServices): void {
    $request = new RequestFramework(
        [],
        [
            'action' => 'save-page',
            'card_action' => 'Test',
        ],
        ['REQUEST_METHOD' => 'POST'],
        [],
        []
    );

    $result = (new ActionDispatcherFramework())->dispatch(
        $request,
        $pageServices,
        static fn(): ActionResultFramework => ActionResultFramework::success(['page.action'])
    );

    $harness->assertSame(['page.action'], $result->changedFacts());
});

$harness->check(ActionDispatcherFramework::class, 'returns no action result for empty requests', function () use ($harness, $pageServices): void {
    $result = (new ActionDispatcherFramework())->dispatch(
        new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []),
        $pageServices,
        static fn(): ActionResultFramework => ActionResultFramework::success(['unexpected'])
    );

    $harness->assertTrue($result->isSuccess());
    $harness->assertSame([], $result->changedFacts());
});

$harness->check(ActionDispatcherFramework::class, 'reloads page for pagination requests', function () use ($harness, $pageServices): void {
    $request = new RequestFramework([], ['_pagination' => '1'], ['REQUEST_METHOD' => 'POST'], [], []);

    $result = (new ActionDispatcherFramework())->dispatch(
        $request,
        $pageServices,
        static fn(): ActionResultFramework => ActionResultFramework::success(['unexpected'])
    );

    $harness->assertSame(['page.reload'], $result->changedFacts());
});

$harness->check(ActionDispatcherFramework::class, 'dispatches valid shared card actions', function () use ($harness, $pageServices): void {
    $request = new RequestFramework([], ['card_action' => 'Test'], ['REQUEST_METHOD' => 'POST'], [], []);

    $result = (new ActionDispatcherFramework())->dispatch(
        $request,
        $pageServices,
        static fn(): ActionResultFramework => ActionResultFramework::success(['unexpected'])
    );

    $harness->assertSame(['dump.stack', 'dump.classes', 'dump.context'], $result->changedFacts());
});

$harness->check(ActionDispatcherFramework::class, 'rejects invalid shared card action names', function () use ($harness, $pageServices): void {
    try {
        (new ActionDispatcherFramework())->dispatch(
            new RequestFramework([], ['card_action' => 'bad-action'], ['REQUEST_METHOD' => 'POST'], [], []),
            $pageServices,
            static fn(): ActionResultFramework => ActionResultFramework::none()
        );
    } catch (InvalidArgumentException) {
        $harness->assertTrue(true);
        return;
    }

    throw new RuntimeException('Invalid card action name was accepted.');
});

$harness->check(ActionDispatcherFramework::class, 'rejects missing shared card action classes', function () use ($harness, $pageServices): void {
    try {
        (new ActionDispatcherFramework())->dispatch(
            new RequestFramework([], ['card_action' => 'Missing'], ['REQUEST_METHOD' => 'POST'], [], []),
            $pageServices,
            static fn(): ActionResultFramework => ActionResultFramework::none()
        );
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'Unable to resolve'));
        return;
    }

    throw new RuntimeException('Missing card action class was accepted.');
});

$harness->check(ActionDispatcherFramework::class, 'rejects shared card classes without the action interface', function () use ($harness, $pageServices): void {
    try {
        (new ActionDispatcherFramework())->dispatch(
            new RequestFramework([], ['card_action' => 'NotAn'], ['REQUEST_METHOD' => 'POST'], [], []),
            $pageServices,
            static fn(): ActionResultFramework => ActionResultFramework::none()
        );
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'does not resolve'));
        return;
    }

    throw new RuntimeException('Non-action card class was accepted.');
});
