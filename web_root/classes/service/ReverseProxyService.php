<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ReverseProxyService
{
    public function clientIpAddress(RequestFramework $request): string
    {
        $remoteAddress = $this->normaliseIp((string)($request->remoteAddress() ?? ''));
        if ($remoteAddress === '') {
            return '';
        }

        if (!$this->isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        foreach ($this->clientIpHeaders() as $headerName) {
            $value = trim((string)$request->header($headerName, ''));
            if ($value === '') {
                continue;
            }

            $clientIp = $this->clientIpFromHeader($headerName, $value);
            if ($clientIp !== '') {
                return $clientIp;
            }
        }

        return $remoteAddress;
    }

    public function trustedProxyIps(): array
    {
        return $this->normaliseList(AppConfigurationStore::get('reverse_proxy.trusted_proxy_ips', []));
    }

    public function isTrustedProxyRequest(RequestFramework $request): bool
    {
        $remoteAddress = $this->normaliseIp((string)($request->remoteAddress() ?? ''));

        return $remoteAddress !== '' && $this->isTrustedProxy($remoteAddress);
    }

    public function forwardedHost(RequestFramework $request): string
    {
        if (!$this->isTrustedProxyRequest($request)) {
            return '';
        }

        $host = $this->normaliseHost($this->firstHeaderValue((string)$request->header('X-Forwarded-Host', '')));
        if ($host !== '') {
            return $host;
        }

        return $this->normaliseHost($this->forwardedHeaderParameter((string)$request->header('Forwarded', ''), 'host'));
    }

    public function forwardedScheme(RequestFramework $request): string
    {
        if (!$this->isTrustedProxyRequest($request)) {
            return '';
        }

        $scheme = $this->normaliseScheme($this->firstHeaderValue((string)$request->header('X-Forwarded-Proto', '')));
        if ($scheme !== '') {
            return $scheme;
        }

        return $this->normaliseScheme($this->forwardedHeaderParameter((string)$request->header('Forwarded', ''), 'proto'));
    }

    public function clientIpHeaders(): array
    {
        $headers = [];
        foreach ($this->normaliseList(AppConfigurationStore::get('reverse_proxy.client_ip_headers', [])) as $headerName) {
            $headerName = HelperFramework::httpHeaderLabelFromServerKey(str_replace('-', '_', $headerName));
            if ($headerName !== '') {
                $headers[] = $headerName;
            }
        }

        return array_values(array_unique($headers));
    }

    private function isTrustedProxy(string $remoteAddress): bool
    {
        return in_array($remoteAddress, $this->trustedProxyIps(), true);
    }

    private function clientIpFromHeader(string $headerName, string $value): string
    {
        if (strcasecmp($headerName, 'Forwarded') === 0) {
            foreach (preg_split('/,/', $value) ?: [] as $segment) {
                if (preg_match('/for=(?:"?\[?([^;,"\]]+)\]?"?)/i', $segment, $matches) === 1) {
                    $ip = $this->normaliseIp((string)$matches[1]);
                    if ($ip !== '') {
                        return $ip;
                    }
                }
            }

            return '';
        }

        foreach (explode(',', $value) as $candidate) {
            $ip = $this->normaliseIp($candidate);
            if ($ip !== '') {
                return $ip;
            }
        }

        return '';
    }

    private function firstHeaderValue(string $value): string
    {
        return trim(explode(',', $value)[0] ?? '');
    }

    private function forwardedHeaderParameter(string $value, string $name): string
    {
        foreach (preg_split('/,/', $value) ?: [] as $segment) {
            if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '=(?:"?([^;,"\s]+)"?)/i', $segment, $matches) === 1) {
                return trim((string)$matches[1]);
            }
        }

        return '';
    }

    private function normaliseHost(string $value): string
    {
        $value = strtolower(trim($value, " \t\n\r\0\x0B\"'"));
        if (
            $value === ''
            || strlen($value) > 255
            || preg_match('/[\x00-\x1F\x7F\s\/\\\\@]/', $value) === 1
        ) {
            return '';
        }

        if (str_starts_with($value, '[')) {
            if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/', $value, $matches) !== 1) {
                return '';
            }

            if (filter_var((string)$matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return '';
            }

            $port = isset($matches[2]) ? (int)$matches[2] : 0;
            if (isset($matches[2]) && ($port < 1 || $port > 65535)) {
                return '';
            }

            return '[' . $matches[1] . ']' . (isset($matches[2]) ? ':' . (string)$port : '');
        }

        $host = $value;
        $port = null;
        if (preg_match('/^(.+):([0-9]{1,5})$/', $value, $matches) === 1) {
            $host = (string)$matches[1];
            $port = (int)$matches[2];
            if ($port < 1 || $port > 65535) {
                return '';
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false && !$this->isValidHostname($host)) {
            return '';
        }

        return $host . ($port !== null ? ':' . (string)$port : '');
    }

    private function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function normaliseScheme(string $value): string
    {
        $scheme = strtolower(trim($value, " \t\n\r\0\x0B\"'"));

        return in_array($scheme, ['http', 'https'], true) ? $scheme : '';
    }

    private function normaliseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalised = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $normalised[] = $item;
            }
        }

        return array_values(array_unique($normalised));
    }

    private function normaliseIp(string $value): string
    {
        $value = trim($value);
        $value = trim($value, '"[]');

        if (str_contains($value, ':') && preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d+$/', $value) === 1) {
            $value = (string)preg_replace('/:\d+$/', '', $value);
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? '' : mb_substr($value, 0, 45);
    }
}
