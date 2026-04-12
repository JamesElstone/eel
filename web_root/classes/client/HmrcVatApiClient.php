<?php
declare(strict_types=1);

final class HmrcVatApiClient
{
    private ?string $accessToken = null;
    /** @var callable */
    private $outboundRequest;

    public function __construct(
        private readonly array $config,
        ?callable $outboundRequest = null,
    ) {
        $this->outboundRequest = $outboundRequest;
    }

    public function normaliseVatNumber(string $vatNumber): string {
        $vatNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($vatNumber))) ?? '';

        if (str_starts_with($vatNumber, 'GB')) {
            return substr($vatNumber, 2);
        }

        return $vatNumber;
    }

    public function fetchAccessTokenResponse(): array {
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
            : HmrcOutbound::tokenRequest($request);

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

    public function lookupVatNumber(string $vatNumber): array {
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
            : HmrcOutbound::vatLookupRequest($request);
    }

    private function resolveAccessToken(): string {
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

    private function loadCachedAccessToken(): ?string {
        $cached = FrameworkHelper::hmrcRuntimeTokenGet($this->config);

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

    private function storeAccessTokenFromPayload(array $payload): void {
        $token = trim((string)($payload['access_token'] ?? ''));

        if ($token === '') {
            return;
        }

        $expiresIn = isset($payload['expires_in']) ? (int)$payload['expires_in'] : 0;
        $expiresAt = $expiresIn > 0 ? time() + $expiresIn : null;

        FrameworkHelper::hmrcRuntimeTokenSet($this->config, $token, $expiresAt);
    }

    private function baseUrl(): string {
        $baseUrl = $this->baseUrlFromCredential();

        if ($baseUrl === '') {
            throw new RuntimeException('HMRC VAT validation is not configured.');
        }

        return $baseUrl;
    }

    private function baseUrlFromCredential(): string {
        $configuredBaseUrl = $this->baseUrlFromConfig();

        if ($configuredBaseUrl !== '') {
            return $configuredBaseUrl;
        }

        $provider = (string)($this->config['credential_provider'] ?? 'HMRC');
        $tag = (string)($this->config['credential_tag'] ?? 'VAT_CHECK');
        $environment = $this->credentialEnvironment();
        $keysPath = (string)($this->config['keys_path'] ?? '');

        try {
            $credential = HmrcOutbound::loadCredential($tag, $environment, $keysPath, $provider);
        } catch (Throwable) {
            return '';
        }

        $host = trim((string)($credential['url'] ?? ''));
        if ($host === '') {
            return '';
        }

        $schema = strtoupper(trim((string)($credential['schema'] ?? 'HTTPS')));
        $scheme = $schema === 'HTTP' ? 'http://' : 'https://';

        return rtrim($scheme . ltrim($host, '/'), '/');
    }

    private function baseUrlFromConfig(): string {
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

    private function credentialEnvironment(): string {
        return FrameworkHelper::normaliseEnvironmentMode((string)($this->config['mode'] ?? 'TEST'));
    }
}
