<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds an immutable canonical filing model exclusively from the locked run and its audit snapshot. */
final class CtPeriodFilingModelService
{
    public const BASIS_VERSION = 'ct-period-filing-model-v1';

    public function build(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            return $this->failure('Select a company, accounting period and CT period.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT c.company_name, c.company_number, ap.period_start AS accounting_period_start,
                    ap.period_end AS accounting_period_end, ctp.*, r.id AS run_id, r.status AS run_status,
                    r.computation_hash, r.summary_json, s.id AS snapshot_id, s.basis_version AS snapshot_basis_version,
                    s.basis_hash AS snapshot_basis_hash, yr.is_locked, yr.locked_at
             FROM corporation_tax_periods ctp
             INNER JOIN companies c ON c.id = ctp.company_id
             INNER JOIN accounting_periods ap ON ap.id = ctp.accounting_period_id
             INNER JOIN year_end_reviews yr ON yr.company_id = ctp.company_id AND yr.accounting_period_id = ctp.accounting_period_id
             LEFT JOIN corporation_tax_computation_runs r ON r.id = ctp.latest_computation_run_id
             LEFT JOIN corporation_tax_audit_snapshots s ON s.computation_run_id = r.id
             WHERE ctp.id = :ct_period_id AND ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            return $this->failure('The selected CT period is not available in this accounting context.');
        }
        $errors = [];
        if (empty($row['is_locked']) || trim((string)$row['locked_at']) === '') {
            $errors[] = 'The accounting period must be locked before a tax computation iXBRL can be generated.';
        }
        if ((int)($row['run_id'] ?? 0) <= 0) {
            $errors[] = 'The CT period has no locked computation run.';
        } elseif ((string)($row['run_status'] ?? '') !== 'generated') {
            $errors[] = 'The current CT computation run has not completed successfully.';
        }
        if ((int)($row['snapshot_id'] ?? 0) <= 0) {
            $errors[] = 'The locked computation has no frozen Tax Audit snapshot.';
        }
        $summary = json_decode((string)($row['summary_json'] ?? ''), true);
        if (!is_array($summary)) {
            $errors[] = 'The locked computation summary is unreadable.';
            $summary = [];
        }
        $summary['ct_period_id'] = $ctPeriodId;
        foreach ((new CorporationTaxHardGateService())->evaluatePeriod($summary) as $diagnostic) {
            $errors[] = (string)($diagnostic['message'] ?? 'The locked computation has an unresolved filing diagnostic.');
        }
        $warningText = strtolower(implode(' ', array_map('strval', (array)($summary['warnings'] ?? []))));
        foreach (['multiple trade', 'property business', 'property schedule', 'specialist sector'] as $unsupported) {
            if (str_contains($warningText, $unsupported)) {
                $errors[] = 'This filing scope is not supported in V1: ' . $unsupported . '.';
            }
        }
        $areaRows = (int)($row['snapshot_id'] ?? 0) > 0
            ? \InterfaceDB::fetchAll('SELECT * FROM corporation_tax_audit_areas WHERE snapshot_id = :snapshot_id ORDER BY id', ['snapshot_id' => (int)$row['snapshot_id']])
            : [];
        $areas = [];
        foreach ($areaRows as $area) {
            $code = (string)$area['area_code'];
            $detail = json_decode((string)$area['detail_json'], true);
            if (!is_array($detail)) {
                $errors[] = 'The frozen ' . $code . ' audit schedule is unreadable.';
                continue;
            }
            if ((string)$area['reconciliation_status'] !== 'reconciled' || abs((float)$area['reconciliation_difference']) >= 0.005) {
                $errors[] = 'The frozen ' . $code . ' schedule does not cross-cast to the locked computation.';
            }
            $areas[$code] = $detail;
        }
        foreach (['accounting_profit', 'expense_treatments', 'depreciation_capital', 'capital_allowances', 'losses', 'tax_liability'] as $requiredArea) {
            if (!isset($areas[$requiredArea])) {
                $errors[] = 'The frozen filing basis is missing the ' . $requiredArea . ' schedule.';
            }
        }
        if ($errors !== []) {
            return ['available' => false, 'errors' => array_values(array_unique($errors)), 'run' => $row];
        }
        $model = [
            'identity' => ['company_name' => (string)$row['company_name'], 'company_number' => (string)$row['company_number']],
            'accounting_period' => ['start_date' => (string)$row['accounting_period_start'], 'end_date' => (string)$row['accounting_period_end']],
            'ct_period' => ['id' => $ctPeriodId, 'start_date' => (string)$row['period_start'], 'end_date' => (string)$row['period_end'], 'sequence_no' => (int)$row['sequence_no']],
            'computation' => ['run_id' => (int)$row['run_id'], 'hash' => (string)$row['computation_hash'], 'summary' => $summary],
            'audit' => $areas,
        ];
        $facts = [];
        $this->flatten($model, '', $facts);
        $canonical = $this->canonicalJson($model);
        return [
            'available' => true,
            'errors' => [],
            'run' => $row,
            'model' => $model,
            'facts' => $facts,
            'basis_version' => self::BASIS_VERSION . '+' . (string)$row['snapshot_basis_version'],
            'basis_hash' => hash('sha256', self::BASIS_VERSION . '|' . (string)$row['snapshot_basis_hash'] . '|' . $canonical),
        ];
    }

    private function flatten(mixed $value, string $prefix, array &$facts): void
    {
        if (!is_array($value)) {
            if ($prefix !== '') {
                $facts[$prefix] = $value;
            }
            return;
        }
        foreach ($value as $key => $child) {
            $this->flatten($child, $prefix === '' ? (string)$key : $prefix . '.' . $key, $facts);
        }
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };
        return (string)json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function failure(string $message): array
    {
        return ['available' => false, 'errors' => [$message]];
    }
}
