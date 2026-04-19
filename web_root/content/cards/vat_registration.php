<?php
declare(strict_types=1);

final class _vat_registrationCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'vat_registration';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
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
        $hasValidSelectedCompany = !empty($page['has_valid_selected_company']);
        $settings = (array)($page['settings'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);

        if (!$hasValidSelectedCompany) {
            return '<section class="eel-card-fragment" data-card="vat-registration">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">VAT Registration</h2>
                    </div>
                    <div class="card-body">
                        <div class="helper">Select or add a company first, and the VAT registration controls will appear here.</div>
                    </div>
                </div>
            </section>';
        }

        $countryOptions = '';
        foreach ($this->vatCountryOptions() as $countryCode => $countryLabel) {
            $countryOptions .= '<option value="' . HelperFramework::escape($countryCode) . '"' . ((string)($settings['vat_country_code'] ?? '') === $countryCode ? ' selected' : '') . '>' . HelperFramework::escape($countryLabel) . '</option>';
        }

        $resultsHtml = $this->renderVatResultFields($settings, $selectedCompanyId);
        $mismatchHtml = trim((string)($settings['pending_vat_mismatch_warnings'] ?? '')) !== ''
            ? '<div data-vat-mismatch-panel>' . $this->renderVatMismatchPanel($settings) . '</div>'
            : '';

        return '<section class="eel-card-fragment" data-card="vat-registration">
            <div class="card settings-section" data-section="vat-registration">
                <div class="card-header">
                    <h2 class="card-title">VAT Registration</h2>
                </div>
                <div class="card-body">
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
                    <div style="margin-top: 16px;">
                        <button class="button section-save-button" type="submit" disabled onclick="document.getElementById(\'settings_action_field\').value=\'save_vat\'" data-ajax-card-update="vat-registration,vat-readiness">Save VAT Configuration</button>
                    </div>
                </div>
            </div>
        </section>';
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

        return '<button class="button" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'validate_vat\'"' . ($canCheck ? '' : ' disabled') . ' data-ajax-card-update="vat-registration,vat-readiness">Check VAT Number</button>
            <button class="button" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'clear_vat_validation\'" data-ajax-card-update="vat-registration,vat-readiness">Reset Validation</button>';
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
        return '<div class="card" style="box-shadow: none; margin-top: 8px;">
            <div class="card-header"><h3 class="card-title">Mismatch Review</h3></div>
            <div class="card-body">
                <div class="helper" style="white-space: pre-wrap;">' . HelperFramework::escape((string)($settings['pending_vat_mismatch_warnings'] ?? '')) . '</div>
                <div style="margin-top: 12px;">
                    <button class="button" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'accept_vat_mismatch\'" data-ajax-card-update="vat-registration,vat-readiness">Accept Mismatch</button>
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
            ['Validated at', $this->displayDateTime((string)($settings['vat_validated_at'] ?? ''), $companyId, (string)($settings['date_format'] ?? ''))],
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

    private function displayDateTime(string $value, int $companyId, string $dateFormat): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDateTime($value, $companyId, $dateFormat);
    }
}
