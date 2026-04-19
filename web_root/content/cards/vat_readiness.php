<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vat_readinessCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'vat_readiness';
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
        $settings = (array)(($context['page'] ?? [])['settings'] ?? []);
        $vatRegistrationService = new VatRegistrationService();

        return '<section class="eel-card-fragment" data-card="vat-readiness">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">VAT Readiness</h2>
                </div>
                <div class="card-body">
                    <div class="list" data-vat-readiness-list">'
                        . $this->renderVatReadinessList($settings, $vatRegistrationService) . '
                    </div>
                </div>
            </div>
        </section>';
    }

    private function renderVatReadinessList(array $settings, VatRegistrationService $service): string
    {
        $items = [];
        $isVatRegistered = !empty($settings['is_vat_registered']);
        $countryCode = trim((string)($settings['vat_country_code'] ?? ''));
        $vatNumber = trim((string)($settings['vat_number'] ?? ''));
        $validationStatus = trim((string)($settings['vat_validation_status'] ?? 'unverified'));
        $warnings = trim((string)($settings['pending_vat_mismatch_warnings'] ?? ''));

        $items[] = [
            'title' => 'Registration status',
            'ok' => $isVatRegistered,
            'detail' => $isVatRegistered ? 'The company is marked as VAT registered.' : 'The company is currently marked as not VAT registered.',
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
                : ($validationStatus !== '' ? 'Current VAT validation status: ' . str_replace('_', ' ', $validationStatus) . '.' : 'VAT details have not been validated yet.'),
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
}
