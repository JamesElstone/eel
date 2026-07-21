<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_artifactsCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_artifacts'; }
    public function title(): string { return 'Artifacts and Schemas'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceArtifacts', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'artifacts', 'params' => ['companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }
    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceArtifacts'] ?? []);
        if (empty($model['available'])) { return '<div class="helper">Artifacts appear after an Evidence ID is selected.</div>'; }
        $rows = '';
        foreach ((array)$model['artifacts'] as $artifact) {
            $rows .= '<tr><td><strong>' . HelperFramework::escape(HelperFramework::labelFromKey((string)$artifact['artifact_role']))
                . '</strong><br><span class="helper">' . HelperFramework::escape((string)$artifact['display_id']) . '</span></td><td>'
                . HelperFramework::escape((string)($artifact['filename'] ?? 'Not persisted')) . '</td><td><code>'
                . HelperFramework::escape((string)($artifact['sha256'] ?? 'Reserved')) . '</code></td><td>'
                . HelperFramework::escape((string)($artifact['schema_identity'] ?? 'Internal format')) . '</td><td>'
                . HelperFramework::escape((string)$artifact['generator_name'] . ' ' . (string)$artifact['generator_version']) . '</td><td><span class="badge '
                . ((string)$artifact['artifact_status'] === 'failed' ? 'danger' : 'success') . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)$artifact['artifact_status']))
                . '</span>' . (!empty($artifact['legacy_non_embedded']) ? ' <span class="badge warning">Not embedded</span>' : '') . '</td></tr>';
        }
        if ($rows === '') { $rows = '<tr><td colspan="6">No generated artifacts are linked yet.</td></tr>'; }
        return '<div class="table-scroll"><table><thead><tr><th>Artifact</th><th>File</th><th>SHA-256</th><th>Schema / taxonomy</th><th>Producer</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
