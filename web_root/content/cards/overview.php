<?php
declare(strict_types=1);

final class _overviewCard implements WebCardInterface
{
    public function key(): string
    {
        return 'overview';
    }

    public function invalidationFacts(): array
    {
        return ['dashboard.metrics'];
    }

    public function render(array $context): string
    {
        $statsHtml = '';

        foreach ((array)($context['stats'] ?? []) as $stat) {
            $statsHtml .= '<article class="card stat-card">
                <div class="eyebrow">' . FrameworkHelper::escape((string)($stat['label'] ?? '')) . '</div>
                <div class="stat-value">' . FrameworkHelper::escape((string)($stat['value'] ?? '')) . '</div>
                <div class="stat-foot">' . FrameworkHelper::escape((string)($stat['foot'] ?? '')) . '</div>
            </article>';
        }

        return '<div class="stack">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Architecture snapshot</h2>
                </div>
                <div class="card-body">
                    <div class="grid-stats">' . $statsHtml . '</div>
                </div>
            </div>
        </div>';
    }
}
