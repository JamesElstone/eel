<?php
declare(strict_types=1);

final class CompaniesHouseService
{
    /** @var callable */
    private $outboundRequest;

    public function __construct(
        private readonly string $environment = 'TEST',
        private readonly int $timeoutSeconds = 20,
        ?callable $outboundRequest = null,
    ) {
        $this->outboundRequest = $outboundRequest ?? fn(array $request): array => CompaniesHouseOutbound::request($request, $this->environment);
    }

    public function request(string $path, array $query = []): array {
        $response = ($this->outboundRequest)([
            'provider' => 'COMPANIESHOUSE',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => HelperFramework::normaliseEnvironmentMode($this->environment),
            'method' => 'GET',
            'path' => $path,
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'auth' => 'basic_api_key',
            'timeout_seconds' => max(1, $this->timeoutSeconds),
        ]);

        $status = (int)($response['status_code'] ?? 0);
        $data = json_decode((string)($response['body'] ?? ''), true);

        if ($status >= 400 && $status !== 404) {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : 'HTTP ' . $status;

            if (is_array($data) && isset($data['message'])) {
                $message = (string)$data['message'];
            }

            throw new RuntimeException('Companies House request failed: ' . $message);
        }

        return [
            'status' => $status,
            'data' => is_array($data) ? $data : [],
            'url' => (string)($response['url'] ?? ''),
            'raw' => $response,
        ];
    }

    public function fetchProfileByNumber(string $companyNumber): array {
        $lookup = $this->request('/company/' . trim($companyNumber));

        return $lookup['status'] === 200 ? $lookup['data'] : [];
    }

    public static function storedAddressLines(array $settings): array
    {
        $lines = [];

        foreach (
            [
                'registered_office_care_of',
                'registered_office_po_box',
                'registered_office_premises',
                'registered_office_address_line_1',
                'registered_office_address_line_2',
                'registered_office_locality',
                'registered_office_region',
                'registered_office_postal_code',
                'registered_office_country',
            ] as $field
        ) {
            $value = trim((string)($settings[$field] ?? ''));

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return array_values(array_unique($lines));
    }
}

