<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_add_claimantCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'expense_add_claimant';
    }

    public function title(): string
    {
        return 'Add Claimant';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['expense.claimants'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $hasCompany = $companyId > 0;
        $addDisabled = $hasCompany ? '' : ' disabled';
        $addHelper = $hasCompany
            ? ''
            : '<div class="helper">Select or add a company before configuring expense claimants.</div>';

        return $addHelper . '
            <form class="expense-claimant-add-form" method="post" action="?page=expense_claims" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="add_claimant">
                <div class="mini-field">
                    <label for="expense-new-claimant">New claimant</label>
                    <input class="input" id="expense-new-claimant" name="claimant_name" type="text" placeholder="Claimant\'s Name"' . $addDisabled . '>
                </div>
                <button class="button primary" type="submit"' . $addDisabled . '>Add Claimant</button>
            </form>';
    }
}
