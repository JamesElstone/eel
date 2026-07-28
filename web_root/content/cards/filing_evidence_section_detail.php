<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_section_detailCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_section_detail'; }
    public function title(): string { return 'Frozen Section Detail'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceSectionDetail', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'sectionDetail', 'params' => [
            'companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id',
            'sectionCode' => ':filing_evidence.section_code', 'page' => ':filing_evidence.section_page',
        ],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }
    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceSectionDetail'] ?? []);
        if (!empty($model['empty_selection'])) { return '<div class="helper">Choose a frozen evidence section above.</div>'; }
        if (empty($model['available'])) { return '<div class="helper">' . HelperFramework::escape((string)(($model['errors'] ?? [])[0] ?? 'Frozen section evidence is unavailable.')) . '</div>'; }
        $section = (array)($model['section'] ?? []); $rows = '';
        foreach ((array)($model['rows'] ?? []) as $row) {
            $rows .= '<tr><td><pre class="helper">' . HelperFramework::escape(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . '</pre></td></tr>';
        }
        if ($rows === '') { $rows = '<tr><td>No record-level rows were required for this section.</td></tr>'; }
        $pagination = (array)($model['pagination'] ?? []);
        $state = (array)($context['filing_evidence'] ?? []);
        $navigation = '';
        if ((int)($pagination['page_count'] ?? 1) > 1) {
            $navigation = '<div class="actions-row">';
            foreach ([max(1, (int)$pagination['page'] - 1) => 'Previous', min((int)$pagination['page_count'], (int)$pagination['page'] + 1) => 'Next'] as $targetPage => $label) {
                if (($label === 'Previous' && (int)$pagination['page'] <= 1) || ($label === 'Next' && (int)$pagination['page'] >= (int)$pagination['page_count'])) { continue; }
                $navigation .= '<form method="post" action="?page=filing_evidence" data-ajax="true">'
                    . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                    . '<input type="hidden" name="action" value="select-filing-evidence-section"><input type="hidden" name="evidence_reference" value="' . HelperFramework::escape((string)($state['reference'] ?? '')) . '">'
                    . '<input type="hidden" name="evidence_bundle_id" value="' . (int)($state['bundle_id'] ?? 0) . '"><input type="hidden" name="evidence_section_code" value="' . HelperFramework::escape((string)($state['section_code'] ?? $section['section_code'] ?? '')) . '">'
                    . '<input type="hidden" name="evidence_section_page" value="' . $targetPage . '"><button class="button button-inline" type="submit">' . $label . '</button></form>';
            }
            $navigation .= '</div>';
        }
        $lifecycleRows = '';
        foreach ((array)($model['lifecycle'] ?? []) as $lifecycle) {
            $lifecycleRows .= '<tr><td>' . HelperFramework::escape((string)($lifecycle['created_at'] ?? '')) . '</td><td>'
                . HelperFramework::escape((string)($lifecycle['snapshot_hash'] ?? '')) . '</td></tr>';
        }
        return '<div class="helper"><span class="badge success">Frozen evidence</span> '
            . HelperFramework::escape((string)($section['section_code'] ?? '')) . ' · SHA-256 <code>' . HelperFramework::escape((string)($section['snapshot_hash'] ?? '')) . '</code>'
            . ' · page ' . (int)($pagination['page'] ?? 1) . ' of ' . (int)($pagination['page_count'] ?? 1) . '</div>'
            . '<div class="table-scroll"><table><thead><tr><th>Frozen record</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $navigation
            . ($lifecycleRows === '' ? '' : '<h4>Append-only filing lifecycle snapshots</h4><div class="table-scroll"><table><thead><tr><th>Captured</th><th>SHA-256</th></tr></thead><tbody>' . $lifecycleRows . '</tbody></table></div>');
    }
}
