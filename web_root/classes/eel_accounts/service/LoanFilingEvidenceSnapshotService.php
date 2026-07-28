<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Captures the director-loan evidence used by a Year End filing bundle. */
final class LoanFilingEvidenceSnapshotService
{
    public const SNAPSHOT_VERSION = 'loan-filing-evidence-v1';

    /** @return array{payload:array<string,mixed>,snapshot_hash:string} */
    public function captureForLock(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('Loan filing evidence can only be captured inside the Year End lock transaction.');
        }
        if (!(new YearEndLockService())->isLocked($companyId, $accountingPeriodId)) {
            throw new \RuntimeException('The accounting period must be locked before loan filing evidence is captured.');
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT id, sequence_no, period_start, period_end
             FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND status <> :superseded
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        ) ?: [];
        if ($periods === []) {
            throw new \RuntimeException('At least one active CT period is required for loan filing evidence.');
        }

        $s455Rows = \InterfaceDB::fetchAll(
            'SELECT * FROM corporation_tax_s455_reviews
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY ct_period_id, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        ) ?: [];
        $s455ByPeriod = [];
        foreach ($s455Rows as $row) {
            $basis = json_decode((string)($row['basis_json'] ?? ''), true);
            if (!is_array($basis) || trim((string)($row['basis_hash'] ?? '')) === '') {
                throw new \RuntimeException('The frozen s455 basis is unreadable for CT period ' . (int)($row['ct_period_id'] ?? 0) . '.');
            }
            $s455ByPeriod[(int)$row['ct_period_id']] = [
                'ct_period_id' => (int)$row['ct_period_id'],
                'close_company_status' => (string)($row['close_company_status'] ?? ''),
                'gross_principal' => (float)($row['gross_principal'] ?? 0),
                'gross_tax' => (float)($row['gross_tax'] ?? 0),
                'qualifying_repayments' => (float)($row['qualifying_repayments'] ?? 0),
                'relief_tax' => (float)($row['relief_tax'] ?? 0),
                'net_tax' => (float)($row['net_tax'] ?? 0),
                'ct600a_required' => !empty($row['ct600a_required']),
                'repayment_deadline' => (string)($row['repayment_deadline'] ?? ''),
                'evidence_cutoff' => (string)($row['evidence_cutoff'] ?? ''),
                'window_status' => (string)($row['window_status'] ?? ''),
                'basis_hash' => (string)$row['basis_hash'],
                'basis' => $basis,
            ];
        }
        foreach ($periods as $period) {
            if (!isset($s455ByPeriod[(int)$period['id']])) {
                throw new \RuntimeException('The frozen s455 basis is missing for CT period ' . (int)$period['id'] . '.');
            }
        }

        $ct600a = (new Ct600aService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        if (empty($ct600a['available']) || count((array)($ct600a['periods'] ?? [])) !== count($periods)) {
            throw new \RuntimeException((string)(($ct600a['errors'] ?? [])[0] ?? 'The CT600A evidence is unavailable.'));
        }
        $statement = (new DirectorLoanService())->fetchStatement($companyId, $accountingPeriodId);
        $disclosure = (new DirectorLoanService())->fetchDisclosureSummary($companyId, $accountingPeriodId);
        if (empty($statement['success']) || empty($disclosure['success'])) {
            throw new \RuntimeException((string)(($statement['errors'] ?? $disclosure['errors'] ?? [])[0] ?? 'The Section 413 director-loan evidence is unavailable.'));
        }
        $accountsDisclosure = (new IxbrlAccountsDisclosureService())->fetch($companyId, $accountingPeriodId);
        if (empty($accountsDisclosure['success'])) {
            throw new \RuntimeException((string)(($accountsDisclosure['errors'] ?? [])[0] ?? 'The Section 413 accounts disclosure is unavailable.'));
        }
        $approval = (new YearEndAcknowledgementService())->fetch(
            $companyId,
            $accountingPeriodId,
            'director_loan_year_end_review'
        );
        if (!empty($statement['has_activity']) && !is_array($approval)) {
            throw new \RuntimeException('The recorded Director Loan Year End approval is required for loan filing evidence.');
        }

        $payload = [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'captured_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'applicable' => !empty($statement['has_activity'])
                || (bool)array_filter(array_map(static fn(array $row): bool => (float)($row['gross_principal'] ?? 0) >= 0.005, $s455ByPeriod)),
            'ct_periods' => array_map(static fn(array $period): array => [
                'ct_period_id' => (int)$period['id'],
                'sequence_no' => (int)$period['sequence_no'],
                'period_start' => (string)$period['period_start'],
                'period_end' => (string)$period['period_end'],
                's455' => $s455ByPeriod[(int)$period['id']],
            ], $periods),
            'ct600a' => [
                'questions' => (array)($ct600a['questions'] ?? []),
                'review' => (array)($ct600a['review'] ?? []),
                'periods' => array_values((array)($ct600a['periods'] ?? [])),
            ],
            'section_413' => [
                'statement' => $statement,
                'disclosure' => $disclosure,
                'accounts_disclosure' => [
                    'stored' => !empty($accountsDisclosure['stored']),
                    'complete' => !empty($accountsDisclosure['complete']),
                    'has_director_advances_credits_or_guarantees' => ($accountsDisclosure['disclosures']['has_director_advances_credits_or_guarantees'] ?? null),
                ],
                'year_end_approval' => is_array($approval) ? $approval : null,
            ],
        ];

        return ['payload' => $payload, 'snapshot_hash' => hash('sha256', $this->canonicalJson($payload))];
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) { return $item; }
            if (array_is_list($item)) { return array_map($normalise, $item); }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) { $item[$key] = $normalise($child); }
            return $item;
        };
        return \eel_accounts\Support\PersistentJson::encode(
            $normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
