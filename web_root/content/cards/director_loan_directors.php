<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_directorsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'director_loan_directors';
    }

    public function title(): string
    {
        return 'Directors';
    }

    public function helper(array $context): string
    {
        return 'Companies House facts used to identify the director loan subledger accounts. Former directors remain available for historic balances and repayments.';
    }

    public function services(): array
    {
        return [[
            'key' => 'directors',
            'service' => \eel_accounts\Service\CompanyDirectorService::class,
            'method' => 'fetchForCompany',
            'params' => ['companyId' => ':company.id'],
        ]];
    }

    public function render(array $context): string
    {
        $directors = (array)($context['services']['directors'] ?? []);
        if ($directors === []) {
            return '<div class="helper">No structured Companies House directors have been synchronised yet. Refresh the company from Companies House.</div>';
        }

        $rows = '';
        foreach ($directors as $director) {
            $resignedOn = trim((string)($director['resigned_on'] ?? ''));
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($director['full_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($director['officer_role'] ?? 'director'), '-')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($director['appointed_on'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape($resignedOn !== '' ? HelperFramework::displayDate($resignedOn) : 'Active') . '</td>
                <td>' . HelperFramework::escape(HelperFramework::displayDateTime((string)($director['last_synced_at'] ?? ''))) . '</td>
            </tr>';
        }

        return '<div class="table-scroll"><table>
            <thead><tr><th>Name</th><th>Role</th><th>Appointed</th><th>Resigned / status</th><th>Last synchronised</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }
}
