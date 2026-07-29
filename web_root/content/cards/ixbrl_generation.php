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
        return 'Generates the HMRC accounts and computation iXBRLs. The required Companies House original or revised artifact is shown when prepared from the locked Year End workflow.';
    }

    public function services(): array
    {
        return [[
            'key' => 'companies_house_ixbrl',
            'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
            'method' => 'fetchContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    protected function additionalInvalidationFacts(): array { return ['ixbrl.generation', 'page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $run = (array)($context['ixbrl']['latest_run'] ?? []);
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $arelleStatus = (array)($readiness['arelle_status'] ?? []);
        $canGenerate = !empty($readiness['can_generate']);
        $readyForFiling = !empty($readiness['ready_for_filing']);
        $generationBlockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $message): string => trim((string)$message),
            (array)($readiness['generation_errors'] ?? [])
        ), static fn(string $message): bool => $message !== '')));
        $canGenerateAll = $canGenerate
            && $this->allComputationPeriodsReady($context)
            && $this->companiesHouseArtifactReady($context);
        $generationBlockerPanel = $this->generationBlockerPanel($context);
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        $runFreshness = (array)($run['run_freshness'] ?? []);
        $stale = (int)($run['fact_count'] ?? 0) > 0
            && (string)($runFreshness['state'] ?? '') !== 'current';
        $displayStatus = $readyForFiling
            ? 'filing_ready'
            : ($stale ? 'stale' : (string)($run['status'] ?? 'draft'));
        $fileExists = !$stale
            && trim((string)($run['generated_path'] ?? '')) !== ''
            && is_file((string)$run['generated_path']);
        $download = $readyForFiling
            ? '<form method="post" action="?page=disclosures">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Ixbrl">'
                . '<input type="hidden" name="intent" value="download_ixbrl_filing">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '">'
                . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<button class="button primary" type="submit"' . ($fileExists ? '' : ' disabled') . '>Download Accounting iXBRL</button>'
                . '</form>'
            : '';
        $artifact = $download !== '' ? $download : 'Not generated';
        return '<div class="settings-stack">
            ' . $generationBlockerPanel . '
            <section class="panel-soft">
                <div class="status-head">
                    <div>
                        <h3 class="card-title">Complete Filing Set</h3>
                        <div class="helper ixbrl-complete-filing-set-helper">Generate and validate the Accounting iXBRL, every Corporation Tax iXBRL, and the required Companies House original or revised accounts iXBRL.</div>
                    </div>
                </div>
                ' . ($developerOptions ? '<div class="actions-row ixbrl-developer-cleanup-action"><form method="post" action="?page=disclosures" data-ajax="true">'
                    . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                    . '<input type="hidden" name="card_action" value="Ixbrl">'
                    . '<input type="hidden" name="intent" value="sync_missing_ixbrl_runs">'
                    . '<input type="hidden" name="company_id" value="' . $companyId . '">'
                    . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                    . '<button class="button danger" type="submit" title="Developer only" data-chicken-check="true" data-chicken-title="Synchronise missing iXBRL runs" data-chicken-message="Synchronise accounts iXBRL artifacts that no longer exist on disk?<br><br>Approved fact snapshots are retained and returned to generation-ready state. Empty run records and unsent Companies House drafts are removed. Filing approvals, evidence bundles, and runs used by transmitted or in-flight Companies House filings are retained. No files are deleted." data-chicken-confirm-text="Synchronise" data-chicken-button-class="button danger">Synchronise missing iXBRL runs</button>'
                    . '</form></div>' : '') . '
                <form method="post" action="?page=disclosures" data-ajax="true" class="actions-row">
                    ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Ixbrl">
                    <input type="hidden" name="intent" value="generate_all_filing_ixbrl">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <button class="button primary" type="submit"' . ($canGenerateAll ? '' : ' disabled') . '>Generate All Filing iXBRLs</button>
                </form>
                ' . ($canGenerateAll ? '' : '<div class="helper">Approve a generation-ready accounts basis, resolve every CT-period computation blocker, and complete the Companies House filing prerequisites when required.</div>') . '
            </section>
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">HMRC Accounting iXBRL</h3>
                    <span class="badge ' . \eel_accounts\Support\Utf8::html($this->statusClass($displayStatus)) . '">' . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey($displayStatus, '_')) . '</span>
                </div>
                <div class="helper ixbrl-complete-filing-set-helper">Generate the approved HMRC accounts iXBRL export and review its structural and Arelle validation results.</div>
                <div class="summary-grid">
                    ' . $this->metric('Generated At', (string)($run['generated_at'] ?? 'Not Generated')) . '
                    ' . $this->metric('Facts', (string)(int)($run['fact_count'] ?? 0)) . '
                    ' . $this->metric('Export Type', $this->exportTypeLabel((string)($run['export_type'] ?? ''))) . '
                    ' . $this->metric('Taxonomy Profile', (string)($run['taxonomy_profile'] ?? '')) . '
                    ' . $this->metric('Validation', $this->validationLabel((string)($run['validation_status'] ?? 'not_run'))) . '
                    ' . $this->metric('Arelle Status', !empty($arelleStatus['installed']) ? 'Installed' : 'Not Installed') . '
                    ' . $this->metric('Arelle Validation', $this->validationLabel((string)($run['external_validation_status'] ?? 'not_run'))) . '
                    ' . $this->metric('Arelle Validated At', (string)($run['external_validated_at'] ?? '')) . '
                    ' . $this->metricHtml('Artifact', $artifact) . '
                </div>
                <div class="helper">' . \eel_accounts\Support\Utf8::html((string)($run['error_message'] ?? '')) . '</div>
                ' . ($stale
                    ? '<div class="helper ixbrl-rebuild-required-helper"><span class="badge warning">Rebuild required</span> '
                        . \eel_accounts\Support\Utf8::html((string)($runFreshness['detail'] ?? 'The latest facts are stale.'))
                        . '</div>'
                    : '') . '
                ' . $this->internalValidationDetails($run) . '
                ' . $this->arelleOutput($run, [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'scope' => 'accounts',
                    'run_id' => (int)($run['id'] ?? 0),
                ]) . '
                ' . (!$readyForFiling && $fileExists
                    ? '<div class="helper"><span class="badge warning">Review draft only</span> The generated file is withheld from filing download until the current file passes every validation and hash check.</div>'
                    : '') . '
                <div class="actions-row">
                    <form method="post" action="?page=disclosures" data-ajax="true">
                        ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                        <input type="hidden" name="card_action" value="Ixbrl">
                        <input type="hidden" name="intent" value="generate_ixbrl_preview">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                        <button class="button primary" type="submit"' . ($canGenerate ? '' : ' disabled') . '>Generate Accounting iXBRL</button>
                    </form>
                </div>
                ' . (!$canGenerate ? $this->generationBlockers($generationBlockers) : '') . '
            </section>
            ' . $this->computationPeriods($context, $companyId, $accountingPeriodId) . '
            ' . $this->companiesHouseArtifact($context, $companyId, $accountingPeriodId) . '
        </div>';
    }

    private function companiesHouseArtifact(array $context, int $companyId, int $accountingPeriodId): string
    {
        $filing = (array)(($context['services'] ?? [])['companies_house_ixbrl'] ?? []);
        $filingKind = strtolower(trim((string)($filing['filing_kind'] ?? '')));
        $filingLabel = in_array($filingKind, ['original', 'revised'], true)
            ? ucfirst($filingKind)
            : 'Unclassified';
        $submission = is_array($filing['submission'] ?? null) ? $filing['submission'] : null;
        $artifact = (array)($filing['prepared_artifact'] ?? []);
        $baseRun = (array)($context['ixbrl']['latest_run'] ?? []);
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $arelleStatus = (array)($readiness['arelle_status'] ?? []);
        $revisedValidation = (array)($filing['revised_validation'] ?? []);
        $artifactCurrent = !array_key_exists('state', $artifact)
            ? trim((string)($artifact['filename'] ?? '')) !== ''
            : (!empty($artifact['current']) || (string)$artifact['state'] === 'current');
        if ($submission === null || $artifact === [] || !$artifactCurrent) {
            $canPrepare = !empty($readiness['ready_for_filing']) && !empty($filing['can_prepare']);
            $blockers = array_values(array_unique(array_filter(array_map(
                static fn(mixed $message): string => trim((string)$message),
                (array)($filing['preparation_blockers'] ?? [])
            ), static fn(string $message): bool => $message !== '')));
            $blockersHtml = '';
            foreach ($blockers as $blocker) {
                $blockersHtml .= '<div class="helper ixbrl-companies-house-prepare-blocker">' . \eel_accounts\Support\Utf8::html($blocker) . '</div>';
            }
            return '<section class="panel-soft"><div class="status-head">'
                . '<h3 class="card-title">Companies House ' . \eel_accounts\Support\Utf8::html($filingLabel) . ' Accounting iXBRL</h3>'
                . '<span class="badge muted">Not Generated</span></div>'
                . '<div class="helper ixbrl-complete-filing-set-helper">Prepare the Companies House-specific accounts iXBRL from the approved '
                . \eel_accounts\Support\Utf8::html($filingLabel) . ' filing basis. This does not transmit it.</div>'
                . $this->arelleOutput($revisedValidation, [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'scope' => 'companies_house',
                    'submission_id' => (int)($submission['id'] ?? 0),
                ])
                . $blockersHtml
                . '<form method="post" action="?page=disclosures" data-ajax="true" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
                . '<input type="hidden" name="intent" value="prepare_accounts">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '">'
                . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<button class="button primary" type="submit" data-processing-text="Generating Companies House iXBRL…" data-processing-state="disabled"' . ($canPrepare ? '' : ' disabled') . '>Generate Companies House iXBRL</button>'
                . '</form></section>';
        }

        $lifecycle = strtolower(trim((string)($submission['lifecycle'] ?? 'prepared')));
        $artifactPath = trim((string)($artifact['path'] ?? ''));
        $artifactExists = $artifactPath !== '' && is_file($artifactPath);
        $artifactDownload = '<form method="post" action="?page=disclosures">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="download_accounts_ixbrl">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<button class="button compact primary" type="submit"' . ($artifactExists ? '' : ' disabled') . '>Download Companies House iXBRL</button>'
            . '</form>';
        $regenerate = '<form method="post" action="?page=disclosures" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="prepare_accounts">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<button class="button primary" type="submit" data-processing-text="Generating Companies House iXBRL…" data-processing-state="disabled"' . (!empty($readiness['ready_for_filing']) && !empty($filing['can_prepare']) ? '' : ' disabled') . '>Generate Companies House iXBRL</button>'
            . '</form>';
        $badge = match ($lifecycle) {
            'accepted' => 'success',
            'rejected', 'failed', 'internal_failure' => 'danger',
            'transport_unknown', 'parked' => 'warning',
            default => 'info',
        };
        return '<section class="panel-soft"><div class="status-head">'
            . '<h3 class="card-title">Companies House ' . \eel_accounts\Support\Utf8::html($filingLabel) . ' Accounting iXBRL</h3>'
            . '<span class="badge ' . $badge . '">'
            . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey($lifecycle, '_')) . '</span></div>'
            . '<div class="helper ixbrl-complete-filing-set-helper">This is the prepared Companies House '
            . \eel_accounts\Support\Utf8::html($filingLabel) . '-accounts iXBRL artifact. It has not been transmitted by this page.</div>'
            . '<div class="summary-grid">'
            . $this->metric('Generated At', (string)($submission['prepared_at'] ?? ''))
            . $this->metric('Facts', (string)(int)($artifact['fact_count'] ?? $revisedValidation['fact_count'] ?? 0))
            . $this->metric('Export Type', $this->exportTypeLabel((string)($baseRun['export_type'] ?? '')))
            . $this->metric('Validation', $this->validationLabel((string)($baseRun['validation_status'] ?? 'not_run')))
            . $this->metric('Arelle Status', !empty($arelleStatus['installed']) ? 'Installed' : 'Not Installed')
            . $this->metric('Arelle Validation', $this->validationLabel((string)($revisedValidation['status'] ?? $baseRun['external_validation_status'] ?? 'not_run')))
            . $this->metric('Arelle Validated At', (string)($revisedValidation['validated_at'] ?? $baseRun['external_validated_at'] ?? ''))
            . $this->metric('Submission number', (string)($submission['submission_number'] ?? 'Allocated on send'))
            . $this->metricHtml('Artifact', $artifactDownload)
            . '</div>'
            . $this->arelleOutput($revisedValidation, [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'scope' => 'companies_house',
                'submission_id' => (int)($submission['id'] ?? 0),
            ])
            . '<div class="actions-row">' . $regenerate . '</div>'
            . '</section>';
    }

    /** @param list<string> $blockers */
    private function generationBlockers(array $blockers): string
    {
        if ($blockers === []) {
            return '<div class="helper">Resolve the accounts filing-basis requirements before generating iXBRL.</div>';
        }

        $html = '<div class="helper"><strong>Generation requirements</strong><ul>';
        foreach ($blockers as $blocker) {
            $html .= '<li>' . \eel_accounts\Support\Utf8::html($blocker) . '</li>';
        }

        return $html . '</ul></div>';
    }

    private function generationBlockerPanel(array $context): string
    {
        $groups = [];
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $filingApproval = (array)($readiness['filing_approval'] ?? []);
        $filingApprovalState = trim((string)($filingApproval['state'] ?? ''));
        if ($filingApprovalState !== '' && $filingApprovalState !== 'current') {
            $yearEndLocked = !array_key_exists('year_end_locked', $readiness)
                || !empty($readiness['year_end_locked']);
            $nextStep = $yearEndLocked
                ? 'Approve the Accounts Disclosures filing basis.'
                : 'Complete and lock Year End, then approve the Accounts Disclosures filing basis.';
            return '<section class="panel-soft warn ixbrl-generation-blockers"><div class="status-head">'
                . '<h3 class="card-title">iXBRL generation blocked</h3>'
                . '<span class="badge warning">Action required</span></div>'
                . '<div class="helper"><strong>' . \eel_accounts\Support\Utf8::html($nextStep) . '</strong> '
                . 'This is the next step before generating HMRC Accounting, Corporation Tax, or Companies House iXBRL.</div>'
                . '</section>';
        }
        if (empty($readiness['can_generate'])) {
            $groups['HMRC Accounting iXBRL'] = $this->uniqueMessages(
                (array)($readiness['generation_errors'] ?? []),
                'The Accounting iXBRL is not ready to generate.'
            );
        }

        $periods = (array)($context['ixbrl']['computation_periods'] ?? []);
        if ($periods === []) {
            $groups['Corporation Tax computations'] = ['No CT periods are available for computations generation.'];
        } else {
            foreach ($periods as $item) {
                $period = (array)($item['ct_period'] ?? []);
                $status = (array)($item['status'] ?? []);
                if (!empty($status['ready'])) {
                    continue;
                }

                $ctPeriodNumber = (int)($period['sequence_no'] ?? $period['ct_period_sequence_no']
                    ?? $period['ct_period_id'] ?? $period['id'] ?? 0);
                $label = $ctPeriodNumber > 0
                    ? 'Corporation Tax Period ' . $ctPeriodNumber . ' iXBRL'
                    : 'Corporation Tax iXBRL';
                $groups[$label] = $this->uniqueMessages(
                    (array)($status['errors'] ?? []),
                    'This Corporation Tax period is not ready to generate iXBRL.'
                );
            }
        }

        $filing = (array)(($context['services'] ?? [])['companies_house_ixbrl'] ?? []);
        if (!empty($filing['filing_required']) && !$this->companiesHouseArtifactReady($context)) {
            $groups['Companies House Accounting iXBRL'] = $this->uniqueMessages(
                (array)($filing['preparation_blockers'] ?? []),
                'The Companies House Accounting iXBRL is not ready to generate.'
            );
        }

        if ($groups === []) {
            return '';
        }

        $html = '<section class="panel-soft warn ixbrl-generation-blockers"><div class="status-head">'
            . '<h3 class="card-title">iXBRL generation blocked</h3>'
            . '<span class="badge warning">Action required</span></div>'
            . '<div class="helper">Resolve the following before generating the complete iXBRL filing set.</div><ul>';
        foreach ($groups as $label => $messages) {
            foreach ($messages as $message) {
                $html .= '<li><strong>' . \eel_accounts\Support\Utf8::html($label) . ':</strong> '
                    . \eel_accounts\Support\Utf8::html($message) . '</li>';
            }
        }

        return $html . '</ul></section>';
    }

    /** @return list<string> */
    private function uniqueMessages(array $messages, string $fallback): array
    {
        $messages = array_values(array_unique(array_filter(array_map(
            static fn(mixed $message): string => trim((string)$message),
            $messages
        ), static fn(string $message): bool => $message !== '')));

        return $messages !== [] ? $messages : [$fallback];
    }

    private function computationPeriods(array $context, int $companyId, int $accountingPeriodId): string
    {
        $periods = (array)($context['ixbrl']['computation_periods'] ?? []);
        $accountsGenerationReady = !empty($context['ixbrl']['readiness']['can_generate']);
        $html = '';
        if ($periods === []) {
            return $html . '<div class="notice warning">No CT periods are available for computations generation.</div>';
        }
        foreach ($periods as $item) {
            $period = (array)($item['ct_period'] ?? []);
            $status = (array)($item['status'] ?? []);
            $run = (array)($status['run'] ?? []);
            $ctPeriodId = (int)($period['ct_period_id'] ?? $period['id'] ?? 0);
            $ctPeriodNumber = (int)($period['sequence_no'] ?? $period['ct_period_sequence_no'] ?? $ctPeriodId);
            $ctPeriodLabel = 'Corporation Tax Period ' . $ctPeriodNumber;
            $start = (string)($period['period_start'] ?? '');
            $end = (string)($period['period_end'] ?? '');
            $ready = $accountsGenerationReady && !empty($status['ready']);
            $fresh = !empty($status['fresh']);
            $fileable = !empty($status['fileable']);
            $artifactPath = trim((string)($run['generated_path'] ?? ''));
            $artifactExists = $artifactPath !== '' && is_file($artifactPath);
            $hidden = HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Ixbrl">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '">'
                . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">';
            $artifact = trim((string)($run['generated_filename'] ?? '')) !== ''
                ? '<form method="post" action="?page=disclosures">' . $hidden
                    . '<input type="hidden" name="intent" value="download_computation_ixbrl">'
                    . '<button class="button compact primary" type="submit"' . ($fileable && $artifactExists ? '' : ' disabled') . '>Download ' . \eel_accounts\Support\Utf8::html($ctPeriodLabel) . ' iXBRL</button></form>'
                : 'Not generated';
            $html .= '<section class="panel-soft"><div class="status-head"><h3>' . \eel_accounts\Support\Utf8::html($ctPeriodLabel) . ' iXBRL</h3><span class="badge '
                . ($fileable ? 'success' : ($fresh ? 'warning' : 'muted')) . '">'
                . ($fileable ? 'Filing ready' : ($fresh ? 'Generated, not fileable' : 'Not generated')) . '</span></div>'
                . '<div class="helper ixbrl-complete-filing-set-helper">Generate a separate Corporation Tax computation iXBRL for this filing period and review its validation status.</div>'
                . '<div class="summary-grid four">'
                . $this->metric('CT period', $start . ' to ' . $end)
                . $this->metric('Internal validation', $this->validationLabel((string)($run['validation_status'] ?? 'not_run')))
                . $this->metric('Arelle validation', $this->validationLabel((string)($run['external_validation_status'] ?? 'not_run')))
                . $this->metricHtml('Artifact', $artifact)
                . '</div>'
                . $this->arelleOutput($run, [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'scope' => 'computation',
                    'run_id' => (int)($run['id'] ?? 0),
                    'ct_period_id' => $ctPeriodId,
                ]);
            $errors = array_values(array_unique(array_merge((array)($status['errors'] ?? []), (array)($status['artifact_errors'] ?? []))));
            $staleArtifactErrors = [
                'The computation artifact filing basis is stale.',
                'The computation taxonomy package is stale, changed or incompatible.',
                'The computation mapping profile is stale or changed.',
                'The computation artifact file is missing or has changed.',
            ];
            if (array_intersect($errors, $staleArtifactErrors) !== []) {
                $errors = array_values(array_diff($errors, $staleArtifactErrors));
                $errors[] = 'Corporation Tax iXBRL needs to be regenerated because its filing basis, taxonomy package, mapping profile, or artifact file is no longer current.';
            }
            foreach ($errors as $error) {
                $html .= '<div class="helper ixbrl-computation-helper">' . \eel_accounts\Support\Utf8::html((string)$error) . '</div>';
            }
            $html .= '<div class="form-row-actions"><form method="post" action="?page=disclosures" data-ajax="true">' . $hidden
                . '<input type="hidden" name="intent" value="generate_computation_ixbrl"><button class="button primary" type="submit"'
                . ($ready ? '' : ' disabled') . '>Generate ' . \eel_accounts\Support\Utf8::html($ctPeriodLabel) . ' iXBRL</button></form>';
            $html .= '</div></section>';
        }
        return $html;
    }

    private function allComputationPeriodsReady(array $context): bool
    {
        $periods = (array)($context['ixbrl']['computation_periods'] ?? []);
        if ($periods === []) {
            return false;
        }

        foreach ($periods as $item) {
            $period = (array)($item['ct_period'] ?? []);
            $status = (array)($item['status'] ?? []);
            if ((int)($period['ct_period_id'] ?? $period['id'] ?? 0) <= 0 || empty($status['ready'])) {
                return false;
            }
        }

        return true;
    }

    private function companiesHouseArtifactReady(array $context): bool
    {
        $filing = (array)(($context['services'] ?? [])['companies_house_ixbrl'] ?? []);
        if (empty($filing['filing_required'])) {
            return true;
        }

        $artifact = (array)($filing['prepared_artifact'] ?? []);
        $artifactCurrent = !array_key_exists('state', $artifact)
            ? trim((string)($artifact['filename'] ?? '')) !== ''
            : (!empty($artifact['current']) || (string)$artifact['state'] === 'current');
        return ($artifactCurrent && trim((string)($artifact['filename'] ?? '')) !== '')
            || $this->companiesHouseCanPrepare($filing);
    }

    private function companiesHouseCanPrepare(array $filing): bool
    {
        return !empty($filing['can_prepare'])
            || !empty($filing['can_prepare_after_accounts_generation']);
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label) . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value) . '</div></div>';
    }

    private function metricHtml(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label) . '</div><div class="summary-value">' . $value . '</div></div>';
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

    private function internalValidationDetails(array $run): string
    {
        $internalErrors = json_decode((string)($run['validation_errors_json'] ?? '[]'), true);
        $groups = [
            'Internal errors' => is_array($internalErrors) ? $internalErrors : [],
        ];
        $html = '';
        foreach ($groups as $label => $messages) {
            if ($messages === []) {
                continue;
            }
            $items = '';
            foreach (array_slice($messages, 0, 20) as $message) {
                $items .= '<li>' . \eel_accounts\Support\Utf8::html(is_scalar($message) ? (string)$message : (string)\eel_accounts\Support\Utf8::json($message)) . '</li>';
            }
            $html .= '<section class="panel-soft"><h3>' . \eel_accounts\Support\Utf8::html($label) . '</h3>'
                . '<div class="helper ixbrl-complete-filing-set-helper">These are structural checks performed before external Arelle validation.</div>'
                . '<ul>' . $items . '</ul></section>';
        }

        return $html;
    }

    private function arelleOutput(array $result, array $download = []): string
    {
        $status = trim((string)($result['external_validation_status'] ?? $result['status'] ?? ''));
        $version = trim((string)($result['external_validator_version'] ?? $result['version'] ?? ''));
        $errors = $this->validationMessages($result['external_validation_errors_json'] ?? $result['errors'] ?? []);
        $warnings = $this->validationMessages($result['external_validation_warnings_json'] ?? $result['warnings'] ?? []);
        if ($status === '' || $status === 'not_run' || $status === 'not_configured') {
            return '';
        }

        $html = '<section class="panel-soft ixbrl-arelle-output">';
        if ($version !== '') {
            $html .= '<div class="helper">Arelle version: ' . \eel_accounts\Support\Utf8::html($version) . '</div>';
        }
        if ($errors === [] && $warnings === [] && $version === '') {
            $html .= '<div class="helper">Arelle validation: '
                . \eel_accounts\Support\Utf8::html($this->validationLabel($status)) . '</div>';
        }
        foreach (['Errors' => $errors, 'Warnings' => $warnings] as $label => $messages) {
            if ($messages === []) {
                continue;
            }
            $html .= '<h5>' . $label . '</h5><ul>';
            foreach (array_slice($messages, 0, 20) as $message) {
                $html .= '<li>' . $this->arelleDiagnosticHtml($message) . '</li>';
            }
            $html .= '</ul>';
        }
        $logPath = trim((string)($result['external_validation_log_path'] ?? $result['log_path'] ?? ''));
        if ($logPath !== '') {
            $companyId = (int)($download['company_id'] ?? 0);
            $accountingPeriodId = (int)($download['accounting_period_id'] ?? 0);
            $scope = trim((string)($download['scope'] ?? ''));
            $runId = (int)($download['run_id'] ?? 0);
            $ctPeriodId = (int)($download['ct_period_id'] ?? 0);
            $submissionId = (int)($download['submission_id'] ?? 0);
            $identityReady = $scope === 'companies_house' ? $submissionId > 0 : $runId > 0;
            if ($companyId > 0 && $accountingPeriodId > 0 && $scope !== '' && $identityReady) {
                $html .= '<form method="post" action="?page=disclosures" class="actions-row ixbrl-arelle-log-download">'
                    . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                    . '<input type="hidden" name="card_action" value="Ixbrl">'
                    . '<input type="hidden" name="intent" value="download_arelle_log">'
                    . '<input type="hidden" name="company_id" value="' . $companyId . '">'
                    . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                    . '<input type="hidden" name="arelle_scope" value="'
                    . \eel_accounts\Support\Utf8::html($scope) . '">'
                    . '<input type="hidden" name="run_id" value="' . $runId . '">'
                    . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">'
                    . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
                    . '<button class="button compact secondary" type="submit">Download Arelle Log</button>'
                    . '</form>';
            }
        }
        return $html . '</section>';
    }

    private function arelleDiagnosticHtml(mixed $diagnostic): string
    {
        if (!is_array($diagnostic) || trim((string)($diagnostic['code'] ?? '')) === '') {
            return \eel_accounts\Support\Utf8::html(
                is_scalar($diagnostic) ? (string)$diagnostic : (string)\eel_accounts\Support\Utf8::json($diagnostic)
            );
        }
        $severity = trim((string)($diagnostic['severity'] ?? 'unknown'));
        $code = trim((string)$diagnostic['code']);
        $message = trim((string)($diagnostic['message'] ?? ''));
        $location = [];
        $document = trim((string)($diagnostic['source_document'] ?? ''));
        if ($document !== '') {
            $location[] = 'File: ' . basename(str_replace('\\', '/', $document));
        }
        $line = (int)($diagnostic['line'] ?? 0);
        $column = (int)($diagnostic['column'] ?? 0);
        if ($line > 0) {
            $location[] = 'Line ' . $line . ($column > 0 ? ', column ' . $column : '');
        }
        $fact = trim((string)($diagnostic['fact_reference'] ?? ''));
        if ($fact !== '') {
            $location[] = 'Fact: ' . $fact;
        }
        $html = '<strong>' . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey($severity, '_')) . '</strong> '
            . '<code>' . \eel_accounts\Support\Utf8::html($code) . '</code>';
        if ($message !== '') {
            $html .= ' ' . \eel_accounts\Support\Utf8::html($message);
        }
        if ($location !== []) {
            $html .= '<div class="helper">' . \eel_accounts\Support\Utf8::html(implode(' · ', $location)) . '</div>';
        }

        return $html;
    }

    /** @return list<mixed> */
    private function validationMessages(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? array_values($decoded) : ($value === '' ? [] : [$value]);
        }
        return is_array($value) ? array_values($value) : [];
    }
}
