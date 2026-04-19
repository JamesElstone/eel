<?php
declare(strict_types=1);

final class _banking_accountsCard implements CardInterfaceFramework
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
                    'companyId' => ':company_id',
                    'activeOnly' => false,
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
        $companyAccounts = (array)($context['services']['companyAccounts'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $bankingMappingAccountId = (int)($page['mapping_account_id'] ?? 0);
        $editingCompanyAccountId = (int)($page['edit_account_id'] ?? 0);

        if ($companyAccounts === []) {
            return '<div class="card">
                <div class="card-header">
                    <h2 class="card-title">Accounts</h2>
                </div>
                <div class="card-body">
                    <div class="helper">No bank or trade accounts have been set up for this company yet.</div>
                </div>
            </div>';
        }

        $rowsHtml = '';
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
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a class="button" href="' . HelperFramework::escape($this->buildPageUrl('banking', [
                            'company_id' => $selectedCompanyId,
                            'tax_year_id' => $selectedTaxYearId,
                            'edit_account_id' => $accountId,
                            'mapping_account_id' => $bankingMappingAccountId,
                        ])) . '" data-ajax-card-link="true" data-ajax-card-update="banking-accounts,banking-account-form">Edit</a>
                        <a class="button" href="' . HelperFramework::escape($this->buildPageUrl('banking', [
                            'company_id' => $selectedCompanyId,
                            'tax_year_id' => $selectedTaxYearId,
                            'mapping_account_id' => $accountId,
                            'edit_account_id' => $editingCompanyAccountId,
                        ])) . '" data-ajax-card-link="true" data-ajax-card-update="banking-field-mappings">Field Mappings</a>
                        <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('banking', [
                            'company_id' => $selectedCompanyId,
                            'tax_year_id' => $selectedTaxYearId,
                        ])) . '" style="display: inline;" data-ajax-card-form="true" data-ajax-card-update="banking-accounts,banking-reconciliation,banking-field-mappings,banking-account-form">
                            <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                            <input type="hidden" name="account_id" value="' . $accountId . '">
                            <input type="hidden" name="global_action" value="delete_company_account">
                            <button class="button danger" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Accounts</h2>
            </div>
            <div class="card-body">
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
            </div>
        </div>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        $query = http_build_query(['page' => $page] + $params);
        return '?' . $query;
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
