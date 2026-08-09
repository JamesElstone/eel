<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

final class _asset_relationshipsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'asset_relationships';
    }

    public function title(): string
    {
        return 'Operational asset relationships';
    }

    public function helper(array $context): string
    {
        return 'Link pre-use source costs to the operational asset. The original source records and capital-allowance dates remain unchanged.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'assetRelationshipData',
                'service' => \eel_accounts\Service\AssetService::class,
                'method' => 'fetchAssetRelationshipData',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'selectedParentAssetId' => ':asset_relationship_parent_id',
                ],
            ],
            [
                'key' => 'periodLockState',
                'service' => \eel_accounts\Service\YearEndLockService::class,
                'method' => 'isLocked',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $selectedParentId = max(0, (int)(
            $actionResult->context()['asset_relationship_parent_id']
            ?? $request->input('asset_relationship_parent_id', 0)
        ));
        if ($selectedParentId > 0) {
            $pageContext['asset_relationship_parent_id'] = $selectedParentId;
        }

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return $serviceKey === 'periodLockState'
            ? 'Period lock state could not be loaded: ' . (string)($error['message'] ?? 'service error')
            : 'Asset relationship data could not be loaded: ' . (string)($error['message'] ?? 'service error');
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $data = (array)($context['services']['assetRelationshipData'] ?? []);
        $isLocked = !empty($context['services']['periodLockState']);
        if (($context['service_errors']['periodLockState'] ?? null) !== null) {
            $isLocked = true;
        }
        if (empty($data['schema_ready'])) {
            return '<div class="helper">Run the available-for-use asset migration before managing relationships.</div>';
        }

        $html = $isLocked
            ? '<div class="helper"><span class="badge warning">Period locked</span> Relationships can be reviewed but not changed for this period.</div>'
            : $this->parentSelector($context, $data);
        if (!$isLocked && is_array($data['selected_parent'] ?? null)) {
            $html .= $this->editor($context, $data, $settings, $isLocked);
        }

        return $html . $this->relationshipsTable($context, $data, $settings, $isLocked);
    }

    private function parentSelector(array $context, array $data): string
    {
        $company = (array)($context['company'] ?? []);
        $selectedId = (int)($context['asset_relationship_parent_id'] ?? 0);
        $options = '<option value="">Choose an operational asset…</option>';
        foreach ((array)($data['parent_candidates'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $id = (int)($asset['id'] ?? 0);
            $options .= '<option value="' . $id . '"' . ($id === $selectedId ? ' selected' : '') . '>'
                . \eel_accounts\Support\Utf8::html($this->assetLabel($asset)) . '</option>';
        }

        return '<form method="post" action="?page=assets" data-ajax="true" class="form-grid">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Asset"><input type="hidden" name="intent" value="select_asset_relationship_parent">'
            . '<input type="hidden" name="company_id" value="' . (int)($company['id'] ?? 0) . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . (int)($company['accounting_period_id'] ?? 0) . '">'
            . '<div class="form-row"><label for="asset-relationship-parent">Operational asset</label><select class="select" id="asset-relationship-parent" name="asset_relationship_parent_id" required>' . $options . '</select></div>'
            . '<div class="form-row"><label>&nbsp;</label><button class="button" type="submit">Edit Relationship</button></div></form>';
    }

    private function editor(array $context, array $data, array $settings, bool $isLocked): string
    {
        $company = (array)($context['company'] ?? []);
        $parent = (array)$data['selected_parent'];
        $parentId = (int)($parent['id'] ?? 0);
        $components = (array)($data['component_candidates'] ?? []);
        $disabled = $isLocked ? ' disabled aria-disabled="true"' : '';
        $componentRows = '';
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $componentId = (int)($component['id'] ?? 0);
            $linked = !empty($component['linked_to_selected_parent']);
            $componentRows .= '<tr><td><label class="checkbox-row"><input type="checkbox" name="component_asset_ids[]" value="' . $componentId . '"' . ($linked ? ' checked' : '') . $disabled . '><span>' . \eel_accounts\Support\Utf8::html($this->assetLabel($component)) . '</span></label></td>'
                . '<td>' . \eel_accounts\Support\Utf8::html($this->displayDate((string)($component['purchase_date'] ?? ''))) . '</td>'
                . '<td class="numeric">' . \eel_accounts\Support\Utf8::html($this->money($settings, (float)($component['cost'] ?? 0))) . '</td>'
                . '<td>' . ($linked
                    ? '<label>Standalone date if removed <input class="input" type="date" name="detached_available_for_use_dates[' . $componentId . ']" value="' . \eel_accounts\Support\Utf8::html((string)($component['available_for_use_date'] ?? '')) . '"' . $disabled . '></label>'
                    : '<span class="helper">Unlinked source cost</span>') . '</td></tr>';
        }
        if ($componentRows === '') {
            $componentRows = '<tr><td colspan="4">No eligible source assets are available.</td></tr>';
        }

        return '<form method="post" action="?page=assets" data-ajax="true" class="asset-relationship-form">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Asset"><input type="hidden" name="intent" value="save_asset_relationship">'
            . '<input type="hidden" name="company_id" value="' . (int)($company['id'] ?? 0) . '"><input type="hidden" name="accounting_period_id" value="' . (int)($company['accounting_period_id'] ?? 0) . '">'
            . '<input type="hidden" name="asset_relationship_parent_id" value="' . $parentId . '">'
            . '<h3>' . \eel_accounts\Support\Utf8::html($this->assetLabel($parent)) . '</h3><div class="form-grid">'
            . '<div class="form-row"><label for="asset-relationship-available-date">Available for use</label><input class="input" id="asset-relationship-available-date" type="date" name="available_for_use_date" value="' . \eel_accounts\Support\Utf8::html((string)($parent['available_for_use_date'] ?? '')) . '" required' . $disabled . '></div>'
            . '<div class="form-row"><label for="asset-relationship-evidence">Evidence</label><input class="input" id="asset-relationship-evidence" type="text" name="available_for_use_evidence" value="' . \eel_accounts\Support\Utf8::html((string)($parent['available_for_use_evidence'] ?? '')) . '" required' . $disabled . '></div></div>'
            . '<p class="helper">Tick directly attributable costs incurred before the asset was available for use. For a currently linked item that is unticked, record its standalone operational date.</p>'
            . '<div class="table-scroll"><table><thead><tr><th>Source asset</th><th>Purchase date</th><th>Cost</th><th>Correction if removed</th></tr></thead><tbody>' . $componentRows . '</tbody></table></div>'
            . '<button class="button primary" type="submit"' . ($isLocked ? ' disabled' : '') . '>Save Relationship</button></form>';
    }

    private function relationshipsTable(array $context, array $data, array $settings, bool $isLocked): string
    {
        $company = (array)($context['company'] ?? []);
        $rows = '';
        foreach ((array)($data['relationships'] ?? []) as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }
            $componentSummary = implode('; ', array_map(
                fn(array $component): string => $this->assetLabel($component) . ' (' . $this->money($settings, (float)($component['cost'] ?? 0)) . ')',
                (array)($relationship['components'] ?? [])
            ));
            $id = (int)($relationship['id'] ?? 0);
            $edit = $isLocked ? '<span class="helper">Read only</span>' : '<form method="post" action="?page=assets" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Asset"><input type="hidden" name="intent" value="select_asset_relationship_parent">'
                . '<input type="hidden" name="company_id" value="' . (int)($company['id'] ?? 0) . '"><input type="hidden" name="accounting_period_id" value="' . (int)($company['accounting_period_id'] ?? 0) . '">'
                . '<input type="hidden" name="asset_relationship_parent_id" value="' . $id . '"><button class="button" type="submit">Edit</button></form>';
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html($this->assetLabel($relationship)) . '</td><td>' . \eel_accounts\Support\Utf8::html($this->displayDate((string)($relationship['available_for_use_date'] ?? ''))) . '</td><td class="numeric">' . \eel_accounts\Support\Utf8::html($this->money($settings, (float)($relationship['accounting_cost'] ?? 0))) . '</td><td>' . \eel_accounts\Support\Utf8::html($componentSummary) . '</td><td>' . $edit . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">No operational asset relationships have been recorded.</td></tr>';
        }

        return '<h3>Existing relationships</h3><div class="table-scroll"><table><thead><tr><th>Operational asset</th><th>Available for use</th><th>Accounting cost</th><th>Linked source costs</th><th>Action</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function assetLabel(array $asset): string
    {
        $code = \eel_accounts\Support\Utf8::normalize(trim((string)($asset['asset_code'] ?? '')));
        $description = \eel_accounts\Support\Utf8::normalize(trim((string)($asset['description'] ?? '')));
        return trim($code . ($code !== '' && $description !== '' ? ' — ' : '') . $description);
    }

    private function displayDate(string $date): string
    {
        return $date === '' ? 'Not recorded' : (new \DateTimeImmutable($date))->format('d/m/y');
    }

    private function money(array $settings, float $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }
}
