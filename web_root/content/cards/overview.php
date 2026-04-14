<?php
declare(strict_types=1);

final class _overviewCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'overview';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['dashboard.metrics'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $statsHtml = '';
        $page = (array)($context['page'] ?? []);

        foreach ((array)($page['stats'] ?? []) as $stat) {
            $statsHtml .= '<article class="card stat-card">
                <div class="eyebrow">' . HelperFramework::escape((string)($stat['label'] ?? '')) . '</div>
                <div class="stat-value">' . HelperFramework::escape((string)($stat['value'] ?? '')) . '</div>
                <div class="stat-foot">' . HelperFramework::escape((string)($stat['foot'] ?? '')) . '</div>
            </article>';
        }

        return '<div class="stack">
            <div class="card">
                <div class="card-header card-header-has-eyebrow">
                    <h2 class="card-title">Architecture snapshot</h2>
                    <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
                </div>
                <div class="card-body">
                    <div class="grid-stats">' . $statsHtml . '</div>
                </div>
            </div>
        </div>';
    }
}

