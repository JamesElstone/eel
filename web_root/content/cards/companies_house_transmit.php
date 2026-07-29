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
        return 'Send only the immutable original or revised accounts artifact prepared from the locked Year End workflow. Submission numbers are allocated when Send is pressed, never during preparation.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companies_house_transmit_context',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'companies_house_transmit_history',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'submissionHistory',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
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
            return '<div class="notice warning">Select a company and accounting period before transmitting accounts.</div>';
        }

        $model = (array)(($context['services'] ?? [])['companies_house_transmit_context'] ?? []);
        $history = (array)(($context['services'] ?? [])['companies_house_transmit_history'] ?? []);
        if ($model === []) {
            return '<div class="notice warning">The Companies House transmission status could not be loaded.</div>';
        }

        $feature = (array)($model['feature'] ?? []);
        $sequence = (array)($model['sequence'] ?? []);
        $submission = is_array($model['submission'] ?? null) ? $model['submission'] : null;
        $filingKind = strtolower(trim((string)($submission['filing_kind'] ?? $model['filing_kind'] ?? '')));
        $artifact = (array)($model['prepared_artifact'] ?? []);
        $preflight = is_array($model['preflight'] ?? null) ? (array)$model['preflight'] : null;
        $statusCycle = is_array($model['status_cycle'] ?? null) ? (array)$model['status_cycle'] : null;
        $exchanges = (array)($model['exchanges'] ?? []);
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        $lifecycle = strtolower(trim((string)($submission['lifecycle'] ?? 'not_prepared')));
        $html = '<div class="settings-stack">'
            . '<section class="panel-soft"><div class="status-head"><h3 class="card-title">Companies House Connection</h3>'
            . '<span class="badge ' . (!empty($feature['credentials_configured']) ? 'success' : 'warning') . '">'
            . (!empty($feature['credentials_configured']) ? 'Configured' : 'Unavailable') . '</span></div>'
            . '<div class="summary-grid">'
            . $this->environmentMetric((string)($feature['mode'] ?? 'DISABLED'))
            . $this->credentialMetric(
                (string)($feature['mode'] ?? 'DISABLED'),
                !empty($feature['credentials_configured'])
            )
            . '</div>'
            . '<div class="summary-grid">'
            . $this->metric('Next submission number', (string)($sequence['next_number'] ?? 'Unavailable'))
            . $this->metric('Last issued number', (string)($sequence['last_issued_number'] ?? 'None'))
            . '</div></section>';

        $html .= '<section class="panel-soft"><div class="status-head"><h3 class="card-title">Prepared transmission</h3>'
            . '<span class="badge ' . $this->badge($lifecycle) . '">'
            . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey($lifecycle, '_')) . '</span></div>';
        if ($submission === null) {
            $html .= '<div class="notice warning">No Companies House accounts artifact is prepared. Generate it from the approved filing basis.</div>';
        } else {
            $archive = (array)($submission['transmission_archive'] ?? []);
            $artifactCurrent = !array_key_exists('state', $artifact)
                ? !empty($artifact['filename'])
                : (!empty($artifact['current']) || (string)$artifact['state'] === 'current');
            if (!$artifactCurrent && $lifecycle === 'prepared') {
                $html .= $this->transmissionMessage(
                    (string)(($artifact['errors'] ?? [])[0]
                        ?? 'This prepared artifact is historical and cannot be submitted for the current filing basis.')
                );
            }
            $html .= '<div class="summary-grid">'
                . $this->metric('Filing classification', ucfirst($filingKind))
                . $this->artifactDownloadMetric(
                    $companyId,
                    $accountingPeriodId,
                    $artifactCurrent
                )
                . $this->metric('Private archive', $archive !== [] ? 'Captured and hashed' : 'Created on send')
                . $this->metric(
                    'CompanyData preflight',
                    $preflight === null
                        ? 'Not run'
                        : HelperFramework::labelFromKey((string)$preflight['outcome'], '_')
                )
                . $this->metric(
                    'Status acknowledgement',
                    $statusCycle === null
                        ? 'Not required'
                        : HelperFramework::labelFromKey(
                            (string)$statusCycle['acknowledgement_state'],
                            '_'
                        )
                )
                . '</div>';
            foreach ((array)($model['submission_blockers'] ?? []) as $blocker) {
                $html .= $this->transmissionMessage((string)$blocker);
            }
            if ($lifecycle === 'prepared' && !empty($model['can_submit'])) {
                $html .= $this->submitForm(
                    $companyId,
                    $accountingPeriodId,
                    (int)$submission['id'],
                    strtoupper((string)($feature['mode'] ?? 'TEST')),
                    $filingKind
                );
                if ($developerOptions) {
                    $html .= $this->developerPreparedControls(
                        $companyId,
                        $accountingPeriodId,
                        (int)$submission['id'],
                        strtoupper((string)($feature['mode'] ?? 'TEST')),
                        $preflight,
                        !empty($feature['developer_binding_configured']),
                        $filingKind
                    );
                }
            } elseif (in_array($lifecycle, ['submitting', 'transport_unknown', 'pending', 'parked'], true)) {
                $html .= $this->refreshForm(
                    $companyId,
                    $accountingPeriodId,
                    (int)$submission['id'],
                    'Send / continue Companies House filing'
                );
                if ($developerOptions) {
                    $html .= $this->developerStatusControls(
                        $companyId,
                        $accountingPeriodId,
                        (int)$submission['id'],
                        $statusCycle
                    );
                }
            } elseif ($lifecycle === 'accepted'
                && trim((string)($submission['document_request_key'] ?? '')) !== ''
                && trim((string)($submission['returned_document_sha256'] ?? '')) === '') {
                $html .= $this->refreshForm(
                    $companyId,
                    $accountingPeriodId,
                    (int)$submission['id'],
                    'Continue and retrieve accepted document'
                );
                if ($developerOptions) {
                    $html .= $this->simpleProtocolForm(
                        $companyId,
                        $accountingPeriodId,
                        (int)$submission['id'],
                        'retrieve_accounts_document',
                        'Get filed document'
                    );
                }
            }
        }
        $html .= '</section>';
        if ($developerOptions && $submission !== null) {
            $html .= $this->exchangeTimeline(
                $companyId,
                $accountingPeriodId,
                (int)$submission['id'],
                $exchanges
            );
        }
        $html .= $this->history($history) . '</div>';

        return $html;
    }

    private function submitForm(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        string $mode,
        string $filingKind
    ): string
    {
        $filingKind = in_array($filingKind, ['original', 'revised'], true) ? $filingKind : 'accounts';
        $filingLabel = ucfirst($filingKind);
        $confirmationPhrase = 'SUBMIT LIVE ' . strtoupper($filingKind) . ' ACCOUNTS';
        $live = $mode === 'LIVE'
            ? '<label class="checkbox-row"><input type="checkbox" name="authority_confirmed" value="1" required> '
                . '<span>I am authorised to file these statutory accounts.</span></label>'
                . '<label>Type <strong>' . \eel_accounts\Support\Utf8::html($confirmationPhrase) . '</strong> to confirm'
                . '<input type="text" name="live_confirmation_phrase" required autocomplete="off"></label>'
            : '';

        return '<section class="panel-soft"><h3 class="card-title">Submit accounts</h3>'
            . '<form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
            . $this->hidden($companyId, $accountingPeriodId, 'submit_accounts')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<label>Company authentication code'
            . '<input type="password" name="company_auth_code" minlength="6" maxlength="6" '
            . 'pattern="[A-Za-z0-9]{6}" required autocomplete="off"></label>'
            . $live
            . '<button class="button danger" type="submit" data-chicken-check="true" '
            . 'data-chicken-title="Send ' . \eel_accounts\Support\Utf8::html($filingLabel) . ' accounts" '
            . 'data-chicken-message="Send this immutable ' . \eel_accounts\Support\Utf8::html($filingKind) . '-accounts package to Companies House '
            . \eel_accounts\Support\Utf8::html($mode) . '?" data-chicken-confirm-text="Send accounts">Send / continue '
            . \eel_accounts\Support\Utf8::html($mode) . ' filing</button></form></section>';
    }

    private function refreshForm(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        string $label = 'Send / continue Companies House filing'
    ): string
    {
        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . $this->hidden($companyId, $accountingPeriodId, 'refresh_accounts_status')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button" type="submit">' . \eel_accounts\Support\Utf8::html($label) . '</button></form>';
    }

    private function developerPreparedControls(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        string $mode,
        ?array $preflight,
        bool $bindingConfigured,
        string $filingKind
    ): string {
        $html = '<section class="panel-soft"><h3 class="card-title">Developer step controls</h3>'
            . '<div class="helper">Each button performs one XML send/receive pair and then pauses.</div>';
        if (!$bindingConfigured) {
            return $html . '<div class="notice warning">The preflight binding key could not be prepared for '
                . \eel_accounts\Support\Utf8::html($mode) . '.</div></section>';
        }
        $verified = is_array($preflight)
            && (string)($preflight['outcome'] ?? '') === 'verified'
            && empty($preflight['consumed_at'])
            && $this->utcTimestamp((string)($preflight['binding_expires_at'] ?? '')) >= time();
        if (!$verified) {
            $html .= '<form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
                . $this->hidden($companyId, $accountingPeriodId, 'preflight_accounts')
                . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
                . '<label>Company authentication code'
                . '<input type="password" name="company_auth_code" minlength="6" maxlength="6" '
                . 'pattern="[A-Za-z0-9]{6}" required autocomplete="off"></label>'
                . '<button class="button" type="submit">Send CompanyData preflight</button></form>';
        } else {
            $filingKind = in_array($filingKind, ['original', 'revised'], true) ? $filingKind : 'accounts';
            $confirmationPhrase = 'SUBMIT LIVE ' . strtoupper($filingKind) . ' ACCOUNTS';
            $live = $mode === 'LIVE'
                ? '<label class="checkbox-row"><input type="checkbox" name="authority_confirmed" value="1" required> '
                    . '<span>I am authorised to file these statutory accounts.</span></label>'
                    . '<label>Type <strong>' . \eel_accounts\Support\Utf8::html($confirmationPhrase) . '</strong> to confirm'
                    . '<input type="text" name="live_confirmation_phrase" required autocomplete="off"></label>'
                : '';
            $html .= '<div class="notice success">CompanyData preflight verified. Re-enter the same code to submit Accounts.</div>'
                . '<form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
                . $this->hidden($companyId, $accountingPeriodId, 'submit_preflighted_accounts')
                . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
                . '<input type="hidden" name="preflight_id" value="' . (int)$preflight['id'] . '">'
                . '<label>Company authentication code'
                . '<input type="password" name="company_auth_code" minlength="6" maxlength="6" '
                . 'pattern="[A-Za-z0-9]{6}" required autocomplete="off"></label>'
                . $live
                . '<button class="button danger" type="submit" data-chicken-check="true" '
                . 'data-chicken-message="Send the Accounts exchange using submission number allocation?" '
                . 'data-chicken-confirm-text="Submit Accounts">Submit Accounts</button></form>';
        }
        return $html . '</section>';
    }

    private function developerStatusControls(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        ?array $statusCycle
    ): string {
        $state = strtolower((string)($statusCycle['acknowledgement_state'] ?? 'acknowledged'));
        $html = '<section class="panel-soft"><h3 class="card-title">Developer step controls</h3>';
        if ($state === 'required'
            || ($state === 'failed' && trim((string)($statusCycle['result_json'] ?? '')) !== '')) {
            $html .= $this->simpleProtocolForm(
                $companyId,
                $accountingPeriodId,
                $submissionId,
                'ack_accounts_status',
                $state === 'failed' ? 'Retry StatusAck' : 'Send StatusAck'
            );
        } elseif ($state === 'transport_unknown') {
            $html .= '<div class="notice danger">The status or StatusAck exchange has an uncertain transport result. '
                . 'Further polling is blocked pending confirmation from Companies House.</div>'
                . '<form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
                . $this->hidden($companyId, $accountingPeriodId, 'reconcile_accounts_status')
                . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
                . '<input type="hidden" name="resolution" value="'
                . (trim((string)($statusCycle['result_json'] ?? '')) !== ''
                    ? 'ack_confirmed'
                    : 'poll_not_received') . '">'
                . '<label>After obtaining confirmation, type <strong>RECONCILE COMPANIES HOUSE</strong>'
                . '<input type="text" name="reconciliation_phrase" required autocomplete="off"></label>'
                . '<button class="button danger" type="submit" data-chicken-check="true" '
                . 'data-chicken-message="Only reconcile after Companies House has confirmed the remote state." '
                . 'data-chicken-confirm-text="Reconcile state">Reconcile confirmed state</button></form>';
        } else {
            $html .= $this->simpleProtocolForm(
                $companyId,
                $accountingPeriodId,
                $submissionId,
                'poll_accounts_status',
                'Get submission status'
            );
        }
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

    private function exchangeTimeline(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        array $exchanges
    ): string {
        $rows = '';
        foreach ($exchanges as $exchange) {
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)$exchange['operation'])
                . '</td><td>' . \eel_accounts\Support\Utf8::html((string)$exchange['transaction_id'])
                . '</td><td>' . \eel_accounts\Support\Utf8::html((string)$exchange['exchange_state'])
                . '</td><td>' . $this->evidenceButton(
                    $companyId,
                    $accountingPeriodId,
                    $submissionId,
                    (int)$exchange['id'],
                    'request',
                    !empty($exchange['request_path'])
                )
                . '</td><td>' . $this->evidenceButton(
                    $companyId,
                    $accountingPeriodId,
                    $submissionId,
                    (int)$exchange['id'],
                    'response',
                    !empty($exchange['response_path'])
                ) . '</td></tr>';
        }
        return '<section class="panel-soft"><h3 class="card-title">Developer XML exchange timeline</h3>'
            . '<div class="notice warning">Exact outbound XML can contain presenter and company authentication values. '
            . 'Downloads are private and are not cached.</div>'
            . ($rows === ''
                ? '<div class="helper">No XML exchanges have been sent.</div>'
                : '<div class="table-scroll"><table><thead><tr><th>Operation</th><th>Transaction</th>'
                    . '<th>State</th><th>Sent XML</th><th>Received XML</th></tr></thead><tbody>'
                    . $rows . '</tbody></table></div>')
            . '</section>';
    }

    private function evidenceButton(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId,
        int $exchangeId,
        string $direction,
        bool $available
    ): string {
        if (!$available) {
            return '—';
        }
        $warning = $direction === 'request'
            ? 'This exact outbound XML may contain authentication values. Download it?'
            : 'Download the exact received Companies House XML?';
        return '<form method="post" action="?page=transmit" class="actions-row">'
            . $this->hidden($companyId, $accountingPeriodId, 'download_protocol_evidence')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<input type="hidden" name="exchange_id" value="' . $exchangeId . '">'
            . '<input type="hidden" name="direction" value="' . $direction . '">'
            . '<button class="button button-inline" type="submit" data-chicken-check="true" '
            . 'data-chicken-message="' . \eel_accounts\Support\Utf8::html($warning) . '" '
            . 'data-chicken-confirm-text="Download XML">Download</button></form>';
    }

    private function hidden(int $companyId, int $accountingPeriodId, string $intent): string
    {
        return HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="' . \eel_accounts\Support\Utf8::html($intent) . '">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">';
    }

    private function history(array $history): string
    {
        $history = array_values(array_filter(
            $history,
            static fn(mixed $submission): bool => is_array($submission)
                && (trim((string)($submission['submission_number'] ?? '')) !== ''
                    || trim((string)($submission['submitted_at'] ?? '')) !== '')
        ));
        if ($history === []) {
            return '<section class="panel-soft"><h3 class="card-title">Submission History</h3>'
                . '<div class="helper">No Companies House submission attempts are recorded.</div></section>';
        }
        $rows = '';
        foreach ($history as $submission) {
            $archive = (array)($submission['transmission_archive'] ?? []);
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($submission['submission_number'] ?? 'Not sent'))
                . '</td><td>' . \eel_accounts\Support\Utf8::html((string)($submission['environment'] ?? ''))
                . '</td><td>' . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey((string)($submission['lifecycle'] ?? ''), '_'))
                . '</td><td>' . \eel_accounts\Support\Utf8::html((string)($submission['submitted_at'] ?? ''))
                . '</td><td>' . ($archive !== [] ? 'Captured' : '—') . '</td></tr>';
        }

        return '<section class="panel-soft"><h3 class="card-title">Submission History</h3>'
            . '<div class="table-scroll"><table><thead><tr><th>Number</th><th>Environment</th>'
            . '<th>Status</th><th>When</th><th>Evidence</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
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

        return '<div class="summary-card ' . $class . ' hmrc-connection-summary-card">'
            . '<div class="summary-label">Environment</div>'
            . '<div class="summary-value">' . $label . '</div>'
            . '<div class="actions-row actions-row-right hmrc-connection-summary-actions">'
            . '<a class="button" href="?page=settings&amp;show_card=api_mode">'
            . 'Configure Companies House XML environment</a></div></div>';
    }

    private function credentialMetric(string $environment, bool $configured): string
    {
        if ($configured) {
            return '<div class="summary-card success hmrc-credential-summary-card">'
                . '<div class="summary-label">Credentials</div>'
                . '<div class="summary-value">Configured</div>'
                . '<div class="actions-row actions-row-right hmrc-credential-summary-actions">'
                . '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">'
                . 'Configure Companies House XML credentials</a></div></div>';
        }

        $environment = strtoupper(trim($environment));
        $environment = in_array($environment, ['TEST', 'LIVE'], true) ? $environment : 'selected';

        return '<div class="summary-card danger hmrc-credential-summary-card">'
            . '<div class="summary-label">Credentials</div>'
            . '<div class="helper">Companies House XML accounts filing credentials are missing for the '
            . \eel_accounts\Support\Utf8::html($environment) . ' environment.</div>'
            . '<div class="actions-row actions-row-right hmrc-credential-summary-actions">'
            . '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">'
            . 'Configure Companies House XML credentials</a></div></div>';
    }

    private function transmissionMessage(string $message): string
    {
        $message = trim($message);
        if ($message === 'The prepared Companies House iXBRL artifact is missing.') {
            return '<div class="helper companies-house-artifact-missing-helper">'
                . \eel_accounts\Support\Utf8::html($message) . '</div>';
        }

        return '<div class="notice warning">' . \eel_accounts\Support\Utf8::html($message) . '</div>';
    }

    private function artifactDownloadMetric(
        int $companyId,
        int $accountingPeriodId,
        bool $available
    ): string {
        return '<div class="summary-card"><div class="summary-label">Artifact</div>'
            . '<form method="post" action="?page=transmit" class="actions-row">'
            . $this->hidden($companyId, $accountingPeriodId, 'download_accounts_ixbrl')
            . '<button class="button compact primary" type="submit"'
            . ($available ? '' : ' disabled')
            . '>Companies House iXBRL</button></form></div>';
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

    private function utcTimestamp(string $value): int
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
