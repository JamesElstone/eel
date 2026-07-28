<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_coverageCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_coverage'; }
    public function title(): string { return 'Evidence Coverage'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceCoverage', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'coverageIndex', 'params' => ['companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }
    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceCoverage'] ?? []);
        if (empty($model['available'])) { return '<div class="helper">Select filing evidence to inspect its section coverage.</div>'; }
        $state = (array)($context['filing_evidence'] ?? []); $rows = '';
        foreach ((array)($model['sections'] ?? []) as $section) {
            $snapshot = (array)($section['lock_snapshot'] ?? []); $captured = !empty($section['captured']);
            $rows .= '<tr><td><strong>' . HelperFramework::escape(HelperFramework::labelFromKey((string)$section['section_code'])) . '</strong></td><td><span class="badge '
                . ($captured ? 'success' : 'warning') . '">' . ($captured ? 'Frozen' : 'Not captured') . '</span></td><td>'
                . ($captured ? (int)($snapshot['record_count'] ?? 0) : '—') . '</td><td><code>'
                . HelperFramework::escape($captured ? (string)($snapshot['snapshot_hash'] ?? '') : 'Historic bundle') . '</code></td><td>';
            if ($captured) {
                $rows .= '<form method="post" action="?page=filing_evidence" data-ajax="true">'
                    . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                    . '<input type="hidden" name="action" value="select-filing-evidence-section"><input type="hidden" name="evidence_reference" value="' . HelperFramework::escape((string)($state['reference'] ?? '')) . '">'
                    . '<input type="hidden" name="evidence_bundle_id" value="' . (int)($state['bundle_id'] ?? 0) . '"><input type="hidden" name="evidence_section_code" value="' . HelperFramework::escape((string)$section['section_code']) . '">'
                    . '<button class="button button-inline" type="submit">Show frozen records</button></form>';
            } else { $rows .= '<span class="helper">Not captured for this historic bundle.</span>'; }
            $rows .= '</td></tr>';
        }
        return '<div class="helper">Each frozen section is bound into the EEL-FE bundle hash. Linked documents are identified by their stored reference and hash; source files are not copied.</div>'
            . '<div class="table-scroll"><table><thead><tr><th>Section</th><th>Status</th><th>Records</th><th>SHA-256</th><th>Action</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
