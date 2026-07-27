<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_historyCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_history'; }

    public function title(): string { return 'iXBRL History'; }

    public function helper(array $context): string
    {
        return 'Previous accounts fact snapshots and generated iXBRL artifacts for the selected accounting period.';
    }

    public function services(): array
    {
        return [[
            'key' => 'ixbrl_history',
            'service' => \eel_accounts\Service\IxbrlGenerationHistoryService::class,
            'method' => 'fetch',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['ixbrl.facts.preview', 'ixbrl.generation', 'page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $runs = array_values((array)($context['services']['ixbrl_history'] ?? []));
        $company = (array)($context['company'] ?? []);
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        if ($runs === []) {
            $table = '<div class="helper">No iXBRL run history exists for this accounting period.</div>';
        } else {
            $table = $this->historyTable($runs);
        }

        return '<div class="settings-stack">' . $table
            . ($developerOptions
                ? '<div class="form-row-actions"><form method="post" action="?page=disclosures" data-ajax="true">'
                    . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                    . '<input type="hidden" name="card_action" value="Ixbrl">'
                    . '<input type="hidden" name="intent" value="cleanup_untransmitted_ixbrl_history">'
                    . '<input type="hidden" name="company_id" value="' . (int)($company['id'] ?? 0) . '">'
                    . '<input type="hidden" name="accounting_period_id" value="' . (int)($company['accounting_period_id'] ?? 0) . '">'
                    . '<button class="button danger" type="submit" title="Developer only" data-chicken-check="true" data-chicken-title="Clean untransmitted iXBRL history" data-chicken-message="Remove superseded filing approvals, runs, evidence bundles, artifacts, events, and unsent submission drafts for this accounting period?<br><br>The current approval, current evidence bundle, current facts, and any transmitted or in-flight evidence are protected. Files are not deleted." data-chicken-confirm-text="Clean history" data-chicken-button-class="button danger">Clean Untransmitted History</button>'
                    . '</form></div>'
                : '')
            . '</div>';
    }

    private function historyTable(array $runs): string
    {
        $rows = '';
        foreach ($runs as $index => $run) {
            $run = (array)$run;
            $path = trim((string)($run['generated_path'] ?? ''));
            $artifact = $path === ''
                ? 'Not generated'
                : (!empty($run['artifact_exists']) ? 'Present' : 'Missing');
            $approvalId = (int)($run['filing_approval_id'] ?? 0);
            $approval = $approvalId > 0 ? '#' . $approvalId : 'Unlinked';
            $bundleId = (int)($run['evidence_bundle_id'] ?? 0);
            if ($bundleId > 0) {
                $approval .= ' · Evidence #' . $bundleId;
            }
            $companiesHouseCount = (int)($run['companies_house_count'] ?? 0);
            $companiesHouseFiledCount = (int)($run['companies_house_filed_count'] ?? 0);
            $companiesHouse = $companiesHouseCount === 0
                ? 'None'
                : $companiesHouseCount . ' record(s); ' . $companiesHouseFiledCount . ' filed/in-flight';
            $rows .= '<tr>'
                . '<td><strong>#' . (int)($run['id'] ?? 0) . '</strong>'
                    . ($index === 0 ? '<div><span class="badge info">Latest</span></div>' : '') . '</td>'
                . '<td>' . HelperFramework::escape($approval)
                    . '<div class="helper">' . HelperFramework::escape((string)($run['approved_at'] ?? '')) . '</div></td>'
                . '<td>' . (int)($run['fact_count'] ?? 0) . '</td>'
                . '<td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($run['status'] ?? 'draft'), '_')) . '</td>'
                . '<td>' . HelperFramework::escape($artifact)
                    . '<div class="helper">' . HelperFramework::escape((string)($run['generated_filename'] ?? '')) . '</div></td>'
                . '<td>' . HelperFramework::escape($this->validation((string)($run['validation_status'] ?? 'not_validated'))) . '</td>'
                . '<td>' . HelperFramework::escape($this->validation((string)($run['external_validation_status'] ?? 'not_configured'))) . '</td>'
                . '<td>' . HelperFramework::escape($companiesHouse) . '</td>'
                . '<td>' . HelperFramework::escape((string)($run['created_at'] ?? ''))
                    . '<div class="helper">Generated ' . HelperFramework::escape((string)($run['generated_at'] ?? 'Not generated')) . '</div></td>'
                . '</tr>';
        }

        return '<div class="table-scroll"><table class="data-table"><thead><tr>'
            . '<th>Run</th><th>Approval</th><th>Facts</th><th>Status</th><th>Artifact</th>'
            . '<th>Internal validation</th><th>Arelle</th><th>Companies House</th><th>Dates</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function validation(string $status): string
    {
        return match ($status) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'error' => 'Error',
            'not_configured' => 'Not configured',
            'not_validated', 'not_run', '' => 'Not run',
            default => HelperFramework::labelFromKey($status, '_'),
        };
    }
}
