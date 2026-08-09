<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_house_transmitCard extends CardBaseFramework
{
    public function key(): string { return 'companies_house_transmit'; }

    public function title(): string { return 'Companies House accounts transmission'; }

    public function helper(array $context): string
    {
        return 'This card sends data externally to the Companies House public register. Only generated companies-house iXBRL files can be sent.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companies_house_transmit_context',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'fetchTransmissionContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'companies_house_schema_status',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSchemaService::class,
                'method' => 'fetchOperationStatus',
                'params' => ['operation' => 'company_data'],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['companies.house.accounts.submission', 'page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->warningPanel([
                'Select a company and accounting period before transmitting accounts.',
            ]);
        }

        $model = (array)(($context['services'] ?? [])['companies_house_transmit_context'] ?? []);
        if ($model === []) {
            return $this->warningPanel([
                'The Companies House transmission status could not be loaded.',
            ]);
        }
        $schemaStatus = (array)(($context['services'] ?? [])['companies_house_schema_status'] ?? []);
        $schemaState = (array)($schemaStatus['state'] ?? []);
        $schemaReady = !empty($schemaState['ready']);
        $schemaError = trim((string)($schemaState['error'] ?? ''));
        if ($schemaError === '') {
            $schemaError = 'The installed Companies House XML schema status could not be verified.';
        }

        $feature = (array)($model['feature'] ?? []);
        $sequence = (array)($model['sequence'] ?? []);
        $submission = is_array($model['submission'] ?? null) ? $model['submission'] : null;
        $activeSubmission = is_array($model['active_submission'] ?? null)
            ? (array)$model['active_submission']
            : null;
        $filingKind = strtolower(trim((string)($submission['filing_kind'] ?? $model['filing_kind'] ?? '')));
        $artifact = (array)($model['prepared_artifact'] ?? []);
        $preflight = is_array($model['preflight'] ?? null) ? (array)$model['preflight'] : null;
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        $lifecycle = strtolower(trim((string)($submission['lifecycle'] ?? 'not_prepared')));
        $warningMessages = [];
        $transmitForm = null;
        $html = '<div class="settings-stack">'
            . '<section class="panel-soft"><div class="status-head"><h3 class="card-title">Companies House Connection</h3>'
            . '<span class="badge ' . (!empty($feature['credentials_configured']) ? 'success' : 'warning') . '">'
            . (!empty($feature['credentials_configured']) ? 'Configured' : 'Unavailable') . '</span></div>'
            . '<div class="summary-grid companies-house-connection-summary-grid">'
            . $this->environmentMetric((string)($feature['mode'] ?? 'DISABLED'))
            . $this->credentialMetric(
                (string)($feature['mode'] ?? 'DISABLED'),
                !empty($feature['credentials_configured'])
            )
            . $this->metric('Next submission number', (string)($sequence['next_number'] ?? 'Unavailable'))
            . $this->metric('Last issued submission number', (string)($sequence['last_issued_number'] ?? 'None'))
            . '</div></section>';

        $html .= '<section class="panel-soft"><div class="status-head"><h3 class="card-title">Prepared transmission</h3>'
            . '<span class="badge ' . $this->badge($lifecycle) . '">'
            . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey($lifecycle, '_')) . '</span></div>';
        if ($submission === null) {
            $warningMessages[] = 'No Companies House accounts artifact is prepared. '
                . 'Generate and validate the Companies House iXBRL for the current Disclosure Approval.';
            $html .= '<div class="summary-grid companies-house-prepared-transmission-summary-grid">'
                . $this->artifactDownloadMetric($companyId, $accountingPeriodId, false)
                . '</div>';
            $transmitForm = [
                'submission_id' => 0,
                'mode' => strtoupper((string)($feature['mode'] ?? 'TEST')),
                'disabled' => true,
            ];
        } else {
            $artifactCurrent = !array_key_exists('state', $artifact)
                ? !empty($artifact['filename'])
                : (!empty($artifact['current']) || (string)$artifact['state'] === 'current');
            if (!$artifactCurrent && $lifecycle === 'prepared') {
                $warningMessages[] = (string)(($artifact['errors'] ?? [])[0]
                    ?? 'This prepared artifact is historical and cannot be submitted for the current filing basis.');
            }
            $html .= '<div class="summary-grid companies-house-prepared-transmission-summary-grid">'
                . $this->metric('Filing classification', ucfirst($filingKind))
                . $this->artifactDownloadMetric(
                    $companyId,
                    $accountingPeriodId,
                    $artifactCurrent
                )
                . $this->schemaMetric($schemaReady, $schemaState)
                . '</div>';
            if ($lifecycle === 'prepared') {
                $transmitForm = [
                    'submission_id' => (int)$submission['id'],
                    'mode' => strtoupper((string)($feature['mode'] ?? 'TEST')),
                    'disabled' => empty($model['can_submit']) || !$schemaReady,
                ];
            } elseif ($lifecycle === 'accepted'
                && trim((string)($submission['document_request_key'] ?? '')) !== ''
                && trim((string)($submission['returned_document_sha256'] ?? '')) === '') {
                $html .= $this->refreshForm(
                    $companyId,
                    $accountingPeriodId,
                    (int)$submission['id'],
                    'Continue and Retrieve Accepted Document'
                );
                if ($developerOptions) {
                    $html .= $this->simpleProtocolForm(
                        $companyId,
                        $accountingPeriodId,
                        (int)$submission['id'],
                        'retrieve_accounts_document',
                        'Get Filed Document'
                    );
                }
            }
        }
        foreach ((array)($model['submission_blockers'] ?? []) as $blocker) {
            $warningMessages[] = (string)$blocker;
        }
        if (!$schemaReady && ($submission === null || $lifecycle === 'prepared')) {
            $warningMessages[] = 'Accounts transmission is blocked because ' . $schemaError;
        }
        $html .= '</section>';
        if ($activeSubmission !== null
            && (int)($activeSubmission['id'] ?? 0) !== (int)($submission['id'] ?? 0)) {
            $activeIdentifier = trim((string)($activeSubmission['submission_number'] ?? ''));
            $activeIdentifier = $activeIdentifier !== ''
                ? $activeIdentifier
                : '#' . (int)($activeSubmission['id'] ?? 0);
            $activeLifecycle = strtolower(trim((string)($activeSubmission['lifecycle'] ?? 'pending')));
            $html .= '<section class="panel-soft warn companies-house-active-earlier-submission">'
                . '<div class="status-head"><h3 class="card-title">Active earlier submission</h3>'
                . '<span class="badge ' . $this->badge($activeLifecycle) . '">'
                . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey($activeLifecycle, '_'))
                . '</span></div><div class="summary-grid">'
                . $this->metric('Submission number', $activeIdentifier)
                . $this->metric('Environment', (string)($activeSubmission['environment'] ?? $feature['mode'] ?? ''))
                . '</div><div class="helper">This immutable submission belongs to an earlier prepared basis. '
                . 'Continue checking it in GovTalk Transmission History. It blocks another Companies House transmission, '
                . 'but does not block preparation of the current Companies House or HMRC artifacts.</div></section>';
        }
        $html .= $this->submitForm(
            $companyId,
            $accountingPeriodId,
            $filingKind,
            is_array($transmitForm) ? $transmitForm : null,
            $warningMessages
        );
        if ($developerOptions) {
            $html .= $this->developerConnectionControls(
                $companyId,
                $accountingPeriodId,
                $preflight,
                $schemaReady,
                $schemaError
            );
        }
        $html .= '</div>';

        return $html;
    }

    private function submitForm(
        int $companyId,
        int $accountingPeriodId,
        string $filingKind,
        ?array $transmitForm,
        array $warnings = []
    ): string
    {
        $filingKind = in_array($filingKind, ['original', 'revised'], true) ? $filingKind : 'accounts';
        $hasWarnings = array_filter(array_map(
            static fn(mixed $warning): string => trim((string)$warning),
            $warnings
        )) !== [];
        $html = '<section class="panel-soft"><h3 class="card-title">'
            . 'Transmit Company accounts to Companies House Public Register.</h3>'
            . (!$hasWarnings ? $this->sectionHelper(
                'Enter the six-character company authentication code to transmit the prepared statutory accounts.'
            ) : '')
            . $this->warningPanel($warnings);
        if ($transmitForm === null) {
            return $html . '</section>';
        }

        $submissionId = (int)($transmitForm['submission_id'] ?? 0);
        $mode = strtoupper((string)($transmitForm['mode'] ?? 'TEST'));
        $disabled = !empty($transmitForm['disabled']);
        $filingLabel = ucfirst($filingKind);
        $confirmationPhrase = 'SUBMIT LIVE ' . strtoupper($filingKind) . ' ACCOUNTS';
        $live = $mode === 'LIVE'
            ? '<label class="checkbox-row"><input type="checkbox" name="authority_confirmed" value="1" required> '
                . '<span>I am authorised to file these statutory accounts.</span></label>'
                . '<label><span>Type <strong>' . \eel_accounts\Support\Utf8::html($confirmationPhrase)
                . '</strong> to confirm</span>'
                . '<input class="input" type="text" name="live_confirmation_phrase" required autocomplete="off"></label>'
            : '';

        return $html
            . '<form method="post" action="?page=transmit" data-ajax="true" '
            . 'class="settings-stack companies-house-transmit-form">'
            . $this->hidden($companyId, $accountingPeriodId, 'submit_accounts')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . $this->companyAuthenticationCodeField()
            . $live
            . '<button class="button danger" type="submit" data-chicken-check="true" '
            . 'data-chicken-title="Send ' . \eel_accounts\Support\Utf8::html($filingLabel) . ' accounts" '
            . 'data-chicken-message="Send this immutable ' . \eel_accounts\Support\Utf8::html($filingKind) . '-accounts package to Companies House '
            . \eel_accounts\Support\Utf8::html($mode) . '?" data-chicken-confirm-text="Send accounts"'
            . ($disabled ? ' disabled aria-disabled="true"' : '') . '>'
            . 'Transmit Company Accounts</button></form></section>';
    }

    private function refreshForm(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        string $label = 'Send / Continue Companies House Filing'
    ): string
    {
        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . $this->hidden($companyId, $accountingPeriodId, 'refresh_accounts_status')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button" type="submit">' . \eel_accounts\Support\Utf8::html($label) . '</button></form>';
    }

    private function developerConnectionControls(
        int $companyId,
        int $accountingPeriodId,
        ?array $preflight,
        bool $schemaReady,
        string $schemaError
    ): string {
        $html = '<section class="panel-soft"><h3 class="card-title">Test Companies House Connection</h3>'
            . $this->sectionHelper(
                'Optionally check the presenter and company authentication values with Companies House CompanyData. This diagnostic is not required before transmitting Accounts.'
            );
        if (!$schemaReady) {
            $html .= $this->warningPanel([
                'The company authentication-code check is blocked because ' . $schemaError,
            ]);
        }
        $verified = is_array($preflight)
            && (string)($preflight['outcome'] ?? '') === 'verified'
            && trim((string)($preflight['matched_company_number'] ?? '')) !== '';
        if ($verified) {
            $companyName = trim((string)($preflight['matched_company_name'] ?? ''));
            $html .= $this->authenticationCheckPanel(
                $companyName !== ''
                    ? 'Companies House returned matching data for <strong>'
                        . \eel_accounts\Support\Utf8::html($companyName) . '</strong>.'
                    : 'Companies House returned matching company data.',
                true,
                (string)($preflight['created_at'] ?? '')
            );
        } elseif (is_array($preflight) && trim((string)($preflight['outcome'] ?? '')) !== '') {
            $outcome = HelperFramework::labelFromKey((string)$preflight['outcome'], '_');
            $error = trim((string)($preflight['error_summary'] ?? ''));
            $html .= $this->authenticationCheckPanel(
                'Latest authentication check: ' . $outcome
                    . ($error !== '' ? '. ' . $error : ''),
                false,
                (string)($preflight['created_at'] ?? '')
            );
        }
        $html .= '<form method="post" action="?page=transmit" data-ajax="true" '
            . 'class="settings-stack companies-house-transmit-form">'
            . $this->hidden($companyId, $accountingPeriodId, 'preflight_accounts')
            . $this->companyAuthenticationCodeField()
            . '<button class="button primary" type="submit"'
            . (!$schemaReady ? ' disabled aria-disabled="true"' : '')
            . '>Check Company Authentication Code</button></form>';
        return $html . '</section>';
    }

    private function simpleProtocolForm(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        string $intent,
        string $label
    ): string {
        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . $this->hidden($companyId, $accountingPeriodId, $intent)
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button" type="submit">' . \eel_accounts\Support\Utf8::html($label) . '</button></form>';
    }

    private function hidden(int $companyId, int $accountingPeriodId, string $intent): string
    {
        return HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="' . \eel_accounts\Support\Utf8::html($intent) . '">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">';
    }

    private function badge(string $lifecycle): string
    {
        return match ($lifecycle) {
            'accepted' => 'success',
            'prepared', 'pending', 'submitting' => 'info',
            'transport_unknown', 'parked' => 'warning',
            'rejected', 'failed', 'internal_failure' => 'danger',
            default => 'muted',
        };
    }

    private function environmentMetric(string $environment): string
    {
        $environment = strtoupper(trim($environment));
        $class = match ($environment) {
            'TEST' => 'success',
            'LIVE' => 'warn',
            default => 'primary',
        };
        $label = match ($environment) {
            'TEST' => 'Test',
            'LIVE' => 'Live',
            default => 'Disabled',
        };

        $badgeClass = $environment === 'TEST' ? 'success' : ($environment === 'LIVE' ? 'warning' : 'info');

        return '<section class="panel-soft summary-card ' . $class
            . ' hmrc-connection-summary-card companies-house-status-board">'
            . '<div class="status-head"><h3 class="card-title">Environment</h3>'
            . '<span class="badge ' . $badgeClass . '">' . $label . '</span></div>'
            . '<div class="actions-row actions-row-right hmrc-connection-summary-actions">'
            . '<a class="button" href="?page=settings&amp;show_card=api_mode">'
            . 'Configure Companies House XML Environment</a></div></section>';
    }

    private function credentialMetric(string $environment, bool $configured): string
    {
        if ($configured) {
            return '<section class="panel-soft summary-card success hmrc-credential-summary-card companies-house-status-board">'
                . '<div class="status-head"><h3 class="card-title">Credentials</h3>'
                . '<span class="badge success">Configured</span></div>'
                . '<div class="actions-row actions-row-right hmrc-credential-summary-actions">'
                . '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">'
                . 'Configure Companies House XML Credentials</a></div></section>';
        }

        $environment = strtoupper(trim($environment));
        $environment = in_array($environment, ['TEST', 'LIVE'], true) ? $environment : 'selected';

        return '<section class="panel-soft summary-card danger hmrc-credential-summary-card companies-house-status-board">'
            . '<div class="status-head"><h3 class="card-title">Credentials</h3>'
            . '<span class="badge danger">Action required</span></div>'
            . '<div class="helper">Companies House XML accounts filing credentials are missing for the '
            . \eel_accounts\Support\Utf8::html($environment) . ' environment.</div>'
            . '<div class="actions-row actions-row-right hmrc-credential-summary-actions">'
            . '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">'
            . 'Configure Companies House XML Credentials</a></div></section>';
    }

    /** @param list<string> $messages */
    private function authenticationCheckPanel(string $message, bool $success, string $testedAt): string
    {
        $testedAt = trim($testedAt);
        return '<section class="panel-soft ' . ($success ? 'success' : 'warn')
            . ' full settings-stack companies-house-authentication-panel">'
            . '<div class="status-head"><h3 class="card-title">Company authentication check</h3>'
            . '<span class="badge ' . ($success ? 'success' : 'warning') . '">'
            . ($success ? 'Verified' : 'Action required') . '</span></div>'
            . '<div class="helper">' . $message . '</div>'
            . ($testedAt !== ''
                ? '<div class="stat-foot">Last tested: ' . \eel_accounts\Support\Utf8::html($testedAt) . '</div>'
                : '')
            . '</section>';
    }

    /** @param list<string> $messages */
    private function warningPanel(array $messages): string
    {
        $messages = array_values(array_unique(array_filter(array_map(
            static fn(mixed $message): string => trim((string)$message),
            $messages
        ))));
        if ($messages === []) {
            return '';
        }

        $items = '';
        foreach ($messages as $message) {
            $items .= '<li>' . \eel_accounts\Support\Utf8::html($message) . '</li>';
        }

        return '<section class="panel-soft warn full settings-stack companies-house-warning-panel">'
            . '<div class="status-head"><h3 class="card-title">Transmission warnings</h3>'
            . '<span class="badge warning">Action required</span></div>'
            . '<ul class="helper">' . $items . '</ul></section>';
    }

    private function sectionHelper(string $message): string
    {
        return '<div class="helper companies-house-transmit-section-helper">'
            . \eel_accounts\Support\Utf8::html($message) . '</div>';
    }

    private function companyAuthenticationCodeField(): string
    {
        return '<label><span>Company authentication code</span>'
            . '<input class="input companies-house-authentication-code-input" type="password" name="company_auth_code" minlength="6" maxlength="6" '
            . 'pattern="[A-Za-z0-9]{6}" title="Enter exactly six letters or numbers." '
            . 'required autocomplete="off" autocapitalize="none" spellcheck="false">'
            . '<span class="helper">Enter exactly six letters or numbers.</span></label>';
    }

    private function schemaMetric(bool $ready, array $state): string
    {
        $fileCount = (int)($state['file_count'] ?? 0);
        $detail = $ready
            ? $fileCount . ' verified schema file' . ($fileCount === 1 ? '' : 's') . ' installed.'
            : 'Refresh the installed schemas before checking credentials or transmitting accounts.';

        return '<section class="panel-soft summary-card companies-house-summary-action-card companies-house-status-board '
            . ($ready ? 'success' : 'danger') . '">'
            . '<div class="status-head"><h3 class="card-title">Companies House XML schemas</h3>'
            . '<span class="badge ' . ($ready ? 'success' : 'danger') . '">'
            . ($ready ? 'Verified' : 'Refresh required') . '</span></div>'
            . '<div class="helper">' . \eel_accounts\Support\Utf8::html($detail) . '</div>'
            . '<div class="actions-row companies-house-summary-card-actions">'
            . '<a class="button" href="?page=artefacts">Manage Filing Schemas</a></div></section>';
    }

    private function artifactDownloadMetric(
        int $companyId,
        int $accountingPeriodId,
        bool $available
    ): string {
        return '<section class="panel-soft summary-card companies-house-summary-action-card companies-house-status-board '
            . ($available ? 'success' : 'danger') . '">'
            . '<div class="status-head"><h3 class="card-title">Artifact</h3>'
            . '<span class="badge ' . ($available ? 'success' : 'danger') . '">'
            . ($available ? 'Available' : 'Unavailable') . '</span></div>'
            . '<form method="post" action="?page=transmit" class="actions-row companies-house-summary-card-actions">'
            . $this->hidden($companyId, $accountingPeriodId, 'download_accounts_ixbrl')
            . '<button class="button compact primary" type="submit"'
            . ($available ? '' : ' disabled')
            . '>Companies House iXBRL</button></form></section>';
    }

    private function metric(string $label, string $value, string $explanation = ''): string
    {
        $value = trim($value) !== '' ? $value : '—';
        return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label)
            . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value) . '</div>'
            . ($explanation !== ''
                ? '<div class="helper">' . \eel_accounts\Support\Utf8::html($explanation) . '</div>'
                : '')
            . '</div>';
    }

}
