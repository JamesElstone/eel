<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_add_accountCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_add_account';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_account_catalog',
                'service' => \eel_accounts\Repository\NominalAccountRepository::class,
                'method' => 'fetchNominalAccountCatalog',
            ],
            [
                'key' => 'nominal_subtypes',
                'service' => \eel_accounts\Repository\NominalSubtypeRepository::class,
                'method' => 'fetchNominalSubtypes',
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

    public function render(array $context): string
    {
        $nominalContext = (array)($context['nominals'] ?? []);
        $editingNominal = $this->findRowById(
            (array)($context['services']['nominal_account_catalog'] ?? []),
            (int)($nominalContext['editing_nominal_id'] ?? 0)
        );
        $nominalSubtypes = (array)($context['services']['nominal_subtypes'] ?? []);

        $accountTypeOptions = '';
        foreach ($this->validAccountTypes() as $accountType) {
            $selected = (string)($editingNominal['account_type'] ?? '') === $accountType ? ' selected' : '';
            $accountTypeOptions .= '<option value="' . HelperFramework::escape($accountType) . '"' . $selected . '>' . HelperFramework::escape($accountType) . '</option>';
        }

        $subtypeOptions = '<option value="">No subtype</option>';
        foreach ($nominalSubtypes as $subtype) {
            if (!is_array($subtype)) {
                continue;
            }

            $subtypeId = (string)($subtype['id'] ?? '');
            $selected = (string)($editingNominal['account_subtype_id'] ?? '') === $subtypeId ? ' selected' : '';
            $label = (string)($subtype['name'] ?? '') . ' [' . (string)($subtype['parent_account_type'] ?? '') . ']';
            $subtypeOptions .= '<option value="' . HelperFramework::escape($subtypeId) . '"' . $selected . '>' . HelperFramework::escape($label) . '</option>';
        }

        $taxTreatmentOptions = '';
        foreach ($this->validNominalTaxTreatments() as $taxTreatment) {
            $selected = (string)($editingNominal['tax_treatment'] ?? 'allowable') === $taxTreatment ? ' selected' : '';
            $taxTreatmentOptions .= '<option value="' . HelperFramework::escape($taxTreatment) . '"' . $selected . '>' . HelperFramework::escape(\eel_accounts\Service\AccountingFormattingService::nominalTaxTreatmentLabel($taxTreatment)) . '</option>';
        }

        $cancelFormId = 'nominals-account-cancel-form';

        return '
            ' . ($editingNominal !== null
                ? '<form id="' . $cancelFormId . '" method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="Nominals">
                    <input type="hidden" name="intent" value="cancel_nominal_edit">
                    <input type="hidden" name="show_card" value="nominals_add_account">
                </form>'
                : '') . '
            <form method="post" data-ajax-card-form="true">
                <input type="hidden" name="card_action" value="Nominals">
                <input type="hidden" name="global_action" value="' . HelperFramework::escape($editingNominal !== null ? 'save_nominal_account' : 'add_nominal_account') . '">'
                . ($editingNominal !== null
                    ? '<input type="hidden" name="nominal_account_id" value="' . (int)($editingNominal['id'] ?? 0) . '">'
                    : '') . '
                <div class="form-grid">
                    <div class="form-row">
                        <label for="nominal_code">Account Code <span>(Letters, Number or _ only)</span></label>
                        <input class="input" id="nominal_code" name="nominal_code" pattern="[A-Za-z0-9_]*" title="Letters, Number or _ only" value="' . HelperFramework::escape((string)($editingNominal['code'] ?? '')) . '">
                    </div>
                    <div class="form-row">
                        <label for="nominal_name">Account Name</label>
                        <input class="input" id="nominal_name" name="nominal_name" value="' . HelperFramework::escape((string)($editingNominal['name'] ?? '')) . '">
                    </div>
                    <div class="form-row">
                        <label for="nominal_account_type">Account Type</label>
                        <select class="select" id="nominal_account_type" name="nominal_account_type">' . $accountTypeOptions . '</select>
                    </div>
                    <div class="form-row">
                        <label for="nominal_account_subtype_id">Subtype</label>
                        <select class="select" id="nominal_account_subtype_id" name="nominal_account_subtype_id">' . $subtypeOptions . '</select>
                    </div>
                    <div class="form-row">
                        <label for="nominal_tax_treatment">Tax Treatment</label>
                        <select class="select" id="nominal_tax_treatment" name="nominal_tax_treatment">' . $taxTreatmentOptions . '</select>
                    </div>
                    <div class="form-row">
                        <label for="nominal_sort_order">Sort Order</label>
                        <input class="input" id="nominal_sort_order" name="nominal_sort_order" value="' . HelperFramework::escape((string)($editingNominal['sort_order'] ?? '100')) . '">
                    </div>
                    <label class="checkbox-item">
                        <input type="checkbox" name="nominal_is_active" value="1"' . (!isset($editingNominal['is_active']) || (int)($editingNominal['is_active'] ?? 0) === 1 ? ' checked' : '') . '>
                        <div class="checkbox-copy">
                            <strong>Active</strong>
                            <span>When a item becomes inactive, it cannot be used in future. Historic records are unchanged.</span>
                        </div>
                    </label>
                </div>
                <div>
                    <button class="button primary" type="submit">' . HelperFramework::escape($editingNominal !== null ? 'Save Account' : 'Add Account') . '</button>'
                    . ($editingNominal !== null
                        ? '<button class="button" type="submit" form="' . $cancelFormId . '" formnovalidate>Cancel</button>'
                        : '') . '
                </div>
            </form>
        ';
    }

    private function validAccountTypes(): array
    {
        return ['asset', 'liability', 'equity', 'income', 'cost_of_sales', 'expense'];
    }

    private function validNominalTaxTreatments(): array
    {
        return ['allowable', 'disallowable', 'capital', 'other'];
    }

    private function findRowById(array $rows, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }
}
