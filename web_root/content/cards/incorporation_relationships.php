<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_relationshipsCard extends CardBaseFramework
{
    public function key(): string { return 'incorporation_relationships'; }
    public function title(): string { return 'Business Relationship'; }

    public function helper(array $context): string
    {
        return 'Record effective Participator and Associate relationships. Shareholder status is derived from effective share allocations.';
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
            return '<div class="helper">Select a company before maintaining incorporation relationships.</div>';
        }
        if (empty($summary['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Incorporation relationships are unavailable.')) . '</div>';
        }

        $parties = (array)$summary['parties'];
        return '<section class="settings-stack" id="incorporation-relationships">'
            . $this->rolesTable($companyId, $parties)
            . $this->roleForm($companyId, $this->partyOptions($parties))
            . '</section>';
    }

    private function rolesTable(int $companyId, array $parties): string
    {
        $rows = '';
        foreach ($parties as $party) {
            $shareholderFrom = $this->calculatedShareholderFrom($party);
            if ($shareholderFrom !== null) {
                $rows .= '<tr><td>' . HelperFramework::escape((string)$party['legal_name']) . '</td>'
                    . '<td>Shareholder (calculated)</td>'
                    . '<td>' . HelperFramework::escape(HelperFramework::displayDate($shareholderFrom)) . '</td>'
                    . '<td>Current</td><td>—</td></tr>';
            }
            foreach ((array)($party['roles'] ?? []) as $role) {
                $rows .= '<tr><td>' . HelperFramework::escape((string)$party['legal_name']) . '</td>'
                    . '<td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)$role['role_type'], '_')) . '</td>'
                    . '<td>' . HelperFramework::escape(HelperFramework::displayDate((string)($role['effective_from'] ?? ''))) . '</td>'
                    . '<td>' . HelperFramework::escape(
                        trim((string)($role['effective_to'] ?? '')) !== ''
                            ? HelperFramework::displayDate((string)$role['effective_to'])
                            : 'Current'
                    ) . '</td><td>' . $this->manageRoleForm($companyId, $role) . '</td></tr>';
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="helper">No business relationships have been recorded.</td></tr>';
        }

        return '<div class="panel-soft"><table class="table"><thead><tr><th>Party</th><th>Role</th><th>Effective from</th><th>Effective to</th><th>Manage</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private function roleForm(int $companyId, string $partyOptions): string
    {
        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="save_ownership_role">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><h4 class="card-title">Add effective role</h4>'
            . '<div class="form-grid"><div class="form-row"><label>Party</label><select class="select" name="party_id" required><option value="">Select</option>' . $partyOptions . '</select></div>'
            . '<div class="form-row"><label>Role</label><select class="select" name="role_type"><option value="participator">Participator</option><option value="associate">Associate</option></select></div>'
            . '<div class="form-row"><label>Effective from</label><input class="input" type="date" name="effective_from" required></div></div>'
            . '<div class="actions-row"><button class="button primary" type="submit">Add role</button></div></form>';
    }

    private function manageRoleForm(int $companyId, array $role): string
    {
        if (trim((string)($role['effective_to'] ?? '')) !== '') {
            return '—';
        }

        return '<form method="post" data-ajax="true" class="incorporation-relationships-manage-form">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="end_ownership_role">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="role_id" value="' . (int)$role['id'] . '">'
            . '<input class="input" type="date" name="effective_to" aria-label="Last effective date" required>'
            . '<button class="button" type="submit">End role</button></form>';
    }

    private function calculatedShareholderFrom(array $party): ?string
    {
        $effectiveFrom = [];
        foreach ((array)($party['effective_holdings'] ?? []) as $holding) {
            $from = trim((string)($holding['effective_from'] ?? ''));
            if ($from !== '') {
                $effectiveFrom[] = $from;
            }
        }
        if ($effectiveFrom === []) {
            return null;
        }
        sort($effectiveFrom);
        return $effectiveFrom[0];
    }

    private function partyOptions(array $parties): string
    {
        $html = '';
        foreach ($parties as $party) { $html .= '<option value="' . (int)$party['id'] . '">' . HelperFramework::escape((string)$party['legal_name']) . '</option>'; }
        return $html;
    }
}
