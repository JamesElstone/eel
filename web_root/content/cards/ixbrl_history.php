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
        return 'Generated HMRC Accounting, HMRC CT600, and Companies House iXBRL artifacts for the selected accounting period.';
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
                    . '<button class="button danger" type="submit" title="Developer only" data-chicken-check="true" data-chicken-title="Clean untransmitted iXBRL history" data-chicken-message="Remove local iXBRL history, filing approvals, and submission drafts that have never been transmitted. CT600 output metadata is cleared while its tax computation remains.<br><br>Transmitted or in-flight filings are retained. Evidence bundles and all generated files are retained." data-chicken-confirm-text="Clean history" data-chicken-button-class="button danger">Clean Untransmitted History</button>'
                    . '</form></div>'
                : '')
            . '</div>';
    }

    private function historyTable(array $runs): string
    {
        $approvalGroups = [];
        $unlinkedRuns = [];
        foreach ($runs as $run) {
            $run = (array)$run;
            $approvalId = (int)($run['filing_approval_id'] ?? 0);
            if ($approvalId <= 0) {
                $unlinkedRuns[] = $run;
                continue;
            }
            if (!isset($approvalGroups[$approvalId])) {
                $approvalGroups[$approvalId] = ['approval' => $run, 'runs' => []];
            }
            $approvalGroups[$approvalId]['runs'][] = $run;
        }

        krsort($approvalGroups, SORT_NUMERIC);
        $rows = '';
        foreach ($approvalGroups as $approvalId => $group) {
            $groupRuns = (array)$group['runs'];
            usort($groupRuns, fn(array $left, array $right): int => $this->compareRows($left, $right));
            $approval = (array)$group['approval'];
            $bundleId = (int)($approval['evidence_bundle_id'] ?? 0);
            $bundleExists = !array_key_exists('evidence_bundle_exists', $approval)
                || (int)$approval['evidence_bundle_exists'] > 0;
            foreach ($groupRuns as $index => $run) {
                $rows .= '<tr>'
                    . ($index === 0
                        ? $this->approvalCells(
                            '#' . $approvalId,
                            $bundleId,
                            $bundleExists,
                            (string)($approval['approved_at'] ?? ''),
                            count($groupRuns)
                        )
                        : '')
                    . $this->runCells($run)
                    . '</tr>';
            }
        }

        if ($unlinkedRuns !== []) {
            usort($unlinkedRuns, fn(array $left, array $right): int => $this->compareRows($left, $right));
            foreach ($unlinkedRuns as $index => $run) {
                $rows .= '<tr>'
                    . ($index === 0
                        ? $this->approvalCells('Unlinked', 0, true, '—', count($unlinkedRuns))
                        : '')
                    . $this->runCells($run)
                    . '</tr>';
            }
        }

        return '<div class="table-scroll"><table class="data-table"><thead><tr>'
            . '<th>Approval ID</th><th>Evidence bundle</th><th>Approved</th><th>Run</th>'
            . '<th>Output</th><th>CT period</th><th>Facts</th><th>Status</th><th>Artifact</th>'
            . '<th>Internal validation</th><th>Arelle</th><th>Dates</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function approvalCells(
        string $approvalId,
        int $evidenceBundleId,
        bool $evidenceBundleExists,
        string $approvedAt,
        int $rowSpan
    ): string
    {
        $approvedAt = trim($approvedAt) !== '' ? $approvedAt : '—';
        $evidenceBundle = $evidenceBundleId > 0
            ? '#' . $evidenceBundleId
                . (!$evidenceBundleExists ? '<div><span class="badge danger">Missing</span></div>' : '')
            : '—';

        return '<td rowspan="' . $rowSpan . '"><strong>' . \eel_accounts\Support\Utf8::html($approvalId) . '</strong></td>'
            . '<td rowspan="' . $rowSpan . '">' . $evidenceBundle . '</td>'
            . '<td rowspan="' . $rowSpan . '">' . \eel_accounts\Support\Utf8::html($approvedAt) . '</td>';
    }

    private function runCells(array $run): string
    {
        $path = trim((string)($run['generated_path'] ?? ''));
        $artifact = $path === ''
            ? 'Not generated'
            : (!empty($run['artifact_exists']) ? 'Present' : 'Missing');
        $outputType = (string)($run['output_type'] ?? '');
        $runId = (int)($run['run_id'] ?? $run['id'] ?? 0);
        $sourceId = (int)($run['source_id'] ?? $runId);
        $runLabel = $runId > 0 ? 'Run #' . $runId : 'Record #' . $sourceId;
        if (str_starts_with($outputType, 'companies_house_')) {
            $runLabel = $runId > 0 ? 'Base run #' . $runId : 'Preparation #' . $sourceId;
        }
        $runHelper = str_starts_with($outputType, 'companies_house_') && $runId > 0
            ? 'Preparation #' . $sourceId
            : '';
        $facts = array_key_exists('fact_count', $run) && $run['fact_count'] !== null
            ? (string)(int)$run['fact_count']
            : '—';

        return '<td><strong>' . \eel_accounts\Support\Utf8::html($runLabel) . '</strong>'
                . ($runHelper !== '' ? '<div class="helper">' . \eel_accounts\Support\Utf8::html($runHelper) . '</div>' : '')
                . (!empty($run['is_latest']) ? '<div><span class="badge info">Latest</span></div>' : '') . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html((string)($run['output_label'] ?? 'iXBRL')) . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html((string)($run['ct_period_label'] ?? '')) . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html($facts) . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey((string)($run['output_status'] ?? $run['status'] ?? 'draft'), '_')) . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html($artifact)
                . '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)($run['generated_filename'] ?? '')) . '</div></td>'
            . '<td>' . \eel_accounts\Support\Utf8::html($this->validation((string)($run['validation_status'] ?? 'not_validated'))) . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html($this->validation((string)($run['external_validation_status'] ?? 'not_configured'))) . '</td>'
            . '<td>' . \eel_accounts\Support\Utf8::html((string)($run['created_at'] ?? ''))
                . '<div class="helper">Generated ' . \eel_accounts\Support\Utf8::html((string)($run['generated_at'] ?? 'Not generated')) . '</div></td>';
    }

    private function compareRows(array $left, array $right): int
    {
        $time = strcmp((string)($right['history_at'] ?? $right['generated_at'] ?? ''), (string)($left['history_at'] ?? $left['generated_at'] ?? ''));
        if ($time !== 0) {
            return $time;
        }
        return (int)($right['source_id'] ?? $right['id'] ?? 0) <=> (int)($left['source_id'] ?? $left['id'] ?? 0);
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
