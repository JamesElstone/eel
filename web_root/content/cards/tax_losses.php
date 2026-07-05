<?php
declare(strict_types=1);

final class _tax_lossesCard extends CardBaseFramework
{
    public function key(): string { return 'tax_losses'; }
    public function title(): string { return 'Losses'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }
        $rows = [];
        foreach ((array)($workings['losses'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['label'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['loss_brought_forward'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['loss_created'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['loss_utilised'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['loss_carried_forward'] ?? 0)),
            ];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('losses')
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Period', 'Brought forward', 'Created', 'Used', 'Carried forward'], $rows, 'No loss schedule rows were found.');
    }
}
