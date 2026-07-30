<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Assembles prepared CT600 bodies for generation and verifies registered
 * prepared artifacts before they are handed to Transaction Engine.
 */
final class HmrcSubmissionPackageService
{
    public const PACKAGE_VERSION = 'ct600-package-v1';
    private const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';

    private ?\Closure $accountsLocator;
    private ?\Closure $computationLocator;
    private ?\Closure $ct600Builder;

    /**
     * @param null|callable(int,int):array $accountsLocator
     * @param null|callable(int,int):array $computationLocator
     * @param null|callable(int,int,int,array):array $ct600Builder
     */
    public function __construct(
        ?callable $accountsLocator = null,
        ?callable $computationLocator = null,
        ?callable $ct600Builder = null
    ) {
        $this->accountsLocator = $accountsLocator !== null ? \Closure::fromCallable($accountsLocator) : null;
        $this->computationLocator = $computationLocator !== null ? \Closure::fromCallable($computationLocator) : null;
        $this->ct600Builder = $ct600Builder !== null ? \Closure::fromCallable($ct600Builder) : null;
    }

    public function locateAccountsIxbrl(int $companyId, int $accountingPeriodId): array
    {
        return $this->accountsLocator !== null
            ? (array)($this->accountsLocator)($companyId, $accountingPeriodId)
            : (new IxbrlStatutoryAccountsArtifactService())->locate($companyId, $accountingPeriodId);
    }

