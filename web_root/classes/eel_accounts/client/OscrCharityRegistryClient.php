<?php
declare(strict_types=1);

namespace eel_accounts\Client;

use eel_accounts\Contract\CharityRegistryClientInterface;

final class OscrCharityRegistryClient implements CharityRegistryClientInterface
{
    /** @var callable */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? static fn(array $request): array => \eel_accounts\Outbound\CharityRegistryOutbound::oscr($request);
    }

    public function lookup(string $registrationNumber): array
    {
        $number = strtoupper(preg_replace('/\s+/', '', trim($registrationNumber)) ?? '');
        if (preg_match('/^SC\d{6}$/', $number) !== 1) {
            return $this->failure('OSCR numbers must use the format SC123456.');
        }
        try {
            $response = ($this->transport)([
                'base_url' => 'https://oscrapi.azurewebsites.net',
                'path' => '/api/all_charities',
                'query' => ['charitynumber' => $number],
            ]);
            $body = (string)($response['body'] ?? '');
            if ((int)($response['status_code'] ?? 0) !== 200) {
                return $this->failure('The OSCR lookup failed.');
            }
            $decoded = json_decode($body, true);
            $rows = is_array($decoded) ? (array)($decoded['data'] ?? $decoded['results'] ?? $decoded) : [];
            if (!array_is_list($rows)) $rows = [$rows];
            $records = [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $name = trim((string)($row['CharityName'] ?? $row['charity_name'] ?? $row['name'] ?? ''));
                if ($name === '') continue;
                $status = trim((string)($row['Status'] ?? $row['status'] ?? 'registered'));
                $records[] = [
                    'authority' => 'oscr', 'registration_number' => $number, 'entity_suffix' => '',
                    'registered_name' => $name, 'registry_status' => $status,
                    'registered_on' => self::date($row['RegisteredDate'] ?? $row['registered_date'] ?? null),
                    'removed_on' => self::date($row['CeasedDate'] ?? $row['removed_date'] ?? null),
                    'source_url' => 'https://www.oscr.org.uk/about-charities/search-the-register/charity-details?number=' . rawurlencode($number),
                ];
            }
            return $records === [] ? $this->failure('No registered Scottish charity was found.') : [
                'success' => true, 'records' => $records, 'errors' => [], 'response_sha256' => hash('sha256', $body),
            ];
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string)$value); if ($value === '') return null;
        try { return (new \DateTimeImmutable($value))->format('Y-m-d'); } catch (\Throwable) { return null; }
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'records' => [], 'errors' => [$message], 'response_sha256' => ''];
    }
}
