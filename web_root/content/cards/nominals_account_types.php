<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_account_typesCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'nominals_account_types';
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
        return '<section class="eel-card-fragment" data-card="nominals-account-types">
            <div class="card nominals-account-types">
                <div class="card-header">
                    <h2 class="card-title">Account Types</h2>
                </div>
                <div class="card-body">
                    <table>
                        <thead>
                            <tr>
                                <th>Account Type</th>
                                <th>Typical Use</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>asset</td>
                                <td>Bank, debtors, fixed assets, and other resources the company owns or controls.</td>
                            </tr>
                            <tr>
                                <td>liability</td>
                                <td>VAT, loans, tax, creditors, and other obligations the company owes.</td>
                            </tr>
                            <tr>
                                <td>equity</td>
                                <td>Share capital, reserves, retained profit, and other ownership balances.</td>
                            </tr>
                            <tr>
                                <td>income</td>
                                <td>Turnover, sales, and other income earned by the business.</td>
                            </tr>
                            <tr>
                                <td>cost_of_sales</td>
                                <td>Direct costs of delivering work or goods, such as materials and subcontract costs.</td>
                            </tr>
                            <tr>
                                <td>expense</td>
                                <td>Overheads and operating costs such as software, insurance, motor, and office costs.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>';
    }
}
