<?php
declare(strict_types=1);

final class _tax_ct600_rim_mappingsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_ct600_rim_mappings'; }
    public function title(): string { return 'CT600 RIM mappings'; }
    public function services(): array
    {
        return [
            ['key' => 'packages', 'service' => \eel_accounts\Service\HmrcCtRimCatalogueService::class, 'method' => 'fetchPackages'],
            ['key' => 'profiles', 'service' => \eel_accounts\Service\CtFilingMappingService::class, 'method' => 'fetchProfiles', 'params' => ['targetType' => \eel_accounts\Service\CtFilingMappingService::TARGET_RIM]],
        ];
    }
    protected function additionalInvalidationFacts(): array { return ['ct.filing.mappings', 'hmrc_ct_rim.state']; }
    public function helper(array $context): string { return 'Canonical tax data to CT600 XPath/XSD mappings. These profiles are reserved for later CT600 XML generation.'; }
    public function render(array $context): string { return $this->renderProfiles((array)($context['services']['packages'] ?? []), (array)($context['services']['profiles'] ?? [])); }
    private function renderProfiles(array $packages, array $profiles): string
    {
        $html = '<div class="settings-stack"><form method="post" data-ajax="true" class="form-row-actions">' . $this->hidden('create_draft')
            . '<input type="hidden" name="target_type" value="ct600_rim"><label>Package <select name="package_id">';
        foreach ($packages as $package) { $html .= '<option value="' . (int)$package['id'] . '">' . HelperFramework::escape((string)$package['form_version'] . ' / ' . (string)$package['artifact_version']) . '</option>'; }
        $html .= '</select></label><button class="button primary" type="submit"' . ($packages === [] ? ' disabled' : '') . '>Create draft</button></form>';
        $html .= $this->table($profiles);
        foreach ($profiles as $profile) { if ((string)$profile['status'] !== 'draft') { continue; } $html .= '<details><summary>Add or replace mapping in ' . HelperFramework::escape((string)$profile['profile_name'] . ' r' . (string)$profile['revision_no']) . '</summary><form method="post" data-ajax="true" class="panel-soft settings-stack">' . $this->hidden('save_mapping') . '<input type="hidden" name="target_type" value="ct600_rim"><input type="hidden" name="profile_id" value="' . (int)$profile['id'] . '"><div class="form-grid"><label>Canonical key<input class="input" name="canonical_key" required></label><label>Catalogued component path<input class="input" name="target_xpath" required></label><label>Value type<select name="value_type"><option>numeric</option><option>text</option><option>date</option><option>boolean</option><option>integer</option></select></label><label>Null policy<select name="null_policy"><option>omit</option><option>nil</option><option>error</option></select></label><label>Sign multiplier<input class="input" type="number" step="0.01" name="sign_multiplier" value="1"></label><label>Order<input class="input" type="number" name="sort_order" value="100"></label></div><label class="checkbox-row"><input type="checkbox" name="is_required" value="1"> Required</label><button class="button primary" type="submit">Save draft mapping</button></form></details>'; }
        return $html . '</div>';
    }
    private function table(array $profiles): string
    {
        if ($profiles === []) { return '<div class="notice warning">No CT600 RIM mapping profile exists.</div>'; }
        $html = '<div class="table-scroll"><table><thead><tr><th>Profile</th><th>Package</th><th>Status</th><th>Compatibility</th><th>Hash</th><th>Actions</th></tr></thead><tbody>';
        foreach ($profiles as $p) {
            $compatibility = json_decode((string)($p['compatibility_json'] ?? ''), true); $compatibility = is_array($compatibility) ? $compatibility : [];
            $html .= '<tr><td>' . HelperFramework::escape((string)$p['profile_name'] . ' r' . (string)$p['revision_no']) . '</td><td>' . HelperFramework::escape((string)($p['rim_version'] ?? '') . ' / ' . (string)($p['rim_artifact_version'] ?? '')) . '</td><td>' . HelperFramework::escape((string)$p['status']) . '</td><td>' . HelperFramework::escape((string)$p['compatibility_status']) . '<br><span class="helper">' . count((array)($compatibility['unmapped_canonical_sources'] ?? [])) . ' canonical; ' . count((array)($compatibility['unmapped_required_targets'] ?? [])) . ' required target; ' . count((array)($compatibility['missing_targets'] ?? [])) . ' removed/changed</span></td><td>' . HelperFramework::escape(substr((string)$p['content_hash'], 0, 12)) . '</td><td>' . $this->actions($p) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }
    private function actions(array $profile): string
    {
        $status = (string)$profile['status']; $html = '';
        foreach (($status === 'draft' ? ['validate' => 'Validate'] : ($status === 'validated' ? ['activate' => 'Activate'] : ($status === 'active' ? ['retire' => 'Retire'] : []))) as $intent => $label) {
            $html .= '<form method="post" data-ajax="true" style="display:inline">' . $this->hidden($intent) . '<input type="hidden" name="profile_id" value="' . (int)$profile['id'] . '"><button class="button button-inline" type="submit">' . $label . '</button></form> ';
        }
        return $html;
    }
    private function hidden(string $intent): string { return HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="CtFilingMappings"><input type="hidden" name="intent" value="' . $intent . '">'; }
}
