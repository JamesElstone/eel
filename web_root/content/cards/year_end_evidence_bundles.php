<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _year_end_evidence_bundlesCard extends CardBaseFramework
{
    public function key(): string { return 'year_end_evidence_bundles'; }
    public function title(): string { return 'Evidence Bundles'; }

    public function services(): array
    {
        return [[
            'key' => 'year_end_evidence_bundles',
            'service' => \eel_accounts\Service\FilingEvidenceService::class,
            'method' => 'listForAccountingPeriod',
            'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
        ]];
    }

    protected function additionalInvalidationFacts(): array { return ['page.context', 'year.end.filing.evidence']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $state = (array)(($context['services'] ?? [])['year_end_evidence_bundles'] ?? []);
        $bundles = (array)($state['bundles'] ?? []);
        $company = (array)($context['company'] ?? []);
        $rows = '';
        foreach ($bundles as $bundle) {
            $bundle = (array)$bundle;
            $retained = array_values(array_filter((array)($bundle['retained_reasons'] ?? [])));
            $status = !empty($bundle['eligible_for_cleanup'])
                ? '<span class="badge warning">Unused historic</span>'
                : '<span class="badge success">Retained</span>';
            $reasons = $retained === [] ? 'No filing use recorded' : implode(', ', $retained);
            $activeArtifactCount = array_key_exists('active_artifact_count', $bundle)
                ? (int)$bundle['active_artifact_count']
                : (int)($bundle['artifact_count'] ?? 0);
            $rows .= '<tr><td><strong>' . \eel_accounts\Support\Utf8::html((string)($bundle['display_id'] ?? $bundle['evidence_id'] ?? '')) . '</strong>'
                . '<div class="helper">#' . (int)($bundle['id'] ?? 0) . '</div></td><td>'
                . \eel_accounts\Support\Utf8::html((string)($bundle['lifecycle_status'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($bundle['locked_at'] ?? '')) . '<div class="helper">'
                . \eel_accounts\Support\Utf8::html((string)($bundle['locked_by'] ?? '')) . '</div></td><td>'
                . (int)($bundle['snapshot_count'] ?? 0) . ' snapshots<br>' . $activeArtifactCount . ' active artifacts</td><td>'
                . $status . '<div class="helper">' . \eel_accounts\Support\Utf8::html($reasons) . '</div></td></tr>';
        }
        if ($rows === '') { $rows = '<tr><td colspan="5">No filing evidence bundles exist for the selected accounting period.</td></tr>'; }

        $developer = (bool)AppConfigurationStore::get('developer_options', false);
        $cleanup = '';
        if ($developer) {
            $eligible = (int)($state['eligible_count'] ?? 0);
            $cleanup = '<div class="actions-row"><form method="post" action="?page=year_end" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="YearEnd"><input type="hidden" name="intent" value="cleanup_unused_historic_filing_evidence">'
                . '<input type="hidden" name="company_id" value="' . (int)($company['id'] ?? 0) . '"><input type="hidden" name="accounting_period_id" value="' . (int)($company['accounting_period_id'] ?? 0) . '">'
                . '<button class="button danger" type="submit" title="Developer only" data-chicken-check="true" data-chicken-title="Remove unused historic evidence" data-chicken-message="Remove ' . $eligible . ' unused historic evidence bundle(s) for this accounting period?<br><br>Latest evidence and the current evidence bundle for a locked period are retained. A historic bundle is retained for a transmitted filing artifact, or while it is linked to a local filing approval or submission. Bundle records and their dependent snapshots, artifacts, and events are removed. When eligible evidence exists, a full database backup is created immediately before and after cleanup. Files on disk are not deleted." data-chicken-confirm-text="Remove evidence" data-chicken-button-class="button danger">Remove unused historic evidence</button></form></div>';
        }

        return '<p class="helper">Frozen Year End filing evidence for the selected accounting period. Historic evidence is retained for transmitted filing artifacts and while linked to local filing approvals or submissions.</p>'
            . '<div class="table-scroll"><table><thead><tr><th>Evidence bundle</th><th>Lifecycle</th><th>Locked</th><th>Frozen content</th><th>Retention</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>' . $cleanup;
    }
}
