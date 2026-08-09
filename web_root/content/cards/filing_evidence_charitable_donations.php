<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_charitable_donationsCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_charitable_donations'; }
    public function title(): string { return 'Frozen Charitable Donation Evidence'; }
    public function services(): array { return [[
        'key' => 'filingEvidenceCharitableDonations',
        'service' => \eel_accounts\Service\FilingEvidenceService::class,
        'method' => 'sectionDetail',
        'params' => [
            'companyId' => ':company.id',
            'bundleId' => ':filing_evidence.bundle_id',
            'sectionCode' => 'charitable_donations',
            'page' => 1,
        ],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $model = (array)($context['services']['filingEvidenceCharitableDonations'] ?? []);
        if (!empty($model['empty_selection'])) {
            return '<div class="helper">Look up an Evidence ID to inspect its frozen charitable-donation evidence.</div>';
        }
        if (empty($model['available'])) {
            return '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)(
                ($model['errors'] ?? [])[0] ?? 'Charitable-donation evidence was not captured for this historic bundle.'
            )) . '</div>';
        }

        $payload = (array)($model['payload'] ?? []);
        $section = (array)($model['section'] ?? []);
        $records = array_values((array)($payload['records'] ?? []));
        $verifiedAmount = (float)(($payload['totals'] ?? [])['verified_amount'] ?? 0);
        $rows = '';
        foreach ($records as $record) {
            if (!is_array($record)) { continue; }
            $registration = $this->authorityLabel((string)($record['authority'] ?? '')) . ' '
                . (string)($record['registration_number'] ?? '');
            if (trim((string)($record['entity_suffix'] ?? '')) !== '') {
                $registration .= ' / ' . trim((string)$record['entity_suffix']);
            }
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)($record['txn_date'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($record['registered_name'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html(trim($registration)) . '</td><td>'
                . \eel_accounts\Support\Utf8::html((string)($record['registry_status'] ?? '')) . '</td><td>'
                . \eel_accounts\Support\Utf8::html($this->money($context, $record['amount'] ?? 0)) . '</td></tr>';
        }

        $snapshotHash = (string)($section['snapshot_hash'] ?? '');
        return '<div class="helper">These verified payments were frozen when the Evidence ID was created. '
            . 'Qualifying donations are deducted from available profits and may reduce Corporation Tax owed; the amount claimed remains subject to the frozen Corporation Tax computation.</div>'
            . '<div class="summary-grid">'
            . $this->stat('Verified amount paid', $this->money($context, $verifiedAmount))
            . $this->stat('Frozen payments', (string)count($records))
            . $this->stat('Snapshot hash', $snapshotHash === '' ? 'Unavailable' : substr($snapshotHash, 0, 16) . '…')
            . '</div><div class="table-scroll"><table><thead><tr><th>Payment date</th><th>Registered charity</th><th>Register number</th><th>Status</th><th>Amount</th></tr></thead><tbody>'
            . ($rows === '' ? '<tr><td colspan="5">No verified qualifying charitable donations were frozen for this Evidence ID.</td></tr>' : $rows)
            . '</tbody></table></div>';
    }

    private function authorityLabel(string $authority): string
    {
        return match (strtolower(trim($authority))) {
            'cc_ew' => 'Charity Commission',
            'oscr' => 'OSCR',
            'ccni' => 'CCNI',
            default => strtoupper(trim($authority)),
        };
    }

    private function stat(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label)
            . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value) . '</div></div>';
    }

    private function money(array $context, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money((array)($context['company']['settings'] ?? []), $value);
    }
}
