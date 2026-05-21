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
        $page = (array)($context['page'] ?? []);
        $hasValidSelectedCompany = (int)($context['company']['id'] ?? 0) > 0;
        $settings = (array)($page['settings'] ?? []);
        $companyId = (int)($page['selected_company_id'] ?? $context['company']['id'] ?? 0);

        if (!$hasValidSelectedCompany) {
            return '<div class="helper">Select or add a company first, and the VAT registration controls will appear here.</div>';
        }

        $countryOptions = '';
        foreach ($this->vatCountryOptions() as $countryCode => $countryLabel) {
            $countryOptions .= '<option value="' . HelperFramework::escape($countryCode) . '"' . ((string)($settings['vat_country_code'] ?? '') === $countryCode ? ' selected' : '') . '>' . HelperFramework::escape($countryLabel) . '</option>';
        }

        $resultsHtml = $this->renderVatResultFields($settings, $companyId);
        $mismatchHtml = trim((string)($settings['pending_vat_mismatch_warnings'] ?? '')) !== ''
            ? '<div data-vat-mismatch-panel>' . $this->renderVatMismatchPanel($settings) . '</div>'
            : '';

        return '
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
                            <input type="radio" name="is_vat_registered" value="1"' . (!empty($settings['is_vat_registered']) ? ' checked' : '') . '>
                            <span>Yes</span>
                        </label>
                        <label class="segmented-option">
                            <input type="radio" name="is_vat_registered" value="0"' . (empty($settings['is_vat_registered']) ? ' checked' : '') . '>
                            <span>No</span>
                        </label>
                    </div>
                </div>
                <div class="form-row full vat-panel' . (empty($settings['is_vat_registered']) ? ' is-hidden' : '') . '" data-vat-fields>
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="vat_country_code">VAT Country/Prefix</label>
                            <select class="select" id="vat_country_code" name="vat_country_code">
                                <option value="">Select country/prefix</option>' . $countryOptions . '
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="vat_number">VAT Registration Number</label>
                            <input class="input" id="vat_number" name="vat_number" value="' . HelperFramework::escape((string)($settings['vat_number'] ?? '')) . '" placeholder="Enter VAT Registration Number" autocomplete="off">
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
                <button class="button primary" type="submit" disabled data-submit-action="save_vat">Save VAT Configuration</button>
            </div>
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

        return '<button class="button" type="submit" data-submit-action="validate_vat"' . ($canCheck ? '' : ' disabled') . '>Check VAT Number</button>
            <button class="button" type="submit" data-submit-action="clear_vat_validation">Reset Validation</button>';
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

    private function renderVatMismatchPanel(array $settings): string
    {
        return '<div class="helper">' . HelperFramework::escape((string)($settings['pending_vat_mismatch_warnings'] ?? '')) . '</div>
                <div>
                    <button class="button" type="submit">Accept Mismatch</button>
                </div>
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
            ['Address', (string)($settings['vat_validation_address'] ?? '')],
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
}
