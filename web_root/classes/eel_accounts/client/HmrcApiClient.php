<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Client;

final class HmrcApiClient
{
    public function submitCorporationTaxReturn(array $payload, string $mode, array $headers): array
    {
        $mode = \HelperFramework::normaliseEnvironmentMode($mode);
        $config = $this->ct600Config($mode);
        $path = trim((string)($config['submit_path'] ?? ''));
        if ($path === '') {
            return [
                'success' => false,
                'status_code' => 0,
                'headers' => [],
                'body' => '',
                'error' => 'HMRC CT600 submit_path is not configured.',
                'endpoint' => $this->baseUrlForMode($mode) . '/[missing-submit-path]',
            ];
        }

        try {
            $outbound = new \eel_accounts\Outbound\HmrcOutbound($config);
            $tokenResponse = $outbound->fetchAccessTokenResponse();
            $tokenPayload = json_decode((string)($tokenResponse['body'] ?? ''), true);
            $accessToken = trim((string)($tokenPayload['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Unable to obtain HMRC access token.');
            }

            $requestHeaders = array_replace([
                'Accept' => (string)($config['accept_header'] ?? 'application/xml'),
                'Content-Type' => 'application/xml',
            ], $headers);

            $response = \ApiHelperOutbound::request([
                'transport' => 'http',
                'method' => 'POST',
                'base_url' => $this->baseUrlForMode($mode),
                'path' => $path,
                'headers' => $requestHeaders,
                'auth' => 'bearer',
                'bearer_token' => $accessToken,
                'body' => (string)($payload['body'] ?? ''),
                'timeout_seconds' => max(1, (int)($config['timeout_seconds'] ?? 30)),
            ]);

            return [
                'success' => (int)($response['status_code'] ?? 0) >= 200 && (int)($response['status_code'] ?? 0) < 300,
                'status_code' => (int)($response['status_code'] ?? 0),
                'headers' => (array)($response['headers'] ?? []),
                'body' => (string)($response['body'] ?? ''),
                'endpoint' => (string)($response['url'] ?? ''),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status_code' => 0,
                'headers' => [],
                'body' => '',
                'error' => $exception->getMessage(),
                'endpoint' => $this->baseUrlForMode($mode) . '/' . ltrim($path, '/'),
            ];
        }
    }

    public function testFraudPreventionHeaders(array $headers, string $mode): array
    {
        $mode = \HelperFramework::normaliseEnvironmentMode($mode);
        try {
            $outbound = new \eel_accounts\Outbound\HmrcOutbound(\eel_accounts\Outbound\HmrcOutbound::antiFraudValidatorConfig($mode));
            $response = $outbound->validateAntiFraudHeaders($headers);

            return [
                'success' => (int)($response['status_code'] ?? 0) >= 200 && (int)($response['status_code'] ?? 0) < 300,
                'status_code' => (int)($response['status_code'] ?? 0),
                'headers' => (array)($response['headers'] ?? []),
                'body' => (string)($response['body'] ?? ''),
                'endpoint' => (string)($response['url'] ?? ''),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status_code' => 0,
                'headers' => [],
                'body' => '',
                'error' => $exception->getMessage(),
                'endpoint' => $this->baseUrlForMode($mode) . '/test/fraud-prevention-headers/validate',
            ];
        }
    }

    public function credentialsConfigured(string $mode): array
    {
        $mode = \HelperFramework::normaliseEnvironmentMode($mode);
        $config = $this->ct600Config($mode);
        try {
            \eel_accounts\Outbound\HmrcOutbound::loadCredential(
                (string)($config['credential_tag'] ?? 'CT600'),
                $mode,
                \SecurityStore::apiKeysPath(),
                (string)($config['credential_provider'] ?? 'HMRC')
            );

            return ['ok' => true, 'errors' => []];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function ct600Config(string $mode): array
    {
        $appConfig = \AppConfigurationStore::config();
        $config = is_array($appConfig['hmrc']['ct600'] ?? null) ? $appConfig['hmrc']['ct600'] : [];

        return array_replace([
            'accept_header' => 'application/xml',
            'credential_provider' => 'HMRC',
            'credential_tag' => 'CT600',
            'keys_path' => \SecurityStore::apiKeysPath(),
            'mode' => $mode,
            'oauth_path' => '/oauth/token',
            'submit_path' => '',
            'timeout_seconds' => 30,
            'token_scope' => '',
        ], $config, ['mode' => $mode]);
    }

    private function baseUrlForMode(string $mode): string
    {
        return \HelperFramework::normaliseEnvironmentMode($mode) === 'LIVE'
            ? 'https://api.service.hmrc.gov.uk'
            : 'https://test-api.service.hmrc.gov.uk';
    }
}

