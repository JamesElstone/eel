<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Store\AccountingConfigurationStore;

/**
 * Generates and verifies one immutable, environment-independent CT/5
 * IRenvelope per current Corporation Tax period.
 */
final class Ct600GenerationService
{
    public const ARTIFACT_VERSION = 'ct600-prepared-artifact-v1';
    private const TABLE = 'ct600_generated_artifacts';
    private const REQUIRED_COLUMNS = [
        'source_manifest_json',
        'ct_filing_basis_hash',
        'accounts_run_id',
        'accounts_sha256',
        'computation_run_id',
        'computations_sha256',
        'rim_package_id',
        'rim_form_version',
        'rim_artifact_version',
        'rim_package_sha256',
        'mapping_profile_id',
        'mapping_revision_no',
        'mapping_content_hash',
        'serializer_version',
        'package_version',
        'irmark',
        'validation_json',
    ];

    public function __construct(
        private readonly ?HmrcSubmissionPackageService $packages = null
    ) {
    }

    /** @return array<string,mixed> */
    public function generate(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?callable $progress = null
    ): array
    {
        // Retain the action-level setting when this service is invoked by a
        // command or test harness rather than through IxbrlAction.
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        $lastReportedPercent = -1;
        $report = static function (string $message, int $percent) use ($progress, &$lastReportedPercent): void {
            // Several internal checks complete within a few milliseconds.
            // Keep the progress stream readable while preserving meaningful
            // stage changes and the final completion signal.
            if ($percent < 100 && $lastReportedPercent >= 0
                && $percent - $lastReportedPercent < 5) {
                return;
            }
            if ($progress !== null) {
                $progress($message, $percent);
            }
            $lastReportedPercent = $percent;
        };
        $report('Checking the prepared CT600 artifact registry…', 5);
        $schemaError = $this->schemaError();
        if ($schemaError !== null) {
            return $this->failure($schemaError);
        }

        $context = $this->sourceContext(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            true,
            $progress
        );
        if (empty($context['ready'])) {
            return $this->failure(
                'The CT600 XML is not ready to generate.',
                (array)($context['errors'] ?? [])
            );
        }

        $declaration = (array)$context['declaration'];
        $return = (array)$context['return'];
        $accounts = (array)$context['accounts'];
        $computation = (array)$context['computation'];
        $report('Building the final CT600 body and attachments…', 60);
        $assembled = $this->packageTools()->assembleForGeneration(
            $companyId,
            $ctPeriodId,
            [
                'declarant_name' => (string)$declaration['declarant_name'],
                'declarant_status' => (string)$declaration['declarant_status'],
                'declaration_confirmed' => true,
                'authority_confirmed' => true,
                'original_unfiled_confirmed' => true,
                'supplementary_scope_confirmed' => true,
            ],
            $return,
            $accounts,
            $computation,
            static function (string $message, int $percent) use ($report): void {
                $report($message, 60 + (int)floor(max(0, min(100, $percent)) * 0.24));
            }
        );
        if (empty($assembled['ok'])) {
            return $this->failure(
                'The final CT600 XML could not be assembled.',
                (array)($assembled['errors'] ?? [])
            );
        }

        $xml = (string)($assembled['filing_body_xml'] ?? '');
        $sha256 = strtolower(trim((string)($assembled['body_sha256'] ?? '')));
        if ($xml === '' || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || !hash_equals($sha256, hash('sha256', $xml))) {
            return $this->failure('The assembled CT600 XML failed its content hash check.');
        }

        $filingModel = (array)($return['filing_model']['model'] ?? []);
        $identity = (array)($filingModel['identity'] ?? []);
        $ctPeriod = (array)($filingModel['ct_period'] ?? []);
        $approval = (array)$context['approval'];
        $companyNumber = strtoupper(trim((string)($identity['company_number'] ?? '')));
        if (preg_match('/^[A-Z0-9]{1,20}$/D', $companyNumber) !== 1) {
            return $this->failure(
                'The frozen filing approval does not contain a valid Companies House company number.'
            );
        }
        $from = $this->date((string)($ctPeriod['start_date'] ?? ''));
        $to = $this->date((string)($ctPeriod['end_date'] ?? ''));
        if ($from === null || $to === null) {
            return $this->failure('The frozen CT Period dates are invalid.');
        }

        $report('Writing the checksum-named CT600 XML artifact…', 86);
        $filename = implode('_', [
            'ct600',
            $companyNumber,
            (string)$accountingPeriodId,
            (string)(int)$approval['id'],
            $from,
            $to,
            $sha256,
        ]) . '.xml';
        try {
            $path = $this->store($companyNumber, $filename, $sha256, $xml);
        } catch (\Throwable $exception) {
            return $this->failure('The CT600 XML could not be stored.', [$exception->getMessage()]);
        }

        $manifest = (array)($assembled['source_manifest'] ?? []);
        $manifest['prepared_artifact'] = [
            'artifact_version' => self::ARTIFACT_VERSION,
            'filename' => $filename,
            'sha256' => $sha256,
        ];
        $manifest['corporation_tax_return_authorisation'] = $declaration;
        $manifest['filing_evidence_id'] = (string)$context['filing_evidence']['evidence_id'];
        $manifest['filing_evidence_bundle_hash'] = (string)$context['filing_evidence']['bundle_hash'];
        $manifestJson = $this->canonicalJson($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $source = (array)($return['source_manifest'] ?? []);
        $validation = (array)($assembled['validation'] ?? []);
        $report('Registering CT600 provenance and validation evidence…', 91);
        $values = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'filing_approval_id' => (int)$approval['id'],
            'filing_approval_hash' => (string)$approval['basis_hash'],
            'source_manifest_sha256' => $manifestHash,
            'source_manifest_json' => $manifestJson,
            'ct_filing_basis_hash' => (string)($return['filing_model']['basis_hash'] ?? ''),
            'accounts_run_id' => (int)$accounts['run_id'],
            'accounts_sha256' => strtolower((string)$accounts['hash']),
            'computation_run_id' => (int)$computation['run_id'],
            'computations_sha256' => strtolower((string)$computation['hash']),
            'rim_package_id' => (int)($source['rim_package_id'] ?? 0),
            'rim_form_version' => (string)($source['rim_form_version'] ?? ''),
            'rim_artifact_version' => (string)($source['rim_artifact_version'] ?? ''),
            'rim_package_sha256' => strtolower((string)($source['rim_package_sha256'] ?? '')),
            'mapping_profile_id' => (int)($source['mapping_profile_id'] ?? 0),
            'mapping_revision_no' => (int)($source['mapping_revision_no'] ?? 0),
            'mapping_content_hash' => strtolower((string)($source['mapping_content_hash'] ?? '')),
            'serializer_version' => Ct600BuilderService::SERIALIZER_VERSION,
            'package_version' => HmrcSubmissionPackageService::PACKAGE_VERSION,
            'irmark' => (string)($assembled['irmark'] ?? ''),
            'validation_json' => $this->canonicalJson($validation),
            'output_path' => $path,
            'output_filename' => $filename,
            'output_sha256' => $sha256,
            'validation_status' => 'passed',
        ];

        $existing = $this->artifactByIdentity($values);
        if (is_array($existing)) {
            $mismatch = $this->artifactIdentityErrors($existing, $values);
            if ($mismatch !== []) {
                return $this->failure(
                    'An existing CT600 artifact with the same content hash has inconsistent provenance.',
                    $mismatch
                );
            }
        } else {
            try {
                $this->insert($values);
            } catch (\Throwable $exception) {
                return $this->failure(
                    'The CT600 artifact registry could not be updated.',
                    [$exception->getMessage()]
                );
            }
        }

        $report('Rechecking the stored CT600 file, attachments and IRmark…', 96);
        // The complete, deep source context above is still authoritative for
        // this request. Reuse it for the persisted-file check rather than
        // rebuilding every approval, filing model and artifact a second time.
        $registered = is_array($existing) ? $existing : $this->artifactByIdentity($values);
        if (!is_array($registered)) {
            return $this->failure(
                'The generated CT600 XML could not be read back from its registry entry.'
            );
        }
        $persistedErrors = $this->verifyRow($registered, $context, true);
        if ($persistedErrors !== []) {
            return $this->failure(
                'The generated CT600 XML did not pass its persisted integrity check.',
                $persistedErrors
            );
        }
        $artifact = $this->normaliseArtifact($registered);
        \eel_accounts\Support\RequestCache::clear();
        $report('Corporation Tax CT600 XML generated and validated.', 100);

        return [
            'success' => true,
            'ok' => true,
            'errors' => [],
            'warnings' => (array)($assembled['warnings'] ?? []),
            'messages' => ['Corporation Tax Period ' . (int)($ctPeriod['sequence_no'] ?? 0)
                . ' CT600 XML generated and validated.'],
            'artifact' => $artifact,
            'path' => $path,
            'filename' => $filename,
            'sha256' => $sha256,
        ];
    }

