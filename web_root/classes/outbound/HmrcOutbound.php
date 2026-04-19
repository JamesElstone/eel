<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'OutboundHelper.php';

final class HmrcOutbound implements VatValidationInterfaceService
{
    private ?string $accessToken = null;
    /** @var callable|null */
    private $outboundRequest;

    public function __construct(
        private readonly array $config = [],
        ?callable $outboundRequest = null,
    ) {
        $this->outboundRequest = $outboundRequest;
    }

    public static function loadCredential(
        string $tag = 'VAT_CHECK',
        string $environment = 'TEST',
        ?string $keysPath = null,
        string $provider = 'HMRC'
    ): array {
        return OutboundHelper::loadCredential(
            $provider,
            $tag,
            HelperFramework::normaliseEnvironmentMode($environment),
            $keysPath
        );
    }

    public static function tokenRequest(array $request): array
    {
        $defaultHeaders = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return OutboundHelper::request(array_replace([
            'transport' => 'http',
            'method' => 'POST',
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'oauth_client_credentials',
            'provider' => 'HMRC',
            'tag' => 'VAT_CHECK',
            'environment' => 'TEST',
            'timeout_seconds' => 10,
        ], $request));
    }

    public static function vatLookupRequest(array $request): array
    {
        $defaultHeaders = ['Accept' => 'application/vnd.hmrc.2.0+json'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return OutboundHelper::request(array_replace([
            'transport' => 'http',
            'method' => 'GET',
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'bearer',
            'timeout_seconds' => 10,
        ], $request));
    }

    public static function antiFraudValidationRequest(array $request): array
    {
        $defaultHeaders = [
            'Accept' => (string)($request['accept_header'] ?? 'application/vnd.hmrc.1.0+json'),
        ];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return OutboundHelper::request(array_replace([
            'transport' => 'http',
            'method' => strtoupper(trim((string)($request['validate_method'] ?? 'GET'))),
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'bearer',
            'timeout_seconds' => 10,
        ], $request));
    }

    public static function antiFraudValidatorConfig(?string $mode = null): array
    {
        $appConfig = AppConfigurationStore::config();
        $config = is_array($appConfig['hmrc']['fraud_prevention_validator'] ?? null)
            ? $appConfig['hmrc']['fraud_prevention_validator']
            : [];

        $config = array_replace([
            'accept_header' => 'application/vnd.hmrc.1.0+json',
            'credential_provider' => 'HMRC',
            'credential_tag' => 'FPH_VALIDATOR',
            'keys_path' => SecurityStore::apiKeysPath(),
            'mode' => 'TEST',
            'oauth_path' => '/oauth/token',
            'timeout_seconds' => 10,
            'token_scope' => '',
            'validate_method' => 'GET',
            'validate_path' => '/test/fraud-prevention-headers/validate',
        ], $config);

        if ($mode !== null && trim($mode) !== '') {
            $config['mode'] = HelperFramework::normaliseEnvironmentMode($mode);
        }

        if (trim((string)($config['keys_path'] ?? '')) === '') {
            $config['keys_path'] = SecurityStore::apiKeysPath();
        }

        return $config;
    }

    public function supports(string $countryCode): bool
    {
        return strtoupper(trim($countryCode)) === 'GB';
    }

    public function validate(string $countryCode, string $vatNumber): VatValidationResultService
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber = $this->normaliseVatNumber($vatNumber);

        if ($countryCode !== 'GB' || $vatNumber === '') {
            return VatValidationResultService::error('hmrc', 'A GB VAT registration number is required.');
        }

        try {
            $response = $this->lookupVatNumber($vatNumber);
        } catch (Throwable $e) {
            return VatValidationResultService::error('hmrc', 'Validation service unavailable: ' . $e->getMessage());
        }

        if (($response['status_code'] ?? 0) >= 500) {
            return VatValidationResultService::error('hmrc', 'Validation service unavailable.');
        }

        $payload = json_decode((string)($response['body'] ?? ''), true);

        if (!is_array($payload)) {
            return VatValidationResultService::error('hmrc', 'Unexpected HMRC validation response.');
        }

        $target = is_array($payload['target'] ?? null) ? $payload['target'] : $payload;
        $name = trim((string)($target['name'] ?? $target['traderName'] ?? '')) ?: null;
        $address = $this->normaliseAddress($target['address'] ?? $target['traderAddress'] ?? null);

        if (is_bool($payload['valid'] ?? null)) {
            return ($payload['valid']
                ? VatValidationResultService::valid('hmrc', $name, $address, ['payload' => $payload])
                : VatValidationResultService::invalid('hmrc', $name, $address, ['payload' => $payload]));
        }

        if (is_array($target) && array_key_exists('vatNumber', $target) && (int)($response['status_code'] ?? 0) === 200) {
            return VatValidationResultService::valid('hmrc', $name, $address, ['payload' => $payload]);
        }

        if (($response['status_code'] ?? 0) === 404) {
            return VatValidationResultService::invalid('hmrc', $name, $address, ['payload' => $payload]);
        }

        if (($response['status_code'] ?? 0) === 401 || ($response['status_code'] ?? 0) === 403) {
            return VatValidationResultService::error('hmrc', 'Invalid HMRC credentials or access token.', ['payload' => $payload]);
        }

        $message = trim((string)($payload['message'] ?? $payload['code'] ?? 'HMRC validation failed.'));

        return VatValidationResultService::error('hmrc', $message, ['payload' => $payload]);
    }

