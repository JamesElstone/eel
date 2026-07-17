<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Converts one current filing-readiness snapshot into immutable CT600 inputs.
 *
 * The readiness array is a gate, not a source of truth. Identity, periods,
 * computation figures and artifact fingerprints are re-read from their
 * persisted rows so that a stale browser request cannot freeze a different
 * return from the one which passed the readiness checks.
 */
final class Ct600ReturnDataFactory
{
    public const ORDINARY_UK_TRADING_COMPANY = 0;

    /**
     * @param array<string, mixed> $readiness
     * @param array{name?: mixed, status?: mixed, confirmed?: mixed} $declaration
     * @return array{
     *     return: Ct600ReturnData,
     *     accounts: Ct600IxbrlArtifact,
     *     computation: Ct600IxbrlArtifact,
     *     mapping: array<string, mixed>,
     *     validation: array<string, mixed>
     * }
     */
    public function build(array $readiness, array $declaration): array
    {
        $this->assertReadyForPreparation($readiness);

        $readinessCompany = $this->requiredArray($readiness, 'company');
        $readinessAccountingPeriod = $this->requiredArray($readiness, 'accounting_period');
        $readinessCtPeriod = $this->requiredArray($readiness, 'ct_period');
        $readinessAccounts = $this->requiredArray($readiness, 'accounts');
        $readinessComputationArtifact = $this->requiredArray($readiness, 'computations');

        $companyId = $this->positiveId($readinessCompany['id'] ?? null, 'company');
        $accountingPeriodId = $this->positiveId(
            $readinessAccountingPeriod['id'] ?? null,
            'accounting period'
        );
        $ctPeriodId = $this->positiveId($readinessCtPeriod['id'] ?? null, 'Corporation Tax period');

        $company = $this->company($companyId);
        $accountingPeriod = $this->accountingPeriod($companyId, $accountingPeriodId);
        $ctPeriods = $this->ctPeriods($companyId, $accountingPeriodId);
        $ctPeriod = $this->selectedCtPeriod($ctPeriods, $ctPeriodId);
        $this->assertLocked($companyId, $accountingPeriodId);

        $registrationNumber = strtoupper(trim((string)($company['company_number'] ?? '')));
        $utr = preg_replace('/\s+/', '', trim((string)($readiness['utr'] ?? ''))) ?? '';
        if (!preg_match('/^[0-9]{10}$/D', $utr)) {
            throw new \RuntimeException(
                'The filing-readiness snapshot does not contain a validated 10-digit Corporation Tax UTR.'
            );
        }

        $accountsRunId = $this->positiveId($readinessAccounts['run_id'] ?? null, 'accounts iXBRL run');
        $computationRunId = $this->positiveId(
            $readinessComputationArtifact['run_id'] ?? null,
            'computations iXBRL run'
        );
        $accountsRun = $this->accountsRun($companyId, $accountingPeriodId, $accountsRunId);
        $computationRun = $this->computationRun(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $computationRunId,
            (int)($ctPeriod['latest_computation_run_id'] ?? 0)
        );

        $accountsDocument = $this->inspectIxbrl(
            $accountsRun,
            $readinessAccounts,
            'accounts',
            $registrationNumber,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end'],
            null
        );
        $computationDocument = $this->inspectIxbrl(
            $computationRun,
            $readinessComputationArtifact,
            'computation',
            $registrationNumber,
            (string)$ctPeriod['period_start'],
            (string)$ctPeriod['period_end'],
            $utr
        );

        $accounts = $this->artifactFromInspection(
            Ct600IxbrlArtifact::ACCOUNTS,
            $accountsRun,
            $accountsDocument
        );
        $computation = $this->artifactFromInspection(
            Ct600IxbrlArtifact::COMPUTATION,
            $computationRun,
            $computationDocument
        );

        $summary = $this->computationSummary($computationRun, $readiness, $computationRunId);
        $mapping = $this->mapCalculation(
            $summary,
            $accountsDocument,
            $accountingPeriod,
            $ctPeriod,
            $ctPeriods
        );
        [$declarationName, $declarationStatus] = $this->declaration($declaration);

        $supplementary = (array)($readiness['supplementary'] ?? []);
        $requiredPages = array_values(array_unique(array_map(
            'strval',
            (array)($supplementary['required_pages'] ?? [])
        )));
        $additionalAttachments = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($supplementary['required_additional_attachments'] ?? [])
        ))));
        $submissionType = strtolower(trim((string)($readiness['submission_type'] ?? 'original')));

        $return = new Ct600ReturnData(
            companyId: $companyId,
            accountingPeriodId: $accountingPeriodId,
            ctPeriodId: $ctPeriodId,
            ctPeriodSequence: (int)$ctPeriod['sequence_no'],
            accountsRunId: $accountsRunId,
            computationRunId: $computationRunId,
            companyName: trim((string)($company['company_name'] ?? '')),
            registrationNumber: $registrationNumber,
            utr: $utr,
            companyType: self::ORDINARY_UK_TRADING_COMPANY,
            accountingPeriodStart: (string)$accountingPeriod['period_start'],
            accountingPeriodEnd: (string)$accountingPeriod['period_end'],
            periodStart: (string)$ctPeriod['period_start'],
            periodEnd: (string)$ctPeriod['period_end'],
            declarationName: $declarationName,
            declarationStatus: $declarationStatus,
            declarationConfirmed: true,
            calculation: $mapping['calculation'],
            multipleReturns: count($ctPeriods) > 1,
            requiredSupplementaryPages: $requiredPages,
            requiredAdditionalAttachments: $additionalAttachments,
            isAmendment: $submissionType !== 'original'
        );

        $scopeBlockers = $return->scopeBlockers();
        if ($scopeBlockers !== []) {
            throw new \RuntimeException(implode(' ', $scopeBlockers));
        }

        return [
            'return' => $return,
            'accounts' => $accounts,
            'computation' => $computation,
            'mapping' => $mapping,
            'validation' => [
                'ok' => true,
                'errors' => [],
                'scope_blockers' => [],
                'readiness_environment' => (string)($readiness['environment'] ?? ''),
                'accounts_identity' => $accountsDocument['identity'],
                'computation_identity' => $computationDocument['identity'],
                'accounts_schema_ref' => $accountsDocument['schema_ref'],
                'computation_schema_ref' => $computationDocument['schema_ref'],
                'hashes_reverified' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $readiness */
    private function assertReadyForPreparation(array $readiness): void
    {
        if (!empty($readiness['can_prepare'])) {
            return;
        }

        $blockers = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($readiness['blockers'] ?? [])
        )));
        throw new \RuntimeException(
            $blockers !== []
                ? 'The return is not ready to prepare: ' . implode(' ', $blockers)
                : 'The return is not ready to prepare.'
        );
    }

    /** @return array<string, mixed> */
    private function company(int $companyId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_name, company_number, company_status, companies_house_type,
                    has_insolvency_history, has_been_liquidated
             FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The company no longer exists.');
        }
        if ((string)($row['company_status'] ?? '') !== 'active'
            || (string)($row['companies_house_type'] ?? '') !== 'ltd') {
            throw new \RuntimeException('Phase one requires an active ordinary UK private limited company.');
        }
        if (!empty($row['has_insolvency_history']) || !empty($row['has_been_liquidated'])) {
            throw new \RuntimeException('An insolvency or liquidation case is outside phase-one CT600 scope.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function accountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The accounting period no longer belongs to the company.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function ctPeriods(int $companyId, int $accountingPeriodId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT id, company_id, accounting_period_id, sequence_no, period_start, period_end,
                    latest_computation_run_id
             FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
             ORDER BY sequence_no ASC, id ASC',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if ($rows === []) {
            throw new \RuntimeException('No Corporation Tax periods exist for the locked accounting period.');
        }

        return array_values($rows);
    }

    /** @param list<array<string, mixed>> $ctPeriods */
    /** @return array<string, mixed> */
    private function selectedCtPeriod(array $ctPeriods, int $ctPeriodId): array
    {
        foreach ($ctPeriods as $period) {
            if ((int)($period['id'] ?? 0) === $ctPeriodId) {
                return $period;
            }
        }

        throw new \RuntimeException('The Corporation Tax period no longer belongs to the accounting period.');
    }

    private function assertLocked(int $companyId, int $accountingPeriodId): void
    {
        $locked = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM year_end_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_locked = 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if ($locked !== 1) {
            throw new \RuntimeException('Year End is no longer locked; prepare a new filing package after relocking.');
        }
    }

    /** @return array<string, mixed> */
    private function accountsRun(int $companyId, int $accountingPeriodId, int $runId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_generation_runs
             WHERE id = :id AND company_id = :company_id AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['id' => $runId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The selected accounts iXBRL run no longer exists.');
        }
        $latest = (int)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(MAX(id), 0) FROM ixbrl_generation_runs
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if ($latest !== $runId) {
            throw new \RuntimeException('The accounts iXBRL readiness snapshot is stale because a newer run exists.');
        }
        if ((string)($row['export_type'] ?? '') !== 'filing_export') {
            throw new \RuntimeException('The accounts iXBRL run is not a filing export.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function computationRun(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $runId,
        int $latestRunId
    ): array {
        if ($runId !== $latestRunId) {
            throw new \RuntimeException('The computations iXBRL does not belong to the current lock-time computation run.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_computation_runs
             WHERE id = :id
               AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND ct_period_id = :ct_period_id
             LIMIT 1',
            [
                'id' => $runId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
            ]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The selected computations iXBRL run no longer exists.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $readinessArtifact
     * @return array<string, mixed>
     */
    private function inspectIxbrl(
        array $run,
        array $readinessArtifact,
        string $type,
        string $registrationNumber,
        string $expectedStart,
        string $expectedEnd,
        ?string $expectedUtr
    ): array {
        $label = $type === 'accounts' ? 'Accounts' : 'Computations';
        if ((string)($run['status'] ?? '') !== 'generated'
            || (string)($run['validation_status'] ?? '') !== 'passed'
            || (string)($run['external_validation_status'] ?? '') !== 'passed') {
            throw new \RuntimeException($label . ' iXBRL no longer has passed internal and external validation.');
        }

        $path = trim((string)($run['generated_path'] ?? ''));
        $filename = trim((string)($run['generated_filename'] ?? ''));
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException($label . ' iXBRL file was not found.');
        }
        if ($filename !== '' && $filename !== basename($path)) {
            throw new \RuntimeException($label . ' iXBRL filename metadata does not match its protected path.');
        }
        if (isset($readinessArtifact['path'])
            && trim((string)$readinessArtifact['path']) !== $path) {
            throw new \RuntimeException($label . ' iXBRL path changed after the readiness assessment.');
        }

        $outputHash = strtolower(trim((string)($run['output_sha256'] ?? '')));
        $validatedHash = strtolower(trim((string)($run['external_validated_sha256'] ?? '')));
        $readinessHash = strtolower(trim((string)($readinessArtifact['hash'] ?? '')));
        $actualHash = strtolower((string)(hash_file('sha256', $path) ?: ''));
        foreach ([$outputHash, $validatedHash, $readinessHash, $actualHash] as $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \RuntimeException($label . ' iXBRL does not have complete SHA-256 provenance.');
            }
        }
        if (!hash_equals($outputHash, $validatedHash)
            || !hash_equals($outputHash, $readinessHash)
            || !hash_equals($outputHash, $actualHash)) {
            throw new \RuntimeException($label . ' iXBRL changed after generation or external validation.');
        }

        [$document, $xpath] = $this->loadIxbrl($path, $label);
        [$schemaRef, $baseTaxonomyDate] = $this->taxonomy($xpath, $run, $type);
        $identity = $type === 'accounts'
            ? $this->accountsIdentity($xpath, $registrationNumber, $expectedStart, $expectedEnd)
            : $this->computationIdentity(
                $xpath,
                $registrationNumber,
                (string)$expectedUtr,
                $expectedStart,
                $expectedEnd
            );

        return [
            'document' => $document,
            'xpath' => $xpath,
            'path' => $path,
            'filename' => basename($path),
            'output_sha256' => $outputHash,
            'validated_sha256' => $validatedHash,
            'taxonomy_profile' => trim((string)($run['taxonomy_profile'] ?? '')),
            'base_taxonomy_version_date' => $baseTaxonomyDate,
            'schema_ref' => $schemaRef,
            'identity' => $identity,
        ];
    }

    /** @return array{0: \DOMDocument, 1: \DOMXPath} */
    private function loadIxbrl(string $path, string $label): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->load($path, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->documentElement === null) {
            $detail = $errors !== [] ? trim((string)$errors[0]->message) : 'not well-formed XML';
            throw new \RuntimeException($label . ' iXBRL could not be parsed safely: ' . $detail . '.');
        }

        return [$document, new \DOMXPath($document)];
    }

    /**
     * @param array<string, mixed> $run
     * @return array{0: string, 1: string}
     */
    private function taxonomy(\DOMXPath $xpath, array $run, string $type): array
    {
        $profile = trim((string)($run['taxonomy_profile'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/D', $profile)) {
            throw new \RuntimeException(ucfirst($type) . ' iXBRL taxonomy profile metadata is missing or invalid.');
        }
        if ($type === 'accounts' && $profile !== IxbrlTaxonomyProfileService::PROFILE) {
            throw new \RuntimeException('Accounts iXBRL is not using the phase-one FRS 105 filing profile.');
        }

        $refs = [];
        foreach ($xpath->query('//*[local-name()="schemaRef"]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            foreach ($node->attributes ?? [] as $attribute) {
                if ($attribute->localName === 'href' && trim($attribute->value) !== '') {
                    $refs[] = trim($attribute->value);
                }
            }
        }
        $refs = array_values(array_unique($refs));
        if (count($refs) !== 1 || !preg_match('#^https?://#i', $refs[0])) {
            throw new \RuntimeException(ucfirst($type) . ' iXBRL must contain one absolute taxonomy schemaRef.');
        }
        if ($type === 'accounts' && !hash_equals(IxbrlTaxonomyProfileService::SCHEMA_REF, $refs[0])) {
            throw new \RuntimeException('Accounts iXBRL schemaRef does not match its configured taxonomy profile.');
        }
        if (!preg_match_all('/(?<!\d)(20\d{2}-\d{2}-\d{2})(?!\d)/', $refs[0], $matches)
            || ($matches[1] ?? []) === []) {
            throw new \RuntimeException(ucfirst($type) . ' iXBRL schemaRef does not identify a taxonomy version date.');
        }
        $versionDate = (string)end($matches[1]);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $versionDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $versionDate) {
            throw new \RuntimeException(ucfirst($type) . ' iXBRL taxonomy version date is invalid.');
        }

        return [$refs[0], $versionDate];
    }

    /** @return array<string, string> */
    private function accountsIdentity(
        \DOMXPath $xpath,
        string $expectedRegistration,
        string $expectedStart,
        string $expectedEnd
    ): array {
        $registration = strtoupper($this->singleFact(
            $xpath,
            ['UKCompaniesHouseRegisteredNumber', 'CompanyRegistrationNumber'],
            'Accounts iXBRL company registration number'
        ));
        $periodStart = $this->singleFact(
            $xpath,
            ['StartDateForPeriodCoveredByReport'],
            'Accounts iXBRL full-period start date'
        );
        $periodEnd = $this->singleFact(
            $xpath,
            ['EndDateForPeriodCoveredByReport'],
            'Accounts iXBRL full-period end date'
        );
        if (!hash_equals($expectedRegistration, $registration)) {
            throw new \RuntimeException('Accounts iXBRL company registration number does not match the company.');
        }
        if (!hash_equals($expectedStart, $periodStart) || !hash_equals($expectedEnd, $periodEnd)) {
            throw new \RuntimeException('Accounts iXBRL full-period dates do not match the locked accounting period.');
        }

        return [
            'registration_number' => $registration,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /** @return array<string, string> */
    private function computationIdentity(
        \DOMXPath $xpath,
        string $expectedRegistration,
        string $expectedUtr,
        string $expectedStart,
        string $expectedEnd
    ): array {
        $periodStart = $this->singleFact(
            $xpath,
            ['StartDateForPeriodCoveredByReport', 'StartDateForPeriodCoveredByComputation'],
            'Computations iXBRL CT-period start date'
        );
        $periodEnd = $this->singleFact(
            $xpath,
            ['EndDateForPeriodCoveredByReport', 'EndDateForPeriodCoveredByComputation'],
            'Computations iXBRL CT-period end date'
        );
        $utrValues = $this->factValues($xpath, [
            'UKTaxNumber',
            'UniqueTaxpayerReference',
            'CorporationTaxUniqueTaxpayerReference',
            'TaxReference',
        ]);
        foreach ($xpath->query('//*[local-name()="identifier"]') ?: [] as $identifier) {
            if (!$identifier instanceof \DOMElement) {
                continue;
            }
            $scheme = strtolower(trim($identifier->getAttribute('scheme')));
            $value = preg_replace('/\s+/', '', trim($identifier->textContent)) ?? '';
            if (($scheme === '' || str_contains($scheme, 'hmrc') || str_contains($scheme, 'tax'))
                && preg_match('/^[0-9]{10}$/D', $value)) {
                $utrValues[] = $value;
            }
        }
        $utrValues = array_values(array_unique(array_map(
            static fn(string $value): string => preg_replace('/\s+/', '', $value) ?? '',
            $utrValues
        )));
        if ($utrValues === []) {
            throw new \RuntimeException('Computations iXBRL does not contain a parseable Corporation Tax UTR.');
        }
        if (count($utrValues) !== 1 || !hash_equals($expectedUtr, $utrValues[0])) {
            throw new \RuntimeException('Computations iXBRL UTR does not match the CT600 return.');
        }
        if (!hash_equals($expectedStart, $periodStart) || !hash_equals($expectedEnd, $periodEnd)) {
            throw new \RuntimeException('Computations iXBRL dates do not match the selected Corporation Tax period.');
        }

        $registrations = array_values(array_unique(array_map(
            static fn(string $value): string => strtoupper(trim($value)),
            $this->factValues($xpath, ['UKCompaniesHouseRegisteredNumber', 'CompanyRegistrationNumber'])
        )));
        if ($registrations !== []
            && (count($registrations) !== 1 || !hash_equals($expectedRegistration, $registrations[0]))) {
            throw new \RuntimeException('Computations iXBRL company registration number does not match the company.');
        }

        return [
            'registration_number' => $registrations[0] ?? $expectedRegistration,
            'utr' => $utrValues[0],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /**
     * @param list<string> $localNames
     * @return list<string>
     */
    private function factValues(\DOMXPath $xpath, array $localNames): array
    {
        $values = [];
        foreach ($xpath->query('//*[@name]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = trim($node->getAttribute('name'));
            $localName = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;
            if (!in_array($localName, $localNames, true)) {
                continue;
            }
            $value = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /** @param list<string> $localNames */
    private function singleFact(\DOMXPath $xpath, array $localNames, string $label): string
    {
        $values = $this->factValues($xpath, $localNames);
        if (count($values) !== 1) {
            throw new \RuntimeException(
                $label . (count($values) === 0 ? ' is missing.' : ' is ambiguous.')
            );
        }

        return $values[0];
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $inspection
     */
    private function artifactFromInspection(string $type, array $run, array $inspection): Ct600IxbrlArtifact
    {
        $identity = (array)$inspection['identity'];

        return new Ct600IxbrlArtifact(
            documentType: $type,
            runId: (int)$run['id'],
            path: (string)$inspection['path'],
            filename: (string)$inspection['filename'],
            outputSha256: (string)$inspection['output_sha256'],
            validatedSha256: (string)$inspection['validated_sha256'],
            externalValidationPassed: true,
            periodStart: (string)$identity['period_start'],
            periodEnd: (string)$identity['period_end'],
            taxonomyProfile: (string)$inspection['taxonomy_profile'],
            baseTaxonomyVersionDate: (string)$inspection['base_taxonomy_version_date'],
            registrationNumber: isset($identity['registration_number'])
                ? (string)$identity['registration_number']
                : null,
            utr: isset($identity['utr']) ? (string)$identity['utr'] : null
        );
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $readiness
     * @return array<string, mixed>
     */
    private function computationSummary(array $run, array $readiness, int $runId): array
    {
        try {
            $summary = json_decode((string)($run['summary_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The locked Corporation Tax computation snapshot is not valid JSON.');
        }
        if (!is_array($summary) || empty($summary['available'])) {
            throw new \RuntimeException('The locked Corporation Tax computation snapshot is unavailable.');
        }
        $rowHash = strtolower(trim((string)($run['computation_hash'] ?? '')));
        $summaryHash = strtolower(trim((string)($summary['computation_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $rowHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $summaryHash)
            || !hash_equals($rowHash, $summaryHash)) {
            throw new \RuntimeException('The locked Corporation Tax computation snapshot hash is inconsistent.');
        }

        $readinessSummary = (array)($readiness['computation'] ?? []);
        if ((int)($readinessSummary['computation_run_id'] ?? 0) !== $runId
            || !hash_equals($rowHash, strtolower(trim((string)($readinessSummary['computation_hash'] ?? ''))))) {
            throw new \RuntimeException('The Corporation Tax computation changed after the readiness assessment.');
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $accountsInspection
     * @param array<string, mixed> $accountingPeriod
     * @param array<string, mixed> $ctPeriod
     * @param list<array<string, mixed>> $ctPeriods
     * @return array<string, mixed>
     */
    private function mapCalculation(
        array $summary,
        array $accountsInspection,
        array $accountingPeriod,
        array $ctPeriod,
        array $ctPeriods
    ): array {
        $this->assertSummaryPeriod($summary, $ctPeriod);

        $taxableBeforeLosses = $this->money($summary, 'taxable_before_losses');
        $taxableProfit = $this->money($summary, 'taxable_profit');
        $lossCreated = $this->money($summary, 'loss_created_in_period', 'taxable_loss');
        $lossesBroughtForward = $this->money($summary, 'losses_brought_forward');
        $lossesUsed = $this->money($summary, 'losses_used');
        $lossesCarriedForward = $this->money($summary, 'losses_carried_forward');
        $tax = $this->money($summary, 'estimated_corporation_tax');

        foreach ([$taxableProfit, $lossCreated, $lossesBroughtForward, $lossesUsed, $lossesCarriedForward, $tax] as $value) {
            if ($value < -0.004) {
                throw new \RuntimeException('The locked Corporation Tax computation contains a negative unsigned value.');
            }
        }
        if ($lossesUsed - $lossesBroughtForward > 0.004
            || $lossesUsed - max(0.0, $taxableBeforeLosses) > 0.004) {
            throw new \RuntimeException('The locked brought-forward loss utilisation does not reconcile to trading profits.');
        }
        $expectedTaxableProfit = max(0.0, round($taxableBeforeLosses - $lossesUsed, 2));
        if (abs($taxableProfit - $expectedTaxableProfit) >= 0.005) {
            throw new \RuntimeException('The locked taxable profit does not reconcile after brought-forward losses.');
        }

        $aia = $this->aiaOnly($summary);
        $turnoverAllocation = $this->turnoverAllocation(
            $accountsInspection,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end'],
            (int)$ctPeriod['id'],
            $ctPeriods
        );

        // CT600 box 155 is the post-capital-allowance trading result before
        // brought-forward trading losses. Box 250 is specifically management
        // expenses/capital allowances and must not be used for trading AIA.
        $tradingProfits = $this->roundProfitWholePounds(max(0.0, $taxableBeforeLosses));
        $lossesBfUsed = min($tradingProfits, $this->roundProfitWholePounds($lossesUsed));
        $netTradingProfits = max(0, $tradingProfits - $lossesBfUsed);
        $chargeableProfits = $this->roundProfitWholePounds($taxableProfit);
        $taxPence = $this->roundTaxPence($tax);

        $calculation = [
            Ct600ReturnData::TURNOVER => $turnoverAllocation['whole_pounds'],
            Ct600ReturnData::TRADING_PROFITS => $tradingProfits,
            Ct600ReturnData::LOSSES_BROUGHT_FORWARD => $lossesBfUsed,
            Ct600ReturnData::NET_TRADING_PROFITS => $netTradingProfits,
            Ct600ReturnData::PROFITS_BEFORE_OTHER_DEDUCTIONS => $netTradingProfits,
            Ct600ReturnData::CAPITAL_ALLOWANCES => 0,
            Ct600ReturnData::TRADING_LOSSES => 0,
            Ct600ReturnData::TRADING_LOSSES_CARRIED_FORWARD => 0,
            Ct600ReturnData::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF => $chargeableProfits,
            Ct600ReturnData::CHARGEABLE_PROFITS => $chargeableProfits,
            Ct600ReturnData::AIA => $this->roundReliefWholePounds($aia),
            Ct600ReturnData::LOSS_ARISING => $this->roundReliefWholePounds($lossCreated),
            Ct600ReturnData::CORPORATION_TAX => $taxPence,
            Ct600ReturnData::NET_CORPORATION_TAX => $taxPence,
            Ct600ReturnData::TOTAL_RELIEFS_AND_DEDUCTIONS => 0,
            Ct600ReturnData::TAX_PAYABLE => $taxPence,
        ];

        return [
            'calculation' => $calculation,
            'source' => [
                'computation_hash' => (string)$summary['computation_hash'],
                'taxable_before_losses' => round($taxableBeforeLosses, 2),
                'taxable_profit' => round($taxableProfit, 2),
                'loss_created' => round($lossCreated, 2),
                'losses_brought_forward' => round($lossesBroughtForward, 2),
                'losses_used' => round($lossesUsed, 2),
                'losses_carried_forward' => round($lossesCarriedForward, 2),
                'aia' => round($aia, 2),
                'estimated_corporation_tax' => round($tax, 2),
                'accounts_turnover' => $turnoverAllocation['accounts_turnover'],
                'allocated_turnover' => $turnoverAllocation['allocated_turnover'],
            ],
            'rounding' => [
                'profit_and_income_boxes' => 'down_to_whole_pounds',
                'aia_and_loss_arising_boxes' => 'up_to_whole_pounds',
                'tax_boxes' => 'nearest_penny_half_up',
                'long_period_turnover' => 'pence_half_up_by_inclusive_days_with_final_period_residual',
                'losses_brought_forward' => 'down_to_whole_pounds_capped_at_box_155',
            ],
            'box_notes' => [
                Ct600ReturnData::CAPITAL_ALLOWANCES => 'Zero: CT600 management-expense capital allowances are not trading AIA.',
                Ct600ReturnData::AIA => 'Trading AIA is reported in AllowancesAndCharges/AIACapitalAllowancesInc.',
                Ct600ReturnData::LOSSES_BROUGHT_FORWARD => 'Set against box 155 trading profits; CT7 therefore reduces net trading profits to nil.',
            ],
        ];
    }

    /** @param array<string, mixed> $summary */
    /** @param array<string, mixed> $ctPeriod */
    private function assertSummaryPeriod(array $summary, array $ctPeriod): void
    {
        if ((int)($summary['ct_period_id'] ?? 0) !== (int)$ctPeriod['id']
            || (string)($summary['period_start'] ?? '') !== (string)$ctPeriod['period_start']
            || (string)($summary['period_end'] ?? '') !== (string)$ctPeriod['period_end']) {
            throw new \RuntimeException('The locked computation summary does not match the selected CT period.');
        }
    }

    /** @param array<string, mixed> $summary */
    private function aiaOnly(array $summary): float
    {
        $breakdown = (array)($summary['capital_allowance_breakdown'] ?? []);
        if (empty($breakdown['available'])) {
            throw new \RuntimeException('The locked computation has no capital-allowance breakdown for CT600 mapping.');
        }
        $aia = 0.0;
        $otherAllowances = 0.0;
        $balancingCharges = 0.0;
        foreach ((array)($breakdown['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aia += (float)($row['aia_claimed'] ?? 0);
            $otherAllowances += (float)($row['fya_claimed'] ?? 0)
                + (float)($row['wda_claimed'] ?? 0)
                + (float)($row['balancing_allowance'] ?? 0);
            $balancingCharges += (float)($row['balancing_charge'] ?? 0);
        }
        if (abs($otherAllowances) >= 0.005 || abs($balancingCharges) >= 0.005) {
            throw new \RuntimeException(
                'Phase-one AP79 mapping supports AIA only; another capital allowance or balancing charge requires reviewed CT600 box mapping.'
            );
        }
        $summaryAllowances = $this->money($summary, 'capital_allowances');
        if (abs($summaryAllowances - $aia) >= 0.005) {
            throw new \RuntimeException('The locked AIA breakdown does not reconcile to total capital allowances.');
        }

        return round($aia, 2);
    }

    /**
     * @param array<string, mixed> $inspection
     * @param list<array<string, mixed>> $ctPeriods
     * @return array{accounts_turnover: float, allocated_turnover: float, whole_pounds: int}
     */
    private function turnoverAllocation(
        array $inspection,
        string $accountingStart,
        string $accountingEnd,
        int $selectedCtPeriodId,
        array $ctPeriods
    ): array {
        /** @var \DOMXPath $xpath */
        $xpath = $inspection['xpath'];
        $contexts = $this->contextPeriods($xpath);
        $values = [];
        foreach ($xpath->query('//*[@name]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = trim($node->getAttribute('name'));
            $localName = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;
            if (!in_array($localName, ['TurnoverRevenue', 'Turnover'], true)) {
                continue;
            }
            $context = $contexts[$node->getAttribute('contextRef')] ?? null;
            if (!is_array($context)
                || ($context['start'] ?? '') !== $accountingStart
                || ($context['end'] ?? '') !== $accountingEnd) {
                continue;
            }
            $values[] = $this->numericFact($node);
        }
        $values = array_values(array_unique(array_map(
            static fn(float $value): string => number_format($value, 2, '.', ''),
            $values
        )));
        if (count($values) !== 1) {
            throw new \RuntimeException('Accounts iXBRL must contain one full-period turnover fact for CT600 box 145.');
        }
        $accountsTurnover = (float)$values[0];
        if ($accountsTurnover < -0.004) {
            throw new \RuntimeException('A negative accounts turnover is outside phase-one CT600 mapping.');
        }

        $totalPence = (int)round($accountsTurnover * 100, 0, PHP_ROUND_HALF_UP);
        $accountingDays = $this->inclusiveDays($accountingStart, $accountingEnd);
        $allocated = 0;
        $selectedPence = null;
        foreach ($ctPeriods as $index => $period) {
            $periodDays = $this->inclusiveDays(
                (string)$period['period_start'],
                (string)$period['period_end']
            );
            $pence = $index === count($ctPeriods) - 1
                ? $totalPence - $allocated
                : (int)round($totalPence * ($periodDays / $accountingDays), 0, PHP_ROUND_HALF_UP);
            $allocated += $pence;
            if ((int)$period['id'] === $selectedCtPeriodId) {
                $selectedPence = $pence;
            }
        }
        if ($selectedPence === null || $allocated !== $totalPence) {
            throw new \RuntimeException('Long-period turnover could not be allocated to the selected CT period.');
        }

        return [
            'accounts_turnover' => round($accountsTurnover, 2),
            'allocated_turnover' => round($selectedPence / 100, 2),
            'whole_pounds' => intdiv(max(0, $selectedPence), 100),
        ];
    }

    /** @return array<string, array{start?: string, end?: string, instant?: string}> */
    private function contextPeriods(\DOMXPath $xpath): array
    {
        $periods = [];
        foreach ($xpath->query('//*[local-name()="context"]') ?: [] as $context) {
            if (!$context instanceof \DOMElement || trim($context->getAttribute('id')) === '') {
                continue;
            }
            $start = trim((string)$xpath->evaluate('string(.//*[local-name()="startDate"][1])', $context));
            $end = trim((string)$xpath->evaluate('string(.//*[local-name()="endDate"][1])', $context));
            $instant = trim((string)$xpath->evaluate('string(.//*[local-name()="instant"][1])', $context));
            $periods[$context->getAttribute('id')] = array_filter([
                'start' => $start,
                'end' => $end,
                'instant' => $instant,
            ], static fn(string $value): bool => $value !== '');
        }

        return $periods;
    }

    private function numericFact(\DOMElement $node): float
    {
        $lexical = preg_replace('/[\s,]+/u', '', trim($node->textContent)) ?? '';
        if (!preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/D', $lexical)) {
            throw new \RuntimeException('Accounts iXBRL turnover has an unsupported numeric transformation.');
        }
        $value = (float)$lexical;
        if ($node->getAttribute('sign') === '-') {
            $value = -abs($value);
        }
        $scale = trim($node->getAttribute('scale'));
        if ($scale !== '') {
            if (!preg_match('/^-?\d{1,2}$/D', $scale)) {
                throw new \RuntimeException('Accounts iXBRL turnover scale is invalid.');
            }
            $value *= 10 ** (int)$scale;
        }

        return round($value, 2);
    }

    /** @param array<string, mixed> $summary */
    private function money(array $summary, string $key, ?string $fallback = null): float
    {
        $value = array_key_exists($key, $summary)
            ? $summary[$key]
            : ($fallback !== null && array_key_exists($fallback, $summary) ? $summary[$fallback] : null);
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \RuntimeException('The locked Corporation Tax computation is missing ' . $key . '.');
        }
        if (!is_numeric((string)$value)) {
            throw new \RuntimeException('The locked Corporation Tax computation contains invalid ' . $key . '.');
        }

        return round((float)$value, 2);
    }

    private function roundProfitWholePounds(float $value): int
    {
        return (int)floor(max(0.0, $value) + 0.0000001);
    }

    private function roundReliefWholePounds(float $value): int
    {
        return (int)ceil(max(0.0, $value) - 0.0000001);
    }

    private function roundTaxPence(float $value): int
    {
        return (int)round(max(0.0, $value) * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function inclusiveDays(string $start, string $end): int
    {
        $startDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$startDate || !$endDate || $startDate > $endDate) {
            throw new \RuntimeException('Valid inclusive dates are required for CT600 turnover allocation.');
        }

        return (int)$startDate->diff($endDate)->days + 1;
    }

    /** @param array<string, mixed> $declaration */
    /** @return array{0: string, 1: string} */
    private function declaration(array $declaration): array
    {
        $name = trim((string)($declaration['name'] ?? ''));
        if (($declaration['confirmed'] ?? null) !== true) {
            throw new \RuntimeException('The declarant must explicitly confirm the exact frozen return.');
        }
        $statusToken = strtolower(trim((string)($declaration['status'] ?? '')));
        $status = match ($statusToken) {
            'proper_officer' => 'Proper officer',
            'authorised_person' => 'Authorised person',
            default => throw new \RuntimeException(
                'Declaration status must be proper_officer or authorised_person.'
            ),
        };
        if ($name === '') {
            throw new \RuntimeException('Declarant name is required before freezing the CT600.');
        }

        return [$name, $status];
    }

    /** @param array<string, mixed> $source */
    /** @return array<string, mixed> */
    private function requiredArray(array $source, string $key): array
    {
        if (!isset($source[$key]) || !is_array($source[$key])) {
            throw new \RuntimeException('The filing-readiness snapshot is missing ' . $key . '.');
        }

        return $source[$key];
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \RuntimeException('The filing-readiness snapshot has an invalid ' . $label . ' ID.');
        }

        return (int)$id;
    }
}
