<?php
declare(strict_types=1);

final class _nominals_add_categoryCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'nominals_add_category';
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
        $editingSubtype = is_array($page['editing_subtype'] ?? null) ? $page['editing_subtype'] : null;

        $accountTypeOptions = '';
        foreach ($this->validAccountTypes() as $accountType) {
            $selected = (string)($editingSubtype['parent_account_type'] ?? '') === $accountType ? ' selected' : '';
            $accountTypeOptions .= '<option value="' . HelperFramework::escape($accountType) . '"' . $selected . '>' . HelperFramework::escape($accountType) . '</option>';
        }

        return '<section class="eel-card-fragment" data-card="nominals-add-category">
            <div class="card nominals-add-category">
                <div class="card-header">
                    <h2 class="card-title">' . HelperFramework::escape($editingSubtype !== null ? 'Edit Category' : 'Add Category') . '</h2>
                </div>
                <div class="card-body">
                    <form method="post" data-ajax-card-form="true" data-ajax-card-update="nominals-categories,nominals-add-category">
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
                        <div style="margin-top: 16px;">
                            <button class="button primary" type="submit">' . HelperFramework::escape($editingSubtype !== null ? 'Save Category' : 'Add Category') . '</button>'
                            . ($editingSubtype !== null
                                ? '<a class="button" href="' . HelperFramework::escape($this->buildPageUrl('nominals')) . '" data-ajax-card-link="true" data-ajax-card-update="nominals-categories,nominals-add-category">Cancel</a>'
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

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