    public function normaliseVatNumber(string $vatNumber): string
    {
        $vatNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($vatNumber))) ?? '';

        if (str_starts_with($vatNumber, 'GB')) {
            return substr($vatNumber, 2);
        }

        return $vatNumber;
    }

    public function fetchAccessTokenResponse(): array
    {
        $cachedToken = $this->loadCachedAccessToken();

        if ($cachedToken !== null) {
            $this->accessToken = $cachedToken;

            return [
                'status_code' => 200,
                'headers' => [],
                'body' => json_encode(['access_token' => $cachedToken], JSON_UNESCAPED_SLASHES),
            ];
        }

        $request = [
            'base_url' => $this->baseUrl(),
            'path' => (string)($this->config['oauth_path'] ?? '/oauth/token'),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'auth' => 'oauth_client_credentials',
            'provider' => (string)($this->config['credential_provider'] ?? 'HMRC'),
            'tag' => (string)($this->config['credential_tag'] ?? 'VAT_CHECK'),
            'environment' => $this->credentialEnvironment(),
            'keys_path' => (string)($this->config['keys_path'] ?? ''),
            'client_id' => (string)($this->config['client_id'] ?? ''),
            'client_secret' => (string)($this->config['client_secret'] ?? ''),
            'form_params' => [
                'grant_type' => 'client_credentials',
                'scope' => (string)($this->config['token_scope'] ?? 'read:vat'),
            ],
            'timeout_seconds' => max(1, (int)($this->config['timeout_seconds'] ?? 10)),
        ];
        $response = is_callable($this->outboundRequest)
            ? ($this->outboundRequest)($request)
            : self::tokenRequest($request);

        $payload = json_decode((string)($response['body'] ?? ''), true);

        if (!is_array($payload) || trim((string)($payload['access_token'] ?? '')) === '') {
            $message = is_array($payload)
                ? trim((string)($payload['error_description'] ?? $payload['error'] ?? 'Unable to obtain HMRC access token.'))
                : 'Unable to obtain HMRC access token.';
            throw new RuntimeException($message);
        }

        $this->accessToken = trim((string)$payload['access_token']);
        $this->storeAccessTokenFromPayload($payload);

        return $response;
    }

    public function lookupVatNumber(string $vatNumber): array
    {
        $normalisedVatNumber = $this->normaliseVatNumber($vatNumber);

        if ($normalisedVatNumber === '') {
            throw new RuntimeException('A GB VAT registration number is required.');
        }

        $pathTemplate = (string)($this->config['lookup_path'] ?? '/organisations/vat/check-vat-number/lookup/{vatNumber}');
        $url = $this->baseUrl() . str_replace('{vatNumber}', rawurlencode($normalisedVatNumber), $pathTemplate);

        $request = [
            'url' => $url,
            'headers' => [
                'Accept' => (string)($this->config['accept_header'] ?? 'application/vnd.hmrc.2.0+json'),
            ],
            'auth' => 'bearer',
            'bearer_token' => $this->resolveAccessToken(),
            'timeout_seconds' => max(1, (int)($this->config['timeout_seconds'] ?? 10)),
        ];

        return is_callable($this->outboundRequest)
            ? ($this->outboundRequest)($request)
            : self::vatLookupRequest($request);
    }

    public function validateAntiFraudHeaders(?array $govHeaders = null): array
    {
        $request = [
            'base_url' => $this->baseUrl(),
            'path' => (string)($this->config['validate_path'] ?? '/test/fraud-prevention-headers/validate'),
            'headers' => array_replace([
                'Accept' => (string)($this->config['accept_header'] ?? 'application/vnd.hmrc.1.0+json'),
            ], $govHeaders ?? AntiFraudService::instance()->buildGovHeaders()),
            'auth' => 'bearer',
            'bearer_token' => $this->resolveAccessToken(),
            'timeout_seconds' => max(1, (int)($this->config['timeout_seconds'] ?? 10)),
            'validate_method' => (string)($this->config['validate_method'] ?? 'GET'),
            'accept_header' => (string)($this->config['accept_header'] ?? 'application/vnd.hmrc.1.0+json'),
        ];

        return is_callable($this->outboundRequest)
            ? ($this->outboundRequest)($request)
            : self::antiFraudValidationRequest($request);
    }

    private function resolveAccessToken(): string
    {
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        $cachedToken = $this->loadCachedAccessToken();

        if ($cachedToken !== null) {
            $this->accessToken = $cachedToken;
            return $this->accessToken;
        }

        $tokenResponse = $this->fetchAccessTokenResponse();
        $payload = json_decode((string)($tokenResponse['body'] ?? ''), true);
        $this->accessToken = trim((string)($payload['access_token'] ?? ''));

        if ($this->accessToken === '') {
            throw new RuntimeException('Unable to obtain HMRC access token.');
        }

        return $this->accessToken;
    }

    private function loadCachedAccessToken(): ?string
    {
        $cached = self::runtimeTokenGet($this->config);

        if (!is_array($cached)) {
            return null;
        }

        $token = trim((string)($cached['access_token'] ?? ''));
        $expiresAt = isset($cached['expires_at']) ? (int)$cached['expires_at'] : null;

        if ($token === '') {
            return null;
        }

        if ($expiresAt !== null && $expiresAt > 0 && $expiresAt <= (time() + 30)) {
            return null;
        }

        return $token;
    }

    private function storeAccessTokenFromPayload(array $payload): void
    {
        $token = trim((string)($payload['access_token'] ?? ''));

        if ($token === '') {
            return;
        }

        $expiresIn = isset($payload['expires_in']) ? (int)$payload['expires_in'] : 0;
        $expiresAt = $expiresIn > 0 ? time() + $expiresIn : null;

        self::runtimeTokenSet($this->config, $token, $expiresAt);
    }

    private function baseUrl(): string
    {
        $configuredBaseUrl = $this->baseUrlFromConfig();

        if ($configuredBaseUrl !== '') {
            return $configuredBaseUrl;
        }

        $provider = (string)($this->config['credential_provider'] ?? 'HMRC');
        $tag = (string)($this->config['credential_tag'] ?? 'VAT_CHECK');
        $environment = $this->credentialEnvironment();
        $keysPath = (string)($this->config['keys_path'] ?? '');

        try {
            $credential = self::loadCredential($tag, $environment, $keysPath, $provider);
        } catch (Throwable $exception) {
            throw new RuntimeException($this->missingCredentialMessage($provider, $tag, $environment, $exception), 0, $exception);
        }

        $host = trim((string)($credential['url'] ?? ''));
        if ($host === '') {
            throw new RuntimeException($this->serviceLabel() . ' is not configured with a base URL.');
        }

        $schema = strtoupper(trim((string)($credential['schema'] ?? 'HTTPS')));
        $scheme = $schema === 'HTTP' ? 'http://' : 'https://';

        return rtrim($scheme . ltrim($host, '/'), '/');
    }

    private function serviceLabel(): string
    {
        if (array_key_exists('validate_path', $this->config)) {
            return 'HMRC anti-fraud validator';
        }

        if (array_key_exists('lookup_path', $this->config)) {
            return 'HMRC VAT validation';
        }

        return 'HMRC service';
    }

    private function missingCredentialMessage(string $provider, string $tag, string $environment, Throwable $exception): string
    {
        $reason = trim($exception->getMessage());
        $prefix = sprintf(
            '%s credentials are not configured (%s / %s / %s)',
            $this->serviceLabel(),
            strtoupper(trim($provider)),
            strtoupper(trim($tag)),
            strtoupper(trim($environment))
        );

        $duplicateNotFound = 'API credential not found for '
            . strtoupper(trim($provider))
            . ' / '
            . strtoupper(trim($tag))
            . ' / '
            . strtoupper(trim($environment))
            . '.';

        if ($reason === $duplicateNotFound) {
            return $prefix . '.';
        }

        return sprintf(
            '%s: %s',
            $prefix,
            $reason
        );
    }

    private function baseUrlFromConfig(): string
    {
        $mode = $this->credentialEnvironment();
        $candidates = [];

        if ($mode === 'LIVE') {
            $candidates[] = (string)($this->config['live_base_url'] ?? '');
        } else {
            $candidates[] = (string)($this->config['test_base_url'] ?? '');
        }

        $candidates[] = (string)($this->config['base_url'] ?? '');

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return rtrim($candidate, '/');
            }
        }

        return '';
    }

    private function credentialEnvironment(): string
    {
        return HelperFramework::normaliseEnvironmentMode((string)($this->config['mode'] ?? 'TEST'));
    }

    private function normaliseAddress(mixed $address): ?string
    {
        if (is_string($address)) {
            $value = trim($address);

            return $value !== '' ? $value : null;
        }

        if (!is_array($address)) {
            return null;
        }

        $lines = [];

        foreach (['line1', 'line2', 'line3', 'line4', 'line5', 'postCode', 'postcode'] as $field) {
            $value = trim((string)($address[$field] ?? ''));

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines !== [] ? implode(PHP_EOL, array_values(array_unique($lines))) : null;
    }

    private static function runtimeTokenGet(array $config): ?array
    {
        $key = self::runtimeTokenKey($config);
        $tokens = is_array($GLOBALS['ctrl_hmrc_runtime_tokens'] ?? null) ? $GLOBALS['ctrl_hmrc_runtime_tokens'] : [];

        return is_array($tokens[$key] ?? null) ? $tokens[$key] : null;
    }

    private static function runtimeTokenSet(array $config, string $token, ?int $expiresAt = null): void
    {
        $key = self::runtimeTokenKey($config);
        $tokens = is_array($GLOBALS['ctrl_hmrc_runtime_tokens'] ?? null) ? $GLOBALS['ctrl_hmrc_runtime_tokens'] : [];
        $tokens[$key] = [
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ];
        $GLOBALS['ctrl_hmrc_runtime_tokens'] = $tokens;
    }

    private static function runtimeTokenKey(array $config): string
    {
        $mode = HelperFramework::normaliseEnvironmentMode((string)($config['mode'] ?? 'TEST'));
        $baseUrl = trim((string)($config['base_url'] ?? $config['test_base_url'] ?? $config['live_base_url'] ?? ''));
        $client = trim((string)($config['credential_tag'] ?? 'HMRC'));

        return $mode . '|' . $baseUrl . '|' . $client;
    }
}

