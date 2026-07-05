<?php
declare(strict_types=1);

final class _tax_warningsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_warnings'; }
    public function title(): string { return 'Tax Warnings'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }
        $warnings = (array)($workings['warnings'] ?? []);
        if ($warnings === []) {
            return \eel_accounts\Renderer\TaxCardRenderer::header('company_tax_returns')
                . '<section class="panel-soft"><span class="badge success">Ready</span><div class="helper">No tax fact warnings were found for this period.</div></section>';
        }
        $html = \eel_accounts\Renderer\TaxCardRenderer::header('company_tax_returns');
        $company = (array)($context['company'] ?? []);
        foreach ($warnings as $warning) {
            $workflowButton = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromWorkflow(
                (array)$warning,
                (string)($warning['workflow_label'] ?? 'Open Related Workflow'),
                [
                    'company_id' => (int)($company['id'] ?? 0),
                    'accounting_period_id' => (int)($company['accounting_period_id'] ?? 0),
                ]
            );
            $html .= '<section class="panel-soft settings-stack"><span class="badge warning">Warning</span>'
                . '<div class="helper">' . \eel_accounts\Renderer\TaxCardRenderer::escape($warning['message'] ?? '') . '</div>'
                . '<div class="year-end-related-workflow">' . $workflowButton . '</div></section>';
        }
        return $html;
    }
}
