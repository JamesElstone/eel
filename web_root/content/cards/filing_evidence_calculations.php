<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_calculationsCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_calculations'; }
    public function title(): string { return 'Frozen Calculation Trace'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceCalculations', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'calculations', 'params' => ['companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }
    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceCalculations'] ?? []);
        if (empty($model['available'])) { return '<div class="helper">Select filing evidence to inspect its frozen equations and sources.</div>'; }
        $state = (array)($context['filing_evidence'] ?? []); $rows = '';
        foreach ((array)$model['areas'] as $area) {
            $rows .= '<tr><td>CT period ' . (int)$area['sequence_no'] . '<br><span class="helper">'
                . \eel_accounts\Support\Utf8::html((string)$area['period_start'] . ' to ' . (string)$area['period_end']) . '</span></td><td><strong>'
                . \eel_accounts\Support\Utf8::html((string)$area['area_label']) . '</strong><br><span class="helper">sum(frozen source rows)</span></td><td>'
                . \eel_accounts\Support\Utf8::html((string)$area['amount']) . '</td><td>' . (int)$area['source_count'] . '</td><td><form method="post" action="?page=filing_evidence" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="action" value="select-filing-evidence-area"><input type="hidden" name="evidence_bundle_id" value="' . (int)$state['bundle_id'] . '">'
                . '<input type="hidden" name="evidence_reference" value="' . \eel_accounts\Support\Utf8::html((string)$state['reference']) . '"><input type="hidden" name="evidence_snapshot_id" value="' . (int)$area['snapshot_id'] . '">'
                . '<input type="hidden" name="evidence_area_code" value="' . \eel_accounts\Support\Utf8::html((string)$area['area_code']) . '"><button class="button button-inline" type="submit">Show Frozen Sources</button></form></td></tr>';
        }
        return '<div class="table-scroll"><table><thead><tr><th>CT period</th><th>Calculation</th><th>Result</th><th>Sources</th><th>Action</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
