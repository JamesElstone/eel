<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_overviewCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_overview'; }
    public function title(): string { return 'Evidence Overview'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceOverview', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'overview', 'params' => ['companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }
    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceOverview'] ?? []);
        if (!empty($model['empty_selection'])) { return '<div class="helper">Look up an Evidence ID to inspect its immutable history.</div>'; }
        if (empty($model['available'])) { return '<div class="helper">' . HelperFramework::escape((string)(($model['errors'] ?? [])[0] ?? 'Evidence unavailable.')) . '</div>'; }
        $b = (array)$model['bundle'];
        $status = (string)($b['lifecycle_status'] ?? 'current');
        $periodRows = '';
        foreach ((array)$model['ct_periods'] as $period) {
            $periodRows .= '<tr><td>CT period ' . (int)$period['sequence_no'] . '</td><td>'
                . HelperFramework::escape((string)$period['period_start'] . ' to ' . (string)$period['period_end'])
                . '</td><td><code>' . HelperFramework::escape((string)$period['calculation_basis_hash']) . '</code></td></tr>';
        }
        $events = '';
        foreach ((array)$model['events'] as $event) {
            $level = (string)$event['event_status'];
            $events .= '<tr><td>' . HelperFramework::escape((string)$event['created_at']) . '</td><td><span class="badge '
                . ($level === 'success' ? 'success' : ($level === 'error' ? 'danger' : 'warning')) . '">'
                . HelperFramework::escape(HelperFramework::labelFromKey((string)$event['event_type'])) . '</span></td><td>'
                . HelperFramework::escape((string)$event['event_message']) . '</td><td>' . HelperFramework::escape((string)$event['actor']) . '</td></tr>';
        }
        $submissions = '';
        foreach ((array)$model['hmrc_submissions'] as $submission) {
            $outcome = (string)($submission['business_outcome'] ?: $submission['status']);
            $submissions .= '<tr><td>HMRC Corporation Tax</td><td>'
                . HelperFramework::escape((string)$submission['environment']) . '</td><td>'
                . HelperFramework::escape(HelperFramework::labelFromKey($outcome)) . '</td><td>'
                . HelperFramework::escape((string)($submission['hmrc_submission_reference'] ?: $submission['hmrc_correlation_id'] ?: $submission['transaction_id']))
                . '</td><td>' . HelperFramework::escape((string)($submission['final_response_at'] ?: $submission['submitted_at'])) . '</td></tr>';
        }
        foreach ((array)$model['companies_house_submissions'] as $submission) {
            $submissions .= '<tr><td>Companies House accounts</td><td>'
                . HelperFramework::escape((string)$submission['environment']) . '</td><td>'
                . HelperFramework::escape(HelperFramework::labelFromKey((string)$submission['lifecycle'])) . '</td><td>'
                . HelperFramework::escape((string)($submission['gateway_submission_reference'] ?: $submission['submission_number']))
                . '</td><td>' . HelperFramework::escape((string)($submission['accepted_at'] ?: $submission['submitted_at'])) . '</td></tr>';
        }
        return '<div class="summary-grid"><div class="summary-card"><div class="summary-label">Evidence ID</div><div class="summary-value">'
            . HelperFramework::escape((string)$b['display_id']) . '</div></div><div class="summary-card"><div class="summary-label">Lifecycle</div><div class="summary-value"><span class="badge '
            . ($status === 'current' ? 'success' : 'warning') . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status))
            . '</span></div></div><div class="summary-card"><div class="summary-label">Locked</div><div class="summary-value">'
            . HelperFramework::escape((string)$b['locked_at']) . '</div></div></div>'
            . (!empty($b['legacy_backfill']) ? '<div class="helper"><span class="badge warning">Legacy backfill</span> The original files were not modified; equation presentation may be reconstructed from frozen values.</div>' : '')
            . '<div class="helper">Produced by ' . HelperFramework::escape((string)$b['application_name']) . ' '
            . HelperFramework::escape((string)$b['application_version']) . ' · evidence ' . HelperFramework::escape((string)$b['evidence_version']) . '</div>'
            . '<div class="table-scroll"><table><thead><tr><th>Scope</th><th>Dates</th><th>Frozen basis hash</th></tr></thead><tbody>' . $periodRows . '</tbody></table></div>'
            . '<h3>Submission outcomes</h3>'
            . ($submissions === '' ? '<div class="helper">No submission attempts are linked to this evidence.</div>'
                : '<div class="table-scroll"><table><thead><tr><th>Destination</th><th>Environment</th><th>Outcome</th><th>Reference</th><th>When</th></tr></thead><tbody>' . $submissions . '</tbody></table></div>')
            . '<h3>Lifecycle history</h3><div class="table-scroll"><table><thead><tr><th>When</th><th>Event</th><th>Detail</th><th>Actor</th></tr></thead><tbody>' . $events . '</tbody></table></div>';
    }
}
