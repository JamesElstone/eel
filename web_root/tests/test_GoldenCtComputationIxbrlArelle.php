<?php
/**
 * Real Arelle regression coverage for the synthetic Golden AP79-equivalent
 * period. This test is intentionally test-database and temporary-file only.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';
require_once PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ArelleIxbrlValidator.php';

GoldenAccountsFixture::build();

$harness = new GeneratedServiceClassTestHarness();
$harness->run('eel_accounts\\Service\\IxbrlTaxComputationService', static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check('GoldenCtComputationIxbrlArelle', 'keeps AP79 loss facts out of the UK-trade dimensional context', static function () use ($harness): void {
        goldenCtIxbrlEnsureTaxonomyPackage();
        $fixture = goldenCtIxbrlArelleFixture();
        $harness->assertSame(2, count((array)$fixture['periods']));
        $failures = [];
        foreach ((array)$fixture['periods'] as $periodFixture) {
            try {
                $rendered = goldenCtIxbrlRender($periodFixture, (array)$fixture['package']);
                goldenCtIxbrlAssertDocumentIntegrity($harness, $rendered, $periodFixture);
                goldenCtIxbrlAssertLossFacts($harness, $rendered, $periodFixture);
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(implode(PHP_EOL . PHP_EOL, $failures));
        }
    });

    $harness->check('GoldenCtComputationIxbrlArelle', 'Arelle accepts both AP79 CT computation exports without dimensional diagnostics', static function () use ($harness): void {
        goldenCtIxbrlEnsureTaxonomyPackage();
        $fixture = goldenCtIxbrlArelleFixture();
        $harness->assertSame(2, count((array)$fixture['periods']));
        $failures = [];
        foreach ((array)$fixture['periods'] as $periodFixture) {
            try {
                $rendered = goldenCtIxbrlRender($periodFixture, (array)$fixture['package']);
                $path = goldenCtIxbrlTemporaryArtifact((string)$rendered['xhtml'], (array)$periodFixture);
                $validation = goldenCtIxbrlValidate($path, (string)$rendered['package_archive']);
                goldenCtIxbrlAssertArelleSuccess($validation, $periodFixture, $path);
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(implode(PHP_EOL . PHP_EOL, $failures));
        }
    });
});

goldenCtIxbrlCleanupSharedLock();

function goldenCtIxbrlCleanupSharedLock(): void
{
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $accountingPeriodId = 9111;
    $lockService = new \eel_accounts\Service\YearEndLockService();
    if (!$lockService->isLocked($companyId, $accountingPeriodId)) {
        return;
    }
    $backupCreator = new class implements \eel_accounts\Contract\DatabaseBackupCreatorInterface {
        public function createBackup(int $companyId, string $trigger = 'Manual'): array
        {
            return [
                'filename' => 'golden-ct-ixbrl-cleanup.sql.zip',
                'size_bytes' => 1024,
                'table_count' => 1,
                'trigger' => $trigger,
            ];
        }
    };
    $cleanup = (new \eel_accounts\Service\YearEndChecklistService(backupCreator: $backupCreator))
        ->unlockPeriod(
            $companyId,
            $accountingPeriodId,
            'golden_ct_ixbrl_arelle_cleanup',
            'Restore the shared Golden accounting fixture after Arelle assertions.',
            null,
            true
        );
    if (empty($cleanup['success'])) {
        throw new RuntimeException(
            'Golden CT iXBRL cleanup could not unlock AP 9111: '
            . implode(' ', array_map('strval', (array)($cleanup['errors'] ?? [])))
        );
    }
    \eel_accounts\Support\RequestCache::clear();
}

/** @return array{package: array<string,mixed>, periods: list<array<string,mixed>>} */
function goldenCtIxbrlArelleFixture(): array
{
    static $fixture = null;
    if (is_array($fixture)) {
        return $fixture;
    }
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $accountingPeriodId = 9111;
    $package = goldenCtIxbrlEnsureTaxonomyPackage();
    $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
    $synced = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
    if (empty($synced['success'])) {
        throw new RuntimeException('Golden AP79 CT-period sync failed: ' . implode(' ', (array)($synced['errors'] ?? [])));
    }
    $ctPeriods = array_values(array_filter(
        $periodService->fetchForAccountingPeriod($companyId, $accountingPeriodId),
        static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
    ));
    if (count($ctPeriods) !== 2) {
        throw new RuntimeException('Golden AP79 must resolve exactly two CT periods; got ' . count($ctPeriods) . '.');
    }
    test_confirm_ct_period_facts($companyId, $accountingPeriodId);
    goldenCtIxbrlCompleteFilingInputs($companyId, $accountingPeriodId);
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('utr', '1234567890', 'char');
    $settings->flush();

    InterfaceDB::beginTransaction();
    try {
        $readiness = (new \eel_accounts\Service\YearEndTaxReadinessService())
            ->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
        $basis = (new \eel_accounts\Service\YearEndTaxFreezeService())->approvalBasis($readiness);
        if (!is_array($basis)) {
            throw new RuntimeException('The Golden AP79 tax approval basis is unavailable.');
        }
        $acknowledgement = (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
            $companyId, $accountingPeriodId, 'tax_readiness_acknowledgement', $basis,
            'golden_ct_ixbrl_arelle', '', true
        );
        if (empty($acknowledgement['success'])) {
            throw new RuntimeException('Golden AP79 tax acknowledgement failed: ' . implode(' ', (array)($acknowledgement['errors'] ?? [])));
        }
        $computations = new \eel_accounts\Service\CorporationTaxComputationService();
        $persisted = $computations->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
        if (empty($persisted['success'])) {
            throw new RuntimeException('Golden AP79 computation persistence failed: ' . implode(' ', (array)($persisted['errors'] ?? [])));
        }
        $terms = (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
        $companyId,
        GoldenAccountsFixture::GOLDEN_PARTY_ID,
        [
            'interest_rate_percent' => 0,
            'security_type' => 'unsecured',
            'repayable_on_demand' => 1,
            'repayment_timing' => 'within_12_months',
            'deferment_right_confirmed' => 0,
            'set_off_right_confirmed' => 0,
            'settlement_intention' => 'independently',
            'advance_interest_rate_percent' => 0,
            'advance_security_type' => 'unsecured',
            'advance_repayment_basis' => 'on_demand',
        ],
        'golden_ct_ixbrl_arelle'
        );
        if (empty($terms['success'])) {
            throw new RuntimeException('Golden AP79 Participator Loan terms failed: ' . implode(' ', (array)($terms['errors'] ?? [])));
        }
        $loanReview = (new \eel_accounts\Service\DirectorLoanReconciliationService())
            ->saveYearEndReview($companyId, $accountingPeriodId, true, 'golden_ct_ixbrl_arelle');
        if (empty($loanReview['success'])) {
            throw new RuntimeException('Golden AP79 Director Loan review failed: ' . implode(' ', (array)($loanReview['errors'] ?? [])));
        }
        $lock = (new \eel_accounts\Service\YearEndLockService())->lockPeriod($companyId, $accountingPeriodId, 'golden_ct_ixbrl_arelle');
        if (empty($lock['success'])) {
            throw new RuntimeException('Golden AP79 lock failed: ' . implode(' ', (array)($lock['errors'] ?? [])));
        }
        $sealed = $computations->sealSummariesForYearEndLock($companyId, $accountingPeriodId);
        if (empty($sealed['success'])) {
            throw new RuntimeException('Golden AP79 computation seal failed: ' . implode(' ', (array)($sealed['errors'] ?? [])));
        }
        InterfaceDB::commit();
    } catch (Throwable $exception) {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        throw $exception;
    }
    ixbrl_test_complete_disclosures($companyId, $accountingPeriodId, 'golden_ct_ixbrl_arelle');
    $filingApproval = (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService())
        ->approveAndBuildFacts($companyId, $accountingPeriodId, 'golden_ct_ixbrl_arelle', 'Golden CT iXBRL Arelle regression fixture.');
    if ((int)($filingApproval['approval_id'] ?? 0) <= 0) {
        throw new RuntimeException('Golden AP79 filing approval could not be created: ' . implode(' ', (array)($filingApproval['errors'] ?? [])));
    }

    $filingModels = [];
    $filingService = new \eel_accounts\Service\CtPeriodFilingModelService();
    foreach ($ctPeriods as $ctPeriod) {
        $model = $filingService->build($companyId, $accountingPeriodId, (int)$ctPeriod['id']);
        if (empty($model['available'])) {
            throw new RuntimeException('Golden CT-period filing model is unavailable: ' . implode(' ', (array)($model['errors'] ?? [])));
        }
        $filingModels[] = ['ct_period' => $ctPeriod, 'model' => $model];
    }

    return $fixture = ['package' => $package, 'periods' => $filingModels];
}

