<?php
declare(strict_types=1);

namespace eel_accounts\Client;

use eel_accounts\Contract\CharityRegistryClientInterface;

final class CharityCommissionRegistryClient implements CharityRegistryClientInterface
{
    /** @var callable */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? static fn(array $request): array => \eel_accounts\Outbound\CharityRegistryOutbound::charityCommission($request);
    }

    public function lookup(string $registrationNumber): array
    {
        $number = preg_replace('/\D+/', '', $registrationNumber) ?? '';
        if ($number === '') {
            return $this->failure('Enter a valid Charity Commission registration number.');
        }

        return $this->request('/register/api/charityRegNumber/' . rawurlencode($number) . '/0', $number);
    }

    private function request(string $path, string $number): array
    {
        try {
            $response = ($this->transport)([
                'base_url' => 'https://api.charitycommission.gov.uk',
                'path' => $path,
            ]);
            $status = (int)($response['status_code'] ?? 0);
            $body = (string)($response['body'] ?? '');
            if ($status !== 200) {
                return $this->failure($status === 404 ? 'No registered charity was found.' : 'The Charity Commission lookup failed.');
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return $this->failure('The Charity Commission returned an invalid response.');
            }
            $rows = array_is_list($decoded) ? $decoded : (array)($decoded['charities'] ?? $decoded['results'] ?? [$decoded]);
            $records = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['charity_name'] ?? $row['charityName'] ?? $row['organisation_name'] ?? $row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $suffix = trim((string)($row['group_subsid_suffix'] ?? $row['linked_charity_number'] ?? $row['suffix'] ?? '0'));
                $removed = self::date($row['date_of_removal'] ?? $row['removed_on'] ?? null);
                $records[] = [
                    'authority' => 'cc_ew', 'registration_number' => $number, 'entity_suffix' => $suffix,
                    'registered_name' => $name,
                    'registry_status' => $removed === null ? 'registered' : 'removed',
                    'registered_on' => self::date($row['date_of_registration'] ?? $row['registered_on'] ?? null),
                    'removed_on' => $removed,
                    'source_url' => 'https://register-of-charities.charitycommission.gov.uk/charity-search/-/charity-details/' . rawurlencode($number),
                ];
            }
            return $records === [] ? $this->failure('The Charity Commission response contained no registered entity.') : [
                'success' => true, 'records' => $records, 'errors' => [], 'response_sha256' => hash('sha256', $body),
            ];
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        try { return (new \DateTimeImmutable($value))->format('Y-m-d'); } catch (\Throwable) { return null; }
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'records' => [], 'errors' => [$message], 'response_sha256' => ''];
    }
}