    /**
     * Fast, approval-pinned evidence for a status card. Submission preparation
     * deliberately uses locateAccountsIxbrl() and performs the full rebuild.
     */
    public function locateAccountsIxbrlForStatus(int $companyId, int $accountingPeriodId): array
    {
        if ($this->accountsLocator !== null) {
            return (array)($this->accountsLocator)($companyId, $accountingPeriodId);
        }
        $cacheKey = \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ixbrl.statutory-accounts-artifact.status',
            $cacheKey,
            fn(): array => (new IxbrlStatutoryAccountsArtifactService())->locate(
                $companyId,
                $accountingPeriodId,
                true
            )
        );
    }

    public function locateComputationsIxbrl(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return $this->artifactFailure('missing', 'Select a company and accounting period with CT periods.');
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND status <> :superseded
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        );
        if ($periods === []) {
            return $this->artifactFailure('missing', 'No current CT periods exist for the accounting period.');
        }
        $artifacts = [];
        foreach ($periods as $period) {
            $artifact = $this->locateComputationsIxbrlForCtPeriod($companyId, (int)$period['id']);
            if (empty($artifact['ok'])) {
                return $artifact + ['artifacts' => $artifacts];
            }
            $artifacts[] = $artifact;
        }
        return ['ok' => true, 'state' => 'ready', 'artifacts' => $artifacts, 'errors' => [], 'warnings' => []];
    }

    public function locateComputationsIxbrlForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        if ($this->computationLocator !== null) {
            return (array)($this->computationLocator)($companyId, $ctPeriodId);
        }
        if ($companyId <= 0 || $ctPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return $this->artifactFailure('missing', 'Select a company and CT period.');
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id FROM corporation_tax_periods
             WHERE id = :id AND company_id = :company_id AND status <> :superseded LIMIT 1',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'superseded' => 'superseded']
        );
        if (!is_array($period)) {
            return $this->artifactFailure('mismatched_period', 'The requested CT period does not belong to this company or is superseded.');
        }
        try {
            $status = (new IxbrlTaxComputationService())->status(
                $companyId,
                (int)$period['accounting_period_id'],
                $ctPeriodId
            );
        } catch (\Throwable $exception) {
            return $this->artifactFailure('error', 'The computations iXBRL artifact could not be verified.', [$exception->getMessage()]);
        }
        if (empty($status['fileable'])) {
            $errors = (array)($status['fileable_errors'] ?? $status['artifact_errors'] ?? $status['errors'] ?? []);
            return $this->artifactFailure('not_ready', (string)($errors[0] ?? 'The computations iXBRL artifact is not filing-ready.'), $errors);
        }
        $run = (array)$status['run'];
        $taxonomy = [];
        if ((int)($run['computation_taxonomy_package_id'] ?? 0) > 0
            && \InterfaceDB::tableExists('hmrc_ct_computation_packages')) {
            $taxonomy = (array)(\InterfaceDB::fetchOne(
                'SELECT taxonomy_version, artifact_version FROM hmrc_ct_computation_packages WHERE id = :id LIMIT 1',
                ['id' => (int)$run['computation_taxonomy_package_id']]
            ) ?: []);
        }
        return [
            'ok' => true,
            'state' => 'ready',
            'run_id' => (int)$run['id'],
            'ct_period_id' => $ctPeriodId,
            'accounting_period_id' => (int)$period['accounting_period_id'],
            'path' => (string)$run['generated_path'],
            'filename' => (string)$run['generated_filename'],
            'hash' => (string)$run['output_sha256'],
            'basis_hash' => (string)($run['filing_basis_hash'] ?? ''),
            'mapping_profile_id' => (int)($run['ixbrl_mapping_profile_id'] ?? 0),
            'mapping_hash' => (string)($run['ixbrl_mapping_hash'] ?? ''),
            'tagging_version' => (string)($run['ixbrl_tagging_version'] ?? ''),
            'presentation_version' => (string)($run['ixbrl_presentation_version'] ?? ''),
            'taxonomy_package_id' => (int)($run['computation_taxonomy_package_id'] ?? 0),
            'taxonomy_package_hash' => (string)($run['computation_taxonomy_package_hash'] ?? ''),
            'taxonomy_profile' => trim((string)($taxonomy['taxonomy_version'] ?? '')) !== ''
                ? (string)$taxonomy['taxonomy_version'] . '/' . (string)$taxonomy['artifact_version']
                : '',
            'validation_status' => (string)($run['external_validation_status'] ?? ''),
            'warnings' => json_decode((string)($run['external_validation_warnings_json'] ?? '[]'), true) ?: [],
            'errors' => [],
        ];
    }

    /** Lightweight immutable-run check used only while rendering status. */
    public function locateComputationsIxbrlForStatus(int $companyId, int $ctPeriodId): array
    {
        if ($this->computationLocator !== null) {
            return (array)($this->computationLocator)($companyId, $ctPeriodId);
        }
        $cacheKey = \eel_accounts\Support\RequestCache::key($companyId, $ctPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ixbrl.computation-artifact.status',
            $cacheKey,
            fn(): array => $this->locateComputationsIxbrlForStatusUncached(
                $companyId,
                $ctPeriodId
            )
        );
    }

    /** @return array<string,mixed> */
    private function locateComputationsIxbrlForStatusUncached(
        int $companyId,
        int $ctPeriodId
    ): array {
        $run = \InterfaceDB::fetchOne(
            'SELECT r.*, ctp.accounting_period_id
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_computation_runs r ON r.id = ctp.latest_computation_run_id
             WHERE ctp.id = :id AND ctp.company_id = :company_id AND ctp.status <> :superseded LIMIT 1',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'superseded' => 'superseded']
        );
        if (!is_array($run) || (int)($run['id'] ?? 0) <= 0) {
            return $this->artifactFailure('missing', 'No current computation iXBRL run exists for this CT period.');
        }
        if ((string)($run['ixbrl_status'] ?? '') !== 'validated'
            || (string)($run['validation_status'] ?? '') !== 'passed'
            || (string)($run['external_validation_status'] ?? '') !== 'passed') {
            return $this->artifactFailure('not_ready', 'The current computation iXBRL artifact has not passed validation.');
        }
        if ((string)($run['ixbrl_tagging_version'] ?? '') !== HmrcCtComputationReportProfile::TAGGING_VERSION
            || (string)($run['ixbrl_presentation_version'] ?? '') !== IxbrlTaxComputationService::PRESENTATION_VERSION) {
            return $this->artifactFailure(
                'not_ready',
                'The current computation iXBRL artifact uses a stale or missing tagging/presentation version.'
            );
        }
        $path = trim((string)($run['generated_path'] ?? ''));
        $hash = strtolower(trim((string)($run['output_sha256'] ?? '')));
        if ($path === '' || !is_file($path) || $hash === ''
            || !hash_equals($hash, strtolower(trim((string)($run['external_validated_sha256'] ?? ''))))
            || !hash_equals($hash, (string)(new IxbrlArtifactFingerprintService())->sha256($path))) {
            return $this->artifactFailure('missing', 'The current computation iXBRL artifact is missing or has changed.');
        }
        return [
            'ok' => true, 'state' => 'ready', 'run_id' => (int)$run['id'],
            'ct_period_id' => $ctPeriodId, 'accounting_period_id' => (int)$run['accounting_period_id'],
            'path' => $path, 'filename' => (string)$run['generated_filename'], 'hash' => $hash,
            'basis_hash' => (string)($run['filing_basis_hash'] ?? ''),
            'mapping_profile_id' => (int)($run['ixbrl_mapping_profile_id'] ?? 0),
            'mapping_hash' => (string)($run['ixbrl_mapping_hash'] ?? ''),
            'tagging_version' => (string)($run['ixbrl_tagging_version'] ?? ''),
            'presentation_version' => (string)($run['ixbrl_presentation_version'] ?? ''),
            'taxonomy_package_id' => (int)($run['computation_taxonomy_package_id'] ?? 0),
            'taxonomy_package_hash' => (string)($run['computation_taxonomy_package_hash'] ?? ''),
            'validation_status' => (string)($run['external_validation_status'] ?? ''), 'warnings' => [], 'errors' => [],
        ];
    }

    /**
     * Load the already-generated CT600 body for transmission. Declaration and
     * source overrides are retained only as compatibility parameters and are
     * deliberately ignored: transmission may not rebuild or alter the body.
     *
     * @return array<string,mixed>
     */
    public function prepareForSubmission(
        int $companyId,
        int $ctPeriodId,
        string $mode,
        array $declaration = [],
        ?array $returnOverride = null,
        ?array $accountsOverride = null,
        ?array $computationOverride = null
    ): array {
        unset($declaration, $returnOverride, $accountsOverride, $computationOverride);
        return (new Ct600GenerationService($this))->loadForSubmission(
            $companyId,
            $ctPeriodId,
            $mode
        );
    }

    /**
     * Build the environment-independent final CT/5 IRenvelope. Only
     * Ct600GenerationService should call this method.
     *
     * @return array<string,mixed>
     */
    public function assembleForGeneration(
        int $companyId,
        int $ctPeriodId,
        array $declaration,
        ?array $returnOverride = null,
        ?array $accountsOverride = null,
        ?array $computationOverride = null,
        ?callable $progress = null
    ): array {
        $report = static function (string $message, int $percent) use ($progress): void {
            if ($progress !== null) {
                $progress($message, $percent);
            }
        };
        $report('Resolving the CT600 return and frozen declaration…', 0);
        $period = $this->period($companyId, $ctPeriodId);
        if (!is_array($period)) {
            return $this->failure('The selected CT period does not belong to this company or is superseded.');
        }
        $accountingPeriodId = (int)$period['accounting_period_id'];

        $builder = $this->ct600Builder !== null
            ? (array)($this->ct600Builder)($companyId, $accountingPeriodId, $ctPeriodId, $declaration)
            : (new Ct600BuilderService())->buildForGeneration(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $declaration,
                $returnOverride
            );
        if (empty($builder['ok'])) {
            return $this->failure('The CT600 filing body is not ready.', (array)($builder['errors'] ?? []));
        }
        $report('Locating the validated Accounting and Computation iXBRLs…', 18);
        $return = (array)$builder['return_model'];
        $filingModel = (array)($return['filing_model']['model'] ?? []);
        $accounts = $accountsOverride ?? $this->locateAccountsIxbrl($companyId, $accountingPeriodId);
        $computation = $computationOverride ?? $this->locateComputationsIxbrlForCtPeriod($companyId, $ctPeriodId);
        $artifactErrors = [];
        if (empty($accounts['ok'])) {
            $artifactErrors = array_merge($artifactErrors, (array)($accounts['errors'] ?? ['The accounts iXBRL artifact is not ready.']));
        }
        if (empty($computation['ok'])) {
            $artifactErrors = array_merge($artifactErrors, (array)($computation['errors'] ?? ['The computations iXBRL artifact is not ready.']));
        }
        if ($artifactErrors !== []) {
            return $this->failure('The filing iXBRL artifacts are not ready.', $artifactErrors);
        }

        $report('Checking CT600 values against both iXBRL artifacts…', 32);
        $crossDocument = $this->crossDocumentChecks(
            $filingModel,
            $accounts,
            $computation,
            (string)($return['filing_model']['basis_hash'] ?? '')
        );
        if (!$crossDocument['ok']) {
            return $this->failure('The CT600 and iXBRL artifacts are inconsistent.', (array)$crossDocument['errors']);
        }

        $report('Validating the CT600 body against the pinned RIM…', 45);
        $baseValidation = (new HmrcCt600ValidationService())->validateIrEnvelope(
            (string)$builder['filing_body_xml'],
            (array)$return['rim']
        );
        if (empty($baseValidation['ok'])) {
            return $this->failure('The provisional CT600 failed its pinned RIM schema.', (array)($baseValidation['errors'] ?? []));
        }

        try {
            $report('Embedding the Accounting and Computation iXBRLs…', 58);
            $attachedBody = $this->attachIxbrl((string)$builder['filing_body_xml'], $accounts, $computation);
            $report('Building the temporary GovTalk validation envelope…', 68);
            $validationEnvelope = $this->validationEnvelope(
                $attachedBody,
                (string)$filingModel['filing_identity']['utr']
            );
            $report('Applying and verifying the HMRC IRmark…', 78);
            $marked = (new HmrcIrmarkService())->apply($validationEnvelope);
            if (empty($marked['ok'])) {
                return $this->failure('The generic HMRC IRmark could not be applied.', (array)($marked['errors'] ?? []));
            }
            $finalEnvelope = (string)$marked['xml'];
            $report('Validating the final IRmarked CT600 package…', 88);
            $finalValidation = (new HmrcCt600ValidationService())->validateGovTalkEnvelope(
                $finalEnvelope,
                (array)$return['rim']
            );
            if (empty($finalValidation['ok'])) {
                return $this->failure('The final CT600 package failed local HMRC validation.', (array)($finalValidation['errors'] ?? []));
            }
            $filingBody = $this->extractFilingBody($finalEnvelope);
            $bodyHash = hash('sha256', $filingBody);
            $path = '';
        } catch (\Throwable $exception) {
            return $this->failure('The immutable CT600 package could not be assembled.', [$exception->getMessage()]);
        }

        $report('Recording the immutable CT600 source manifest…', 96);
        $validationArtifacts = (array)($baseValidation['artifacts']['artifacts'] ?? []);
        $artifactHashes = [];
        foreach ($validationArtifacts as $role => $artifact) {
            $artifactHashes[(string)$role] = (string)($artifact['sha256'] ?? '');
        }
        ksort($artifactHashes, SORT_STRING);
        $sourceManifest = array_replace((array)$return['source_manifest'], [
            'package_version' => self::PACKAGE_VERSION,
            'accounts_ixbrl' => [
                'run_id' => (int)$accounts['run_id'],
                'basis_hash' => (string)($accounts['basis_hash'] ?? ''),
                'sha256' => (string)$accounts['hash'],
                'filename' => (string)$accounts['filename'],
            ],
            'computations_ixbrl' => [
                'run_id' => (int)$computation['run_id'],
                'basis_hash' => (string)($computation['basis_hash'] ?? ''),
                'sha256' => (string)$computation['hash'],
                'filename' => (string)$computation['filename'],
                'mapping_profile_id' => (int)($computation['mapping_profile_id'] ?? 0),
                'mapping_hash' => (string)($computation['mapping_hash'] ?? ''),
                'tagging_version' => (string)($computation['tagging_version'] ?? ''),
                'presentation_version' => (string)($computation['presentation_version'] ?? ''),
                'taxonomy_package_id' => (int)($computation['taxonomy_package_id'] ?? 0),
                'taxonomy_package_hash' => (string)($computation['taxonomy_package_hash'] ?? ''),
            ],
            'rim_validation_artifact_hashes' => $artifactHashes,
            'cross_document_policy' => (string)$crossDocument['policy_version'],
            'artifacts' => $this->manifestArtifacts(
                $return,
                $builder,
                $accounts,
                $computation,
                $bodyHash,
                'ct600-prepared-' . $bodyHash . '.xml',
                'passed'
            ),
        ]);
        $manifestJson = $this->canonicalJson($sourceManifest);
        $manifestHash = hash('sha256', $manifestJson);
        $packageHash = hash('sha256', self::PACKAGE_VERSION . '|PREPARED|' . $manifestHash . '|' . $bodyHash);
        $report('The final CT600 package is assembled and validated.', 100);

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => array_values(array_unique(array_merge(
                (array)($builder['warnings'] ?? []),
                (array)($accounts['warnings'] ?? []),
                (array)($computation['warnings'] ?? [])
            ))),
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'environment' => 'PREPARED',
            'utr' => (string)$filingModel['filing_identity']['utr'],
            'filing_body_xml' => $filingBody,
            'body' => $filingBody,
            'xml' => $filingBody,
            'body_sha256' => $bodyHash,
            'ct600_sha256' => $bodyHash,
            'ct600_xml_path' => $path,
            'source_manifest' => $sourceManifest,
            'source_manifest_json' => $manifestJson,
            'source_manifest_sha256' => $manifestHash,
            'package_hash' => $packageHash,
            'accounts_ixbrl_path' => (string)$accounts['path'],
            'accounts_run_id' => (int)$accounts['run_id'],
            'accounts_sha256' => (string)$accounts['hash'],
            'computations_ixbrl_path' => (string)$computation['path'],
            'computation_run_id' => (int)$computation['run_id'],
            'computations_sha256' => (string)$computation['hash'],
            'year_end_locked_at' => (string)($filingModel['approval']['year_end_locked_at'] ?? ''),
            'irmark' => (string)$marked['base64'],
            'schema_version' => (string)($return['rim']['form_version'] ?? '') . '/'
                . (string)($return['rim']['artifact_version'] ?? ''),
            'validation' => [
                'base_ct_xsd' => $baseValidation,
                'final' => $finalValidation,
                'cross_document' => $crossDocument,
                'irmark' => [
                    'version' => (string)$marked['version'],
                    'base64' => (string)$marked['base64'],
                    'base32' => (string)$marked['base32'],
                    'verified' => true,
                ],
            ],
        ];
    }

    /**
     * Returns the verified manifest of the current prepared CT600 artifact.
     * @return array<string,mixed>
     */
    public function currentSourceManifest(
        int $companyId,
        int $ctPeriodId,
        ?array $returnOverride = null,
        ?array $accountsOverride = null,
        ?array $computationOverride = null,
        bool $includeCurrentBody = true
    ): array
    {
        unset(
            $returnOverride,
            $accountsOverride,
            $computationOverride,
            $includeCurrentBody
        );
        return (new Ct600GenerationService($this))->currentManifest(
            $companyId,
            $ctPeriodId
        );
    }

    /** Compatibility accessor for already persisted outbound evidence. */
    public function buildSubmissionEnvelope(int $submissionId): array
    {
        if ($submissionId <= 0 || !\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            return $this->failure('Select a persisted CT600 submission.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT request_body_path, package_hash FROM hmrc_ct600_submissions WHERE id = :id LIMIT 1',
            ['id' => $submissionId]
        );
        $path = is_array($row) ? trim((string)($row['request_body_path'] ?? '')) : '';
        if ($path === '' || !is_file($path)) {
            return $this->failure('No persisted GovTalk request body exists for this submission.');
        }
        $body = file_get_contents($path);
        return is_string($body)
            ? ['ok' => true, 'path' => $path, 'body' => $body, 'errors' => [], 'package_hash' => (string)$row['package_hash']]
            : $this->failure('The persisted GovTalk request body could not be read.');
    }

    public function hashPackage(int $submissionId): string
    {
        if ($submissionId <= 0 || !\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            return '';
        }
        return (string)(\InterfaceDB::fetchColumn(
            'SELECT package_hash FROM hmrc_ct600_submissions WHERE id = :id LIMIT 1',
            ['id' => $submissionId]
        ) ?: '');
    }

    /** @return array<string,mixed> */
    private function crossDocumentChecks(
        array $model,
        array $accounts,
        array $computation,
        string $filingBasisHash
    ): array
    {
        $errors = [];
        $expectedAccountsBasis = strtolower(trim((string)($model['accounts_report']['basis_hash'] ?? '')));
        $actualAccountsBasis = strtolower(trim((string)($accounts['basis_hash'] ?? '')));
        if (!$this->validHash($expectedAccountsBasis)
            || !$this->validHash($actualAccountsBasis)
            || !hash_equals($expectedAccountsBasis, $actualAccountsBasis)) {
            $errors[] = 'The accounts iXBRL does not belong to the approved accounts-report basis.';
        }
        $expectedComputationBasis = strtolower(trim($filingBasisHash));
        $actualComputationBasis = strtolower(trim((string)($computation['basis_hash'] ?? '')));
        if (!$this->validHash($expectedComputationBasis)
            || !$this->validHash($actualComputationBasis)
            || !hash_equals($expectedComputationBasis, $actualComputationBasis)) {
            $errors[] = 'The computations iXBRL does not belong to the approved CT-period filing basis.';
        }
        if ((int)($computation['ct_period_id'] ?? 0) !== (int)($model['ct_period']['id'] ?? 0)) {
            $errors[] = 'The computations iXBRL belongs to a different CT period.';
        }

        $companyNumber = (string)($model['identity']['company_number'] ?? '');
        $utr = (string)($model['filing_identity']['utr'] ?? '');
        $accounting = (array)($model['accounting_period'] ?? []);
        $ct = (array)($model['ct_period'] ?? []);
        $accountsSignals = $this->ixbrlSignals((string)$accounts['path']);
        $computationSignals = $this->ixbrlSignals((string)$computation['path']);
        if (!$accountsSignals['ok']) {
            $errors = array_merge($errors, (array)$accountsSignals['errors']);
        }
        if (!$computationSignals['ok']) {
            $errors = array_merge($errors, (array)$computationSignals['errors']);
        }
        if (!in_array($companyNumber, (array)$accountsSignals['identifiers'], true)) {
            $errors[] = 'The accounts iXBRL entity identifier does not match the frozen company number.';
        }
        if (!in_array($companyNumber, (array)$computationSignals['identifiers'], true)) {
            $errors[] = 'The computations iXBRL entity identifier does not match the frozen company number.';
        }
        foreach ([(string)$accounting['start_date'], (string)$accounting['end_date']] as $date) {
            if (!in_array($date, (array)$accountsSignals['dates'], true)) {
                $errors[] = 'The accounts iXBRL does not contain the frozen accounting-period date ' . $date . '.';
            }
        }
        foreach ([(string)$ct['start_date'], (string)$ct['end_date']] as $date) {
            if (!in_array($date, (array)$computationSignals['dates'], true)) {
                $errors[] = 'The computations iXBRL does not contain the frozen CT-period date ' . $date . '.';
            }
        }
        if (!str_contains((string)$computationSignals['text'], $utr)) {
            $errors[] = 'The computations iXBRL does not contain the frozen Corporation Tax UTR.';
        }
        return [
            'ok' => $errors === [],
            'policy_version' => 'ct600-cross-document-v1',
            'errors' => array_values(array_unique($errors)),
            'warnings' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function ixbrlSignals(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            return ['ok' => false, 'identifiers' => [], 'dates' => [], 'text' => '', 'errors' => ['An iXBRL file is missing.']];
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $messages = array_map(static fn(\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return ['ok' => false, 'identifiers' => [], 'dates' => [], 'text' => '', 'errors' => array_merge(['An iXBRL file is not well-formed XML.'], $messages)];
        }
        $xpath = new \DOMXPath($document);
        $identifiers = [];
        foreach ($xpath->query('//*[local-name()="identifier"]') ?: [] as $node) {
            $value = trim((string)$node->textContent);
            if ($value !== '') { $identifiers[] = $value; }
        }
        $dates = [];
        foreach ($xpath->query('//*[local-name()="startDate" or local-name()="endDate" or local-name()="instant"]') ?: [] as $node) {
            $value = trim((string)$node->textContent);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) { $dates[] = $value; }
        }
        return [
            'ok' => true,
            'identifiers' => array_values(array_unique($identifiers)),
            'dates' => array_values(array_unique($dates)),
            'text' => preg_replace('/\s+/', ' ', (string)$document->textContent) ?? '',
            'errors' => [],
        ];
    }

    private function attachIxbrl(string $xml, array $accounts, array $computation): string
    {
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
            throw new \RuntimeException('The provisional CT600 XML could not be parsed for attachment assembly.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ct', Ct600BuilderService::CT_NAMESPACE);
        $returns = $xpath->query('/ct:IRenvelope/ct:CompanyTaxReturn');
        if (!$returns instanceof \DOMNodeList || $returns->length !== 1 || !$returns->item(0) instanceof \DOMElement) {
            throw new \RuntimeException('The provisional CT600 has no unique CompanyTaxReturn element.');
        }
        /** @var \DOMElement $companyReturn */
        $companyReturn = $returns->item(0);
        $attached = $this->ctElement($document, $companyReturn, 'AttachedFiles');
        $submission = $this->ctElement($document, $attached, 'XBRLsubmission');
        $this->appendEncodedIxbrl($document, $submission, 'Computation', $computation);
        $this->appendEncodedIxbrl($document, $submission, 'Accounts', $accounts);
        $result = $document->saveXML();
        if (!is_string($result) || $result === '') {
            throw new \RuntimeException('The attached CT600 body could not be serialized.');
        }
        return $result;
    }

    private function appendEncodedIxbrl(\DOMDocument $document, \DOMElement $parent, string $type, array $artifact): void
    {
        $path = (string)$artifact['path'];
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException($type . ' iXBRL could not be read for attachment.');
        }
        $filename = basename((string)($artifact['filename'] ?? $path));
        if ($filename === '' || preg_match('~[£$#\~|€/\\\\:*"<>]~u', $filename) === 1) {
            throw new \RuntimeException($type . ' iXBRL has a filename unsupported by the CT600 schema.');
        }
        $details = $this->ctElement($document, $parent, $type);
        $instance = $this->ctElement($document, $details, 'Instance');
        $encoded = $this->ctElement($document, $instance, 'EncodedInlineXBRLDocument', base64_encode($bytes));
        $encoded->setAttribute('Filename', $filename);
        $encoded->setAttribute('entryPoint', 'yes');
    }

    private function validationEnvelope(string $filingBody, string $utr): string
    {
        $draft = (new \eel_accounts\Client\GovTalkEnvelopeBuilder())->create(
            '2.0',
            'HMRC-CT-CT600',
            'request',
            str_repeat('0', 31) . '1',
            'submit'
        );
        $document = $draft->document;
        $root = $draft->root;
        $govTalk = $draft->element($root, 'GovTalkDetails');
        $keys = $draft->element($govTalk, 'Keys');
        $key = $draft->text($keys, 'Key', $utr);
        $key->setAttribute('Type', 'UTR');
        $target = $draft->element($govTalk, 'TargetDetails');
        $draft->text($target, 'Organisation', 'HMRC');
        $body = $draft->appendBody();
        $inner = new \DOMDocument();
        if (!$inner->loadXML($filingBody, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
            || !$inner->documentElement instanceof \DOMElement) {
            throw new \RuntimeException('The attached filing body could not be imported into GovTalk.');
        }
        $body->appendChild($document->importNode($inner->documentElement, true));
        return $draft->xml();
    }

    private function extractFilingBody(string $govTalkXml): string
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($govTalkXml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
            throw new \RuntimeException('The IRmarked GovTalk XML could not be parsed.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('hd', self::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ct', Ct600BuilderService::CT_NAMESPACE);
        $nodes = $xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope');
        if (!$nodes instanceof \DOMNodeList || $nodes->length !== 1 || !$nodes->item(0) instanceof \DOMElement) {
            throw new \RuntimeException('The final GovTalk XML has no unique CT filing body.');
        }
        $xml = $document->saveXML($nodes->item(0));
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('The final CT filing body could not be serialized.');
        }
        return $xml;
    }

    private function period(int $companyId, int $ctPeriodId): ?array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_periods
             WHERE id = :id AND company_id = :company_id AND status <> :superseded LIMIT 1',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'superseded' => 'superseded']
        );
        return is_array($row) ? $row : null;
    }

    private function ctElement(\DOMDocument $document, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $element = $document->createElementNS(Ct600BuilderService::CT_NAMESPACE, $name);
        if ($value !== null) { $element->appendChild($document->createTextNode(\eel_accounts\Support\Utf8::normalize($value))); }
        $parent->appendChild($element);
        return $element;
    }

    /** @return list<array<string,mixed>> */
    private function manifestArtifacts(
        array $return,
        array $builder,
        array $accounts,
        array $computation,
        string $ct600Hash,
        string $ct600Filename,
        string $ct600ValidationStatus
    ): array {
        $model = (array)($return['model'] ?? []);
        $period = (array)($model['period'] ?? []);
        $rim = (array)($return['rim'] ?? []);
        $profile = (array)($return['mapping_profile'] ?? []);
        $accountingPeriodId = (int)($return['accounting_period_id'] ?? 0);
        $ctPeriodId = (int)($return['ct_period_id'] ?? 0);
        $ct600Filename = $ct600Filename !== ''
            ? $ct600Filename
            : (string)($builder['filename'] ?? '');

        return [
            [
                'role' => 'ct600_return_xml',
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'period_start' => (string)($period['start_date'] ?? ''),
                'period_end' => (string)($period['end_date'] ?? ''),
                'filename' => $ct600Filename,
                'sha256' => $ct600Hash,
                'schema_version' => (string)($rim['form_version'] ?? '') . '/' . (string)($rim['artifact_version'] ?? ''),
                'mapping_profile_id' => (int)($profile['id'] ?? 0),
                'mapping_profile_name' => (string)($profile['profile_name'] ?? ''),
                'mapping_revision_no' => (int)($profile['revision_no'] ?? 0),
                'mapping_content_hash' => (string)($profile['content_hash'] ?? ''),
                'validation_status' => $ct600ValidationStatus,
                'supplementary_pages' => array_values((array)($model['attachments']['supplementary_pages'] ?? [])),
            ],
            [
                'role' => 'statutory_accounts_ixbrl',
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => null,
                'period_start' => '',
                'period_end' => '',
                'filename' => (string)($accounts['filename'] ?? ''),
                'sha256' => (string)($accounts['hash'] ?? ''),
                'schema_version' => (string)($accounts['taxonomy_profile'] ?? ''),
                'mapping_profile_id' => null,
                'mapping_profile_name' => '',
                'mapping_revision_no' => null,
                'mapping_content_hash' => '',
                'validation_status' => (string)($accounts['validation_status'] ?? 'passed'),
                'supplementary_pages' => [],
            ],
            [
                'role' => 'ct_computation_ixbrl',
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'period_start' => (string)($period['start_date'] ?? ''),
                'period_end' => (string)($period['end_date'] ?? ''),
                'filename' => (string)($computation['filename'] ?? ''),
                'sha256' => (string)($computation['hash'] ?? ''),
                'schema_version' => (string)($computation['taxonomy_profile'] ?? ''),
                'mapping_profile_id' => (int)($computation['mapping_profile_id'] ?? 0),
                'mapping_profile_name' => '',
                'mapping_revision_no' => null,
                'mapping_content_hash' => (string)($computation['mapping_hash'] ?? ''),
                'tagging_version' => (string)($computation['tagging_version'] ?? ''),
                'presentation_version' => (string)($computation['presentation_version'] ?? ''),
                'validation_status' => (string)($computation['validation_status'] ?? 'passed'),
                'supplementary_pages' => [],
            ],
        ];
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $child) { $item[$key] = $normalise($child); }
            return $item;
        };
        return \eel_accounts\Support\Utf8::json(
            $normalise($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function validHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }

    private function artifactFailure(string $state, string $message, array $errors = []): array
    {
        return [
            'ok' => false, 'state' => $state, 'run_id' => null, 'path' => null,
            'filename' => null, 'warnings' => [],
            'errors' => $errors !== [] ? array_values(array_map('strval', $errors)) : [$message],
            'hash' => null, 'basis_hash' => null,
        ];
    }

    private function failure(string $message, array $details = []): array
    {
        return [
            'ok' => false,
            'errors' => array_values(array_unique(array_filter(array_map(
                'strval', array_merge([$message], $details)
            ), static fn(string $item): bool => trim($item) !== ''))),
            'warnings' => [],
            'filing_body_xml' => null,
            'source_manifest' => [],
            'source_manifest_sha256' => '',
            'body_sha256' => '',
            'package_hash' => '',
        ];
    }
}
