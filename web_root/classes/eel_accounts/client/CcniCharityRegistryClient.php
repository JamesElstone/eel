<?php
declare(strict_types=1);

namespace eel_accounts\Client;

use eel_accounts\Contract\CharityRegistryClientInterface;

final class CcniCharityRegistryClient implements CharityRegistryClientInterface
{
    /** @var callable */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? static fn(array $request): array => \eel_accounts\Outbound\CharityRegistryOutbound::ccni($request);
    }

    public function lookup(string $registrationNumber): array
    {
        $number = strtoupper(preg_replace('/\s+/', '', trim($registrationNumber)) ?? '');
        if (preg_match('/^NIC\d{6}$/', $number) !== 1) {
            return $this->failure('CCNI numbers must use the format NIC123456.');
        }
        $digits = substr($number, 3);
        $url = 'https://www.charitycommissionni.org.uk/charity-search/charity-details-page/?regId=' . rawurlencode($digits) . '&subId=0';
        try {
            $response = ($this->transport)(['url' => $url, 'follow_location' => false]);
            $body = (string)($response['body'] ?? '');
            if ((int)($response['status_code'] ?? 0) !== 200 || $body === '') {
                return $this->failure('The CCNI lookup failed.');
            }
            $withoutScripts = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
            $text = html_entity_decode(
                preg_replace('/<[^>]+>/', ' ', $withoutScripts) ?? $withoutScripts,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $text = preg_replace('/\s+/', ' ', $text) ?? '';
            if (stripos($text, $number) === false) {
                return $this->failure('The CCNI page did not confirm the requested registration number.');
            }
            $name = self::capture($text, '/(?:Charity name|Registered name)\s*:?\s*(.+?)(?=\s+(?:Charity number|Registration number|Status)\s*:)/i');
            $status = self::capture($text, '/Status\s*:?\s*([A-Za-z ]+?)(?=\s+(?:Date registered|Registration date|Charity name|Contact|$))/i');
            if ($name === '' || $status === '') {
                return $this->failure('The CCNI page layout could not be verified safely.');
            }
            $record = [
                'authority' => 'ccni', 'registration_number' => $number, 'entity_suffix' => '0',
                'registered_name' => $name, 'registry_status' => $status,
                'registered_on' => self::capturedDate($text, '/(?:Date registered|Registration date)\s*:?\s*([^ ]+(?:\s+[^ ]+){0,2})/i'),
                'removed_on' => self::capturedDate($text, '/(?:Date removed|Removal date)\s*:?\s*([^ ]+(?:\s+[^ ]+){0,2})/i'),
                'source_url' => $url,
            ];
            return ['success' => true, 'records' => [$record], 'errors' => [], 'response_sha256' => hash('sha256', $body)];
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private static function capture(string $text, string $pattern): string
    {
        return preg_match($pattern, $text, $matches) === 1 ? trim((string)$matches[1]) : '';
    }

    private static function capturedDate(string $text, string $pattern): ?string
    {
        $value = self::capture($text, $pattern); if ($value === '') return null;
        try { return (new \DateTimeImmutable($value))->format('Y-m-d'); } catch (\Throwable) { return null; }
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'records' => [], 'errors' => [$message], 'response_sha256' => ''];
    }
}
