<?php
declare(strict_types=1);

final class _tax_rates_ct600_rimCard extends CardBaseFramework
{
    public function key(): string { return 'tax_rates_ct600_rim'; }
    public function title(): string { return 'HMRC CT600 RIM artefacts'; }

    public function services(): array
    {
        return [[
            'key' => 'hmrc_ct_rim_catalogue',
            'service' => \eel_accounts\Service\HmrcCtRimCatalogueService::class,
            'method' => 'fetchPackages',
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['hmrc_ct_rim.refresh', 'hmrc_ct_rim.state', 'page.context'];
    }

    public function helper(array $context): string
    {
        return 'Refresh the HMRC CT600 RIM catalogue to check for new validation artefacts. New packages are downloaded and verified locally and are not committed to this repository.';
    }

    public function render(array $context): string
    {
        $packages = array_values(array_filter((array)($context['services']['hmrc_ct_rim_catalogue'] ?? []), 'is_array'));
        $sourceDates = array_values(array_filter(array_map(static fn(array $package): ?string => ($package['source_updated_at'] ?? null) ?: null, $packages)));
        $checkedDates = array_values(array_filter(array_map(static fn(array $package): ?string => ($package['checked_at'] ?? null) ?: null, $packages)));
        rsort($sourceDates);
        rsort($checkedDates);
        $sourceUpdated = $sourceDates[0] ?? 'Not checked';
        $checked = $checkedDates[0] ?? 'Not checked';
        $html = '<div class="settings-stack">'
            . $this->links()
            . '<div class="helper"><strong>Source updated:</strong> ' . HelperFramework::escape($sourceUpdated)
            . ' &nbsp; <strong>Checked:</strong> ' . HelperFramework::escape($checked) . '</div>'
            . '<div class="form-row-actions">' . $this->refreshForm() . '</div>';

        if ($packages === []) {
            return $html . '<div class="notice warning">No HMRC CT600 RIM metadata is stored yet. Refresh the catalogue to discover the current V2 and V3 artefacts.</div></div>';
        }

        $html .= '<div class="table-scroll"><table><thead><tr><th>Form</th><th>Artefact</th><th>Applicable from</th><th>HMRC status</th><th>State</th></tr></thead><tbody>';
        foreach ($packages as $package) {
            $state = (string)($package['package_state'] ?? 'not_downloaded');
            $status = (string)($package['hmrc_status'] ?? 'unknown');
            $html .= '<tr><td>' . HelperFramework::escape((string)($package['form_version'] ?? '')) . '</td>'
                . '<td>' . HelperFramework::escape((string)($package['artifact_version'] ?? '')) . '</td>'
                . '<td>' . HelperFramework::escape((string)($package['applicable_from'] ?? 'Not confirmed')) . '</td>'
                . '<td>' . HelperFramework::escape($status) . '</td>'
                . '<td><span class="badge ' . HelperFramework::escape($state === 'verified' ? 'success' : ($state === 'failed' ? 'danger' : 'info')) . '">' . HelperFramework::escape(str_replace('_', ' ', ucfirst($state))) . '</span></td></tr>';
        }
        return $html . '</tbody></table></div></div>';
    }

    private function links(): string
    {
        $links = [
            ['HMRC - CT600 RIM Artefacts', 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts'],
            ['HMRC - CT600 Version 3', 'https://www.gov.uk/government/news/new-version-of-company-tax-return-form-introduced'],
            ['HMRC - CT600 Version 2', 'https://www.gov.uk/government/publications/corporation-tax-company-tax-return-ct600-2008-version-2'],
            ['HMRC - Current CT600 Version 3 Forms', 'https://www.gov.uk/government/publications/corporation-tax-company-tax-return-ct600-2015-version-3'],
        ];
        $html = '<div class="form-row-actions">';
        foreach ($links as [$label, $url]) {
            $html .= '<a class="button button-inline" href="' . HelperFramework::escape($url) . '" target="_blank" rel="noopener noreferrer">' . HelperFramework::escape($label) . '</a> ';
        }
        return $html . '</div>';
    }

    private function refreshForm(): string
    {
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxRates"><input type="hidden" name="intent" value="hmrc_ct_rim_refresh"><button class="button primary" type="submit">Refresh HMRC CT600 RIM Catalogue</button></form>';
    }
}