    /** @return array<string,mixed> */
    public function status(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        bool $deep = false
    ): array {
        if ($this->packages !== null) {
            return $this->statusUncached($companyId, $accountingPeriodId, $ctPeriodId, $deep);
        }
        $cacheKey = \eel_accounts\Support\RequestCache::key(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $deep ? 'deep' : 'read-model'
        );
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ct600.generated-artifact.status',
            $cacheKey,
            fn(): array => $this->statusUncached(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $deep
            )
        );
    }

    /** @return array<string,mixed> */
    private function statusUncached(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        bool $deep
    ): array {
        $schemaError = $this->schemaError();
        if ($schemaError !== null) {
            return $this->statusFailure($schemaError);
        }
        $context = $this->sourceContext($companyId, $accountingPeriodId, $ctPeriodId, $deep);
        if (empty($context['ready'])) {
            return [
                'ready_to_generate' => false,
                'current' => false,
                'state' => 'blocked',
                'artifact' => [],
                'errors' => (array)($context['errors'] ?? []),
                'warnings' => [],
            ];
        }

        $row = $this->latestArtifact($companyId, $accountingPeriodId, $ctPeriodId);
        if (!is_array($row)) {
            return [
                'ready_to_generate' => true,
                'current' => false,
                'state' => 'not_generated',
                'artifact' => [],
                'errors' => ['Generate the current CT600 XML artifact from iXBRL Generation.'],
                'warnings' => [],
            ];
        }

        $errors = $this->verifyRow($row, $context, $deep);
        return [
            'ready_to_generate' => true,
            'current' => $errors === [],
            'state' => $errors === [] ? 'current' : 'stale',
            'artifact' => $this->normaliseArtifact($row),
            'errors' => $errors,
            'warnings' => [],
            'source' => $context,
        ];
    }

    /** @return array<string,mixed> */
    public function statusForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return ['success' => false, 'periods' => [], 'errors' => ['Select a company and accounting period.']];
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT id
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status <> :superseded
             ORDER BY sequence_no, id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'superseded' => 'superseded',
            ]
        );
        $periods = [];
        foreach ($rows as $row) {
            $ctPeriodId = (int)$row['id'];
            $periods[(string)$ctPeriodId] = $this->status(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId
            );
        }
        return ['success' => true, 'periods' => $periods, 'errors' => []];
    }

    /**
     * Verify and load the exact prepared body used by TEST, TIL and LIVE.
     *
     * @return array<string,mixed>
     */
    public function loadForSubmission(int $companyId, int $ctPeriodId, string $mode): array
    {
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, ['TEST', 'TIL', 'LIVE'], true)) {
            return $this->packageFailure('CT600 submission mode must be TEST, TIL or LIVE.');
        }
        $period = $this->period($companyId, 0, $ctPeriodId);
        if (!is_array($period)) {
            return $this->packageFailure(
                'The selected CT period does not belong to this company or is superseded.'
            );
        }
        $accountingPeriodId = (int)$period['accounting_period_id'];
        $status = $this->status($companyId, $accountingPeriodId, $ctPeriodId, true);
        if (empty($status['current'])) {
            return $this->packageFailure(
                'The current CT600 XML artifact is not ready.',
                (array)($status['errors'] ?? [])
            );
        }
        $artifact = (array)$status['artifact'];
        $source = (array)$status['source'];
        $xml = file_get_contents((string)$artifact['path']);
        if (!is_string($xml) || $xml === '') {
            return $this->packageFailure('The current CT600 XML artifact could not be read.');
        }
        $manifest = json_decode((string)$artifact['source_manifest_json'], true);
        if (!is_array($manifest)) {
            return $this->packageFailure('The current CT600 source manifest is unreadable.');
        }
        $return = (array)$source['return'];
        $filingModel = (array)($return['filing_model']['model'] ?? []);
        $filingIdentity = (array)($filingModel['filing_identity'] ?? []);
        $approval = (array)$source['approval'];
        $declaration = (array)$source['declaration'];
        $accounts = (array)$source['accounts'];
        $computation = (array)$source['computation'];
        $manifestHash = (string)$artifact['source_manifest_sha256'];
        $bodyHash = (string)$artifact['sha256'];
        $validation = json_decode((string)($artifact['validation_json'] ?? ''), true);

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'environment' => $mode,
            'utr' => preg_replace('/\s+/', '', (string)($filingIdentity['utr'] ?? '')) ?? '',
            'filing_body_xml' => $xml,
            'body' => $xml,
            'xml' => $xml,
            'body_sha256' => $bodyHash,
            'ct600_sha256' => $bodyHash,
            'ct600_xml_path' => (string)$artifact['path'],
            'source_manifest' => $manifest,
            'source_manifest_json' => (string)$artifact['source_manifest_json'],
            'source_manifest_sha256' => $manifestHash,
            'package_hash' => hash(
                'sha256',
                HmrcSubmissionPackageService::PACKAGE_VERSION . '|' . $mode . '|'
                    . $manifestHash . '|' . $bodyHash
            ),
            'accounts_ixbrl_path' => (string)$accounts['path'],
            'accounts_run_id' => (int)$accounts['run_id'],
            'accounts_sha256' => (string)$accounts['hash'],
            'computations_ixbrl_path' => (string)$computation['path'],
            'computation_run_id' => (int)$computation['run_id'],
            'computations_sha256' => (string)$computation['hash'],
            'year_end_locked_at' => (string)($approval['year_end_locked_at'] ?? ''),
            'irmark' => (string)$artifact['irmark'],
            'schema_version' => (string)$artifact['rim_form_version'] . '/'
                . (string)$artifact['rim_artifact_version'],
            'validation' => is_array($validation) ? $validation : [],
            'approval_declaration' => $declaration,
            'filing_approval_id' => (int)$approval['id'],
            'filing_approval_hash' => (string)$approval['basis_hash'],
        ];
    }

    /** @return array<string,mixed> */
    public function currentManifest(int $companyId, int $ctPeriodId): array
    {
        $period = $this->period($companyId, 0, $ctPeriodId);
        if (!is_array($period)) {
            return $this->packageFailure('The selected CT period is unavailable.');
        }
        $loaded = $this->loadForSubmission($companyId, $ctPeriodId, 'TEST');
        if (empty($loaded['ok'])) {
            return $loaded;
        }
        return [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'source_manifest' => (array)$loaded['source_manifest'],
            'source_manifest_sha256' => (string)$loaded['source_manifest_sha256'],
            'body_sha256' => (string)$loaded['body_sha256'],
            'package_hash' => hash(
                'sha256',
                (string)$loaded['source_manifest_sha256'] . '|' . (string)$loaded['body_sha256']
            ),
        ];
    }

    /**
     * Render-time manifest lookup. It verifies current immutable provenance,
     * registry integrity, the on-disk hash and basic XML identity without
     * rerunning attachment, IRmark and RIM schema validation.
     *
     * @return array<string,mixed>
     */
    public function currentManifestForStatus(int $companyId, int $ctPeriodId): array
    {
        $period = $this->period($companyId, 0, $ctPeriodId);
        if (!is_array($period)) {
            return $this->packageFailure('The selected CT period is unavailable.');
        }
        $status = $this->status(
            $companyId,
            (int)$period['accounting_period_id'],
            $ctPeriodId,
            false
        );
        if (empty($status['current'])) {
            return $this->packageFailure(
                'The current CT600 XML artifact is not ready.',
                (array)($status['errors'] ?? [])
            );
        }
        $artifact = (array)$status['artifact'];
        $manifest = json_decode((string)($artifact['source_manifest_json'] ?? ''), true);
        if (!is_array($manifest)) {
            return $this->packageFailure('The current CT600 source manifest is unreadable.');
        }
        return [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'source_manifest' => $manifest,
            'source_manifest_sha256' => (string)$artifact['source_manifest_sha256'],
            'body_sha256' => (string)$artifact['sha256'],
            'package_hash' => hash(
                'sha256',
                (string)$artifact['source_manifest_sha256'] . '|' . (string)$artifact['sha256']
            ),
        ];
    }

    /**
     * Return the current registered file after the read-model integrity
     * checks. Generation has already performed the expensive schema and
     * IRmark validation, and transmission repeats those deep checks before
     * filing. A download still verifies the current source identities,
     * manifest, file hash, XML root and registered IRmark before streaming.
     *
     * @return array<string,mixed>
     */
    public function downloadArtifact(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        $status = $this->status($companyId, $accountingPeriodId, $ctPeriodId, false);
        if (empty($status['current'])) {
            return $this->failure(
                'Only the current validated CT600 XML artifact can be downloaded.',
                (array)($status['errors'] ?? [])
            );
        }
        return [
            'success' => true,
            'ok' => true,
            'errors' => [],
            'artifact' => (array)$status['artifact'],
        ];
    }

    /** @return array<string,mixed> */
    private function sourceContext(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        bool $deep,
        ?callable $progress = null
    ): array {
        $report = static function (string $message, int $percent) use ($progress): void {
            if ($progress !== null) {
                $progress($message, $percent);
            }
        };
        $report('Verifying the selected Corporation Tax period…', 5);
        $period = $this->period($companyId, $accountingPeriodId, $ctPeriodId);
        if (!is_array($period)) {
            return ['ready' => false, 'errors' => [
                'The selected CT period does not belong to this company and accounting period.',
            ]];
        }
        $approvalService = new IxbrlAccountsFilingApprovalService();
        $report('Verifying the frozen accounts approval and return authorisation…', 12);
        if ($deep) {
            $approvalProgress = 12;
            $approvalResult = $approvalService->status(
                $companyId,
                $accountingPeriodId,
                static function (string $message) use (&$approvalProgress, $report): void {
                    $approvalProgress = min(24, $approvalProgress + 2);
                    $report($message, $approvalProgress);
                }
            );
        } else {
            $approvalResult = $approvalService->statusForReadModel($companyId, $accountingPeriodId);
        }
        $approval = (array)($approvalResult['approval'] ?? []);
        if ((string)($approvalResult['state'] ?? '') !== 'current' || $approval === []) {
            return ['ready' => false, 'errors' => [
                (string)(((array)($approvalResult['errors'] ?? []))[0]
                    ?? 'A current approved filing basis is required.'),
            ]];
        }
        $declarationResult = $this->frozenDeclaration($approval);
        if (empty($declarationResult['ok'])) {
            return ['ready' => false, 'errors' => (array)$declarationResult['errors']];
        }
        $returnService = new Ct600ReturnModelService();
        $report('Loading and validating the supported CT600 return model…', 26);
        if ($deep) {
            $modelProgress = 26;
            $return = $returnService->build(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                static function (string $message) use (&$modelProgress, $report): void {
                    $modelProgress = min(37, $modelProgress + 2);
                    $report($message, $modelProgress);
                }
            );
        } else {
            $return = $returnService->buildForStatus($companyId, $accountingPeriodId, $ctPeriodId);
        }
        if (empty($return['ok'])) {
            return ['ready' => false, 'errors' => array_values(array_unique(array_merge(
                ['The current CT600 source model is not ready.'],
                array_map('strval', (array)($return['errors'] ?? []))
            )))];
        }
        $report('Resolving the immutable filing-evidence bundle…', 38);
        $filingEvidence = $this->filingEvidence($approval, $companyId, $accountingPeriodId);
        if (!is_array($filingEvidence)) {
            return ['ready' => false, 'errors' => [
                'The current filing approval has no matching immutable filing-evidence bundle.',
            ]];
        }
        $packages = $this->packageTools();
        $report('Checking the HMRC Accounting iXBRL artifact…', 46);
        $accounts = $deep
            ? $packages->locateAccountsIxbrl($companyId, $accountingPeriodId)
            : $packages->locateAccountsIxbrlForStatus($companyId, $accountingPeriodId);
        $report('Checking the matching Computation iXBRL artifact…', 53);
        $computation = $deep
            ? $packages->locateComputationsIxbrlForCtPeriod($companyId, $ctPeriodId)
            : $packages->locateComputationsIxbrlForStatus($companyId, $ctPeriodId);
        $errors = [];
        if (empty($accounts['ok'])) {
            $errors = array_merge($errors, (array)($accounts['errors'] ?? [
                'The HMRC Accounting iXBRL artifact is not ready.',
            ]));
        }
        if (empty($computation['ok'])) {
            $errors = array_merge($errors, (array)($computation['errors'] ?? [
                'The Corporation Tax computation iXBRL artifact is not ready.',
            ]));
        }
        if ($errors !== []) {
            return ['ready' => false, 'errors' => array_values(array_unique(array_map('strval', $errors)))];
        }
        $report('All CT600 source artifacts are current and filing-ready.', 58);

        return [
            'ready' => true,
            'errors' => [],
            'period' => $period,
            'approval' => $approval,
            'declaration' => (array)$declarationResult['declaration'],
            'filing_evidence' => $filingEvidence,
            'return' => $return,
            'accounts' => $accounts,
            'computation' => $computation,
        ];
    }

    /** @return array<string,mixed> */
    private function frozenDeclaration(array $approval): array
    {
        $basisJson = (string)($approval['basis_json'] ?? '');
        $basisHash = strtolower(trim((string)($approval['basis_hash'] ?? '')));
        if ($basisJson === '' || preg_match('/^[a-f0-9]{64}$/D', $basisHash) !== 1
            || !hash_equals($basisHash, hash('sha256', $basisJson))) {
            return $this->packageFailure(
                'The current filing approval failed its Corporation Tax authorisation integrity check.'
            );
        }
        $basis = json_decode($basisJson, true);
        $authorisation = is_array($basis)
            ? (array)($basis['corporation_tax_return_authorisation'] ?? [])
            : [];
        $name = trim((string)($authorisation['declarant_name'] ?? ''));
        $status = trim((string)($authorisation['declarant_status'] ?? ''));
        $declaredAt = trim((string)($authorisation['declaration_at'] ?? ''));
        $errors = [];
        if ($name === '' || $status === '' || $declaredAt === '') {
            $errors[] = 'The filing approval has no complete frozen Corporation Tax return authorisation.';
        }
        foreach ([
            'original_unfiled_confirmed',
            'authority_confirmed',
            'declaration_confirmed',
        ] as $confirmation) {
            if (empty($authorisation[$confirmation])) {
                $errors[] = 'The filing approval does not contain all Corporation Tax return confirmations.';
                break;
            }
        }
        if (!hash_equals($name, trim((string)($approval['declarant_name'] ?? '')))
            || !hash_equals($status, trim((string)($approval['declarant_status'] ?? '')))) {
            $errors[] = 'The frozen Corporation Tax authorisation does not match the filing approval record.';
        }
        if ($errors !== []) {
            return $this->packageFailure($errors[0], array_slice($errors, 1));
        }

        return [
            'ok' => true,
            'errors' => [],
            'declaration' => [
                'declarant_name' => $name,
                'declarant_status' => $status,
                'declaration_at' => $declaredAt,
                'declarant_party_id' => (int)($authorisation['declarant_party_id'] ?? 0) ?: null,
                'declarant_director_id' => (int)($authorisation['declarant_director_id'] ?? 0) ?: null,
                'declarant_role_id' => (int)($authorisation['declarant_role_id'] ?? 0) ?: null,
                'original_unfiled_confirmed' => true,
                'authority_confirmed' => true,
                'declaration_confirmed' => true,
                'approved_at' => (string)($approval['approved_at'] ?? ''),
                'approved_by' => (string)($approval['approved_by'] ?? ''),
            ],
        ];
    }

    /** @return list<string> */
    private function verifyRow(array $row, array $context, bool $deep): array
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!array_key_exists($column, $row) || trim((string)$row[$column]) === '') {
                return ['The registered CT600 XML artifact predates complete provenance tracking and must be regenerated.'];
            }
        }
        $approval = (array)$context['approval'];
        $return = (array)$context['return'];
        $accounts = (array)$context['accounts'];
        $computation = (array)$context['computation'];
        $source = (array)($return['source_manifest'] ?? []);
        $checks = [
            'filing approval' => [
                (string)(int)$approval['id'],
                (string)(int)($row['filing_approval_id'] ?? 0),
            ],
            'filing approval hash' => [
                strtolower((string)$approval['basis_hash']),
                strtolower((string)($row['filing_approval_hash'] ?? '')),
            ],
            'CT-period filing basis' => [
                strtolower((string)($return['filing_model']['basis_hash'] ?? '')),
                strtolower((string)($row['ct_filing_basis_hash'] ?? '')),
            ],
            'accounts iXBRL run' => [
                (string)(int)$accounts['run_id'],
                (string)(int)($row['accounts_run_id'] ?? 0),
            ],
            'accounts iXBRL hash' => [
                strtolower((string)$accounts['hash']),
                strtolower((string)($row['accounts_sha256'] ?? '')),
            ],
            'computation iXBRL run' => [
                (string)(int)$computation['run_id'],
                (string)(int)($row['computation_run_id'] ?? 0),
            ],
            'computation iXBRL hash' => [
                strtolower((string)$computation['hash']),
                strtolower((string)($row['computations_sha256'] ?? '')),
            ],
            'RIM package' => [
                (string)(int)($source['rim_package_id'] ?? 0),
                (string)(int)($row['rim_package_id'] ?? 0),
            ],
            'RIM package hash' => [
                strtolower((string)($source['rim_package_sha256'] ?? '')),
                strtolower((string)($row['rim_package_sha256'] ?? '')),
            ],
            'RIM form version' => [
                (string)($source['rim_form_version'] ?? ''),
                (string)($row['rim_form_version'] ?? ''),
            ],
            'RIM artifact version' => [
                (string)($source['rim_artifact_version'] ?? ''),
                (string)($row['rim_artifact_version'] ?? ''),
            ],
            'CT600 mapping profile' => [
                (string)(int)($source['mapping_profile_id'] ?? 0),
                (string)(int)($row['mapping_profile_id'] ?? 0),
            ],
            'CT600 mapping revision' => [
                (string)(int)($source['mapping_revision_no'] ?? 0),
                (string)(int)($row['mapping_revision_no'] ?? 0),
            ],
            'CT600 mapping hash' => [
                strtolower((string)($source['mapping_content_hash'] ?? '')),
                strtolower((string)($row['mapping_content_hash'] ?? '')),
            ],
        ];
        $errors = [];
        foreach ($checks as $label => [$expected, $actual]) {
            if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
                $errors[] = 'The prepared CT600 XML has a stale or mismatched ' . $label . '.';
            }
        }
        if ((string)($row['serializer_version'] ?? '') !== Ct600BuilderService::SERIALIZER_VERSION
            || (string)($row['package_version'] ?? '') !== HmrcSubmissionPackageService::PACKAGE_VERSION) {
            $errors[] = 'The prepared CT600 XML uses a stale serializer or package version.';
        }
        if ((string)($row['validation_status'] ?? '') !== 'passed') {
            $errors[] = 'The prepared CT600 XML has not passed final validation.';
        }
        $manifestJson = (string)($row['source_manifest_json'] ?? '');
        $manifestHash = strtolower((string)($row['source_manifest_sha256'] ?? ''));
        if ($manifestJson === '' || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1
            || !hash_equals($manifestHash, hash('sha256', $manifestJson))) {
            $errors[] = 'The prepared CT600 source manifest failed its integrity check.';
        } else {
            $manifest = json_decode($manifestJson, true);
            $registeredDeclaration = is_array($manifest)
                ? (array)($manifest['corporation_tax_return_authorisation'] ?? [])
                : [];
            if (!is_array($manifest)
                || !hash_equals(
                    $this->canonicalJson((array)$context['declaration']),
                    $this->canonicalJson($registeredDeclaration)
                )
                || !hash_equals(
                    strtolower((string)($row['output_sha256'] ?? '')),
                    strtolower((string)($manifest['prepared_artifact']['sha256'] ?? ''))
                )
                || !hash_equals(
                    (string)($row['output_filename'] ?? ''),
                    (string)($manifest['prepared_artifact']['filename'] ?? '')
                )
                || !hash_equals(
                    (string)$context['filing_evidence']['evidence_id'],
                    (string)($manifest['filing_evidence_id'] ?? '')
                )
                || !hash_equals(
                    (string)$context['filing_evidence']['bundle_hash'],
                    (string)($manifest['filing_evidence_bundle_hash'] ?? '')
                )) {
                $errors[] = 'The prepared CT600 manifest does not match its frozen authorisation or file identity.';
            }
        }
        $pathError = $this->pathError((string)($row['output_path'] ?? ''));
        if ($pathError !== null) {
            $errors[] = $pathError;
            return array_values(array_unique($errors));
        }
        $fileHash = hash_file('sha256', (string)$row['output_path']);
        if (!is_string($fileHash)
            || !hash_equals(strtolower((string)$row['output_sha256']), strtolower($fileHash))) {
            $errors[] = 'The prepared CT600 XML file is missing or has changed.';
            return array_values(array_unique($errors));
        }
        $xml = file_get_contents((string)$row['output_path']);
        if (!is_string($xml) || $xml === '') {
            $errors[] = 'The prepared CT600 XML file could not be read.';
            return array_values(array_unique($errors));
        }
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
            || !$document->documentElement instanceof \DOMElement
            || $document->documentElement->localName !== 'IRenvelope'
            || $document->documentElement->namespaceURI !== Ct600BuilderService::CT_NAMESPACE) {
            $errors[] = 'The prepared CT600 XML is not a CT/5 IRenvelope.';
            return array_values(array_unique($errors));
        }
        $xpath = new \DOMXPath($document);
        $marks = $xpath->query('//*[local-name()="IRmark" and @Type="generic"]');
        $storedMark = $marks instanceof \DOMNodeList && $marks->length === 1
            ? trim((string)$marks->item(0)?->textContent)
            : '';
        if ($storedMark === '' || !hash_equals($storedMark, (string)$row['irmark'])) {
            $errors[] = 'The prepared CT600 XML IRmark does not match its registry evidence.';
        }
        if ($deep && $errors === []) {
            try {
                $attachmentHashes = [
                    'Accounts' => [
                        'element' => 'Accounts',
                        'hash' => strtolower((string)$accounts['hash']),
                    ],
                    'Computations' => [
                        // CT/5 uses singular <Computation>; the UI label is
                        // plural because it describes the iXBRL artifact.
                        'element' => 'Computation',
                        'hash' => strtolower((string)$computation['hash']),
                    ],
                ];
                foreach ($attachmentHashes as $role => $attachment) {
                    $nodes = $xpath->query(
                        '//*[local-name()="AttachedFiles"]/*[local-name()="XBRLsubmission"]'
                        . '/*[local-name()="' . $attachment['element'] . '"]'
                        . '/*[local-name()="Instance"]/*[local-name()="EncodedInlineXBRLDocument"]'
                    );
                    if (!$nodes instanceof \DOMNodeList || $nodes->length !== 1) {
                        $errors[] = 'The prepared CT600 XML does not contain exactly one '
                            . $role . ' iXBRL attachment.';
                        continue;
                    }
                    $decoded = base64_decode(
                        preg_replace('/\s+/', '', (string)$nodes->item(0)?->textContent) ?? '',
                        true
                    );
                    if (!is_string($decoded)
                        || !hash_equals((string)$attachment['hash'], hash('sha256', $decoded))) {
                        $errors[] = 'The embedded ' . $role
                            . ' iXBRL does not match the current validated artifact.';
                    }
                }
                if ($errors !== []) {
                    return array_values(array_unique($errors));
                }
                $envelope = $this->verificationEnvelope(
                    $xml,
                    (string)(($return['filing_model']['model'] ?? [])['filing_identity']['utr'] ?? '')
                );
                $irmark = (new HmrcIrmarkService())->verify($envelope);
                if (empty($irmark['ok'])) {
                    $errors = array_merge($errors, (array)($irmark['errors'] ?? []));
                }
                $validation = (new HmrcCt600ValidationService())->validateGovTalkEnvelope(
                    $envelope,
                    (array)($return['rim'] ?? [])
                );
                if (empty($validation['ok'])) {
                    $errors = array_merge($errors, (array)($validation['errors'] ?? []));
                }
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        return array_values(array_unique(array_filter(array_map('strval', $errors))));
    }

    private function verificationEnvelope(string $bodyXml, string $utr): string
    {
        $draft = (new \eel_accounts\Client\GovTalkEnvelopeBuilder())->create(
            '2.0',
            'HMRC-CT-CT600',
            'request',
            str_repeat('0', 31) . '1',
            'submit'
        );
        $details = $draft->element($draft->root, 'GovTalkDetails');
        $keys = $draft->element($details, 'Keys');
        $key = $draft->text($keys, 'Key', preg_replace('/\s+/', '', $utr) ?? '');
        $key->setAttribute('Type', 'UTR');
        $target = $draft->element($details, 'TargetDetails');
        $draft->text($target, 'Organisation', 'HMRC');
        $body = new \DOMDocument();
        if (!$body->loadXML($bodyXml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
            || !$body->documentElement instanceof \DOMElement) {
            throw new \RuntimeException('The prepared CT600 XML could not be wrapped for verification.');
        }
        $draft->appendBody()->appendChild(
            $draft->document->importNode($body->documentElement, true)
        );
        return $draft->xml();
    }

    private function store(
        string $companyNumber,
        string $filename,
        string $sha256,
        string $xml
    ): string {
        $root = $this->uploadRoot(false);
        if ($root === '') {
            throw new \RuntimeException(
                'Configure uploads.upload_base_dir before generating CT600 XML.'
            );
        }
        $directory = $root . DIRECTORY_SEPARATOR . $companyNumber . DIRECTORY_SEPARATOR . 'xml';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The CT600 XML directory could not be created.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            $existing = hash_file('sha256', $path);
            if (!is_string($existing) || !hash_equals($sha256, strtolower($existing))) {
                throw new \RuntimeException('An existing CT600 XML artifact failed its content hash check.');
            }
            return $path;
        }
        if (file_put_contents($path, $xml, LOCK_EX) !== strlen($xml)) {
            @unlink($path);
            throw new \RuntimeException('The CT600 XML artifact could not be stored completely.');
        }
        @chmod($path, 0660);
        return $path;
    }

    private function insert(array $values): void
    {
        $columns = array_keys($values);
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')',
            $values
        );
    }

    private function latestArtifact(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): ?array {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND ct_period_id = :ct_period_id
             ORDER BY id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
            ]
        );
        return is_array($row) ? $row : null;
    }

    private function artifactByIdentity(array $values): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND ct_period_id = :ct_period_id
               AND source_manifest_sha256 = :source_manifest_sha256
             LIMIT 1',
            [
                'company_id' => (int)$values['company_id'],
                'accounting_period_id' => (int)$values['accounting_period_id'],
                'ct_period_id' => (int)$values['ct_period_id'],
                'source_manifest_sha256' => (string)$values['source_manifest_sha256'],
            ]
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    private function artifactIdentityErrors(array $existing, array $expected): array
    {
        $errors = [];
        foreach ([
            'company_id',
            'accounting_period_id',
            'ct_period_id',
            'filing_approval_id',
            'filing_approval_hash',
            'source_manifest_sha256',
            'output_path',
            'output_filename',
        ] as $key) {
            if ((string)($existing[$key] ?? '') !== (string)($expected[$key] ?? '')) {
                $errors[] = 'The existing artifact has a different ' . str_replace('_', ' ', $key) . '.';
            }
        }
        return $errors;
    }

    private function normaliseArtifact(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'company_id' => (int)($row['company_id'] ?? 0),
            'accounting_period_id' => (int)($row['accounting_period_id'] ?? 0),
            'ct_period_id' => (int)($row['ct_period_id'] ?? 0),
            'filing_approval_id' => (int)($row['filing_approval_id'] ?? 0),
            'filing_approval_hash' => (string)($row['filing_approval_hash'] ?? ''),
            'source_manifest_json' => (string)($row['source_manifest_json'] ?? ''),
            'source_manifest_sha256' => (string)($row['source_manifest_sha256'] ?? ''),
            'filename' => (string)($row['output_filename'] ?? ''),
            'path' => (string)($row['output_path'] ?? ''),
            'sha256' => (string)($row['output_sha256'] ?? ''),
            'validation_status' => (string)($row['validation_status'] ?? ''),
            'validation_json' => (string)($row['validation_json'] ?? ''),
            'generated_at' => (string)($row['generated_at'] ?? ''),
            'irmark' => (string)($row['irmark'] ?? ''),
            'rim_form_version' => (string)($row['rim_form_version'] ?? ''),
            'rim_artifact_version' => (string)($row['rim_artifact_version'] ?? ''),
        ];
    }

    private function period(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): ?array {
        if ($companyId <= 0 || $ctPeriodId <= 0
            || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return null;
        }
        $params = [
            'company_id' => $companyId,
            'ct_period_id' => $ctPeriodId,
            'superseded' => 'superseded',
        ];
        $where = '';
        if ($accountingPeriodId > 0) {
            $where = ' AND accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM corporation_tax_periods
             WHERE id = :ct_period_id
               AND company_id = :company_id'
                . $where
                . ' AND status <> :superseded
             LIMIT 1',
            $params
        );
        return is_array($row) ? $row : null;
    }

    private function filingEvidence(
        array $approval,
        int $companyId,
        int $accountingPeriodId
    ): ?array {
        $evidenceBundleId = (int)($approval['evidence_bundle_id'] ?? 0);
        if ($evidenceBundleId <= 0 || !\InterfaceDB::tableExists('filing_evidence_bundles')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT id, evidence_id, bundle_hash
             FROM filing_evidence_bundles
             WHERE id = :id
               AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            [
                'id' => $evidenceBundleId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        if (!is_array($row)
            || trim((string)($row['evidence_id'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/i', (string)($row['bundle_hash'] ?? '')) !== 1) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'evidence_id' => (string)$row['evidence_id'],
            'bundle_hash' => strtolower((string)$row['bundle_hash']),
        ];
    }

    private function pathError(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            return 'The prepared CT600 XML file is missing.';
        }
        $root = $this->uploadRoot(true);
        $real = realpath($path);
        if ($root === '' || !is_string($real)
            || !str_starts_with(
                rtrim(str_replace('\\', '/', $real), '/'),
                rtrim(str_replace('\\', '/', $root), '/') . '/'
            )) {
            return 'The prepared CT600 XML path is outside the configured upload directory.';
        }
        return null;
    }

    private function uploadRoot(bool $real): string
    {
        $uploads = (array)AccountingConfigurationStore::uploads();
        $configured = rtrim(trim((string)($uploads['upload_base_dir'] ?? '')), '\\/');
        if ($configured === '') {
            return '';
        }
        if (!$real) {
            return $configured;
        }
        $resolved = realpath($configured);
        return is_string($resolved) ? rtrim($resolved, '\\/') : '';
    }

    private function schemaError(): ?string
    {
        if (!\InterfaceDB::tableExists(self::TABLE)) {
            return 'Apply the prepared CT600 artifact migration before generating CT600 XML.';
        }
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!\InterfaceDB::columnExists(self::TABLE, $column)) {
                return 'Apply the prepared CT600 artifact provenance migration before generating CT600 XML.';
            }
        }
        return null;
    }

    private function packageTools(): HmrcSubmissionPackageService
    {
        return $this->packages ?? new HmrcSubmissionPackageService();
    }

    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === trim($value)
            ? trim($value)
            : null;
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
        return \eel_accounts\Support\Utf8::json(
            $normalise($value),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function statusFailure(string $message): array
    {
        return [
            'ready_to_generate' => false,
            'current' => false,
            'state' => 'blocked',
            'artifact' => [],
            'errors' => [$message],
            'warnings' => [],
        ];
    }

    private function failure(string $message, array $details = []): array
    {
        return [
            'success' => false,
            'ok' => false,
            'artifact' => [],
            'warnings' => [],
            'errors' => array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge([$message], $details)
            ), static fn(string $item): bool => trim($item) !== ''))),
        ];
    }

    private function packageFailure(string $message, array $details = []): array
    {
        $failure = $this->failure($message, $details);
        unset($failure['success'], $failure['artifact']);
        return $failure;
    }
}
