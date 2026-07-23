<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_house_transmissionCard extends CardBaseFramework
{
    public function key(): string { return 'companies_house_transmission'; }

    public function title(): string { return 'Companies House revised-accounts transmission'; }

    public function helper(array $context): string
    {
        return 'Send only the immutable revised-account artifact prepared from the locked Year End workflow. Submission numbers are allocated when Send is pressed, never during preparation.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companies_house_transmission_context',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'companies_house_transmission_history',
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
            return '<div class="notice warning">Select a company and accounting period before transmitting revised accounts.</div>';
        }

        $model = (array)(($context['services'] ?? [])['companies_house_transmission_context'] ?? []);
        $history = (array)(($context['services'] ?? [])['companies_house_transmission_history'] ?? []);
        if ($model === []) {
            return '<div class="notice warning">The Companies House transmission status could not be loaded.</div>';
        }

        $feature = (array)($model['feature'] ?? []);
        $sequence = (array)($model['sequence'] ?? []);
        $submission = is_array($model['submission'] ?? null) ? $model['submission'] : null;
        $artifact = (array)($model['prepared_artifact'] ?? []);
        $lifecycle = strtolower(trim((string)($submission['lifecycle'] ?? 'not_prepared')));
        $html = '<div class="settings-stack">'
            . '<section class="panel-soft"><div class="status-head"><h3 class="card-title">Connection and sequence</h3>'
            . '<span class="badge ' . (!empty($feature['credentials_configured']) ? 'success' : 'warning') . '">'
            . (!empty($feature['credentials_configured']) ? 'Configured' : 'Unavailable') . '</span></div>'
            . '<div class="summary-grid">'
            . $this->metric('Environment', (string)($feature['mode'] ?? 'DISABLED'))
            . $this->metric('Next submission number', (string)($sequence['next_number'] ?? 'Unavailable'))
            . $this->metric('Last issued number', (string)($sequence['last_issued_number'] ?? 'None'))
            . $this->metric(
                'Transport lock',
                (int)($sequence['in_flight_submission_id'] ?? 0) > 0
                    ? 'Submission ' . (int)$sequence['in_flight_submission_id']
                    : 'Clear'
            )
            . '</div></section>';

        $html .= '<section class="panel-soft"><div class="status-head"><h3 class="card-title">Prepared transmission</h3>'
            . '<span class="badge ' . $this->badge($lifecycle) . '">'
            . HelperFramework::escape(HelperFramework::labelFromKey($lifecycle, '_')) . '</span></div>';
        if ($submission === null) {
            $html .= '<div class="notice warning">No revised Companies House artifact is prepared. Prepare it from the locked Year End Companies House comparison card.</div>';
        } else {
            $archive = (array)($submission['transmission_archive'] ?? []);
            $html .= '<div class="summary-grid">'
                . $this->metric('Submission number', (string)($submission['submission_number'] ?? 'Allocated on send'))
                . $this->metric('Artifact', (string)($artifact['filename'] ?? basename((string)($submission['revised_artifact_path'] ?? ''))))
                . $this->metric('Artifact SHA-256', (string)($artifact['sha256'] ?? $submission['revised_artifact_sha256'] ?? ''))
                . $this->metric('Private archive', $archive !== [] ? 'Captured and hashed' : 'Created on send')
                . '</div>';
            foreach ((array)($model['submission_blockers'] ?? []) as $blocker) {
                $html .= '<div class="notice warning">' . HelperFramework::escape((string)$blocker) . '</div>';
            }
            if ($lifecycle === 'prepared' && !empty($model['can_submit'])) {
                $html .= $this->submitForm(
                    $companyId,
                    $accountingPeriodId,
                    (int)$submission['id'],
                    strtoupper((string)($feature['mode'] ?? 'TEST'))
                );
            } elseif (in_array($lifecycle, ['submitting', 'transport_unknown', 'pending', 'parked'], true)) {
                $html .= $this->refreshForm($companyId, $accountingPeriodId, (int)$submission['id']);
            }
        }
        $html .= '</section>' . $this->history($history) . '</div>';

        return $html;
    }

    private function submitForm(int $companyId, int $accountingPeriodId, int $submissionId, string $mode): string
    {
        $live = $mode === 'LIVE'
            ? '<label class="checkbox-row"><input type="checkbox" name="authority_confirmed" value="1" required> '
                . '<span>I am authorised to file these revised statutory accounts.</span></label>'
                . '<label>Type <strong>SUBMIT LIVE REVISED ACCOUNTS</strong> to confirm'
                . '<input type="text" name="live_confirmation_phrase" required autocomplete="off"></label>'
            : '';

        return '<form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
            . $this->hidden($companyId, $accountingPeriodId, 'submit_revised_accounts')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<label>Company authentication code'
            . '<input type="password" name="company_auth_code" minlength="6" maxlength="8" '
            . 'pattern="[A-Za-z0-9]{6,8}" required autocomplete="off"></label>'
            . $live
            . '<button class="button danger" type="submit" data-chicken-check="true" '
            . 'data-chicken-title="Send revised accounts" '
            . 'data-chicken-message="Send this immutable revised-accounts package to Companies House '
            . HelperFramework::escape($mode) . '?" data-chicken-confirm-text="Send revised accounts">Send '
            . HelperFramework::escape($mode) . ' revised accounts</button></form>';
    }

    private function refreshForm(int $companyId, int $accountingPeriodId, int $submissionId): string
    {
        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . $this->hidden($companyId, $accountingPeriodId, 'refresh_revised_accounts_status')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button" type="submit">Refresh Companies House status</button></form>';
    }

    private function hidden(int $companyId, int $accountingPeriodId, string $intent): string
    {
        return HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">';
    }

    private function history(array $history): string
    {
        if ($history === []) {
            return '<section class="panel-soft"><h3 class="card-title">Submission history</h3>'
                . '<div class="helper">No Companies House submission attempts are recorded.</div></section>';
        }
        $rows = '';
        foreach ($history as $submission) {
            $archive = (array)($submission['transmission_archive'] ?? []);
            $rows .= '<tr><td>' . HelperFramework::escape((string)($submission['submission_number'] ?? 'Not sent'))
                . '</td><td>' . HelperFramework::escape((string)($submission['environment'] ?? ''))
                . '</td><td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($submission['lifecycle'] ?? ''), '_'))
                . '</td><td>' . HelperFramework::escape((string)($submission['submitted_at'] ?? $submission['prepared_at'] ?? ''))
                . '</td><td>' . ($archive !== [] ? 'Captured' : '—') . '</td></tr>';
        }

        return '<section class="panel-soft"><h3 class="card-title">Submission history</h3>'
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

    private function metric(string $label, string $value): string
    {
        $value = trim($value) !== '' ? $value : '—';
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }
}
