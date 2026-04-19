<?php
declare(strict_types=1);

final class _banking_account_formCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'banking_account_form';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'editingCompanyAccount',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccount',
                'params' => [
                    'companyId' => ':company_id',
                    'accountId' => ':edit_account_id',
                ],
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $editingCompanyAccountId = (int)($page['edit_account_id'] ?? 0);
        $bankingMappingAccountId = (int)($page['mapping_account_id'] ?? 0);
        $editingCompanyAccount = is_array($context['services']['editingCompanyAccount'] ?? null) ? $context['services']['editingCompanyAccount'] : null;
        $bankingAccountForm = $this->buildFormState($editingCompanyAccount, (array)($page['banking_account_form'] ?? []));

        $optionsHtml = '';
        foreach (CompanyAccountService::accountTypes() as $accountType => $accountTypeLabel) {
            $selected = (string)$bankingAccountForm['account_type'] === $accountType ? ' selected' : '';
            $optionsHtml .= '<option value="' . HelperFramework::escape($accountType) . '"' . $selected . '>' . HelperFramework::escape($accountTypeLabel) . '</option>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">' . HelperFramework::escape($editingCompanyAccount !== null ? 'Edit Account' : 'Add Account') . '</h2>
            </div>
            <div class="card-body">
                <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('banking', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId])) . '" data-ajax-card-form="true" data-ajax-card-update="banking-accounts,banking-reconciliation,banking-field-mappings,banking-account-form">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                    <input type="hidden" name="account_id" value="' . $editingCompanyAccountId . '">
                    <input type="hidden" name="global_action" value="' . HelperFramework::escape($editingCompanyAccount !== null ? 'save_company_account' : 'add_company_account') . '">
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="account_name">Account name</label>
                            <input class="input" id="account_name" name="account_name" value="' . HelperFramework::escape((string)$bankingAccountForm['account_name']) . '" required>
                        </div>
                        <div class="form-row">
                            <label for="account_type">Type</label>
                            <select class="select" id="account_type" name="account_type">' . $optionsHtml . '</select>
                        </div>
                        <div class="form-row">
                            <label for="institution_name">Institution</label>
                            <input class="input" id="institution_name" name="institution_name" value="' . HelperFramework::escape((string)$bankingAccountForm['institution_name']) . '">
                        </div>
                        <div class="form-row">
                            <label for="account_identifier">Identifier</label>
                            <input class="input" id="account_identifier" name="account_identifier" value="' . HelperFramework::escape((string)$bankingAccountForm['account_identifier']) . '" placeholder="Sort code/account mask, card ending, or ANNA label">
                        </div>
                        <div class="form-row">
                            <label for="internal_transfer_marker">Internal transfer marker</label>
                            <input class="input" id="internal_transfer_marker" name="internal_transfer_marker" value="' . HelperFramework::escape((string)$bankingAccountForm['internal_transfer_marker']) . '" placeholder="P2P">
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
                        <label class="checkbox-item">
                            <input type="checkbox" name="is_active" value="1"' . (!empty($bankingAccountForm['is_active']) ? ' checked' : '') . '>
                            <div class="checkbox-copy">
                                <strong>Active</strong>
                                <span>Allow future transaction against this account?</span>
                            </div>
                        </label>
                    </div>
                    <div class="helper" style="margin-top: 12px;">Internal transfer marker applies only to owned bank accounts and is ignored for trade accounts.</div>
                    <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="button primary" type="submit">' . HelperFramework::escape($editingCompanyAccount !== null ? 'Save Account' : 'Add Account') . '</button>'
                        . ($editingCompanyAccount !== null
                            ? '<a class="button" href="' . HelperFramework::escape($this->buildPageUrl('banking', [
                                'company_id' => $selectedCompanyId,
                                'tax_year_id' => $selectedTaxYearId,
                                'mapping_account_id' => $bankingMappingAccountId,
                            ])) . '" data-ajax-card-link="true" data-ajax-card-update="banking-account-form">Cancel</a>'
                            : '') . '
                    </div>
                </form>
            </div>
        </div>';
    }

    private function buildFormState(?array $editingCompanyAccount, array $pageForm): array
    {
        $defaults = [
            'account_name' => '',
            'account_type' => CompanyAccountService::TYPE_BANK,
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

        if ($editingCompanyAccount !== null) {
            $defaults = array_merge($defaults, $editingCompanyAccount);
        }

        return array_merge($defaults, $pageForm);
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        $query = http_build_query(['page' => $page] + $params);
        return '?' . $query;
    }
}
