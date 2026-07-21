<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _director_loan_ct600aCard extends CardBaseFramework
{
    public function key(): string { return 'director_loan_ct600a'; }
    public function title(): string { return 'CT600A Evidence and Section 464A Review'; }
    public function helper(array $context): string
    {
        return 'CT600A figures are derived from posted loan transactions and journals. To correct a later movement, select the following accounting period, then use Loans → Participator Loan Statement → Participator Loan Party.';
    }
    public function services(): array
    {
        return [[
            'key' => 'ct600a',
            'service' => \eel_accounts\Service\Ct600aService::class,
            'method' => 'fetchForAccountingPeriod',
            'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
        ]];
    }
    protected function additionalInvalidationFacts(): array
    {
        return ['tax.ct600a', 'tax.s455', 'ct.filing', 'ixbrl.readiness'];
    }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $data = (array)($context['services']['ct600a'] ?? []);
        if (empty($data['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($data['errors'] ?? [])[0] ?? 'CT600A evidence is unavailable.')) . '</div>';
        }
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $periodId = (int)($company['accounting_period_id'] ?? 0);
        $html = '<div class="settings-stack"><div class="actions-row">
            <a class="button button-inline" target="_blank" rel="noopener noreferrer" href="https://www.gov.uk/guidance/supplementary-pages-ct600a-2015-version-3-close-company-loans-and-arrangements-to-confer-benefits-on-participators">HMRC: CT600A guidance</a>
            <a class="button button-inline" target="_blank" rel="noopener noreferrer" href="https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm61570">HMRC: section 464A</a>
        </div>';
        foreach ((array)$data['periods'] as $ct) {
            $ctId = (int)($ct['ct_period_id'] ?? 0);
            $errors = (array)($ct['blocking_errors'] ?? []);
            $complete = !array_key_exists('complete', $ct) ? $errors === [] : !empty($ct['complete']);
            $review = (array)($ct['review'] ?? []);
            $html .= '<section class="panel-soft settings-stack"><div class="status-head"><h3 class="card-title">CT period '
                . (int)$ct['sequence_no'] . ' — ' . HelperFramework::escape((string)$ct['period_start']) . ' to '
                . HelperFramework::escape((string)$ct['period_end']) . '</h3><span class="badge '
                . ($complete ? 'success' : 'danger') . '">' . ($complete ? 'Ready' : 'Review required') . '</span></div>';
            $html .= '<div class="summary-grid">'
                . $this->stat('CT600A required', !empty($ct['required']) ? 'Yes' : 'No')
                . $this->stat('A15 loans/benefits', $this->money($company, (float)($ct['part1']['total_loans'] ?? 0)))
                . $this->stat('A20 gross tax', $this->money($company, (float)($ct['part1']['tax_chargeable'] ?? 0)))
                . $this->stat('A45 early relief', $this->money($company, (float)($ct['part2']['relief_due'] ?? 0)))
                . $this->stat('A70 later relief', $this->money($company, (float)($ct['part3']['relief_due'] ?? 0)))
                . $this->stat('A75 outstanding', $this->money($company, (float)($ct['total_loans_outstanding'] ?? 0)))
                . $this->stat('A80 tax payable', $this->money($company, (float)($ct['tax_payable'] ?? 0))) . '</div>';
            foreach ($errors as $error) {
                $html .= '<div class="standout helper">' . HelperFramework::escape((string)$error) . '</div>';
            }
            foreach ((array)($ct['evidence_warnings'] ?? []) as $warning) {
                $html .= '<div class="panel-soft warn helper">' . HelperFramework::escape((string)$warning) . '</div>';
            }
            $html .= $this->reviewForm((array)$data['questions'], $review, $ctId, $companyId, $periodId)
                . '</section>';
        }
        return $html . '</div>';
    }

    private function reviewForm(array $questions, array $review, int $ctId, int $companyId, int $periodId): string
    {
        $answers = (array)($review['answers'] ?? []);
        $fields = '';
        foreach ($questions as $key => $question) {
            $value = (string)($answers[$key] ?? 'unresolved');
            $fields .= '<fieldset class="panel-soft"><legend>' . HelperFramework::escape((string)$question) . '</legend><div class="actions-row">'
                . $this->radio((string)$key, 'no', 'No', $value) . $this->radio((string)$key, 'yes', 'Yes', $value)
                . $this->radio((string)$key, 'unresolved', 'Unresolved', $value) . '</div></fieldset>';
        }
        return '<form class="settings-stack" method="post" action="?page=loans" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Ct600a"><input type="hidden" name="intent" value="save_ct600a_review">
                <input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $periodId . '">
                <input type="hidden" name="ct_period_id" value="' . $ctId . '"><h4 class="card-title">Section 464A and 464C declaration</h4>
                <div class="helper">Answer the risk questions from the company records and posted transaction or journal evidence. This review does not create tax evidence.</div>'
            . $fields . '<div class="form-grid"><div class="form-row"><label>Approver name</label><input class="input" name="approved_by" value="'
            . HelperFramework::escape((string)($review['approved_by'] ?? '')) . '" required></div><div class="form-row"><label>Approver role</label>
                <select class="input" name="approver_role"><option value="director"' . ((string)($review['approver_role'] ?? 'director') === 'director' ? ' selected' : '')
            . '>Director</option><option value="adviser"' . ((string)($review['approver_role'] ?? '') === 'adviser' ? ' selected' : '')
            . '>Adviser</option></select></div></div><div class="form-row"><label>Evidence or conclusion note</label><textarea class="input" name="confirmation_note" rows="3">'
            . HelperFramework::escape((string)($review['confirmation_note'] ?? '')) . '</textarea></div><button class="button primary" type="submit">Approve section 464A review</button></form>';
    }

    private function radio(string $name, string $value, string $label, string $selected): string
    {
        $id = 'ct600a_' . $name . '_' . $value;
        return '<label for="' . $id . '"><input id="' . $id . '" type="radio" name="' . HelperFramework::escape($name)
            . '" value="' . $value . '"' . ($selected === $value ? ' checked' : '') . ' required> ' . $label . '</label>';
    }
    private function stat(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }
    private function money(array $company, float $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money((array)($company['settings'] ?? []), $value);
    }
}
