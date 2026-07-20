<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_submission_unavailableCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_unavailable'; }

    public function title(): string { return 'Corporation Tax submission'; }

    public function helper(array $context): string
    {
        return 'Test each CT600 through HMRC Test in Live, then submit the unchanged filing to LIVE only after every local and HMRC gate passes.';
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
            . HelperFramework::escape((string)($error['message'] ?? 'Review the application log and try again.'))
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
        $html = '<div class="settings-stack">'
            . '<div class="notice warning"><strong>HMRC Test in Live does not file the return.</strong> '
            . 'The LIVE action remains locked unless TIL accepted the same filing body and source manifest.</div>'
            . $this->environmentSummary($status);

        foreach ($this->messages((array)($status['errors'] ?? [])) as $error) {
            $html .= '<div class="notice warning">' . HelperFramework::escape($error) . '</div>';
        }

        if ($periods === []) {
            return $html . '<div class="notice warning">No current CT periods are available for this accounting period.</div></div>';
        }

        foreach ($periods as $period) {
            $html .= $this->periodPanel((array)$period, $companyId, $accountingPeriodId);
        }

        return $html . '</div>';
    }

    private function environmentSummary(array $status): string
    {
        $environments = (array)($status['environments'] ?? []);
        $testName = strtoupper(trim((string)($status['test_environment'] ?? 'TIL')));
        $liveName = strtoupper(trim((string)($status['live_environment'] ?? 'LIVE')));
        $test = (array)($environments[$testName] ?? []);
        $live = (array)($environments[$liveName] ?? []);

        $html = '<section class="panel-soft"><div class="status-head"><h3 class="card-title">HMRC connection</h3></div>'
            . '<div class="summary-grid">'
            . $this->metric('Test mode', $testName)
            . $this->metric('TIL credentials', $this->credentialLabel($test))
            . $this->metric('Live mode', $liveName)
            . $this->metric('LIVE credentials', $this->credentialLabel($live))
            . '</div>';

        $environmentBlockers = array_merge(
            $this->messages((array)($test['blockers'] ?? [])),
            $this->messages((array)($live['blockers'] ?? []))
        );
        foreach (array_values(array_unique($environmentBlockers)) as $blocker) {
            $html .= '<div class="helper"><span class="badge warning">Connection blocker</span> '
                . HelperFramework::escape($blocker) . '</div>';
        }

        return $html . '</section>';
    }

    private function periodPanel(array $period, int $companyId, int $accountingPeriodId): string
    {
        $ctPeriodId = (int)($period['ct_period_id'] ?? $period['id'] ?? 0);
        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        $testReady = !empty($period['test_ready']);
        $liveReady = !empty($period['live_ready']);
        $latestTest = (array)($period['latest_test'] ?? []);
        $latestLive = (array)($period['latest_live'] ?? []);
        $pending = (array)($period['pending_submission'] ?? []);
        $pendingId = (int)($pending['submission_id'] ?? $pending['id'] ?? 0);
        $pendingState = strtolower(trim((string)($pending['protocol_state'] ?? '')));
        $canPoll = $pendingId > 0 && (!empty($pending['needs_poll'])
            || in_array($pendingState, ['awaiting_poll', 'delete_pending'], true));
        [$badgeClass, $badgeLabel] = $this->periodBadge($period);

        $reference = trim((string)($latestLive['hmrc_reference']
            ?? $latestLive['hmrc_submission_reference']
            ?? $latestLive['hmrc_correlation_id']
            ?? $latestLive['correlation_id']
            ?? $latestTest['hmrc_reference']
            ?? $latestTest['hmrc_submission_reference']
            ?? $latestTest['hmrc_correlation_id']
            ?? $latestTest['correlation_id']
            ?? ''));
        $irmark = trim((string)($latestLive['irmark'] ?? $latestTest['irmark'] ?? ''));
        $html = '<section class="panel-soft"><div class="status-head"><h3 class="card-title">CT period '
            . HelperFramework::escape($start) . ' to ' . HelperFramework::escape($end)
            . '</h3><span class="badge ' . $badgeClass . '">' . HelperFramework::escape($badgeLabel) . '</span></div>'
            . '<div class="summary-grid">'
            . $this->metric('Last TIL result', $this->submissionLabel($latestTest))
            . $this->metric('LIVE result', $this->submissionLabel($latestLive))
            . $this->metric('IRmark', $irmark !== '' ? $irmark : 'Not generated')
            . $this->metric('HMRC reference', $reference !== '' ? $reference : 'Not issued')
            . '</div>';

        $blockers = array_values(array_unique(array_merge(
            $this->messages((array)($period['blockers'] ?? [])),
            $this->messages((array)($period['test_blockers'] ?? [])),
            $this->messages((array)($period['live_blockers'] ?? []))
        )));
        foreach ($blockers as $blocker) {
            $html .= '<div class="notice warning">' . HelperFramework::escape($blocker) . '</div>';
        }

        $html .= $this->submissionForm(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $start,
            $end,
            $testReady,
            $liveReady,
            $period
        );
        if ($canPoll) {
            $html .= '<div class="form-row-actions">'
                . $this->pollForm($companyId, $accountingPeriodId, $ctPeriodId, $pendingId, $pending)
                . '</div>';
        }

        return $html . '</section>';
    }

    private function pollForm(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $submissionId,
        array $pending
    ): string {
        $pollAfter = (int)($pending['poll_after_seconds'] ?? $pending['poll_interval_seconds'] ?? 0);
        $label = (string)($pending['protocol_state'] ?? '') === 'delete_pending'
            ? 'Complete HMRC cleanup'
            : ($pollAfter > 0 ? 'Check HMRC status (after ' . $pollAfter . 's)' : 'Check HMRC status');

        return '<form method="post" action="?page=HMRC" data-ajax="true">'
            . $this->hiddenFields($companyId, $accountingPeriodId, $ctPeriodId)
            . '<input type="hidden" name="intent" value="hmrc_poll">'
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button" type="submit">' . HelperFramework::escape($label) . '</button></form>';
    }

    private function submissionForm(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $start,
        string $end,
        bool $testEnabled,
        bool $liveEnabled,
        array $period
    ): string {
        $testDisabled = $testEnabled ? '' : ' disabled';
        $liveDisabled = $liveEnabled ? '' : ' disabled';
        $periodLabel = trim($start . ' to ' . $end);
        $declaration = (array)($period['declaration'] ?? []);
        $declarationName = trim((string)($declaration['declaration_name'] ?? $period['declaration_name'] ?? ''));
        $declarationStatus = trim((string)($declaration['declaration_status'] ?? $period['declaration_status'] ?? ''));

        return '<form method="post" action="?page=HMRC" data-ajax="true" class="settings-stack">'
            . $this->hiddenFields($companyId, $accountingPeriodId, $ctPeriodId)
            . '<h4>Return declaration</h4>'
            . '<div class="helper">These details form part of the tested filing body. Leave them unchanged between a successful TIL test and LIVE submission.</div>'
            . '<div class="form-row half"><label for="hmrc-declaration-name-' . $ctPeriodId . '">Declarant name</label>'
            . '<input class="input" id="hmrc-declaration-name-' . $ctPeriodId . '" name="declaration_name" type="text" value="'
            . HelperFramework::escape($declarationName) . '" required></div>'
            . '<div class="form-row half"><label for="hmrc-declaration-status-' . $ctPeriodId . '">Declarant status or capacity</label>'
            . '<input class="input" id="hmrc-declaration-status-' . $ctPeriodId . '" name="declaration_status" type="text" value="'
            . HelperFramework::escape($declarationStatus) . '" required></div>'
            . $this->confirmation('original_unfiled_confirmed', $ctPeriodId, 'This is an original return and has not already been filed for this CT period.')
            . $this->confirmation('supplementary_scope_confirmed', $ctPeriodId, 'I confirm the supported return does not require a supplementary page.')
            . $this->confirmation('authority_confirmed', $ctPeriodId, 'I am authorised to file this Corporation Tax return for the company.')
            . $this->confirmation('declaration_confirmed', $ctPeriodId, 'I declare that the information in this return is correct and complete to the best of my knowledge and belief.')
            . '<div class="actions-row">'
            . '<button class="button primary" type="submit" name="intent" value="hmrc_submit_test"' . $testDisabled . '>Test</button>'
            . '<button class="button danger" type="submit" name="intent" value="hmrc_submit_live"' . $liveDisabled
            . ' data-chicken-check="true" data-chicken-title="Submit Corporation Tax return"'
            . ' data-chicken-message="Submit the CT600 for ' . HelperFramework::escape($periodLabel)
            . ' to HMRC LIVE?&lt;br&gt;&lt;br&gt;This is a statutory filing and cannot be undone in this application."'
            . ' data-chicken-confirm-text="Submit Tax Return">Submit Tax Return</button></div>'
            . ($liveEnabled ? '' : '<div class="helper">A successful TIL result for the current body and source manifest is required before LIVE submission.</div>')
            . '</form>';
    }

    private function confirmation(string $name, int $ctPeriodId, string $label): string
    {
        $id = 'hmrc-' . str_replace('_', '-', $name) . '-' . $ctPeriodId;
        return '<label class="checkbox-row" for="' . $id . '"><input id="' . $id . '" name="'
            . HelperFramework::escape($name) . '" type="checkbox" value="1" required> '
            . HelperFramework::escape($label) . '</label>';
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
        if (!empty($period['live_ready'])) {
            return ['success', 'Ready for LIVE'];
        }
        if (!empty($period['test_ready'])) {
            return ['warning', 'Ready to test'];
        }
        return ['muted', 'Blocked'];
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

    private function credentialLabel(array $environment): string
    {
        if ($environment === [] || !array_key_exists('credentials_configured', $environment)) {
            return 'Unavailable';
        }
        return !empty($environment['credentials_configured']) ? 'Configured' : 'Unavailable';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
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
