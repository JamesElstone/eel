<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class YearEndAcknowledgementService
{
    public const BASIS_VERSION = 'year_end_v1';

    public function fetch(int $companyId, int $accountingPeriodId, string $checkCode): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || trim($checkCode) === '' || !$this->tableAvailable()) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT check_code,
                    acknowledged_at,
                    acknowledged_by,
                    note,
                    basis_version,
                    basis_hash,
                    basis_json
             FROM year_end_review_acknowledgements
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND check_code = :check_code
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'check_code' => trim($checkCode),
            ]
        );

        return is_array($row) ? $row : null;
    }

    public function fetchAll(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->tableAvailable()) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT check_code,
                    acknowledged_at,
                    acknowledged_by,
                    note,
                    basis_version,
                    basis_hash,
                    basis_json
             FROM year_end_review_acknowledgements
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row['check_code'] ?? '')) === '') {
                continue;
            }
            $result[(string)$row['check_code']] = $row;
        }

        return $result;
    }

    public function evaluate(?array $acknowledgement, ?array $currentBasis, bool $approvedPreClosePosition = false): array
    {
        if (!is_array($acknowledgement)) {
            return ['state' => 'absent', 'current' => false, 'acknowledgement' => null];
        }

        $storedVersion = trim((string)($acknowledgement['basis_version'] ?? ''));
        $storedHash = trim((string)($acknowledgement['basis_hash'] ?? ''));
        if ($storedVersion === '' || $storedHash === '' || !hash_equals(self::BASIS_VERSION, $storedVersion)) {
            return ['state' => 'stale', 'current' => false, 'acknowledgement' => $acknowledgement];
        }

        if ($approvedPreClosePosition) {
            return [
                'state' => 'current',
                'current' => true,
                'acknowledgement' => $acknowledgement,
                'approved_pre_close_position' => true,
            ];
        }

        if ($currentBasis === null) {
            return ['state' => 'unverifiable', 'current' => false, 'acknowledgement' => $acknowledgement];
        }

        $currentHash = $this->hashBasis($currentBasis);
        $current = hash_equals($storedHash, $currentHash);

        return [
            'state' => $current ? 'current' : 'stale',
            'current' => $current,
            'acknowledgement' => $acknowledgement,
            'current_basis_hash' => $currentHash,
        ];
    }

    public function save(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        array $currentBasis,
        string $changedBy,
        string $note = ''
    ): array {
        if (!$this->tableAvailable()) {
            return ['success' => false, 'errors' => ['Run the Year End acknowledgement-basis migration before saving this approval.']];
        }

        $checkCode = trim($checkCode);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $actor = trim($changedBy) !== '' ? trim($changedBy) : 'web_app';
        $basisJson = $this->encodeBasis($currentBasis);
        $basisHash = hash('sha256', $basisJson);

        $sql = 'INSERT INTO year_end_review_acknowledgements (
                    company_id, accounting_period_id, check_code,
                    acknowledged_at, acknowledged_by, note,
                    basis_version, basis_hash, basis_json,
                    created_at, updated_at
                ) VALUES (
                    :company_id, :accounting_period_id, :check_code,
                    :acknowledged_at, :acknowledged_by, :note,
                    :basis_version, :basis_hash, :basis_json,
                    :created_at, :updated_at
                )';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(company_id, accounting_period_id, check_code) DO UPDATE SET
                    acknowledged_at = excluded.acknowledged_at,
                    acknowledged_by = excluded.acknowledged_by,
                    note = excluded.note,
                    basis_version = excluded.basis_version,
                    basis_hash = excluded.basis_hash,
                    basis_json = excluded.basis_json,
                    updated_at = excluded.updated_at'
            : ' ON DUPLICATE KEY UPDATE
                    acknowledged_at = VALUES(acknowledged_at),
                    acknowledged_by = VALUES(acknowledged_by),
                    note = VALUES(note),
                    basis_version = VALUES(basis_version),
                    basis_hash = VALUES(basis_hash),
                    basis_json = VALUES(basis_json),
                    updated_at = VALUES(updated_at)';

        \InterfaceDB::execute($sql, [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'check_code' => $checkCode,
            'acknowledged_at' => $now,
            'acknowledged_by' => $actor,
            'note' => trim($note) !== '' ? trim($note) : null,
            'basis_version' => self::BASIS_VERSION,
            'basis_hash' => $basisHash,
            'basis_json' => $basisJson,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'success' => true,
            'acknowledgement' => $this->fetch($companyId, $accountingPeriodId, $checkCode),
        ];
    }

    public function revoke(int $companyId, int $accountingPeriodId, string $checkCode): array
    {
        if (!$this->tableAvailable()) {
            return ['success' => false, 'errors' => ['Year End acknowledgements are not available.']];
        }

        \InterfaceDB::execute(
            'DELETE FROM year_end_review_acknowledgements
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND check_code = :check_code',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'check_code' => trim($checkCode),
            ]
        );

        return ['success' => true];
    }

    public function hashBasis(array $basis): string
    {
        return hash('sha256', $this->encodeBasis($basis));
    }

    public function normalizedBasis(array $basis): array
    {
        return $this->normalizeArray($basis);
    }

    public function buildBasis(string $checkCode, array $facts): array
    {
        return [
            'check_code' => trim($checkCode),
            'facts' => $this->compactAccountingFacts($facts),
        ];
    }

    private function encodeBasis(array $basis): string
    {
        $json = json_encode($this->normalizeArray($basis), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode the Year End acknowledgement basis.');
        }

        return $json;
    }

    private function normalizeArray(array $value): array
    {
        if (array_is_list($value)) {
            $normalized = array_map(fn(mixed $item): mixed => $this->normalizeValue($item), $value);
            usort($normalized, static fn(mixed $left, mixed $right): int => strcmp(
                (string)json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                (string)json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
            ));
            return $normalized;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item, (string)$key);
        }
        return $value;
    }

    private function normalizeValue(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            return $this->normalizeArray($value);
        }
        if (is_float($value)) {
            return number_format(round($value, 2), 2, '.', '');
        }
        if (is_string($value)) {
            $value = trim($value);
            $key = strtolower($key);
            if (($key === 'id' || str_ends_with($key, '_id') || str_contains($key, 'count') || str_contains($key, 'sequence'))
                && preg_match('/^-?\d+$/', $value) === 1) {
                return (int)$value;
            }
            if ($this->isDecimalKey($key) && is_numeric($value)) {
                return number_format(round((float)$value, 2), 2, '.', '');
            }
            if (str_contains($key, 'status') || $key === 'state' || $key === 'direction') {
                return strtolower($value);
            }
        }
        return $value;
    }

    private function compactAccountingFacts(array $facts): array
    {
        $result = [];
        foreach ($facts as $key => $value) {
            $keyText = strtolower((string)$key);
            if ($this->isPresentationOrMetadataKey($keyText)) {
                continue;
            }
            if (is_array($value)) {
                $nested = $this->compactAccountingFacts($value);
                if ($nested !== [] || $value === []) {
                    $result[$key] = $nested;
                }
                continue;
            }
            if ($this->isAccountingFactKey($keyText)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function isPresentationOrMetadataKey(string $key): bool
    {
        return str_contains($key, 'acknowledg')
            || str_contains($key, 'formatted')
            || str_contains($key, 'currency')
            || str_contains($key, 'url')
            || str_contains($key, 'label')
            || in_array($key, [
                'settings', 'errors', 'warnings', 'title', 'detail', 'detail_text', 'description',
                'name', 'note', 'notes', 'account', 'created_at', 'updated_at', 'reviewed_at',
                'reviewed_by', 'locked_at', 'locked_by', 'existing_journal',
            ], true);
    }

    private function isAccountingFactKey(string $key): bool
    {
        if ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_key')) {
            return true;
        }

        foreach ([
            'available', 'status', 'state', 'type', 'code', 'date', 'period', 'sequence', 'count',
            'amount', 'balance', 'debit', 'credit', 'profit', 'loss', 'tax', 'allowance', 'rate',
            'threshold', 'variance', 'total', 'opening', 'closing', 'carried', 'brought', 'movement',
            'value', 'scope', 'method', 'direction', 'required', 'used', 'is_', 'has_', 'can_',
            'source', 'nominal', 'equity', 'assets', 'liabilities', 'difference', 'exposure',
        ] as $token) {
            if (str_contains($key, $token)) {
                return true;
            }
        }

        return false;
    }

    private function isDecimalKey(string $key): bool
    {
        foreach ([
            'amount', 'balance', 'debit', 'credit', 'profit', 'loss', 'tax', 'allowance', 'rate',
            'threshold', 'variance', 'total', 'opening', 'closing', 'carried', 'brought', 'movement',
            'value', 'equity', 'assets', 'liabilities', 'difference', 'exposure',
        ] as $token) {
            if (str_contains($key, $token)) {
                return true;
            }
        }

        return false;
    }

    private function tableAvailable(): bool
    {
        try {
            return \InterfaceDB::tableExists('year_end_review_acknowledgements')
                && \InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_hash')
                && \InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_json')
                && \InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_version');
        } catch (\Throwable) {
            return false;
        }
    }
}
