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

        if ($companyId <= 0) {
            return '<div class="helper">No company accounts can be displayed before a company is added.</div>';
        }

        if ($companyAccounts === []) {
            return '
                <div class="helper">No company accounts have been added to this company yet.</div>
                <button class="button primary" type="button" data-page-card-switch-tab="Add New Account">Add Account</button>
            ';
        }

        return $this->table($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('company-accounts')
            ->exportLimit(1000)
            ->empty('No company accounts have been added to this company yet.')
            ->toolbarActions($this->toolbarActionsHtml($context))
            ->primarySecondaryColumn(
                'account_name',
                'Name',
                secondaryKey: 'account_identifier'
            )
            ->textColumn('account_type_label', 'Type')
            ->textColumn('nominal_label', 'Nominal')
            ->textColumn('institution_name', 'Institution')
            ->textColumn('transfer_marker', 'Transfer Marker')
            ->textColumn('phone_number', 'Phone')
            ->textColumn('address_summary', 'Address')
            ->textColumn('status_label', 'Status')
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->actionsHtml($row),
                exportable: false
            );
    }

    private function toolbarActionsHtml(array $context): string
    {
        if (!(bool)AppConfigurationStore::get('developer_options', false)) {
            return '';
        }

        $companyId = (int)($context['company']['id'] ?? 0);
        if ($companyId <= 0) {
            return '';
        }

        return '<form method="post" data-ajax="true" class="toolbar">
            <input type="hidden" name="card_action" value="Banking">
            <input type="hidden" name="intent" value="assign_missing_nominals">
            <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)$companyId) . '">
            <button class="button danger" type="submit" data-chicken-check="true" data-chicken-message="Create and assign missing company account nominals?<br><br>Bank accounts use 1001-1099. Trade accounts use 2001-2099." data-chicken-confirm-text="Assign">Assign Missing Nominals</button>
        </form>';
    }

    private function actionsHtml(array $account): string
    {
        $accountId = (int)($account['id'] ?? 0);

        return '<div>
            <form method="post" class="actions-row actions-row-nowrap" data-ajax="true">
                <input type="hidden" name="account_id" value="' . HelperFramework::escape((string)$accountId) . '">
                <input type="hidden" name="field_mapping_account_id" value="' . HelperFramework::escape((string)$accountId) . '">
                <input type="hidden" name="card_action" value="Banking">
                <button class="button button-inline" name="intent" value="edit" data-show-card="banking_account_form" data-ajax-link="true">Edit</button>
                <button class="button button-inline" name="intent" value="select_field_mapping" data-show-card="statement_field_mapping" data-ajax-link="true">Field Mappings</button>
                <button class="button button-inline danger" name="intent" value="delete" data-ajax-link="true" data-chicken-check="true" data-chicken-message="Confirm this logical bank account and all related transactions should be deleted.<br><br>This does not delete data with your bank or any third party." data-chicken-confirm-text="Delete">Delete</button>
            </form>
        </div>';
    }

    private function rows(array $context): array
    {
        $rows = [];

        foreach ((array)($context['services']['companyAccounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountType = (string)($account['account_type'] ?? '');
            $account['account_type_label'] = CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType);
            $account['transfer_marker'] = $accountType === CompanyAccountService::TYPE_BANK
                ? (string)($account['internal_transfer_marker'] ?? '')
                : '';
            $account['nominal_label'] = $this->nominalLabel($account);
            $account['address_summary'] = $this->companyAccountAddressSummary($account);
            $account['status_label'] = (int)($account['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive';

            $rows[] = $account;
        }

        return $rows;
    }

    private function nominalLabel(array $account): string
    {
        $code = trim((string)($account['nominal_code'] ?? ''));
        $name = trim((string)($account['nominal_name'] ?? ''));

        if ($code === '' && $name === '') {
            return 'Not assigned';
        }

        return trim($code . ' ' . $name);
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
