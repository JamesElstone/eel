<?php
declare(strict_types=1);

final class _ct_filing_mappings extends PageContextFramework
{
    public function id(): string { return 'ct_filing_mappings'; }
    public function title(): string { return 'CT Filing Mappings'; }
    public function subtitle(): string { return 'Maintain reviewed, revisioned mappings independently from company tax-return work.'; }
    public function hiddenSiteContextSelectors(): array { return ['company_id', 'accounting_period_id']; }
    public function cards(): array { return ['tax_ct600_rim_mappings', 'tax_ct_computation_mappings']; }
}
