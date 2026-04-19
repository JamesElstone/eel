<?php
declare(strict_types=1);

final class _dashboard_action_queueCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'dashboard_action_queue';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $actionQueue = (array)(($context['page'] ?? [])['action_queue'] ?? []);
        $itemsHtml = '';

        foreach ($actionQueue as $item) {
            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
            </div>';
        }

        if ($itemsHtml === '') {
            $itemsHtml = '<div class="list-item">
                <strong>No queued actions</strong>
                <span>The dashboard has nothing urgent to surface right now.</span>
            </div>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Action Queue</h2>
            </div>
            <div class="card-body">
                <div class="list">' . $itemsHtml . '</div>
            </div>
        </div>';
    }
}
