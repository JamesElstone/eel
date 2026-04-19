<?php
declare(strict_types=1);

final class _expenses_workspaceCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'expenses_workspace';
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
        return '<section style="grid-column: 1 / -1;">
            <div id="expenses-app">
                <div class="helper">Loading expense register...</div>
            </div>
        </section>';
    }
}
