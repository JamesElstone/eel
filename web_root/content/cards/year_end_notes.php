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

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $checklist = $this->checklist($context);
        if ($checklist === []) {
            return '<div class="helper">Year-end notes are not available for the selected accounting period.</div>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($checklist['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $review = (array)($checklist['review'] ?? []);

        return '
            <section class="settings-stack">
                <form method="post" data-ajax="true" class="settings-stack">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="save_notes">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <div class="form-row full">
                        <textarea class="input year-end-review-notes" id="year-end-review-notes" name="review_notes" aria-label="Year end notes">' . HelperFramework::escape((string)($review['review_notes'] ?? '')) . '</textarea>
                    </div>
                    <div><button class="button primary" type="submit">Save notes</button></div>
                </form>
            </section>';
    }

    private function checklist(array $context): array
    {
        return (array)(($context['year_end'] ?? [])['checklist'] ?? (($context['services'] ?? [])['yearEndChecklist'] ?? []));
    }
}
