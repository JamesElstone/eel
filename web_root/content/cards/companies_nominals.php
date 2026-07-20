<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_nominalsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_nominals';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'company_nominals',
                'service' => \eel_accounts\Repository\NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
                'params' => ['companyId' => ':company.id'],
            ]
        ];
    }

    public function title(): string {
        return 'Default Nominals for Company';
    }

    public function helper(array $context): string {
        if ((int)($context['company']['id'] ?? 0) <= 0) {
            return 'No company selected';
        }
        return 'These are the default categories that can be applied to a transaction within this company.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        
        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '<div class="helper">No information available until a company is selected.</div>';
        }

        $settings = (array)($context['company']['settings'] ?? []);
        $nominalAccounts = (array)($context['services']['company_nominals'] ?? []);
        $nominalSuggestions = $this->buildNominalDefaultSuggestions($nominalAccounts, $settings);

        $suggestionsHtml = '';
        {
            $suggestionItemsHtml = '';

            $suggestionLabels = [
                'default_bank_nominal_id' => 'Default bank nominal',
                'default_sales_nominal_id' => 'Default sales nominal',
                'default_trade_nominal_id' => 'Default trade nominal',
                'default_expense_nominal_id' => 'Expense claims payable nominal',
                'tools_small_equipment_nominal_id' => 'Tools & Small Equipment nominal',
                'prepayment_asset_nominal_id' => 'Prepayments asset nominal',
                'participator_loan_asset_nominal_id' => 'Participator Loan Asset nominal',
                'participator_loan_liability_nominal_id' => 'Participator Loan Liability nominal',
                'vat_nominal_id' => 'VAT control nominal',
                'uncategorised_nominal_id' => 'Fallback uncategorised nominal',
                'corporation_tax_expense_nominal_id' => 'Corporation Tax expense nominal',
                'corporation_tax_liability_nominal_id' => 'Corporation Tax liability nominal',
            ];
            $suggestionLabels['dividends_payable_nominal_id'] = 'Dividends Payable nominal';
            $suggestionLabels['default_expense_charge_nominal_id'] = 'Default ordinary expense charge nominal';
            foreach (\eel_accounts\Service\AssetService::assetCategoryOptions() as $category => $label) {
                $suggestionLabels[$category . '_asset_cost_nominal_id'] = $label . ' cost nominal';
                $suggestionLabels[$category . '_accum_dep_nominal_id'] = $label . ' accumulated depreciation nominal';
            }

            foreach ($suggestionLabels as $key => $label) {
                if (!isset($nominalSuggestions[$key]) || !is_array($nominalSuggestions[$key])) {
                    continue;
                }

                $suggestionItemsHtml .= '<div class="list-item"><strong>'
                    . HelperFramework::escape($label)
                    . '</strong><span>'
                    . HelperFramework::escape(FormattingFramework::nominalLabel($nominalSuggestions[$key], ' '))
                    . '</span></div>';
            }

            $suggestionsHtml = '
                <div class="panel-soft warn">
                    <h4 class="card-title">Assign system defaults</h4>
                    <span class="helper">This is a suggested assignment based on Good Practice.</span>
                    <div class="list">
                        ' . ($suggestionItemsHtml !== '' ? $suggestionItemsHtml : '<div class="helper">No system defaults are available for the current nominal accounts.</div>') . '
                    </div>
                    <div>
                        <form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                            <input type="hidden" name="card_action" value="Nominals">
                            <input type="hidden" name="intent" value="apply_nominal_suggestions">
                            <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)($context['company']['id'] ?? 0)) . '">
                            <button class="button primary" type="submit"' . ($suggestionItemsHtml === '' ? ' disabled' : '') . '>Assign system defaults</button>
                        </form>
                    </div>
                </div>
            ';
        }

        $helperStateFields = implode(',', (new \eel_accounts\Service\CompanySettingsService())->helperNominalSettingKeys());
        $mainHtml = '
            <form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Nominals">
                <input type="hidden" name="intent" value="save_nominals">
                <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)($context['company']['id'] ?? 0)) . '">
                <section data-state-fields="default_bank_nominal_id,default_sales_nominal_id,default_trade_nominal_id,default_expense_nominal_id,tools_small_equipment_nominal_id,prepayment_asset_nominal_id,participator_loan_asset_nominal_id,participator_loan_liability_nominal_id,vat_nominal_id,uncategorised_nominal_id,corporation_tax_expense_nominal_id,corporation_tax_liability_nominal_id,' . HelperFramework::escape($helperStateFields) . '" data-state-target="save_default_nominals">
                    ' . $this->renderNominalDefaultTable($nominalAccounts, $settings) . '
                    <div>
                        <button class="button primary" id="save_default_nominals" type="submit" disabled>Save Nominals Defaults</button>
                    </div>
                </section>
            </form>
        ';

        return '
            <div class="nominals-layout">
                <div>' . $suggestionsHtml . '</div>
                <div>' . $mainHtml . '</div>
            </div>
        ';
    }

    private function renderNominalDefaultTable(array $nominalAccounts, array $settings): string
    {
        $fields = [
            'default_bank_nominal_id' => 'Default Bank nominal',
            'default_sales_nominal_id' => 'Default Sales nominal',
            'default_trade_nominal_id' => 'Default Trade nominal',
            'default_expense_nominal_id' => 'Expense claims payable nominal',
            'tools_small_equipment_nominal_id' => 'Tools & Small Equipment nominal',
            'prepayment_asset_nominal_id' => 'Prepayments asset nominal',
            'participator_loan_asset_nominal_id' => 'Participator Loan Asset nominal',
            'participator_loan_liability_nominal_id' => 'Participator Loan Liability nominal',
            'vat_nominal_id' => 'Default VAT Control nominal',
            'uncategorised_nominal_id' => 'Fallback nominal for uncategorised Transactions',
            'corporation_tax_expense_nominal_id' => 'Corporation Tax Expense nominal',
            'corporation_tax_liability_nominal_id' => 'Corporation Tax Liability nominal',
            'dividends_payable_nominal_id' => 'Dividends Payable nominal',
            'default_expense_charge_nominal_id' => 'Default ordinary expense charge nominal',
        ];
        foreach (\eel_accounts\Service\AssetService::assetCategoryOptions() as $category => $label) {
            $fields[$category . '_asset_cost_nominal_id'] = $label . ' cost nominal';
            $fields[$category . '_accum_dep_nominal_id'] = $label . ' accumulated depreciation nominal';
        }
        $rowsHtml = '';
        foreach ($fields as $key => $label) {
            $value = (string)($settings[$key] ?? '');
            $options = $key === 'prepayment_asset_nominal_id'
                ? $this->prepaymentNominalOptions($nominalAccounts, $value)
                : $this->nominalOptions($nominalAccounts, $value);
            $rowsHtml .= '<tr><th scope="row"><label for="' . HelperFramework::escape($key) . '">'
                . HelperFramework::escape($label) . '</label></th><td><select class="select" id="' . HelperFramework::escape($key)
                . '" name="' . HelperFramework::escape($key) . '" data-state-default="' . HelperFramework::escape($value)
                . '"><option value="">Select nominal account</option>' . $options . '</select></td></tr>';
        }

        return '<div class="table-scroll"><table class="table"><thead><tr><th>Default</th><th>Nominal account</th></tr></thead><tbody>'
            . $rowsHtml . '</tbody></table></div>';
    }

    private function nominalOptions(array $nominalAccounts, string $selectedId): string
    {
        $html = '';
        foreach ($nominalAccounts as $nominal) {
            $id = (string)($nominal['id'] ?? '');
            $html .= '<option value="' . HelperFramework::escape($id) . '"' . ($id === $selectedId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal, ' ')) . '</option>';
        }
        return $html;
    }

    private function prepaymentNominalOptions(array $nominalAccounts, string $selectedId): string
    {
        return $this->nominalOptions(array_values(array_filter(
            $nominalAccounts,
            static fn(array $nominal): bool => strtolower(trim((string)($nominal['account_type'] ?? ''))) === 'asset'
                && strtolower(trim((string)($nominal['subtype_code'] ?? ''))) === 'prepayments'
        )), $selectedId);
    }

    private function buildNominalDefaultSuggestions(array $nominalAccounts, array $settings): array
    {
        $normalised = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'code' => trim((string)($row['code'] ?? '')),
                'name' => trim((string)($row['name'] ?? '')),
                'account_type' => strtolower(trim((string)($row['account_type'] ?? ''))),
                'subtype_code' => strtolower(trim((string)($row['subtype_code'] ?? ''))),
            ];
        }, $nominalAccounts);

        $suggestions = array_filter([
            'default_bank_nominal_id' => !$this->hasAssignedNominal($settings, 'default_bank_nominal_id')
                ? $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && ($row['subtype_code'] === 'bank' || $row['code'] === '1200' || str_contains(strtolower($row['name']), 'bank')))
                : null,
            'default_sales_nominal_id' => !$this->hasAssignedNominal($settings, 'default_sales_nominal_id')
                ? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);
                    return $row['id'] > 0
                        && $row['account_type'] === 'income'
                        && ($row['subtype_code'] === 'turnover' || $row['code'] === '4000' || str_contains($name, 'sales'));
                })
                : null,
            'default_trade_nominal_id' => !$this->hasAssignedNominal($settings, 'default_trade_nominal_id')
                ? ($this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && $row['code'] === '2300')
                    ?? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);
                    return $row['id'] > 0
                        && $row['account_type'] === 'liability'
                        && ($row['subtype_code'] === 'trade_creditor'
                            || str_contains($name, 'trade creditor'));
                }))
                : null,
            'default_expense_nominal_id' => !$this->hasAssignedNominal($settings, 'default_expense_nominal_id')
                ? ($this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);
                    return $row['id'] > 0
                        && $row['account_type'] === 'liability'
                        && ($row['subtype_code'] === 'expense_payable'
                            || $row['code'] === '2110'
                            || str_contains($name, 'expense claims payable'));
                }) ?? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);
                    return $row['id'] > 0 && $row['account_type'] === 'expense' && !str_contains($name, 'director loan') && !str_contains($name, 'vat') && !str_contains($name, 'tax');
                }))
                : null,
            'tools_small_equipment_nominal_id' => !$this->hasAssignedNominal($settings, 'tools_small_equipment_nominal_id')
                ? ($this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && $row['code'] === '6070')
                    ?? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);
                    return $row['id'] > 0 && $row['account_type'] === 'expense' && str_contains($name, 'tools') && str_contains($name, 'equipment');
                }))
                : null,
            'prepayment_asset_nominal_id' => !$this->hasAssignedNominal($settings, 'prepayment_asset_nominal_id')
                ? $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0
                    && $row['account_type'] === 'asset'
                    && $row['subtype_code'] === 'prepayments')
                : null,
            'participator_loan_asset_nominal_id' => !$this->hasAssignedNominal($settings, 'participator_loan_asset_nominal_id')
                ? $this->directorLoanAssetNominalSuggestion($normalised)
                : null,
            'participator_loan_liability_nominal_id' => !$this->hasAssignedNominal($settings, 'participator_loan_liability_nominal_id')
                ? $this->directorLoanLiabilityNominalSuggestion($normalised)
                : null,
            'vat_nominal_id' => !$this->hasAssignedNominal($settings, 'vat_nominal_id')
                ? $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && ($row['subtype_code'] === 'vat_control' || str_contains(strtolower($row['name']), 'vat') || str_contains(strtolower($row['code']), 'vat')))
                : null,
            'uncategorised_nominal_id' => !$this->hasAssignedNominal($settings, 'uncategorised_nominal_id')
                ? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);
                    return $row['id'] > 0 && ($row['code'] === '9999' || str_contains($name, 'uncategorised') || str_contains($name, 'unclassified'));
                })
                : null,
            'corporation_tax_expense_nominal_id' => !$this->hasAssignedNominal($settings, 'corporation_tax_expense_nominal_id')
                ? $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && $row['account_type'] === 'expense' && $row['subtype_code'] === 'corp_tax_expense')
                : null,
            'corporation_tax_liability_nominal_id' => !$this->hasAssignedNominal($settings, 'corporation_tax_liability_nominal_id')
                ? $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && $row['account_type'] === 'liability' && $row['subtype_code'] === 'corp_tax')
                : null,
        ], static fn(?array $row): bool => $row !== null);
        $byCode = [];
        foreach ($normalised as $row) {
            $byCode[(string)$row['code']] = $row;
        }
        $codeSettings = [
            'dividends_payable_nominal_id' => '2150',
            'default_expense_charge_nominal_id' => '6000',
        ];
        foreach (\eel_accounts\Service\AssetService::assetCategoryOptions() as $category => $_label) {
            $codes = \eel_accounts\Service\AssetService::assetNominalCodesForCategory((string)$category);
            $codeSettings[$category . '_asset_cost_nominal_id'] = (string)$codes['cost'];
            $codeSettings[$category . '_accum_dep_nominal_id'] = (string)$codes['accum'];
        }
        foreach ($codeSettings as $key => $code) {
            if (!$this->hasAssignedNominal($settings, $key) && isset($byCode[$code])) {
                $suggestions[$key] = $byCode[$code];
            }
        }

        return $suggestions;
    }

    private function hasAssignedNominal(array $settings, string $key): bool
    {
        return (int)($settings[$key] ?? 0) > 0;
    }

    private function directorLoanAssetNominalSuggestion(array $nominals): ?array
    {
        return $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['subtype_code'] === 'director_loan_asset'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'asset'
                && $row['code'] === '1200'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'asset'
                && str_contains(strtolower($row['name']), 'director loan')
        );
    }

    private function directorLoanLiabilityNominalSuggestion(array $nominals): ?array
    {
        return $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['subtype_code'] === 'director_loan_liability'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'liability'
                && $row['code'] === '2100'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'liability'
                && str_contains(strtolower($row['name']), 'director loan')
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && str_contains(strtolower($row['name']), 'director loan')
        );
    }

    private function firstMatchingNominal(array $nominals, callable $predicate): ?array
    {
        foreach ($nominals as $nominal) {
            if ($predicate($nominal)) {
                return $nominal;
            }
        }
        return null;
    }
}
