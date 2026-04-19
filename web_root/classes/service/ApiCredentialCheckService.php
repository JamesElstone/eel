<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApiCredentialCheckService
{
    /** @var callable */
    private $outboundRequest;

    public function __construct(?callable $outboundRequest = null) {
        $this->outboundRequest = $outboundRequest;
    }

    public function checkSelectedModes(string $companiesHouseMode, string $hmrcMode): array {
        return [
            $this->checkCompaniesHouse($companiesHouseMode),
            $this->checkHmrc($hmrcMode),
        ];
    }

    public function checkCompaniesHouse(string $mode): array {
        $mode = HelperFramework::normaliseEnvironmentMode($mode);
        $service = new CompaniesHouseService($mode, 10, $this->outboundRequest);

        try {
            $response = $service->request('/search/companies', ['q' => 'limited']);
            $status = (int)($response['status'] ?? 0);

            return [
                'key' => 'companies_house',
                'label' => 'Companies House',
                'mode' => $mode,
                'ok' => $status >= 200 && $status < 300,
                'detail' => $status >= 200 && $status < 300
                    ? 'Authenticated request succeeded with HTTP ' . $status . '.'
                    : 'Authenticated request returned HTTP ' . $status . '.',
                'url' => (string)($response['url'] ?? ''),
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'companies_house',
                'label' => 'Companies House',
                'mode' => $mode,
                'ok' => false,
                'detail' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'Credential check failed.',
                'url' => '',
            ];
        }
    }

    public function checkHmrc(string $mode): array {
        $mode = HelperFramework::normaliseEnvironmentMode($mode);
        $config = is_array(AppConfigurationStore::config()['hmrc']['vat'] ?? null) ? AppConfigurationStore::config()['hmrc']['vat'] : [];
        $client = new HmrcOutbound(array_replace($config, [
            'mode' => $mode,
            'oauth_path' => '/oauth/token',
            'token_scope' => 'read:vat',
            'timeout_seconds' => 10,
        ]), $this->outboundRequest);

        try {
            $response = $client->fetchAccessTokenResponse();
            return [
                'key' => 'hmrc',
                'label' => 'HMRC',
                'mode' => $mode,
                'ok' => true,
                'detail' => 'Bearer token retrieved successfully.',
                'url' => (string)($response['url'] ?? ''),
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'hmrc',
                'label' => 'HMRC',
                'mode' => $mode,
                'ok' => false,
                'detail' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'Credential check failed.',
                'url' => '',
            ];
        }
    }
}


