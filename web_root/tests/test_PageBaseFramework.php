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
$harness->check(PageBaseFramework::class, 'loads as an abstract base class', function () use ($harness): void {
    $reflection = new ReflectionClass(PageBaseFramework::class);
    $harness->assertTrue($reflection->isAbstract());
});

$harness->check(PageBaseFramework::class, 'provides empty default cards list', function () use ($harness): void {
    $page = new class extends PageBaseFramework {
        public function id(): string { return 'default_cards_test'; }
        public function title(): string { return 'Default Cards Test'; }
        public function subtitle(): string { return ''; }
        public function services(): array { return []; }
    };

    $harness->assertSame([], $page->cards());
});
