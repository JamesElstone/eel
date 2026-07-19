<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds an immutable canonical filing model exclusively from approved, locked Year End evidence. */
final class CtPeriodFilingModelService
{
    public const BASIS_VERSION = 'ct-period-filing-model-v4';
    private const TAX_APPROVAL_CHECK = 'tax_readiness_acknowledgement';
    private const REQUIRED_AUDIT_AREAS = [
        'accounting_profit',
        'expense_treatments',
        'depreciation_capital',
        'capital_allowances',
        'losses',
        'tax_liability',
    ];

    public function build(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        return $this->buildInternal($companyId, $accountingPeriodId, $ctPeriodId, true);
    }

    /** Builds the verified model before its final hash is written during the atomic Year End lock. */
    public function buildForYearEndSeal(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        if (!\InterfaceDB::inTransaction()) {
            return $this->failure('The filing basis can only be sealed inside the Year End lock transaction.');
        }
        return $this->buildInternal($companyId, $accountingPeriodId, $ctPeriodId, false);
    }

    private function buildInternal(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        bool $requireSeal
    ): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            return $this->failure('Select a company, accounting period and CT period.');
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT c.id AS live_company_id, c.company_name, c.company_number,
                    ap.id AS live_accounting_period_id,
                    ap.period_start AS accounting_period_start,
                    ap.period_end AS accounting_period_end,
                    ctp.*,
                    r.id AS run_id,
                    r.company_id AS run_company_id,
                    r.accounting_period_id AS run_accounting_period_id,
                    r.ct_period_id AS run_ct_period_id,
                    r.period_start AS run_period_start,
                    r.period_end AS run_period_end,
                    r.status AS run_status,
                    r.computation_hash,
                    r.summary_json,
                    s.id AS snapshot_id,
                    s.company_id AS snapshot_company_id,
                    s.accounting_period_id AS snapshot_accounting_period_id,
                    s.ct_period_id AS snapshot_ct_period_id,
                    s.basis_version AS snapshot_basis_version,
                    s.basis_hash AS snapshot_basis_hash,
                    yr.is_locked,
                    yr.locked_at,
                    ack.check_code AS approval_check_code,
                    ack.acknowledged_at AS approval_acknowledged_at,
                    ack.basis_version AS approval_basis_version,
                    ack.basis_hash AS approval_basis_hash,
                    ack.basis_json AS approval_basis_json
             FROM corporation_tax_periods ctp
             INNER JOIN companies c ON c.id = ctp.company_id
             INNER JOIN accounting_periods ap
               ON ap.id = ctp.accounting_period_id
              AND ap.company_id = ctp.company_id
             INNER JOIN year_end_reviews yr
               ON yr.company_id = ctp.company_id
              AND yr.accounting_period_id = ctp.accounting_period_id
             LEFT JOIN corporation_tax_computation_runs r
               ON r.id = ctp.latest_computation_run_id
              AND r.company_id = ctp.company_id
              AND r.accounting_period_id = ctp.accounting_period_id
              AND r.ct_period_id = ctp.id
             LEFT JOIN corporation_tax_audit_snapshots s
               ON s.computation_run_id = r.id
              AND s.company_id = r.company_id
              AND s.accounting_period_id = r.accounting_period_id
              AND s.ct_period_id = r.ct_period_id
             LEFT JOIN year_end_review_acknowledgements ack
               ON ack.company_id = ctp.company_id
              AND ack.accounting_period_id = ctp.accounting_period_id
              AND ack.check_code = :approval_check
             WHERE ctp.id = :ct_period_id
               AND ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id
             LIMIT 1',
            [
                'approval_check' => self::TAX_APPROVAL_CHECK,
                'ct_period_id' => $ctPeriodId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        if (!is_array($row)) {
            return $this->failure('The selected CT period is not available in this accounting context.');
        }

        $errors = $this->structuralErrors($row, $companyId, $accountingPeriodId, $ctPeriodId);
        $summary = json_decode((string)($row['summary_json'] ?? ''), true);
        if (!is_array($summary)) {
            $errors[] = 'The locked computation summary is unreadable.';
            $summary = [];
        }
        $seal = is_array($summary['frozen_filing_basis'] ?? null)
            ? (array)$summary['frozen_filing_basis']
            : [];
        unset($summary['frozen_filing_basis']);

        $approvalResult = $this->approvedPeriodBasis($row, $summary, $companyId, $accountingPeriodId, $ctPeriodId);
        array_push($errors, ...(array)($approvalResult['errors'] ?? []));
        $approval = (array)($approvalResult['approval'] ?? []);
        $supportedReturnProfile = (array)($approvalResult['supported_return_profile'] ?? []);
        $filingIdentity = (array)($approvalResult['filing_identity'] ?? []);

        [$blockingDiagnostics, $warningDiagnostics, $diagnosticErrors] = $this->frozenDiagnostics(
            $summary,
            $supportedReturnProfile,
            $ctPeriodId
        );
        array_push($errors, ...$diagnosticErrors);

        $auditResult = $this->verifiedAuditAreas(
            (int)($row['snapshot_id'] ?? 0),
            (string)($row['snapshot_basis_hash'] ?? '')
        );
        array_push($errors, ...(array)($auditResult['errors'] ?? []));
        $areas = (array)($auditResult['areas'] ?? []);

        if ($errors !== []) {
            return [
                'available' => false,
                'errors' => array_values(array_unique(array_map('strval', $errors))),
                'run' => $row,
                'approval' => $approval,
                'supported_return_profile' => $supportedReturnProfile,
                'blocking_diagnostics' => $blockingDiagnostics,
                'warning_diagnostics' => $warningDiagnostics,
            ];
        }

        array_push($errors, ...$this->requiredSummaryErrors($summary));
        if ($errors !== []) {
            return [
                'available' => false,
                'errors' => array_values(array_unique(array_map('strval', $errors))),
                'run' => $row,
                'approval' => $approval,
                'supported_return_profile' => $supportedReturnProfile,
                'blocking_diagnostics' => $blockingDiagnostics,
                'warning_diagnostics' => $warningDiagnostics,
            ];
        }

        $frozenCompany = (array)($filingIdentity['company'] ?? []);
        $frozenAccountingPeriod = (array)($filingIdentity['accounting_period'] ?? []);
        $frozenCtPeriod = (array)($filingIdentity['ct_period'] ?? []);
        $model = [
            'identity' => [
                'company_id' => (int)$frozenCompany['id'],
                'company_name' => (string)$frozenCompany['name'],
                'company_number' => (string)$frozenCompany['number'],
            ],
            'accounting_period' => [
                'id' => (int)$frozenAccountingPeriod['id'],
                'start_date' => (string)$frozenAccountingPeriod['start_date'],
                'end_date' => (string)$frozenAccountingPeriod['end_date'],
            ],
            'ct_period' => [
                'id' => (int)$frozenCtPeriod['id'],
                'start_date' => (string)$frozenCtPeriod['start_date'],
                'end_date' => (string)$frozenCtPeriod['end_date'],
                'sequence_no' => (int)$frozenCtPeriod['sequence_no'],
            ],
            'approval' => $approval,
            'supported_return_profile' => $supportedReturnProfile,
            'diagnostics' => [
                'blocking' => $blockingDiagnostics,
                'warnings' => $warningDiagnostics,
            ],
            'computation' => [
                'run_id' => (int)$row['run_id'],
                'hash' => (string)$row['computation_hash'],
                'summary' => $summary,
            ],
            'audit' => $areas,
        ];
        $facts = [];
        $this->flatten($model, '', $facts);
        $canonical = $this->canonicalJson($model);
        $basisVersion = self::BASIS_VERSION . '+' . (string)$row['snapshot_basis_version'];
        $basisHash = hash(
            'sha256',
            self::BASIS_VERSION . '|' . (string)$row['snapshot_basis_hash'] . '|' . $canonical
        );
        if ($requireSeal) {
            $sealErrors = $this->sealErrors($seal, $row, $approval, $basisVersion, $basisHash);
            if ($sealErrors !== []) {
                return [
                    'available' => false,
                    'errors' => $sealErrors,
                    'run' => $row,
                    'approval' => $approval,
                    'supported_return_profile' => $supportedReturnProfile,
                    'blocking_diagnostics' => $blockingDiagnostics,
                    'warning_diagnostics' => $warningDiagnostics,
                ];
            }
        }

        return [
            'available' => true,
            'errors' => [],
            'run' => $row,
            'approval' => $approval,
            'supported_return_profile' => $supportedReturnProfile,
            'blocking_diagnostics' => $blockingDiagnostics,
            'warning_diagnostics' => $warningDiagnostics,
            'model' => $model,
            'facts' => $facts,
            'basis_version' => $basisVersion,
            'basis_hash' => $basisHash,
            'seal' => $seal,
        ];
    }

