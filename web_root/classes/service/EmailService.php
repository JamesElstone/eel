<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class EmailService
{
    public function __construct(
        private readonly mixed $connector = null,
    ) {
    }

    public function sendInvite(
        string $toAddress,
        string $link,
        string $expiresAt,
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): array {
        $toAddress = strtolower(trim($toAddress));
        $link = trim($link);

        if ($toAddress === '' || !filter_var($toAddress, FILTER_VALIDATE_EMAIL) || $link === '') {
            throw new RuntimeException('Email invite is missing a valid recipient or link.');
        }

        if (!$this->enabled()) {
            throw new RuntimeException('SMTP invites are not enabled.');
        }

        ['subject' => $subject, 'body' => $body] = $this->inviteEmailContent(
            $link,
            $expiresAt,
            $displayName,
            $recipientName,
            $displayEmail,
            $displayMobile
        );

        if ($this->developmentMode()) {
            return [
                'success' => true,
                'development_mode' => true,
                'subject' => $subject,
                'body' => $body,
            ];
        }

        if ($this->transport() === 'mail') {
            return $this->sendWithMail($toAddress, $subject, $body);
        }

        return $this->sendWithSmtp($toAddress, $subject, $body);
    }

    public function cramMd5Response(string $challenge, string $username, string $password): string
    {
        $decoded = base64_decode($challenge, true);
        if (!is_string($decoded)) {
            $decoded = '';
        }

        return base64_encode($username . ' ' . hash_hmac('md5', $decoded, $password));
    }

    public function testSmtpConnection(): array
    {
        if ($this->transport() !== 'smtp') {
            throw new RuntimeException('SMTP connection testing requires SMTP transport.');
        }

        $host = trim((string)AppConfigurationStore::get('smtp.host', ''));
        $port = max(1, min(65535, (int)AppConfigurationStore::get('smtp.port', 25)));
        if ($host === '') {
            throw new RuntimeException('SMTP host is required.');
        }

        $encryption = strtolower(trim((string)AppConfigurationStore::get('smtp.encryption', 'starttls')));
        $target = ($encryption === 'ssl_tls' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = $this->openSocket($target, $host, $port);

        try {
            $this->expect($socket, [220], 'SMTP greeting');
            $this->command($socket, 'EHLO ' . $this->localName(), [250], 'SMTP EHLO');

            if ($encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220], 'SMTP STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed after the server accepted STARTTLS.');
                }
                $this->command($socket, 'EHLO ' . $this->localName(), [250], 'SMTP EHLO after STARTTLS');
            }

            $this->authenticate($socket);
            $fromAddress = $this->fromAddress();
            $this->command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250], 'SMTP sender address check');
            $this->command($socket, 'RSET', [250], 'SMTP reset after sender check');
            $this->command($socket, 'QUIT', [221], 'SMTP quit');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        return [
            'success' => true,
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
        ];
    }

    public function sendTestEmailToFromAddress(): array
    {
        $toAddress = $this->fromAddress();
        $subject = $this->testEmailSubject();
        $body = $this->testEmailBody();

        if ($this->transport() === 'mail') {
            return $this->sendWithMail($toAddress, $subject, $body);
        }

        return $this->sendWithSmtp($toAddress, $subject, $body);
    }

    public function sendTemplateTestEmailToFromAddress(
        string $link,
        string $expiresAt,
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): array
    {
        return $this->sendTemplateTestEmail($this->fromAddress(), $link, $expiresAt, $displayName, $recipientName, $displayEmail, $displayMobile);
    }

    public function sendTemplateTestEmail(
        string $toAddress,
        string $link,
        string $expiresAt,
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): array
    {
        $toAddress = strtolower(trim($toAddress));
        $link = trim($link);
        if ($toAddress === '' || !filter_var($toAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP template test is missing a valid recipient email address.');
        }

        if ($link === '') {
            throw new RuntimeException('SMTP template test is missing a test link.');
        }

        ['subject' => $subject, 'body' => $body] = $this->inviteEmailContent(
            $link,
            $expiresAt,
            $displayName,
            $recipientName,
            $displayEmail,
            $displayMobile
        );

        if ($this->transport() === 'mail') {
            return $this->sendWithMail($toAddress, $subject, $body);
        }

        return $this->sendWithSmtp($toAddress, $subject, $body);
    }

    private function sendWithMail(string $toAddress, string $subject, string $body): array
    {
        $headers = [
            'From: ' . $this->formattedFrom(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (!mail($toAddress, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('PHP mail() did not accept the invite email.');
        }

        return ['success' => true, 'transport' => 'mail'];
    }

    private function sendWithSmtp(string $toAddress, string $subject, string $body): array
    {
        $host = trim((string)AppConfigurationStore::get('smtp.host', ''));
        $port = max(1, (int)AppConfigurationStore::get('smtp.port', 25));
        if ($host === '') {
            throw new RuntimeException('SMTP host is required.');
        }

        $encryption = strtolower(trim((string)AppConfigurationStore::get('smtp.encryption', 'starttls')));
        $target = ($encryption === 'ssl_tls' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = $this->openSocket($target, $host, $port);

        try {
            $this->expect($socket, [220], 'SMTP greeting');
            $this->command($socket, 'EHLO ' . $this->localName(), [250], 'SMTP EHLO');

            if ($encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220], 'SMTP STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed after the server accepted STARTTLS.');
                }
                $this->command($socket, 'EHLO ' . $this->localName(), [250], 'SMTP EHLO after STARTTLS');
            }

            $this->authenticate($socket);
            $fromAddress = $this->fromAddress();
            $this->command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250], 'SMTP sender address check');
            $this->command($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251], 'SMTP recipient or relay check');
            $this->command($socket, 'DATA', [354], 'SMTP DATA command');
            $this->write($socket, $this->messageData($toAddress, $subject, $body) . "\r\n.\r\n");
            $this->expect($socket, [250], 'SMTP message acceptance');
            $this->command($socket, 'QUIT', [221], 'SMTP quit');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        return ['success' => true, 'transport' => 'smtp'];
    }

    private function authenticate($socket): void
    {
        $authMode = strtolower(trim((string)AppConfigurationStore::get('smtp.auth_mode', 'login')));
        $username = trim((string)AppConfigurationStore::get('smtp.username', ''));
        $password = trim((string)AppConfigurationStore::get('smtp.password', ''));

        if ($authMode === 'none' || ($username === '' && $password === '')) {
            return;
        }

        if ($username === '' || $password === '') {
            throw new RuntimeException('SMTP username and password are required for authentication.');
        }

        if ($authMode === 'plain') {
            $this->command($socket, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), [235], 'SMTP AUTH PLAIN authentication');
            return;
        }

        if ($authMode === 'cram_md5') {
            $response = $this->command($socket, 'AUTH CRAM-MD5', [334], 'SMTP AUTH CRAM-MD5 challenge');
            $challenge = trim(substr($response, 4));
            $this->command($socket, $this->cramMd5Response($challenge, $username, $password), [235], 'SMTP AUTH CRAM-MD5 authentication');
            return;
        }

        $this->command($socket, 'AUTH LOGIN', [334], 'SMTP AUTH LOGIN username prompt');
        $this->command($socket, base64_encode($username), [334], 'SMTP AUTH LOGIN password prompt');
        $this->command($socket, base64_encode($password), [235], 'SMTP AUTH LOGIN authentication');
    }

    private function messageData(string $toAddress, string $subject, string $body): string
    {
        $headers = [
            'From: ' . $this->formattedFrom(),
            'To: ' . $toAddress,
            'Subject: ' . $this->headerValue($subject),
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->smtpBody($body);
    }

    private function command($socket, string $command, array $expectedCodes, string $stage = 'SMTP command'): string
    {
        $this->write($socket, $command . "\r\n", $stage);

        return $this->expect($socket, $expectedCodes, $stage);
    }

    private function expect($socket, array $expectedCodes, string $stage = 'SMTP response'): string
    {
        $response = '';
        while (($line = fgets($socket, 2048)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            if ($response === '') {
                throw new RuntimeException($stage . ' failed: no response from SMTP server.');
            }

            throw new RuntimeException(
                $stage . ' failed: expected SMTP response '
                . implode(' or ', array_map('strval', $expectedCodes))
                . ', got ' . $code
                . ' (' . $this->smtpResponseSummary($response) . ').'
            );
        }

        return $response;
    }

    private function write($socket, string $value, string $stage = 'SMTP write'): void
    {
        if (fwrite($socket, $value) === false) {
            throw new RuntimeException($stage . ' failed: could not write to SMTP server.');
        }
    }

    private function openSocket(string $target, string $host = '', int $port = 0)
    {
        $errno = 0;
        $errstr = '';
        if (is_callable($this->connector)) {
            $socket = ($this->connector)($target, 20);
        } else {
            $socket = @stream_socket_client($target, $errno, $errstr, 20);
        }

        if (!is_resource($socket)) {
            throw new RuntimeException($this->socketFailureMessage($host, $port, $errno, $errstr));
        }

        return $socket;
    }

    private function socketFailureMessage(string $host, int $port, int $errno, string $errstr): string
    {
        $host = $host !== '' ? $host : 'configured host';
        $portLabel = $port > 0 ? ' on port ' . $port : '';
        $detail = trim($errstr);

        if ($detail === '') {
            return 'Could not connect to SMTP host ' . $host . $portLabel . '.';
        }

        if (stripos($detail, 'getaddrinfo') !== false
            || stripos($detail, 'php_network_getaddresses') !== false
            || stripos($detail, 'Name or service not known') !== false
            || stripos($detail, 'No such host') !== false) {
            return 'SMTP host could not be resolved: ' . $host . '. DNS reported: ' . $detail . '.';
        }

        return 'Could not connect to SMTP host ' . $host . $portLabel . ': ' . $detail . ($errno > 0 ? ' (error ' . $errno . ')' : '') . '.';
    }

    private function smtpResponseSummary(string $response): string
    {
        $response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $response);
        $response = trim((string)$response);
        $response = preg_replace('/\s+/', ' ', $response);

        if (strlen((string)$response) > 240) {
            $response = substr((string)$response, 0, 237) . '...';
        }

        return (string)$response;
    }

    private function testEmailSubject(): string
    {
        return 'SMTP Test from ' . $this->appName();
    }

    private function testEmailBody(): string
    {
        return "This is a test email from " . $this->appName() . ".\n\n"
            . "Good news, your email settings are working. This message was sent using the saved SMTP configuration.\n\n"
            . "Sent at: " . date(DATE_RFC2822) . "\n";
    }

    private function inviteEmailContent(
        string $link,
        string $expiresAt,
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): array {
        return [
            'subject' => $this->renderTemplate(
                (string)AppConfigurationStore::get('invitation.email_subject_template', 'Complete your {app_name} account setup'),
                $link,
                $expiresAt,
                $displayName,
                $recipientName,
                $displayEmail,
                $displayMobile
            ),
            'body' => $this->renderTemplate(
                (string)AppConfigurationStore::get(
                    'invitation.email_body_template',
                    "You have been invited to complete your account setup for {app_name}.\n\nUse this secure link:\n\n{link}\n\nThis link will expire on {expires_at}."
                ),
                $link,
                $expiresAt,
                $displayName,
                $recipientName,
                $displayEmail,
                $displayMobile
            ),
        ];
    }

    private function renderTemplate(
        string $template,
        string $link,
        string $expiresAt,
        string $displayName = '',
        string $recipientName = '',
        string $displayEmail = '',
        string $displayMobile = ''
    ): string {
        $template = trim($template);
        if ($template === '') {
            $template = '{link}';
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
        ]);
    }

    private function smtpBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return str_replace("\n", "\r\n", $body);
    }

    private function enabled(): bool
    {
        return (bool)AppConfigurationStore::get('smtp.enabled', false);
    }

    private function developmentMode(): bool
    {
        return (bool)AppConfigurationStore::get('smtp.development_mode', true);
    }

    private function transport(): string
    {
        $transport = strtolower(trim((string)AppConfigurationStore::get('smtp.transport', 'smtp')));

        return $transport === 'mail' ? 'mail' : 'smtp';
    }

    private function formattedFrom(): string
    {
        $fromName = trim((string)AppConfigurationStore::get('smtp.from_name', $this->appName()));
        $fromAddress = $this->fromAddress();

        if ($fromName === '') {
            return $fromAddress;
        }

        return $this->headerValue($fromName) . ' <' . $fromAddress . '>';
    }

    private function fromAddress(): string
    {
        $fromAddress = strtolower(trim((string)AppConfigurationStore::get('smtp.from_address', '')));
        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP from address must be valid.');
        }

        return $fromAddress;
    }

    private function headerValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function localName(): string
    {
        $host = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));

        return $host !== '' ? $host : 'localhost';
    }

    private function appName(): string
    {
        $appName = trim((string)AppConfigurationStore::get('app_name', 'eelKit Framework'));

        return $appName !== '' ? $appName : 'eelKit Framework';
    }
}
