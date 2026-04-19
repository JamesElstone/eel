<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AntiFraudService
{
    public const HEADER_PREFIX = 'X-AntiFraud-';
    public const COOKIE_PREFIX = 'af_';

    private static ?self $instance = null;

    private ?array $config = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $appConfig = AppConfigurationStore::config();
        $antifraudConfig = is_array($appConfig['antifraud'] ?? null) ? $appConfig['antifraud'] : [];
        $vendorProductName = $this->normaliseOptionalString($antifraudConfig['vendor_product_name'] ?? 'HMRC Account App');
        $vendorSoftwareName = $this->vendorSoftwareName($vendorProductName);
        $vendorLicenseIds = $this->buildVendorLicenseIdsValue(
            $vendorSoftwareName,
            $this->normaliseOptionalString($antifraudConfig['vendor_license_ids'] ?? null)
        );
        $vendorVersion = $this->buildVendorVersionValue(
            $vendorSoftwareName,
            $this->normaliseOptionalString($antifraudConfig['vendor_version'] ?? 'dev')
        );

        return $this->config = [
            'vendor_license_ids' => $vendorLicenseIds,
            'vendor_product_name' => $vendorProductName,
            'vendor_public_ip' => $this->normaliseOptionalString($antifraudConfig['vendor_public_ip'] ?? null),
            'vendor_software_name' => $vendorSoftwareName,
            'vendor_version' => $vendorVersion,
        ];
    }

    public function initAntifraudData(): array
    {
        if (isset($GLOBALS['antifraud_data']) && is_array($GLOBALS['antifraud_data'])) {
            return $GLOBALS['antifraud_data'];
        }

        $config = $this->config();
        $clientPublicIp = $this->detectClientPublicIp();
        $vendorPublicIp = $this->detectVendorPublicIp($config['vendor_public_ip']);
        $deviceId = $this->requestValue('Client-Device-ID');
        $sessionAntiFraudContext = $this->authenticatedSessionAntiFraudContext($deviceId);

        $af = [
            self::HEADER_PREFIX . 'Client-Connection-Method' => 'WEB_APP_VIA_SERVER',
            self::HEADER_PREFIX . 'Client-Browser-JS-User-Agent' => $this->requestValue('Client-Browser-JS-User-Agent'),
            self::HEADER_PREFIX . 'Client-Device-ID' => $deviceId,
            self::HEADER_PREFIX . 'Client-Multi-Factor' => $this->buildClientMultiFactorValue($sessionAntiFraudContext),
            self::HEADER_PREFIX . 'Client-Public-IP' => $clientPublicIp,
            self::HEADER_PREFIX . 'Client-Public-IP-Timestamp' => $this->currentUtcTimestamp(),
            self::HEADER_PREFIX . 'Client-Public-Port' => $this->normaliseOptionalString($_SERVER['REMOTE_PORT'] ?? null),
            self::HEADER_PREFIX . 'Client-Screens' => $this->requestValue('Client-Screens'),
            self::HEADER_PREFIX . 'Client-Timezone' => $this->requestValue('Client-Timezone'),
            self::HEADER_PREFIX . 'Client-User-IDs' => $this->buildClientUserIdsValue($sessionAntiFraudContext),
            self::HEADER_PREFIX . 'Client-Window-Size' => $this->requestValue('Client-Window-Size'),
            self::HEADER_PREFIX . 'Vendor-Forwarded' => $this->buildVendorForwardedValue($clientPublicIp, $vendorPublicIp),
            self::HEADER_PREFIX . 'Vendor-License-IDs' => $config['vendor_license_ids'],
            self::HEADER_PREFIX . 'Vendor-Product-Name' => $config['vendor_product_name'],
            self::HEADER_PREFIX . 'Vendor-Public-IP' => $vendorPublicIp,
            self::HEADER_PREFIX . 'Vendor-Version' => $config['vendor_version'],
        ];

        foreach ($af as $headerName => $value) {
            if (!is_string($value)) {
                continue;
            }

            $af[$headerName] = $this->formatAntiFraudHeaderValue($headerName, $value);
        }

        $data = [
            'af' => array_filter(
                $af,
                static fn(mixed $value): bool => $value !== null && $value !== ''
            ),
        ];

        $GLOBALS['antifraud_data'] = $data;

        return $data;
    }

    public function getAntifraudData(): array
    {
        return $this->initAntifraudData();
    }

    public function getAntiFraudHeaders(): array
    {
        $data = $this->initAntifraudData();

        return is_array($data['af'] ?? null) ? $data['af'] : [];
    }

    public function buildGovHeaders(): array
    {
        $headers = [];

        foreach ($this->getAntiFraudHeaders() as $antiFraudHeaderName => $value) {
            $suffix = substr((string)$antiFraudHeaderName, strlen(self::HEADER_PREFIX));
            if ($suffix === false || $suffix === '') {
                continue;
            }

            $govHeaderName = 'Gov-' . $suffix;
            $headers[$govHeaderName] = (string)$value;
        }

        return $headers;
    }

    public function requestValue(string $fieldName): ?string
    {
        $headerName = self::HEADER_PREFIX . $fieldName;
        $headerValue = $this->readHeaderValue($headerName);

        if ($headerValue !== null) {
            return $headerValue;
        }

        $cookieName = self::COOKIE_PREFIX . $this->cookieSuffixFromField($fieldName);

        return $this->normaliseOptionalString($_COOKIE[$cookieName] ?? null);
    }

    public function readHeaderValue(string $headerName): ?string
    {
        $headers = $this->getRequestHeaders();

        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, $headerName) === 0) {
                return $this->normaliseOptionalString($value);
            }
        }

        return null;
    }

    public function getRequestHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $rawHeaders = getallheaders();
            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $name => $value) {
                    $headers[(string)$name] = is_scalar($value) ? (string)$value : '';
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || strncmp($key, 'HTTP_', 5) !== 0) {
                continue;
            }

            $headerName = HelperFramework::httpHeaderLabelFromServerKey($key);
            $headers[$headerName] = is_scalar($value) ? (string)$value : '';
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $contentKey) {
            if (!isset($_SERVER[$contentKey])) {
                continue;
            }

            $headerName = HelperFramework::httpHeaderLabelFromServerKey($contentKey);
            $headers[$headerName] = is_scalar($_SERVER[$contentKey]) ? (string)$_SERVER[$contentKey] : '';
        }

        return $headers;
    }

    public function cookieSuffixFromField(string $fieldName): string
    {
        return strtolower(str_replace('-', '_', $fieldName));
    }

    public function normaliseOptionalString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $stringValue = trim((string)$value);

        return $stringValue === '' ? null : $stringValue;
    }

    public function encodeUsAsciiPercent(?string $value): ?string
    {
        $value = $this->normaliseOptionalString($value);

        if ($value === null) {
            return null;
        }

        $encoded = '';
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            $utf8 = mb_convert_encoding($character, 'UTF-8', 'UTF-8');

            if (strlen($utf8) === 1) {
                $ord = ord($utf8);

                if ($ord >= 0x20 && $ord <= 0x7E) {
                    $encoded .= $utf8;
                    continue;
                }
            }

            foreach (str_split($utf8) as $byte) {
                $encoded .= sprintf('%%%02X', ord($byte));
            }
        }

        return $encoded;
    }

    public function encodeStructuredComponent(?string $value): ?string
    {
        $value = $this->normaliseOptionalString($value);

        if ($value === null) {
            return null;
        }

        return rawurlencode($value);
    }

    public function formatGovHeaderValue(string $headerName, string $value): string
    {
        if (in_array($headerName, ['Gov-Client-Screens', 'Gov-Client-Window-Size'], true)) {
            return $this->formatKeyValueSequence($value, '&');
        }

        if (in_array($headerName, ['Gov-Client-Multi-Factor', 'Gov-Client-User-IDs', 'Gov-Vendor-License-IDs', 'Gov-Vendor-Version'], true)) {
            return $this->formatKeyValueSequence($value, '&');
        }

        if ($headerName === 'Gov-Vendor-Forwarded') {
            return $this->formatStructuredList($value);
        }

        if ($headerName === 'Gov-Vendor-Product-Name') {
            return (string)($this->encodeStructuredComponent($value) ?? '');
        }

        return (string)($this->encodeUsAsciiPercent($value) ?? '');
    }

    public function formatAntiFraudHeaderValue(string $headerName, string $value): string
    {
        $govHeaderName = 'Gov-' . substr($headerName, strlen(self::HEADER_PREFIX));

        return $this->formatGovHeaderValue($govHeaderName, $value);
    }

    private function authenticatedSessionAntiFraudContext(?string $deviceId): array
    {
        $sessionAuthenticationService = new SessionAuthenticationService();

        return $sessionAuthenticationService->authenticatedAntiFraudContext($deviceId);
    }

    private function buildClientUserIdsValue(array $context): ?string
    {
        $userId = max(0, (int)($context['user_id'] ?? 0));
        $emailAddress = $this->normaliseOptionalString($context['email_address'] ?? null);
        $pairs = [];

        if ($userId > 0) {
            $pairs[] = 'eel-accounts-user-id=' . $userId;
        }

        if ($emailAddress !== null) {
            $pairs[] = 'email=' . $emailAddress;
        }

        if ($pairs === []) {
            return null;
        }

        return implode('&', $pairs);
    }

    private function buildClientMultiFactorValue(array $context): ?string
    {
        $mfa = is_array($context['mfa'] ?? null) ? $context['mfa'] : [];
        $type = $this->normaliseOptionalString($mfa['type'] ?? null);
        $timestamp = $this->normaliseOptionalString($mfa['timestamp'] ?? null);
        $uniqueReference = $this->normaliseOptionalString($mfa['unique_reference'] ?? null);

        if ($type === null || $timestamp === null || $uniqueReference === null) {
            return null;
        }

        return 'type=' . $type
            . '&timestamp=' . $timestamp
            . '&unique-reference=' . $uniqueReference;
    }

    private function vendorSoftwareName(?string $vendorProductName): string
    {
        $vendorProductName = strtolower(trim((string)$vendorProductName));
        $vendorProductName = preg_replace('/[^a-z0-9]+/', '-', $vendorProductName) ?? '';
        $vendorProductName = trim($vendorProductName, '-');

        return $vendorProductName !== '' ? $vendorProductName : 'hmrc-account-app';
    }

    private function buildVendorLicenseIdsValue(string $vendorSoftwareName, ?string $configuredValue): ?string
    {
        $configuredValue = $this->normaliseOptionalString($configuredValue);

        if ($configuredValue === null) {
            return null;
        }

        if (str_contains($configuredValue, '=')) {
            return $configuredValue;
        }

        return $vendorSoftwareName . '=' . hash('sha256', $configuredValue);
    }

    private function buildVendorVersionValue(string $vendorSoftwareName, ?string $configuredValue): ?string
    {
        $configuredValue = $this->normaliseOptionalString($configuredValue);

        if ($configuredValue === null) {
            return null;
        }

        if (str_contains($configuredValue, '=')) {
            return $configuredValue;
        }

        return $vendorSoftwareName . '=' . $configuredValue;
    }

    public function currentUtcTimestamp(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Forwarded headers are deployment-specific and may be spoofed unless the app
     * is behind trusted proxy handling configured by the operator.
     */
    public function detectClientPublicIp(): ?string
    {
        $headers = $this->getRequestHeaders();
        $candidates = [];

        foreach (['Cf-Connecting-Ip', 'True-Client-Ip', 'X-Real-Ip'] as $headerName) {
            $value = $headers[$headerName] ?? null;
            if ($value !== null) {
                $candidates[] = (string)$value;
            }
        }

        $xForwardedFor = $headers['X-Forwarded-For'] ?? null;
        if ($xForwardedFor !== null) {
            $candidates = array_merge($candidates, explode(',', (string)$xForwardedFor));
        }

        $forwarded = $headers['Forwarded'] ?? null;
        if ($forwarded !== null) {
            foreach (preg_split('/,/', (string)$forwarded) ?: [] as $segment) {
                if (preg_match('/for=(?:"?\\[?([^;,"\]]+)\\]?"?)/i', $segment, $matches) === 1) {
                    $candidates[] = $matches[1];
                }
            }
        }

        $remoteAddr = $this->normaliseOptionalString($_SERVER['REMOTE_ADDR'] ?? null);
        if ($remoteAddr !== null) {
            $candidates[] = $remoteAddr;
        }

        $firstValid = null;

        foreach ($candidates as $candidate) {
            $ip = $this->extractIp((string)$candidate);
            if ($ip === null) {
                continue;
            }

            if ($firstValid === null) {
                $firstValid = $ip;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $ip;
            }
        }

        return $firstValid;
    }

    public function extractIp(string $value): ?string
    {
        $candidate = trim($value, " \t\n\r\0\x0B\"'[]");

        if ($candidate === '') {
            return null;
        }

        if (substr_count($candidate, ':') === 1 && strpos($candidate, '.') !== false) {
            $parts = explode(':', $candidate, 2);
            if (isset($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $candidate = $parts[0];
            }
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $candidate;
    }

    public function detectVendorForwarded(?string $configuredVendorPublicIp = null): ?string
    {
        $clientPublicIp = $this->detectClientPublicIp();
        $vendorPublicIp = $this->detectVendorPublicIp($configuredVendorPublicIp ?? ($this->config()['vendor_public_ip'] ?? null));

        return $this->buildVendorForwardedValue($clientPublicIp, $vendorPublicIp);
    }

    public function detectVendorPublicIp(?string $configuredValue): ?string
    {
        $configuredIp = $this->extractIp((string)$configuredValue);
        if ($configuredIp !== null) {
            return $configuredIp;
        }

        foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $serverKey) {
            $ip = $this->extractIp((string)($_SERVER[$serverKey] ?? ''));
            if ($ip === null) {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $ip;
            }
        }

        return null;
    }

    private function buildVendorForwardedValue(?string $clientPublicIp, ?string $vendorPublicIp): ?string
    {
        $startIp = $this->extractIp((string)$clientPublicIp);
        $endIp = $this->extractIp((string)$vendorPublicIp);

        if ($startIp === null || $endIp === null) {
            return null;
        }

        $chain = [$startIp];

        foreach ($this->proxyChainCandidates() as $candidate) {
            $ip = $this->extractIp($candidate);

            if ($ip === null) {
                continue;
            }

            $chain[] = $ip;
        }

        $chain[] = $endIp;
        $chain = array_values(array_unique($chain));

        if (count($chain) < 2) {
            return null;
        }

        $segments = [];

        for ($index = 0, $lastIndex = count($chain) - 1; $index < $lastIndex; $index += 1) {
            $segments[] = 'by=' . $chain[$index + 1] . '&for=' . $chain[$index];
        }

        return implode(',', $segments);
    }

    /**
     * @return list<string>
     */
    private function proxyChainCandidates(): array
    {
        $headers = $this->getRequestHeaders();
        $candidates = [];

        foreach (['Cf-Connecting-Ip', 'True-Client-Ip', 'X-Real-Ip'] as $headerName) {
            $value = $this->normaliseOptionalString($headers[$headerName] ?? null);

            if ($value !== null) {
                $candidates[] = $value;
            }
        }

        $forwarded = $this->normaliseOptionalString($headers['Forwarded'] ?? null);
        if ($forwarded !== null) {
            foreach (preg_split('/,/', $forwarded) ?: [] as $segment) {
                if (preg_match_all('/(?:for|by)=(?:"?\\[?([^;,"\]]+)\\]?"?)/i', $segment, $matches) > 0) {
                    foreach ((array)($matches[1] ?? []) as $match) {
                        $candidates[] = (string)$match;
                    }
                }
            }
        }

        $xForwardedFor = $this->normaliseOptionalString($headers['X-Forwarded-For'] ?? null);
        if ($xForwardedFor !== null) {
            foreach (explode(',', $xForwardedFor) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        $remoteAddr = $this->normaliseOptionalString($_SERVER['REMOTE_ADDR'] ?? null);
        if ($remoteAddr !== null) {
            $candidates[] = $remoteAddr;
        }

        return $candidates;
    }

    private function formatStructuredList(string $value): string
    {
        $segments = preg_split('/\s*,\s*/', trim($value)) ?: [];
        $formatted = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $formatted[] = $this->formatKeyValueSequence($segment, '&');
        }

        return implode(',', $formatted);
    }

    private function formatKeyValueSequence(string $value, string $separator): string
    {
        $parts = preg_split('/\s*' . preg_quote($separator, '/') . '\s*/', trim($value)) ?: [];
        $formatted = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $pair = explode('=', $part, 2);
            $key = $this->encodeStructuredComponent((string)($pair[0] ?? ''));
            $pairValue = $this->encodeStructuredComponent((string)($pair[1] ?? ''));

            if ($key === null || $key === '') {
                continue;
            }

            if ($pairValue === null) {
                $formatted[] = $key;
                continue;
            }

            $formatted[] = $key . '=' . $pairValue;
        }

        return implode($separator, $formatted);
    }
}


