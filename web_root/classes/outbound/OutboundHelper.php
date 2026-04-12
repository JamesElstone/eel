<?php
declare(strict_types=1);


final class OutboundHelper
{
    public static function keysPath(?string $overridePath = null): string
    {
        $overridePath = trim((string)$overridePath);

        if ($overridePath !== '') {
            return $overridePath;
        }

        $config = FrameworkHelper::config();
        $configuredPath = trim((string)($config['api_keys']['path'] ?? ''));
        if ($configuredPath !== '') {
            return $configuredPath;
        }

        throw new RuntimeException('API keys path is not configured in config/app.php.');
    }

    public static function credentialCatalog(?string $keysPath = null): array
    {
        static $catalogByPath = [];

        $path = self::keysPath($keysPath);

        if (isset($catalogByPath[$path])) {
            return $catalogByPath[$path];
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('API key file was not found or is not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('API key file could not be opened: ' . $path);
        }

        $catalog = [];
        $lineNumber = 0;

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $lineNumber++;

                $firstField = trim((string)($row[0] ?? ''));

                if ($firstField !== '' && str_starts_with($firstField, '#')) {
                    continue;
                }

                if (strtoupper($firstField) === 'PROVIDER') {
                    continue;
                }

                if (count($row) < 5) {
                    continue;
                }

                $provider = strtoupper(trim((string)$row[0]));
                $tag = strtoupper(trim((string)$row[1]));

                if ($provider === '' || $tag === '') {
                    continue;
                }

                if (count($row) >= 6) {
                    $environment = FrameworkHelper::normaliseEnvironmentMode((string)$row[2]);
                    $credential = [
                        'provider' => $provider,
                        'tag' => $tag,
                        'environment' => $environment,
                        'schema' => strtoupper(trim((string)$row[3])),
                        'url' => trim((string)$row[4]),
                        'api_key' => trim((string)$row[5]),
                    ];
                    $catalog[$provider][$tag][$environment] = $credential;
                    continue;
                }

                $credential = [
                    'provider' => $provider,
                    'tag' => $tag,
                    'environment' => 'TEST',
                    'schema' => strtoupper(trim((string)$row[2])),
                    'url' => trim((string)$row[3]),
                    'api_key' => trim((string)$row[4]),
                ];
                $catalog[$provider][$tag]['DEFAULT'] = $credential;
            }
        } finally {
            fclose($handle);
        }

        $catalogByPath[$path] = $catalog;

