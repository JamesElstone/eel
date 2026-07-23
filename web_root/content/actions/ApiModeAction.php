<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApiModeAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        
        $intent = trim((string)$request->input('intent'));

        $current_chMode = \eel_accounts\Store\AccountingConfigurationStore::companiesHouseMode();
        $current_chAccountsFilingMode = \eel_accounts\Store\AccountingConfigurationStore::companiesHouseAccountsFilingMode();
        $current_hmrcMode = \eel_accounts\Store\AccountingConfigurationStore::hmrcMode();

        switch ($intent) {
            case 'check':

                $credentialCheckService = new \eel_accounts\Service\ApiCredentialCheckService();

                $apiCredentialCheckResults = $credentialCheckService->checkSelectedModes($current_chMode, $current_hmrcMode);

                $allChecksPassed = count(array_filter($apiCredentialCheckResults, static fn (array $result): bool => !empty($result['ok']))) === count($apiCredentialCheckResults);

                if ($allChecksPassed) {
                    $flashMessages[] = 'Credential check passed for the selected API modes.';
                } else {
                    $flashMessages[] = [
                        'type' => 'error',
                        'message'=>'One or more API credential checks failed for the selected modes.',
                    ];
                }

                return ActionResultFramework::success(
                    changedFacts: ['api.connectivity.test'],
                    flashMessages: $flashMessages, 
                    context: ['api_credential_check_results' => $apiCredentialCheckResults],
                );

                break;

            case 'set':

                $requested_hmrcMode = HelperFramework::normaliseEnvironmentMode((string)$request->input('hmrc_api_mode'));
                $requested_chMode = HelperFramework::normaliseEnvironmentMode((string)$request->input('companies_house_api_mode'));
                $requested_chAccountsFilingMode = strtoupper(trim((string)$request->input('ch_accounts_filing_mode')));
                if (!in_array($requested_chAccountsFilingMode, ['DISABLED', 'TEST', 'LIVE'], true)) {
                    $requested_chAccountsFilingMode = 'DISABLED';
                }

                $changed = false;


                if ($current_chMode <> $requested_chMode) {
                    \eel_accounts\Store\AccountingConfigurationStore::setCompaniesHouseMode($requested_chMode);
                    $flashMessages[] = 'Companies House API mode saved as ' . $requested_chMode . '.';
                    $changed = true;
                }

                if ($current_chAccountsFilingMode <> $requested_chAccountsFilingMode) {
                    \eel_accounts\Store\AccountingConfigurationStore::setCompaniesHouseAccountsFilingMode($requested_chAccountsFilingMode);
                    $flashMessages[] = 'Companies House XML environment saved as ' . $requested_chAccountsFilingMode . '.';
                    $changed = true;
                }

                if ($current_hmrcMode <> $requested_hmrcMode) {
                    \eel_accounts\Store\AccountingConfigurationStore::setHmrcMode($requested_hmrcMode);
                    $flashMessages[] = 'HMRC API mode saved as ' . $requested_hmrcMode . '.';
                    $changed = true;
                }

                if ($changed === true) {
                    return ActionResultFramework::success(
                        changedFacts: ['api.connectivity.test'],
                        flashMessages: $flashMessages,
                    );
                } else {
                    return ActionResultFramework::none();
                }

                break;

            default:
                return ActionResultFramework::none();
        }
    }
}
