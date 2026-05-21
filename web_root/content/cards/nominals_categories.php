<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_categoriesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_categories';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_subtypes',
                'service' => NominalSubtypeRepository::class,
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
        $nominalSubtypes = (array)($context['services']['nominal_subtypes'] ?? []);
        $pageId = (string)($context['page']['page_id'] ?? 'nominals');

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
                <td>
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="Nominals">
                        <input type="hidden" name="intent" value="edit_nominal_subtype">
                        <input type="hidden" name="page" value="' . HelperFramework::escape($pageId) . '">
                        <input type="hidden" name="show_card" value="nominals_add_category">
                        <input type="hidden" name="edit_subtype_id" value="' . (int)($subtype['id'] ?? 0) . '">
                        <button class="button" type="submit">Edit</button>
                    </form>
                </td>
            </tr>';
        }

        return '
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
        ';
    }
}
