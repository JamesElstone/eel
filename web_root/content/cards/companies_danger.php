<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_dangerCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_danger';
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
        if (empty($page['has_valid_selected_company'])) {
            return '';
        }

        $settings = (array)($page['settings'] ?? []);
        $companyNumber = (string)($settings['companies_house_number'] ?? '');

        return '<div class="card">
            <div class="card-header"><h2 class="card-title">Danger Zone</h2></div>
            <div class="card-body">
                <div class="danger-panel" style="margin-top: 0;">
                    <h3 style="margin: 0 0 10px;">Clear Imported Accounting Data</h3>
                    <div class="helper" style="margin-bottom: 12px;">Company: <strong>' . HelperFramework::escape((string)($settings['company_name'] ?? '')) . '</strong><br>Company number: <strong>' . HelperFramework::escape($companyNumber) . '</strong></div>
                    <div class="helper" style="margin-bottom: 8px;">This removes the following company data:</div>
                    <ul class="helper" style="margin: 0 0 12px 18px; padding: 0;"><li>Bank account mappings</li><li>Expense claim lines</li><li>Expense claim payment links</li><li>Expense claimants</li><li>Expense claims</li><li>Journal lines</li><li>Journals</li><li>Statement import rows</li><li>Statement uploads</li><li>Transaction category audit history</li><li>Transactions</li></ul>
                    <div class="helper" style="margin-bottom: 12px;">Master company settings, tax years, nominal accounts, company accounts, and Companies House profile data are not removed. This cannot be undone through the UI.</div>
                    <div class="form-row">
                        <label for="company_clear_confirmation">Type the company number ' . HelperFramework::escape($companyNumber) . ' to confirm.</label>
                        <input class="input" id="company_clear_confirmation" name="company_clear_confirmation" value="" data-clear-company-input data-expected-value="' . HelperFramework::escape($companyNumber) . '" oninput="document.getElementById(\'clear-imported-data-button\').disabled = this.value.trim() !== this.dataset.expectedValue;">
                    </div>
                    <div style="margin-top: 12px;">
                        <button class="button danger" id="clear-imported-data-button" type="submit" disabled onclick="document.getElementById(\'settings_action_field\').value=\'clear_imported_accounting_data\'" data-ajax-card-update="companies-danger,companies-setup-health">Clear Imported Accounting Data</button>
                    </div>
                </div>
                <div class="danger-panel">
                    <h3 style="margin: 0 0 10px;">Delete Orphaned Transferred Files</h3>
                    <div class="helper" style="margin-bottom: 12px;">Removes server files for this company only when the database no longer references them. This checks staged statement CSVs, downloaded transaction receipts, and expense receipt uploads.</div>
                    <div><button class="button danger" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'delete_orphaned_transferred_files\'" data-ajax-card-update="companies-danger,companies-setup-health">Delete Orphaned Files</button></div>
                </div>
                <div class="danger-panel">
                    <h2 class="card-title">Delete Company</h2>
                    <div class="helper">This removes the selected company and all linked company data from the database. Shared nominal account tables are left untouched.</div>
                    <label class="checkbox-item">
                        <input type="checkbox" name="delete_company_confirm" value="1" data-delete-confirm-checkbox>
                        <div class="checkbox-copy"><strong>Confirm Company Deletion</strong><span>I understand this permanently deletes the selected company and its linked data within this app.</span></div>
                    </label>
                    <div class="form-row">
                        <label for="delete_company_confirm_value">Type the exact Companies House number to confirm deletion</label>
                        <input class="input" id="delete_company_confirm_value" name="delete_company_confirm_value" value="" data-delete-confirm-input data-expected-value="' . HelperFramework::escape($companyNumber) . '" disabled>
                    </div>
                    <div><button class="button danger" type="submit" disabled data-delete-company-button onclick="document.getElementById(\'settings_action_field\').value=\'delete_company\'" data-ajax-card-update="companies-search,companies-company-settings,companies-stored-detail,companies-accounting,companies-nominals,companies-danger,companies-empty-state,companies-setup-health">Delete Company</button></div>
                </div>
            </div>
        </div>';
    }
}
