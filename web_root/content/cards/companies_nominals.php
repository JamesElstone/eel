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
        if (trim((string)($settings['director_loan_liability_nominal_id'] ?? '')) === ''
            && trim((string)($settings['director_loan_nominal_id'] ?? '')) !== '') {
            $settings['director_loan_liability_nominal_id'] = $settings['director_loan_nominal_id'];
        }
        $nominalAccounts = (array)($context['services']['company_nominals'] ?? []);
        $nominalSuggestions = $this->buildNominalDefaultSuggestions($nominalAccounts, $settings);
        $assetMappingsHtml = $this->renderAssetNominalMappings($nominalAccounts);

        $suggestionsHtml = '';
        if ($nominalSuggestions !== []) {
            $suggestionItemsHtml = '';

            $suggestionLabels = [
                'default_bank_nominal_id' => 'Default bank nominal',
                'default_trade_nominal_id' => 'Default trade nominal',
                'default_expense_nominal_id' => 'Expense claims payable nominal',
                'tools_small_equipment_nominal_id' => 'Tools & Small Equipment nominal',
                'director_loan_asset_nominal_id' => 'Director Loan Asset nominal',
                'director_loan_liability_nominal_id' => 'Director Loan Liability nominal',
                'vat_nominal_id' => 'VAT control nominal',
                'uncategorised_nominal_id' => 'Fallback uncategorised nominal',
            ];

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
                    <h4 class="card-title">Suggested Nominal Assignments:</h4>
                    <span class="helper">This is a suggested assignment based on Good Practice.</span>
                    <div class="list">
                        ' . $suggestionItemsHtml . '
                    </div>
                    <div>
                        <form method="post" data-ajax="true">
                            <input type="hidden" name="card_action" value="Nominals">
                            <input type="hidden" name="intent" value="apply_nominal_suggestions">
                            <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)($context['company']['id'] ?? 0)) . '">
                            <button class="button primary" type="submit">Use Suggested Assignments</button>
                        </form>
                    </div>
                </div>
            ';
        }

        $mainHtml = '
            <form method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="Nominals">
                <input type="hidden" name="intent" value="save_nominals">
                <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)($context['company']['id'] ?? 0)) . '">
                <div class="panel-soft">
                    <section data-state-fields="default_bank_nominal_id,default_trade_nominal_id,default_expense_nominal_id,tools_small_equipment_nominal_id,director_loan_asset_nominal_id,director_loan_liability_nominal_id,vat_nominal_id,uncategorised_nominal_id" data-state-target="save_default_nominals">
                    <div class="form-flex-flow">
                        <div class="form-row">
                            <label for="default_bank_nominal_id">Default Bank nominal</label>
                            <select class="select" id="default_bank_nominal_id" name="default_bank_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['default_bank_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['default_bank_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="default_trade_nominal_id">Default Trade nominal</label>
                            <select class="select" id="default_trade_nominal_id" name="default_trade_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['default_trade_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['default_trade_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="default_expense_nominal_id">Expense claims payable nominal</label>
                            <select class="select" id="default_expense_nominal_id" name="default_expense_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['default_expense_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['default_expense_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="tools_small_equipment_nominal_id">Tools &amp; Small Equipment nominal</label>
                            <select class="select" id="tools_small_equipment_nominal_id" name="tools_small_equipment_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['tools_small_equipment_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['tools_small_equipment_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="director_loan_asset_nominal_id">Director Loan Asset nominal</label>
                            <select class="select" id="director_loan_asset_nominal_id" name="director_loan_asset_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['director_loan_asset_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['director_loan_asset_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="director_loan_liability_nominal_id">Director Loan Liability nominal</label>
                            <select class="select" id="director_loan_liability_nominal_id" name="director_loan_liability_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['director_loan_liability_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['director_loan_liability_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="vat_nominal_id">Default VAT Control nominal</label>
                            <select class="select" id="vat_nominal_id" name="vat_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['vat_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['vat_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="uncategorised_nominal_id">Fallback nominal for uncategorised Transactions</label>
                            <select class="select" id="uncategorised_nominal_id" name="uncategorised_nominal_id" data-state-default="' . HelperFramework::escape((string)($settings['uncategorised_nominal_id'] ?? '')) . '">
                                <option value="">Select nominal account</option>
                                ' . $this->nominalOptions($nominalAccounts, (string)($settings['uncategorised_nominal_id'] ?? '')) . '
                            </select>
                        </div>
                    </div>
                    <div>
                        <button class="button primary" id="save_default_nominals" type="submit" disabled>Save Nominals Defaults</button>
                    </div>
                    </section>
                </div>
            </form>
        ';

        return '
            <div class="nominals-layout">
                <div>' . $mainHtml . '</div>
                <div>' . $suggestionsHtml . $assetMappingsHtml . '</div>
            </div>
        ';
    }

    private function renderAssetNominalMappings(array $nominalAccounts): string
    {
        $nominalsByCode = [];
        foreach ($nominalAccounts as $nominal) {
            $code = trim((string)($nominal['code'] ?? ''));
            if ($code !== '') {
                $nominalsByCode[$code] = $nominal;
            }
        }

        $items = '';
        foreach (\eel_accounts\Service\AssetService::assetCategoryOptions() as $category => $label) {
            $codes = \eel_accounts\Service\AssetService::assetNominalCodesForCategory((string)$category);
            $costNominal = $nominalsByCode[(string)$codes['cost']] ?? null;
            $accumNominal = $nominalsByCode[(string)$codes['accum']] ?? null;
            $status = is_array($costNominal) && is_array($accumNominal) ? 'Ready' : 'Missing setup';
            $items .= '<div class="list-item"><strong>'
                . HelperFramework::escape((string)$label)
                . '</strong><span>'
                . HelperFramework::escape($status . ': cost ' . $this->mappingNominalLabel($costNominal, (string)$codes['cost']) . ', accumulated depreciation ' . $this->mappingNominalLabel($accumNominal, (string)$codes['accum']))
                . '</span></div>';
        }

        return '<div class="panel-soft">
            <h4 class="card-title">Asset Nominal Mappings</h4>
            <span class="helper">Asset claim lines use these shared fixed asset mappings automatically.</span>
            <div class="list">' . $items . '</div>
        </div>';
    }

    private function mappingNominalLabel(mixed $nominal, string $expectedCode): string
    {
        return is_array($nominal)
            ? FormattingFramework::nominalLabel($nominal, ' ')
            : $expectedCode . ' missing';
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

        return array_filter([
            'default_bank_nominal_id' => !$this->hasAssignedNominal($settings, 'default_bank_nominal_id')
                ? $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && ($row['subtype_code'] === 'bank' || $row['code'] === '1200' || str_contains(strtolower($row['name']), 'bank')))
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
            'director_loan_asset_nominal_id' => !$this->hasAssignedNominal($settings, 'director_loan_asset_nominal_id')
                ? $this->directorLoanAssetNominalSuggestion($normalised)
                : null,
            'director_loan_liability_nominal_id' => !$this->hasAssignedNominal($settings, 'director_loan_liability_nominal_id')
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
        ], static fn(?array $row): bool => $row !== null);
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
