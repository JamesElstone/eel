<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _director_loan_filing_evidenceCard extends CardBaseFramework
{
    public function key(): string { return 'director_loan_filing_evidence'; }
    public function title(): string { return 'Frozen Filing Evidence'; }
    public function services(): array { return [[
        'key' => 'loanFilingEvidenceBundles', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'listForAccountingPeriod', 'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['year.end.state', 'filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $model = (array)($context['services']['loanFilingEvidenceBundles'] ?? []);
        $bundles = (array)($model['bundles'] ?? []);
        $bundle = current(array_filter($bundles, static fn(array $row): bool => !empty($row['is_current_for_locked_period'])));
        if (!is_array($bundle)) {
            return '<div class="helper">Immutable director-loan evidence is created when this accounting period is locked at Year End.</div>';
        }
        $reference = (string)($bundle['evidence_id'] ?? '');
        $url = '?page=filing_evidence&evidence_reference=' . rawurlencode($reference)
            . '&evidence_bundle_id=' . (int)($bundle['id'] ?? 0);
        return '<section class="panel-soft settings-stack"><div class="summary-card-header"><div><div class="eyebrow">Current Year End bundle</div><div class="summary-value">'
            . \eel_accounts\Support\Utf8::html((string)($bundle['display_id'] ?? $reference)) . '</div></div><span class="badge success">Frozen</span></div>'
            . '<div class="helper">This bundle contains the frozen S455, CT600A/Section 464A–C and Section 413 evidence used at Year End.</div>'
            . '<a class="button primary" href="' . \eel_accounts\Support\Utf8::html($url) . '">View Frozen Filing Evidence</a></section>';
    }
}
