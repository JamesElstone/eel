<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_ownership_partiesCard extends CardBaseFramework
{
    public function key(): string { return 'incorporation_ownership_parties'; }
    public function title(): string { return 'Ownership & Parties'; }

    public function helper(array $context): string
    {
        return 'Ownership records are entered and maintained by people, not created automatically. They are effective-dated, so ending a holding keeps the history needed for earlier Corporation Tax periods.';
    }

    public function services(): array
    {
        return [[
            'key' => 'ownership',
            'service' => \eel_accounts\Service\OwnershipPartyService::class,
            'method' => 'fetchSummary',
            'params' => ['companyId' => ':company.id'],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['ownership.parties', 'tax.s455', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $summary = (array)($context['services']['ownership'] ?? []);
        if ($companyId <= 0) {
            return '<div class="helper">Select a company before maintaining ownership.</div>';
        }
        if (empty($summary['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Ownership is unavailable.')) . '</div>';
        }

        $partyOptions = $this->partyOptions((array)$summary['parties']);
        $directorOptions = $this->directorOptions((array)$summary['directors'], (array)$summary['parties']);
        $shareClassOptions = $this->shareClassOptions((array)$summary['share_classes']);
        $rows = '';
        foreach ((array)$summary['parties'] as $party) {
            $roles = array_map(
                static fn(array $role): string => HelperFramework::labelFromKey((string)$role['role_type'], '_')
                    . ' (' . (string)$role['effective_from'] . ' to ' . ((string)($role['effective_to'] ?? '') ?: 'current') . ')',
                (array)($party['roles'] ?? [])
            );
            $holdings = array_map(
                static fn(array $holding): string => (int)$holding['quantity'] . ' ' . (string)$holding['share_class']
                    . ' (' . (string)$holding['effective_from'] . ' to ' . ((string)($holding['effective_to'] ?? '') ?: 'current') . ')',
                (array)($party['holdings'] ?? [])
            );
            $rows .= '<tr><td>' . HelperFramework::escape((string)$party['legal_name']) . '</td>'
                . '<td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)$party['party_type'], '_')) . '</td>'
                . '<td>' . HelperFramework::escape((string)($party['linked_director_name'] ?? '')) . '</td>'
                . '<td>' . HelperFramework::escape(implode('; ', $roles)) . '</td>'
                . '<td>' . HelperFramework::escape(implode('; ', $holdings)) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="helper">No ownership parties have been recorded.</td></tr>';
        }
        return '<section class="settings-stack" id="ownership-parties">'
            . '<div class="panel-soft"><table class="table"><thead><tr><th>Party</th><th>Type</th><th>Linked director</th><th>Roles</th><th>Holdings</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . $this->directorShareholdingsForm($companyId, (array)$summary['directors'], $shareClassOptions)
            . $this->partyForm($companyId, $directorOptions)
            . $this->roleForm($companyId, $partyOptions)
            . $this->endRoleForm($companyId, (array)$summary['parties'])
            . $this->holdingForm($companyId, $partyOptions, $shareClassOptions)
            . $this->endHoldingForm($companyId, (array)$summary['parties'])
            . '</section>';
    }

    private function partyForm(int $companyId, string $directorOptions): string
    {
        return '<form method="post" data-ajax="true" data-ownership-party-form="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="save_ownership_party">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">Add ownership details</h4>'
            . '<table class="table"><thead><tr><th>Director status</th><th>Owner name</th><th>Owner basis</th><th>Notes</th><th></th></tr></thead><tbody><tr>'
            . '<td><select class="select" name="director_status" data-ownership-director-status data-no-submit-on-change="true"><option value="director">Director</option><option value="non_director">Non-Director</option></select></td>'
            . '<td><input type="hidden" name="legal_name" data-ownership-director-legal-name><select class="select" name="linked_director_id" data-ownership-director-name data-no-submit-on-change="true" required><option value="">Select director</option>' . $directorOptions . '</select><input class="input" name="legal_name" data-ownership-non-director-name required hidden></td>'
            . '<td><input type="hidden" name="party_type" value="individual" data-ownership-director-party-type><select class="select" name="party_type" data-ownership-party-type data-no-submit-on-change="true" disabled><option value="individual" selected>Individual</option><option value="company">Company</option><option value="trust">Trust</option><option value="partnership">Partnership</option><option value="other">Other</option></select></td>'
            . '<td><input class="input" name="source_note"></td>'
            . '<td class="cell-fit"><button class="button primary" type="submit">Add ownership details</button></td>'
            . '</tr></tbody></table></form>';
    }

    private function directorShareholdingsForm(int $companyId, array $directors, string $shareClassOptions): string
    {
        if ($directors === []) {
            return '<div class="helper">Synchronise the company directors from Companies House before recording their shareholdings.</div>';
        }
        if ($shareClassOptions === '') {
            return '<div class="helper">Add the company’s share capital before recording directors’ shareholdings.</div>';
        }

        $rows = '';
        foreach ($directors as $director) {
            $directorId = (int)($director['id'] ?? 0);
            if ($directorId <= 0) {
                continue;
            }
            $rows .= '<tr><td>' . HelperFramework::escape((string)($director['full_name'] ?? '')) . '</td>'
                . '<td><input class="input" type="number" min="0" name="director_shareholdings[' . $directorId . '][quantity]" value="0"></td>'
                . '<td><input class="input" type="date" name="director_shareholdings[' . $directorId . '][issued_on]"></td></tr>';
        }

        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="save_director_shareholdings">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">Record directors’ shareholdings</h4>'
            . '<div class="form-grid"><div class="form-row"><label>Share class</label><select class="select" name="share_class_id" required><option value="">Select</option>' . $shareClassOptions . '</select></div></div>'
            . '<table class="table"><thead><tr><th>Director</th><th>Shares held</th><th>Issued on</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="actions-row"><button class="button primary" type="submit">Save directors’ shareholdings</button></div></form>';
    }

    private function roleForm(int $companyId, string $partyOptions): string
    {
        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="save_ownership_role">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">Add effective role</h4>'
            . '<div class="form-grid"><div class="form-row"><label>Party</label><select class="select" name="party_id" required><option value="">Select</option>' . $partyOptions . '</select></div>'
            . '<div class="form-row"><label>Role</label><select class="select" name="role_type"><option value="shareholder">Shareholder</option><option value="participator">Participator</option><option value="associate">Associate</option></select></div>'
            . '<div class="form-row"><label>Effective from</label><input class="input" type="date" name="effective_from" required></div>'
            . '<div class="form-row"><label>Effective to</label><input class="input" type="date" name="effective_to"></div></div>'
            . '<div class="actions-row"><button class="button primary" type="submit">Add role</button></div></form>';
    }

    private function holdingForm(int $companyId, string $partyOptions, string $shareClassOptions): string
    {
        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="save_shareholding">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">Add shareholding</h4>'
            . '<div class="form-grid"><div class="form-row"><label>Shareholder</label><select class="select" name="party_id" required><option value="">Select</option>' . $partyOptions . '</select></div>'
            . '<div class="form-row"><label>Share class</label><select class="select" name="share_class_id" required><option value="">Select</option>' . $shareClassOptions . '</select></div>'
            . '<div class="form-row"><label>Quantity</label><input class="input" type="number" min="1" name="quantity" required></div>'
            . '<div class="form-row"><label>Effective from</label><input class="input" type="date" name="effective_from" required></div>'
            . '<div class="form-row"><label>Effective to</label><input class="input" type="date" name="effective_to"></div></div>'
            . '<div class="actions-row"><button class="button primary" type="submit">Add holding</button></div></form>';
    }

    private function endRoleForm(int $companyId, array $parties): string
    {
        $options = '';
        foreach ($parties as $party) {
            foreach ((array)($party['roles'] ?? []) as $role) {
                if (trim((string)($role['effective_to'] ?? '')) !== '') { continue; }
                $options .= '<option value="' . (int)$role['id'] . '">' . HelperFramework::escape(
                    (string)$party['legal_name'] . ' — ' . HelperFramework::labelFromKey((string)$role['role_type'], '_')
                ) . '</option>';
            }
        }
        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="end_ownership_role">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">End an ownership role</h4>'
            . '<div class="form-grid"><div class="form-row"><label>Current role</label><select class="select" name="role_id" required><option value="">Select</option>' . $options . '</select></div>'
            . '<div class="form-row"><label>Last effective date</label><input class="input" type="date" name="effective_to" required></div></div>'
            . '<div class="actions-row"><button class="button" type="submit">End role</button></div></form>';
    }

    private function endHoldingForm(int $companyId, array $parties): string
    {
        $options = '';
        foreach ($parties as $party) {
            foreach ((array)($party['holdings'] ?? []) as $holding) {
                if (trim((string)($holding['effective_to'] ?? '')) !== '') { continue; }
                $options .= '<option value="' . (int)$holding['id'] . '">' . HelperFramework::escape((string)$party['legal_name'] . ' — ' . (int)$holding['quantity'] . ' ' . (string)$holding['share_class']) . '</option>';
            }
        }
        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="end_shareholding">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">End a holding</h4>'
            . '<div class="form-grid"><div class="form-row"><label>Current holding</label><select class="select" name="holding_id" required><option value="">Select</option>' . $options . '</select></div>'
            . '<div class="form-row"><label>Last effective date</label><input class="input" type="date" name="effective_to" required></div></div>'
            . '<div class="actions-row"><button class="button" type="submit">End holding</button></div></form>';
    }

    private function partyOptions(array $parties): string
    {
        $html = '';
        foreach ($parties as $party) { $html .= '<option value="' . (int)$party['id'] . '">' . HelperFramework::escape((string)$party['legal_name']) . '</option>'; }
        return $html;
    }

    private function directorOptions(array $directors, array $parties = []): string
    {
        $linkedDirectorIds = [];
        foreach ($parties as $party) {
            $linkedDirectorId = (int)($party['linked_director_id'] ?? 0);
            if ($linkedDirectorId > 0) {
                $linkedDirectorIds[$linkedDirectorId] = true;
            }
        }

        $html = '';
        foreach ($directors as $director) {
            $directorId = (int)($director['id'] ?? 0);
            if ($directorId <= 0 || isset($linkedDirectorIds[$directorId])) {
                continue;
            }

            $html .= '<option value="' . $directorId . '">' . HelperFramework::escape((string)$director['full_name']) . '</option>';
        }

        return $html;
    }

    private function shareClassOptions(array $classes): string
    {
        $html = '';
        foreach ($classes as $class) { $html .= '<option value="' . (int)$class['id'] . '">' . HelperFramework::escape((string)$class['share_class'] . ' (' . (int)$class['quantity'] . ' issued)') . '</option>'; }
        return $html;
    }
}
