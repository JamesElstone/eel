<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _banking_accountsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'banking_accounts';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companyAccounts',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => false,
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function title(): string
    {
        return 'Company Accounts';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function helper(array $context): string{
        if (
            (array)($context['services']['companyAccounts'] ?? []) === []
        ) {
            return 'No bank or trade accounts have been set up for this company yet.';
        }

        return 'Below is a list of accounts for this company.';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyAccounts = (array)($context['services']['companyAccounts'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);

        if ($companyId <= 0) {
            return '<div class="helper">No company accounts can be displayed before a company is added.</div>';
        }

        $rowsHtml = '';

        if ( $companyAccounts === [] ) {
            return '
                <div class="helper">No company accounts have been added to this company yet.</div>
                <button class="button primary" type="button" data-page-card-switch-tab="Add New Account">Add Account</button>
            ';
        }


        foreach ($companyAccounts as $account) {
            $accountId = (int)($account['id'] ?? 0);
            $rowsHtml .= '<tr>
                <td>
                    <div>' . HelperFramework::escape((string)($account['account_name'] ?? '')) . '</div>'
                    . (trim((string)($account['account_identifier'] ?? '')) !== ''
                        ? '<div class="helper">' . HelperFramework::escape((string)$account['account_identifier']) . '</div>'
                        : '') . '
                </td>
                <td>' . HelperFramework::escape(CompanyAccountService::accountTypes()[(string)($account['account_type'] ?? '')] ?? ucfirst((string)($account['account_type'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($account['institution_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($account['account_type'] ?? '') === CompanyAccountService::TYPE_BANK ? (string)($account['internal_transfer_marker'] ?? '') : '') . '</td>
                <td>' . HelperFramework::escape((string)($account['phone_number'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->companyAccountAddressSummary((array)$account)) . '</td>
                <td>' . ((int)($account['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>
                <td>
                    <div>
                        <form method="post" class="actions-row actions-row-nowrap" data-ajax="true">
                            <input type="hidden" name="account_id" value="' . $accountId . '">
                            <input type="hidden" name="field_mapping_account_id" value="' . $accountId . '">
                            <input type="hidden" name="card_action" value="Banking">
                            <button class="button button-inline" name="intent" value="edit" data-show-card="banking_account_form" data-ajax-link="true">Edit</button>
                            <button class="button button-inline" name="intent" value="select_field_mapping" data-show-card="statement_field_mapping" data-ajax-link="true">Field Mappings</button>
                            <button class="button button-inline danger" name="intent" value="delete" data-ajax-link="true" data-chicken-check="true" data-chicken-message="Confirm this logical bank account and all related transactions should be deleted.<br><br>This does not delete data with your bank or any third party." data-chicken-confirm-text="Delete">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>';
        }
    
        return '
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Institution</th>
                        <th>Transfer Marker</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
        ';
    }

    private function companyAccountAddressSummary(array $account): string
    {
        $parts = [];
        foreach (['address_line_1', 'address_line_2', 'address_locality', 'address_region', 'address_postal_code', 'address_country'] as $field) {
            $value = trim((string)($account[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }
}
