<?php
declare(strict_types=1);

final class _companies_setup_healthCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_setup_health';
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
        $items = (array)(($context['page'] ?? [])['company_setup_health_items'] ?? []);
        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                <span class="status-indicator"><span class="status-square ' . (!empty($item['ok']) ? 'ok' : 'bad') . '"></span>' . (!empty($item['ok']) ? 'OK' : 'Needs attention') . '</span>
                <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
            </div>';
        }

        return '<div class="card">
            <div class="card-header"><h2 class="card-title">Company Setup Health</h2></div>
            <div class="card-body">
                <div class="list">' . $itemsHtml . '</div>
            </div>
        </div>';
    }
}
