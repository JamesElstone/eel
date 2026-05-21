<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _api_modeCard extends CardBaseFramework
{

    public function helper(array $context): string {
        return 'Mode that each API is operating in.';
    }

    public function title(): string {
        return 'Application API Credentials';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);

        $companiesHouseApiMode = AccountingConfigurationStore::companiesHouseMode();
        $hmrcApiMode = AccountingConfigurationStore::hmrcMode();

        $apiCredentialCheckResults = (array)($page['api_credential_check_results'] ?? []);
        $messages = (array)($page['api_mode_messages'] ?? []);
        $errors = (array)($page['api_mode_errors'] ?? []);

        // 
        // Button enabler on data change functions in a section tag.
        // Now not used in this card as target button removed and ajax saving is occuring.
        // 
        // data-state-fields="companies_house_api_mode,hmrc_api_mode" data-state-target="save_api_mode_button" 
        // 

        return '
            <form method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="ApiMode">
                <input type="hidden" name="intent" value="set">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="companies_house_api_mode">Companies House Enviroment</label>
                        <select class="select" id="companies_house_api_mode" name="companies_house_api_mode" data-state-default="' . HelperFramework::escape($companiesHouseApiMode) . '">
                            <option value="TEST"' . ($companiesHouseApiMode === 'TEST' ? ' selected' : '') . '>TEST</option>
                            <option value="LIVE"' . ($companiesHouseApiMode === 'LIVE' ? ' selected' : '') . '>LIVE</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="hmrc_api_mode">HMRC Environment</label>
                        <select class="select" id="hmrc_api_mode" name="hmrc_api_mode" data-state-default="' . HelperFramework::escape($hmrcApiMode) . '">
                            <option value="TEST"' . ($hmrcApiMode === 'TEST' ? ' selected' : '') . '>TEST</option>
                            <option value="LIVE"' . ($hmrcApiMode === 'LIVE' ? ' selected' : '') . '>LIVE</option>
                        </select>
                    </div>
                </div>
            </form>
        ';
    }
}
