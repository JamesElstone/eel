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
