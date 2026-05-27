<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _banking_account_formCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'banking_account_form';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_accounts',
                'service' => NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
            [
                'key' => 'LookupCompanyAccount',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccount',
                'params' => [
                    'companyId' => ':company.id',
                    'accountId' => ':edit_account_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function helper(array $context) : string {
        if ((int)($context['edit_account_id'] ?? 0) > 0 || !empty($context['company']['account'])) {
            return 'Edit the details below and click "Save Account" or "Cancel".';
        }
        
        return 'Enter the details below and click "Add Account"!';
    }

    public function title() : string {
        return 'Add or Edit a company account.';
    }

    public function contextTitle(array $context): string
    {
        if ((int)($context['edit_account_id'] ?? 0) > 0 || !empty($context['company']['account'])) {
            return 'Edit bank or trade account.';
        }

        return 'Add a new company account.';
    }

    public function render(array $context): string
    {


        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '<div class="helper">Adding a company account is not possible without adding a company first.</div>';
        }

        $page = (array)($context['page'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $LookupCompanyAccount = is_array($context['services']['LookupCompanyAccount'] ?? null) ? $context['services']['LookupCompanyAccount'] : null;
        $nominalAccounts = (array)($context['services']['nominal_accounts'] ?? []);
        $LookupCompanyAccountId = (int)($LookupCompanyAccount['id'] ?? $context['edit_account_id'] ?? $page['edit_account_id'] ?? 0);
        $bankingMappingAccountId = (int)($page['mapping_account_id'] ?? 0);
        $bankingAccountForm = $this->buildFormState($LookupCompanyAccount, (array)($context['banking_account_form'] ?? $page['banking_account_form'] ?? []));

        $optionsHtml = '';
        foreach (CompanyAccountService::accountTypes() as $accountType => $accountTypeLabel) {
            $selected = (string)$bankingAccountForm['account_type'] === $accountType ? ' selected' : '';
            $optionsHtml .= '<option value="' . HelperFramework::escape($accountType) . '"' . $selected . '>' . HelperFramework::escape($accountTypeLabel) . '</option>';
        }

        return '
            <form method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="Banking">
                <input type="hidden" name="intent" value="' . HelperFramework::escape($LookupCompanyAccount !== null ? 'save' : 'add') . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="account_id" value="' . $LookupCompanyAccountId . '">
                <input type="hidden" name="edit_account_id" value="' . $LookupCompanyAccountId . '">
                <input type="hidden" name="mapping_account_id" value="' . $bankingMappingAccountId . '">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="account_name">Account name</label>
                        <input class="input" id="account_name" name="account_name" value="' . HelperFramework::escape((string)$bankingAccountForm['account_name']) . '" required>
                    </div>
                    <div class="flex-controls">
                        <div class="mini-field">
                            <label for="nominal_account_id">Nominal</label>
                            <select class="select" id="nominal_account_id" name="nominal_account_id">
                                <option value="">Auto assign</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)$bankingAccountForm['nominal_account_id']) . '
                            </select>
                        </div>
                        <div class="mini-field">
                            <label for="account_type">Type</label>
                            <select class="select" id="account_type" name="account_type">' . $optionsHtml . '</select>
                        </div>
                        <div class="mini-field">
                            <label for="internal_transfer_marker">Internal transfer marker</label>
                            <input class="input" id="internal_transfer_marker" name="internal_transfer_marker" value="' . HelperFramework::escape((string)$bankingAccountForm['internal_transfer_marker']) . '" maxlength="6" size="6" placeholder="P2P">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="institution_name">Institution</label>
                        <input class="input" id="institution_name" name="institution_name" value="' . HelperFramework::escape((string)$bankingAccountForm['institution_name']) . '">
                    </div>
                    <div class="form-row">
                        <label for="account_identifier">Identifier</label>
                        <input class="input" id="account_identifier" name="account_identifier" value="' . HelperFramework::escape((string)$bankingAccountForm['account_identifier']) . '" placeholder="Sort code/account mask, card ending, or Account label">
                    </div>
                    <div class="form-row">
                        <label for="contact_name">Contact</label>
                        <input class="input" id="contact_name" name="contact_name" value="' . HelperFramework::escape((string)$bankingAccountForm['contact_name']) . '">
                    </div>
                    <div class="form-row">
                        <label for="phone_number">Phone number</label>
                        <input class="input" id="phone_number" name="phone_number" value="' . HelperFramework::escape((string)$bankingAccountForm['phone_number']) . '" required>
                    </div>
                    <div class="form-row full">
                        <label for="address_line_1">Address line 1</label>
                        <input class="input" id="address_line_1" name="address_line_1" value="' . HelperFramework::escape((string)$bankingAccountForm['address_line_1']) . '" required>
                    </div>
                    <div class="form-row full">
                        <label for="address_line_2">Address line 2</label>
                        <input class="input" id="address_line_2" name="address_line_2" value="' . HelperFramework::escape((string)$bankingAccountForm['address_line_2']) . '">
                    </div>
                    <div class="form-row">
                        <label for="address_locality">Town/City</label>
                        <input class="input" id="address_locality" name="address_locality" value="' . HelperFramework::escape((string)$bankingAccountForm['address_locality']) . '">
                    </div>
                    <div class="form-row">
                        <label for="address_region">Region/County</label>
                        <input class="input" id="address_region" name="address_region" value="' . HelperFramework::escape((string)$bankingAccountForm['address_region']) . '">
                    </div>
                    <div class="form-row">
                        <label for="address_postal_code">Postcode</label>
                        <input class="input" id="address_postal_code" name="address_postal_code" value="' . HelperFramework::escape((string)$bankingAccountForm['address_postal_code']) . '">
                    </div>
                    <div class="form-row">
                        <label for="address_country">Country</label>
                        <input class="input" id="address_country" name="address_country" value="' . HelperFramework::escape((string)$bankingAccountForm['address_country']) . '">
                    </div>
                    <label class="checkbox-item form-row full">
                        <input type="checkbox" name="is_active" value="1"' . (!empty($bankingAccountForm['is_active']) ? ' checked' : '') . '>
                        <div class="checkbox-copy">
                            <strong>Active</strong>
                            <span>Allow future transaction against this account?</span>
                        </div>
                    </label>
                    <div class="form-row">
                        <button class="button primary" type="submit">' . HelperFramework::escape($LookupCompanyAccount !== null ? 'Save Account' : 'Add Account') . '</button>'
                            . ($LookupCompanyAccount !== null
                            ? '<a class="button" data-ajax-link="true">Cancel</a>'
                            : '') . '
                    </div>
                </div>
            </form>
        ';
    }

    private function buildFormState(?array $LookupCompanyAccount, array $pageForm): array
    {
        $defaults = [
            'account_name' => '',
            'account_type' => CompanyAccountService::TYPE_BANK,
            'nominal_account_id' => '',
            'institution_name' => '',
            'account_identifier' => '',
            'internal_transfer_marker' => '',
            'contact_name' => '',
            'phone_number' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'address_locality' => '',
            'address_region' => '',
            'address_postal_code' => '',
            'address_country' => '',
            'is_active' => 1,
        ];

        if ($LookupCompanyAccount !== null) {
            $defaults = array_merge($defaults, $LookupCompanyAccount);
        }

        return array_merge($defaults, $pageForm);
    }

    private function nominalOptions(array $nominalAccounts, string $selectedId): string
    {
        $html = '';
        foreach ($nominalAccounts as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }

            $accountType = (string)($nominal['account_type'] ?? '');
            if (!in_array($accountType, ['asset', 'liability'], true)) {
                continue;
            }

            $id = (string)($nominal['id'] ?? '');
            $html .= '<option value="' . HelperFramework::escape($id) . '"' . ($id === $selectedId ? ' selected' : '') . '>'
                . HelperFramework::escape(FormattingFramework::nominalLabel($nominal, ' '))
                . '</option>';
        }

        return $html;
    }

}
