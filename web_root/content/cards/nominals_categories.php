<?php
declare(strict_types=1);

final class _nominals_categoriesCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'nominals_categories';
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
        $nominalSubtypes = (array)($page['nominal_subtypes'] ?? []);

        $rows = '';
        foreach ($nominalSubtypes as $subtype) {
            if (!is_array($subtype)) {
                continue;
            }

            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($subtype['code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($subtype['name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($subtype['parent_account_type'] ?? '')) . '</td>
                <td>' . (int)($subtype['sort_order'] ?? 0) . '</td>
                <td>' . ((int)($subtype['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>
                <td><a class="button" href="' . HelperFramework::escape($this->buildPageUrl('nominals', ['edit_subtype_id' => (int)($subtype['id'] ?? 0)])) . '" data-ajax-card-link="true" data-ajax-card-update="nominals-categories,nominals-add-category">Edit</a></td>
            </tr>';
        }

        return '<section class="eel-card-fragment" data-card="nominals-categories">
            <div class="card nominals-categories">
                <div class="card-header">
                    <h2 class="card-title">Categories</h2>
                </div>
                <div class="card-body">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Parent Type</th>
                                <th>Sort</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>' . $rows . '</tbody>
                    </table>
                </div>
            </div>
        </section>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
