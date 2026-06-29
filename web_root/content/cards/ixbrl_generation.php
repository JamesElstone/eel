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

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $run = (array)($context['ixbrl']['latest_run'] ?? []);
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $canBuild = !empty($readiness['can_build_facts']);
        $canGenerate = $canBuild && ((int)($run['fact_count'] ?? 0) > 0 || $run === []);
        $fileExists = trim((string)($run['generated_path'] ?? '')) !== '' && is_file((string)$run['generated_path']);
        $canValidateExternal = $fileExists;
        $download = $fileExists
            ? '<a class="button" href="/outbound/ixbrl/' . rawurlencode((string)$run['generated_filename']) . '">Download Generated File</a>'
            : '';

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Latest run</h3>
                    <span class="badge ' . HelperFramework::escape($this->statusClass((string)($run['status'] ?? 'draft'))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($run['status'] ?? 'none'), '_')) . '</span>
                </div>
                <div class="summary-grid">
                    ' . $this->metric('Generated at', (string)($run['generated_at'] ?? 'Not generated')) . '
                    ' . $this->metric('Output filename', (string)($run['generated_filename'] ?? '')) . '
                    ' . $this->metric('SHA-256', (string)($run['output_sha256'] ?? '')) . '
                    ' . $this->metric('Facts', (string)(int)($run['fact_count'] ?? 0)) . '
                    ' . $this->metric('Export type', (string)($run['export_type'] ?? '')) . '
                    ' . $this->metric('Validation', (string)($run['validation_status'] ?? '')) . '
                    ' . $this->metric('Arelle status', (string)($run['external_validation_status'] ?? 'not_configured')) . '
                    ' . $this->metric('Arelle validated at', (string)($run['external_validated_at'] ?? '')) . '
                </div>
                <div class="helper">' . HelperFramework::escape((string)($run['error_message'] ?? '')) . '</div>
                <div class="helper">' . HelperFramework::escape($this->externalSummary($run)) . '</div>
                <div class="helper">Generated XHTML is an FRS 105 micro-entity accounts iXBRL export for review and validation before filing.</div>
            </section>
            <form method="post" action="?page=ixbrl_builder" data-ajax="true" class="actions-row">
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="build_ixbrl_facts">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button" type="submit"' . ($canBuild ? '' : ' disabled') . '>Build / Refresh Facts</button>
            </form>
            <form method="post" action="?page=ixbrl_builder" data-ajax="true" class="actions-row">
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="generate_ixbrl_preview">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button primary" type="submit"' . ($canGenerate ? '' : ' disabled') . '>Generate Filing Export</button>
                ' . $download . '
            </form>
            <form method="post" action="?page=ixbrl_builder" data-ajax="true" class="actions-row">
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
            'failed' => 'danger',
            default => 'muted',
        };
    }

    private function externalSummary(array $run): string
    {
        $status = (string)($run['external_validation_status'] ?? 'not_configured');
        $errors = json_decode((string)($run['external_validation_errors_json'] ?? '[]'), true);
        $warnings = json_decode((string)($run['external_validation_warnings_json'] ?? '[]'), true);
        $errorCount = is_array($errors) ? count($errors) : 0;
        $warningCount = is_array($warnings) ? count($warnings) : 0;
        $logPath = (string)($run['external_validation_log_path'] ?? '');

        if ($status === 'passed') {
            return 'Arelle external validation passed' . ($warningCount > 0 ? ' with ' . $warningCount . ' warning(s).' : '.');
        }
        if ($status === 'failed') {
            return 'Arelle external validation failed with ' . $errorCount . ' error(s)' . ($logPath !== '' ? '. Log: ' . $logPath : '.');
        }
        if ($status === 'error') {
            return 'Arelle external validation could not be completed' . ($logPath !== '' ? '. Log: ' . $logPath : '.');
        }

        return 'Arelle external validation has not been configured or run.';
    }
}