/** @return array<string,mixed> */
function goldenCtIxbrlEnsureTaxonomyPackage(): array
{
    static $package = null;
    if (is_array($package)) {
        return $package;
    }
    goldenCtIxbrlEnsureTaxonomySchema();
    $directory = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR
        . 'ct-computation' . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0';
    if (!is_dir($directory) || !is_file(dirname($directory) . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0.zip')) {
        throw new RuntimeException('The real CT2024 taxonomy package is required for this integration test.');
    }
    $catalogue = new \eel_accounts\Service\HmrcCtComputationCatalogueService();
    $packageId = goldenCtIxbrlSeedTaxonomyCatalogue($catalogue, $directory);
    $package = $catalogue->resolveForPeriod('2022-09-05', '2023-09-30');
    if (!is_array($package)) {
        throw new RuntimeException('The test CT2024 taxonomy package was not resolved.');
    }
    return $package;
}

function goldenCtIxbrlCompleteFilingInputs(int $companyId, int $accountingPeriodId): void
{
    $scope = new \eel_accounts\Service\CorporationTaxFilingScopeService();
    foreach (array_keys($scope->definitions()) as $field) {
        $saved = $scope->saveAnswer($companyId, $accountingPeriodId, $field, 'no', 'golden_ct_ixbrl_arelle');
        if (empty($saved['success'])) {
            throw new RuntimeException('Golden CT filing scope failed: ' . implode(' ', (array)($saved['errors'] ?? [])));
        }
    }
    $ct600a = new \eel_accounts\Service\Ct600aService();
    $review = $ct600a->saveReview(
        $companyId,
        $accountingPeriodId,
        array_fill_keys(array_keys($ct600a->reviewQuestions()), 'no'),
        'director',
        'Golden Fixture Director',
        'No section 464A arrangements in the synthetic Golden AP79 fixture.'
    );
    if (empty($review['success'])) {
        throw new RuntimeException('Golden CT600A review failed: ' . implode(' ', (array)($review['errors'] ?? [])));
    }
}

/** @return array{xhtml:string,report:array<string,mixed>,package_archive:string,schema_ref:string} */
function goldenCtIxbrlRender(array $periodFixture, array $package): array
{
    $filing = (array)$periodFixture['model'];
    $mappingModel = $filing;
    $ct600aTax = round((float)($filing['model']['ct600a']['tax_payable'] ?? 0), 2);
    $ordinaryTax = round((float)($filing['model']['computation']['summary']['ordinary_corporation_tax'] ?? 0), 2);
    $mappingModel['facts']['return_position.ct600a_a80'] = $ct600aTax;
    $mappingModel['facts']['return_position.tax_payable'] = round($ordinaryTax + $ct600aTax, 2);
    $mappingModel['facts']['computation.summary.s455_tax'] = $ct600aTax;
    $mappingModel['facts']['computation.summary.estimated_corporation_tax'] = round($ordinaryTax + $ct600aTax, 2);
    $mapped = goldenCtIxbrlMapFrozenFacts($mappingModel, $package);
    $catalogue = new \eel_accounts\Service\HmrcCtComputationCatalogueService();
    $resources = $catalogue->validationResources($package);
    $service = new \eel_accounts\Service\IxbrlTaxComputationService();
    $report = $service->buildReportModel($filing, (array)$mapped['mappings']);
    $method = new ReflectionMethod($service, 'renderMappedDocument');
    $method->setAccessible(true);
    $rendered = (array)$method->invoke(
        $service,
        new \eel_accounts\Service\IxbrlGeneratorService(),
        $filing,
        (array)$mapped['mappings'],
        (string)$resources['schema_ref']
    );
    return [
        'xhtml' => (string)$rendered['xhtml'],
        'report' => $report,
        'package_archive' => (string)$resources['package_archive'],
        'schema_ref' => (string)$resources['schema_ref'],
    ];
}

/** @return array{success:bool,mappings:list<array<string,mixed>>,errors:list<string>} */
function goldenCtIxbrlMapFrozenFacts(array $filing, array $package): array
{
    $service = new \eel_accounts\Service\CtFilingMappingService();
    $template = $service->reviewedTemplate(
        \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION,
        (string)$package['taxonomy_version'],
        (string)$package['artifact_version']
    );
    if (!is_array($template)) {
        throw new RuntimeException('The production reviewed CT computation mapping template is unavailable.');
    }
    $knownMappings = [];
    foreach ((array)$template['mappings'] as $index => $templateMapping) {
        $localName = (string)$templateMapping['local_name'];
        $concept = InterfaceDB::fetchOne(
            'SELECT qname, namespace_uri, period_type FROM hmrc_ct_computation_concepts
             WHERE package_id = :package_id AND local_name = :local_name LIMIT 1',
            ['package_id' => (int)$package['id'], 'local_name' => $localName]
        );
        if (!is_array($concept)) {
            throw new RuntimeException('The real CT2024 package has no mapped concept ' . $localName . '.');
        }
        $canonicalKey = (string)$templateMapping['canonical_key'];
        $numeric = !in_array($canonicalKey, ['identity.company_name', 'filing_identity.utr', 'ct_period.start_date', 'ct_period.end_date'], true);
        $knownMappings[] = [
            'id' => $index + 1,
            'profile_id' => 1,
            'canonical_key' => $canonicalKey,
            'taxonomy_concept' => (string)$concept['qname'],
            'namespace_uri' => (string)$concept['namespace_uri'],
            'local_name' => $localName,
            'value_type' => $numeric ? 'numeric' : (str_starts_with($canonicalKey, 'ct_period.') ? 'date' : 'text'),
            'period_type' => (string)($templateMapping['period_type'] ?? $concept['period_type'] ?? 'duration'),
            'context_profile' => (string)$templateMapping['context_profile'],
            'unit_ref' => $numeric ? 'GBP' : null,
            'decimals_value' => $numeric ? '2' : null,
            'dimensions_json' => null,
            'sign_multiplier' => 1,
            'presentation_section' => goldenCtIxbrlSection($canonicalKey),
            'presentation_label' => $canonicalKey,
            'null_policy' => 'error',
            'is_required' => 1,
            'sort_order' => ($index + 1) * 10,
        ];
    }
    return $service->mapFrozenFacts(
        \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION,
        $filing,
        [
            'id' => 1,
            'target_type' => \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION,
            'status' => 'active',
            'compatibility_status' => 'compatible',
        ],
        $knownMappings
    );
}

function goldenCtIxbrlSection(string $canonicalKey): string
{
    return match (true) {
        str_starts_with($canonicalKey, 'identity.') || str_starts_with($canonicalKey, 'filing_identity.') || str_starts_with($canonicalKey, 'ct_period.') => 'identity',
        str_contains($canonicalKey, 'accounting_profit') => 'detailed_profit_and_loss',
        str_contains($canonicalKey, 'allowances') => 'capital_allowances',
        str_contains($canonicalKey, 'loss') || str_contains($canonicalKey, 'taxable_before') => 'losses',
        default => 'tax_liability',
    };
}

function goldenCtIxbrlEnsureTaxonomySchema(): void
{
    if (InterfaceDB::tableExists('hmrc_ct_computation_packages')) {
        return;
    }
    if (strtolower((string)InterfaceDB::driverName()) !== 'sqlite') {
        throw new RuntimeException('The real Arelle regression fixture may only create its taxonomy catalogue in SQLite.');
    }
    InterfaceDB::execute('CREATE TABLE hmrc_ct_computation_packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy_version TEXT NOT NULL, artifact_version TEXT NOT NULL,
        applicable_from TEXT NOT NULL, applicable_to TEXT NULL, source_url TEXT NOT NULL, download_url TEXT NULL,
        local_path TEXT NULL, entry_point_path TEXT NULL, combined_dpl_entry_point_path TEXT NULL, sha256 TEXT NULL,
        package_state TEXT NOT NULL, verification_error TEXT NULL, checked_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL
    )');
    InterfaceDB::execute('CREATE TABLE hmrc_ct_computation_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT, package_id INTEGER NOT NULL, archive_path TEXT NOT NULL,
        extracted_path TEXT NOT NULL, file_type TEXT NOT NULL, file_role TEXT NULL, file_size INTEGER NOT NULL,
        sha256 TEXT NOT NULL
    )');
    InterfaceDB::execute('CREATE TABLE hmrc_ct_computation_concepts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, package_id INTEGER NOT NULL, qname TEXT NOT NULL,
        namespace_uri TEXT NOT NULL, local_name TEXT NOT NULL, data_type TEXT NULL, period_type TEXT NULL,
        substitution_group TEXT NULL, is_abstract INTEGER NOT NULL DEFAULT 0, is_dimension INTEGER NOT NULL DEFAULT 0,
        is_required INTEGER NOT NULL DEFAULT 0
    )');
    if (!InterfaceDB::tableExists('ct_filing_mapping_profiles')) {
        InterfaceDB::execute('CREATE TABLE ct_filing_mapping_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, rim_package_id INTEGER NULL,
            computation_package_id INTEGER NULL, profile_name TEXT NOT NULL, revision_no INTEGER NOT NULL,
            status TEXT NOT NULL, content_hash TEXT NOT NULL, compatibility_status TEXT NOT NULL,
            compatibility_report_json TEXT NULL, supersedes_profile_id INTEGER NULL, approved_by TEXT NULL,
            approved_at TEXT NULL, created_by TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL
        )');
    }
}

function goldenCtIxbrlSeedTaxonomyCatalogue(\eel_accounts\Service\HmrcCtComputationCatalogueService $catalogue, string $directory): int
{
    $inspect = new ReflectionMethod($catalogue, 'inspectDirectory');
    $inspect->setAccessible(true);
    $manifest = (array)$inspect->invoke($catalogue, $directory);
    $readConcepts = new ReflectionMethod($catalogue, 'readConcepts');
    $readConcepts->setAccessible(true);
    $files = [];
    $concepts = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $archivePath = str_replace('\\', '/', substr($path, strlen($directory) + 1));
        $extension = strtolower($file->getExtension());
        $files[] = [
            'archive_path' => $archivePath,
            'extracted_path' => $path,
            'file_type' => in_array($extension, ['xsd', 'xml', 'json'], true) ? $extension : (in_array($extension, ['xbrl', 'linkbase'], true) ? 'linkbase' : 'other'),
            'file_role' => realpath($path) === realpath((string)($manifest['entry_point_path'] ?? '')) ? 'entry_point' : null,
            'file_size' => $file->getSize(),
            'sha256' => hash_file('sha256', $path),
        ];
        if ($extension === 'xsd') {
            foreach ((array)$readConcepts->invoke($catalogue, $path) as $concept) {
                $concepts[(string)$concept['namespace_uri'] . '|' . (string)$concept['local_name']] = $concept;
            }
        }
    }
    usort($files, static fn(array $left, array $right): int => $left['archive_path'] <=> $right['archive_path']);
    $inventory = new ReflectionMethod($catalogue, 'inventoryHash');
    $inventory->setAccessible(true);
    $hash = (string)$inventory->invoke($catalogue, $files);
    InterfaceDB::prepareExecute(
        'INSERT INTO hmrc_ct_computation_packages
         (taxonomy_version, artifact_version, applicable_from, applicable_to, source_url, download_url,
          local_path, entry_point_path, combined_dpl_entry_point_path, sha256, package_state, checked_at)
         VALUES (:taxonomy, :artifact, :from_date, NULL, :source, :download, :path, :entry, :combined, :sha, :state, CURRENT_TIMESTAMP)',
        [
            'taxonomy' => (string)$manifest['taxonomy_version'], 'artifact' => (string)$manifest['artifact_version'],
            'from_date' => '1900-01-01', 'source' => \eel_accounts\Service\HmrcCtComputationCatalogueService::SOURCE_URL,
            'download' => \eel_accounts\Service\HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL,
            'path' => $directory, 'entry' => (string)$manifest['entry_point_path'],
            'combined' => (string)$manifest['entry_point_path'], 'sha' => $hash, 'state' => 'verified',
        ]
    );
    $packageId = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
    foreach ($files as $file) {
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct_computation_files (package_id, archive_path, extracted_path, file_type, file_role, file_size, sha256)
             VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_role, :file_size, :sha256)',
            ['package_id' => $packageId] + $file
        );
    }
    foreach ($concepts as $concept) {
        $concept = array_replace([
            'qname' => '', 'namespace_uri' => '', 'local_name' => '', 'data_type' => null,
            'period_type' => null, 'substitution_group' => null, 'is_abstract' => 0,
            'is_dimension' => 0, 'is_required' => 0,
        ], $concept);
        $concept['is_abstract'] = (int)($concept['is_abstract'] ?? 0);
        $concept['is_dimension'] = (int)($concept['is_dimension'] ?? 0);
        $concept['is_required'] = (int)($concept['is_required'] ?? 0);
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct_computation_concepts
             (package_id, qname, namespace_uri, local_name, data_type, period_type, substitution_group, is_abstract, is_dimension, is_required)
             VALUES (:package_id, :qname, :namespace_uri, :local_name, :data_type, :period_type, :substitution_group, :is_abstract, :is_dimension, :is_required)',
            ['package_id' => $packageId] + $concept
        );
    }
    return $packageId;
}

