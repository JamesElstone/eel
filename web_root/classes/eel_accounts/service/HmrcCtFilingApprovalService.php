<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Freezes the HMRC-specific Corporation Tax filing decision independently of
 * the statutory-accounts approval that supplies its accounts facts.
 */
final class HmrcCtFilingApprovalService
{
    public const BASIS_VERSION = 'hmrc-ct-filing-approval-v1';

    /** @return array<string,mixed> */
    public function status(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->statusResult('absent', false, null, null, [
                'Select a company and accounting period.',
            ]);
        }

        $nativeSchemaAvailable = $this->schemaReady();
        $latest = $nativeSchemaAvailable
            ? $this->latestNativeApproval($companyId, $accountingPeriodId)
            : null;

        try {
            $candidate = $this->candidate($companyId, $accountingPeriodId, false);
        } catch (\Throwable $exception) {
            return $this->statusResult(
                $latest === null ? 'absent' : 'stale',
                false,
                $latest,
                null,
                [$exception->getMessage()],
                $latest,
                $latest === null ? 'none' : 'native'
            );
        }

        if ($nativeSchemaAvailable) {
            $matching = $this->matchingNativeApproval(
                $companyId,
                $accountingPeriodId,
                $candidate
            );
            if ($matching !== null) {
                return $this->statusResult(
                    'current',
                    false,
                    $matching,
                    $candidate,
                    [],
                    $latest,
                    'native'
                );
            }
        }

        // Before the split, the accounts approval also froze the CT return.
        // Recognise that evidence without manufacturing a native approval row.
        if ($latest === null) {
            $legacy = $this->legacyAdapter((array)$candidate['accounts_approval'], $candidate);
            if ($legacy !== null) {
                return $this->statusResult(
                    'current',
                    $nativeSchemaAvailable,
                    $legacy,
                    $candidate,
                    [],
                    null,
                    'legacy_combined'
                );
            }
        }

