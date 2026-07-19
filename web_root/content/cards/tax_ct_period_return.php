<?php
declare(strict_types=1);

final class _tax_ct_period_returnCard extends CardBaseFramework
{
    public function key(): string { return 'tax_ct_period_return'; }
    public function title(): string { return 'CT-period iXBRL return'; }
    public function services(): array
    {
        return [[
            'key' => 'ct_ixbrl_status',
            'service' => \eel_accounts\Service\IxbrlTaxComputationService::class,
            'method' => 'status',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'ctPeriodId' => ':tax.selected_ct_period_id',
            ],
        ]];
    }
    protected function additionalInvalidationFacts(): array { return ['tax.ct.ixbrl', 'year.end.lock', 'page.context']; }
    public function helper(array $context): string { return 'Generation uses only the locked computation and frozen Tax Audit basis. Database mappings and Arelle validation must both pass.'; }

    public function render(array $context): string
    {
        $status = (array)($context['services']['ct_ixbrl_status'] ?? []);
        $package = (array)($status['package'] ?? []);
        $profile = (array)($status['profile'] ?? []);
        $run = (array)($status['run'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $ctPeriodId = (int)($context['tax']['selected_ct_period_id'] ?? 0);
        $ready = !empty($status['ready']);
        $fresh = !empty($status['fresh']);
        $fileable = !empty($status['fileable']);
        $html = '<div class="settings-stack"><div class="actions-row">'
            . '<span class="badge ' . ($ready ? 'success' : 'warning') . '">' . ($ready ? 'Ready to generate' : 'Blocked') . '</span>'
            . '<span class="badge ' . ($fileable ? 'success' : ($fresh ? 'warning' : 'info')) . '">' . ($fileable ? 'Arelle validated' : ($fresh ? 'Generated, not fileable' : 'No current artifact')) . '</span></div>'
            . '<dl><dt>Taxonomy package</dt><dd>' . HelperFramework::escape($package === [] ? 'Not selected' : (string)$package['taxonomy_version'] . ' / ' . (string)$package['artifact_version']) . '</dd>'
            . '<dt>Mapping profile</dt><dd>' . HelperFramework::escape($profile === [] ? 'Not selected' : (string)$profile['profile_name'] . ' revision ' . (string)$profile['revision_no'] . ' (' . substr((string)$profile['content_hash'], 0, 12) . ')') . '</dd>'
            . '<dt>Artifact</dt><dd>' . HelperFramework::escape((string)($run['generated_filename'] ?? 'Not generated')) . '</dd>'
            . '<dt>Validation</dt><dd>' . HelperFramework::escape((string)($run['external_validation_status'] ?? 'not run')) . '</dd></dl>';
        foreach ((array)($status['errors'] ?? []) as $error) {
            $html .= '<div class="notice warning">' . HelperFramework::escape((string)$error) . '</div>';
        }
        $hidden = HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxCtPeriodReturn">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">';
        $html .= '<div class="form-row-actions"><form method="post" data-ajax="true">' . $hidden
            . '<input type="hidden" name="intent" value="generate"><button class="button primary" type="submit"' . ($ready ? '' : ' disabled') . '>Generate and validate</button></form>';
        if ($fresh) {
            $html .= '<form method="post" data-ajax="true">' . $hidden . '<input type="hidden" name="intent" value="validate"><button class="button" type="submit">Validate again</button></form>';
            $html .= '<form method="post">' . $hidden . '<input type="hidden" name="intent" value="download"><button class="button" type="submit">Download</button></form>';
        }
        return $html . '</div></div>';
    }
}
