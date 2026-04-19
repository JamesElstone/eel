<?php
declare(strict_types=1);

final class _trial_balance_workspaceCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'trial_balance_workspace';
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
        return '<section class="eel-card-fragment" data-card="trial-balance-workspace" style="grid-column: 1 / -1;">
            <div id="trial-balance-app">
                <div class="helper">Loading Trial Balance...</div>
            </div>
        </section>';
    }
}
