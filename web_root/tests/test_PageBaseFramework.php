<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class PageBaseAuthContextTestPage extends PageBaseFramework
{
    public function __construct(private readonly int $userId, private readonly int $roleId)
    {
    }

    public function id(): string { return 'auth_context_test'; }
    public function title(): string { return 'Auth Context Test'; }
    public function subtitle(): string { return ''; }
    public function services(): array { return []; }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        return [
            'page' => [
                'page_id' => $this->id(),
                'page_cards' => [],
            ],
        ];
    }

    protected function currentUserId(): int
    {
        return $this->userId;
    }

    protected function currentUserRoleId(int $userId): int
    {
        return $this->roleId;
    }
}

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

$harness->check(PageBaseFramework::class, 'defaults ajax pending blur to none', function () use ($harness): void {
    $page = new class extends PageBaseFramework {
        public function id(): string { return 'default_ajax_pending_blur_test'; }
        public function title(): string { return 'Default AJAX Pending Blur Test'; }
        public function subtitle(): string { return ''; }
        public function services(): array { return []; }
    };

    $harness->assertSame('none', $page->ajaxPendingBlurScope());
});

$harness->check(PageBaseFramework::class, 'injects authenticated user metadata into request context', function () use ($harness): void {
    $page = new PageBaseAuthContextTestPage(123, 2);
    $services = new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'));
    $request = new RequestFramework(['page' => $page->id()], [], ['REQUEST_METHOD' => 'GET'], [], []);

    $context = $page->buildContextForRequest($request, $services, ActionResultFramework::none());

    $harness->assertSame(123, $context['auth']['user_id'] ?? null);
    $harness->assertSame(2, $context['auth']['role_id'] ?? null);
});

$harness->check(PageBaseFramework::class, 'uses zero auth metadata for unauthenticated request context', function () use ($harness): void {
    $page = new PageBaseAuthContextTestPage(0, 2);
    $services = new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'));
    $request = new RequestFramework(['page' => $page->id()], [], ['REQUEST_METHOD' => 'GET'], [], []);

    $context = $page->buildContextForRequest($request, $services, ActionResultFramework::none());

    $harness->assertSame(0, $context['auth']['user_id'] ?? null);
    $harness->assertSame(0, $context['auth']['role_id'] ?? null);
});
