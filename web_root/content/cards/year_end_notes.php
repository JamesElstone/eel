<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_notesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_notes';
    }

    public function title(): string
    {
        return 'Year End Notes';
    }

    public function helper(array $context): string
    {
        return 'Record any notes for the year here, to be kept with the accounting period.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist', 'year.end.audit.log'];
    }

    public function services(): array
    {
        return [
            [
                'key' => 'year_end_review',
                'service' => \eel_accounts\Service\YearEndLockService::class,
                'method' => 'fetchReview',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="helper">Year-end notes are not available for the selected accounting period.</div>';
        }

        $review = (array)(($context['services'] ?? [])['year_end_review'] ?? []);
        if ($review === []) {
            $review = (array)(($this->checklist($context))['review'] ?? []);
        }
        $notes = (string)($review['review_notes'] ?? '');
        $isLocked = !empty($review['is_locked'])
            || (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);

        if ($isLocked) {
            return '
            <section class="settings-stack">
                <div class="helper"><span class="badge warning">Period locked</span> Year End notes are read only.</div>
                <div class="form-row full">
                    <textarea class="input year-end-review-notes" id="year-end-review-notes" aria-label="Year end notes" readonly>' . HelperFramework::escape($notes) . '</textarea>
                </div>
            </section>';
        }

        return '
            <section class="settings-stack">
                <form method="post" data-ajax="true" class="settings-stack">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="save_notes">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <div class="form-row full">
                        <textarea class="input year-end-review-notes" id="year-end-review-notes" name="review_notes" aria-label="Year end notes">' . HelperFramework::escape($notes) . '</textarea>
                    </div>
                    <div><button class="button primary" type="submit">Save Notes</button></div>
                </form>
            </section>';
    }

    private function checklist(array $context): array
    {
        return (array)(($context['year_end'] ?? [])['checklist'] ?? (($context['services'] ?? [])['yearEndChecklist'] ?? []));
    }
}
