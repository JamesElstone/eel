<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_add_categoryCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_add_category';
    }

    public function services(): array
    {
        return [
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
        $editingSubtype = $this->findRowById(
            (array)($context['services']['nominal_subtypes'] ?? []),
            (int)($nominalContext['editing_subtype_id'] ?? 0)
        );

        $accountTypeOptions = '';
        foreach ($this->validAccountTypes() as $accountType) {
            $selected = (string)($editingSubtype['parent_account_type'] ?? '') === $accountType ? ' selected' : '';
            $accountTypeOptions .= '<option value="' . HelperFramework::escape($accountType) . '"' . $selected . '>' . HelperFramework::escape($accountType) . '</option>';
        }

        $cancelFormId = 'nominals-category-cancel-form';

        return '
            ' . ($editingSubtype !== null
                ? '<form id="' . $cancelFormId . '" method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="Nominals">
                    <input type="hidden" name="intent" value="cancel_nominal_edit">
                    <input type="hidden" name="show_card" value="nominals_add_category">
                </form>'
                : '') . '
            <form method="post" data-ajax-card-form="true">
                <input type="hidden" name="card_action" value="Nominals">
                <input type="hidden" name="global_action" value="' . HelperFramework::escape($editingSubtype !== null ? 'save_nominal_subtype' : 'add_nominal_subtype') . '">'
                . ($editingSubtype !== null
                    ? '<input type="hidden" name="subtype_id" value="' . (int)($editingSubtype['id'] ?? 0) . '">'
                    : '') . '
                <div class="form-grid">
                    <div class="form-row">
                        <label for="subtype_code">Category Code <span>(Letters, Number or _ only)</span></label>
                        <input class="input" id="subtype_code" name="subtype_code" pattern="[A-Za-z0-9_]*" title="Letters, Number or _ only" value="' . HelperFramework::escape((string)($editingSubtype['code'] ?? '')) . '">
                    </div>
                    <div class="form-row">
                        <label for="subtype_name">Category Name</label>
                        <input class="input" id="subtype_name" name="subtype_name" value="' . HelperFramework::escape((string)($editingSubtype['name'] ?? '')) . '">
                    </div>
                    <div class="form-row">
                        <label for="subtype_parent_account_type">Parent Account Type</label>
                        <select class="select" id="subtype_parent_account_type" name="subtype_parent_account_type">' . $accountTypeOptions . '</select>
                    </div>
                    <div class="form-row">
                        <label for="subtype_sort_order">Sort Order</label>
                        <input class="input" id="subtype_sort_order" name="subtype_sort_order" value="' . HelperFramework::escape((string)($editingSubtype['sort_order'] ?? '100')) . '">
                    </div>
                    <label class="checkbox-item">
                        <input type="checkbox" name="subtype_is_active" value="1"' . (!isset($editingSubtype['is_active']) || (int)($editingSubtype['is_active'] ?? 0) === 1 ? ' checked' : '') . '>
                        <div class="checkbox-copy">
                            <strong>Active</strong>
                            <span>When a item becomes inactive, it cannot be used in future. Historic records are unchanged.</span>
                        </div>
                    </label>
                </div>
                <div>
                    <button class="button primary" type="submit">' . HelperFramework::escape($editingSubtype !== null ? 'Save Category' : 'Add Category') . '</button>'
                    . ($editingSubtype !== null
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
