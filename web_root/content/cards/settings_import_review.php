<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings_import_reviewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'settings_import_review';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function title(): string
    {
        return 'Company Import Settings';
    }

    public function render(array $context): string
    {
        $hasValidSelectedCompany = (int)($context['company']['id'] ?? 0) > 0;
        $settings = (array)($context['company']['settings'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);

        if (!$hasValidSelectedCompany) {
            return '<div class="helper">Select or add a company first, and the import and review controls will appear here.</div>';
        }

        return '
            <form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Company">
                <input type="hidden" name="intent" value="save_import_review">
                <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)$companyId) . '">
                <input type="hidden" name="accounting_period_id" value="' . HelperFramework::escape((string)$accountingPeriodId) . '">
                <section data-state-fields="enable_duplicate_file_check,enable_duplicate_row_check,auto_create_rule_prompt,lock_posted_periods" data-state-target="save_import_review_button">
                <div class="checkbox-grid">
                    <label class="checkbox-item">
                        <input type="checkbox" id="enable_duplicate_file_check" name="enable_duplicate_file_check" value="1" data-state-default="' . HelperFramework::escape(!empty($settings['enable_duplicate_file_check']) ? '1' : '0') . '"' . $this->checked($settings['enable_duplicate_file_check'] ?? null) . '>
                        <div class="checkbox-copy">
                            <strong>Duplicate file detection</strong>
                            <span>Warn if the same CSV file hash has already been uploaded.</span>
                        </div>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="enable_duplicate_row_check" name="enable_duplicate_row_check" value="1" data-state-default="' . HelperFramework::escape(!empty($settings['enable_duplicate_row_check']) ? '1' : '0') . '"' . $this->checked($settings['enable_duplicate_row_check'] ?? null) . '>
                        <div class="checkbox-copy">
                            <strong>Duplicate row detection</strong>
                            <span>Skip individual statement rows already seen for the company.</span>
                        </div>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="auto_create_rule_prompt" name="auto_create_rule_prompt" value="1" data-state-default="' . HelperFramework::escape(!empty($settings['auto_create_rule_prompt']) ? '1' : '0') . '"' . $this->checked($settings['auto_create_rule_prompt'] ?? null) . '>
                        <div class="checkbox-copy">
                            <strong>Prompt to save categorisation rule</strong>
                            <span>After manual categorisation, offer to turn that choice into a future rule.</span>
                        </div>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="lock_posted_periods" name="lock_posted_periods" value="1" data-state-default="' . HelperFramework::escape(!empty($settings['lock_posted_periods']) ? '1' : '0') . '"' . $this->checked($settings['lock_posted_periods'] ?? null) . '>
                        <div class="checkbox-copy">
                            <strong>Lock posted periods</strong>
                            <span>Prevent further edits once a month or year-end period is marked complete.</span>
                        </div>
                    </label>
                </div>
                <div>
                    <button class="button primary primary" id="save_import_review_button" type="submit" disabled>Save Import &amp; Review Behaviour</button>
                </div>
                </section>
            </form>
        ';
    }

    private function checked(mixed $value): string
    {
        return !empty($value) ? ' checked' : '';
    }
}
