<?php
declare(strict_types=1);

final class _journal_cut_off_createCard extends CardBaseFramework
{
    public function key(): string { return 'journal_cut_off_create'; }
    public function title(): string { return 'Cut-off journal details'; }
    public function helper(array $context): string { return 'Define the cut-off journal header and posting options.'; }

    public function services(): array
    {
        return [
            ['key' => 'cutOffJournals', 'service' => \eel_accounts\Service\YearEndAdjustmentService::class, 'method' => 'fetchContext', 'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id']],
            ['key' => 'journalCutOffReview', 'service' => \eel_accounts\Service\JournalCutOffReviewService::class, 'method' => 'fetchContext', 'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id']],
        ];
    }

    protected function additionalInvalidationFacts(): array { return ['cut.off.journals', 'year.end.state', 'trial.balance.state']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $data = (array)($context['services']['cutOffJournals'] ?? []);
        if (empty($data['available'])) {
            return '<section class="settings-stack"><section class="panel-soft settings-stack"><div class="helper">Cut-off journals are not available.</div></section></section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $period = (array)($data['accounting_period'] ?? []);
        $periodId = (int)($period['id'] ?? ($company['accounting_period_id'] ?? 0));
        $review = (array)($context['services']['journalCutOffReview'] ?? []);
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        $confirmed = !empty($acknowledgement['current']);
        $locked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $periodId);
        $disabled = ($confirmed || $locked) ? ' disabled' : '';
        $message = $locked
            ? '<div class="helper"><span class="badge warning">Period locked</span> Cut-off journals are read only.</div>'
            : ($confirmed ? '<div class="helper"><span class="badge warning">Year End Confirmation entered</span> Cut-off journal controls are disabled until the confirmation is revoked.</div>' : '');

        return '<section class="settings-stack">' . $message . '<section class="panel-soft settings-stack">
            <h4 class="card-title">Cut-off journal details</h4>
            <form id="cut-off-journal-details-form" method="post" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="YearEnd"><input type="hidden" name="intent" value="create_adjustment"><input type="hidden" name="show_card" value=".self"><input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $periodId . '"><input type="hidden" name="adjustment_journal_key" value="template-cutoff-' . bin2hex(random_bytes(4)) . '">
            <div class="form-flex-flow journal-cut-off-create-fields">
                <div class="form-row"><label for="cutoff-template-type">Template</label><select class="select" id="cutoff-template-type" name="adjustment_template_type"' . $disabled . '><option value="accrual" selected>Create accrual</option><option value="prepayment">Create prepayment</option><option value="deferred_income">Create deferred income</option></select></div>
                <div class="form-row"><label for="cutoff-date">Date</label><input class="input" id="cutoff-date" name="adjustment_date" type="date" value="' . HelperFramework::escape((string)($period['period_end'] ?? '')) . '"' . $disabled . '></div>
                <div class="form-row"><label for="cutoff-description">Description</label><input class="input" id="cutoff-description" name="adjustment_description" value=""' . $disabled . '></div>
                <div class="form-row"><label for="cutoff-notes">Notes</label><input class="input" id="cutoff-notes" name="adjustment_notes" value=""' . $disabled . '></div>
                <div class="form-row"><label for="cutoff-primary-nominal">Primary nominal</label><select class="select" id="cutoff-primary-nominal" name="adjustment_primary_nominal_id"' . $disabled . '>' . $this->nominalOptions((array)($data['nominals'] ?? [])) . '</select></div>
                <div class="form-row"><label for="cutoff-offset-nominal">Offset nominal</label><select class="select" id="cutoff-offset-nominal" name="adjustment_offset_nominal_id"' . $disabled . '>' . $this->nominalOptions((array)($data['nominals'] ?? [])) . '</select></div>
                <div class="form-row"><label for="cutoff-amount">Amount</label><input class="input" id="cutoff-amount" name="adjustment_amount" inputmode="decimal"' . $disabled . '></div>
                <div class="form-row"><label>&nbsp;</label><label class="checkbox-item"><input type="checkbox" id="cutoff-auto-reverse" name="adjustment_auto_reverse" value="1"' . $disabled . '><div class="checkbox-copy"><strong>Auto reverse into next period</strong><span>Create the reversing journal on the next period start date.</span></div></label></div>
            </div>
            <div class="actions-row"><button class="button primary" type="submit"' . $disabled . '>Save Cut-off Journal</button></div>
            </form>
        </section>' . $this->renderPostedAdjustments((array)($data['adjustments'] ?? [])) . '</section>';
    }

    private function nominalOptions(array $nominals): string
    {
        $html = '<option value="">Choose nominal...</option>';
        foreach ($nominals as $nominal) {
            $id = (int)($nominal['id'] ?? 0);
            if ($id <= 0) continue;
            $label = trim((string)($nominal['code'] ?? '') . ' ' . (string)($nominal['name'] ?? ''));
            $html .= '<option value="' . $id . '">' . HelperFramework::escape($label) . '</option>';
        }
        return $html;
    }

    private function renderPostedAdjustments(array $adjustments): string
    {
        if ($adjustments === []) {
            return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted cut-off journals</h4><div class="helper">No cut-off journals have been posted for this accounting period yet.</div></section>';
        }

        $rows = '';
        foreach ($adjustments as $adjustment) {
            $rows .= '<tr><td>' . HelperFramework::escape(HelperFramework::displayDate((string)($adjustment['journal_date'] ?? ''))) . '</td><td>' . HelperFramework::escape((string)($adjustment['description'] ?? '')) . '</td><td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($adjustment['journal_tag'] ?? ''), '_')) . '</td><td>' . count((array)($adjustment['lines'] ?? [])) . '</td></tr>';
        }

        return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted cut-off journals</h4><div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }
}
