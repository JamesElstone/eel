<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(ActionProgressFramework::class, 'stays inactive until progress is reported and emits ordered NDJSON', function () use ($harness): void {
    $lines = [];
    $progress = new ActionProgressFramework(
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
        false
    );

    $harness->assertSame(false, $progress->isStarted());
    $progress->report('Loading transactions');
    $progress->report('Matching records', 40);
    $harness->assertSame(true, $progress->isStarted());
    $harness->assertSame(false, $progress->isTerminal());

    $firstProgress = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    $harness->assertSame('progress', $firstProgress['type']);
    $harness->assertSame(1, $firstProgress['sequence']);
    $harness->assertTrue((bool)preg_match('/^\[\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}\] - Loading transactions$/', $firstProgress['message']));

    $secondProgress = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
    $harness->assertSame('progress', $secondProgress['type']);
    $harness->assertSame(2, $secondProgress['sequence']);
    $harness->assertSame(40, $secondProgress['percent']);
    $harness->assertTrue((bool)preg_match('/^\[\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}\] - Matching records$/', $secondProgress['message']));

    $response = ResponseFramework::json(['success' => true, 'cards' => ['card-id' => '<p>Updated</p>']]);
    $harness->assertSame(true, $progress->complete($response));
    $harness->assertSame(true, $progress->isTerminal());
    $harness->assertSame([
        'type' => 'complete',
        'payload' => [
            'success' => true,
            'cards' => ['card-id' => '<p>Updated</p>'],
        ],
    ], json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR));
});

$harness->check(ActionProgressFramework::class, 'does not consume an ordinary response before streaming starts', function () use ($harness): void {
    $progress = new ActionProgressFramework(static function (string $line): void {}, false);

    $harness->assertSame(false, $progress->complete(ResponseFramework::json(['success' => true])));
    $harness->assertSame(false, $progress->isStarted());
    $harness->assertSame(false, $progress->isTerminal());
});

$harness->check(ActionProgressFramework::class, 'rejects invalid percentages and progress after completion', function () use ($harness): void {
    $progress = new ActionProgressFramework(static function (string $line): void {}, false);
    $invalidPercentThrown = false;

    try {
        $progress->report('Invalid', 101);
    } catch (InvalidArgumentException) {
        $invalidPercentThrown = true;
    }

    $harness->assertSame(true, $invalidPercentThrown);
    $progress->report('Valid', 100);
    $progress->complete(ResponseFramework::json(['success' => true]));

    $terminalThrown = false;
    try {
        $progress->report('Too late');
    } catch (LogicException) {
        $terminalThrown = true;
    }

    $harness->assertSame(true, $terminalThrown);
});

$harness->check(ActionProgressFramework::class, 'turns an active exception into one terminal error event', function () use ($harness): void {
    $lines = [];
    $progress = new ActionProgressFramework(
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
        false
    );

    $progress->report('Starting');
    $harness->assertSame(true, ActionProgressFramework::failActive('Public failure'));
    $harness->assertSame(true, $progress->isTerminal());
    $harness->assertSame([
        'type' => 'error',
        'message' => 'Public failure',
    ], json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR));
    $harness->assertSame(false, ActionProgressFramework::failActive('Duplicate failure'));
});

$harness->check(ActionProgressFramework::class, 'is connected to the response and exception pipelines', function () use ($harness): void {
    $index = (string)file_get_contents(APP_ROOT . 'index.php');
    $bootstrap = (string)file_get_contents(APP_CLASSES . 'bootstrap.php');

    $harness->assertTrue(str_contains($index, '$pageServices->actionProgress()->complete($response)'));
    $harness->assertTrue(str_contains($bootstrap, 'ActionProgressFramework::failActive($message)'));
});
