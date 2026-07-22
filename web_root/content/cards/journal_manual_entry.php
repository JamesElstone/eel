<?php
declare(strict_types=1);

final class _journal_manual_entryCard extends CardBaseFramework
{
    public function key(): string { return 'journal_manual_entry'; }
    public function title(): string { return 'Custom journal lines'; }
    public function helper(array $context): string { return 'Enter the balanced nominal lines for the cut-off journal.'; }
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
        if (empty($data['available'])) return '<section class="settings-stack"><section class="panel-soft settings-stack"><div class="helper">Cut-off journals are not available.</div></section></section>';
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $period = (array)($data['accounting_period'] ?? []);
        $periodId = (int)($period['id'] ?? ($company['accounting_period_id'] ?? 0));
        $review = (array)($context['services']['journalCutOffReview'] ?? []);
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        $confirmed = !empty($acknowledgement['current']);
        $locked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $periodId);
        $disabled = ($confirmed || $locked) ? ' disabled' : '';
        $message = $locked ? '<div class="helper"><span class="badge warning">Period locked</span> Cut-off journals are read only.</div>' : ($confirmed ? '<div class="helper"><span class="badge warning">Year End Confirmation entered</span> Cut-off journal controls are disabled until the confirmation is revoked.</div>' : '');
        $html = '<section class="settings-stack">' . $message . '<section class="panel-soft settings-stack"><h4 class="card-title">Custom journal lines</h4><form method="post" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="YearEnd"><input type="hidden" name="intent" value="create_adjustment"><input type="hidden" name="show_card" value=".self"><input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $periodId . '"><input type="hidden" name="adjustment_template_type" value="custom"><input type="hidden" name="adjustment_date" value="' . HelperFramework::escape((string)($period['period_end'] ?? '')) . '"><input type="hidden" name="adjustment_description" value="Custom journal"><input type="hidden" name="adjustment_journal_key" value="manual-cutoff-' . bin2hex(random_bytes(4)) . '"><div class="table-scroll"><table><thead><tr><th>Nominal</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead><tbody>';
        for ($index = 0; $index < 2; $index++) {
            $html .= '<tr><td><select class="select" name="adjustment_line_' . $index . '_nominal_id"' . $disabled . '>' . $this->nominalOptions((array)($data['nominals'] ?? [])) . '</select></td><td><input class="input" name="adjustment_line_' . $index . '_debit" inputmode="decimal"' . $disabled . '></td><td><input class="input" name="adjustment_line_' . $index . '_credit" inputmode="decimal"' . $disabled . '></td><td><input class="input" name="adjustment_line_' . $index . '_description"' . $disabled . '></td></tr>';
        }
        return $html . '</tbody></table></div><div class="actions-row"><button class="button primary" type="submit"' . $disabled . '>Post Cut-off Journal</button></div></form></section>' . $this->renderManualEntries((array)($data['adjustments'] ?? [])) . '</section>';
    }

    private function nominalOptions(array $nominals): string
    {
        $html = '<option value="">Choose nominal...</option>';
        foreach ($nominals as $nominal) { $id = (int)($nominal['id'] ?? 0); if ($id > 0) $html .= '<option value="' . $id . '">' . HelperFramework::escape(trim((string)($nominal['code'] ?? '') . ' ' . (string)($nominal['name'] ?? ''))) . '</option>'; }
        return $html;
    }

    private function renderManualEntries(array $adjustments): string
    {
        $rows = '';
        foreach ($adjustments as $journal) {
            if (!str_starts_with((string)($journal['journal_key'] ?? ''), 'manual-cutoff-')) continue;
            $rows .= '<tr><td>' . HelperFramework::escape(HelperFramework::displayDate((string)($journal['journal_date'] ?? ''))) . '</td><td>' . HelperFramework::escape((string)($journal['description'] ?? '')) . '</td><td>' . count((array)($journal['lines'] ?? [])) . '</td></tr>';
        }

        return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted Manual Journal Entries</h4>' . ($rows !== ''
            ? '<div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            : '<div class="helper">No manual journal entries have been posted for this accounting period yet.</div>') . '</section>';
    }
}
