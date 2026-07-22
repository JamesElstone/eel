<?php
declare(strict_types=1);

final class _loan_reviewCard extends CardBaseFramework
{
    public function key(): string { return 'loan_review'; }
    public function title(): string { return 'Loan Review'; }
    public function helper(array $context): string
    {
        return 'HMRC requires participator-loan movements, parties, and Section 464A/464C conclusions to be supported before the Corporation Tax position is confirmed.';
    }
    public function services(): array
    {
        return [[
            'key' => 'loanReview',
            'service' => \eel_accounts\Service\LoanReviewService::class,
            'method' => 'fetch',
            'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
        ]];
    }
    protected function additionalInvalidationFacts(): array { return ['director.loan.state', 'tax.s455', 'tax.workings', 'year.end.checklist']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $review = (array)($context['services']['loanReview'] ?? []);
        if (empty($review['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($review['errors'] ?? [])[0] ?? 'Loan review is unavailable.')) . '</div>';
        }
        $items = (array)($review['items'] ?? []);
        if ($items === []) {
            return '<div class="panel-soft"><span class="badge success">No review items</span> <span class="helper">No unresolved participator-loan tax items remain.</span></div>';
        }
        $rows = '';
        foreach ($items as $item) {
            $evidence = trim((string)($item['source_url'] ?? '')) !== ''
                ? '<a class="button button-inline"' . (str_starts_with((string)$item['source_url'], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '') . ' href="' . HelperFramework::escape((string)$item['source_url']) . '">' . HelperFramework::escape((string)$item['source_label']) . '</a>'
                : '';
            $rows .= '<tr><td><span class="badge danger">' . ((string)($item['state'] ?? '') === 'stale' ? 'Stale' : 'Action required') . '</span></td><td><strong>'
                . HelperFramework::escape((string)$item['title']) . '</strong><div class="helper">' . HelperFramework::escape((string)$item['detail']) . '</div></td><td>' . $evidence . '</td><td><a class="button primary" href="'
                . HelperFramework::escape((string)$item['action_url']) . '">' . HelperFramework::escape((string)$item['action_label']) . '</a></td></tr>';
        }
        return '<div class="table-scroll"><table><thead><tr><th>State</th><th>Issue</th><th>Evidence</th><th>Resolve</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
