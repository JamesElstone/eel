<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_share_allocationCard extends CardBaseFramework
{
    public function key(): string { return 'incorporation_share_allocation'; }
    public function title(): string { return 'Share Allocation'; }

    public function helper(array $context): string
    {
        return 'Allocate issued shares to ownership parties and end a holding when it ceases. Holdings are effective-dated to retain the history used for earlier Corporation Tax periods.';
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
            return '<div class="helper">Select a company before allocating shares.</div>';
        }
        if (empty($summary['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Share allocation is unavailable.')) . '</div>';
        }

        $parties = (array)$summary['parties'];
        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        return '<section class="settings-stack" id="share-allocation">'
            . $this->allocationsTable($companyId, $parties)
            . $this->holdingForm($companyId, (int)($accountingPeriod['id'] ?? 0), $this->partyOptions($parties), $this->shareClassOptions(
                (array)$summary['share_classes'],
                (array)($summary['reconciliation']['rows'] ?? [])
            ))
            . '</section>';
    }

    private function allocationsTable(int $companyId, array $parties): string
    {
        $rows = '';
        foreach ($parties as $party) {
            foreach ((array)($party['holdings'] ?? []) as $holding) {
                $rows .= '<tr><td>' . HelperFramework::escape((string)$party['legal_name']) . '</td>'
                    . '<td>' . HelperFramework::escape((string)$holding['share_class']) . '</td>'
                    . '<td class="numeric">' . (int)$holding['quantity'] . '</td>'
                    . '<td>' . HelperFramework::escape(HelperFramework::displayDate((string)($holding['effective_from'] ?? ''))) . '</td>'
                    . '<td>' . HelperFramework::escape(
                        trim((string)($holding['effective_to'] ?? '')) !== ''
                            ? HelperFramework::displayDate((string)$holding['effective_to'])
                            : 'Current'
                    ) . '</td><td>' . $this->manageHoldingForm($companyId, $holding) . '</td></tr>';
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="helper">No share allocations have been recorded.</td></tr>';
        }

        return '<div class="panel-soft"><table class="table"><thead><tr><th>Entity</th><th>Share class</th><th>Quantity</th><th>Effective from</th><th>Effective to</th><th>Manage</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private function manageHoldingForm(int $companyId, array $holding): string
    {
        if (trim((string)($holding['effective_to'] ?? '')) !== '') {
            return '—';
        }

        return '<form method="post" data-ajax="true" class="share-allocation-manage-form">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="end_shareholding">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="holding_id" value="' . (int)$holding['id'] . '">'
            . '<input class="input" type="date" name="effective_to" aria-label="Last effective date" required>'
            . '<button class="button" type="submit">End holding</button></form>';
    }

    private function holdingForm(int $companyId, int $accountingPeriodId, string $partyOptions, string $shareClassOptions): string
    {
        return '<form method="post" data-ajax="true" data-share-allocation-form="true" class="panel-soft settings-stack share-allocation-add-form">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation"><input type="hidden" name="intent" value="save_shareholding">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '"><h4 class="card-title">Add shareholding</h4>'
            . '<div class="share-allocation-fields"><div class="form-row"><label>Entity</label><select class="select" name="party_id" data-no-submit-on-change="true" required><option value="">Select</option>' . $partyOptions . '</select></div>'
            . '<div class="form-row"><label>Issued Shares</label><select class="select" name="share_class_id" data-share-allocation-issued-shares data-no-submit-on-change="true" required><option value="">Select</option>' . $shareClassOptions . '</select></div>'
            . '<div class="form-row"><label>Quantity</label><input class="input" type="number" min="1" name="quantity" data-share-allocation-quantity required></div>'
            . '<div class="share-allocation-actions"><button class="button primary" type="submit">Add holding</button></div></div></form>';
    }

    private function partyOptions(array $parties): string
    {
        $html = '';
        foreach ($parties as $party) {
            $html .= '<option value="' . (int)$party['id'] . '">' . HelperFramework::escape((string)$party['legal_name']) . '</option>';
        }
        return $html;
    }

    private function shareClassOptions(array $classes, array $reconciliationRows): string
    {
        $allocatedByClassId = [];
        foreach ($reconciliationRows as $row) {
            $allocatedByClassId[(int)($row['share_class_id'] ?? 0)] = (int)($row['held_quantity'] ?? 0);
        }

        $html = '';
        foreach ($classes as $class) {
            $issuedAt = trim((string)($class['issued_at'] ?? ''));
            $issueDate = $issuedAt !== '' ? HelperFramework::displayDate($issuedAt) : 'Date not recorded';
            $classId = (int)($class['id'] ?? 0);
            $allocated = (int)($allocatedByClassId[$classId] ?? 0);
            $allotted = (int)$class['quantity'];
            if ($allocated === $allotted) {
                continue;
            }
            $html .= '<option value="' . $classId . '" data-remaining-shares="' . ($allotted - $allocated) . '">' . HelperFramework::escape(
                $issueDate . ' ' . (string)$class['share_class'] . ' '
                . $allocated . ' of ' . $allotted
            ) . '</option>';
        }
        return $html;
    }
}
