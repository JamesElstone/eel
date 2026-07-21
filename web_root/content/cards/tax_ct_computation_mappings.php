<?php
declare(strict_types=1);

final class _tax_ct_computation_mappingsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_ct_computation_mappings'; }
    public function title(): string { return 'Computation-taxonomy mappings'; }
    public function services(): array
    {
        return [
            ['key' => 'packages', 'service' => \eel_accounts\Service\HmrcCtComputationCatalogueService::class, 'method' => 'fetchPackages'],
            ['key' => 'profiles', 'service' => \eel_accounts\Service\CtFilingMappingService::class, 'method' => 'fetchProfiles', 'params' => ['targetType' => \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION]],
        ];
    }
    protected function additionalInvalidationFacts(): array { return ['ct.filing.mappings', 'hmrc.ct.computation.taxonomy']; }
    public function helper(array $context): string { return 'Canonical locked-computation data to taxonomy concepts, contexts, dimensions, units and presentation sections. Runtime has no PHP fallback.'; }
    public function render(array $context): string
    {
        $packages = (array)($context['services']['packages'] ?? []); $profiles = (array)($context['services']['profiles'] ?? []);
        $hidden = fn(string $intent): string => HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="CtFilingMappings"><input type="hidden" name="intent" value="' . $intent . '">';
        $html = '<div class="settings-stack"><form method="post" data-ajax="true" class="form-row-actions">' . $hidden('create_draft') . '<input type="hidden" name="target_type" value="computation_ixbrl"><label>Package <select name="package_id">';
        foreach ($packages as $package) { $html .= '<option value="' . (int)$package['id'] . '">' . HelperFramework::escape((string)$package['taxonomy_version'] . ' / ' . (string)$package['artifact_version']) . '</option>'; }
        $html .= '</select></label><button class="button primary" type="submit"' . ($packages === [] ? ' disabled' : '') . '>Create draft</button></form>';
        if ($profiles === []) { return $html . '<div class="notice warning">No computation mapping profile exists. Tax iXBRL generation will fail closed.</div></div>'; }
        $html .= '<div class="table-scroll"><table><thead><tr><th>Profile</th><th>Package</th><th>Status</th><th>Compatibility</th><th>Hash</th><th>Actions</th></tr></thead><tbody>';
        foreach ($profiles as $p) {
            $actions = [];
            if ((string)$p['status'] === 'draft') { $actions = ['validate' => 'Validate']; }
            elseif ((string)$p['status'] === 'validated') { $actions = ['activate' => 'Activate']; }
            elseif ((string)$p['status'] === 'active') { $actions = ['retire' => 'Retire']; }
            $buttons = '';
            foreach ($actions as $intent => $label) { $buttons .= '<form method="post" data-ajax="true" class="ct-filing-mapping-action-form">' . $hidden($intent) . '<input type="hidden" name="profile_id" value="' . (int)$p['id'] . '"><button class="button button-inline" type="submit">' . $label . '</button></form> '; }
            $compatibility = json_decode((string)($p['compatibility_json'] ?? ''), true); $compatibility = is_array($compatibility) ? $compatibility : [];
            $html .= '<tr><td>' . HelperFramework::escape((string)$p['profile_name'] . ' r' . (string)$p['revision_no']) . '</td><td>' . HelperFramework::escape((string)($p['taxonomy_version'] ?? '') . ' / ' . (string)($p['taxonomy_artifact_version'] ?? '')) . '</td><td>' . HelperFramework::escape((string)$p['status']) . '</td><td>' . HelperFramework::escape((string)$p['compatibility_status']) . '<br><span class="helper">' . count((array)($compatibility['unmapped_canonical_sources'] ?? [])) . ' canonical; ' . count((array)($compatibility['unmapped_required_targets'] ?? [])) . ' required target; ' . count((array)($compatibility['missing_targets'] ?? [])) . ' removed/changed</span></td><td>' . HelperFramework::escape(substr((string)$p['content_hash'], 0, 12)) . '</td><td>' . $buttons . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        foreach ($profiles as $p) { if ((string)$p['status'] !== 'draft') { continue; } $html .= '<details><summary>Add or replace mapping in ' . HelperFramework::escape((string)$p['profile_name'] . ' r' . (string)$p['revision_no']) . '</summary><form method="post" data-ajax="true" class="panel-soft settings-stack">' . $hidden('save_mapping') . '<input type="hidden" name="target_type" value="computation_ixbrl"><input type="hidden" name="profile_id" value="' . (int)$p['id'] . '"><div class="form-grid"><label>Canonical key<input class="input" name="canonical_key" required></label><label>Catalogued QName<input class="input" name="taxonomy_concept" required></label><label>Label<input class="input" name="presentation_label" required></label><label>Section<select name="presentation_section"><option>detailed_profit_and_loss</option><option>accounts_adjustments</option><option>capital_allowances</option><option>losses</option><option>tax_liability</option></select></label><label>Value type<select name="value_type"><option>numeric</option><option>text</option><option>date</option><option>boolean</option><option>integer</option></select></label><label>Period type<select name="period_type"><option>duration</option><option>instant</option></select></label><label>Unit<input class="input" name="unit_ref" value="GBP"></label><label>Decimals<input class="input" name="decimals_value" value="2"></label><label>Dimensions JSON<input class="input" name="dimensions_json" placeholder="{}"></label><label>Null policy<select name="null_policy"><option>omit</option><option>nil</option><option>error</option></select></label><label>Sign multiplier<input class="input" type="number" step="0.01" name="sign_multiplier" value="1"></label><label>Order<input class="input" type="number" name="sort_order" value="100"></label></div><label class="checkbox-row"><input type="checkbox" name="is_required" value="1"> Required</label><button class="button primary" type="submit">Save draft mapping</button></form></details>'; }
        return $html . '</div>';
    }
}
