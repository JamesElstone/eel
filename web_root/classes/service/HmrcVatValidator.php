<?php
declare(strict_types=1);

final class HmrcVatValidator implements VatValidationServiceInterface
{
    private readonly HmrcVatApiClient $apiClient;

    public function __construct(
        private readonly array $config,
        ?callable $outboundRequest = null,
    ) {
        $this->apiClient = new HmrcVatApiClient($config, $outboundRequest);
    }

    public function supports(string $countryCode): bool {
        return strtoupper(trim($countryCode)) === 'GB';
    }

    public function validate(string $countryCode, string $vatNumber): VatValidationResult {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber = $this->apiClient->normaliseVatNumber($vatNumber);

        if ($countryCode !== 'GB' || $vatNumber === '') {
            return VatValidationResult::error('hmrc', 'A GB VAT registration number is required.');
        }

        try {
            $response = $this->apiClient->lookupVatNumber($vatNumber);
        } catch (Throwable $e) {
            return VatValidationResult::error('hmrc', 'Validation service unavailable: ' . $e->getMessage());
        }

        if (($response['status_code'] ?? 0) >= 500) {
            return VatValidationResult::error('hmrc', 'Validation service unavailable.');
        }

        $payload = json_decode((string)($response['body'] ?? ''), true);

        if (!is_array($payload)) {
            return VatValidationResult::error('hmrc', 'Unexpected HMRC validation response.');
        }

        $target = is_array($payload['target'] ?? null) ? $payload['target'] : $payload;
        $name = trim((string)($target['name'] ?? $target['traderName'] ?? '')) ?: null;
        $address = $this->normaliseAddress($target['address'] ?? $target['traderAddress'] ?? null);

        if (is_bool($payload['valid'] ?? null)) {
            return ($payload['valid']
                ? VatValidationResult::valid('hmrc', $name, $address, ['payload' => $payload])
                : VatValidationResult::invalid('hmrc', $name, $address, ['payload' => $payload]));
        }

        if (is_array($target) && array_key_exists('vatNumber', $target) && (int)($response['status_code'] ?? 0) === 200) {
            return VatValidationResult::valid('hmrc', $name, $address, ['payload' => $payload]);
        }

        if (($response['status_code'] ?? 0) === 404) {
            return VatValidationResult::invalid('hmrc', $name, $address, ['payload' => $payload]);
        }

        if (($response['status_code'] ?? 0) === 401 || ($response['status_code'] ?? 0) === 403) {
            return VatValidationResult::error('hmrc', 'Invalid HMRC credentials or access token.', ['payload' => $payload]);
        }

        $message = trim((string)($payload['message'] ?? $payload['code'] ?? 'HMRC validation failed.'));

        return VatValidationResult::error('hmrc', $message, ['payload' => $payload]);
    }

    private function normaliseAddress(mixed $address): ?string {
        if (is_string($address)) {
            $value = trim($address);

            return $value !== '' ? $value : null;
        }

        if (!is_array($address)) {
            return null;
        }

        $lines = [];

        foreach (['line1', 'line2', 'line3', 'line4', 'line5', 'postCode', 'postcode'] as $field) {
            $value = trim((string)($address[$field] ?? ''));

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines !== [] ? implode(PHP_EOL, array_values(array_unique($lines))) : null;
    }
}