    /** @return list<string> */
    private function structuralErrors(array $row, int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $errors = [];
        if (empty($row['is_locked']) || trim((string)($row['locked_at'] ?? '')) === '') {
            $errors[] = 'The accounting period must be locked before a tax computation iXBRL can be generated.';
        }
        if ((int)($row['run_id'] ?? 0) <= 0) {
            $errors[] = 'The CT period has no locked computation run.';
        } elseif ((string)($row['run_status'] ?? '') !== 'generated') {
            $errors[] = 'The current CT computation run has not completed successfully.';
        }
        if ((int)($row['run_company_id'] ?? 0) !== $companyId
            || (int)($row['run_accounting_period_id'] ?? 0) !== $accountingPeriodId
            || (int)($row['run_ct_period_id'] ?? 0) !== $ctPeriodId) {
            $errors[] = 'The locked computation run does not match the selected company, accounting period and CT period.';
        }
        if ((string)($row['run_period_start'] ?? '') !== (string)($row['period_start'] ?? '')
            || (string)($row['run_period_end'] ?? '') !== (string)($row['period_end'] ?? '')) {
            $errors[] = 'The locked computation dates do not match the selected CT period.';
        }
        if (trim((string)($row['computation_hash'] ?? '')) === '') {
            $errors[] = 'The locked computation run has no computation hash.';
        }
        if ((int)($row['snapshot_id'] ?? 0) <= 0) {
            $errors[] = 'The locked computation has no frozen Tax Audit snapshot.';
        }
        if ((int)($row['snapshot_company_id'] ?? 0) !== $companyId
            || (int)($row['snapshot_accounting_period_id'] ?? 0) !== $accountingPeriodId
            || (int)($row['snapshot_ct_period_id'] ?? 0) !== $ctPeriodId) {
            $errors[] = 'The frozen Tax Audit snapshot does not match the selected filing context.';
        }
        if (trim((string)($row['snapshot_basis_version'] ?? '')) === ''
            || trim((string)($row['snapshot_basis_hash'] ?? '')) === '') {
            $errors[] = 'The frozen Tax Audit snapshot has no verifiable basis identity.';
        }

        return $errors;
    }

