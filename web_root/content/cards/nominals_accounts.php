<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_accountsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'nominals_accounts';
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
        $nominalAccountCatalog = (array)($page['nominal_account_catalog'] ?? []);

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
                <td>' . HelperFramework::escape(FormattingFramework::nominalTaxTreatmentLabel((string)($nominal['tax_treatment'] ?? 'allowable'))) . '</td>
                <td>' . (int)($nominal['sort_order'] ?? 0) . '</td>
                <td>' . ((int)($nominal['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>
                <td><a class="button" href="' . HelperFramework::escape($this->buildPageUrl('nominals', ['edit_nominal_id' => (int)($nominal['id'] ?? 0)])) . '" data-ajax-card-link="true" data-ajax-card-update="nominals-accounts,nominals-add-account">Edit</a></td>
            </tr>';
        }

        return '<section class="eel-card-fragment" data-card="nominals-accounts">
            <div class="card nominals-accounts">
                <div class="card-header">
                    <h2 class="card-title">Accounts</h2>
                </div>
                <div class="card-body">
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
                </div>
            </div>
        </section>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
