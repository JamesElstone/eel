<?php
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

        return $this->config = [
            'vendor_license_ids' => $this->normaliseOptionalString($antifraudConfig['vendor_license_ids'] ?? null),
            'vendor_product_name' => $this->normaliseOptionalString($antifraudConfig['vendor_product_name'] ?? 'HMRC Account App'),
            'vendor_public_ip' => $this->normaliseOptionalString($antifraudConfig['vendor_public_ip'] ?? null),
            'vendor_version' => $this->normaliseOptionalString($antifraudConfig['vendor_version'] ?? 'dev'),
        ];
    }

    public function initAntifraudData(): array
    {
        if (isset($GLOBALS['antifraud_data']) && is_array($GLOBALS['antifraud_data'])) {
            return $GLOBALS['antifraud_data'];
        }

        $config = $this->config();

        $data = [
            'Client-Connection-Method' => 'WEB_APP_VIA_SERVER',
            'Client-Browser-JS-User-Agent' => $this->requestValue('Client-Browser-JS-User-Agent'),
            'Client-Device-ID' => $this->requestValue('Client-Device-ID'),
            // Future hook: populate when the app gains user MFA state.
            'Client-Multi-Factor' => null,
            'Client-Public-IP' => $this->detectClientPublicIp(),
            'Client-Public-IP-Timestamp' => $this->currentUtcTimestamp(),
            'Client-Public-Port' => $this->normaliseOptionalString($_SERVER['REMOTE_PORT'] ?? null),
            'Client-Screens' => $this->requestValue('Client-Screens'),
            'Client-Timezone' => $this->requestValue('Client-Timezone'),
            // Future hook: populate when authenticated user/session identifiers exist.
            'Client-User-IDs' => null,
            'Client-Window-Size' => $this->requestValue('Client-Window-Size'),
            'Vendor-Forwarded' => $this->detectVendorForwarded(),
            'Vendor-License-IDs' => $config['vendor_license_ids'],
            'Vendor-Product-Name' => $config['vendor_product_name'],
            'Vendor-Public-IP' => $this->detectVendorPublicIp($config['vendor_public_ip']),
            'Vendor-Version' => $config['vendor_version'],
        ];

        $GLOBALS['antifraud_data'] = $data;

        return $data;
    }

    public function getAntifraudData(): array
    {
        return $this->initAntifraudData();
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

            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = is_scalar($value) ? (string)$value : '';
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $contentKey) {
            if (!isset($_SERVER[$contentKey])) {
                continue;
            }

            $headerName = str_replace('_', '-', ucwords(strtolower($contentKey), '_'));
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

    public function detectVendorForwarded(): ?string
    {
        $headers = $this->getRequestHeaders();
        $pairs = [];

        foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'Via'] as $headerName) {
            $value = $this->normaliseOptionalString($headers[$headerName] ?? null);
            if ($value !== null) {
                $pairs[] = rawurlencode(strtolower($headerName)) . '=' . rawurlencode($value);
            }
        }

        if ($pairs !== []) {
            return implode('&', $pairs);
        }

        return null;
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
}