    /** @return array{approval: array<string, mixed>, supported_return_profile: array<string, mixed>, filing_identity: array<string, mixed>, errors: list<string>} */
    private function approvedPeriodBasis(
        array $row,
        array $summary,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        $errors = [];
        $approval = [];
        if ((string)($row['approval_check_code'] ?? '') !== self::TAX_APPROVAL_CHECK) {
            return [
                'approval' => [],
                'supported_return_profile' => [],
                'filing_identity' => [],
                'errors' => ['The locked accounting period has no approved Corporation Tax readiness basis.'],
            ];
        }

        $storedVersion = trim((string)($row['approval_basis_version'] ?? ''));
        $storedHash = trim((string)($row['approval_basis_hash'] ?? ''));
        if ($storedVersion !== YearEndAcknowledgementService::BASIS_VERSION) {
            $errors[] = 'The Corporation Tax readiness approval basis version is missing or incompatible.';
        }
        if (preg_match('/^[a-f0-9]{64}$/i', $storedHash) !== 1) {
            $errors[] = 'The Corporation Tax readiness approval has no valid basis hash.';
        }

        $basis = json_decode((string)($row['approval_basis_json'] ?? ''), true);
        if (!is_array($basis)) {
            $errors[] = 'The Corporation Tax readiness approval basis is unreadable.';
            $basis = [];
        } elseif ($storedHash !== ''
            && !hash_equals($storedHash, (new YearEndAcknowledgementService())->hashBasis($basis))) {
            $errors[] = 'The Corporation Tax readiness approval basis hash does not match its stored evidence.';
        }
        if ((string)($basis['check_code'] ?? '') !== self::TAX_APPROVAL_CHECK) {
            $errors[] = 'The stored Year End approval is not a Corporation Tax readiness approval.';
        }
        $profileResult = $this->validateSupportedReturnProfile($basis['supported_return_profile'] ?? null);
        array_push($errors, ...(array)($profileResult['errors'] ?? []));
        $supportedReturnProfile = (array)($profileResult['profile'] ?? []);
        $identityResult = $this->validateFilingIdentity(
            $basis['filing_identity'] ?? null,
            $row,
            $companyId,
            $accountingPeriodId,
            $ctPeriodId
        );
        array_push($errors, ...(array)($identityResult['errors'] ?? []));
        $filingIdentity = (array)($identityResult['identity'] ?? []);

        $manifest = is_array($basis['freeze_manifest'] ?? null) ? (array)$basis['freeze_manifest'] : [];
        if ($manifest === []) {
            $errors[] = 'The Corporation Tax readiness approval has no frozen calculation manifest.';
        }
        if ((string)($manifest['basis_version'] ?? '') !== YearEndTaxFreezeService::BASIS_VERSION) {
            $errors[] = 'The approved Corporation Tax freeze basis version is missing or incompatible.';
        }
        if ((int)($manifest['company_id'] ?? 0) !== $companyId
            || (int)($manifest['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
            $errors[] = 'The approved Corporation Tax freeze manifest belongs to a different filing context.';
        }

        $approvedPeriod = null;
        $matches = 0;
        foreach ((array)($manifest['periods'] ?? []) as $period) {
            if (!is_array($period) || (int)($period['ct_period_id'] ?? 0) !== $ctPeriodId) {
                continue;
            }
            $approvedPeriod = $period;
            $matches++;
        }
        if ($matches !== 1 || !is_array($approvedPeriod)) {
            $errors[] = 'The selected CT period is not identified exactly once in the approved Corporation Tax freeze manifest.';
            $approvedPeriod = [];
        } elseif ((string)($approvedPeriod['period_start'] ?? '') !== (string)($row['period_start'] ?? '')
            || (string)($approvedPeriod['period_end'] ?? '') !== (string)($row['period_end'] ?? '')) {
            $errors[] = 'The approved Corporation Tax period dates do not match the selected CT period.';
        }

        $manifestHash = $manifest !== []
            ? (new YearEndAcknowledgementService())->hashBasis($manifest)
            : '';
        if ((string)($summary['year_end_freeze_basis_version'] ?? '') !== YearEndTaxFreezeService::BASIS_VERSION) {
            $errors[] = 'The locked computation is not bound to the approved Year End freeze basis version.';
        }
        if ($manifestHash === ''
            || trim((string)($summary['year_end_freeze_manifest_hash'] ?? '')) === ''
            || !hash_equals($manifestHash, (string)$summary['year_end_freeze_manifest_hash'])) {
            $errors[] = 'The locked computation is not bound to the approved Corporation Tax freeze manifest.';
        }
        if (trim((string)($summary['computation_hash'] ?? '')) === ''
            || !hash_equals((string)($row['computation_hash'] ?? ''), (string)$summary['computation_hash'])) {
            $errors[] = 'The locked computation summary does not match its computation hash identity.';
        }
        if ((int)($summary['accounting_period_id'] ?? 0) !== $accountingPeriodId
            || (int)($summary['ct_period_id'] ?? 0) !== $ctPeriodId
            || (string)($summary['period_start'] ?? '') !== (string)($row['period_start'] ?? '')
            || (string)($summary['period_end'] ?? '') !== (string)($row['period_end'] ?? '')) {
            $errors[] = 'The locked computation summary identity and dates do not match the approved CT period.';
        }

        $manifestBlockers = $manifest['blocking_diagnostic_codes'] ?? null;
        $periodBlockers = $approvedPeriod['blocking_diagnostic_codes'] ?? null;
        if (!is_array($manifestBlockers) || !is_array($periodBlockers)) {
            $errors[] = 'The approved Corporation Tax freeze manifest has no structured diagnostic result.';
        } elseif ($manifestBlockers !== [] || $periodBlockers !== []) {
            $errors[] = 'The approved Corporation Tax freeze manifest contains unresolved blocking diagnostics.';
        }

        $approval = [
            'check_code' => self::TAX_APPROVAL_CHECK,
            'year_end_locked_at' => (string)($row['locked_at'] ?? ''),
            'acknowledged_at' => (string)($row['approval_acknowledged_at'] ?? ''),
            'basis_version' => $storedVersion,
            'basis_hash' => $storedHash,
            'freeze_basis_version' => (string)($manifest['basis_version'] ?? ''),
            'freeze_manifest_hash' => $manifestHash,
            'approved_ct_period_id' => (int)($approvedPeriod['ct_period_id'] ?? 0),
        ];

        return [
            'approval' => $approval,
            'supported_return_profile' => $supportedReturnProfile,
            'filing_identity' => $filingIdentity,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array{identity: array<string, mixed>, errors: list<string>} */
    private function validateFilingIdentity(
        mixed $value,
        array $row,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        if (!is_array($value)) {
            return ['identity' => [], 'errors' => ['The approved Year End basis has no frozen filing identity.']];
        }

        $errors = [];
        $company = is_array($value['company'] ?? null) ? (array)$value['company'] : [];
        $accountingPeriod = is_array($value['accounting_period'] ?? null) ? (array)$value['accounting_period'] : [];
        if ((int)($company['id'] ?? 0) !== $companyId
            || trim((string)($company['name'] ?? '')) === ''
            || trim((string)($company['number'] ?? '')) === '') {
            $errors[] = 'The approved frozen company identity is missing or inconsistent.';
        }
        if ((int)($accountingPeriod['id'] ?? 0) !== $accountingPeriodId
            || !$this->validDate((string)($accountingPeriod['start_date'] ?? ''))
            || !$this->validDate((string)($accountingPeriod['end_date'] ?? ''))
            || (string)($accountingPeriod['start_date'] ?? '') !== (string)($row['accounting_period_start'] ?? '')
            || (string)($accountingPeriod['end_date'] ?? '') !== (string)($row['accounting_period_end'] ?? '')) {
            $errors[] = 'The approved frozen accounting-period identity or dates are missing or inconsistent.';
        }

        $matchedPeriod = null;
        $matches = 0;
        foreach ((array)($value['ct_periods'] ?? []) as $period) {
            if (!is_array($period) || (int)($period['id'] ?? 0) !== $ctPeriodId) {
                continue;
            }
            $matchedPeriod = $period;
            $matches++;
        }
        if ($matches !== 1 || !is_array($matchedPeriod)
            || (int)($matchedPeriod['sequence_no'] ?? 0) <= 0
            || (string)($matchedPeriod['start_date'] ?? '') !== (string)($row['period_start'] ?? '')
            || (string)($matchedPeriod['end_date'] ?? '') !== (string)($row['period_end'] ?? '')
            || !$this->validDate((string)($matchedPeriod['start_date'] ?? ''))
            || !$this->validDate((string)($matchedPeriod['end_date'] ?? ''))) {
            $errors[] = 'The approved frozen CT-period identity or dates are missing or inconsistent.';
            $matchedPeriod = [];
        }

        return [
            'identity' => [
                'company' => $company,
                'accounting_period' => $accountingPeriod,
                'ct_period' => $matchedPeriod,
            ],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>} */
    private function frozenDiagnostics(array $summary, array $supportedReturnProfile, int $ctPeriodId): array
    {
        $errors = [];
        if (!array_key_exists('hard_gate_diagnostics', $summary)
            || !is_array($summary['hard_gate_diagnostics'])) {
            $errors[] = 'The locked computation has no frozen structured hard-gate diagnostic result.';
        }

        $blocking = [];
        foreach ((array)($summary['hard_gate_diagnostics'] ?? []) as $diagnostic) {
            if (!is_array($diagnostic)) {
                $errors[] = 'A frozen hard-gate diagnostic is unreadable.';
                continue;
            }
            $blocking[] = $this->normaliseDiagnostic($diagnostic, $ctPeriodId, 'hard_failure', 'tax_computation');
        }

        foreach ((array)($supportedReturnProfile['failed_checks'] ?? []) as $failedCheck) {
            if (!is_array($failedCheck)) {
                $failedCheck = ['message' => trim((string)$failedCheck)];
            }
            $blocking[] = $this->normaliseDiagnostic(
                $failedCheck,
                $ctPeriodId,
                'hard_failure',
                'supported_return_profile'
            );
        }

        $blocking = $this->uniqueDiagnostics($blocking);
        if ($blocking !== []) {
            foreach ($blocking as $diagnostic) {
                $message = trim((string)($diagnostic['message'] ?? ''));
                $errors[] = $message !== ''
                    ? $message
                    : 'The approved locked computation contains an unresolved blocking diagnostic.';
            }
        }

        $warnings = [];
        foreach ((array)($summary['warnings'] ?? []) as $warning) {
            $diagnostic = is_array($warning) ? $warning : ['message' => trim((string)$warning)];
            if (trim((string)($diagnostic['message'] ?? $diagnostic['detail'] ?? '')) === '') {
                continue;
            }
            $warnings[] = $this->normaliseDiagnostic($diagnostic, $ctPeriodId, 'warning', 'tax_computation');
        }

        return [$blocking, $this->uniqueDiagnostics($warnings), array_values(array_unique($errors))];
    }

    /** @return array{profile: array<string, mixed>, errors: list<string>} */
    private function validateSupportedReturnProfile(mixed $value): array
    {
        if (!is_array($value)) {
            return [
                'profile' => [],
                'errors' => ['The approved Year End basis has no supported-return-profile assessment. Unlock, review and approve Year End again.'],
            ];
        }

        $errors = [];
        if ((string)($value['profile_code'] ?? '') !== Frs105YearEndProfileService::RETURN_PROFILE_CODE) {
            $errors[] = 'The approved supported-return-profile code is missing or incompatible.';
        }
        if ((string)($value['profile_version'] ?? '') !== Frs105YearEndProfileService::RETURN_PROFILE_VERSION) {
            $errors[] = 'The approved supported-return-profile version is missing or incompatible.';
        }
        if (($value['ordinary_trading_company_confirmed'] ?? null) !== true) {
            $errors[] = 'The approved Year End basis does not explicitly confirm an ordinary trading company.';
        }
        if (($value['supported'] ?? null) !== true) {
            $errors[] = 'The approved Year End basis does not support this CT600 return profile.';
        }

        $checkResults = $value['check_results'] ?? null;
        if (!is_array($checkResults)) {
            $errors[] = 'The approved supported-return-profile check results are missing or unreadable.';
        } else {
            foreach (Frs105YearEndProfileService::RETURN_PROFILE_CHECK_CODES as $code) {
                if (($checkResults[$code] ?? null) !== true) {
                    $errors[] = 'The approved supported-return-profile check is missing or unresolved: ' . $code . '.';
                }
            }
        }

        if (!is_array($value['failed_checks'] ?? null)) {
            $errors[] = 'The approved supported-return-profile diagnostics are missing or unreadable.';
        } elseif ($value['failed_checks'] !== []) {
            $errors[] = 'The approved supported-return-profile assessment contains unresolved checks.';
        }

        return ['profile' => $value, 'errors' => array_values(array_unique($errors))];
    }

    /** @return array<string, mixed> */
    private function normaliseDiagnostic(
        array $diagnostic,
        int $ctPeriodId,
        string $defaultSeverity,
        string $defaultCategory
    ): array {
        $message = trim((string)($diagnostic['message'] ?? $diagnostic['detail'] ?? ''));
        $code = trim((string)($diagnostic['code'] ?? ''));
        if ($code === '') {
            $prefix = $defaultSeverity === 'warning' ? 'frozen_warning_' : 'frozen_diagnostic_';
            $code = $prefix . substr(hash('sha256', $message), 0, 12);
        }

        return array_replace($diagnostic, [
            'code' => $code,
            'category' => trim((string)($diagnostic['category'] ?? '')) !== ''
                ? (string)$diagnostic['category']
                : $defaultCategory,
            'severity' => trim((string)($diagnostic['severity'] ?? '')) !== ''
                ? (string)$diagnostic['severity']
                : $defaultSeverity,
            'message' => $message,
            'workflow_page' => trim((string)($diagnostic['workflow_page'] ?? '')) !== ''
                ? (string)$diagnostic['workflow_page']
                : 'corporation_tax',
            'workflow_fields' => is_array($diagnostic['workflow_fields'] ?? null)
                ? (array)$diagnostic['workflow_fields']
                : ['ct_period_id' => (string)$ctPeriodId],
        ]);
    }

    /** @param list<array<string, mixed>> $diagnostics @return list<array<string, mixed>> */
    private function uniqueDiagnostics(array $diagnostics): array
    {
        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            $unique[(string)$diagnostic['code']] = $diagnostic;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    /** @return array{areas: array<string, mixed>, errors: list<string>} */
    private function verifiedAuditAreas(int $snapshotId, string $snapshotBasisHash): array
    {
        if ($snapshotId <= 0) {
            return ['areas' => [], 'errors' => []];
        }
        $areaRows = \InterfaceDB::fetchAll(
            'SELECT * FROM corporation_tax_audit_areas WHERE snapshot_id = :snapshot_id ORDER BY id',
            ['snapshot_id' => $snapshotId]
        );
        $areas = [];
        $areaHashes = [];
        $errors = [];
        foreach ($areaRows as $area) {
            $code = trim((string)($area['area_code'] ?? ''));
            if ($code === '' || isset($areas[$code])) {
                $errors[] = 'The frozen Tax Audit snapshot contains an invalid or duplicate area code.';
                continue;
            }
            $detail = json_decode((string)($area['detail_json'] ?? ''), true);
            if (!is_array($detail)) {
                $errors[] = 'The frozen ' . $code . ' audit schedule is unreadable.';
                continue;
            }
            if ((string)($detail['area_code'] ?? '') !== $code) {
                $errors[] = 'The frozen ' . $code . ' audit schedule has an inconsistent area identity.';
            }
            if ((string)($area['reconciliation_status'] ?? '') !== 'reconciled'
                || abs((float)($area['reconciliation_difference'] ?? 0)) >= 0.005
                || (string)($detail['reconciliation_status'] ?? '') !== 'reconciled'
                || abs((float)($detail['reconciliation_difference'] ?? 0)) >= 0.005) {
                $errors[] = 'The frozen ' . $code . ' schedule does not cross-cast to the locked computation.';
            }
            $hashBasis = $detail;
            unset($hashBasis['area_hash'], $hashBasis['pagination']);
            $calculatedHash = hash('sha256', $this->canonicalJson($hashBasis));
            if (trim((string)($area['area_hash'] ?? '')) === ''
                || trim((string)($detail['area_hash'] ?? '')) === ''
                || !hash_equals((string)$area['area_hash'], (string)$detail['area_hash'])
                || !hash_equals((string)$area['area_hash'], $calculatedHash)) {
                $errors[] = 'The frozen ' . $code . ' audit schedule hash is invalid.';
            }
            $areas[$code] = $detail;
            $areaHashes[$code] = (string)($area['area_hash'] ?? '');
        }
        foreach (self::REQUIRED_AUDIT_AREAS as $requiredArea) {
            if (!isset($areas[$requiredArea])) {
                $errors[] = 'The frozen filing basis is missing the ' . $requiredArea . ' schedule.';
            }
        }
        if ($areaHashes !== []) {
            $calculatedBasisHash = hash('sha256', $this->canonicalJson($areaHashes));
            if (trim($snapshotBasisHash) === '' || !hash_equals($snapshotBasisHash, $calculatedBasisHash)) {
                $errors[] = 'The frozen Tax Audit snapshot basis hash is invalid.';
            }
        }

        return ['areas' => $areas, 'errors' => array_values(array_unique($errors))];
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

    /** @return list<string> */
    private function requiredSummaryErrors(array $summary): array
    {
        $errors = [];
        if (($summary['available'] ?? null) !== true) {
            $errors[] = 'The frozen computation is not explicitly marked available.';
        }
        foreach (['accounting_period_id', 'ct_period_id'] as $field) {
            if (!is_int($summary[$field] ?? null) || (int)$summary[$field] <= 0) {
                $errors[] = 'The frozen computation is missing the required identifier: ' . $field . '.';
            }
        }
        foreach (['period_start', 'period_end'] as $field) {
            if (!$this->validDate((string)($summary[$field] ?? ''))) {
                $errors[] = 'The frozen computation is missing the required date: ' . $field . '.';
            }
        }
        foreach ([
            'accounting_profit',
            'disallowable_add_backs',
            'capital_add_backs',
            'depreciation_add_back',
            'capital_allowances',
            'taxable_before_losses',
            'taxable_profit',
            'ordinary_corporation_tax',
            's455_tax',
            'estimated_corporation_tax',
        ] as $field) {
            if (!is_int($summary[$field] ?? null) && !is_float($summary[$field] ?? null)) {
                $errors[] = 'The frozen computation is missing the required monetary fact: ' . $field . '.';
            }
        }
        foreach (['hard_gate_diagnostics', 'warnings'] as $field) {
            if (!array_key_exists($field, $summary) || !is_array($summary[$field])) {
                $errors[] = 'The frozen computation is missing the structured ' . $field . ' result.';
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/i', trim((string)($summary['computation_hash'] ?? ''))) !== 1) {
            $errors[] = 'The frozen computation summary has no valid computation hash.';
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function sealErrors(
        array $seal,
        array $row,
        array $approval,
        string $basisVersion,
        string $basisHash
    ): array {
        if ($seal === []) {
            return ['The locked computation has no frozen filing-basis seal. Unlock, review and lock Year End again.'];
        }
        $errors = [];
        $expected = [
            'basis_version' => $basisVersion,
            'basis_hash' => $basisHash,
            'approval_basis_version' => (string)($approval['basis_version'] ?? ''),
            'approval_basis_hash' => (string)($approval['basis_hash'] ?? ''),
            'freeze_manifest_hash' => (string)($approval['freeze_manifest_hash'] ?? ''),
            'computation_run_id' => (int)($row['run_id'] ?? 0),
            'computation_hash' => (string)($row['computation_hash'] ?? ''),
            'tax_audit_snapshot_id' => (int)($row['snapshot_id'] ?? 0),
            'tax_audit_basis_version' => (string)($row['snapshot_basis_version'] ?? ''),
            'tax_audit_basis_hash' => (string)($row['snapshot_basis_hash'] ?? ''),
        ];
        foreach ($expected as $field => $value) {
            $stored = $seal[$field] ?? null;
            $matches = is_int($value)
                ? is_int($stored) && $stored === $value
                : is_string($stored) && $stored !== '' && hash_equals($value, $stored);
            if (!$matches) {
                $errors[] = 'The frozen filing-basis seal is missing or inconsistent: ' . $field . '.';
            }
        }

        return $errors;
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        try {
            $date = new \DateTimeImmutable($value);
            return $date->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
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
        $json = json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('The canonical CT-period filing basis could not be encoded.');
        }
        return $json;
    }

    private function failure(string $message): array
    {
        return [
            'available' => false,
            'errors' => [$message],
            'approval' => [],
            'blocking_diagnostics' => [],
            'warning_diagnostics' => [],
        ];
    }
}
