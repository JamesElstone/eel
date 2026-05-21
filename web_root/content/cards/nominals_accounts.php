<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_accountsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_accounts';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_account_catalog',
                'service' => NominalAccountRepository::class,
                'method' => 'fetchNominalAccountCatalog',
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
        $nominalAccountCatalog = (array)($context['services']['nominal_account_catalog'] ?? []);
        $pageId = (string)($context['page']['page_id'] ?? 'nominals');

        $rows = '';
        foreach ($nominalAccountCatalog as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }

            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($nominal['code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($nominal['name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($nominal['account_type'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($nominal['subtype_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(AccountingFormattingService::nominalTaxTreatmentLabel((string)($nominal['tax_treatment'] ?? 'allowable'))) . '</td>
                <td>' . (int)($nominal['sort_order'] ?? 0) . '</td>
                <td>' . ((int)($nominal['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>
                <td>
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="Nominals">
                        <input type="hidden" name="intent" value="edit_nominal_account">
                        <input type="hidden" name="page" value="' . HelperFramework::escape($pageId) . '">
                        <input type="hidden" name="show_card" value="nominals_add_account">
                        <input type="hidden" name="edit_nominal_id" value="' . (int)($nominal['id'] ?? 0) . '">
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
                        <th>Type</th>
                        <th>Subtype</th>
                        <th>Tax Treatment</th>
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
