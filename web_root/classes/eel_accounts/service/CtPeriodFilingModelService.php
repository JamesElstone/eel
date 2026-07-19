<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Loads and verifies the immutable CT-period basis created by filing approval. */
final class CtPeriodFilingModelService
{
    public const BASIS_VERSION = IxbrlAccountsFilingApprovalService::CT_BASIS_VERSION;

    public function build(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            return $this->failure('Select a company, accounting period and CT period.');
        }
        if (!\InterfaceDB::tableExists('ct_period_filing_bases')
            || !\InterfaceDB::tableExists('ixbrl_accounts_filing_approvals')) {
            return $this->failure('Apply the accounts filing approval migration before preparing CT filing output.');
        }

        $approvalStatus = (new IxbrlAccountsFilingApprovalService())->status($companyId, $accountingPeriodId);
        if (($approvalStatus['state'] ?? '') !== 'current' || !is_array($approvalStatus['approval'] ?? null)) {
            return $this->failure('Approve the current disclosures and filing basis before preparing CT filing output.');
        }
        $approval = (array)$approvalStatus['approval'];
        $row = \InterfaceDB::fetchOne(
            'SELECT b.*, r.id AS run_id, ctp.sequence_no, ctp.period_start, ctp.period_end,
                    ctp.latest_computation_run_id, r.computation_hash, r.summary_json,
                    a.approved_at, a.approved_by, a.year_end_locked_at
             FROM ct_period_filing_bases b
             INNER JOIN ixbrl_accounts_filing_approvals a ON a.id = b.filing_approval_id
             INNER JOIN corporation_tax_periods ctp ON ctp.id = b.ct_period_id
             INNER JOIN corporation_tax_computation_runs r ON r.id = b.computation_run_id
             WHERE b.filing_approval_id = :approval_id
               AND b.company_id = :company_id
               AND b.accounting_period_id = :period_id
               AND b.ct_period_id = :ct_period_id
             LIMIT 1',
            [
                'approval_id' => (int)$approval['id'], 'company_id' => $companyId,
                'period_id' => $accountingPeriodId, 'ct_period_id' => $ctPeriodId,
            ]
        );
        if (!is_array($row)) {
            return $this->failure('The current filing approval has no immutable basis for this CT period.');
        }

        $errors = [];
        if ((int)$row['latest_computation_run_id'] !== (int)$row['computation_run_id']) {
            $errors[] = 'The CT computation has changed since filing approval.';
        }
        $summary = json_decode((string)$row['summary_json'], true);
        $seal = is_array($summary) && is_array($summary['frozen_calculation_basis'] ?? null)
            ? (array)$summary['frozen_calculation_basis'] : [];
        $sealHash = (string)($seal['basis_hash'] ?? '');
        $sealBasis = $seal;
        unset($sealBasis['basis_hash']);
        if ($sealHash === ''
            || !hash_equals($sealHash, (string)$row['calculation_basis_hash'])
            || !hash_equals($sealHash, (new YearEndAcknowledgementService())->hashBasis($sealBasis))) {
            $errors[] = 'The approved CT calculation seal is missing, stale or invalid.';
        }
        $model = json_decode((string)$row['basis_json'], true);
        if (!is_array($model)) {
            $errors[] = 'The approved CT-period filing basis is unreadable.';
            $model = [];
        }
        $liveSummary = is_array($summary) ? $summary : [];
        unset($liveSummary['frozen_calculation_basis'], $liveSummary['frozen_filing_basis']);
        if (is_array($model)
            && (!hash_equals(
                hash('sha256', $this->canonicalJson((array)($model['computation']['summary'] ?? []))),
                hash('sha256', $this->canonicalJson($liveSummary))
            )
                || (int)($model['computation']['run_id'] ?? 0) !== (int)$row['computation_run_id']
                || !hash_equals((string)($model['computation']['hash'] ?? ''), (string)$row['computation_hash']))) {
            $errors[] = 'The frozen computation summary has changed since filing approval.';
        }
        $canonical = $this->canonicalJson($model);
        $calculatedHash = hash(
            'sha256',
            self::BASIS_VERSION . '|' . (string)$approval['basis_hash'] . '|'
            . (string)$row['calculation_basis_hash'] . '|' . $canonical
        );
        if ((string)$row['basis_version'] !== self::BASIS_VERSION
            || !hash_equals((string)$row['basis_hash'], $calculatedHash)
            || (int)($model['ct_period']['id'] ?? 0) !== $ctPeriodId
            || (int)($model['approval']['id'] ?? 0) !== (int)$approval['id']
            || !hash_equals((string)($model['approval']['basis_hash'] ?? ''), (string)$approval['basis_hash'])) {
            $errors[] = 'The approved CT-period filing basis failed its integrity check.';
        }
        if ($errors !== []) {
            return ['available' => false, 'errors' => array_values(array_unique($errors)), 'run' => $row];
        }

        $facts = [];
        $this->flatten($model, '', $facts);
        ksort($facts, SORT_STRING);
        return [
            'available' => true, 'errors' => [], 'run' => $row,
            'approval' => (array)$model['approval'],
            'supported_return_profile' => (array)($model['supported_return_profile'] ?? []),
            'blocking_diagnostics' => (array)($model['diagnostics']['blocking'] ?? []),
            'warning_diagnostics' => (array)($model['diagnostics']['warnings'] ?? []),
            'model' => $model, 'facts' => $facts,
            'basis_version' => (string)$row['basis_version'], 'basis_hash' => (string)$row['basis_hash'],
            'seal' => ['basis_version' => (string)$row['basis_version'], 'basis_hash' => (string)$row['basis_hash']],
        ];
    }

    /** Filing bases are now created only by the post-Year-End approval transaction. */
    public function buildForYearEndSeal(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        return $this->failure('CT filing bases are created after Year End by approving the complete filing basis.');
    }

    private function flatten(mixed $value, string $prefix, array &$facts): void
    {
        if (!is_array($value)) {
            if ($prefix !== '') { $facts[$prefix] = $value; }
            return;
        }
        foreach ($value as $key => $child) {
            $this->flatten($child, $prefix === '' ? (string)$key : $prefix . '.' . $key, $facts);
        }
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $child) { $item[$key] = $normalise($child); }
            return $item;
        };
        return json_encode($normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function failure(string $message): array
    {
        return [
            'available' => false, 'errors' => [$message], 'approval' => [],
            'blocking_diagnostics' => [], 'warning_diagnostics' => [],
        ];
    }
}
