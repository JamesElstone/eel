<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HmrcFraudPreventionHeaderService
{
    public function buildHeadersFromRequest(RequestFramework $request, int $companyId, string $mode): array
    {
        $headers = AntiFraudService::instance()->buildGovHeaders();
        $headers['Accept'] = 'application/vnd.hmrc.1.0+json';
        $headers['X-EEL-Company-ID'] = (string)$companyId;
        $headers['X-EEL-HMRC-Mode'] = HelperFramework::normaliseEnvironmentMode($mode);

        return $headers;
    }

    public function redactHeadersForStorage(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $headerName = (string)$name;
            if (preg_match('/authorization|token|secret|client/i', $headerName) === 1 && strcasecmp($headerName, 'Gov-Client-Public-IP') !== 0) {
                $redacted[$headerName] = '[redacted]';
                continue;
            }
            $redacted[$headerName] = is_scalar($value) ? (string)$value : '[non-scalar]';
        }

        return $redacted;
    }

    public function validateLocally(array $headers): array
    {
        $required = [
            'Gov-Client-Connection-Method',
            'Gov-Client-Device-ID',
            'Gov-Client-Public-IP',
            'Gov-Client-Public-IP-Timestamp',
            'Gov-Vendor-Product-Name',
            'Gov-Vendor-Version',
        ];
        $errors = [];
        $warnings = [];
        foreach ($required as $header) {
            if (trim((string)($headers[$header] ?? '')) === '') {
                $errors[] = 'Missing fraud prevention header: ' . $header;
            }
        }
        if (trim((string)($headers['Gov-Client-Browser-JS-User-Agent'] ?? '')) === '') {
            $warnings[] = 'Browser user-agent fraud prevention header is not available.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
