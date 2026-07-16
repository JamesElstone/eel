<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DirectorLoanReportingPresentationService
{
    public const WITHIN_ONE_YEAR = 'within_one_year';
    public const AFTER_MORE_THAN_ONE_YEAR = 'after_more_than_one_year';
    public const PROVENANCE_VERSION = 1;

    private const PRESENTATION_TABLE = 'director_loan_reporting_presentations';
    private const AUDIT_TABLE = 'director_loan_reporting_presentation_audit';

    public function fetchPresentation(int $companyId, int $accountingPeriodId): array
    {
        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->error('The selected accounting period could not be found for this company.');
        }

        $presentation = $this->resolveForReporting($companyId, $accountingPeriodId);
        if (empty($presentation['applicable'])) {
            return $this->error((string)(
                ($presentation['errors'] ?? [])[0]
                ?? 'Map one Director Loan Liability control nominal in Company Nominals before setting its reporting presentation.'
            ));
        }

        return $presentation + [
            'success' => true,
            'available' => true,
            'accounting_period' => $period,
            'is_locked' => (new YearEndLockService())->isLocked($companyId, $accountingPeriodId),
            'schema_ready' => $this->schemaReadyForWrite(),
        ];
    }

    public function resolveForReporting(int $companyId, int $accountingPeriodId): array
    {
        $currentNominal = $this->liabilityNominal($companyId);
        $periodExists = $this->accountingPeriod($companyId, $accountingPeriodId) !== null;
        $default = [
            'applicable' => $currentNominal !== null && $periodExists,
            'classification' => self::WITHIN_ONE_YEAR,
            'classification_label' => $this->classificationLabel(self::WITHIN_ONE_YEAR),
            'revision' => 0,
            'explicit' => false,
            'liability_nominal_account_id' => (int)($currentNominal['id'] ?? 0),
            'liability_nominal' => $currentNominal ?? [],
            'current_liability_nominal_account_id' => (int)($currentNominal['id'] ?? 0),
            'current_liability_nominal' => $currentNominal ?? [],
            'nominal_mapping_changed' => false,
            'updated_at' => null,
            'updated_by' => null,
            'provenance_version' => self::PROVENANCE_VERSION,
        ];

        if (!$periodExists || !$this->schemaReadyForRead()) {
            return $default;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id, liability_nominal_account_id,
                    classification, revision, created_by, updated_by, created_at, updated_at
             FROM ' . self::PRESENTATION_TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            return $default;
        }

        $classification = (string)($row['classification'] ?? '');
        $storedNominalId = (int)($row['liability_nominal_account_id'] ?? 0);
        $storedNominal = $this->liabilityNominalById($storedNominalId);
        if (!$this->validClassification($classification) || $storedNominal === null) {
            return $default + [
                'applicable' => false,
                'errors' => ['The saved Director Loan reporting presentation has an invalid liability nominal mapping and must be repaired before reporting.'],
            ];
        }

        return [
            'applicable' => true,
            'classification' => $classification,
            'classification_label' => $this->classificationLabel($classification),
            'revision' => max(0, (int)($row['revision'] ?? 0)),
            'explicit' => true,
            'liability_nominal_account_id' => $storedNominalId,
            'liability_nominal' => $storedNominal,
            'current_liability_nominal_account_id' => (int)($currentNominal['id'] ?? 0),
            'current_liability_nominal' => $currentNominal ?? [],
            'nominal_mapping_changed' => $currentNominal !== null
                && $storedNominalId !== (int)$currentNominal['id'],
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'provenance_version' => self::PROVENANCE_VERSION,
        ];
    }

    public function save(
        int $companyId,
        int $accountingPeriodId,
        string $classification,
        string $changedBy = 'web_app'
    ): array {
        $classification = trim($classification);
        if (!$this->validClassification($classification)) {
            return $this->error('Choose whether the Director Loan Liability is due within one year or after more than one year.');
        }
        if (!$this->schemaReadyForWrite()) {
            return $this->error('The Director Loan reporting presentation migration has not been applied.');
        }

        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->error('The selected accounting period could not be found for this company.');
        }

        $changedBy = trim($changedBy);
        if ($changedBy === '') {
            $changedBy = 'web_app';
        }
        $changedBy = substr($changedBy, 0, 100);

        try {
            $result = \InterfaceDB::transaction(function () use (
                $companyId,
                $accountingPeriodId,
                $classification,
                $changedBy
            ): array {
                $suffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
                $existing = \InterfaceDB::fetchOne(
                    'SELECT id, liability_nominal_account_id, classification, revision
                     FROM ' . self::PRESENTATION_TABLE . '
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1' . $suffix,
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                );

                $storedNominalId = is_array($existing)
                    ? (int)($existing['liability_nominal_account_id'] ?? 0)
                    : 0;
                $nominal = $storedNominalId > 0
                    ? $this->liabilityNominalById($storedNominalId)
                    : $this->liabilityNominal($companyId);
                if ($nominal === null) {
                    throw new \RuntimeException(
                        is_array($existing)
                            ? 'The saved Director Loan reporting presentation has an invalid liability nominal mapping.'
                            : 'Map one Director Loan Liability control nominal in Company Nominals before setting its reporting presentation.'
                    );
                }
                $nominalId = (int)$nominal['id'];

                if (!is_array($existing) && $classification === self::WITHIN_ONE_YEAR) {
                    return ['changed' => false, 'revision' => 0];
                }

                $oldNominalId = is_array($existing)
                    ? (int)($existing['liability_nominal_account_id'] ?? 0)
                    : $nominalId;
                $oldClassification = is_array($existing)
                    ? (string)($existing['classification'] ?? self::WITHIN_ONE_YEAR)
                    : self::WITHIN_ONE_YEAR;
                $oldRevision = is_array($existing) ? max(0, (int)($existing['revision'] ?? 0)) : 0;

                if (is_array($existing)
                    && $oldNominalId === $nominalId
                    && $oldClassification === $classification) {
                    return ['changed' => false, 'revision' => $oldRevision];
                }

                $newRevision = $oldRevision + 1;
                if (is_array($existing)) {
                    \InterfaceDB::prepareExecute(
                        'UPDATE ' . self::PRESENTATION_TABLE . '
                         SET liability_nominal_account_id = :nominal_account_id,
                             classification = :classification,
                             revision = :revision,
                             updated_by = :updated_by,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id',
                        [
                            'nominal_account_id' => $nominalId,
                            'classification' => $classification,
                            'revision' => $newRevision,
                            'updated_by' => $changedBy,
                            'id' => (int)$existing['id'],
                        ]
                    );
                } else {
                    \InterfaceDB::prepareExecute(
                        'INSERT INTO ' . self::PRESENTATION_TABLE . ' (
                            company_id, accounting_period_id, liability_nominal_account_id,
                            classification, revision, created_by, updated_by, created_at, updated_at
                         ) VALUES (
                            :company_id, :accounting_period_id, :nominal_account_id,
                            :classification, :revision, :created_by, :updated_by,
                            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $accountingPeriodId,
                            'nominal_account_id' => $nominalId,
                            'classification' => $classification,
                            'revision' => $newRevision,
                            'created_by' => $changedBy,
                            'updated_by' => $changedBy,
                        ]
                    );
                }

                \InterfaceDB::prepareExecute(
                    'INSERT INTO ' . self::AUDIT_TABLE . ' (
                        company_id, accounting_period_id,
                        old_liability_nominal_account_id, new_liability_nominal_account_id,
                        old_classification, new_classification,
                        old_revision, new_revision, changed_by, reason, changed_at
                     ) VALUES (
                        :company_id, :accounting_period_id,
                        :old_nominal_account_id, :new_nominal_account_id,
                        :old_classification, :new_classification,
                        :old_revision, :new_revision, :changed_by, :reason, CURRENT_TIMESTAMP
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'old_nominal_account_id' => $oldNominalId > 0 ? $oldNominalId : null,
                        'new_nominal_account_id' => $nominalId,
                        'old_classification' => $this->validClassification($oldClassification)
                            ? $oldClassification
                            : self::WITHIN_ONE_YEAR,
                        'new_classification' => $classification,
                        'old_revision' => $oldRevision,
                        'new_revision' => $newRevision,
                        'changed_by' => $changedBy,
                        'reason' => 'Director Loan statutory repayment presentation changed.',
                    ]
                );

                return ['changed' => true, 'revision' => $newRevision];
            });
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        $presentation = $this->fetchPresentation($companyId, $accountingPeriodId);
        $presentation['changed'] = !empty($result['changed']);

        return $presentation;
    }

    public function classificationLabel(string $classification): string
    {
        return match ($classification) {
            self::AFTER_MORE_THAN_ONE_YEAR => 'Due after more than one year',
            default => 'Due within one year',
        };
    }

    private function validClassification(string $classification): bool
    {
        return in_array($classification, [
            self::WITHIN_ONE_YEAR,
            self::AFTER_MORE_THAN_ONE_YEAR,
        ], true);
    }

    private function liabilityNominal(int $companyId): ?array
    {
        if ($companyId <= 0) {
            return null;
        }

        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        $nominalId = (int)($controls['liability'] ?? 0);
        if ($nominalId <= 0) {
            return null;
        }

        return $this->liabilityNominalById($nominalId);
    }

    private function liabilityNominalById(int $nominalId): ?array
    {
        if ($nominalId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type
             FROM nominal_accounts
             WHERE id = :id
             LIMIT 1',
            ['id' => $nominalId]
        );
        if (!is_array($row) || (string)($row['account_type'] ?? '') !== 'liability') {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'account_type' => (string)($row['account_type'] ?? ''),
        ];
    }

    private function accountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function schemaReadyForRead(): bool
    {
        return $this->hasColumns(self::PRESENTATION_TABLE, [
            'company_id',
            'accounting_period_id',
            'liability_nominal_account_id',
            'classification',
            'revision',
            'updated_by',
            'updated_at',
        ]);
    }

    private function schemaReadyForWrite(): bool
    {
        return $this->schemaReadyForRead()
            && $this->hasColumns(self::AUDIT_TABLE, [
                'company_id',
                'accounting_period_id',
                'old_liability_nominal_account_id',
                'new_liability_nominal_account_id',
                'old_classification',
                'new_classification',
                'old_revision',
                'new_revision',
                'changed_by',
                'reason',
                'changed_at',
            ]);
    }

    private function hasColumns(string $table, array $columns): bool
    {
        if (!\InterfaceDB::tableExists($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (!\InterfaceDB::columnExists($table, (string)$column)) {
                return false;
            }
        }

        return true;
    }

    private function error(string $message): array
    {
        return [
            'success' => false,
            'available' => false,
            'errors' => [$message],
        ];
    }
}
