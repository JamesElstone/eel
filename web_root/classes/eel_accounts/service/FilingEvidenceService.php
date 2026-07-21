<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Immutable filing-evidence identity, lifecycle, artifact and read-model service. */
final class FilingEvidenceService
{
    public const EVIDENCE_VERSION = 'filing-evidence-v1';
    public const BUNDLE_PREFIX = 'EEL-FE-';
    public const ARTIFACT_PREFIX = 'EEL-AR-';

    /** @return array<string,mixed> */
    public function createForLock(int $companyId, int $accountingPeriodId, string $actor): array
    {
        $this->requireSchema();
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('Filing evidence must be created inside the Year End lock transaction.');
        }
        $review = \InterfaceDB::fetchOne(
            'SELECT id, is_locked, locked_at, locked_by FROM year_end_reviews
             WHERE company_id = :company_id AND accounting_period_id = :period_id LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($review) || empty($review['is_locked']) || trim((string)$review['locked_at']) === '') {
            throw new \RuntimeException('The accounting period must be locked before its filing evidence is created.');
        }
        $existing = \InterfaceDB::fetchOne(
            'SELECT * FROM filing_evidence_bundles
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND locked_at = :locked_at LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'locked_at' => $review['locked_at']]
        );
        if (is_array($existing)) {
            return $this->normaliseBundle($existing);
        }
        $snapshots = \InterfaceDB::fetchAll(
            'SELECT s.id AS snapshot_id, s.ct_period_id, s.computation_run_id,
                    s.basis_version, s.basis_hash, s.calculation_trace_hash
             FROM corporation_tax_audit_snapshots s
             INNER JOIN corporation_tax_periods cp
               ON cp.id = s.ct_period_id AND cp.latest_computation_run_id = s.computation_run_id
             WHERE s.company_id = :company_id AND s.accounting_period_id = :period_id
             ORDER BY cp.sequence_no, s.id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        ) ?: [];
        if ($snapshots === []) {
            throw new \RuntimeException('No sealed Corporation Tax audit snapshots exist for the locked period.');
        }
        $predecessor = \InterfaceDB::fetchOne(
            'SELECT * FROM filing_evidence_bundles
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        $identity = (new ApplicationBuildIdentityService())->snapshot();
        $evidenceId = $this->newReference(self::BUNDLE_PREFIX);
        $basis = [
            'evidence_id' => $evidenceId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'locked_at' => (string)$review['locked_at'],
            'application' => $identity,
            'snapshots' => array_map(static fn(array $row): array => [
                'ct_period_id' => (int)$row['ct_period_id'],
                'computation_run_id' => (int)$row['computation_run_id'],
                'snapshot_id' => (int)$row['snapshot_id'],
                'basis_version' => (string)$row['basis_version'],
                'basis_hash' => (string)$row['basis_hash'],
                'trace_hash' => (string)($row['calculation_trace_hash'] ?? ''),
            ], $snapshots),
        ];
        $bundleHash = hash('sha256', $this->canonicalJson($basis));
        if (is_array($predecessor)) {
            \InterfaceDB::prepareExecute(
                'UPDATE filing_evidence_bundles
                 SET lifecycle_status = :status, superseded_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND lifecycle_status <> :superseded',
                ['status' => 'superseded', 'superseded' => 'superseded', 'id' => (int)$predecessor['id']]
            );
            $this->recordEvent(
                (int)$predecessor['id'],
                'superseded',
                'warning',
                $actor,
                'A later Year End lock created replacement filing evidence.',
                ['successor_evidence_id' => $evidenceId]
            );
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO filing_evidence_bundles
                (evidence_id, company_id, accounting_period_id, year_end_review_id,
                 predecessor_bundle_id, lifecycle_status, evidence_version,
                 application_name, application_version, calculation_build,
                 locked_at, locked_by, bundle_hash, legacy_backfill)
             VALUES
                (:evidence_id, :company_id, :period_id, :review_id,
                 :predecessor_id, :status, :evidence_version,
                 :app_name, :app_version, :calculation_build,
                 :locked_at, :locked_by, :bundle_hash, 0)',
            [
                'evidence_id' => $evidenceId,
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'review_id' => (int)$review['id'],
                'predecessor_id' => is_array($predecessor) ? (int)$predecessor['id'] : null,
                'status' => 'current',
                'evidence_version' => self::EVIDENCE_VERSION,
                'app_name' => $identity['name'],
                'app_version' => $identity['version'],
                'calculation_build' => $identity['calculation_build'],
                'locked_at' => $review['locked_at'],
                'locked_by' => trim($actor) !== '' ? substr(trim($actor), 0, 100) : (string)$review['locked_by'],
                'bundle_hash' => $bundleHash,
            ]
        );
        $bundleId = $this->lastInsertId();
        foreach ($snapshots as $snapshot) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO filing_evidence_ct_snapshots
                    (bundle_id, ct_period_id, computation_run_id, tax_audit_snapshot_id,
                     calculation_basis_version, calculation_basis_hash, trace_hash)
                 VALUES (:bundle_id, :ct_period_id, :run_id, :snapshot_id, :basis_version, :basis_hash, :trace_hash)',
                [
                    'bundle_id' => $bundleId,
                    'ct_period_id' => (int)$snapshot['ct_period_id'],
                    'run_id' => (int)$snapshot['computation_run_id'],
                    'snapshot_id' => (int)$snapshot['snapshot_id'],
                    'basis_version' => (string)$snapshot['basis_version'],
                    'basis_hash' => (string)$snapshot['basis_hash'],
                    'trace_hash' => ($snapshot['calculation_trace_hash'] ?? null) ?: null,
                ]
            );
        }
        $this->recordEvent($bundleId, 'locked', 'success', $actor, 'Year End filing evidence frozen.', [
            'bundle_hash' => $bundleHash,
            'ct_period_count' => count($snapshots),
        ]);
        $row = \InterfaceDB::fetchOne('SELECT * FROM filing_evidence_bundles WHERE id = :id', ['id' => $bundleId]);
        return $this->normaliseBundle(is_array($row) ? $row : ['id' => $bundleId, 'evidence_id' => $evidenceId]);
    }

    public function reopenForAccountingPeriod(int $companyId, int $accountingPeriodId, string $actor, ?string $note = null): void
    {
        if (!\InterfaceDB::tableExists('filing_evidence_bundles')) {
            return;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT id, evidence_id FROM filing_evidence_bundles
             WHERE company_id = :company_id AND accounting_period_id = :period_id
               AND lifecycle_status = :status',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'status' => 'current']
        ) ?: [];
        foreach ($rows as $row) {
            \InterfaceDB::prepareExecute(
                'UPDATE filing_evidence_bundles
                 SET lifecycle_status = :status, reopened_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                ['status' => 'reopened', 'id' => (int)$row['id']]
            );
            $this->recordEvent((int)$row['id'], 'unlocked', 'warning', $actor,
                'The accounting period was reopened; frozen evidence remains available but cannot be reused for a new filing.',
                ['note' => $note]);
        }
    }

    /** @return array<string,mixed> */
    public function currentBundle(int $companyId, int $accountingPeriodId, bool $requireCurrent = true): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT b.*, yr.is_locked AS period_is_locked
             FROM filing_evidence_bundles b
             LEFT JOIN year_end_reviews yr
               ON yr.company_id = b.company_id
              AND yr.accounting_period_id = b.accounting_period_id
             WHERE b.company_id = :company_id AND b.accounting_period_id = :period_id
             ORDER BY b.id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('No filing evidence exists for this accounting period.');
        }
        if ($requireCurrent && ((string)$row['lifecycle_status'] !== 'current' || empty($row['period_is_locked']))) {
            throw new \RuntimeException('The filing evidence is not current for a locked accounting period.');
        }
        return $this->normaliseBundle($row);
    }

    /** Create evidence lazily for a pre-feature locked period, while preserving transactional creation. */
    public function ensureCurrentBundle(int $companyId, int $accountingPeriodId, string $actor = 'system'): array
    {
        try {
            return $this->currentBundle($companyId, $accountingPeriodId, true);
        } catch (\Throwable $exception) {
            $review = \InterfaceDB::fetchOne(
                'SELECT is_locked FROM year_end_reviews WHERE company_id = :company_id AND accounting_period_id = :period_id LIMIT 1',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            if (!is_array($review) || empty($review['is_locked'])) { throw $exception; }
            if (\InterfaceDB::inTransaction()) {
                return $this->createForLock($companyId, $accountingPeriodId, $actor);
            }
            return (array)\InterfaceDB::transaction(
                fn(): array => $this->createForLock($companyId, $accountingPeriodId, $actor)
            );
        }
    }

    /** @return array<string,mixed> */
    public function reserveArtifact(
        int $companyId,
        int $accountingPeriodId,
        string $role,
        ?int $ctPeriodId = null,
        array $metadata = [],
        ?string $transactionHex = null
    ): array {
        $bundle = $this->ensureCurrentBundle($companyId, $accountingPeriodId, 'artifact-generation');
        $hex = strtoupper(trim((string)$transactionHex));
        if ($hex !== '' && preg_match('/^[A-F0-9]{1,32}$/D', $hex) !== 1) {
            throw new \InvalidArgumentException('The filing artifact transaction identity is invalid.');
        }
        $hex = $hex !== '' ? str_pad($hex, 32, '0', STR_PAD_LEFT) : strtoupper(bin2hex(random_bytes(16)));
        $reference = self::ARTIFACT_PREFIX . $hex;
        $identity = (new ApplicationBuildIdentityService())->snapshot();
        \InterfaceDB::prepareExecute(
            'INSERT INTO filing_evidence_artifacts
                (artifact_id, transaction_hex, bundle_id, ct_period_id, artifact_role,
                 artifact_status, generator_name, generator_version, metadata_json)
             VALUES
                (:artifact_id, :transaction_hex, :bundle_id, :ct_period_id, :role,
                 :status, :generator_name, :generator_version, :metadata)',
            [
                'artifact_id' => $reference,
                'transaction_hex' => $hex,
                'bundle_id' => (int)$bundle['id'],
                'ct_period_id' => $ctPeriodId,
                'role' => substr(trim($role), 0, 64),
                'status' => 'reserved',
                'generator_name' => $identity['name'],
                'generator_version' => $identity['version'],
                'metadata' => $metadata === [] ? null : $this->canonicalJson($metadata),
            ]
        );
        $artifactId = $this->lastInsertId();
        $this->recordEvent((int)$bundle['id'], 'artifact_reserved', 'info', 'system',
            'A filing artifact identity was reserved.', ['artifact_id' => $reference, 'role' => $role], $artifactId);
        return [
            'id' => $artifactId,
            'artifact_id' => $reference,
            'display_id' => $this->displayReference($reference),
            'transaction_hex' => $hex,
            'bundle_id' => (int)$bundle['id'],
            'evidence_id' => (string)$bundle['evidence_id'],
        ];
    }

    public function completeArtifact(int $artifactRowId, array $artifact): void
    {
        $row = \InterfaceDB::fetchOne('SELECT * FROM filing_evidence_artifacts WHERE id = :id', ['id' => $artifactRowId]);
        if (!is_array($row)) {
            throw new \RuntimeException('The reserved filing artifact could not be found.');
        }
        $sha = strtolower(trim((string)($artifact['sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            throw new \RuntimeException('A completed filing artifact requires a SHA-256 hash.');
        }
        \InterfaceDB::prepareExecute(
            'UPDATE filing_evidence_artifacts SET
                artifact_status = :status, filename = :filename, storage_path = :path,
                sha256 = :sha, schema_identity = :schema_identity,
                schema_manifest_sha256 = :schema_hash, validator_name = :validator,
                validator_version = :validator_version, validation_status = :validation_status,
                identifier_embedded = :embedded, metadata_json = COALESCE(:metadata, metadata_json),
                completed_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'status' => (string)($artifact['status'] ?? 'generated'),
                'filename' => ($artifact['filename'] ?? null) ?: null,
                'path' => ($artifact['path'] ?? null) ?: null,
                'sha' => $sha,
                'schema_identity' => ($artifact['schema_identity'] ?? null) ?: null,
                'schema_hash' => ($artifact['schema_manifest_sha256'] ?? null) ?: null,
                'validator' => ($artifact['validator_name'] ?? null) ?: null,
                'validator_version' => ($artifact['validator_version'] ?? null) ?: null,
                'validation_status' => ($artifact['validation_status'] ?? null) ?: null,
                'embedded' => !empty($artifact['identifier_embedded']) ? 1 : 0,
                'metadata' => isset($artifact['metadata']) ? $this->canonicalJson((array)$artifact['metadata']) : null,
                'id' => $artifactRowId,
            ]
        );
        $this->recordEvent((int)$row['bundle_id'], 'artifact_completed', 'success', 'system',
            'A filing artifact was frozen and hashed.', ['artifact_id' => $row['artifact_id'], 'sha256' => $sha], $artifactRowId);
    }

    public function failArtifact(int $artifactRowId, string $message): void
    {
        $row = \InterfaceDB::fetchOne('SELECT * FROM filing_evidence_artifacts WHERE id = :id', ['id' => $artifactRowId]);
        if (!is_array($row)) { return; }
        \InterfaceDB::prepareExecute(
            'UPDATE filing_evidence_artifacts SET artifact_status = :status, validation_status = :validation_status,
             metadata_json = :metadata, completed_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => 'failed', 'validation_status' => 'failed', 'metadata' => $this->canonicalJson(['error' => $message]), 'id' => $artifactRowId]
        );
        $this->recordEvent((int)$row['bundle_id'], 'artifact_failed', 'error', 'system', $message, ['artifact_id' => $row['artifact_id']], $artifactRowId);
    }

    public function linkApproval(int $approvalId, int $companyId, int $accountingPeriodId, string $actor): int
    {
        $bundle = $this->ensureCurrentBundle($companyId, $accountingPeriodId, $actor);
        \InterfaceDB::prepareExecute(
            'UPDATE ixbrl_accounts_filing_approvals SET evidence_bundle_id = :bundle_id
             WHERE id = :id AND company_id = :company_id AND accounting_period_id = :period_id',
            ['bundle_id' => (int)$bundle['id'], 'id' => $approvalId, 'company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        $this->recordEvent((int)$bundle['id'], 'filing_approved', 'success', $actor, 'The accounts filing basis was approved.', ['approval_id' => $approvalId]);
        return (int)$bundle['id'];
    }

    public function recordEvent(
        int $bundleId,
        string $type,
        string $status,
        string $actor,
        string $message,
        array $context = [],
        ?int $artifactRowId = null
    ): void {
        \InterfaceDB::prepareExecute(
            'INSERT INTO filing_evidence_events
                (bundle_id, artifact_id, event_type, event_status, actor, event_message, event_context_json)
             VALUES (:bundle_id, :artifact_id, :event_type, :event_status, :actor, :message, :context)',
            [
                'bundle_id' => $bundleId,
                'artifact_id' => $artifactRowId,
                'event_type' => substr(trim($type), 0, 64),
                'event_status' => substr(trim($status), 0, 32),
                'actor' => substr(trim($actor) !== '' ? trim($actor) : 'system', 0, 100),
                'message' => $message,
                'context' => $context === [] ? null : $this->canonicalJson($context),
            ]
        );
    }

    /** @return array<string,mixed> */
    public function resolve(int $companyId, string $reference): array
    {
        $canonical = $this->normaliseReference($reference);
        if ($canonical === '') {
            return ['found' => false, 'empty' => trim($reference) === '', 'errors' => trim($reference) === '' ? [] : ['The EEL Evidence ID is invalid.']];
        }
        $artifact = null;
        if (str_starts_with($canonical, self::ARTIFACT_PREFIX)) {
            $artifact = \InterfaceDB::fetchOne(
                'SELECT a.* FROM filing_evidence_artifacts a
                 INNER JOIN filing_evidence_bundles b ON b.id = a.bundle_id
                 WHERE a.artifact_id = :reference AND b.company_id = :company_id LIMIT 1',
                ['reference' => $canonical, 'company_id' => $companyId]
            );
            $bundleId = is_array($artifact) ? (int)$artifact['bundle_id'] : 0;
        } else {
            $bundle = \InterfaceDB::fetchOne(
                'SELECT * FROM filing_evidence_bundles WHERE evidence_id = :reference AND company_id = :company_id LIMIT 1',
                ['reference' => $canonical, 'company_id' => $companyId]
            );
            $bundleId = is_array($bundle) ? (int)$bundle['id'] : 0;
        }
        if ($bundleId <= 0) {
            return ['found' => false, 'empty' => false, 'errors' => ['No filing evidence with that ID exists for the selected company.']];
        }
        return ['found' => true, 'empty' => false, 'bundle_id' => $bundleId, 'selected_artifact_id' => (int)($artifact['id'] ?? 0), 'reference' => $canonical, 'errors' => []];
    }

    /** @return array<string,mixed> */
    public function overview(int $companyId, int $bundleId): array
    {
        if ($bundleId <= 0) { return ['available' => false, 'empty_selection' => true, 'errors' => []]; }
        $bundle = \InterfaceDB::fetchOne(
            'SELECT b.*, c.company_name, c.company_number, ap.label AS period_label,
                    ap.period_start, ap.period_end, yr.is_locked AS currently_locked
             FROM filing_evidence_bundles b
             INNER JOIN companies c ON c.id = b.company_id
             INNER JOIN accounting_periods ap ON ap.id = b.accounting_period_id
             LEFT JOIN year_end_reviews yr ON yr.id = b.year_end_review_id
             WHERE b.id = :bundle_id AND b.company_id = :company_id LIMIT 1',
            ['bundle_id' => $bundleId, 'company_id' => $companyId]
        );
        if (!is_array($bundle)) { return ['available' => false, 'errors' => ['The filing evidence is unavailable.']]; }
        $periods = \InterfaceDB::fetchAll(
            'SELECT es.*, cp.sequence_no, cp.period_start, cp.period_end, cp.status AS ct_status
             FROM filing_evidence_ct_snapshots es
             INNER JOIN corporation_tax_periods cp ON cp.id = es.ct_period_id
             WHERE es.bundle_id = :bundle_id ORDER BY cp.sequence_no', ['bundle_id' => $bundleId]
        ) ?: [];
        $events = \InterfaceDB::fetchAll(
            'SELECT event_type, event_status, actor, event_message, event_context_json, created_at
             FROM filing_evidence_events WHERE bundle_id = :bundle_id ORDER BY id DESC', ['bundle_id' => $bundleId]
        ) ?: [];
        $hmrc = \InterfaceDB::fetchAll(
            'SELECT id, environment, status, protocol_state, business_outcome, submission_type,
                    hmrc_submission_reference, hmrc_correlation_id, transaction_id, submitted_at, final_response_at
             FROM hmrc_ct600_submissions WHERE evidence_bundle_id = :bundle_id ORDER BY id', ['bundle_id' => $bundleId]
        ) ?: [];
        $companiesHouse = \InterfaceDB::fetchAll(
            'SELECT id, environment, lifecycle, submission_number, gateway_submission_reference, submitted_at, accepted_at
             FROM companies_house_accounts_submissions WHERE evidence_bundle_id = :bundle_id ORDER BY id', ['bundle_id' => $bundleId]
        ) ?: [];
        return ['available' => true, 'bundle' => $this->normaliseBundle($bundle), 'ct_periods' => $periods,
            'events' => $events, 'hmrc_submissions' => $hmrc, 'companies_house_submissions' => $companiesHouse, 'errors' => []];
    }

    /** @return array<string,mixed> */
    public function artifacts(int $companyId, int $bundleId): array
    {
        $valid = $this->overview($companyId, $bundleId);
        if (empty($valid['available'])) { return $valid; }
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM filing_evidence_artifacts WHERE bundle_id = :bundle_id ORDER BY id', ['bundle_id' => $bundleId]
        ) ?: [];
        return ['available' => true, 'artifacts' => array_map(function(array $row): array {
            $row['display_id'] = $this->displayReference((string)$row['artifact_id']); return $row;
        }, $rows), 'errors' => []];
    }

    /** @return array<string,mixed> */
    public function calculations(int $companyId, int $bundleId): array
    {
        $valid = $this->overview($companyId, $bundleId);
        if (empty($valid['available'])) { return $valid; }
        $rows = \InterfaceDB::fetchAll(
            'SELECT es.tax_audit_snapshot_id AS snapshot_id, es.ct_period_id, cp.sequence_no,
                    cp.period_start, cp.period_end, a.area_code, a.area_label, a.amount,
                    a.expected_amount, a.reconciliation_status, a.source_count, a.area_hash
             FROM filing_evidence_ct_snapshots es
             INNER JOIN corporation_tax_periods cp ON cp.id = es.ct_period_id
             INNER JOIN corporation_tax_audit_areas a ON a.snapshot_id = es.tax_audit_snapshot_id
             WHERE es.bundle_id = :bundle_id ORDER BY cp.sequence_no, a.id', ['bundle_id' => $bundleId]
        ) ?: [];
        return ['available' => true, 'areas' => $rows, 'legacy_reconstructed' => !empty($valid['bundle']['legacy_backfill']), 'errors' => []];
    }

    /** @return array<string,mixed> */
    public function calculationDetail(int $companyId, int $bundleId, int $snapshotId, string $areaCode, int $page = 1): array
    {
        if ($bundleId <= 0 || $snapshotId <= 0 || trim($areaCode) === '') {
            return ['available' => false, 'empty_selection' => true, 'errors' => []];
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT a.*, b.legacy_backfill
             FROM corporation_tax_audit_areas a
             INNER JOIN filing_evidence_ct_snapshots es ON es.tax_audit_snapshot_id = a.snapshot_id
             INNER JOIN filing_evidence_bundles b ON b.id = es.bundle_id
             WHERE b.id = :bundle_id AND b.company_id = :company_id
               AND a.snapshot_id = :snapshot_id AND a.area_code = :area_code LIMIT 1',
            ['bundle_id' => $bundleId, 'company_id' => $companyId, 'snapshot_id' => $snapshotId, 'area_code' => strtolower(trim($areaCode))]
        );
        if (!is_array($row)) { return ['available' => false, 'errors' => ['The frozen calculation area is unavailable.']]; }
        $detail = json_decode((string)$row['detail_json'], true);
        if (!is_array($detail)) { return ['available' => false, 'errors' => ['The frozen calculation evidence is unreadable.']]; }
        $all = array_values((array)($detail['rows'] ?? []));
        $perPage = 50; $pages = max(1, (int)ceil(count($all) / $perPage)); $page = max(1, min($page, $pages));
        $detail['rows'] = array_slice($all, ($page - 1) * $perPage, $perPage);
        $detail['pagination'] = ['page' => $page, 'page_count' => $pages, 'total_rows' => count($all), 'per_page' => $perPage];
        $detail['available'] = true; $detail['frozen'] = true; $detail['legacy_reconstructed'] = !empty($row['legacy_backfill']);
        return $detail;
    }

    public function normaliseReference(string $reference): string
    {
        $compact = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim($reference)));
        if (preg_match('/^EEL(FE|AR)([A-F0-9]{32})$/D', $compact, $matches) !== 1) { return ''; }
        return 'EEL-' . $matches[1] . '-' . $matches[2];
    }

    public function displayReference(string $reference): string
    {
        $canonical = $this->normaliseReference($reference);
        if ($canonical === '') { return $reference; }
        $prefix = substr($canonical, 0, 7); $hex = substr($canonical, 7);
        return $prefix . implode('-', str_split($hex, 4));
    }

    private function newReference(string $prefix): string { return $prefix . strtoupper(bin2hex(random_bytes(16))); }
    private function requireSchema(): void
    {
        foreach (['filing_evidence_bundles','filing_evidence_ct_snapshots','filing_evidence_artifacts','filing_evidence_events'] as $table) {
            if (!\InterfaceDB::tableExists($table)) { throw new \RuntimeException('Apply the Filing Evidence database migration before locking Year End.'); }
        }
    }
    private function normaliseBundle(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0); $row['company_id'] = (int)($row['company_id'] ?? 0);
        $row['accounting_period_id'] = (int)($row['accounting_period_id'] ?? 0);
        $row['legacy_backfill'] = !empty($row['legacy_backfill']);
        $row['display_id'] = $this->displayReference((string)($row['evidence_id'] ?? ''));
        return $row;
    }
    private function lastInsertId(): int
    {
        return (int)\InterfaceDB::fetchColumn(strtolower((string)\InterfaceDB::driverName()) === 'sqlite'
            ? 'SELECT last_insert_rowid()' : 'SELECT LAST_INSERT_ID()');
    }
    private function canonicalJson(array $value): string
    {
        $normalise = function(mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) { return $item; }
            if (array_is_list($item)) { return array_map($normalise, $item); }
            ksort($item, SORT_STRING); foreach ($item as $key => $child) { $item[$key] = $normalise($child); } return $item;
        };
        $json = json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) { throw new \RuntimeException('Filing evidence could not be encoded.'); }
        return $json;
    }
}
