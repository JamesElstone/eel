<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_loansCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_loans'; }
    public function title(): string { return 'Frozen Director Loan Evidence'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceLoans', 'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'loanEvidence', 'params' => ['companyId' => ':company.id', 'bundleId' => ':filing_evidence.bundle_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceLoans'] ?? []);
        if (!empty($model['empty_selection'])) { return '<div class="helper">Look up an Evidence ID to inspect its frozen director-loan evidence.</div>'; }
        if (empty($model['available'])) {
            return '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)(($model['errors'] ?? [])[0] ?? 'Frozen loan evidence is unavailable.')) . '</div>';
        }
        $snapshot = (array)($model['snapshot'] ?? []);
        $html = '<section class="settings-stack"><div class="summary-grid four">'
            . $this->stat('Snapshot version', (string)($model['snapshot_version'] ?? ''))
            . $this->stat('Captured', (string)($model['created_at'] ?? ''))
            . $this->stat('Scope', !empty($snapshot['applicable']) ? 'Director-loan activity' : 'No loan activity')
            . $this->stat('Snapshot hash', substr((string)($model['snapshot_hash'] ?? ''), 0, 16) . '…')
            . '</div>';

        foreach ((array)($snapshot['ct_periods'] ?? []) as $period) {
            $s455 = (array)($period['s455'] ?? []);
            $html .= '<section class="panel-soft settings-stack"><h3 class="card-title">S455 — CT period '
                . (int)($period['sequence_no'] ?? 0) . ' · ' . \eel_accounts\Support\Utf8::html((string)($period['period_start'] ?? '') . ' to ' . (string)($period['period_end'] ?? ''))
                . '</h3><div class="table-scroll"><table><tbody>'
                . $this->row('Close-company status', (string)($s455['close_company_status'] ?? ''))
                . $this->row('Evidence cut-off', (string)($s455['evidence_cutoff'] ?? ''))
                . $this->row('Repayment deadline', (string)($s455['repayment_deadline'] ?? ''))
                . $this->row('Gross principal', $this->money($context, $s455['gross_principal'] ?? 0))
                . $this->row('Gross S455 tax', $this->money($context, $s455['gross_tax'] ?? 0))
                . $this->row('Qualifying repayments', $this->money($context, $s455['qualifying_repayments'] ?? 0))
                . $this->row('Net S455 tax', $this->money($context, $s455['net_tax'] ?? 0))
                . $this->row('Basis hash', (string)($s455['basis_hash'] ?? ''))
                . '</tbody></table></div>' . $this->s455Trace((array)($s455['basis'] ?? []), $context) . '</section>';
        }

        $ct600a = (array)($snapshot['ct600a'] ?? []);
        $html .= $this->ct600a($ct600a, $context);
        $html .= $this->section413((array)($snapshot['section_413'] ?? []), $context);
        return $html . '</section>';
    }

    private function ct600a(array $ct600a, array $context): string
    {
        $review = (array)($ct600a['review'] ?? []);
        $answers = '';
        foreach ((array)($ct600a['questions'] ?? []) as $key => $question) {
            $answers .= '<tr><th scope="row">' . \eel_accounts\Support\Utf8::html((string)$question) . '</th><td>'
                . \eel_accounts\Support\Utf8::html(ucfirst((string)($review['answers'][$key] ?? 'unresolved'))) . '</td></tr>';
        }
        $periods = '';
        foreach ((array)($ct600a['periods'] ?? []) as $period) {
            $periods .= '<tr><td>CT period ' . (int)($period['sequence_no'] ?? 0) . '</td><td>' . $this->money($context, $period['part1']['total_loans'] ?? 0)
                . '</td><td>' . $this->money($context, $period['part1']['tax_chargeable'] ?? 0)
                . '</td><td>' . $this->money($context, $period['part2']['relief_due'] ?? 0)
                . '</td><td>' . $this->money($context, $period['part3']['relief_due'] ?? 0)
                . '</td><td>' . $this->money($context, $period['total_loans_outstanding'] ?? 0)
                . '</td><td>' . $this->money($context, $period['tax_payable'] ?? 0) . '</td></tr>';
        }
        $events = '';
        foreach ((array)($ct600a['periods'] ?? []) as $period) {
            foreach ((array)($period['events'] ?? []) as $event) {
                $events .= '<tr><td>CT period ' . (int)($period['sequence_no'] ?? 0) . '</td><td>'
                    . \eel_accounts\Support\Utf8::html((string)($event['event_date'] ?? '')) . '</td><td>'
                    . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey((string)($event['event_kind'] ?? ''))) . '</td><td>'
                    . \eel_accounts\Support\Utf8::html((string)($event['party_name'] ?? '')) . '</td><td>'
                    . $this->money($context, $event['amount'] ?? 0) . '</td><td>'
                    . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey((string)($event['matching_status'] ?? 'clear'))) . '</td></tr>';
            }
        }
        return '<section class="panel-soft settings-stack"><h3 class="card-title">CT600A and Section 464A/464C</h3>'
            . '<div class="table-scroll"><table><thead><tr><th>Period</th><th>A15</th><th>A20</th><th>A45</th><th>A70</th><th>A75</th><th>A80</th></tr></thead><tbody>' . $periods . '</tbody></table></div>'
            . '<h4>Frozen Section 464A/464C declaration</h4><div class="table-scroll"><table><tbody>' . $answers
            . $this->row('Approved by', (string)($review['approved_by'] ?? ''))
            . $this->row('Approved at', (string)($review['confirmed_at'] ?? ''))
            . $this->row('Evidence manifest hash', (string)($review['basis_hash'] ?? ''))
            . '</tbody></table></div><h4>Frozen CT600A events</h4>'
            . ($events === '' ? '<div class="helper">No CT600A benefit, repayment, release or return-payment events were frozen.</div>'
                : '<div class="table-scroll"><table><thead><tr><th>Period</th><th>Date</th><th>Event</th><th>Party</th><th>Amount</th><th>464C status</th></tr></thead><tbody>' . $events . '</tbody></table></div>')
            . '</section>';
    }

    private function section413(array $section, array $context): string
    {
        $disclosure = (array)($section['disclosure'] ?? []);
        $rows = '';
        foreach ((array)($disclosure['director_evidence'] ?? []) as $row) {
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($row['director_name'] ?? 'Director')) . '</td><td>'
                . $this->money($context, $row['advances'] ?? 0) . '</td><td>' . $this->money($context, $row['cash_repayments'] ?? 0)
                . '</td><td>' . $this->money($context, $row['closing_company_to_director_balance'] ?? 0)
                . '</td><td>' . (!empty($row['section_413_required']) ? 'Yes' : 'No') . '</td></tr>';
        }
        $statementRows = '';
        foreach ((array)(($section['statement'] ?? [])['statement_rows'] ?? []) as $row) {
            $statementRows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($row['txn_date'] ?? $row['date'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($row['director_name'] ?? $row['party_name'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($row['source_type'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($row['source_id'] ?? '')) . '</td><td>'
                . $this->money($context, $row['signed_amount'] ?? 0) . '</td><td>'
                . $this->money($context, $row['running_balance'] ?? 0) . '</td></tr>';
        }
        $approval = (array)($section['year_end_approval'] ?? []);
        $accountsDisclosure = (array)($section['accounts_disclosure'] ?? []);
        return '<section class="panel-soft settings-stack"><h3 class="card-title">Section 413 — Directors’ advances, credits and guarantees</h3>'
            . '<div class="table-scroll"><table><thead><tr><th>Director</th><th>Advances</th><th>Cash repaid</th><th>Closing advance</th><th>Disclosure required</th></tr></thead><tbody>'
            . ($rows === '' ? '<tr><td colspan="5">No director evidence was required.</td></tr>' : $rows) . '</tbody></table></div><div class="table-scroll"><table><tbody>'
            . $this->row('Accounts disclosure: director advances, credits or guarantees', (($accountsDisclosure['has_director_advances_credits_or_guarantees'] ?? null) === null) ? 'Not recorded' : (!empty($accountsDisclosure['has_director_advances_credits_or_guarantees']) ? 'Yes' : 'No'))
            . $this->row('Year End approval basis hash', (string)($approval['basis_hash'] ?? 'Not required'))
            . $this->row('Approved by', (string)($approval['acknowledged_by'] ?? ''))
            . $this->row('Approved at', (string)($approval['acknowledged_at'] ?? ''))
            . '</tbody></table></div><h4>Frozen director-loan statement trace</h4>'
            . ($statementRows === '' ? '<div class="helper">No director-loan statement movements were frozen.</div>'
                : '<div class="table-scroll"><table><thead><tr><th>Date</th><th>Director</th><th>Source</th><th>Reference</th><th>Movement</th><th>Running balance</th></tr></thead><tbody>' . $statementRows . '</tbody></table></div>')
            . '</section>';
    }

    private function s455Trace(array $basis, array $context): string
    {
        $rows = '';
        foreach ((array)($basis['all_lots'] ?? $basis['lots'] ?? []) as $lot) {
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($lot['transaction_id'] ?? $lot['source_id'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($lot['txn_date'] ?? $lot['advance_date'] ?? '')) . '</td><td>'
                . $this->money($context, $lot['remaining_at_period_end'] ?? $lot['amount'] ?? 0) . '</td><td>'
                . \eel_accounts\Support\Utf8::html(number_format((float)($lot['rate'] ?? 0) * 100, 3)) . '%</td></tr>';
        }
        $movements = '';
        foreach ((array)($basis['movements'] ?? []) as $movement) {
            $movements .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($movement['transaction_id'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($movement['txn_date'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($movement['party_id'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey((string)($movement['cash_direction'] ?? ''))) . '</td><td>'
                . $this->money($context, $movement['amount'] ?? 0) . '</td></tr>';
        }
        $repayments = '';
        foreach ((array)($basis['all_repayment_allocations'] ?? $basis['repayment_allocations'] ?? []) as $repayment) {
            $repayments .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($repayment['repayment_date'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($repayment['transaction_id'] ?? $repayment['repayment_transaction_id'] ?? '')) . '</td><td>'
                . $this->money($context, $repayment['amount'] ?? $repayment['allocated_amount'] ?? 0) . '</td></tr>';
        }
        $lots = $rows === '' ? '<div class="helper">No S455 loan lots were frozen for this CT period.</div>'
            : '<h4>Frozen S455 lots</h4><div class="table-scroll"><table><thead><tr><th>Source</th><th>Date</th><th>Year-end balance</th><th>Rate</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $movementTable = $movements === '' ? '' : '<h4>Frozen source movements</h4><div class="table-scroll"><table><thead><tr><th>Transaction</th><th>Date</th><th>Party</th><th>Direction</th><th>Amount</th></tr></thead><tbody>' . $movements . '</tbody></table></div>';
        $repaymentTable = $repayments === '' ? '' : '<h4>Frozen repayment allocations</h4><div class="table-scroll"><table><thead><tr><th>Repayment date</th><th>Source</th><th>Amount</th></tr></thead><tbody>' . $repayments . '</tbody></table></div>';
        return $lots . $movementTable . $repaymentTable;
    }

    private function stat(string $label, string $value): string { return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label) . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value) . '</div></div>'; }
    private function row(string $label, string $value): string { return '<tr><th scope="row">' . \eel_accounts\Support\Utf8::html($label) . '</th><td>' . \eel_accounts\Support\Utf8::html($value) . '</td></tr>'; }
    private function money(array $context, mixed $value): string { return (new \eel_accounts\Service\CompanySettingsService())->money((array)($context['company']['settings'] ?? []), $value); }
}
