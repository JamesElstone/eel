<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SmsService
{
    public function sendInvite(
        string $telephoneNumber,
        string $link,
        string $expiresAt,
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): array {
        $telephoneNumber = trim($telephoneNumber);
        $link = trim($link);

        if ($telephoneNumber === '' || $link === '') {
            throw new RuntimeException('SMS invite is missing a telephone number or link.');
        }

        if (!$this->enabled()) {
            throw new RuntimeException('SMS invites are not enabled.');
        }

        $message = $this->inviteMessage($link, $expiresAt, $displayName, $recipientName, $displayEmail, $displayMobile);
        if ($this->developmentMode()) {
            return [
                'success' => true,
                'development_mode' => true,
                'message' => $message,
            ];
        }

        $url = $this->sendUrl($telephoneNumber);
        if ($url === '') {
            throw new RuntimeException('SMS API URL must include {telephone_number}.');
        }

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
        ];
        $authHeader = $this->authHeader();
        $authToken = trim((string)AppConfigurationStore::get('sms.auth_token', ''));
        if ($authHeader !== '' && $authToken !== '') {
            $headers[$authHeader] = $authToken;
        }

        $response = \ApiHelperOutbound::request([
            'url' => $url,
            'method' => $this->method(),
            'headers' => $headers,
            'body' => $message,
            'timeout_seconds' => 10,
            'user_agent' => 'eelKit-SmsInvite/1.0',
            'max_response_bytes' => 8192,
        ]);

        $statusCode = (int)($response['status_code'] ?? 0);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('SMS API failed with HTTP status ' . $statusCode . '.');
        }
        $status = $this->responseStatus((string)($response['body'] ?? ''));
        if ($status !== '' && $status !== 'sent') {
            throw new RuntimeException('SMS Gateway returned status ' . $status . '.');
        }

        return [
            'success' => true,
            'status_code' => $statusCode,
        ];
    }

    public function inviteMessage(
        string $link,
        string $expiresAt = '',
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): string {
        $template = trim((string)AppConfigurationStore::get(
            'invitation.sms_template',
            'You have been invited to complete your account setup for {app_name}. Use this secure link: {link}'
        ));
        if ($template === '') {
            $template = 'You have been invited to complete your account setup for {app_name}. Use this secure link: {link}';
        }

        return strtr($template, [
            '{app_name}' => $this->appName(),
            '{display_name}' => trim($displayName),
            '{display_email}' => strtolower(trim($displayEmail)),
            '{display_mobile}' => trim($displayMobile),
            '{recipient_name}' => trim($recipientName),
            '{recipient}' => trim($recipientName),
            '{link}' => $link,
            '{expires_at}' => $expiresAt,
            '{sender}' => trim((string)AppConfigurationStore::get('sms.sender_id', '')),
        ]);
    }

    private function enabled(): bool
    {
        return (bool)AppConfigurationStore::get('sms.enabled', false);
    }

    private function developmentMode(): bool
    {
        return (bool)AppConfigurationStore::get('sms.development_mode', true);
    }

    private function apiUrl(): string
    {
        return trim((string)AppConfigurationStore::get('sms.api_url', ''));
    }

    private function sendUrl(string $telephoneNumber): string
    {
        $apiUrl = $this->apiUrl();
        if ($apiUrl === '') {
            return '';
        }

        $encodedTelephoneNumber = rawurlencode(trim($telephoneNumber));
        if (str_contains($apiUrl, '{telephone_number}')) {
            return str_replace('{telephone_number}', $encodedTelephoneNumber, $apiUrl);
        }

        return '';
    }

    private function authHeader(): string
    {
        return trim((string)AppConfigurationStore::get('sms.auth_header', 'X-SMS-Gateway-Token'));
    }

    private function responseStatus(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        return strtolower(trim((string)($decoded['status'] ?? '')));
    }

    private function method(): string
    {
        $method = strtoupper(trim((string)AppConfigurationStore::get('sms.method', 'POST')));

        return in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $method : 'POST';
    }

    private function appName(): string
    {
        $appName = trim((string)AppConfigurationStore::get('app_name', 'eelKit Framework'));

        return $appName !== '' ? $appName : 'eelKit Framework';
    }
}
