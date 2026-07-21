<?php
declare(strict_types=1);

final class _tax_rates_ct600_rimCard extends CardBaseFramework
{
    private const AUTOMATIC_COMPUTATION_TAXONOMY = '2024';
    private const AUTOMATIC_COMPUTATION_ARTIFACT = 'V1.0.0';

    public function key(): string { return 'tax_rates_ct600_rim'; }
    public function title(): string { return 'HMRC CT filing artefacts'; }

    public function services(): array
    {
        return [
            [
                'key' => 'hmrc_ct_rim_catalogue',
                'service' => \eel_accounts\Service\HmrcCtRimCatalogueService::class,
                'method' => 'fetchPackages',
            ],
            [
                'key' => 'hmrc_ct_computation_catalogue',
                'service' => \eel_accounts\Service\HmrcCtComputationCatalogueService::class,
                'method' => 'fetchPackages',
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'hmrc_ct_rim.refresh',
            'hmrc_ct_rim.state',
            'hmrc.ct.computation.taxonomy',
            'ct.filing.mappings',
            'page.context',
        ];
    }

    public function helper(array $context): string
    {
        return 'Install and verify the HMRC artefacts used to build CT600 return XML and computation iXBRL. Official package files are stored locally and are not committed to this repository.';
    }

    public function render(array $context): string
    {
        $rimPackages = array_values(array_filter(
            (array)($context['services']['hmrc_ct_rim_catalogue'] ?? []),
            'is_array'
        ));
        $computationPackages = array_values(array_filter(
            (array)($context['services']['hmrc_ct_computation_catalogue'] ?? []),
            'is_array'
        ));

        return '<div class="settings-stack">'
            . '<div class="form-row-actions">' . $this->refreshForm() . '</div>'
            . $this->renderRimSection($rimPackages)
            . $this->renderComputationSection($computationPackages)
            . '</div>';
    }

    /** @param list<array<string, mixed>> $packages */
    private function renderRimSection(array $packages): string
    {
        $sourceDates = array_values(array_filter(array_map(
            static fn(array $package): ?string => ($package['source_updated_at'] ?? null) ?: null,
            $packages
        )));
        $checkedDates = array_values(array_filter(array_map(
            static fn(array $package): ?string => ($package['checked_at'] ?? null) ?: null,
            $packages
        )));
        rsort($sourceDates);
        rsort($checkedDates);
        $sourceUpdated = $sourceDates[0] ?? 'Not checked';
        $checked = $checkedDates[0] ?? 'Not checked';

        $html = '<section class="panel-soft settings-stack"><h3 class="card-title">CT600 return RIM schemas</h3>'
            . $this->rimLinks()
            . '<div class="helper"><strong>Source updated:</strong> ' . HelperFramework::escape($sourceUpdated)
            . ' &nbsp; <strong>Checked:</strong> ' . HelperFramework::escape($checked) . '</div>';

        if ($packages === []) {
            return $html . '<div class="notice warning">No HMRC CT600 RIM metadata is stored yet. Refresh the filing artefacts to discover and install the supported packages.</div></section>';
        }

        $html .= '<div class="table-scroll"><table><thead><tr><th>Form</th><th>Artefact</th><th>Applicable from</th><th>Primary XSD / inventory</th><th>Mapping compatibility</th><th>Checked</th><th>State</th><th>Action</th></tr></thead><tbody>';
        foreach ($packages as $package) {
            $state = (string)($package['package_state'] ?? 'not_downloaded');
            $html .= '<tr><td>' . HelperFramework::escape((string)($package['form_version'] ?? '')) . '</td>'
                . '<td>' . HelperFramework::escape((string)($package['artifact_version'] ?? '')) . '</td>'
                . '<td>' . HelperFramework::escape((string)($package['applicable_from'] ?? 'Not confirmed')) . '</td>'
                . '<td>' . HelperFramework::escape((string)($package['primary_xsd'] ?? 'Not catalogued')) . '<br><span class="helper">' . (int)($package['schema_count'] ?? $package['xsd_count'] ?? 0) . ' XSD; ' . (int)($package['component_count'] ?? 0) . ' components</span></td>'
                . '<td>' . (int)($package['compatible_profile_count'] ?? 0) . ' active compatible<br><span class="helper">' . (int)($package['unmapped_required_count'] ?? 0) . ' required unmapped</span></td>'
                . '<td>' . HelperFramework::escape((string)($package['checked_at'] ?? 'Not checked')) . '</td>'
                . '<td>' . $this->stateBadge($state) . '</td>'
                . '<td>' . $this->deleteForm((int)($package['id'] ?? 0), (string)($package['form_version'] ?? ''), (string)($package['artifact_version'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table></div></section>';
    }

    /** @param list<array<string, mixed>> $packages */
    private function renderComputationSection(array $packages): string
    {
        $html = '<section class="panel-soft settings-stack"><h3 class="card-title">Computation iXBRL taxonomies</h3>'
            . $this->computationLinks()
            . '<div class="helper">HMRC acceptance, official package availability and an active reviewed mapping are separate checks. Future identities remain read only until all required checks pass.</div>';

        if ($packages === []) {
            return $html . '<div class="notice warning">No supported computation-taxonomy package metadata is available.</div></section>';
        }

        $html .= '<div class="table-scroll"><table><thead><tr><th>Version</th><th>Applicability</th><th>State</th><th>Inventory</th><th>Mapping compatibility</th><th>Support</th><th>Action</th></tr></thead><tbody>';
        $mappingService = new \eel_accounts\Service\CtFilingMappingService();
        foreach ($packages as $package) {
            $taxonomy = trim((string)($package['taxonomy_version'] ?? ''));
            $artifact = strtoupper(trim((string)($package['artifact_version'] ?? '')));
            $state = trim((string)($package['package_state'] ?? 'not_downloaded'));
            $compatibleCount = (int)($package['compatible_profile_count'] ?? 0);
            $hasDownloadUrl = trim((string)($package['download_url'] ?? '')) !== '';
            $mappingReviewed = is_array($mappingService->reviewedTemplate(
                \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION,
                $taxonomy,
                $artifact
            ));
            $automaticInstall = $taxonomy === self::AUTOMATIC_COMPUTATION_TAXONOMY
                && $artifact === self::AUTOMATIC_COMPUTATION_ARTIFACT;

            if (!$mappingReviewed) {
                $support = '<span class="badge info">Unsupported</span>';
            } elseif ($state !== 'verified') {
                $support = $automaticInstall && $hasDownloadUrl
                    ? '<span class="badge warning">Install required</span>'
                    : '<span class="badge info">Awaiting official package</span>';
            } elseif ($compatibleCount <= 0) {
                $support = '<span class="badge warning">Mapping unavailable</span>';
            } else {
                $support = '<span class="badge success">Ready</span>';
            }

            $html .= '<tr><td>' . HelperFramework::escape($taxonomy !== '' ? $taxonomy : 'Unknown')
                . '<br><span class="helper">' . HelperFramework::escape($artifact !== '' ? $artifact : 'Unknown artefact') . '</span></td>'
                . '<td>' . HelperFramework::escape((string)($package['applicable_from'] ?? 'Not confirmed'))
                . ' to ' . HelperFramework::escape((string)(($package['applicable_to'] ?? null) ?: 'open')) . '</td>'
                . '<td>' . $this->stateBadge($state) . '</td>'
                . '<td>' . (int)($package['file_count'] ?? 0) . ' files; '
                . (int)($package['concept_count'] ?? 0) . ' concepts; '
                . (int)($package['dimension_count'] ?? 0) . ' dimensions</td>'
                . '<td>' . $compatibleCount . ' active compatible</td>'
                . '<td>' . $support . '</td>'
                . '<td>' . $this->computationDeleteForm((int)($package['id'] ?? 0), $taxonomy, $artifact) . '</td></tr>';
        }

        return $html . '</tbody></table></div></section>';
    }

    private function rimLinks(): string
    {
        return $this->renderLinks([
            ['CT Filing Mappings', '?page=ct_filing_mappings', false],
            ['HMRC - CT600 RIM Artefacts', 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts', true],
            ['HMRC - CT600 Version 3', 'https://www.gov.uk/government/news/new-version-of-company-tax-return-form-introduced', true],
            ['HMRC - CT600 Version 2', 'https://www.gov.uk/government/publications/corporation-tax-company-tax-return-ct600-2008-version-2', true],
            ['HMRC - Current CT600 Version 3 Forms', 'https://www.gov.uk/government/publications/corporation-tax-company-tax-return-ct600-2015-version-3', true],
        ]);
    }

    private function computationLinks(): string
    {
        return $this->renderLinks([
            ['HMRC - Computation iXBRL Specifications', \eel_accounts\Service\HmrcCtComputationCatalogueService::SOURCE_URL, true],
            ['HMRC - Accepted Taxonomies', 'https://www.gov.uk/government/publications/taxonomies-accepted-by-hm-revenue-and-customs/taxonomies-accepted-by-hmrc', true],
        ]);
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $links */
    private function renderLinks(array $links): string
    {
        $html = '<div class="form-row-actions">';
        foreach ($links as [$label, $url, $external]) {
            $html .= '<a class="button button-inline" href="' . HelperFramework::escape($url) . '"'
                . ($external ? ' target="_blank" rel="noopener noreferrer"' : '')
                . '>' . HelperFramework::escape($label) . '</a> ';
        }
        return $html . '</div>';
    }

    private function refreshForm(): string
    {
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxRates"><input type="hidden" name="intent" value="hmrc_ct_artifacts_refresh"><button class="button primary" type="submit">Refresh and install HMRC filing artefacts</button></form>';
    }

    private function stateBadge(string $state): string
    {
        $class = $state === 'verified' ? 'success' : ($state === 'failed' ? 'danger' : 'info');
        return '<span class="badge ' . $class . '">' . HelperFramework::escape(str_replace('_', ' ', ucfirst($state))) . '</span>';
    }

    private function deleteForm(int $packageId, string $formVersion, string $artifactVersion): string
    {
        if ($packageId <= 0) { return ''; }
        $label = trim($formVersion . ' ' . $artifactVersion);
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxRates"><input type="hidden" name="intent" value="hmrc_ct_rim_delete"><input type="hidden" name="package_id" value="' . $packageId . '"><button class="button button-inline danger" type="submit" data-chicken-check="true" data-chicken-title="Delete HMRC CT600 RIM package" data-chicken-message="Delete HMRC CT600 RIM ' . HelperFramework::escape($label) . '?<br><br>This removes the database row, ZIP file, extracted directory, and validation-file catalogue records. This cannot be undone." data-chicken-confirm-text="Delete" data-chicken-button-class="button danger">Delete</button></form>';
    }

    private function computationDeleteForm(int $packageId, string $taxonomyVersion, string $artifactVersion): string
    {
        if ($packageId <= 0) { return ''; }
        $label = trim($taxonomyVersion . ' ' . $artifactVersion);
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxRates"><input type="hidden" name="intent" value="hmrc_ct_computation_delete"><input type="hidden" name="package_id" value="' . $packageId . '"><button class="button button-inline danger" type="submit" data-chicken-check="true" data-chicken-title="Delete HMRC computation taxonomy package" data-chicken-message="Delete HMRC computation taxonomy ' . HelperFramework::escape($label) . '?<br><br>This removes the package database row, catalogue and mapping records, ZIP file, and extracted directory. This cannot be undone." data-chicken-confirm-text="Delete" data-chicken-button-class="button danger">Delete</button></form>';
    }
}
