<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_calculation_detailCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_calculation_detail'; }
    public function title(): string { return 'Frozen Journal-Level Detail'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceCalculationDetail', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'calculationDetail', 'params' => [
            'companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id',
            'snapshotId' => ':filing_evidence.snapshot_id', 'areaCode' => ':filing_evidence.area_code',
            'page' => ':filing_evidence.detail_page',
        ],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }
    public function render(array $context): string
    {
        $detail = (array)($context['services']['filingEvidenceCalculationDetail'] ?? []);
        if (!empty($detail['empty_selection'])) { return '<div class="helper">Choose a frozen calculation area above.</div>'; }
        if (empty($detail['available'])) { return '<div class="helper">' . HelperFramework::escape((string)(($detail['errors'] ?? [])[0] ?? 'Frozen detail unavailable.')) . '</div>'; }
        $rows = '';
        foreach ((array)$detail['rows'] as $row) {
            $rows .= '<tr><td>' . HelperFramework::escape((string)($row['source_date'] ?? '')) . '</td><td><strong>'
                . HelperFramework::escape((string)($row['label'] ?? 'Frozen source')) . '</strong><br><span class="helper">'
                . HelperFramework::escape((string)($row['source_label'] ?? $row['source_type'] ?? '')) . '</span></td><td>'
                . HelperFramework::escape(trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? ''))) . '</td><td>'
                . HelperFramework::escape((string)($row['accounting_amount'] ?? '0')) . '</td><td>' . HelperFramework::escape((string)($row['tax_adjustment_amount'] ?? '0')) . '</td><td>'
                . HelperFramework::escape(trim((string)($row['rule_code'] ?? '') . ' ' . (string)($row['rule_version'] ?? ''))) . '</td><td>' . $this->currentJournalLink($row, $context) . '</td></tr>';
        }
        if ($rows === '') { $rows = '<tr><td colspan="7">No journal-level rows are required for this calculation.</td></tr>'; }
        return '<div class="helper"><span class="badge success">Frozen evidence</span> Displayed values come from the immutable snapshot, not the current ledger.</div>'
            . '<div class="summary-grid"><div class="summary-card"><div class="summary-label">Frozen result</div><div class="summary-value">' . HelperFramework::escape((string)($detail['amount'] ?? 0)) . '</div></div>'
            . '<div class="summary-card"><div class="summary-label">Expected result</div><div class="summary-value">' . HelperFramework::escape((string)($detail['expected_amount'] ?? 0)) . '</div></div></div>'
            . '<div class="table-scroll"><table><thead><tr><th>Date</th><th>Frozen source</th><th>Nominal</th><th>Accounting</th><th>Tax adjustment</th><th>Rule</th><th>Live record</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
    private function currentJournalLink(array $row, array $context): string
    {
        $journalId = (int)($row['journal_id'] ?? ((string)($row['source_type'] ?? '') === 'journal' ? ($row['source_id'] ?? 0) : 0));
        if ($journalId <= 0) { return '<span class="helper">Calculated source</span>'; }
        return \eel_accounts\Renderer\WorkflowHandoffRenderer::button('journal', 'Current journal', [
            'company_id' => (int)($context['company']['id'] ?? 0),
            'accounting_period_id' => (int)($context['company']['accounting_period_id'] ?? 0),
            'journal_id' => $journalId,
        ], 'button button-inline');
    }
}
