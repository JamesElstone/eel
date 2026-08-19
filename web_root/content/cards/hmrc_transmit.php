<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_transmitCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_transmit'; }

    public function title(): string { return 'HMRC Corporation Tax transmission'; }

    public function helper(array $context): string
    {
        return 'Transmit CT600 XML using the HMRC XML environment selected in Application API Credentials.';
    }

    public function services(): array
    {
        return [[
            'key' => 'hmrc_ct600_status',
            'service' => \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'method' => 'status',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['hmrc.ct600.submissions', 'ct.filing', 'page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '<div class="notice warning">The Corporation Tax filing status could not be loaded. '
            . \eel_accounts\Support\Utf8::html((string)($error['message'] ?? 'Review the application log and try again.'))
            . '</div>';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="notice warning">Select a company and accounting period before preparing a Corporation Tax return.</div>';
        }

        $status = (array)(($context['services'] ?? [])['hmrc_ct600_status'] ?? []);
        $periods = (array)($status['periods'] ?? []);
        $xmlEnvironment = strtoupper(trim((string)($status['xml_environment'] ?? 'DISABLED')));
        $controlsDisabled = $xmlEnvironment === 'DISABLED';
        $environments = (array)($status['environments'] ?? []);
        $credentialEnvironment = $xmlEnvironment === 'LIVE' ? 'TIL' : $xmlEnvironment;
        $credentialProfile = (array)($environments[$credentialEnvironment] ?? []);
        $credentialsConfigured = !empty($credentialProfile['credentials_configured']);
        $workflowNotice = match ($xmlEnvironment) {
            'TEST' => '',
            'LIVE' => '<div class="notice warning"><strong>HMRC Test in Live does not file the return.</strong> '
                . 'The LIVE action remains locked unless TIL accepted the same filing body and source manifest.</div>',
            default => '<div class="notice warning"><strong>HMRC XML transmission is disabled.</strong> '
                . 'Enable TEST or LIVE in Application API Credentials before using these controls.</div>',
        };
        $html = '<div class="settings-stack">'
            . $workflowNotice
            . $this->environmentSummary($status);

        foreach ($this->messages((array)($status['errors'] ?? [])) as $error) {
            $html .= '<div class="notice warning">' . \eel_accounts\Support\Utf8::html($error) . '</div>';
        }

        if ($periods === []) {
            return $html . '<div class="notice warning">No current CT periods are available for this accounting period.</div></div>';
        }

        foreach ($periods as $period) {
            $html .= $this->periodPanel(
                (array)$period,
                $companyId,
                $accountingPeriodId,
                $controlsDisabled,
                $credentialsConfigured
            );
        }

        return $html . '</div>';
    }

    private function environmentSummary(array $status): string
    {
        $environments = (array)($status['environments'] ?? []);
        $xmlEnvironment = strtoupper(trim((string)($status['xml_environment'] ?? 'DISABLED')));
        $credentialEnvironment = $xmlEnvironment === 'LIVE' ? 'TIL' : $xmlEnvironment;
        $profile = (array)($environments[$credentialEnvironment] ?? []);
        $environmentClass = match ($xmlEnvironment) {
            'TEST' => 'success',
            'LIVE' => 'warn',
            default => 'primary',
        };
        $environmentLabel = match ($xmlEnvironment) {
            'TEST' => 'Test',
            'LIVE' => 'Live',
            default => 'Disabled',
        };
        $html = '<section class="panel-soft"><div class="status-head"><h3 class="card-title">HMRC connection</h3></div>';

        $environmentBlockers = $this->messages((array)($profile['blockers'] ?? []));
        foreach (array_values(array_unique($environmentBlockers)) as $blocker) {
            $classes = 'helper' . ($this->isXmlCredentialBlocker($blocker)
                ? ' hmrc-connection-credential-helper'
                : '');
            $html .= '<div class="' . $classes . '">'
                . \eel_accounts\Support\Utf8::html($blocker) . '</div>';
        }

        $html .= '<div class="summary-grid">'
            . $this->environmentMetric($environmentLabel, $environmentClass);
        if ($xmlEnvironment !== 'DISABLED') {
            $html .= $this->credentialMetric($xmlEnvironment, $profile);
        }

        return $html . '</div></section>';
    }

    private function periodPanel(
        array $period,
        int $companyId,
        int $accountingPeriodId,
        bool $controlsDisabled,
        bool $credentialsConfigured
    ): string
    {
        $ctPeriodId = (int)($period['ct_period_id'] ?? $period['id'] ?? 0);
        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        $xmlEnvironment = strtoupper(trim((string)($period['xml_environment'] ?? 'DISABLED')));
        $testReady = !empty($period['test_ready']);
        $liveReady = !empty($period['live_ready']);
        $latestTest = (array)($period['latest_test_attempt'] ?? $period['latest_test'] ?? []);
        $latestTil = (array)($period['latest_til_attempt'] ?? $period['latest_test'] ?? []);
        $latestLive = (array)($period['latest_live_attempt'] ?? $period['latest_live'] ?? []);
        $pending = (array)($period['pending_submission'] ?? []);
        [$badgeClass, $badgeLabel] = $this->periodBadge($period);

        $irmark = trim((string)($latestLive['irmark'] ?? $latestTil['irmark'] ?? $latestTest['irmark'] ?? ''));
        $archiveSubmission = $pending !== [] ? $pending : ($latestLive !== [] ? $latestLive : ($latestTil !== [] ? $latestTil : $latestTest));
        $archive = (array)($archiveSubmission['transmission_archive'] ?? []);
        $sequence = max(1, (int)($period['display_sequence_no'] ?? $period['sequence_no'] ?? 0));
        $html = '<section class="panel-soft"><div class="status-head"><h3 class="card-title">CT Period '
            . $sequence . ' (' . \eel_accounts\Support\Utf8::html($start) . ' to ' . \eel_accounts\Support\Utf8::html($end) . '):'
            . '</h3><span class="badge ' . $badgeClass . '">' . \eel_accounts\Support\Utf8::html($badgeLabel) . '</span></div>'
            . '<div class="helper hmrc-ct-period-status-helper">This shows the current Status for this Corporation Tax Return</div>'
            . '<div class="summary-grid">'
            . $this->submissionStateMetric('Test In Live State', $latestTil, $this->stateCardClass($xmlEnvironment, 'TIL'))
            . $this->submissionStateMetric('Submission Result', $latestLive, $this->stateCardClass($xmlEnvironment, 'LIVE'))
            . $this->submissionStateMetric('Test Result', $latestTest, $this->stateCardClass($xmlEnvironment, 'TEST'))
            . '</div><div class="summary-grid">'
            . $this->metric('HMRC TIL Reference', $this->reference($latestTil))
            . $this->metric('HMRC Live Reference', $this->reference($latestLive))
            . $this->metric('Test Reference', $this->reference($latestTest))
            . '</div><div class="summary-grid">'
            . $this->metric('IRMARK', $irmark !== '' ? $irmark : 'Not generated')
            . $this->metric('HMRC Submission Evidence', $archive !== [] ? 'Captured and hashed' : 'Not created')
            . '</div>';

        $html .= '<div class="summary-grid four">' . $this->dependencyMetrics((array)($period['filing_dependencies'] ?? [])) . '</div>';

        $html .= $this->actionBlockers($period, $xmlEnvironment);

        if ($xmlEnvironment === 'TEST') {
            $testGatewayRejection = (array)($period['test_gateway_rejection'] ?? []);
            if ($testGatewayRejection !== []) {
                $html .= $this->gatewayRejectionPanel(
                    $testGatewayRejection,
                    'HMRC TEST',
                    'TEST'
                );
            }
        } elseif ($xmlEnvironment === 'LIVE') {
            $tilGatewayRejection = (array)($period['test_gateway_rejection'] ?? []);
            $liveGatewayRejection = (array)($period['live_gateway_rejection'] ?? []);
            if ($tilGatewayRejection !== []) {
                $html .= $this->gatewayRejectionPanel(
                    $tilGatewayRejection,
                    'HMRC Test-In-Live',
                    'LIVE'
                );
            }
            if ($liveGatewayRejection !== []) {
                $html .= $this->gatewayRejectionPanel(
                    $liveGatewayRejection,
                    'HMRC LIVE',
                    'LIVE'
                );
            }
        }

        $html .= $this->submissionForm(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $start,
            $end,
            $testReady,
            $liveReady,
            $period,
            $controlsDisabled,
            $credentialsConfigured
        );
        return $html . '</section>';
    }

    private function submissionForm(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $start,
        string $end,
        bool $testEnabled,
        bool $liveEnabled,
        array $period,
        bool $controlsDisabled,
        bool $credentialsConfigured
    ): string {
        $xmlEnvironment = strtoupper(trim((string)($period['xml_environment'] ?? 'DISABLED')));
        $dependenciesReady = $this->filingDependenciesReady((array)($period['filing_dependencies'] ?? []));
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        $periodLabel = trim($start . ' to ' . $end);
        $actions = [];
        if ($xmlEnvironment === 'TEST') {
            $actions[] = [
                'label' => 'Transmit Submission to Test',
                'intent' => 'hmrc_submit_test',
                'ready' => $testEnabled,
                'class' => ' primary',
                'title' => 'Transmit Corporation Tax return to HMRC Test',
                'environment' => 'HMRC TEST',
                'warning' => 'This sends tax return information to the HMRC test environment and does not file the return.',
                'confirm' => 'Transmit to Test',
                'gateway_rejection' => (array)($period['test_gateway_rejection'] ?? []),
                'retry_ready' => !empty($period['test_gateway_retry_ready']),
                'retry_intent' => 'hmrc_retry_test',
                'retry_label' => 'Retry Test Transmission',
            ];
        } elseif ($xmlEnvironment === 'LIVE') {
            $actions[] = [
                'label' => 'Transmit Submission to Test-In-Live',
                'intent' => 'hmrc_submit_test',
                'ready' => $testEnabled,
                'class' => ' primary',
                'title' => 'Transmit Corporation Tax return to HMRC Test-In-Live',
                'environment' => 'HMRC Test-In-Live',
                'warning' => 'This uses the configured LIVE credentials to validate the return. It does not file the return or send it to HMRC back-end systems.',
                'confirm' => 'Run Test-In-Live',
                'gateway_rejection' => (array)($period['test_gateway_rejection'] ?? []),
                'retry_ready' => !empty($period['test_gateway_retry_ready']),
                'retry_intent' => 'hmrc_retry_test',
                'retry_label' => 'Retry Test-In-Live Transmission',
            ];
            $actions[] = [
                'label' => 'Transmit Submission to Live',
                'intent' => 'hmrc_submit_live',
                'ready' => $liveEnabled,
                'class' => ' danger',
                'title' => 'Transmit Corporation Tax return to HMRC LIVE',
                'environment' => 'HMRC LIVE',
                'warning' => 'This is a statutory filing. It sends tax return information outside EEL Accounts and cannot be undone in this application.',
                'confirm' => 'Transmit LIVE Tax Return',
                'gateway_rejection' => (array)($period['live_gateway_rejection'] ?? []),
                'retry_ready' => !empty($period['live_gateway_retry_ready']),
                'retry_intent' => 'hmrc_retry_live',
                'retry_label' => 'Retry LIVE Transmission',
            ];
        }

        $html = '<section class="panel-soft"><form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
            . $this->hiddenFields($companyId, $accountingPeriodId, $ctPeriodId)
            . '<h3>Transmit Submission</h3>'
            . '<div class="actions-row">';
        foreach ($actions as $action) {
            $disabled = !empty($action['ready']) && !$controlsDisabled
                && $credentialsConfigured && $dependenciesReady ? '' : ' disabled';
            $html .= '<button class="button' . (string)$action['class'] . '" type="submit" name="intent" value="'
                . \eel_accounts\Support\Utf8::html((string)$action['intent']) . '"' . $disabled
                . ' data-chicken-check="true" data-chicken-title="'
                . \eel_accounts\Support\Utf8::html((string)$action['title']) . '"'
                . ' data-chicken-message="Transmit the CT600 for ' . \eel_accounts\Support\Utf8::html($periodLabel)
                . ' to ' . \eel_accounts\Support\Utf8::html((string)$action['environment'])
                . '?&lt;br&gt;&lt;br&gt;' . \eel_accounts\Support\Utf8::html((string)$action['warning']) . '"'
                . ' data-chicken-confirm-text="' . \eel_accounts\Support\Utf8::html((string)$action['confirm']) . '"'
                . ' data-chicken-button-class="button' . (string)$action['class'] . '"'
                . '>' . \eel_accounts\Support\Utf8::html((string)$action['label']) . '</button>';

            if ($developerOptions && (array)$action['gateway_rejection'] !== []) {
                $retryDisabled = !empty($action['retry_ready']) && !$controlsDisabled
                    && $credentialsConfigured && $dependenciesReady ? '' : ' disabled';
                $html .= '<button class="button danger" type="submit" name="intent" value="'
                    . \eel_accounts\Support\Utf8::html((string)$action['retry_intent']) . '"'
                    . $retryDisabled
                    . ' data-chicken-check="true" data-chicken-title="Retry HMRC transmission"'
                    . ' data-chicken-message="Create a new audited HMRC submission for the same CT600 body and transmit it to '
                    . \eel_accounts\Support\Utf8::html((string)$action['environment'])
                    . '?&lt;br&gt;&lt;br&gt;This sends tax return information outside EEL Accounts and cannot be undone in this application."'
                    . ' data-chicken-confirm-text="Retry Transmission" data-chicken-button-class="button danger"'
                    . '>' . \eel_accounts\Support\Utf8::html((string)$action['retry_label']) . '</button>';
            }
        }
        $html .= '</div></form>';

        if ($developerOptions) {
            $requestModes = match ($xmlEnvironment) {
                'TEST' => ['TEST' => 'Generate TEST Request File'],
                'LIVE' => [
                    'TEST' => 'Generate TEST Request File',
                    'TIL' => 'Generate Test-In-Live Request File',
                    'LIVE' => 'Generate LIVE Request File',
                ],
                default => [],
            };
            if ($requestModes !== []) {
                $requestDisabled = !$controlsDisabled && $dependenciesReady ? '' : ' disabled';
                $html .= '<div class="settings-stack"><h3>Generate Request Files</h3><div class="actions-row">';
                foreach ($requestModes as $mode => $label) {
                    $html .= '<form method="post" action="?page=transmit" data-ajax="true">'
                        . $this->hiddenFields($companyId, $accountingPeriodId, $ctPeriodId)
                        . '<input type="hidden" name="intent" value="hmrc_generate_request">'
                        . '<input type="hidden" name="request_environment" value="'
                        . \eel_accounts\Support\Utf8::html($mode) . '">'
                        . '<button class="button" type="submit"' . $requestDisabled . '>'
                        . \eel_accounts\Support\Utf8::html($label) . '</button></form>';
                }
                $html .= '</div></div>';
            }
        }

        return $html . '</section>';
    }

    private function gatewayRejectionPanel(
        array $submission,
        string $environmentLabel,
        string $credentialEnvironment
    ): string
    {
        $summary = trim((string)($submission['hmrc_response_summary'] ?? ''));
        if ($summary === '') {
            $summary = 'HMRC Gateway rejected the submission before opening a filing conversation.';
        }
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        $html = '<section class="panel-soft summary-card danger hmrc-gateway-rejection">'
            . '<div class="status-head"><h3 class="card-title">'
            . \eel_accounts\Support\Utf8::html($environmentLabel) . ' Gateway rejection</h3>'
            . '<span class="badge danger">Not transmitted</span></div>'
            . '<div class="helper">' . \eel_accounts\Support\Utf8::html($summary) . '</div>';
        if (str_contains($summary, '1046')) {
            $html .= '<div class="helper">Check the Sender ID and password for HMRC / XML / CT600_XML / '
                . \eel_accounts\Support\Utf8::html($credentialEnvironment) . '.</div>'
                . '<div class="actions-row actions-row-right"><a class="button" href="?page=settings&amp;show_card=api_keys_editor">'
                . 'Configure HMRC XML Credentials</a></div>';
        }
        if (!$developerOptions) {
            $html .= '<div class="helper">Ordinary resubmission is blocked. Enable Developer Options to expose the audited Retry Transmission action.</div>';
        }

        return $html . '</section>';
    }

    private function hiddenFields(int $companyId, int $accountingPeriodId, int $ctPeriodId): string
    {
        return HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="HmrcSubmission">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">';
    }

    private function periodBadge(array $period): array
    {
        if ((array)($period['pending_submission'] ?? []) !== []) {
            return ['warning', 'Awaiting HMRC'];
        }
        $liveOutcome = strtolower(trim((string)(($period['latest_live'] ?? [])['business_outcome'] ?? '')));
        if (in_array($liveOutcome, ['accepted', 'live_accepted'], true)) {
            return ['success', 'Filed'];
        }
        $xmlEnvironment = strtoupper(trim((string)($period['xml_environment'] ?? 'DISABLED')));
        if ($xmlEnvironment === 'LIVE') {
            if (!empty($period['live_ready'])) {
                return ['success', 'Ready for LIVE'];
            }
            if (!empty($period['test_ready'])) {
                return ['warning', 'Ready for Test-In-Live'];
            }
        } elseif ($xmlEnvironment === 'TEST' && !empty($period['test_ready'])) {
            return ['warning', 'Ready for TEST'];
        }
        return ['muted', 'Blocked'];
    }

    private function actionBlockers(array $period, string $xmlEnvironment): string
    {
        $fallback = (array)($period['blockers'] ?? []);
        $testBlockers = array_key_exists('test_blockers', $period)
            ? (array)$period['test_blockers']
            : $fallback;
        $liveBlockers = array_key_exists('live_blockers', $period)
            ? (array)$period['live_blockers']
            : $fallback;
        $groups = match ($xmlEnvironment) {
            'TEST' => ['TEST' => $testBlockers],
            'LIVE' => [
                'Test-In-Live' => $testBlockers,
                'LIVE' => $liveBlockers,
            ],
            default => [],
        };
        $html = '';
        foreach ($groups as $label => $items) {
            $blockers = array_values(array_unique(array_filter(
                $this->messages($items),
                fn(string $blocker): bool => !$this->isCardDependency($blocker)
                    && !$this->isXmlCredentialBlocker($blocker)
                    && !str_contains(strtolower($blocker), 'already passed')
            )));
            if ($blockers === []) {
                continue;
            }
            $html .= '<div class="summary-grid">';
            foreach ($blockers as $blocker) {
                $html .= $this->blockerMetric($label . ' blocker', $blocker);
            }
            $html .= '</div>';
        }

        return $html;
    }

    private function submissionLabel(array $submission): string
    {
        if ($submission === []) {
            return 'Not submitted';
        }
        $outcome = trim((string)($submission['business_outcome'] ?? ''));
        $value = $outcome !== '' && strtolower($outcome) !== 'none'
            ? $outcome
            : trim((string)($submission['protocol_state'] ?? $submission['status'] ?? ''));
        return match (strtolower($value)) {
            'til_validated', 'live_accepted', 'accepted' => 'Accepted',
            'sandbox_passed' => 'Passed',
            'awaiting_poll' => 'Awaiting HMRC',
            'transport_uncertain' => 'Outcome uncertain',
            'rejected' => 'Rejected',
            '' => 'Submitted',
            default => HelperFramework::labelFromKey(strtolower($value), '_'),
        };
    }

    private function submissionStateMetric(string $label, array $submission, ?string $class = null): string
    {
        $outcome = strtolower(trim((string)($submission['business_outcome'] ?? '')));
        $successful = in_array($outcome, ['sandbox_passed', 'til_validated', 'live_accepted', 'accepted'], true);
        $value = $submission === [] ? 'Not attempted' : ($successful ? 'Successful' : 'Not successful');

        return $this->metric($label, $value, $class ?? ($successful ? 'success' : 'warn'));
    }

    private function stateCardClass(string $xmlEnvironment, string $submissionEnvironment): ?string
    {
        if ($xmlEnvironment === 'DISABLED') {
            return 'primary';
        }
        if ($xmlEnvironment === 'TEST' && in_array($submissionEnvironment, ['TIL', 'LIVE'], true)) {
            return 'primary';
        }
        if ($xmlEnvironment === 'LIVE' && $submissionEnvironment === 'TEST') {
            return 'primary';
        }

        return null;
    }

    private function reference(array $submission): string
    {
        return trim((string)($submission['hmrc_reference']
            ?? $submission['hmrc_submission_reference']
            ?? $submission['hmrc_correlation_id']
            ?? $submission['correlation_id']
            ?? '')) ?: 'Not issued';
    }

    private function dependencyMetrics(array $dependencies): string
    {
        $byLabel = [];
        foreach ($dependencies as $dependency) {
            if (is_array($dependency)) {
                $byLabel[(string)($dependency['label'] ?? '')] = $dependency;
            }
        }
        $html = '';
        foreach ([
            'Disclosures and filing basis',
            'CT-period filing basis',
            'CT600 source model',
            'Filing iXBRL artifacts',
        ] as $label) {
            $dependency = (array)($byLabel[$label] ?? []);
            $ready = !empty($dependency['ready']);
            $message = trim((string)($dependency['message'] ?? ''));
            $detail = trim((string)($dependency['detail'] ?? ''));
            $html .= $this->dependencyMetric($label, $ready, $message, $detail);
        }

        return $html;
    }

    private function isCardDependency(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'ct600 source model')
            || str_contains($message, 'ct-period filing basis')
            || str_contains($message, 'current disclosures and filing basis')
            || str_contains($message, 'current filing ixbrl artifacts');
    }

    private function isXmlCredentialBlocker(string $message): bool
    {
        return str_starts_with(strtolower(trim($message)), 'api credential not found for hmrc / xml / ct600_xml /');
    }

    private function credentialMetric(string $environment, array $profile): string
    {
        if (!empty($profile['credentials_configured'])) {
            return $this->metric('Credentials', 'Configured', 'success');
        }

        return '<div class="summary-card danger hmrc-credential-summary-card"><div class="summary-label">Credentials</div>'
            . '<div class="helper">HMRC / XML / CT600_XML / '
            . \eel_accounts\Support\Utf8::html($environment) . ' Credentials Missing</div>'
            . '<div class="actions-row actions-row-right hmrc-credential-summary-actions"><a class="button" href="?page=settings&amp;show_card=api_keys_editor">Configure HMRC XML Credentials</a></div></div>';
    }

    private function environmentMetric(string $environment, string $class): string
    {
        $badgeClass = match ($class) {
            'success' => 'success',
            'warn' => 'warning',
            'danger' => 'danger',
            default => 'info',
        };

        return '<section class="panel-soft summary-card ' . \eel_accounts\Support\Utf8::html($class)
            . ' hmrc-connection-summary-card hmrc-transmit-status-board">'
            . '<div class="status-head"><h3 class="card-title">Environment</h3>'
            . '<span class="badge ' . $badgeClass . '">' . \eel_accounts\Support\Utf8::html($environment) . '</span></div>'
            . '<div class="actions-row actions-row-right hmrc-connection-summary-actions"><a class="button" href="?page=settings&amp;show_card=api_mode">Configure HMRC XML Environment</a></div></section>';
    }

    private function metric(string $label, string $value, string $class = '', bool $helper = false): string
    {
        $classes = 'summary-card' . ($class !== '' ? ' ' . $class : '');
        $contentClass = $helper ? 'helper' : 'summary-value';
        return '<div class="' . \eel_accounts\Support\Utf8::html($classes) . '"><div class="summary-label">'
            . \eel_accounts\Support\Utf8::html($label) . '</div><div class="' . $contentClass . '">'
            . \eel_accounts\Support\Utf8::html($value) . '</div></div>';
    }

    private function dependencyMetric(string $label, bool $ready, string $message, string $detail): string
    {
        if ($ready) {
            return '<section class="panel-soft summary-card success hmrc-transmit-status-board">'
                . '<div class="status-head"><h3 class="card-title">'
                . \eel_accounts\Support\Utf8::html($label) . '</h3>'
                . '<span class="badge success">Present</span></div></section>';
        }

        $html = '<section class="panel-soft summary-card danger hmrc-transmit-status-board">'
            . '<div class="status-head"><h3 class="card-title">'
            . \eel_accounts\Support\Utf8::html($label) . '</h3>'
            . '<span class="badge danger">Not ready</span></div>';
        if ($message !== '') {
            $html .= '<div class="helper">' . \eel_accounts\Support\Utf8::html($message) . '</div>';
        }
        if ($detail !== '' && $detail !== $message) {
            $html .= '<div class="helper">' . \eel_accounts\Support\Utf8::html($detail) . '</div>';
        }

        return $html . '</section>';
    }

    private function blockerMetric(string $label, string $message): string
    {
        return '<div class="summary-card danger"><div class="summary-label">'
            . \eel_accounts\Support\Utf8::html($label) . '</div>'
            . '<div class="helper">' . \eel_accounts\Support\Utf8::html($message) . '</div></div>';
    }

    private function filingDependenciesReady(array $dependencies): bool
    {
        $byLabel = [];
        foreach ($dependencies as $dependency) {
            if (is_array($dependency)) {
                $byLabel[(string)($dependency['label'] ?? '')] = $dependency;
            }
        }
        foreach ([
            'Disclosures and filing basis',
            'CT-period filing basis',
            'CT600 source model',
            'Filing iXBRL artifacts',
        ] as $label) {
            if (empty($byLabel[$label]['ready'])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function messages(array $items): array
    {
        $messages = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $message = trim((string)$item);
            } elseif (is_array($item)) {
                $message = trim((string)($item['message'] ?? $item['detail'] ?? $item['label'] ?? ''));
            } else {
                $message = '';
            }
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        return $messages;
    }
}
