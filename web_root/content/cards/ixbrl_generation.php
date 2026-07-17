<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_generationCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_generation'; }

    public function title(): string { return 'iXBRL Generation'; }

    public function helper(array $context): string
    {
        return 'Builds the filing facts, generates the iXBRL export, checks it internally and with Arelle, and enables the download when the export is filing-ready.';
    }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $run = (array)($context['ixbrl']['latest_run'] ?? []);
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $arelleStatus = (array)($readiness['arelle_status'] ?? []);
        $canBuild = !empty($readiness['can_build_facts']);
        $canGenerate = !empty($readiness['can_generate']);
        $canValidateExternal = !empty($readiness['can_validate']);
        $readyForFiling = !empty($readiness['ready_for_filing']);
        $runFreshness = (array)($run['run_freshness'] ?? []);
        $stale = (int)($run['fact_count'] ?? 0) > 0
            && (string)($runFreshness['state'] ?? '') !== 'current';
        $displayStatus = $readyForFiling
            ? 'filing_ready'
            : ($stale ? 'stale' : (string)($run['status'] ?? 'draft'));
        $fileExists = !$stale
            && trim((string)($run['generated_path'] ?? '')) !== ''
            && is_file((string)$run['generated_path']);
        $download = $readyForFiling && $fileExists
            ? '<form method="post" action="?page=disclosures" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Ixbrl">'
                . '<input type="hidden" name="intent" value="download_ixbrl_filing">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '">'
                . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<button class="button primary" type="submit">Download Filing-ready File</button>'
                . '</form>'
            : '';

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Latest run</h3>
                    <span class="badge ' . HelperFramework::escape($this->statusClass($displayStatus)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($displayStatus, '_')) . '</span>
                </div>
                <div class="summary-grid">
                    ' . $this->metric('Generated At', (string)($run['generated_at'] ?? 'Not Generated')) . '
                    ' . $this->metric('Facts', (string)(int)($run['fact_count'] ?? 0)) . '
                    ' . $this->metric('Export Type', $this->exportTypeLabel((string)($run['export_type'] ?? ''))) . '
                    ' . $this->metric('Taxonomy Profile', (string)($run['taxonomy_profile'] ?? '')) . '
                    ' . $this->metric('Validation', $this->validationLabel((string)($run['validation_status'] ?? 'not_run'))) . '
                    ' . $this->metric('Arelle Status', !empty($arelleStatus['installed']) ? 'Installed' : 'Not Installed') . '
                    ' . $this->metric('Arelle Validation', $this->validationLabel((string)($run['external_validation_status'] ?? 'not_run'))) . '
                    ' . $this->metric('Arelle Validated At', (string)($run['external_validated_at'] ?? '')) . '
                </div>
                <div class="helper">' . HelperFramework::escape((string)($run['error_message'] ?? '')) . '</div>
                ' . ($stale
                    ? '<div class="helper"><span class="badge warning">Rebuild required</span> '
                        . HelperFramework::escape((string)($runFreshness['detail'] ?? 'The latest facts are stale.'))
                        . '</div>'
                    : '') . '
                <div class="helper">' . HelperFramework::escape($this->externalSummary($run, $arelleStatus)) . '</div>
                ' . $this->validationDetails($run) . '
                ' . (!$readyForFiling && $fileExists
                    ? '<div class="helper"><span class="badge warning">Review draft only</span> The generated file is withheld from filing download until the current file passes every validation and hash check.</div>'
                    : '') . '
            </section>
            <form method="post" action="?page=disclosures" data-ajax="true" class="actions-row">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="build_ixbrl_facts">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button" type="submit"' . ($canBuild ? '' : ' disabled') . '>Build / Refresh Facts</button>
            </form>
            <form method="post" action="?page=disclosures" data-ajax="true" class="actions-row">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="generate_ixbrl_preview">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button primary" type="submit"' . ($canGenerate ? '' : ' disabled') . '>Generate Filing Export</button>
            </form>
            ' . $download . '
            <form method="post" action="?page=disclosures" data-ajax="true" class="actions-row">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="validate_ixbrl_external">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button" type="submit"' . ($canValidateExternal ? '' : ' disabled') . '>Run External Validation</button>
            </form>
        </div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            'ready' => 'warning',
            'generated' => 'success',
            'filing_ready' => 'success',
            'stale' => 'warning',
            'failed' => 'danger',
            default => 'muted',
        };
    }

    private function validationLabel(string $status): string
    {
        return match ($status) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'error' => 'Error',
            default => 'Not Run',
        };
    }

    private function exportTypeLabel(string $type): string
    {
        return match ($type) {
            'filing_export' => 'Filing Export',
            default => $type === '' ? 'Not Generated' : HelperFramework::labelFromKey($type, '_'),
        };
    }

    private function externalSummary(array $run, array $arelleStatus = []): string
    {
        $freshness = (array)($run['run_freshness'] ?? []);
        if ($freshness !== [] && (string)($freshness['state'] ?? '') !== 'current') {
            return 'External validation is unavailable until the iXBRL facts and filing export are rebuilt.';
        }

        $status = (string)($run['external_validation_status'] ?? 'not_configured');
        $errors = json_decode((string)($run['external_validation_errors_json'] ?? '[]'), true);
        $warnings = json_decode((string)($run['external_validation_warnings_json'] ?? '[]'), true);
        $errorCount = is_array($errors) ? count($errors) : 0;
        $warningCount = is_array($warnings) ? count($warnings) : 0;
        $logPath = (string)($run['external_validation_log_path'] ?? '');

        if ($status === 'not_configured' && !empty($arelleStatus['installed'])) {
            return 'Arelle is installed; this export has not been externally validated yet.';
        }

        if ($status === 'passed' && $warningCount > 0) {
            return 'Arelle reported ' . $warningCount . ' warning(s).';
        }
        if ($status === 'failed') {
            return 'Arelle external validation failed with ' . $errorCount . ' error(s).';
        }
        if ($status === 'error') {
            return 'Arelle external validation could not be completed.';
        }

        return 'Arelle external validation has not been configured or run.';
    }

    private function validationDetails(array $run): string
    {
        $internalErrors = json_decode((string)($run['validation_errors_json'] ?? '[]'), true);
        $externalErrors = json_decode((string)($run['external_validation_errors_json'] ?? '[]'), true);
        $externalWarnings = json_decode((string)($run['external_validation_warnings_json'] ?? '[]'), true);
        $groups = [
            'Internal errors' => is_array($internalErrors) ? $internalErrors : [],
            'Arelle errors' => is_array($externalErrors) ? $externalErrors : [],
            'Arelle warnings' => is_array($externalWarnings) ? $externalWarnings : [],
        ];
        $html = '';
        foreach ($groups as $label => $messages) {
            if ($messages === []) {
                continue;
            }
            $items = '';
            foreach (array_slice($messages, 0, 20) as $message) {
                $items .= '<li>' . HelperFramework::escape(is_scalar($message) ? (string)$message : (string)json_encode($message)) . '</li>';
            }
            $html .= '<section class="panel-soft"><h4>' . HelperFramework::escape($label) . '</h4><ul>' . $items . '</ul></section>';
        }

        return $html;
    }
}