function goldenCtIxbrlAssertDocumentIntegrity(GeneratedServiceClassTestHarness $harness, array $rendered, array $periodFixture): void
{
    $document = new DOMDocument();
    $harness->assertTrue($document->loadXML((string)$rendered['xhtml'], LIBXML_NONET | LIBXML_NOBLANKS));
    $xpath = goldenCtIxbrlXpath($document);
    $contexts = [];
    foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
        $contexts[(string)$context->attributes?->getNamedItem('id')?->nodeValue] = true;
    }
    $duplicates = [];
    foreach ($xpath->query('//ix:nonFraction|//ix:nonNumeric') ?: [] as $fact) {
        $contextId = (string)$fact->attributes?->getNamedItem('contextRef')?->nodeValue;
        if ($contextId === '' || !isset($contexts[$contextId])) {
            throw new RuntimeException('Golden CT period ' . goldenCtIxbrlPeriodLabel($periodFixture) . ' has a fact with missing context ' . $contextId . '.');
        }
        if ($fact->localName !== 'nonFraction') {
            continue;
        }
        $unit = (string)$fact->attributes?->getNamedItem('unitRef')?->nodeValue;
        $decimals = (string)$fact->attributes?->getNamedItem('decimals')?->nodeValue;
        $harness->assertSame('GBP', $unit);
        $harness->assertSame('2', $decimals);
        $key = implode('|', [(string)$fact->attributes?->getNamedItem('name')?->nodeValue, $contextId, $unit, $decimals]);
        $value = trim((string)$fact->textContent) . '|' . (string)$fact->attributes?->getNamedItem('sign')?->nodeValue;
        if (isset($duplicates[$key]) && $duplicates[$key] !== $value) {
            throw new RuntimeException('Golden CT period ' . goldenCtIxbrlPeriodLabel($periodFixture) . ' has inconsistent duplicate fact ' . $key . '.');
        }
        $duplicates[$key] = $value;
    }
}

