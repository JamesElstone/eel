<?php
declare(strict_types=1);

final class _nominals_add_accountCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'nominals_add_account';
    }

    public function services(): array
    {
        return [];
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
        $editingNominal = is_array($page['editing_nominal'] ?? null) ? $page['editing_nominal'] : null;
        $nominalSubtypes = (array)($page['nominal_subtypes'] ?? []);

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
            $taxTreatmentOptions .= '<option value="' . HelperFramework::escape($taxTreatment) . '"' . $selected . '>' . HelperFramework::escape(FormattingFramework::nominalTaxTreatmentLabel($taxTreatment)) . '</option>';
        }

        return '<section class="eel-card-fragment" data-card="nominals-add-account">
            <div class="card nominals-add-account">
                <div class="card-header">
                    <h2 class="card-title">' . HelperFramework::escape($editingNominal !== null ? 'Edit Account' : 'Add Account') . '</h2>
                </div>
                <div class="card-body">
                    <form method="post" data-ajax-card-form="true" data-ajax-card-update="nominals-accounts,nominals-add-account">
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
                        <div style="margin-top: 16px;">
                            <button class="button primary" type="submit">' . HelperFramework::escape($editingNominal !== null ? 'Save Account' : 'Add Account') . '</button>'
                            . ($editingNominal !== null
                                ? '<a class="button" href="' . HelperFramework::escape($this->buildPageUrl('nominals')) . '" data-ajax-card-link="true" data-ajax-card-update="nominals-accounts,nominals-add-account">Cancel</a>'
                                : '') . '
                        </div>
                    </form>
                </div>
            </div>
        </section>';
    }

    private function validAccountTypes(): array
    {
        return ['asset', 'liability', 'equity', 'income', 'cost_of_sales', 'expense'];
    }

    private function validNominalTaxTreatments(): array
    {
        return ['allowable', 'disallowable', 'capital'];
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