        return $catalogByPath[$path];
    }

    public static function loadCredential(string $provider, string $tag, ?string $environment = null, ?string $keysPath = null): array
    {
        $provider = strtoupper(trim($provider));
        $tag = strtoupper(trim($tag));
        $environment = FrameworkHelper::normaliseEnvironmentMode($environment);
        $catalog = self::credentialCatalog($keysPath);
        $providerCatalog = $catalog[$provider] ?? [];
        $tagCatalog = is_array($providerCatalog[$tag] ?? null) ? $providerCatalog[$tag] : [];

        if (is_array($tagCatalog[$environment] ?? null)) {
            return $tagCatalog[$environment];
        }

        if (is_array($tagCatalog['DEFAULT'] ?? null)) {
            $fallbackCredential = $tagCatalog['DEFAULT'];
            $fallbackCredential['environment'] = $environment;

            return $fallbackCredential;
        }

        throw new RuntimeException('API credential not found for ' . $provider . ' / ' . $tag . ' / ' . $environment . '.');
    }

    public static function request(array $request): array
    {
        $transport = strtolower(trim((string)($request['transport'] ?? 'http')));
        $credential = is_array($request['credential'] ?? null) ? $request['credential'] : null;

        if (
            $credential === null
            && trim((string)($request['provider'] ?? '')) !== ''
            && trim((string)($request['tag'] ?? '')) !== ''
        ) {
            $credential = self::loadCredential(
                (string)$request['provider'],
                (string)$request['tag'],
                (string)($request['environment'] ?? 'TEST'),
                (string)($request['keys_path'] ?? '')
            );
        }

        if ($transport === 'soap') {
            return self::soapRequest($request, $credential);
        }

        return self::httpRequest($request, $credential);
    }

    public static function httpRequest(array $request, ?array $credential = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for outbound API requests.');
        }

        $timeoutSeconds = max(1, (int)($request['timeout_seconds'] ?? 10));
        $method = strtoupper(trim((string)($request['method'] ?? 'GET')));
        $url = trim((string)($request['url'] ?? ''));

        if ($url === '') {
            $baseUrl = trim((string)($request['base_url'] ?? ''));

            if ($baseUrl === '' && is_array($credential)) {
                $scheme = strtolower(trim((string)($credential['schema'] ?? 'https')));
                $host = trim((string)($credential['url'] ?? ''));

                if ($host !== '') {
                    $baseUrl = $scheme . '://' . $host;
                }
            }

            if ($baseUrl === '') {
                throw new RuntimeException('Outbound request is missing a URL or base URL.');
            }

            $path = (string)($request['path'] ?? '');
            $url = rtrim($baseUrl, '/');

            if ($path !== '') {
                $url .= '/' . ltrim($path, '/');
            }

            $query = is_array($request['query'] ?? null) ? $request['query'] : [];

            if ($query !== []) {
                $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $body = array_key_exists('body', $request) ? (is_string($request['body']) ? $request['body'] : (string)$request['body']) : null;
        $authMode = strtolower(trim((string)($request['auth'] ?? 'none')));
        $followLocation = !empty($request['follow_location']);
        $maxRedirects = max(0, (int)($request['max_redirects'] ?? 0));
        $captureBody = !array_key_exists('capture_body', $request) || !empty($request['capture_body']);
        $maxResponseBytes = max(0, (int)($request['max_response_bytes'] ?? 0));
        $userAgent = trim((string)($request['user_agent'] ?? ''));
        $sink = $request['sink'] ?? null;
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followLocation,
            CURLOPT_MAXREDIRS => $maxRedirects,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ];

        if ($authMode === 'basic_api_key') {
            $apiKey = trim((string)($request['api_key'] ?? ($credential['api_key'] ?? '')));

            if ($apiKey === '') {
                throw new RuntimeException('Outbound request is missing the API key for basic authentication.');
            }

            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curlOptions[CURLOPT_USERPWD] = $apiKey . ':';
        } elseif ($authMode === 'bearer') {
            $bearerToken = trim((string)($request['bearer_token'] ?? ''));

            if ($bearerToken === '') {
                throw new RuntimeException('Outbound request is missing a bearer token.');
            }

            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        } elseif ($authMode === 'oauth_client_credentials') {
            [$clientId, $clientSecret] = self::resolveClientCredentials($request, $credential);
            $formParams = is_array($request['form_params'] ?? null) ? $request['form_params'] : [];
            $formParams['client_id'] = $clientId;
            $formParams['client_secret'] = $clientSecret;
            $formParams['grant_type'] = trim((string)($formParams['grant_type'] ?? 'client_credentials')) ?: 'client_credentials';
            $body = http_build_query($formParams, '', '&', PHP_QUERY_RFC3986);

            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }

        $formattedHeaders = [];
        $responseHeaders = [];
        $responseBodyBuffer = '';
        $downloadedBytes = 0;

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $formattedHeaders[] = (string)$value;
                continue;
            }

            $formattedHeaders[] = $name . ': ' . $value;
        }

        if ($formattedHeaders !== []) {
            $curlOptions[CURLOPT_HTTPHEADER] = $formattedHeaders;
        }

        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);

            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $length;
        };

        if ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        if ($userAgent !== '') {
            $curlOptions[CURLOPT_USERAGENT] = $userAgent;
        }

        if (isset($request['protocols'])) {
            $curlOptions[CURLOPT_PROTOCOLS] = (int)$request['protocols'];
        }

        if (isset($request['redir_protocols'])) {
            $curlOptions[CURLOPT_REDIR_PROTOCOLS] = (int)$request['redir_protocols'];
        }

        if (array_key_exists('ssl_verify_peer', $request)) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = !empty($request['ssl_verify_peer']);
        }

        if (array_key_exists('ssl_verify_host', $request)) {
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = (int)$request['ssl_verify_host'];
        }

        if (array_key_exists('fail_on_error', $request)) {
            $curlOptions[CURLOPT_FAILONERROR] = !empty($request['fail_on_error']);
        }

        if (is_resource($sink) || !$captureBody || $maxResponseBytes > 0) {
            $curlOptions[CURLOPT_RETURNTRANSFER] = false;
            $curlOptions[CURLOPT_WRITEFUNCTION] = static function ($curlHandle, string $chunk) use ($sink, $captureBody, $maxResponseBytes, &$responseBodyBuffer, &$downloadedBytes): int {
                $length = strlen($chunk);
                $downloadedBytes += $length;

                if ($maxResponseBytes > 0 && $downloadedBytes > $maxResponseBytes) {
                    return 0;
                }

                if ($captureBody) {
                    $responseBodyBuffer .= $chunk;
                }

                if (is_resource($sink)) {
                    $written = fwrite($sink, $chunk);

                    return $written === false ? 0 : $written;
                }

                return $length;
            };
        }

        $extraCurlOptions = is_array($request['curl_options'] ?? null) ? $request['curl_options'] : [];
        foreach ($extraCurlOptions as $option => $value) {
            if (is_int($option)) {
                $curlOptions[$option] = $value;
            }
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Unable to initialise the outbound HTTP client.');
        }

        curl_setopt_array($curl, $curlOptions);
        $responseBody = curl_exec($curl);

        if ($responseBody === false) {
            $message = $maxResponseBytes > 0 && $downloadedBytes > $maxResponseBytes
                ? 'The remote response exceeded the allowed size limit.'
                : curl_error($curl);
            curl_close($curl);
            throw new RuntimeException($message !== '' ? $message : 'The remote service did not respond.');
        }

        $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => is_string($responseBody) ? $responseBody : $responseBodyBuffer,
            'url' => $url,
            'credential' => $credential,
            'content_type' => $contentType,
            'downloaded_bytes' => $downloadedBytes,
        ];
    }

    public static function soapRequest(array $request, ?array $credential = null): array
    {
        if (!class_exists('SoapClient')) {
            throw new RuntimeException('The PHP SOAP extension is required for outbound SOAP requests.');
        }

        $wsdlUrl = trim((string)($request['wsdl_url'] ?? ''));

        if ($wsdlUrl === '') {
            throw new RuntimeException('Outbound SOAP request is missing a WSDL URL.');
        }

        $soapAction = trim((string)($request['soap_action'] ?? ''));

        if ($soapAction === '') {
            throw new RuntimeException('Outbound SOAP request is missing the SOAP action name.');
        }

        $timeoutSeconds = max(1, (int)($request['timeout_seconds'] ?? 10));
        $soapOptions = is_array($request['soap_options'] ?? null) ? $request['soap_options'] : [];
        $soapOptions = array_replace([
            'connection_timeout' => $timeoutSeconds,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'exceptions' => true,
            'trace' => false,
        ], $soapOptions);

        $client = new SoapClient($wsdlUrl, $soapOptions);
        $params = is_array($request['soap_params'] ?? null) ? $request['soap_params'] : [];
        $result = $client->__soapCall($soapAction, [$params]);

        return [
            'status_code' => 200,
            'headers' => [],
            'body' => '',
            'url' => $wsdlUrl,
            'credential' => $credential,
            'result' => $result,
        ];
    }

    public static function resolveClientCredentials(array $request, ?array $credential = null): array
    {
        $clientId = trim((string)($request['client_id'] ?? ''));
        $clientSecret = trim((string)($request['client_secret'] ?? ''));

        if ($clientId !== '' && $clientSecret !== '') {
            return [$clientId, $clientSecret];
        }

        $apiKey = trim((string)($credential['api_key'] ?? ''));
        $separator = strpos($apiKey, ':');

        if ($separator === false) {
            throw new RuntimeException('OAuth client credentials in api.keys must be stored as clientId:clientSecret.');
        }

        $clientId = trim(substr($apiKey, 0, $separator));
        $clientSecret = trim(substr($apiKey, $separator + 1));

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('OAuth client credentials in api.keys are incomplete.');
        }

        return [$clientId, $clientSecret];
    }
}
