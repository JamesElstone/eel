<?php
declare(strict_types=1);

final class _tax_artifacts_refreshCard extends CardBaseFramework
{
    public function key(): string { return 'tax_artifacts_refresh'; }
    public function title(): string { return 'Refresh tax artefacts'; }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'hmrc_ct_rim.refresh',
            'hmrc_ct_rim.state',
            'hmrc.ct.computation.taxonomy',
            'ct.filing.mappings',
            'frc.taxonomy',
            'companies.house.accounts.schemas',
            'vat.rate.rules',
            'vat.threshold.rules',
            'page.context',
        ];
    }

    public function helper(array $context): string
    {
        return 'Refreshes the HMRC CT filing artefacts, FRC taxonomy, Companies House filing schemas, VAT rates and VAT registration thresholds. Each source is attempted even if another source fails.';
    }

    public function render(array $context): string
    {
        return '<div class="settings-stack"><div class="panel-soft settings-stack">'
            . '<p>Use this when preparing the application with the latest official filing artefacts and VAT reference data.</p>'
            . '<form method="post" action="?page=tax_artifacts" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxArtifactsRefresh">'
            . '<input type="hidden" name="intent" value="refresh_all_tax_artifacts">'
            . '<button class="button primary" type="submit">Refresh all tax artefacts</button>'
            . '</form></div></div>';
    }
}
