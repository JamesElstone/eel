<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_claimantsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'expense_claimants';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expensesPageData',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company_id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Expense Claimants';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['expensesPageData'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $claimants = (array)($data['claimants'] ?? []);

        $rows = '';
        foreach ($claimants as $claimant) {
            $claimantId = (int)($claimant['id'] ?? 0);
            $isActive = (int)($claimant['is_active'] ?? 0) === 1;
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($claimant['claimant_name'] ?? '')) . '</td>
                <td><span class="badge ' . ($isActive ? 'success' : 'warning') . '">' . ($isActive ? 'Active' : 'Inactive') . '</span></td>
                <td>
                    <form method="post" action="?page=expenses" data-ajax="true" class="actions-row actions-row-nowrap">
                        <input type="hidden" name="card_action" value="Expense">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="claimant_id" value="' . $claimantId . '">
                        <button class="button button-inline" type="submit" name="intent" value="' . ($isActive ? 'deactivate_claimant' : 'activate_claimant') . '">' . ($isActive ? 'Deactivate' : 'Activate') . '</button>
                    </form>
                </td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="helper">No claimants configured yet. Add one below to enable claim creation.</td></tr>';
        }

        return '<section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">Claimants</h3>
            </div>
            <div class="helper">Manage the people who can submit monthly personal expense claims for this company.</div>
            <form class="toolbar" method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="add_claimant">
                <div class="mini-field">
                    <label for="expense-new-claimant">New claimant</label>
                    <input class="input" id="expense-new-claimant" name="claimant_name" type="text" placeholder="Add claimant">
                </div>
                <button class="button primary" type="submit">Add claimant</button>
            </form>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Claimant</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                </table>
            </div>
        </section>';
    }
}
