<?php
declare(strict_types=1);

final class _activityCard implements WebCardInterface
{
    public function key(): string
    {
        return 'activity';
    }

    public function invalidationFacts(): array
    {
        return ['dashboard.feed'];
    }

    public function render(array $context): string
    {
        $itemsHtml = '';

        foreach ((array)($context['activity'] ?? []) as $item) {
            $itemsHtml .= '<div class="list-item">
                <strong>' . FrameworkHelper::escape((string)($item['title'] ?? '')) . '</strong>
                <span>' . FrameworkHelper::escape((string)($item['detail'] ?? '')) . '</span>
            </div>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">What this example proves</h2>
            </div>
            <div class="card-body">
                <div class="list">' . $itemsHtml . '</div>
            </div>
        </div>';
    }
}
