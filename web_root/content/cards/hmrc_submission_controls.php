<?php
declare(strict_types=1);

final class _hmrc_submission_controlsCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_controls'; }
    public function title(): string { return 'Submission Controls'; }
    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $data = (array)($context['hmrc_submission'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $ctPeriods = (array)($data['ct_periods'] ?? []);
        $selectedCtPeriodId = (int)($data['selected_ct_period_id'] ?? 0);
        $mode = (string)($data['mode'] ?? 'TEST');
        $liveDisabled = $mode === 'LIVE' ? '' : ' disabled';

        return '<div class="settings-stack">
            ' . $this->form('hmrc_validate_package', 'Validate package', 'primary', $companyId, $accountingPeriodId, $selectedCtPeriodId, $ctPeriods, $mode) . '
            ' . $this->form('hmrc_test_fraud_headers', 'Test fraud prevention headers', '', $companyId, $accountingPeriodId, $selectedCtPeriodId, $ctPeriods, 'TEST') . '
            ' . $this->form('hmrc_submit_test', 'Submit to HMRC sandbox', '', $companyId, $accountingPeriodId, $selectedCtPeriodId, $ctPeriods, 'TEST', true) . '
            <form method="post" action="?page=hmrc_submission" data-ajax="true" data-hmrc-stream-form="true" class="form-grid">
                ' . $this->hidden($companyId, $accountingPeriodId, 'LIVE', 'hmrc_submit_live') . '
                ' . $this->ctPeriodSelect($ctPeriods, $selectedCtPeriodId) . '
                ' . $this->authorityConfirmation('hmrc_live_authority_confirmed', $liveDisabled) . '
                <div class="form-row"><label for="hmrc_live_confirmation">Type SUBMIT LIVE CT600</label><input class="input" id="hmrc_live_confirmation" name="live_confirmation" autocomplete="off"' . $liveDisabled . '></div>
                <div class="actions-row"><button class="button danger" type="submit"' . $liveDisabled . '>Submit to HMRC live</button></div>
            </form>
            <div class="helper">LIVE submission is disabled unless the company HMRC API mode is LIVE and the confirmation phrase is typed exactly.</div>
        </div>';
    }

    private function form(string $intent, string $label, string $buttonClass, int $companyId, int $accountingPeriodId, int $selectedCtPeriodId, array $ctPeriods, string $mode, bool $requiresAuthority = false): string
    {
        return '<form method="post" action="?page=hmrc_submission" data-ajax="true" data-hmrc-stream-form="true" class="' . ($requiresAuthority ? 'form-grid' : 'actions-row') . '">'
            . $this->hidden($companyId, $accountingPeriodId, $mode, $intent)
            . $this->ctPeriodSelect($ctPeriods, $selectedCtPeriodId)
            . ($requiresAuthority ? $this->authorityConfirmation('hmrc_test_authority_confirmed') : '')
            . '<button class="button ' . HelperFramework::escape($buttonClass) . '" type="submit">' . HelperFramework::escape($label) . '</button></form>';
    }

    private function hidden(int $companyId, int $accountingPeriodId, string $mode, string $intent): string
    {
        return '<input type="hidden" name="card_action" value="HmrcSubmission">
            <input type="hidden" name="stream_log" value="1">
            <input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">
            <input type="hidden" name="mode" value="' . HelperFramework::escape($mode) . '">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">';
    }

    private function ctPeriodSelect(array $ctPeriods, int $selectedCtPeriodId): string
    {
        if ($ctPeriods === []) {
            return '<input type="hidden" name="ct_period_id" value="0">';
        }

        $options = '';
        foreach ($ctPeriods as $period) {
            $id = (int)($period['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $selected = $id === $selectedCtPeriodId ? ' selected' : '';
            $label = 'CT Period ' . (int)($period['sequence_no'] ?? 0)
                . ' - ' . (string)($period['period_start'] ?? '')
                . ' to ' . (string)($period['period_end'] ?? '')
                . ' (' . (string)($period['status'] ?? 'pending') . ')';
            $options .= '<option value="' . $id . '"' . $selected . '>' . HelperFramework::escape($label) . '</option>';
        }

        $selectId = 'hmrc_ct_period_id_' . uniqid('', false);

        return '<div class="form-row"><label for="' . $selectId . '">CT period</label><select class="select" id="' . $selectId . '" name="ct_period_id">' . $options . '</select></div>';
    }

    private function authorityConfirmation(string $id, string $disabled = ''): string
    {
        return '<label class="checkbox-row" for="' . HelperFramework::escape($id) . '">
            <input id="' . HelperFramework::escape($id) . '" type="checkbox" name="hmrc_authority_confirmed" value="1"' . $disabled . '>
            <span>I confirm I am authorised to submit on behalf of this company.</span>
        </label>';
    }
}
