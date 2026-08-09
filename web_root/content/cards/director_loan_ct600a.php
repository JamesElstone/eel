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

    public function tables(array $context): array
    {
        $data = (array)($context['services']['ct600a'] ?? []);
        $company = (array)($context['company'] ?? []);
        $tables = [];
        foreach ((array)($data['periods'] ?? []) as $ct) {
            if (is_array($ct)) {
                $tables[] = $this->summaryTable($ct, $company);
            }
        }

        return $tables;
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['ct600a'] ?? []);
        if (empty($data['available'])) {
            return '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)(($data['errors'] ?? [])[0] ?? 'CT600A evidence is unavailable.')) . '</div>';
        }
        $company = (array)($context['company'] ?? []);
        $html = '<div class="settings-stack"><div class="actions-row">
            <a class="button button-inline" target="_blank" rel="noopener noreferrer" href="https://www.gov.uk/guidance/supplementary-pages-ct600a-2015-version-3-close-company-loans-and-arrangements-to-confer-benefits-on-participators">HMRC: CT600A Guidance</a>
            <a class="button button-inline" target="_blank" rel="noopener noreferrer" href="https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm61570">HMRC: Section 464A</a>
            <a class="button button-inline" target="_blank" rel="noopener noreferrer" href="https://www.legislation.gov.uk/ukpga/2010/4/section/464C">Legislation: Section 464C</a>
        </div>';
        $html .= $this->reviewEvidenceStatus((array)($data['review'] ?? []));
        foreach ((array)$data['periods'] as $ct) {
            $errors = (array)($ct['blocking_errors'] ?? []);
            $complete = !array_key_exists('complete', $ct) ? $errors === [] : !empty($ct['complete']);
            $html .= '<section class="panel-soft settings-stack"><div class="status-head"><h3 class="card-title">Tax period '
                . (int)($ct['display_sequence_no'] ?? $ct['sequence_no']) . ' — ' . \eel_accounts\Support\Utf8::html((string)$ct['period_start']) . ' to '
                . \eel_accounts\Support\Utf8::html((string)$ct['period_end']) . '</h3>'
                . ($complete ? '<span class="badge success">Ready</span>' : '')
                . '<span class="badge info">CT600A required: ' . (!empty($ct['required']) ? 'Yes' : 'No') . '</span></div>';
            $html .= $this->summaryTable($ct, $company)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
            ]);
            foreach ($errors as $error) {
                $html .= '<div class="standout helper">' . \eel_accounts\Support\Utf8::html((string)$error) . '</div>';
            }
            foreach ((array)($ct['evidence_warnings'] ?? []) as $warning) {
                $html .= '<div class="panel-soft warn helper">' . \eel_accounts\Support\Utf8::html((string)$warning) . '</div>';
            }
            $html .= '</section>';
        }
        return $html . '</div>';
    }

    private function reviewEvidenceStatus(array $review): string
    {
        $stored = !empty($review['stored']);
        $current = !empty($review['current']);
        $complete = !empty($review['complete']);
        $badge = $current && $complete
            ? '<span class="badge success">Reviewed</span>'
            : '<span class="badge danger">' . ($stored ? 'Review required' : 'Not approved') . '</span>';
        $detail = $stored && !$current
            ? 'The underlying evidence has changed. Review the declaration answers and approve it again.'
            : ($current && $complete
                ? 'The declaration is current for the participator-loan evidence shown below.'
                : 'Complete the declaration before approving the Corporation Tax filing basis.');
        return '<section class="panel-soft settings-stack"><div class="summary-card-header"><h3 class="card-title">Section 464A and 464C evidence status</h3>'
            . $badge . '</div><div class="helper">' . \eel_accounts\Support\Utf8::html($detail) . '</div>'
            . '<div class="actions-row"><a class="button primary" href="?page=loans&amp;show_card=year_end_loan_confirmation">'
            . ($stored ? 'Review in Year End Confirmation' : 'Complete Year End Confirmation')
            . '</a></div></section>';
    }
    private function summaryTable(array $ct, array $company): TableFramework
    {
        $ctPeriodId = (int)($ct['ct_period_id'] ?? 0);
        $sequenceNo = (int)($ct['sequence_no'] ?? 0);
        $identifier = $ctPeriodId > 0 ? (string)$ctPeriodId : 'sequence-' . $sequenceNo;

        return \eel_accounts\Support\Utf8Table::make('director_loan_ct600a_summary_' . $identifier, $this->summaryRows($ct))
            ->filename('ct600a-summary-ct-period-' . ($sequenceNo > 0 ? $sequenceNo : $identifier))
            ->exportLimit(6)
            ->empty('No CT600A summary values are available for this CT period.')
            ->textColumn('name', 'Name')
            ->textColumn('reference', 'Reference')
            ->column(
                'value',
                'Value',
                html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($company, (float)($row['value'] ?? 0))),
                export: static fn(array $row): string => number_format((float)($row['value'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function summaryRows(array $ct): array
    {
        return [
            ['name' => 'Current-period loans/benefits outstanding at Year End', 'reference' => 'A15', 'value' => (float)($ct['part1']['total_loans'] ?? 0)],
            ['name' => 'Gross tax due on A15', 'reference' => 'A20', 'value' => (float)($ct['part1']['tax_chargeable'] ?? 0)],
            ['name' => 'Tax relief on qualifying repayments made within 9 months and 1 day (Early Relief)', 'reference' => 'A45', 'value' => (float)($ct['part2']['relief_due'] ?? 0)],
            ['name' => 'Tax relief due on qualifying repayments made later (Late Relief)', 'reference' => 'A70', 'value' => (float)($ct['part3']['relief_due'] ?? 0)],
            ['name' => 'Total outstanding balance at Year End (all periods)', 'reference' => 'A75', 'value' => (float)($ct['total_loans_outstanding'] ?? 0)],
            ['name' => 'Net Tax Owed (A20 - A45 - A70)', 'reference' => 'A80', 'value' => (float)($ct['tax_payable'] ?? 0)],
        ];
    }
    private function money(array $company, float $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money((array)($company['settings'] ?? []), $value);
    }
}
