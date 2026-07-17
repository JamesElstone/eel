<?php
declare(strict_types=1);

final class _hmrc_submission_controlsCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_controls'; }

    public function title(): string { return 'Prepare And Submit'; }

    public function services(): array
    {
        return [[
            'key' => 'hmrc_submission',
            'service' => \eel_accounts\Service\HmrcCtSubmissionReadModel::class,
            'method' => 'pageState',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'selectedCtPeriodId' => ':hmrc_submission_selection.selected_ct_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['hmrc.submission', 'page.context'];
    }

    public function render(array $context): string
    {
        $data = $this->data($context);
        $periods = (array)($data['ct_periods'] ?? []);
        $selectedCtPeriodId = (int)($data['selected_ct_period_id'] ?? 0);
        $environment = (string)($data['environment'] ?? 'TEST');
        $latest = (array)($data['latest_submission'] ?? []);
        $submissionId = (int)($latest['id'] ?? 0);
        $declarantName = trim((string)($latest['declarant_name'] ?? ''));
        $declarantStatus = $this->declarantStatusToken((string)($latest['declarant_status'] ?? ''));
        $capabilities = (array)($data['capabilities'] ?? []);
        $csrf = (string)($context['page']['csrf_token'] ?? '');

        $canPrepare = !empty($capabilities['can_prepare']) && $selectedCtPeriodId > 0;
        $canApprove = !empty($capabilities['can_approve']) && $submissionId > 0;
        $canSubmit = !empty($capabilities['can_submit']) && $submissionId > 0;
        $canPoll = !empty($capabilities['can_poll']) && $submissionId > 0;
        $canDelete = !empty($capabilities['can_delete']) && $submissionId > 0;

        return '<div class="settings-stack">
            <section class="panel-soft">
                <h3 class="card-title">1. Select the Corporation Tax return</h3>
                <form method="get" action="" class="form-grid">
                    <input type="hidden" name="page" value="HMRC">
                    ' . $this->ctPeriodSelect($periods, $selectedCtPeriodId) . '
                    <div class="actions-row"><button class="button" type="submit">View selected return</button></div>
                </form>
            </section>
            <section class="panel-soft">
                <h3 class="card-title">2. Freeze and validate the return package</h3>
                <div class="helper">Preparation freezes the CT600 body, accounts iXBRL, period-specific computations iXBRL, source hashes, and IRmark into one auditable package.</div>
                <form method="post" action="?page=HMRC" data-ajax="true" class="form-grid">
                    ' . $this->hiddenAction($csrf, 'prepare_ct600', $selectedCtPeriodId, 0) . '
                    <div class="form-row"><label for="hmrc_prepare_declarant_name">Declarant name</label><input class="input" id="hmrc_prepare_declarant_name" name="declarant_name" maxlength="150" required' . $this->disabled($canPrepare) . '></div>
                    <div class="form-row"><label for="hmrc_prepare_declarant_status">Declarant status</label><select class="select" id="hmrc_prepare_declarant_status" name="declarant_status" required' . $this->disabled($canPrepare) . '><option value="proper_officer">Director / proper officer</option><option value="authorised_person">Duly authorised person</option></select></div>
                    <div class="actions-row"><button class="button primary" type="submit"' . $this->disabled($canPrepare) . '>Prepare And Validate Package</button></div>
                </form>
            </section>
            <section class="panel-soft">
                <h3 class="card-title">3. Approve the exact frozen package</h3>
                <form method="post" action="?page=HMRC" data-ajax="true" class="form-grid">
                    ' . $this->hiddenAction($csrf, 'approve_ct600', $selectedCtPeriodId, $submissionId) . '
                    <input type="hidden" name="declarant_name" value="' . HelperFramework::escape($declarantName) . '">
                    <input type="hidden" name="declarant_status" value="' . HelperFramework::escape($declarantStatus) . '">
                    <div class="helper">Declarant: ' . HelperFramework::escape($declarantName !== '' ? $declarantName : 'Not frozen') . ' (' . HelperFramework::escape($this->declarantStatusLabel($declarantStatus)) . '). Changing these details requires a new package.</div>
                    ' . $this->confirmation('hmrc_scope_confirmed', 'scope_confirmed', 'I confirm no CT600 supplementary page, claim, election, or other unsupported attachment is required for this phase-one return.', $canApprove) . '
                    ' . $this->confirmation('hmrc_original_unfiled_confirmed', 'original_unfiled_confirmed', 'I confirm this CT period has not already been filed with HMRC by this company or another filing service.', $canApprove) . '
                    ' . $this->confirmation('hmrc_declaration_confirmed', 'declaration_confirmed', 'I confirm the exact frozen Company Tax Return shown above is correct and complete to the best of my knowledge and belief.', $canApprove) . '
                    <div class="actions-row"><button class="button primary" type="submit"' . $this->disabled($canApprove) . '>Approve Frozen Package</button></div>
                </form>
            </section>
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">4. Send through HMRC ' . HelperFramework::escape($environment) . '</h3>
                    <span class="badge ' . $this->environmentBadge($environment) . '">' . HelperFramework::escape($environment) . '</span>
                </div>
                <div class="helper">' . HelperFramework::escape((string)($data['environment_notice'] ?? '')) . '</div>
                <form method="post" action="?page=HMRC" data-ajax="true" class="form-grid">
                    ' . $this->hiddenAction($csrf, 'submit_ct600', $selectedCtPeriodId, $submissionId) . '
                    ' . $this->confirmation('hmrc_submission_authority', 'authority_confirmed', 'I am authorised to send this Company Tax Return for the company.', $canSubmit) . '
                    ' . ($environment === 'LIVE'
                        ? '<div class="form-row"><label for="hmrc_live_confirmation">Type SUBMIT LIVE CT600</label><input class="input" id="hmrc_live_confirmation" name="live_confirmation" autocomplete="off" required' . $this->disabled($canSubmit) . '></div>'
                        : '') . '
                    <div class="actions-row"><button class="button ' . ($environment === 'LIVE' ? 'danger' : 'primary') . '" type="submit"' . $this->disabled($canSubmit) . '>Send To HMRC ' . HelperFramework::escape($environment) . '</button></div>
                </form>
            </section>
            <section class="panel-soft">
                <h3 class="card-title">5. Complete the asynchronous exchange</h3>
                <div class="helper">An HMRC acknowledgement is not acceptance. Check only when the recorded poll time is due, then retain the final response before deleting it from the Transaction Engine.</div>
                <div class="actions-row">
                    ' . $this->simpleActionForm($csrf, 'poll_ct600', $selectedCtPeriodId, $submissionId, 'Check HMRC Status', '', $canPoll) . '
                    ' . $this->simpleActionForm(
                        $csrf,
                        'delete_ct600_response',
                        $selectedCtPeriodId,
                        $submissionId,
                        !empty($capabilities['can_recover']) ? 'Recover Existing Transaction' : 'Complete Response Cleanup',
                        '',
                        $canDelete
                    ) . '
                </div>
            </section>
        </div>';
    }

    private function data(array $context): array
    {
        return (array)($context['services']['hmrc_submission'] ?? $context['hmrc_submission'] ?? []);
    }

    private function ctPeriodSelect(array $periods, int $selectedCtPeriodId): string
    {
        $options = '';
        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $id = (int)($period['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0)))
                . ' - ' . (string)($period['period_start'] ?? '')
                . ' to ' . (string)($period['period_end'] ?? '');
            $options .= '<option value="' . $id . '"' . ($id === $selectedCtPeriodId ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }
        if ($options === '') {
            $options = '<option value="0">No CT periods available</option>';
        }

        return '<div class="form-row"><label for="hmrc_ct_period_id">CT period</label><select class="select" id="hmrc_ct_period_id" name="ct_period_id">' . $options . '</select></div>';
    }

    private function simpleActionForm(
        string $csrf,
        string $intent,
        int $ctPeriodId,
        int $submissionId,
        string $label,
        string $buttonClass,
        bool $enabled
    ): string {
        return '<form method="post" action="?page=HMRC" data-ajax="true" class="actions-row">'
            . $this->hiddenAction($csrf, $intent, $ctPeriodId, $submissionId)
            . '<button class="button ' . HelperFramework::escape($buttonClass) . '" type="submit"' . $this->disabled($enabled) . '>' . HelperFramework::escape($label) . '</button></form>';
    }

    private function hiddenAction(string $csrf, string $intent, int $ctPeriodId, int $submissionId): string
    {
        return '<input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrf) . '">
            <input type="hidden" name="card_action" value="HmrcSubmission">
            <input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">
            <input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">
            <input type="hidden" name="submission_id" value="' . $submissionId . '">';
    }

    private function confirmation(string $id, string $name, string $label, bool $enabled): string
    {
        return '<label class="checkbox-row" for="' . HelperFramework::escape($id) . '">
            <input id="' . HelperFramework::escape($id) . '" type="checkbox" name="' . HelperFramework::escape($name) . '" value="1" required' . $this->disabled($enabled) . '>
            <span>' . HelperFramework::escape($label) . '</span>
        </label>';
    }

    private function disabled(bool $enabled): string
    {
        return $enabled ? '' : ' disabled aria-disabled="true"';
    }

    private function environmentBadge(string $environment): string
    {
        return match ($environment) {
            'LIVE' => 'danger',
            'TIL' => 'warning',
            default => 'info',
        };
    }

    private function declarantStatusToken(string $status): string
    {
        return match (strtolower(trim($status))) {
            'authorised person', 'authorised_person' => 'authorised_person',
            default => 'proper_officer',
        };
    }

    private function declarantStatusLabel(string $status): string
    {
        return $status === 'authorised_person' ? 'Authorised person' : 'Proper officer';
    }
}
