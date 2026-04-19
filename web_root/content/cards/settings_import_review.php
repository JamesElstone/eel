<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings_import_reviewCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'settings_import_review';
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
        $hasValidSelectedCompany = !empty($page['has_valid_selected_company']);
        $settings = (array)($page['settings'] ?? []);

        if (!$hasValidSelectedCompany) {
            return '<section class="eel-card-fragment" data-card="settings-import">
                <div class="card settings-section" data-section="vat-registration" data-server-vat-registered="' . (!empty($settings['is_vat_registered']) ? '1' : '0') . '">
                    <div class="card-header">
                        <h2 class="card-title">Settings Ready When You Are</h2>
                    </div>
                    <div class="card-body">
                        <div class="helper">Select or add a company first, and the import and review controls will appear here.</div>
                    </div>
                </div>
            </section>';
        }

        return '<section class="eel-card-fragment" data-card="settings-import">
            <div class="card settings-section" data-section="import">
                <div class="card-header">
                    <h2 class="card-title">Import &amp; Review Behaviour</h2>
                </div>
                <div class="card-body">
                    <div class="checkbox-grid" style="margin-top: 16px;">
                        <label class="checkbox-item">
                            <input type="checkbox" name="enable_duplicate_file_check" value="1"' . $this->checked($settings['enable_duplicate_file_check'] ?? null) . '>
                            <div class="checkbox-copy">
                                <strong>Duplicate file detection</strong>
                                <span>Warn if the same CSV file hash has already been uploaded.</span>
                            </div>
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="enable_duplicate_row_check" value="1"' . $this->checked($settings['enable_duplicate_row_check'] ?? null) . '>
                            <div class="checkbox-copy">
                                <strong>Duplicate row detection</strong>
                                <span>Skip individual statement rows already seen for the company.</span>
                            </div>
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="auto_create_rule_prompt" value="1"' . $this->checked($settings['auto_create_rule_prompt'] ?? null) . '>
                            <div class="checkbox-copy">
                                <strong>Prompt to save categorisation rule</strong>
                                <span>After manual categorisation, offer to turn that choice into a future rule.</span>
                            </div>
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="lock_posted_periods" value="1"' . $this->checked($settings['lock_posted_periods'] ?? null) . '>
                            <div class="checkbox-copy">
                                <strong>Lock posted periods</strong>
                                <span>Prevent further edits once a month or year-end period is marked complete.</span>
                            </div>
                        </label>
                    </div>
                    <div style="margin-top: 16px;">
                        <button class="button section-save-button" type="submit" disabled onclick="document.getElementById(\'settings_action_field\').value=\'save_import\'" data-ajax-card-update="settings-import">Save Import &amp; Review Behaviour</button>
                    </div>
                </div>
            </div>
        </section>';
    }

    private function checked(mixed $value): string
    {
        return !empty($value) ? ' checked' : '';
    }
}
