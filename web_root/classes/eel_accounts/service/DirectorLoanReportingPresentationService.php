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
    public const PROVENANCE_VERSION = 2;

    private const PRESENTATION_TABLE = 'director_loan_reporting_presentations';
    private const AUDIT_TABLE = 'director_loan_reporting_presentation_audit';
    private const DEFAULT_MAIN_TERMS = 'Unsecured.';
    private const DEFAULT_REPAYMENT_CONDITIONS = 'Repayable on demand.';

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
        $defaultEvidence = $this->defaultEvidence();
        $default = [
            'applicable' => $currentNominal !== null && $periodExists,
            'classification' => self::WITHIN_ONE_YEAR,
            'requested_classification' => self::WITHIN_ONE_YEAR,
            'classification_label' => $this->classificationLabel(self::WITHIN_ONE_YEAR),
            'classification_supported' => true,
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
            'validation_errors' => [],
        ] + $defaultEvidence;

        if (!$periodExists || !$this->schemaReadyForRead()) {
            return $default;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id, liability_nominal_account_id,
                    classification,
                    set_off_right_confirmed, set_off_net_settlement_intended, set_off_evidence,
                    deferment_right_confirmed, deferment_evidence,
                    annual_rate_percent, main_terms, repayment_conditions,
                    revision, created_by, updated_by, created_at, updated_at
             FROM ' . self::PRESENTATION_TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            return $default;
        }

        $requestedClassification = (string)($row['classification'] ?? '');
        $storedNominalId = (int)($row['liability_nominal_account_id'] ?? 0);
        $storedNominal = $this->liabilityNominalById($storedNominalId);
        if (!$this->validClassification($requestedClassification) || $storedNominal === null) {
            return $default + [
                'applicable' => false,
                'errors' => ['The saved Director Loan reporting presentation has an invalid liability nominal mapping and must be repaired before reporting.'],
            ];
        }

        $evidence = $this->evidenceFromRow($row);
        $validationErrors = $this->evidenceValidationErrors($requestedClassification, $evidence);
        $classificationSupported = $requestedClassification !== self::AFTER_MORE_THAN_ONE_YEAR
            || (!empty($evidence['deferment_right_confirmed'])
                && trim((string)$evidence['deferment_evidence']) !== '');
        $classification = $classificationSupported
            ? $requestedClassification
            : self::WITHIN_ONE_YEAR;

        return [
            'applicable' => true,
            'classification' => $classification,
            'requested_classification' => $requestedClassification,
            'classification_label' => $this->classificationLabel($classification),
            'classification_supported' => $classificationSupported,
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
            'validation_errors' => $validationErrors,
        ] + $evidence;
    }

    public function save(
        int $companyId,
        int $accountingPeriodId,
        string $classification,
        string $changedBy = 'web_app',
        array $evidence = []
    ): array {
        $classification = trim($classification);
        if (!$this->validClassification($classification)) {
            return $this->error('Choose whether the Director Loan Liability is due within one year or after more than one year.');
        }
        $evidence = $this->normaliseEvidence($evidence);
        $evidenceErrors = $this->evidenceValidationErrors($classification, $evidence);
        if ($evidenceErrors !== []) {
            return [
                'success' => false,
                'available' => false,
                'errors' => $evidenceErrors,
            ];
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
                $changedBy,
                $evidence
            ): array {
                $suffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
                $existing = \InterfaceDB::fetchOne(
                    'SELECT id, liability_nominal_account_id, classification,
                            set_off_right_confirmed, set_off_net_settlement_intended, set_off_evidence,
                            deferment_right_confirmed, deferment_evidence,
                            annual_rate_percent, main_terms, repayment_conditions,
                            revision
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

                $newEvidenceJson = $this->canonicalEvidenceJson($evidence);
                $defaultEvidenceJson = $this->canonicalEvidenceJson($this->defaultEvidence());
                if (!is_array($existing)
                    && $classification === self::WITHIN_ONE_YEAR
                    && hash_equals($defaultEvidenceJson, $newEvidenceJson)) {
                    return ['changed' => false, 'revision' => 0];
                }

                $oldNominalId = is_array($existing)
                    ? (int)($existing['liability_nominal_account_id'] ?? 0)
                    : $nominalId;
                $oldClassification = is_array($existing)
                    ? (string)($existing['classification'] ?? self::WITHIN_ONE_YEAR)
                    : self::WITHIN_ONE_YEAR;
                $oldEvidence = is_array($existing)
                    ? $this->evidenceFromRow($existing)
                    : $this->defaultEvidence();
                $oldEvidenceJson = $this->canonicalEvidenceJson($oldEvidence);
                $oldRevision = is_array($existing) ? max(0, (int)($existing['revision'] ?? 0)) : 0;

                if (is_array($existing)
                    && $oldNominalId === $nominalId
                    && $oldClassification === $classification
                    && hash_equals($oldEvidenceJson, $newEvidenceJson)) {
                    return ['changed' => false, 'revision' => $oldRevision];
                }

                $newRevision = $oldRevision + 1;
                if (is_array($existing)) {
                    \InterfaceDB::prepareExecute(
                        'UPDATE ' . self::PRESENTATION_TABLE . '
                         SET liability_nominal_account_id = :nominal_account_id,
                             classification = :classification,
                             set_off_right_confirmed = :set_off_right_confirmed,
                             set_off_net_settlement_intended = :set_off_net_settlement_intended,
                             set_off_evidence = :set_off_evidence,
                             deferment_right_confirmed = :deferment_right_confirmed,
                             deferment_evidence = :deferment_evidence,
                             annual_rate_percent = :annual_rate_percent,
                             main_terms = :main_terms,
                             repayment_conditions = :repayment_conditions,
                             revision = :revision,
                             updated_by = :updated_by,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id',
                        [
                            'nominal_account_id' => $nominalId,
                            'classification' => $classification,
                            'set_off_right_confirmed' => !empty($evidence['set_off_right_confirmed']) ? 1 : 0,
                            'set_off_net_settlement_intended' => !empty($evidence['set_off_net_settlement_intended']) ? 1 : 0,
                            'set_off_evidence' => (string)$evidence['set_off_evidence'],
                            'deferment_right_confirmed' => !empty($evidence['deferment_right_confirmed']) ? 1 : 0,
                            'deferment_evidence' => (string)$evidence['deferment_evidence'],
                            'annual_rate_percent' => number_format((float)$evidence['interest_rate_percent'], 4, '.', ''),
                            'main_terms' => (string)$evidence['main_terms'],
                            'repayment_conditions' => (string)$evidence['repayment_conditions'],
                            'revision' => $newRevision,
                            'updated_by' => $changedBy,
                            'id' => (int)$existing['id'],
                        ]
                    );
                } else {
                    \InterfaceDB::prepareExecute(
                        'INSERT INTO ' . self::PRESENTATION_TABLE . ' (
                            company_id, accounting_period_id, liability_nominal_account_id,
                            classification,
                            set_off_right_confirmed, set_off_net_settlement_intended, set_off_evidence,
                            deferment_right_confirmed, deferment_evidence,
                            annual_rate_percent, main_terms, repayment_conditions,
                            revision, created_by, updated_by, created_at, updated_at
                         ) VALUES (
                            :company_id, :accounting_period_id, :nominal_account_id,
                            :classification,
                            :set_off_right_confirmed, :set_off_net_settlement_intended, :set_off_evidence,
                            :deferment_right_confirmed, :deferment_evidence,
                            :annual_rate_percent, :main_terms, :repayment_conditions,
                            :revision, :created_by, :updated_by,
                            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $accountingPeriodId,
                            'nominal_account_id' => $nominalId,
                            'classification' => $classification,
                            'set_off_right_confirmed' => !empty($evidence['set_off_right_confirmed']) ? 1 : 0,
                            'set_off_net_settlement_intended' => !empty($evidence['set_off_net_settlement_intended']) ? 1 : 0,
                            'set_off_evidence' => (string)$evidence['set_off_evidence'],
                            'deferment_right_confirmed' => !empty($evidence['deferment_right_confirmed']) ? 1 : 0,
                            'deferment_evidence' => (string)$evidence['deferment_evidence'],
                            'annual_rate_percent' => number_format((float)$evidence['interest_rate_percent'], 4, '.', ''),
                            'main_terms' => (string)$evidence['main_terms'],
                            'repayment_conditions' => (string)$evidence['repayment_conditions'],
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
                        old_evidence_json, new_evidence_json,
                        old_revision, new_revision, changed_by, reason, changed_at
                     ) VALUES (
                        :company_id, :accounting_period_id,
                        :old_nominal_account_id, :new_nominal_account_id,
                        :old_classification, :new_classification,
                        :old_evidence_json, :new_evidence_json,
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
                        'old_evidence_json' => $oldEvidenceJson,
                        'new_evidence_json' => $newEvidenceJson,
                        'old_revision' => $oldRevision,
                        'new_revision' => $newRevision,
                        'changed_by' => $changedBy,
                        'reason' => 'Director Loan statutory presentation or evidence changed.',
                    ]
                );

                return ['changed' => true, 'revision' => $newRevision];
            });
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        \eel_accounts\Support\RequestCache::forget(
            'director-loan.statement',
            $companyId . ':' . $accountingPeriodId
        );
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

    /** @return array<string, bool|float|string> */
    private function defaultEvidence(): array
    {
        return [
            'set_off_right_confirmed' => false,
            'set_off_net_settlement_intended' => false,
            'set_off_evidence' => '',
            'set_off_permitted' => false,
            'deferment_right_confirmed' => false,
            'deferment_evidence' => '',
            'interest_rate_percent' => 0.0,
            'interest_rate' => '0%',
            'main_terms' => self::DEFAULT_MAIN_TERMS,
            'repayment_conditions' => self::DEFAULT_REPAYMENT_CONDITIONS,
            'main_conditions' => self::DEFAULT_MAIN_TERMS . ' ' . self::DEFAULT_REPAYMENT_CONDITIONS,
        ];
    }

    /** @return array<string, bool|float|string> */
    private function evidenceFromRow(array $row): array
    {
        return $this->normaliseEvidence([
            'set_off_right_confirmed' => $row['set_off_right_confirmed'] ?? false,
            'set_off_net_settlement_intended' => $row['set_off_net_settlement_intended'] ?? false,
            'set_off_evidence' => $row['set_off_evidence'] ?? '',
            'deferment_right_confirmed' => $row['deferment_right_confirmed'] ?? false,
            'deferment_evidence' => $row['deferment_evidence'] ?? '',
            'interest_rate_percent' => $row['annual_rate_percent'] ?? 0,
            'main_terms' => $row['main_terms'] ?? self::DEFAULT_MAIN_TERMS,
            'repayment_conditions' => $row['repayment_conditions'] ?? self::DEFAULT_REPAYMENT_CONDITIONS,
        ]);
    }

    /** @return array<string, bool|float|string> */
    private function normaliseEvidence(array $evidence): array
    {
        $rateValue = $evidence['interest_rate_percent'] ?? 0;
        $interestRate = is_numeric($rateValue) ? round((float)$rateValue, 4) : -1.0;
        $mainTerms = $this->normaliseText(
            $evidence['main_terms'] ?? self::DEFAULT_MAIN_TERMS,
            1000
        );
        $repaymentConditions = $this->normaliseText(
            $evidence['repayment_conditions'] ?? self::DEFAULT_REPAYMENT_CONDITIONS,
            1000
        );
        $rightConfirmed = $this->normaliseBoolean($evidence['set_off_right_confirmed'] ?? false);
        $settlementIntended = $this->normaliseBoolean(
            $evidence['set_off_net_settlement_intended'] ?? false
        );
        $setOffEvidence = $this->normaliseText($evidence['set_off_evidence'] ?? '', 2000);
        $defermentRight = $this->normaliseBoolean($evidence['deferment_right_confirmed'] ?? false);
        $defermentEvidence = $this->normaliseText($evidence['deferment_evidence'] ?? '', 2000);
        $setOffPermitted = $rightConfirmed && $settlementIntended && $setOffEvidence !== '';

        return [
            'set_off_right_confirmed' => $rightConfirmed,
            'set_off_net_settlement_intended' => $settlementIntended,
            'set_off_evidence' => $setOffEvidence,
            'set_off_permitted' => $setOffPermitted,
            'deferment_right_confirmed' => $defermentRight,
            'deferment_evidence' => $defermentEvidence,
            'interest_rate_percent' => $interestRate,
            'interest_rate' => self::formatInterestRate($interestRate),
            'main_terms' => $mainTerms,
            'repayment_conditions' => $repaymentConditions,
            'main_conditions' => trim($mainTerms . ' ' . $repaymentConditions),
        ];
    }

    /** @return list<string> */
    private function evidenceValidationErrors(string $classification, array $evidence): array
    {
        $errors = [];
        $rightConfirmed = !empty($evidence['set_off_right_confirmed']);
        $settlementIntended = !empty($evidence['set_off_net_settlement_intended']);
        if ($rightConfirmed xor $settlementIntended) {
            $errors[] = 'Set-off requires confirmation of both a legally enforceable right and an intention to settle net or simultaneously.';
        }
        if ($rightConfirmed
            && $settlementIntended
            && trim((string)($evidence['set_off_evidence'] ?? '')) === '') {
            $errors[] = 'Describe the evidence supporting the Director Loan set-off conditions.';
        }
        if ($classification === self::AFTER_MORE_THAN_ONE_YEAR) {
            if (empty($evidence['deferment_right_confirmed'])) {
                $errors[] = 'Due after more than one year requires confirmation that the company had an unconditional right at the balance-sheet date to defer payment for at least twelve months.';
            }
            if (trim((string)($evidence['deferment_evidence'] ?? '')) === '') {
                $errors[] = 'Describe the evidence supporting the unconditional right to defer payment.';
            }
        }
        $interestRate = (float)($evidence['interest_rate_percent'] ?? -1);
        if ($interestRate < 0 || $interestRate > 100) {
            $errors[] = 'Enter an interest rate between 0 and 100 percent.';
        }
        if (trim((string)($evidence['main_terms'] ?? '')) === '') {
            $errors[] = 'Enter the main terms of the Director Loan arrangement.';
        }
        if (trim((string)($evidence['repayment_conditions'] ?? '')) === '') {
            $errors[] = 'Enter the Director Loan repayment conditions.';
        }

        return $errors;
    }

    private function canonicalEvidenceJson(array $evidence): string
    {
        $normalised = $this->normaliseEvidence($evidence);
        unset(
            $normalised['set_off_permitted'],
            $normalised['interest_rate'],
            $normalised['main_conditions']
        );
        ksort($normalised);
        return \eel_accounts\Support\Utf8::json(
            $normalised,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function normaliseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normaliseText(mixed $value, int $maximumLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $maximumLength)
            : substr($text, 0, $maximumLength);
    }

    public static function formatInterestRate(float $interestRate): string
    {
        if ($interestRate < 0) {
            return '';
        }
        $label = rtrim(rtrim(number_format($interestRate, 4, '.', ''), '0'), '.');
        return ($label === '' ? '0' : $label) . '%';
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
            'set_off_right_confirmed',
            'set_off_net_settlement_intended',
            'set_off_evidence',
            'deferment_right_confirmed',
            'deferment_evidence',
            'annual_rate_percent',
            'main_terms',
            'repayment_conditions',
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
                'old_evidence_json',
                'new_evidence_json',
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