function goldenCtIxbrlAssertLossFacts(GeneratedServiceClassTestHarness $harness, array $rendered, array $periodFixture): void
{
    $xpath = goldenCtIxbrlXpath((function () use ($rendered): DOMDocument {
        $document = new DOMDocument();
        $document->loadXML((string)$rendered['xhtml'], LIBXML_NONET | LIBXML_NOBLANKS);
        return $document;
    })());
    $expected = [];
    foreach ((array)$rendered['report']['mappings'] as $mapping) {
        if (in_array((string)($mapping['canonical_key'] ?? ''), [
            'report.loss.post_2017_trading_loss_arising',
            'report.loss.carried_forward_relief_claimed',
        ], true)) {
            $expected[(string)$mapping['taxonomy_concept']] = $mapping;
        }
    }
    foreach ($expected as $qname => $mapping) {
        $facts = $xpath->query('//ix:nonFraction[@name="' . $qname . '"]');
        $factCount = $facts?->length ?? 0;
        if ($factCount === 0
            && (string)($mapping['null_policy'] ?? '') === 'omit'
            && abs((float)($mapping['source_value'] ?? 0)) < 0.005) {
            continue;
        }
        $harness->assertSame(1, $factCount);
        $fact = $facts?->item(0);
        if (!$fact instanceof DOMElement) {
            continue;
        }
        $contextId = $fact->getAttribute('contextRef');
        $context = $xpath->query('//xbrli:context[@id="' . $contextId . '"]')?->item(0);
        $dimensions = goldenCtIxbrlContextDimensions($context);
        $period = (array)$periodFixture['ct_period'];
        $expectedValue = round((float)($mapping['source_value'] ?? 0) * (float)($mapping['sign_multiplier'] ?? 1), 2);
        $actualValue = round((float)str_replace(',', '', trim((string)$fact->textContent)) * ($fact->getAttribute('sign') === '-' ? -1 : 1), 2);
        $harness->assertSame(number_format($expectedValue, 2, '.', ''), number_format($actualValue, 2, '.', ''));
        $harness->assertSame('GBP', $fact->getAttribute('unitRef'));
        $harness->assertSame('2', $fact->getAttribute('decimals'));
        $harness->assertSame('ct_period', (string)($mapping['context_role'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY, (string)($mapping['context_profile'] ?? ''));
        $harness->assertSame((string)$period['period_start'], $xpath->evaluate('string(//xbrli:context[@id="' . $contextId . '"]/xbrli:period/xbrli:startDate)'));
        $harness->assertSame((string)$period['period_end'], $xpath->evaluate('string(//xbrli:context[@id="' . $contextId . '"]/xbrli:period/xbrli:endDate)'));
        $expectedExplicit = [
            'tax:BusinessTypeDimension=tax:Company',
            'tax:DetailedAnalysisDimension=tax:Item1',
        ];
        if ($dimensions['explicit'] !== $expectedExplicit || $dimensions['typed'] !== []) {
            throw new RuntimeException(goldenCtIxbrlLossContextFailure(
                $periodFixture,
                $qname,
                number_format($actualValue, 2, '.', ''),
                $contextId,
                $dimensions,
                \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY
            ));
        }
    }
}

function goldenCtIxbrlTemporaryArtifact(string $xhtml, array $periodFixture): string
{
    $root = test_register_cleanup_path(test_tmp_directory() . DIRECTORY_SEPARATOR . 'golden_ct_ixbrl_arelle_' . bin2hex(random_bytes(4)));
    mkdir($root, 0775, true);
    $period = (array)$periodFixture['ct_period'];
    $path = $root . DIRECTORY_SEPARATOR . 'golden_ap79_ct_' . str_replace('-', '', (string)$period['period_start']) . '.xhtml';
    file_put_contents($path, $xhtml);
    return $path;
}

/** @return array<string,mixed> */
function goldenCtIxbrlValidate(string $path, string $packageArchive): array
{
    $root = dirname($path);
    $command = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR . 'runtime'
        . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'arelleCmdLine.exe';
    if (!is_file($command)) {
        throw new RuntimeException('The real bundled Arelle command is not installed: ' . $command);
    }
    $validator = new ArelleIxbrlValidator([
        'enabled' => true,
        'arelle_cmd' => $command,
        'timeout_seconds' => 180,
        'logs_path' => $root . DIRECTORY_SEPARATOR . 'logs',
        'cache_path' => $root . DIRECTORY_SEPARATOR . 'cache',
        'packages' => [],
        'offline' => true,
        'flags' => ['--validate'],
    ], $root);
    return $validator->validate($path, [$packageArchive]);
}

function goldenCtIxbrlAssertArelleSuccess(array $validation, array $periodFixture, string $path): void
{
    $raw = (string)($validation['raw_stdout'] ?? '') . "\n" . (string)($validation['raw_stderr'] ?? '');
    $errors = array_values(array_filter((array)($validation['diagnostics'] ?? []), static fn(array $diagnostic): bool => in_array((string)($diagnostic['severity'] ?? ''), ['error', 'fatal'], true)));
    $hasUnparsedBracketedError = preg_match('/\[[^\]]*(?:error|fatal)[^\]]*\]/i', $raw) === 1;
    if ((int)($validation['exit_code'] ?? 1) !== 0 || $errors !== [] || $hasUnparsedBracketedError || empty($validation['ok'])) {
        throw new RuntimeException(
            'Arelle rejected Golden CT period ' . goldenCtIxbrlPeriodLabel($periodFixture) . "\n"
            . 'Artifact: ' . $path . "\n"
            . 'Exit code: ' . (string)($validation['exit_code'] ?? '') . "\n"
            . 'Diagnostics: ' . json_encode($validation['diagnostics'] ?? [], JSON_UNESCAPED_SLASHES) . "\n"
            . 'Log: ' . (string)($validation['log_path'] ?? '') . "\n"
            . "STDOUT:\n" . (string)($validation['raw_stdout'] ?? '') . "\n"
            . "STDERR:\n" . (string)($validation['raw_stderr'] ?? '')
        );
    }
}

function goldenCtIxbrlXpath(DOMDocument $document): DOMXPath
{
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ix', \eel_accounts\Service\IxbrlGeneratorService::IX_NAMESPACE);
    $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
    $xpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');
    return $xpath;
}

/** @return array{explicit:list<string>,typed:list<string>} */
function goldenCtIxbrlContextDimensions(?DOMNode $context): array
{
    if (!$context instanceof DOMElement) {
        return ['explicit' => ['missing context'], 'typed' => []];
    }
    $explicit = [];
    foreach ($context->getElementsByTagNameNS('http://xbrl.org/2006/xbrldi', 'explicitMember') as $member) {
        $explicit[] = (string)$member->getAttribute('dimension') . '=' . trim((string)$member->textContent);
    }
    $typed = [];
    foreach ($context->getElementsByTagNameNS('http://xbrl.org/2006/xbrldi', 'typedMember') as $member) {
        $typed[] = (string)$member->getAttribute('dimension') . '=typed:' . trim((string)$member->textContent);
    }
    sort($explicit);
    sort($typed);
    return ['explicit' => $explicit, 'typed' => $typed];
}

function goldenCtIxbrlLossContextFailure(array $periodFixture, string $qname, string $value, string $contextId, array $dimensions, string $expectedRole): string
{
    $period = (array)$periodFixture['ct_period'];
    return 'Golden CT loss context regression: CT period ' . goldenCtIxbrlPeriodLabel($periodFixture)
        . ', concept ' . $qname . ', value ' . $value . ', context ' . $contextId
        . ', dates ' . (string)$period['period_start'] . ' to ' . (string)$period['period_end']
        . ', explicit dimensions [' . implode(', ', (array)($dimensions['explicit'] ?? [])) . ']'
        . ', typed dimensions [' . implode(', ', (array)($dimensions['typed'] ?? [])) . ']'
        . ', expected context role ' . $expectedRole . '.';
}

function goldenCtIxbrlPeriodLabel(array $periodFixture): string
{
    $period = (array)$periodFixture['ct_period'];
    return '#' . (string)($period['sequence_no'] ?? $period['id'] ?? '?')
        . ' (' . (string)($period['period_start'] ?? '?') . ' to ' . (string)($period['period_end'] ?? '?') . ')';
}
