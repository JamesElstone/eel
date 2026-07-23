<?php
declare(strict_types=1);

final class _corporation_tax_reviewCard extends CardBaseFramework
{
    public function key(): string { return 'corporation_tax_review'; }
    public function title(): string { return 'Corporation Tax Review'; }
    public function helper(array $context): string
    {
        return 'HMRC requires the company to review tax-sensitive costs against the services or assets concerned. Select the actual treatment for each line; do not mark professional fees allowable without checking what the services concerned.';
    }
    public function services(): array
    {
        return [[
            'key' => 'corporationTaxReview',
            'service' => \eel_accounts\Service\CorporationTaxLineTreatmentService::class,
            'method' => 'fetchReview',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'ctPeriodId' => ':tax.selected_ct_period_id',
            ],
        ]];
    }
    protected function additionalInvalidationFacts(): array
    {
        return ['tax.review', 'tax.workings', 'profit.loss', 'year.end.checklist', 'ixbrl.readiness', 'ixbrl.facts.preview', 'ixbrl.generation'];
    }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $review = (array)($context['services']['corporationTaxReview'] ?? []);
        if (empty($review['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($review['errors'] ?? [])[0] ?? 'Corporation Tax review is unavailable.')) . '</div>';
        }
        $items = (array)($review['items'] ?? []);
        if ($items === []) {
            return '<div class="panel-soft"><span class="badge success">No review items</span> <span class="helper">No posted Corporation Tax treatment lines currently require review.</span></div>';
        }
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $ctPeriodId = (int)($context['tax']['selected_ct_period_id'] ?? 0);
        $money = new \eel_accounts\Service\MoneyFormatService();
        $settings = (array)($context['company']['settings'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $treatment = (string)($item['tax_treatment'] ?? '');
            $options = '<option value="" disabled' . ($treatment === '' ? ' selected' : '') . '>Choose treatment</option>';
            foreach (['allowable' => 'Allowable', 'disallowable' => 'Disallowable', 'capital' => 'Capital'] as $value => $label) {
                $options .= '<option value="' . $value . '"' . ($treatment === $value ? ' selected' : '') . '>' . $label . '</option>';
            }
            $guidance = trim((string)($item['guidance_url'] ?? '')) !== ''
                ? '<a class="button button-inline" target="_blank" rel="noopener noreferrer" href="' . HelperFramework::escape((string)$item['guidance_url']) . '">BIM guidance</a>'
                : '';
            $source = trim((string)($item['source_url'] ?? '')) !== ''
                ? '<a class="button button-inline" href="' . HelperFramework::escape((string)$item['source_url']) . '">' . HelperFramework::escape((string)$item['source_label']) . '</a>'
                : HelperFramework::escape((string)$item['source_label']);
            $form = '<form method="post" action="?page=corporation_tax" data-ajax="true" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Tax"><input type="hidden" name="intent" value="save_line_tax_treatment">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '"><input type="hidden" name="journal_line_id" value="' . (int)$item['journal_line_id'] . '">'
                . '<select class="select" name="tax_treatment" required data-submit-on-change="true">' . $options . '</select></form>';
            $state = (string)($item['state'] ?? '') === 'resolved'
                ? '<span class="badge success">' . HelperFramework::escape(ucfirst($treatment)) . '</span>'
                : '<span class="badge danger">Review required</span>';
            $rows .= '<tr><td>' . HelperFramework::escape(HelperFramework::displayDate((string)$item['journal_date'])) . '</td><td><strong>'
                . HelperFramework::escape((string)$item['description']) . '</strong><div class="helper">' . HelperFramework::escape(trim((string)$item['nominal_code'] . ' ' . (string)$item['nominal_name'])) . '</div></td><td class="numeric">'
                . HelperFramework::escape($money->format($settings, $item['amount'] ?? 0)) . '</td><td>' . $state . '</td><td>' . $source . ' ' . $guidance . '</td><td>' . $form . '</td></tr>';
        }
        return '<section class="settings-stack"><div class="summary-grid four"><div class="summary-card"><div class="summary-label">Requires treatment</div><div class="summary-value">'
            . (int)($review['unresolved_count'] ?? 0) . '</div></div><div class="summary-card"><div class="summary-label">Treatment saved</div><div class="summary-value">'
            . (int)($review['resolved_count'] ?? 0) . '</div></div></div><div class="table-scroll"><table><thead><tr><th>Date</th><th>Item</th><th class="numeric">Amount</th><th>Line state</th><th>Evidence</th><th>Tax treatment</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }
}
