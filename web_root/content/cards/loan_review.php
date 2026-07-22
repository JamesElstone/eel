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
        $futureWarning = (array)($review['future_attribution_warning'] ?? []);
        $showFutureWarning = (int)($futureWarning['count'] ?? 0) > 0 && empty($futureWarning['acknowledged']);
        if ($items === [] && !$showFutureWarning) {
            return '<div class="panel-soft"><span class="badge success">No review items</span> <span class="helper">No unresolved participator-loan tax items remain.</span></div>';
        }
        $futureWarningHtml = $showFutureWarning
            ? $this->futureAttributionWarning(
                (array)($futureWarning['movements'] ?? []),
                (int)($context['company']['id'] ?? 0),
                (int)($context['company']['accounting_period_id'] ?? 0)
            )
            : '';
        $rows = '';
        foreach ($items as $item) {
            $evidence = trim((string)($item['source_url'] ?? '')) !== ''
                ? '<a class="button button-inline"' . (str_starts_with((string)$item['source_url'], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '') . ' href="' . HelperFramework::escape((string)$item['source_url']) . '">' . HelperFramework::escape((string)$item['source_label']) . '</a>'
                : '';
            $rows .= '<tr><td><span class="badge danger">' . ((string)($item['state'] ?? '') === 'stale' ? 'Stale' : 'Action required') . '</span></td><td><strong>'
                . HelperFramework::escape((string)$item['title']) . '</strong><div class="helper">' . HelperFramework::escape((string)$item['detail']) . '</div></td><td>' . $evidence . '</td><td><a class="button primary" href="'
                . HelperFramework::escape((string)$item['action_url']) . '">' . HelperFramework::escape((string)$item['action_label']) . '</a></td></tr>';
        }
        $blockingHtml = $rows !== ''
            ? '<section class="panel-soft settings-stack"><h3 class="card-title">Items required for this accounting period</h3><div class="table-scroll"><table><thead><tr><th>State</th><th>Issue</th><th>Evidence</th><th>Resolve</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
            : '';
        return '<section class="settings-stack">' . $futureWarningHtml . $blockingHtml . '</section>';
    }

    /** @param list<array<string,mixed>> $movements */
    private function futureAttributionWarning(array $movements, int $companyId, int $accountingPeriodId): string
    {
        $rows = '';
        foreach ($movements as $movement) {
            $sourceUrl = (string)($movement['source_url'] ?? '');
            $source = $sourceUrl !== ''
                ? '<a class="button button-inline" href="' . HelperFramework::escape($sourceUrl) . '">' . HelperFramework::escape((string)($movement['source_label'] ?? 'Source transaction')) . '</a>'
                : HelperFramework::escape((string)($movement['source_label'] ?? ''));
            $rows .= '<tr><td>' . HelperFramework::escape(HelperFramework::displayDate((string)($movement['txn_date'] ?? ''))) . '</td><td>'
                . $source . '</td><td>' . HelperFramework::escape(ucfirst((string)($movement['cash_direction'] ?? 'movement'))) . '</td></tr>';
        }
        return '<section class="panel-soft warn settings-stack">
            <div class="summary-card-header"><h3 class="card-title">Optional future repayment attribution</h3><span class="badge warning">Not a blocker</span></div>
            <div class="helper">These transactions occurred after this accounting period. They only need a participant if the company intends to rely on them to reduce the s455 charge. They do not prevent the current year-end or Corporation Tax position from being completed.</div>
            <div class="helper"><strong>No accounting period will be changed from this card.</strong> Open a source transaction for evidence only. To claim repayment relief later, deliberately select that transaction’s accounting period on Loans and assign its participant there.</div>
            <div class="table-scroll"><table><thead><tr><th>Date</th><th>Source transaction</th><th>Direction</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
            <form method="post" action="?page=loans" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="LoanReview"><input type="hidden" name="intent" value="acknowledge_future_loan_attribution_warning">
                <input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button" type="submit">OK — do not use these transactions for s455 relief</button>
            </form>
        </section>';
    }
}
