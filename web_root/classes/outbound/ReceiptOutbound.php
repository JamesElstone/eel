<?php
declare(strict_types=1);

final class ReceiptOutbound
{
    public static function downloadToFile(string $url, $sink, int $timeoutSeconds = 10, int $maxBytes = 10485760): array
    {
        if (!is_resource($sink)) {
            throw new RuntimeException('Receipt downloads require a writable stream resource.');
        }

        return OutboundHelper::request([
            'transport' => 'http',
            'method' => 'GET',
            'url' => $url,
            'auth' => 'none',
            'timeout_seconds' => max(1, $timeoutSeconds),
            'follow_location' => true,
            'max_redirects' => 5,
            'protocols' => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            'redir_protocols' => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            'user_agent' => 'EEL Accounts Receipt Downloader/1.0',
            'ssl_verify_peer' => true,
            'ssl_verify_host' => 2,
            'fail_on_error' => false,
            'capture_body' => false,
            'sink' => $sink,
            'max_response_bytes' => max(1024, $maxBytes),
        ]);
    }
}
