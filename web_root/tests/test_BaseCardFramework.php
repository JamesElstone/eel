<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class _sample_widgetCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'sample_widget';
    }

    public function services(): array
    {
        return [];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return '';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context', 'settings.api'];
    }
}

final class _sample_helper_listCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'sample_helper_list';
    }

    public function helper(array $context): string|array
    {
        return ['First line', 'Second line'];
    }

    public function services(): array
    {
        return [];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return '';
    }
}

final class _sample_helper_htmlCard extends CardBaseFramework
{
    public function helper(array $context): string|array
    {
        return HelperFramework::rawHtml('<strong>Trusted</strong>');
    }

    public function render(array $context): string
    {
        return '';
    }
}

final class _sample_default_errorCard extends CardBaseFramework
{
    public function render(array $context): string
    {
        return '';
    }
}

final class _sample_paginationCard extends CardBaseFramework
{
    public function render(array $context): string
    {
        return '';
    }

    public function exposedPaginationPage(array $context): int
    {
        return $this->paginationPage($context);
    }

    public function exposedPaginationControls(array $context, array $pagination): string
    {
        return $this->paginationControls($context, $pagination, 'Rows');
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->check(CardBaseFramework::class, 'derives the card key from the card class name', function () use ($harness): void {
    $card = new _sample_widgetCard();

    $harness->assertSame('sample_widget', $card->key());
});

$harness->check(CardBaseFramework::class, 'derives a default invalidation fact from the card class name', function () use ($harness): void {
    $card = new _sample_widgetCard();

    $harness->assertSame(
        ['sample.widget', 'page.context', 'settings.api'],
        $card->invalidationFacts()
    );
});

$harness->check(CardBaseFramework::class, 'allows helper content to be returned as an array', function () use ($harness): void {
    $card = new _sample_helper_listCard();

    $harness->assertSame(['First line', 'Second line'], $card->helper([]));
});

$harness->check(CardBaseFramework::class, 'allows helper content to return trusted html markup wrapper', function () use ($harness): void {
    $card = new _sample_helper_htmlCard();

    $harness->assertSame(['__html' => '<strong>Trusted</strong>'], $card->helper([]));
});

$harness->check(CardBaseFramework::class, 'renders a default service error summary when a card does not override the handler', function () use ($harness): void {
    $card = new _sample_default_errorCard();

    $harness->assertSame(
        '[demo] error: Demo',
        $card->handleError('demo', ['type' => 'error', 'message' => 'Demo'], [])
    );
});

$harness->check(CardBaseFramework::class, 'stores pagination state with a card-prefixed page key', function () use ($harness): void {
    $card = new _sample_paginationCard();
    $request = new RequestFramework(
        ['sample_pagination_page' => '3'],
        [],
        ['REQUEST_METHOD' => 'GET'],
        [],
        []
    );

    $context = $card->handle(
        $request,
        new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp')),
        ['page' => ['page_id' => 'test']],
        ActionResultFramework::none()
    );

    $harness->assertSame(3, (int)($context['page']['sample_pagination_page'] ?? 0));
    $harness->assertSame(3, $card->exposedPaginationPage($context));
});

$harness->check(CardBaseFramework::class, 'renders shared pagination controls with range wording', function () use ($harness): void {
    $card = new _sample_paginationCard();
    $pagination = HelperFramework::paginateArray(range(1, 12), 2, 5);
    $html = $card->exposedPaginationControls(['page' => ['page_id' => 'test']], $pagination);

    $harness->assertTrue(str_contains($html, 'Rows 6-10 of 12'));
    $harness->assertTrue(str_contains($html, 'name="sample_pagination_page" value="1"'));
    $harness->assertTrue(str_contains($html, 'name="sample_pagination_page" value="3"'));
});
