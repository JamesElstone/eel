<?php
declare(strict_types=1);

final class VatRegistrationService
{
    /** @var VatValidationServiceInterface[] */
    private array $validators;

    public function __construct(VatValidationServiceInterface ...$validators) {
        $this->validators = $validators;
    }

    public function resetValidationState(array $settings): array {
        $settings['vat_validation_status'] = 'unverified';
        $settings['vat_validated_at'] = '';
        $settings['vat_validation_source'] = '';
        $settings['vat_validation_name'] = '';
        $settings['vat_validation_address'] = '';
        $settings['vat_last_error'] = '';

        return $settings;
    }

    public function applyManualSaveRules(array $settings, array $previousSettings): array {
        $currentCountry = strtoupper(trim((string)($settings['vat_country_code'] ?? '')));
        $previousCountry = strtoupper(trim((string)($previousSettings['vat_country_code'] ?? '')));
        $currentNumber = $this->normaliseVatNumber((string)($settings['vat_number'] ?? ''));
        $previousNumber = $this->normaliseVatNumber((string)($previousSettings['vat_number'] ?? ''));

        if (
            $currentCountry !== $previousCountry
            || $currentNumber !== $previousNumber
            || trim((string)($settings['vat_validation_status'] ?? '')) === ''
        ) {
            return $this->resetValidationState($settings);
        }

        return $settings;
    }

    public function validate(array $settings): VatValidationResult {
        $countryCode = strtoupper(trim((string)($settings['vat_country_code'] ?? '')));
        $vatNumber = $this->normaliseVatNumber((string)($settings['vat_number'] ?? ''));

        foreach ($this->validators as $validator) {
            if ($validator->supports($countryCode)) {
                return $validator->validate($countryCode, $vatNumber);
            }
        }

        return VatValidationResult::error('unknown', 'This VAT country/prefix is not supported yet.');
    }

    public function updateSettingsFromResult(array $settings, VatValidationResult $result): array {
        if ($result->status === 'valid') {
            $settings['vat_validation_status'] = 'valid';
            $settings['vat_validated_at'] = gmdate('Y-m-d H:i:s');
            $settings['vat_validation_source'] = $result->source;
            $settings['vat_validation_name'] = trim((string)$result->name);
            $settings['vat_validation_address'] = trim((string)$result->address);
            $settings['vat_last_error'] = '';

            return $settings;
        }

        if ($result->status === 'invalid') {
            $settings['vat_validation_status'] = 'invalid';
            $settings['vat_validated_at'] = gmdate('Y-m-d H:i:s');
            $settings['vat_validation_source'] = $result->source;
            $settings['vat_validation_name'] = '';
            $settings['vat_validation_address'] = '';
            $settings['vat_last_error'] = '';

            return $settings;
        }

        $settings['vat_validation_status'] = 'error';
        $settings['vat_last_error'] = trim((string)$result->error);
        $settings['vat_validation_source'] = $result->source;

        return $settings;
    }

    public function compareAgainstCompanyRecord(array $settings, VatValidationResult $result): array {
        $warnings = [];

        if ($result->source !== 'hmrc' || $result->status !== 'valid') {
            return $warnings;
        }

        $storedCompanyName = $this->normaliseForComparison((string)($settings['company_name'] ?? ''));
        $returnedName = $this->normaliseForComparison((string)($result->name ?? ''));

        if ($storedCompanyName !== '' && $returnedName !== '' && !$this->stringsMatchLoosely($storedCompanyName, $returnedName)) {
            $warnings[] = 'The HMRC business name does not match the Companies House company name on file.';
        }

        $storedAddress = $this->normaliseForComparison(implode(' ', CompaniesHouseHelper::storedAddressLines($settings)));
        $returnedAddress = $this->normaliseForComparison((string)($result->address ?? ''));

        if ($storedAddress !== '' && $returnedAddress !== '' && !$this->stringsMatchLoosely($storedAddress, $returnedAddress)) {
            $warnings[] = 'The HMRC address does not match the Companies House registered office address on file.';
        }

        return $warnings;
    }

    public function companyCanUseVatAccounting(array $settings): bool {
        return !empty($settings['is_vat_registered']) && in_array(
            (string)($settings['vat_validation_status'] ?? ''),
            ['valid', 'mismatch_override'],
            true
        );
    }

    public function normaliseVatNumber(string $vatNumber): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($vatNumber))) ?? '';
    }

    private function normaliseForComparison(string $value): string {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';
        $value = trim($value);

        if (in_array($value, ['UNITED KINGDOM', 'GREAT BRITAIN', 'GB', 'UK'], true)) {
            return 'GB';
        }

        return $value;
    }

    private function stringsMatchLoosely(string $left, string $right): bool {
        if ($left === $right) {
            return true;
        }

        return str_contains($left, $right) || str_contains($right, $left);
    }
}
