<?php
declare(strict_types=1);

final class _tax_rates_ct_computation_taxonomyCard extends CardBaseFramework
{
    public function key(): string { return 'tax_rates_ct_computation_taxonomy'; }
    public function title(): string { return 'HMRC computation-taxonomy packages'; }
    public function services(): array { return [['key' => 'packages', 'service' => \eel_accounts\Service\HmrcCtComputationCatalogueService::class, 'method' => 'fetchPackages']]; }
    protected function additionalInvalidationFacts(): array { return ['hmrc.ct.computation.taxonomy', 'ct.filing.mappings', 'page.context']; }
    public function helper(array $context): string { return 'Register and catalogue each official computation-taxonomy revision independently from CT600 RIM packages. Activation remains a reviewed mapping-profile decision.'; }
    public function render(array $context): string
    {
        $packages = (array)($context['services']['packages'] ?? []);
        $html = '<div class="settings-stack"><div class="form-row-actions"><a class="button button-inline" href="' . HelperFramework::escape(\eel_accounts\Service\HmrcCtComputationCatalogueService::SOURCE_URL) . '" target="_blank" rel="noopener noreferrer">HMRC technical specifications</a><a class="button button-inline" href="?page=ct_filing_mappings">CT Filing Mappings</a></div>'
            . '<details><summary>Register a new artifact revision</summary><form method="post" data-ajax="true" class="panel-soft settings-stack">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CtComputationTaxonomy"><input type="hidden" name="intent" value="save_package"><div class="form-grid">'
            . '<label>Taxonomy version<input class="input" name="taxonomy_version" required></label><label>Artifact version<input class="input" name="artifact_version" required></label>'
            . '<label>Applicable from<input class="input" type="date" name="applicable_from" required></label><label>Applicable to<input class="input" type="date" name="applicable_to"></label>'
            . '<label>Official source URL<input class="input" type="url" name="source_url" value="' . HelperFramework::escape(\eel_accounts\Service\HmrcCtComputationCatalogueService::SOURCE_URL) . '" required></label><label>Download URL<input class="input" type="url" name="download_url"></label>'
            . '</div><button class="button primary" type="submit">Register revision</button></form></details>';
        if ($packages === []) { return $html . '<div class="notice warning">No computation-taxonomy package is registered.</div></div>'; }
        $html .= '<div class="table-scroll"><table><thead><tr><th>Taxonomy</th><th>Artifact</th><th>Applicability</th><th>Entry point</th><th>State</th><th>Inventory</th><th>Mappings</th></tr></thead><tbody>';
        foreach ($packages as $p) {
            $entry = (string)(($p['combined_dpl_entry_point_path'] ?? null) ?: ($p['entry_point_path'] ?? 'Not configured'));
            $html .= '<tr><td>' . HelperFramework::escape((string)$p['taxonomy_version']) . '</td><td>' . HelperFramework::escape((string)$p['artifact_version']) . '</td><td>' . HelperFramework::escape((string)$p['applicable_from'] . ' to ' . (string)($p['applicable_to'] ?: 'open')) . '</td><td>' . HelperFramework::escape($entry) . '</td><td>' . HelperFramework::escape((string)$p['package_state']) . '</td><td>' . (int)$p['file_count'] . ' files; ' . (int)$p['concept_count'] . ' concepts; ' . (int)$p['dimension_count'] . ' dimensions</td><td>' . (int)$p['compatible_profile_count'] . ' active compatible</td></tr>';
        }
        $html .= '</tbody></table></div>';
        foreach ($packages as $p) {
            $html .= '<form method="post" data-ajax="true" class="panel-soft settings-stack">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="CtComputationTaxonomy"><input type="hidden" name="intent" value="save_package"><input type="hidden" name="package_id" value="' . (int)$p['id'] . '">'
                . '<div class="form-grid"><label>Taxonomy version<input class="input" name="taxonomy_version" value="' . HelperFramework::escape((string)$p['taxonomy_version']) . '" required></label>'
                . '<label>Artifact version<input class="input" name="artifact_version" value="' . HelperFramework::escape((string)$p['artifact_version']) . '" required></label>'
                . '<label>Applicable from<input class="input" type="date" name="applicable_from" value="' . HelperFramework::escape((string)$p['applicable_from']) . '" required></label>'
                . '<label>Applicable to<input class="input" type="date" name="applicable_to" value="' . HelperFramework::escape((string)($p['applicable_to'] ?? '')) . '"></label>'
                . '<label>Package directory<input class="input" name="local_path" value="' . HelperFramework::escape((string)($p['local_path'] ?? '')) . '"></label>'
                . '<label>CT entry point<input class="input" name="entry_point_path" value="' . HelperFramework::escape((string)($p['entry_point_path'] ?? '')) . '"></label>'
                . '<label>Combined CT/DPL entry point<input class="input" name="combined_dpl_entry_point_path" value="' . HelperFramework::escape((string)($p['combined_dpl_entry_point_path'] ?? '')) . '" required></label>'
                . '<input type="hidden" name="source_url" value="' . HelperFramework::escape((string)$p['source_url']) . '"><input type="hidden" name="download_url" value="' . HelperFramework::escape((string)($p['download_url'] ?? '')) . '"></div>'
                . '<button class="button primary" type="submit">Save and verify package</button></form>';
            $html .= '<form method="post" data-ajax="true" class="panel-soft settings-stack">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="CtComputationTaxonomy"><input type="hidden" name="intent" value="catalogue"><input type="hidden" name="package_id" value="' . (int)$p['id'] . '">'
                . '<strong>' . HelperFramework::escape((string)$p['taxonomy_version'] . ' / ' . (string)$p['artifact_version']) . '</strong><label>Expanded local package directory<input class="input" name="directory" value="' . HelperFramework::escape((string)($p['local_path'] ?? '')) . '" required></label><button class="button" type="submit">Catalogue concepts and files</button></form>';
        }
        return $html . '</div>';
    }
}
