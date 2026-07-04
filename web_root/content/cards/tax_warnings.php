<?php
declare(strict_types=1);

final class _tax_warningsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_warnings'; }
    public function title(): string { return 'Tax Warnings'; }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $warnings = (array)($workings['warnings'] ?? []);
        if ($warnings === []) {
            return \eel_accounts\Ui\TaxCardRenderer::header('company_tax_returns')
                . '<section class="panel-soft"><span class="badge success">Ready</span><div class="helper">No tax fact warnings were found for this period.</div></section>';
        }
        $html = \eel_accounts\Ui\TaxCardRenderer::header('company_tax_returns');
        foreach ($warnings as $warning) {
            $url = (string)($warning['workflow_url'] ?? '?page=year_end');
            $html .= '<section class="panel-soft settings-stack"><span class="badge warning">Warning</span>'
                . '<div class="helper">' . \eel_accounts\Ui\TaxCardRenderer::escape($warning['message'] ?? '') . '</div>'
                . '<div class="year-end-related-workflow"><a class="button" href="' . \eel_accounts\Ui\TaxCardRenderer::escape($url) . '">'
                . \eel_accounts\Ui\TaxCardRenderer::escape($warning['workflow_label'] ?? 'Open Related Workflow') . '</a></div></section>';
        }
        return $html;
    }
}
