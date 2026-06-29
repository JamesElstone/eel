<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class hmrcService
{
    public function runHmrcAntiFraudTest(\RequestFramework $request): \ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new \ActionResultFramework(
                false,
                ['test.antifraud'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before running the HMRC anti-fraud test.',
                ]],
                [],
                [
                    'hmrc_antifraud_test_result' => [
                        'success' => false,
                        'error' => 'No company selected.',
                    ],
                ]
            );
        }

        $hmrcMode = $this->resolveHmrcMode($companyId);

        try {
            $validatorConfig = \eel_accounts\Outbound\HmrcOutbound::antiFraudValidatorConfig($hmrcMode);
            $outbound = new \eel_accounts\Outbound\HmrcOutbound($validatorConfig);
            $afHeaders = \AntiFraudService::instance()->getAntiFraudHeaders();
            $govHeaders = \AntiFraudService::instance()->buildGovHeaders();
            $response = $outbound->validateAntiFraudHeaders($govHeaders);
            $body = json_decode((string)($response['body'] ?? ''), true);

            return \ActionResultFramework::success(
                ['test.antifraud'],
                [[
                    'type' => 'success',
                    'message' => 'HMRC anti-fraud validator request completed.',
                ]],
                [],
                [
                    'hmrc_antifraud_test_result' => [
                        'success' => true,
                        'company_id' => $companyId,
                        'hmrc_mode' => $hmrcMode,
                        'af_headers' => $afHeaders,
                        'gov_headers' => $govHeaders,
                        'status_code' => (int)($response['status_code'] ?? 0),
                        'headers' => (array)($response['headers'] ?? []),
                        'body' => is_array($body) ? $body : (string)($response['body'] ?? ''),
                    ],
                ]
            );
        } catch (\Throwable $exception) {
            return new \ActionResultFramework(
                false,
                ['test.antifraud'],
                [[
                    'type' => 'error',
                    'message' => 'HMRC anti-fraud validator request failed: ' . $exception->getMessage(),
                ]],
                [],
                [
                    'hmrc_antifraud_test_result' => [
                        'success' => false,
                        'company_id' => $companyId,
                        'hmrc_mode' => $hmrcMode,
                        'error' => $exception->getMessage(),
                        'af_headers' => \AntiFraudService::instance()->getAntiFraudHeaders(),
                        'gov_headers' => \AntiFraudService::instance()->buildGovHeaders(),
                    ],
                ]
            );
        }
    }

    public function resolveHmrcMode(int $companyId): string
    {
        if ($companyId <= 0) {
            return 'TEST';
        }

        return \HelperFramework::normaliseEnvironmentMode(
            (string)(new \eel_accounts\Store\CompanySettingsStore($companyId))->get('hmrc_mode', 'TEST')
        );
    }
}