        $errors = $nativeSchemaAvailable
            ? $this->staleErrors($latest, (array)$candidate['basis'])
            : ['Apply the separate HMRC CT filing approval migration before approving a new return.'];
        return $this->statusResult(
            $latest === null ? 'absent' : 'stale',
            $nativeSchemaAvailable,
            $latest,
            $candidate,
            $errors,
            $latest,
            $latest === null ? 'none' : 'native'
        );
    }

    /**
     * Returns the current native approval or a clearly labelled legacy adapter.
     * An empty array means that the HMRC filing decision is not current.
     *
     * @return array<string,mixed>
     */
    public function current(int $companyId, int $accountingPeriodId): array
    {
        $status = $this->status($companyId, $accountingPeriodId);
        return ($status['state'] ?? '') === 'current' && is_array($status['approval'] ?? null)
            ? (array)$status['approval']
            : [];
    }

    /** Verifies that an existing approval froze the exact declaration returned to this request. */
    public function approvalMatchesAuthorisation(array $approval, array $authorisation): bool
    {
        $storedJson = (string)($approval['return_authorisation_json'] ?? '');
        $storedHash = strtolower(trim((string)($approval['return_authorisation_hash'] ?? '')));
        if ($storedJson === ''
            || preg_match('/^[a-f0-9]{64}$/D', $storedHash) !== 1
            || !hash_equals($storedHash, hash('sha256', $storedJson))) {
            return false;
        }

        return hash_equals(
            $storedJson,
            $this->canonicalJson($this->authorisationSnapshot($authorisation))
        );
    }

    /**
     * Atomically records a native HMRC CT approval and binds the current
     * immutable CT-period filing bases to it.
     *
     * @return array{approval_id:int,approval_hash:string,ct_basis_ids:list<int>}
     */
    public function approve(
        int $companyId,
        int $accountingPeriodId,
        string $approvedBy,
        string $note = '',
        array $ctBasisIds = [],
        ?array $expectedAuthorisation = null
    ): array {
        $this->assertSchemaReady();
        $approvedBy = trim($approvedBy);
        if ($approvedBy === '') {
            throw new \RuntimeException('The HMRC Corporation Tax approval must identify its approver.');
        }
        return (array)\InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $approvedBy,
            $note,
            $ctBasisIds,
            $expectedAuthorisation
        ): array {
            $candidate = $this->candidate(
                $companyId,
                $accountingPeriodId,
                true,
                $ctBasisIds,
                $expectedAuthorisation
            );
            if ($this->matchingNativeApproval($companyId, $accountingPeriodId, $candidate) !== null) {
                throw new \RuntimeException('This HMRC Corporation Tax filing basis is already approved and current.');
            }

            $authorisation = (array)$candidate['return_authorisation'];
            $scope = (array)$candidate['ct_scope'];
            $accounts = (array)$candidate['accounts_approval'];
            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_filing_approvals (
                    company_id, accounting_period_id,
                    accounts_filing_approval_id, accounts_filing_approval_hash,
                    return_authorisation_id, return_authorisation_hash, return_authorisation_json,
                    ct_scope_hash, ct_scope_json,
                    basis_version, basis_hash, basis_json,
                    approved_by, approval_note, legacy_combined_approval_id
                 ) VALUES (
                    :company_id, :accounting_period_id,
                    :accounts_approval_id, :accounts_approval_hash,
                    :authorisation_id, :authorisation_hash, :authorisation_json,
                    :scope_hash, :scope_json,
                    :basis_version, :basis_hash, :basis_json,
                    :approved_by, :approval_note, :legacy_combined_approval_id
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'accounts_approval_id' => (int)$accounts['id'],
                    'accounts_approval_hash' => (string)$accounts['basis_hash'],
                    'authorisation_id' => (int)$authorisation['id'],
                    'authorisation_hash' => (string)$candidate['return_authorisation_hash'],
                    'authorisation_json' => (string)$candidate['return_authorisation_json'],
                    'scope_hash' => (string)$candidate['ct_scope_hash'],
                    'scope_json' => (string)$candidate['ct_scope_json'],
                    'basis_version' => self::BASIS_VERSION,
                    'basis_hash' => (string)$candidate['basis_hash'],
                    'basis_json' => (string)$candidate['basis_json'],
                    'approved_by' => $approvedBy,
                    'approval_note' => trim($note) !== '' ? trim($note) : null,
                    'legacy_combined_approval_id' => $this->isLegacyCombinedApproval($accounts)
                        ? (int)$accounts['id']
                        : null,
                ]
            );
            $approvalId = $this->lastInsertId();
            if ($approvalId <= 0) {
                throw new \RuntimeException('The HMRC Corporation Tax approval could not be persisted.');
            }

            $ctBasisIds = [];
            foreach ((array)$candidate['ct_period_bases'] as $basis) {
                $basisId = (int)($basis['id'] ?? 0);
                if ($basisId <= 0) {
                    throw new \RuntimeException('A current CT-period filing basis has no immutable identifier.');
                }
                \InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_filing_approval_period_bases (
                        hmrc_ct_filing_approval_id, ct_period_filing_basis_id,
                        ct_period_id, basis_hash
                     ) VALUES (
                        :hmrc_approval_id, :basis_id, :ct_period_id, :basis_hash
                     )',
                    [
                        'hmrc_approval_id' => $approvalId,
                        'basis_id' => $basisId,
                        'ct_period_id' => (int)$basis['ct_period_id'],
                        'basis_hash' => strtolower(trim((string)$basis['basis_hash'])),
                    ]
                );
                $ctBasisIds[] = $basisId;
            }

            $this->verifyPersisted($approvalId, $candidate, $ctBasisIds);
            $current = $this->candidate(
                $companyId,
                $accountingPeriodId,
                true,
                $ctBasisIds,
                $expectedAuthorisation
            );
            if (!hash_equals((string)$candidate['basis_hash'], (string)$current['basis_hash'])
                || !hash_equals((string)$candidate['basis_json'], (string)$current['basis_json'])) {
                throw new \RuntimeException(
                    'The HMRC Corporation Tax basis changed while it was being approved. No approval was saved.'
                );
            }

            return [
                'approval_id' => $approvalId,
                'approval_hash' => (string)$candidate['basis_hash'],
                'ct_basis_ids' => $ctBasisIds,
            ];
        });
    }

    /** @return array<string,mixed> */
    /** @param list<int> $ctBasisIds */
    private function candidate(
        int $companyId,
        int $accountingPeriodId,
        bool $lock,
        array $ctBasisIds = [],
        ?array $expectedAuthorisation = null
    ): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \RuntimeException('Select a company and accounting period.');
        }

        $accounts = $this->currentAccountsApproval($companyId, $accountingPeriodId, $lock);
        if ($lock && \InterfaceDB::driverName() !== 'sqlite'
            && \InterfaceDB::tableExists('ct600_return_authorisations')) {
            \InterfaceDB::fetchOne(
                'SELECT id FROM ct600_return_authorisations
                 WHERE company_id = :company_id AND accounting_period_id = :period_id FOR UPDATE',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
        }
        if ($lock && \InterfaceDB::driverName() !== 'sqlite'
            && \InterfaceDB::tableExists('corporation_tax_scope_confirmations')) {
            \InterfaceDB::fetchOne(
                'SELECT company_id FROM corporation_tax_scope_confirmations
                 WHERE company_id = :company_id AND accounting_period_id = :period_id FOR UPDATE',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
        }

        $authorisationRow = (new Ct600ReturnAuthorisationService())->current(
            $companyId,
            $accountingPeriodId
        );
        if ($authorisationRow === []) {
            throw new \RuntimeException(
                'Complete and save the Corporation Tax return authorisation before approving the HMRC filing basis.'
            );
        }
        $authorisation = $this->authorisationSnapshot($authorisationRow);
        if (is_array($expectedAuthorisation)) {
            $expectedSnapshot = $this->authorisationSnapshot($expectedAuthorisation);
            if (!hash_equals(
                $this->canonicalJson($expectedSnapshot),
                $this->canonicalJson($authorisation)
            )) {
                throw new \RuntimeException(
                    'The Corporation Tax return authorisation changed before HMRC approval. Review and approve it again.'
                );
            }
        }
        $authorisationJson = $this->canonicalJson($authorisation);
        $authorisationHash = hash('sha256', $authorisationJson);

        $scopeStatus = (new CorporationTaxFilingScopeService())->fetch($companyId, $accountingPeriodId);
        if (empty($scopeStatus['available']) || empty($scopeStatus['complete'])) {
            throw new \RuntimeException((string)(($scopeStatus['errors'] ?? [])[0]
                ?? 'Complete the Corporation Tax supplementary-page scope review.'));
        }
        $scope = (array)($scopeStatus['basis'] ?? []);
        $scopeJson = $this->canonicalJson($scope);
        $scopeHash = hash('sha256', $scopeJson);
        if (!hash_equals($scopeHash, strtolower(trim((string)($scopeStatus['basis_hash'] ?? ''))))) {
            throw new \RuntimeException('The Corporation Tax filing-scope snapshot failed its integrity check.');
        }

        $profileStatus = (new Frs105YearEndProfileService())->fetch($companyId, $accountingPeriodId);
        if (empty($profileStatus['available']) || empty($profileStatus['pass'])) {
            throw new \RuntimeException((string)(($profileStatus['errors'] ?? [])[0]
                ?? 'The supported Corporation Tax return profile is not available.'));
        }
        \eel_accounts\Support\RequestCache::forget(
            'company-settings.rows',
            (string)$companyId
        );
        \eel_accounts\Support\RequestCache::forgetNamespace('tax.ct600a');
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $utr = preg_replace('/\s+/', '', trim((string)($settings['utr'] ?? ''))) ?? '';
        $ctBasisScope = [
            'scope_version' => CorporationTaxFilingScopeService::SCOPE_VERSION,
            'revision' => (int)($scopeStatus['revision'] ?? 0),
            'answers' => (array)($scopeStatus['answers'] ?? []),
            'basis_hash' => (string)($scopeStatus['basis_hash'] ?? ''),
        ];

        $periodBases = $this->currentPeriodBases(
            $companyId,
            $accountingPeriodId,
            (int)$accounts['id'],
            (string)$accounts['basis_hash'],
            $lock,
            $ctBasisIds,
            [
                'utr' => $utr,
                'ct_scope' => $ctBasisScope,
                'supported_return_profile' => (array)$profileStatus['supported_return_profile'],
            ]
        );
        $periodReferences = array_map(static fn(array $basis): array => [
            'id' => (int)$basis['id'],
            'ct_period_id' => (int)$basis['ct_period_id'],
            'sequence_no' => (int)$basis['sequence_no'],
            'period_start' => (string)$basis['period_start'],
            'period_end' => (string)$basis['period_end'],
            'computation_run_id' => (int)$basis['computation_run_id'],
            'computation_hash' => (string)$basis['computation_hash'],
            'calculation_basis_version' => (string)$basis['calculation_basis_version'],
            'calculation_basis_hash' => (string)$basis['calculation_basis_hash'],
            'basis_version' => (string)$basis['basis_version'],
            'basis_hash' => (string)$basis['basis_hash'],
        ], $periodBases);

        $basis = [
            'basis_version' => self::BASIS_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'accounts_filing_approval' => [
                'id' => (int)$accounts['id'],
                'basis_version' => (string)$accounts['basis_version'],
                'basis_hash' => (string)$accounts['basis_hash'],
            ],
            'return_authorisation' => $authorisation,
            'return_authorisation_hash' => $authorisationHash,
            'corporation_tax_filing_scope' => $scope,
            'corporation_tax_filing_scope_hash' => $scopeHash,
            'ct_period_filing_bases' => $periodReferences,
        ];
        $basisJson = $this->canonicalJson($basis);

        return [
            'basis' => $basis,
            'basis_json' => $basisJson,
            'basis_hash' => hash('sha256', $basisJson),
            'accounts_approval' => $accounts,
            'return_authorisation' => $authorisation,
            'return_authorisation_json' => $authorisationJson,
            'return_authorisation_hash' => $authorisationHash,
            'ct_scope' => $scope,
            'ct_scope_json' => $scopeJson,
            'ct_scope_hash' => $scopeHash,
            'ct_period_bases' => $periodBases,
        ];
    }

    /** @return array<string,mixed> */
    private function currentAccountsApproval(int $companyId, int $accountingPeriodId, bool $lock): array
    {
        if (!\InterfaceDB::tableExists('ixbrl_accounts_filing_approvals')) {
            throw new \RuntimeException('Approve the statutory accounts before approving the HMRC filing basis.');
        }
        $suffix = $lock && \InterfaceDB::driverName() !== 'sqlite' ? ' FOR UPDATE' : '';
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM ixbrl_accounts_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id DESC' . $suffix,
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if ($rows === []) {
            throw new \RuntimeException('Approve the statutory accounts before approving the HMRC filing basis.');
        }

        $yearEnd = \InterfaceDB::fetchOne(
            'SELECT id, is_locked, locked_at FROM year_end_reviews
             WHERE company_id = :company_id AND accounting_period_id = :period_id LIMIT 1' . $suffix,
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        $disclosure = \InterfaceDB::fetchOne(
            'SELECT id, revision FROM ixbrl_accounts_disclosures
             WHERE company_id = :company_id AND accounting_period_id = :period_id LIMIT 1' . $suffix,
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($yearEnd) || empty($yearEnd['is_locked'])
            || !is_array($disclosure)) {
            throw new \RuntimeException('The approved statutory-accounts basis is no longer current.');
        }

        // Reuse the statutory-accounts service's exact native/legacy matching
        // contract. In particular, only its legacy path may reconstruct the
        // former Companies House-dependent report hash before projecting it
        // onto the neutral v9 accounts basis.
        \eel_accounts\Support\RequestCache::forget(
            'ixbrl.filing-approval.status',
            \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId)
        );
        $accountsStatus = (new IxbrlAccountsFilingApprovalService())->status(
            $companyId,
            $accountingPeriodId
        );
        $current = is_array($accountsStatus['approval'] ?? null)
            ? (array)$accountsStatus['approval']
            : [];
        $currentId = !empty($accountsStatus['current']) ? (int)($current['id'] ?? 0) : 0;
        foreach ($rows as $row) {
            $rowJson = (string)($row['basis_json'] ?? '');
            $rowHash = strtolower(trim((string)($row['basis_hash'] ?? '')));
            $rowBasis = $rowJson !== '' ? json_decode($rowJson, true) : null;
            if ($currentId > 0
                && (int)($row['id'] ?? 0) === $currentId
                && is_array($rowBasis)
                && preg_match('/^[a-f0-9]{64}$/D', $rowHash) === 1
                && hash_equals($rowHash, hash('sha256', $rowJson))
                && $this->isRecognisedAccountsApproval($row, $rowBasis)
                && hash_equals(
                    $rowHash,
                    strtolower(trim((string)($current['basis_hash'] ?? '')))
                )
                && hash_equals(
                    (string)($row['basis_json'] ?? ''),
                    (string)($current['basis_json'] ?? '')
                )) {
                return $row;
            }
        }

        throw new \RuntimeException(
            'The statutory accounts changed after their approval. Approve the current statutory-accounts basis first.'
        );
    }

    private function isRecognisedAccountsApproval(array $approval, array $basis): bool
    {
        $version = trim((string)($approval['basis_version'] ?? ''));
        if ($version === IxbrlAccountsFilingApprovalService::BASIS_VERSION) {
            return (string)($basis['basis_version'] ?? '') === $version;
        }

        return preg_match('/^accounts-filing-approval-v[1-8]$/D', $version) === 1
            && (string)($basis['basis_version'] ?? '') === $version
            && $this->isLegacyCombinedApproval($approval);
    }

    /** @return list<array<string,mixed>> */
    private function currentPeriodBases(
        int $companyId,
        int $accountingPeriodId,
        int $accountsApprovalId,
        string $accountsApprovalHash,
        bool $lock,
        array $expectedBasisIds = [],
        array $currentHmrcInputs = []
    ): array {
        if (!\InterfaceDB::tableExists('ct_period_filing_bases')) {
            throw new \RuntimeException('Build the current CT-period filing bases before HMRC approval.');
        }
        $suffix = $lock && \InterfaceDB::driverName() !== 'sqlite' ? ' FOR UPDATE' : '';
        $params = [
            'accounts_approval_id' => $accountsApprovalId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'superseded' => 'superseded',
        ];
        $expectedBasisIds = array_values(array_unique(array_filter(
            array_map('intval', $expectedBasisIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($expectedBasisIds !== []) {
            $placeholders = [];
            foreach ($expectedBasisIds as $index => $basisId) {
                $key = 'expected_basis_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $basisId;
            }
            $basisJoin = 'b.ct_period_id = ctp.id
                    AND b.filing_approval_id = :accounts_approval_id
                    AND b.id IN (' . implode(', ', $placeholders) . ')';
        } else {
            $latestHmrcApprovalId = \InterfaceDB::tableExists('hmrc_ct_filing_approvals')
                ? (int)\InterfaceDB::fetchColumn(
                    'SELECT MAX(id) FROM hmrc_ct_filing_approvals
                     WHERE company_id = :company_id AND accounting_period_id = :period_id',
                    ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
                )
                : 0;
            if ($latestHmrcApprovalId > 0
                && \InterfaceDB::tableExists('hmrc_ct_filing_approval_period_bases')) {
                $params['preferred_hmrc_approval_id'] = $latestHmrcApprovalId;
                $basisJoin = 'b.id = (
                        SELECT approval_basis.ct_period_filing_basis_id
                        FROM hmrc_ct_filing_approval_period_bases approval_basis
                        WHERE approval_basis.hmrc_ct_filing_approval_id = :preferred_hmrc_approval_id
                          AND approval_basis.ct_period_id = ctp.id
                        LIMIT 1
                   )
                   AND b.filing_approval_id = :accounts_approval_id';
            } else {
                $basisJoin = 'b.id = (
                        SELECT MAX(current_basis.id)
                        FROM ct_period_filing_bases current_basis
                        WHERE current_basis.ct_period_id = ctp.id
                          AND current_basis.filing_approval_id = :accounts_approval_id
                   )';
            }
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT ctp.id AS active_ct_period_id, ctp.sequence_no, ctp.period_start, ctp.period_end,
                    ctp.status AS ct_period_status,
                    ctp.latest_computation_run_id,
                    b.id, b.filing_approval_id, b.company_id, b.accounting_period_id,
                    b.ct_period_id, b.computation_run_id, b.calculation_basis_version,
                    b.calculation_basis_hash, b.basis_version, b.basis_hash, b.basis_json,
                    r.computation_hash
             FROM corporation_tax_periods ctp
             LEFT JOIN ct_period_filing_bases b
               ON ' . $basisJoin . '
             LEFT JOIN corporation_tax_computation_runs r ON r.id = ctp.latest_computation_run_id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id
               AND ctp.status <> :superseded
             ORDER BY ctp.sequence_no, ctp.id' . $suffix,
            $params
        );
        if ($rows === []) {
            throw new \RuntimeException('No active Corporation Tax periods are available for HMRC approval.');
        }

        foreach ($rows as $row) {
            $model = json_decode((string)($row['basis_json'] ?? ''), true);
            $canonicalModel = is_array($model) ? $this->canonicalJson($model) : '';
            $expectedHash = $canonicalModel !== ''
                ? hash(
                    'sha256',
                    (string)($row['basis_version'] ?? '') . '|' . $accountsApprovalHash . '|'
                    . (string)($row['calculation_basis_hash'] ?? '') . '|' . $canonicalModel
                )
                : '';
            $basisVersion = (string)($row['basis_version'] ?? '');
            $filedBasis = in_array(
                (string)($row['ct_period_status'] ?? ''),
                ['submitted', 'accepted'],
                true
            );
            $recognisedBasisVersion = $basisVersion === IxbrlAccountsFilingApprovalService::CT_BASIS_VERSION
                || ($filedBasis && CtPeriodFilingModelService::recognisesBasisVersion($basisVersion));
            if (!$filedBasis
                && $basisVersion !== IxbrlAccountsFilingApprovalService::CT_BASIS_VERSION
                && CtPeriodFilingModelService::recognisesBasisVersion($basisVersion)) {
                throw new \RuntimeException(
                    'CT period ' . (int)($row['sequence_no'] ?? 0)
                    . ' uses the previous filing model ' . $basisVersion
                    . '. Approve the HMRC Corporation Tax return once under '
                    . IxbrlAccountsFilingApprovalService::CT_BASIS_VERSION . '.'
                );
            }
            if ((int)($row['id'] ?? 0) <= 0
                || (int)($row['active_ct_period_id'] ?? 0) !== (int)($row['ct_period_id'] ?? 0)
                || (int)($row['filing_approval_id'] ?? 0) !== $accountsApprovalId
                || (int)($row['company_id'] ?? 0) !== $companyId
                || (int)($row['accounting_period_id'] ?? 0) !== $accountingPeriodId
                || (int)($row['latest_computation_run_id'] ?? 0) !== (int)($row['computation_run_id'] ?? 0)
                || !$recognisedBasisVersion
                || !is_array($model)
                || (int)($model['approval']['id'] ?? 0) !== $accountsApprovalId
                || !hash_equals(
                    $accountsApprovalHash,
                    strtolower(trim((string)($model['approval']['basis_hash'] ?? '')))
                )
                || (int)($model['ct_period']['id'] ?? 0) !== (int)$row['ct_period_id']
                || (int)($model['computation']['run_id'] ?? 0) !== (int)$row['computation_run_id']
                || !hash_equals(
                    strtolower(trim((string)($row['computation_hash'] ?? ''))),
                    strtolower(trim((string)($model['computation']['hash'] ?? '')))
                )
                || !hash_equals(
                    strtolower(trim((string)($row['basis_hash'] ?? ''))),
                    $expectedHash
                )) {
                throw new \RuntimeException(
                    'CT period ' . (int)($row['sequence_no'] ?? 0)
                    . ' has no current integrity-verified filing basis for this accounts approval.'
                );
            }

            $this->assertCurrentHmrcInputs(
                $companyId,
                $accountingPeriodId,
                $row,
                $model,
                $currentHmrcInputs
            );
        }

        if ($expectedBasisIds !== []) {
            $resolvedIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
            sort($resolvedIds, SORT_NUMERIC);
            sort($expectedBasisIds, SORT_NUMERIC);
            if ($resolvedIds !== $expectedBasisIds) {
                throw new \RuntimeException(
                    'The explicitly prepared CT-period bases do not match every active Corporation Tax period.'
                );
            }
        }

        return array_values($rows);
    }

    /** @param array<string,mixed> $model @param array<string,mixed> $currentHmrcInputs */
    private function assertCurrentHmrcInputs(
        int $companyId,
        int $accountingPeriodId,
        array $row,
        array $model,
        array $currentHmrcInputs
    ): void {
        $utr = (string)($currentHmrcInputs['utr'] ?? '');
        if (!hash_equals(
            $utr,
            (string)($model['filing_identity']['utr'] ?? '')
        )) {
            throw new \RuntimeException(
                'The company UTR changed after the HMRC filing basis was prepared. Approve the Corporation Tax return again.'
            );
        }

        foreach ([
            'corporation_tax_filing_scope' => (array)($currentHmrcInputs['ct_scope'] ?? []),
            'supported_return_profile' => (array)($currentHmrcInputs['supported_return_profile'] ?? []),
        ] as $modelKey => $currentValue) {
            if (!hash_equals(
                $this->canonicalJson($currentValue),
                $this->canonicalJson((array)($model[$modelKey] ?? []))
            )) {
                throw new \RuntimeException(match ($modelKey) {
                    'corporation_tax_filing_scope' =>
                        'The Corporation Tax filing scope changed after the HMRC period basis was prepared. Approve the return again.',
                    default =>
                        'The supported Corporation Tax return profile changed after the HMRC period basis was prepared. Approve the return again.',
                });
            }
        }

        // Once filed, the immutable CT-period basis is the evidence of the
        // submitted CT600A position. Later L2P evidence must not reopen it.
        if (in_array((string)($row['ct_period_status'] ?? ''), ['submitted', 'accepted'], true)) {
            return;
        }
        $currentCt600a = (new Ct600aService())->build(
            $companyId,
            $accountingPeriodId,
            (int)($row['ct_period_id'] ?? 0)
        );
        if (empty($currentCt600a['available']) || empty($currentCt600a['complete'])) {
            throw new \RuntimeException((string)(
                (($currentCt600a['blocking_errors'] ?? $currentCt600a['errors'] ?? [])[0] ?? null)
                ?? 'The current CT600A evidence is incomplete.'
            ));
        }
        $ct600aService = new Ct600aService();
        if (!hash_equals(
            $this->canonicalJson($ct600aService->filingBasisProjection($currentCt600a)),
            $this->canonicalJson($ct600aService->filingBasisProjection((array)($model['ct600a'] ?? [])))
        )) {
            throw new \RuntimeException(
                'The CT600A evidence changed after the HMRC period basis was prepared. Approve the Corporation Tax return again.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function authorisationSnapshot(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'declarant_name' => trim((string)($row['declarant_name'] ?? '')),
            'declarant_status' => trim((string)($row['declarant_status'] ?? '')),
            'declarant_party_id' => (int)($row['declarant_party_id'] ?? 0) ?: null,
            'declarant_director_id' => (int)($row['declarant_director_id'] ?? 0) ?: null,
            'declarant_role_id' => (int)($row['declarant_role_id'] ?? 0) ?: null,
            'original_unfiled_confirmed' => !empty($row['original_unfiled_confirmed']),
            'authority_confirmed' => !empty($row['authority_confirmed']),
            'declaration_confirmed' => !empty($row['declaration_confirmed']),
            'declared_at' => trim((string)($row['saved_at'] ?? '')),
            'saved_by' => trim((string)($row['saved_by'] ?? '')),
        ];
    }

    /** @return array<string,mixed>|null */
    private function legacyAdapter(array $accountsApproval, array $candidate): ?array
    {
        if (!$this->isLegacyCombinedApproval($accountsApproval)
            || !$this->legacyApprovalMatchesCandidate($accountsApproval, $candidate)) {
            return null;
        }

        return [
            'id' => null,
            'persisted' => false,
            'source' => 'legacy_combined',
            'company_id' => (int)($candidate['basis']['company_id'] ?? 0),
            'accounting_period_id' => (int)($candidate['basis']['accounting_period_id'] ?? 0),
            'legacy_combined_approval_id' => (int)$accountsApproval['id'],
            'accounts_filing_approval_id' => (int)$accountsApproval['id'],
            'accounts_filing_approval_hash' => (string)$accountsApproval['basis_hash'],
            'return_authorisation_id' => (int)($candidate['return_authorisation']['id'] ?? 0),
            'return_authorisation_hash' => (string)($candidate['return_authorisation_hash'] ?? ''),
            'return_authorisation_json' => (string)($candidate['return_authorisation_json'] ?? ''),
            'ct_scope_hash' => (string)($candidate['ct_scope_hash'] ?? ''),
            'ct_scope_json' => (string)($candidate['ct_scope_json'] ?? ''),
            'basis_version' => self::BASIS_VERSION,
            'basis_hash' => (string)$candidate['basis_hash'],
            'basis_json' => (string)$candidate['basis_json'],
            'approved_by' => (string)($accountsApproval['approved_by'] ?? ''),
            'approved_at' => (string)($accountsApproval['approved_at'] ?? ''),
        ];
    }

    private function isLegacyCombinedApproval(array $approval): bool
    {
        $basis = json_decode((string)($approval['basis_json'] ?? ''), true);
        return is_array($basis)
            && is_array($basis['corporation_tax_return_authorisation'] ?? null)
            && is_array($basis['corporation_tax_filing_scope'] ?? null)
            && is_array($basis['ct_periods'] ?? null)
            && (array)$basis['ct_periods'] !== [];
    }

    private function legacyApprovalMatchesCandidate(array $approval, array $candidate): bool
    {
        $approvalJson = (string)($approval['basis_json'] ?? '');
        $approvalHash = strtolower(trim((string)($approval['basis_hash'] ?? '')));
        $accounts = (array)($candidate['accounts_approval'] ?? []);
        $stored = json_decode($approvalJson, true);
        if (!is_array($stored)
            || !hash_equals($approvalHash, hash('sha256', $approvalJson))
            || (int)($approval['id'] ?? 0) !== (int)($accounts['id'] ?? 0)
            || !hash_equals(
                $approvalHash,
                strtolower(trim((string)($accounts['basis_hash'] ?? '')))
            )) {
            return false;
        }
        $frozenAuthorisation = (array)($stored['corporation_tax_return_authorisation'] ?? []);
        $currentAuthorisation = (array)($candidate['return_authorisation'] ?? []);
        $legacyAuthorisation = [
            'declarant_status' => (string)($currentAuthorisation['declarant_status'] ?? ''),
            'original_unfiled_confirmed' => !empty($currentAuthorisation['original_unfiled_confirmed']),
            'authority_confirmed' => !empty($currentAuthorisation['authority_confirmed']),
            'declaration_confirmed' => !empty($currentAuthorisation['declaration_confirmed']),
        ];
        if (array_key_exists('declarant_name', $frozenAuthorisation)) {
            $legacyAuthorisation = [
                'declarant_name' => (string)($currentAuthorisation['declarant_name'] ?? ''),
                'declarant_status' => (string)($currentAuthorisation['declarant_status'] ?? ''),
                'declaration_at' => (string)($currentAuthorisation['declared_at'] ?? ''),
                'declarant_party_id' => $currentAuthorisation['declarant_party_id'] ?? null,
                'declarant_director_id' => $currentAuthorisation['declarant_director_id'] ?? null,
                'declarant_role_id' => $currentAuthorisation['declarant_role_id'] ?? null,
                'original_unfiled_confirmed' => !empty($currentAuthorisation['original_unfiled_confirmed']),
                'authority_confirmed' => !empty($currentAuthorisation['authority_confirmed']),
                'declaration_confirmed' => !empty($currentAuthorisation['declaration_confirmed']),
            ];
        }
        if (!hash_equals(
            $this->canonicalJson($frozenAuthorisation),
            $this->canonicalJson($legacyAuthorisation)
        )) {
            return false;
        }

        $scope = (array)($candidate['ct_scope'] ?? []);
        $legacyScope = [
            'scope_version' => (string)($scope['scope_version'] ?? ''),
            'revision' => (int)($scope['revision'] ?? 0),
            'answers' => (array)($scope['answers'] ?? []),
            'basis_hash' => (string)($candidate['ct_scope_hash'] ?? ''),
        ];
        if (!hash_equals(
            $this->canonicalJson((array)$stored['corporation_tax_filing_scope']),
            $this->canonicalJson($legacyScope)
        )) {
            return false;
        }

        $references = [];
        foreach ((array)($candidate['ct_period_bases'] ?? []) as $reference) {
            $references[(int)($reference['ct_period_id'] ?? 0)] = $reference;
        }
        foreach ((array)$stored['ct_periods'] as $period) {
            $reference = $references[(int)($period['id'] ?? 0)] ?? null;
            if (!is_array($reference)
                || (int)($period['computation_run_id'] ?? 0) !== (int)($reference['computation_run_id'] ?? 0)
                || !hash_equals(
                    strtolower((string)($period['computation_hash'] ?? '')),
                    strtolower((string)($reference['computation_hash'] ?? ''))
                )
                || !hash_equals(
                    (string)($period['calculation_basis_version'] ?? ''),
                    (string)($reference['calculation_basis_version'] ?? '')
                )
                || !hash_equals(
                    strtolower((string)($period['calculation_basis_hash'] ?? '')),
                    strtolower((string)($reference['calculation_basis_hash'] ?? ''))
                )) {
                return false;
            }
        }
        return count((array)$stored['ct_periods']) === count($references);
    }

    /** @return array<string,mixed>|null */
    private function latestNativeApproval(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM hmrc_ct_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function matchingNativeApproval(int $companyId, int $accountingPeriodId, array $candidate): ?array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM hmrc_ct_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id
               AND basis_hash = :basis_hash
             ORDER BY id DESC',
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'basis_hash' => (string)$candidate['basis_hash'],
            ]
        );
        foreach ($rows as $row) {
            if ($this->nativeApprovalMatchesCandidate($row, $candidate)
                && $this->nativeApprovalLinksMatchCandidate((int)($row['id'] ?? 0), $candidate)) {
                return $row;
            }
        }
        return null;
    }

    private function nativeApprovalMatchesCandidate(array $approval, array $candidate): bool
    {
        $accounts = (array)($candidate['accounts_approval'] ?? []);
        return (string)($approval['basis_version'] ?? '') === self::BASIS_VERSION
            && (int)($approval['accounts_filing_approval_id'] ?? 0) === (int)($accounts['id'] ?? 0)
            && hash_equals(
                strtolower((string)($approval['accounts_filing_approval_hash'] ?? '')),
                strtolower((string)($accounts['basis_hash'] ?? ''))
            )
            && (int)($approval['return_authorisation_id'] ?? 0)
                === (int)($candidate['return_authorisation']['id'] ?? 0)
            && hash_equals(
                strtolower((string)($approval['return_authorisation_hash'] ?? '')),
                (string)($candidate['return_authorisation_hash'] ?? '')
            )
            && hash_equals(
                (string)($approval['return_authorisation_json'] ?? ''),
                (string)($candidate['return_authorisation_json'] ?? '')
            )
            && hash_equals(
                strtolower((string)($approval['ct_scope_hash'] ?? '')),
                (string)($candidate['ct_scope_hash'] ?? '')
            )
            && hash_equals(
                (string)($approval['ct_scope_json'] ?? ''),
                (string)($candidate['ct_scope_json'] ?? '')
            )
            && hash_equals(
                strtolower((string)($approval['basis_hash'] ?? '')),
                (string)($candidate['basis_hash'] ?? '')
            )
            && hash_equals(
                (string)($approval['basis_json'] ?? ''),
                (string)($candidate['basis_json'] ?? '')
            )
            && hash_equals(
                strtolower((string)($approval['basis_hash'] ?? '')),
                hash('sha256', (string)($approval['basis_json'] ?? ''))
            );
    }

    /** @param list<int> $ctBasisIds */
    private function verifyPersisted(int $approvalId, array $candidate, array $ctBasisIds): void
    {
        $approval = \InterfaceDB::fetchOne(
            'SELECT * FROM hmrc_ct_filing_approvals WHERE id = :id',
            ['id' => $approvalId]
        );
        if (!is_array($approval) || !$this->nativeApprovalMatchesCandidate($approval, $candidate)) {
            throw new \RuntimeException('The persisted HMRC Corporation Tax approval failed its integrity check.');
        }
        $expectedIds = array_values(array_map(
            static fn(array $basis): int => (int)($basis['id'] ?? 0),
            (array)($candidate['ct_period_bases'] ?? [])
        ));
        $persistedIds = array_values(array_map('intval', $ctBasisIds));
        sort($expectedIds, SORT_NUMERIC);
        sort($persistedIds, SORT_NUMERIC);
        if (!$this->nativeApprovalLinksMatchCandidate($approvalId, $candidate)
            || $persistedIds !== $expectedIds) {
            throw new \RuntimeException('A CT-period filing basis was not bound to the HMRC approval.');
        }
    }

    private function nativeApprovalLinksMatchCandidate(int $approvalId, array $candidate): bool
    {
        if ($approvalId <= 0
            || !\InterfaceDB::tableExists('hmrc_ct_filing_approval_period_bases')) {
            return false;
        }

        $expected = [];
        foreach ((array)($candidate['ct_period_bases'] ?? []) as $basis) {
            $basisId = (int)($basis['id'] ?? 0);
            $ctPeriodId = (int)($basis['ct_period_id'] ?? 0);
            $basisHash = strtolower(trim((string)($basis['basis_hash'] ?? '')));
            if ($basisId <= 0 || $ctPeriodId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $basisHash) !== 1) {
                return false;
            }
            $expected[$basisId] = [
                'ct_period_id' => $ctPeriodId,
                'basis_hash' => $basisHash,
            ];
        }
        if ($expected === []) {
            return false;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT approval_basis.ct_period_filing_basis_id,
                    approval_basis.ct_period_id,
                    approval_basis.basis_hash AS linked_basis_hash,
                    basis.ct_period_id AS stored_ct_period_id,
                    basis.basis_hash AS stored_basis_hash
             FROM hmrc_ct_filing_approval_period_bases approval_basis
             INNER JOIN ct_period_filing_bases basis
                     ON basis.id = approval_basis.ct_period_filing_basis_id
             WHERE approval_basis.hmrc_ct_filing_approval_id = :approval_id
             ORDER BY approval_basis.ct_period_id, approval_basis.ct_period_filing_basis_id',
            ['approval_id' => $approvalId]
        );
        if (count($rows) !== count($expected)) {
            return false;
        }
        foreach ($rows as $row) {
            $basisId = (int)($row['ct_period_filing_basis_id'] ?? 0);
            $reference = $expected[$basisId] ?? null;
            if (!is_array($reference)
                || (int)($row['ct_period_id'] ?? 0) !== (int)$reference['ct_period_id']
                || (int)($row['stored_ct_period_id'] ?? 0) !== (int)$reference['ct_period_id']
                || !hash_equals(
                    (string)$reference['basis_hash'],
                    strtolower(trim((string)($row['linked_basis_hash'] ?? '')))
                )
                || !hash_equals(
                    (string)$reference['basis_hash'],
                    strtolower(trim((string)($row['stored_basis_hash'] ?? '')))
                )) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function staleErrors(?array $latest, array $currentBasis): array
    {
        if (!is_array($latest)) {
            return ['Approve the current HMRC Corporation Tax filing basis.'];
        }
        $stored = json_decode((string)($latest['basis_json'] ?? ''), true);
        if (!is_array($stored)
            || !hash_equals(
                strtolower(trim((string)($latest['basis_hash'] ?? ''))),
                hash('sha256', (string)($latest['basis_json'] ?? ''))
            )) {
            return ['The previous HMRC Corporation Tax approval failed its integrity check.'];
        }

        $messages = [];
        foreach ($this->changedSections($stored, $currentBasis) as $section) {
            $messages[] = match ($section) {
                'accounts_filing_approval' => 'The statutory-accounts approval changed after HMRC approval.',
                'return_authorisation', 'return_authorisation_hash' => 'The Corporation Tax return authorisation changed after HMRC approval.',
                'corporation_tax_filing_scope', 'corporation_tax_filing_scope_hash' => 'The Corporation Tax filing scope changed after HMRC approval.',
                'ct_period_filing_bases' => 'A Corporation Tax period filing basis changed after HMRC approval.',
                default => 'The HMRC Corporation Tax filing basis changed after approval.',
            };
        }
        return array_values(array_unique($messages !== [] ? $messages : [
            'The HMRC Corporation Tax filing basis changed after approval.',
        ]));
    }

    /** @return list<string> */
    private function changedSections(array $stored, array $current): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($stored), array_keys($current))));
        sort($keys, SORT_STRING);
        $changed = [];
        foreach ($keys as $key) {
            if (!hash_equals(
                $this->canonicalJson(['value' => $stored[$key] ?? null]),
                $this->canonicalJson(['value' => $current[$key] ?? null])
            )) {
                $changed[] = (string)$key;
            }
        }
        return $changed;
    }

    /** @return array<string,mixed> */
    private function statusResult(
        string $state,
        bool $canApprove,
        ?array $approval,
        ?array $candidate,
        array $errors,
        ?array $latest = null,
        string $source = 'none'
    ): array {
        return [
            'available' => $this->schemaReady() || $source === 'legacy_combined',
            'native_schema_available' => $this->schemaReady(),
            'state' => $state,
            'current' => $state === 'current',
            'can_approve' => $canApprove,
            'source' => $source,
            'approval' => $approval,
            'latest_approval' => $latest ?? $approval,
            'candidate_hash' => $candidate['basis_hash'] ?? null,
            'errors' => array_values(array_unique(array_filter(array_map(
                static fn(mixed $error): string => trim((string)$error),
                $errors
            )))),
        ];
    }

    private function schemaReady(): bool
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_filing_approvals')
            || !\InterfaceDB::tableExists('ct_period_filing_bases')
            || !\InterfaceDB::tableExists('hmrc_ct_filing_approval_period_bases')) {
            return false;
        }
        foreach ([
            'accounts_filing_approval_id', 'accounts_filing_approval_hash',
            'return_authorisation_id', 'return_authorisation_hash', 'return_authorisation_json',
            'ct_scope_hash', 'ct_scope_json', 'basis_version', 'basis_hash', 'basis_json',
            'legacy_combined_approval_id',
        ] as $column) {
            if (!\InterfaceDB::columnExists('hmrc_ct_filing_approvals', $column)) {
                return false;
            }
        }
        foreach ([
            'hmrc_ct_filing_approval_id', 'ct_period_filing_basis_id',
            'ct_period_id', 'basis_hash',
        ] as $column) {
            if (!\InterfaceDB::columnExists('hmrc_ct_filing_approval_period_bases', $column)) {
                return false;
            }
        }
        return true;
    }

    private function assertSchemaReady(): void
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException(
                'Apply the separate HMRC Corporation Tax filing approval migration before approval.'
            );
        }
    }

    private function lastInsertId(): int
    {
        return (int)(\InterfaceDB::fetchColumn(
            \InterfaceDB::driverName() === 'sqlite'
                ? 'SELECT last_insert_rowid()'
                : 'SELECT LAST_INSERT_ID()'
        ) ?: 0);
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };
        return \eel_accounts\Support\PersistentJson::encode(
            $normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
