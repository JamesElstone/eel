<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vat_readinessCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'vat_readiness';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $settings = (array)(($context['page'] ?? [])['settings'] ?? []);
        $vatRegistrationService = new \eel_accounts\Service\VatRegistrationService();

        return '<div class="list" data-vat-readiness-list">' . $this->renderVatReadinessList($settings, $vatRegistrationService) . '</div>';
    }

    private function renderVatReadinessList(array $settings, \eel_accounts\Service\VatRegistrationService $service): string
    {
        $items = [];
        $isVatRegistered = !empty($settings['is_vat_registered']);
        $countryCode = trim((string)($settings['vat_country_code'] ?? ''));
        $vatNumber = trim((string)($settings['vat_number'] ?? ''));
        $validationStatus = trim((string)($settings['vat_validation_status'] ?? 'unverified'));
        $validationSource = trim((string)($settings['vat_validation_source'] ?? ''));
        $lastError = trim((string)($settings['vat_last_error'] ?? ''));
        $warnings = trim((string)($settings['pending_vat_mismatch_warnings'] ?? ''));

        $items[] = [
            'title' => 'Registration status',
            'ok' => true,
            'detail' => $isVatRegistered ? 'The company is marked as VAT registered.' : 'The company is not VAT registered, so VAT accounting is not required.',
        ];
        $items[] = [
            'title' => 'VAT number captured',
            'ok' => !$isVatRegistered || ($countryCode !== '' && $vatNumber !== ''),
            'detail' => !$isVatRegistered ? 'Not required while VAT registration is turned off.' : (($countryCode !== '' && $vatNumber !== '') ? 'Country/prefix and VAT number are present.' : 'A VAT country/prefix and VAT registration number are still needed.'),
        ];
        $items[] = [
            'title' => 'Validation status',
            'ok' => !$isVatRegistered || $service->companyCanUseVatAccounting($settings),
            'detail' => !$isVatRegistered
                ? 'Validation is not required while VAT registration is turned off.'
                : $this->validationStatusDetail($validationStatus, $validationSource, $lastError),
        ];
        $items[] = [
            'title' => 'Mismatch review',
            'ok' => $warnings === '',
            'detail' => $warnings === '' ? 'No HMRC vs Companies House mismatch warnings are waiting for review.' : $warnings,
        ];

        $html = '';
        foreach ($items as $item) {
            $html .= '<div class="list-item">
                <strong>' . HelperFramework::escape($item['title']) . '</strong>
                <span class="status-indicator"><span class="status-square ' . ($item['ok'] ? 'ok' : 'bad') . '"></span>' . ($item['ok'] ? 'Ready' : 'Needs attention') . '</span>
                <span>' . HelperFramework::escape($item['detail']) . '</span>
            </div>';
        }

        return $html;
    }

    private function validationStatusDetail(string $status, string $source, string $lastError): string
    {
        $sourceLabel = $source !== '' ? strtoupper($source) : 'The VAT validation service';

        return match ($status) {
            'valid' => $sourceLabel . ' confirmed the VAT number and returned matching company details.',
            'invalid' => $sourceLabel . ' returned this VAT number as invalid. Check the country/prefix and VAT number, then run Check VAT Number again.',
            'error' => $lastError !== ''
                ? 'VAT validation could not be completed: ' . $lastError
                : 'VAT validation could not be completed. Check the VAT details and try again.',
            'mismatch_pending' => 'The VAT number is valid, but the returned company details do not exactly match this company. Review the mismatch before using VAT accounting.',
            'mismatch_override' => 'The VAT mismatch has been accepted, so VAT accounting can be used for this company.',
            'unverified', '' => 'VAT details have not been checked yet. Use Check VAT Number before saving the configuration.',
            default => 'Current VAT validation status: ' . str_replace('_', ' ', $status) . '.',
        };
    }
}
