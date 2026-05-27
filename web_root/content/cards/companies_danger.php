<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_dangerCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_danger';
    }

    public function services(): array
    {
        return [];
    }

    public function title(): string {
        return 'Danger Zone - Careful';
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
     
        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '<div class="helper">No actions are possible until a company is selected.</div>';
        }

        $companyName = (string)($context['company']['name'] ?? '');
        $companyNumber = (string)($context['company']['number'] ?? '');
        $deletedDataItems = [
            'Expense claim lines',
            'Expense claim payment links',
            'Expense claimants',
            'Expense claims',
            'Journal lines',
            'Journals',
            'Statement field mappings',
            'Statement import rows',
            'Statement uploads',
            'Transaction category audit history',
            'Transactions',
        ];

        $deletedDataListHtml = '';
        foreach ($deletedDataItems as $label) {
            $deletedDataListHtml .= '<li><strong>' . HelperFramework::escape($label) . '</strong></li>';
        }

        return '
            <div class="stack">
                <div class="panel-soft warn">
                    <h3 class="card-title">Clear Imported Accounting Data</h3>
                    <div class="helper">
                        Company: <strong>' . HelperFramework::escape($companyName) . '</strong><br>
                        '."Company's".' Registered Number: <strong>' . HelperFramework::escape($companyNumber) . '</strong>
                    </div>
                    <div class="standout helper">This removes imported bookkeeping data only.<br>Master company settings, accounting periods, nominal accounts, company accounts, and stored Companies House profile data are left untouched.</div>
                    <div class="list">
                        <p><strong>Items to delete:</strong></p>
                        <ul>
                            ' . $deletedDataListHtml . '
                        </ul>
                    </div>
                    <form method="post" data-ajax="true" class="stack">
                        <input type="hidden" name="card_action" value="Company">
                        <input type="hidden" name="intent" value="clear_imported_accounting_data">
                        <div class="form-row">
                            <label for="company_clear_confirmation">' . "Type the Company's Registered Number below to confirm:" . '</label>
                            <input class="input" id="company_clear_confirmation" name="company_clear_confirmation" value="" data-clear-company-input data-clear-confirm-input data-expected-value="' . HelperFramework::escape($companyNumber) . '">
                        </div>
                        <div class="form-row">
                            <button class="button danger" disabled data-delete-company-button data-chicken-check="true" data-chicken-message="Confirm that all uploaded data for this company is to be removed.<br><br>Please make sure you have a valid backup or can recreate the data should you need to." data-chicken-confirm-text="Delete Imported Data" id="clear-imported-data-button" type="submit" disabled>Clear Imported Accounting Data</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="stack">
                <div class="panel-soft warn">
                    <h3 class="card-title">Delete Orphaned Transferred Files</h3>
                    <div class="standout helper">Removes server files for this company only when the database no longer references them. This checks staged statement CSVs, downloaded transaction receipts, and expense receipt uploads.</div>
                    <form method="post" data-ajax="true" class="stack">
                        <input type="hidden" name="card_action" value="Company">
                        <input type="hidden" name="intent" value="delete_orphaned_transferred_files">
                        <div class="form-row">
                            <button class="button danger" type="submit">Delete Orphaned Files</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="stack">
                <div class="panel-soft warn">
                    <h3 class="card-title">Delete Company</h3>
                    <div class="standout helper">This permanently removes the selected company and all linked company data from the database. Shared nominal account tables are left untouched.</div>
                    <form method="post" data-ajax="true" class="stack">
                        <input type="hidden" name="card_action" value="Company">
                        <input type="hidden" name="intent" value="delete_company">
                        <label class="checkbox-item">
                            <input type="checkbox" name="delete_company_confirm" value="1" data-delete-confirm-checkbox>
                            <div class="checkbox-copy"><strong>Confirm Company Deletion</strong><span>I understand this permanently deletes the selected company and its linked data within this app.</span></div>
                        </label>
                        <div class="form-row">
                            <label for="delete_company_confirm_value">' . "Type the Company's Registered Number below to confirm:" . '</label>
                            <input class="input" id="delete_company_confirm_value" name="delete_company_confirm_value" value="" data-delete-confirm-input data-expected-value="' . HelperFramework::escape($companyNumber) . '" disabled>
                        </div>
                        <div class="form-row">
                            <button class="button danger" type="submit" disabled data-delete-company-button data-delete-confirm-button data-chicken-check="true" data-chicken-message="Confirm this company and all of its data stored in this app should be deleted.<br><br>This does not delete data from third-parties, like HMRC and Companies House." data-chicken-confirm-text="Delete Company">Delete Company</button>
                        </div>
                    </form>
                </div>
            </div>
        ';
    }
}
