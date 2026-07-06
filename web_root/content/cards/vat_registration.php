<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vat_registrationCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'vat_registration';
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
        $hasValidSelectedCompany = (int)($context['company']['id'] ?? 0) > 0;
        $settings = (array)($context['company']['settings'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $vatService = new \eel_accounts\Service\VatRegistrationService();
        $validationStatus = trim((string)($settings['vat_validation_status'] ?? ''));
        $validatedHash = \eel_accounts\Service\VatRegistrationViewDataService::validationHash($vatService, $settings);

        if (!$hasValidSelectedCompany) {
            return '<div class="helper">Select or add a company first, and the VAT registration controls will appear here.</div>';
        }

        $countryOptions = '';
        foreach ($this->vatCountryOptions() as $countryCode => $countryLabel) {
            $countryOptions .= '<option value="' . HelperFramework::escape($countryCode) . '"' . ((string)($settings['vat_country_code'] ?? '') === $countryCode ? ' selected' : '') . '>' . HelperFramework::escape($countryLabel) . '</option>';
        }

        $resultsHtml = $this->renderVatResultFields($settings, $companyId);
        $mismatchWarnings = $this->vatMismatchWarnings($settings);
        $mismatchHtml = $mismatchWarnings !== []
            ? '<div data-vat-mismatch-panel>' . $this->renderVatMismatchPanel($mismatchWarnings) . '</div>'
            : '';
        $registeredValue = !empty($settings['is_vat_registered']) ? '1' : '0';

        return '
            <form method="post" data-ajax="true" data-vat-registration-form>
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="VatRegistration">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <div class="form-grid">
                <div class="form-row">
                    <label>Company</label>
                    <input class="input" value="' . HelperFramework::escape((string)($settings['company_name'] ?? '')) . '" readonly>
                </div>
                <div class="form-row">
                    <label>Company Registration Number (CRN)</label>
                    <input class="input" value="' . HelperFramework::escape((string)($settings['companies_house_number'] ?? '')) . '" readonly>
                </div>
                <div class="form-row full">
                    <label>VAT Registered</label>
                    <div class="segmented-control" data-vat-registered-toggle>
                        <label class="segmented-option">
                            <input type="radio" name="is_vat_registered" value="1" data-vat-registered-control data-vat-initial-value="' . HelperFramework::escape($registeredValue) . '"' . (!empty($settings['is_vat_registered']) ? ' checked' : '') . '>
                            <span>Yes</span>
                        </label>
                        <label class="segmented-option">
                            <input type="radio" name="is_vat_registered" value="0" data-vat-registered-control data-vat-initial-value="' . HelperFramework::escape($registeredValue) . '"' . (empty($settings['is_vat_registered']) ? ' checked' : '') . '>
                            <span>No</span>
                        </label>
                    </div>
                </div>
                <div class="form-row full vat-panel' . (empty($settings['is_vat_registered']) ? ' is-hidden' : '') . '" data-vat-fields>
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="vat_country_code">VAT Country/Prefix</label>
                            <select class="select" id="vat_country_code" name="vat_country_code" data-vat-country-code data-vat-initial-value="' . HelperFramework::escape((string)($settings['vat_country_code'] ?? '')) . '">
                                <option value="">Select country/prefix</option>' . $countryOptions . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="vat_number">VAT Registration Number</label>
                            <input class="input" id="vat_number" name="vat_number" value="' . HelperFramework::escape((string)($settings['vat_number'] ?? '')) . '" placeholder="Enter VAT Registration Number" autocomplete="off" data-vat-number data-vat-initial-value="' . HelperFramework::escape((string)($settings['vat_number'] ?? '')) . '">
                        </div>
                        <div class="form-row full">
                            <div class="vat-actions" data-vat-actions">' . $this->renderVatActionButtons($settings) . '</div>
                        </div>
                        <div class="form-row full" data-vat-inline-feedback>' . $this->renderVatInlineFeedback([]) . '</div>
                        <div class="form-row full" data-vat-panel-stack">'
                            . $mismatchHtml
                            . (!empty($settings['is_vat_registered']) && trim($resultsHtml) !== '' ? '<div data-vat-results-panel>' . $resultsHtml . '</div>' : '') . '
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <button class="button primary" id="save_vat_button" type="submit" name="intent" value="save_vat" disabled data-vat-save-button data-vat-validation-status="' . HelperFramework::escape($validationStatus) . '" data-vat-validated-hash="' . HelperFramework::escape($validatedHash) . '">Save VAT Configuration</button>
            </div>
            </form>
        ';
    }

    private function vatCountryOptions(): array
    {
        return [
            'GB' => 'GB - United Kingdom',
            'XI' => 'XI - Northern Ireland',
            'IE' => 'IE - Ireland',
            'DE' => 'DE - Germany',
            'FR' => 'FR - France',
            'ES' => 'ES - Spain',
            'IT' => 'IT - Italy',
            'NL' => 'NL - Netherlands',
            'BE' => 'BE - Belgium',
            'PL' => 'PL - Poland',
        ];
    }

    private function renderVatActionButtons(array $settings): string
    {
        $countryCode = trim((string)($settings['vat_country_code'] ?? ''));
        $vatNumber = trim((string)($settings['vat_number'] ?? ''));
        $canCheck = $countryCode !== '' && $vatNumber !== '';

        return '<button class="button" type="submit" name="intent" value="validate_vat" data-vat-check-button' . ($canCheck ? '' : ' disabled') . '>Check VAT Number</button>
            <button class="button" type="submit" name="intent" value="clear_vat_validation">Reset Validation</button>';
    }

    private function renderVatInlineFeedback(array $messages): string
    {
        if ($messages === []) {
            return '';
        }

        $html = '';
        foreach ($messages as $message) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$message) . '</div>';
        }
        return $html;
    }

    private function renderVatMismatchPanel(array $warnings): string
    {
        return '<div class="helper">' . HelperFramework::escape(implode(' ', $warnings)) . '</div>
                <div>
                    <button class="button" type="submit" name="intent" value="accept_vat_mismatch">Accept Mismatch</button>
                </div>
            </div>';
    }

    private function renderVatResultFields(array $settings, int $companyId): string
    {
        $status = trim((string)($settings['vat_validation_status'] ?? ''));
        if ($status === '') {
            return '';
        }

        $rows = [
            ['Validation status', HelperFramework::labelFromKey($status, '_')],
            ['Validated at', $this->displayDateTime((string)($settings['vat_validated_at'] ?? ''))],
            ['Validation source', (string)($settings['vat_validation_source'] ?? '')],
            ['Business name', (string)($settings['vat_validation_name'] ?? '')],
            ['Address', $this->validationAddress($settings)],
            ['Last error', (string)($settings['vat_last_error'] ?? '')],
        ];

        $html = '<div class="list">';
        foreach ($rows as [$label, $value]) {
            if (trim($value) === '') {
                continue;
            }
            $html .= '<div class="list-item"><strong>' . HelperFramework::escape($label) . '</strong><span>' . HelperFramework::escape($value) . '</span></div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function displayDateTime(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDateTime($value);
    }

    private function vatMismatchWarnings(array $settings): array
    {
        if ((string)($settings['vat_validation_status'] ?? '') !== 'mismatch_pending') {
            return [];
        }

        $result = new \eel_accounts\Service\VatValidationResultService(
            'valid',
            (string)($settings['vat_validation_source'] ?? ''),
            (string)($settings['vat_validation_name'] ?? ''),
            $this->validationAddress($settings)
        );

        return (new \eel_accounts\Service\VatRegistrationService())->compareHmrcAndCompaniesHouse($settings, $result);
    }

    private function validationAddress(array $settings): string
    {
        return trim(implode(' ', array_filter([
            trim((string)($settings['vat_validation_address_line1'] ?? '')),
            trim((string)($settings['vat_validation_postcode'] ?? '')),
            trim((string)($settings['vat_validation_country_code'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));
    }
}
