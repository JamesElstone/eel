<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Creates a complete revised-report copy without mutating the ordinary accounts artifact. */
final class IxbrlRevisedAccountsArtifactService
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const REVISION_FACTS = [
        'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse',
        'StatementThatRevisedReportReplacesPreviouslyFiledReportForPeriod',
        'StatementThatThisReportNowStatutoryAccountsForPeriod',
        'StatementThatThisReportHasBeenPreparedAsDatePreviouslyFiledReport',
        'StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006',
        'StatementSignificantAmendmentsToPreviouslyFiledReport',
        'DateApprovalRevisionReport',
    ];

    public function __construct(
        private readonly ?IxbrlFilingArtifactService $artifactService = null,
        private readonly ?IxbrlExternalValidationService $validationService = null,
        private readonly ?string $outputDirectory = null,
    ) {
    }

    public function prepare(int $companyId, int $accountingPeriodId, array $input, string $evidenceArtifactId = ''): array
    {
        $errors = $this->inputErrors($companyId, $accountingPeriodId, $input);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'warnings' => []];
        }

        $baseArtifact = ($this->artifactService ?? new IxbrlFilingArtifactService())
            ->locate($companyId, $accountingPeriodId);
        if (empty($baseArtifact['ok'])) {
            return [
                'success' => false,
                'errors' => (array)($baseArtifact['errors'] ?? ['A filing-ready ordinary accounts artifact is required.']),
                'warnings' => [],
            ];
        }

        $period = \InterfaceDB::fetchOne(
            'SELECT ap.period_start, ap.period_end, c.company_number, c.company_name
             FROM accounting_periods ap
             INNER JOIN companies c ON c.id = ap.company_id
             WHERE ap.id = :id AND ap.company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return ['success' => false, 'errors' => ['The selected accounting period was not found.'], 'warnings' => []];
        }

        $periodEnd = (string)$period['period_end'];
        $input['company_name'] = trim((string)($period['company_name'] ?? ''));
        $declarations = $this->declarations($periodEnd, $input);
        try {
            $supersededFacts = (new IxbrlSupersededFactsService())->facts(
                $companyId,
                (int)$input['original_document_id'],
                $periodEnd
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => ['The original filing could not be used for superseded facts: ' . $exception->getMessage()],
                'warnings' => [],
            ];
        }
        $basis = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'original_document_id' => (int)$input['original_document_id'],
            'base_run_id' => (int)($baseArtifact['run_id'] ?? 0),
            'base_sha256' => (string)($baseArtifact['hash'] ?? ''),
            'base_basis_hash' => (string)($baseArtifact['basis_hash'] ?? ''),
            'period_start' => (string)$period['period_start'],
            'period_end' => $periodEnd,
            'declarations' => $declarations,
            'superseded_facts' => $supersededFacts,
            'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
        ];
        $basisHash = hash('sha256', $this->canonicalJson($basis));

        $source = file_get_contents((string)$baseArtifact['path']);
        if (!is_string($source) || $source === '') {
            return ['success' => false, 'errors' => ['The ordinary accounts artifact could not be read.'], 'warnings' => []];
        }
        $transformed = $this->transform($source, $declarations, $evidenceArtifactId, $supersededFacts);
        if (empty($transformed['success'])) {
            return $transformed;
        }

        $directory = $this->managedDirectory($companyId);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['success' => false, 'errors' => ['Could not create the revised-accounts artifact directory.'], 'warnings' => []];
        }
        $xhtml = (string)$transformed['xhtml'];
        $sha256 = hash('sha256', $xhtml);
        try {
            $filename = (new IxbrlArtifactFilenameService())->build(
                (string)$period['company_number'],
                $accountingPeriodId,
                (int)($baseArtifact['filing_approval_id'] ?? 0),
                (int)($baseArtifact['run_id'] ?? 0),
                IxbrlArtifactFilenameService::DESTINATION_COMPANIES_HOUSE,
                str_replace('-', '', (string)$period['period_start']),
                str_replace('-', '', $periodEnd),
                $sha256
            );
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()], 'warnings' => []];
        }
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $created = false;
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            if (!is_string($existingHash) || !hash_equals($sha256, strtolower($existingHash))) {
                return ['success' => false, 'errors' => ['The revised-accounts artifact filename is occupied by different content.'], 'warnings' => []];
            }
        } else {
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                if (!is_file($path)) {
                    return ['success' => false, 'errors' => ['Could not create the revised-accounts artifact.'], 'warnings' => []];
                }
                $existingHash = hash_file('sha256', $path);
                if (!is_string($existingHash) || !hash_equals($sha256, strtolower($existingHash))) {
                    return ['success' => false, 'errors' => ['The revised-accounts artifact filename is occupied by different content.'], 'warnings' => []];
                }
            } else {
                $created = true;
                try {
                    if (!flock($handle, LOCK_EX) || fwrite($handle, $xhtml) !== strlen($xhtml) || !fflush($handle)) {
                        throw new \RuntimeException('Could not write the revised-accounts artifact.');
                    }
                } catch (\Throwable $exception) {
                    fclose($handle);
                    @unlink($path);
                    return ['success' => false, 'errors' => [$exception->getMessage()], 'warnings' => []];
                }
                fclose($handle);
            }
        }

        $storedSha256 = hash_file('sha256', $path);
        if (!is_string($storedSha256) || !hash_equals($sha256, strtolower($storedSha256))) {
            if ($created) {
                $this->removeManagedArtifact($path, $companyId);
            }
            return ['success' => false, 'errors' => ['The revised-accounts artifact could not be fingerprinted.'], 'warnings' => []];
        }

        $validation = ($this->validationService ?? new IxbrlExternalValidationService())
            ->validateArtifact($path);
        if ((string)($validation['status'] ?? '') !== 'passed') {
            if ($created) {
                $this->removeManagedArtifact($path, $companyId);
            }
            return [
                'success' => false,
                'errors' => (array)($validation['errors'] ?? ['The revised accounts did not pass Arelle validation.']),
                'warnings' => (array)($validation['warnings'] ?? []),
                'validation' => $validation,
            ];
        }
        $validatedHash = strtolower(trim((string)($validation['validated_sha256'] ?? '')));
        if ($validatedHash === '' || !hash_equals($sha256, $validatedHash)) {
            if ($created) {
                $this->removeManagedArtifact($path, $companyId);
            }
            return [
                'success' => false,
                'errors' => ['The revised artifact does not match the file validated by Arelle.'],
                'warnings' => [],
            ];
        }

        return [
            'success' => true,
            'errors' => [],
            'warnings' => (array)($validation['warnings'] ?? []),
            'path' => $path,
            'filename' => $filename,
            'sha256' => $sha256,
            'validated_sha256' => $validatedHash,
            'basis_hash' => $basisHash,
            'base_run_id' => (int)($baseArtifact['run_id'] ?? 0),
            'base_sha256' => (string)($baseArtifact['hash'] ?? ''),
            'fact_count' => (int)($transformed['fact_count'] ?? 0),
            'declarations' => $declarations,
            'validation' => $validation,
            'evidence_artifact_id' => $evidenceArtifactId,
        ];
    }

    /** @return array{success: bool, errors: array, warnings: array, xhtml?: string} */
    public function transform(
        string $sourceXhtml,
        array $declarations,
        string $evidenceArtifactId = '',
        array $supersededFacts = []
    ): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        $loaded = $document->loadXML($sourceXhtml, LIBXML_NONET | LIBXML_COMPACT);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [
                'success' => false,
                'errors' => ['The ordinary accounts artifact is not well-formed XML.' . ($xmlErrors !== [] ? ' ' . trim($xmlErrors[0]->message) : '')],
                'warnings' => [],
            ];
        }

        $root = $document->documentElement;
        if ($root instanceof \DOMElement) {
            $root->removeAttribute('lang');
            $root->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en');
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('xhtml', self::XHTML_NS);
        $xpath->registerNamespace('ix', self::IX_NS);
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $body = $xpath->query('/xhtml:html/xhtml:body')->item(0);
        if (!$body instanceof \DOMElement) {
            return ['success' => false, 'errors' => ['The accounts artifact has no XHTML body.'], 'warnings' => []];
        }
        if (($xpath->query('//xbrli:context[@id="current_period_duration"]')->length ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['The current-period duration context is missing or ambiguous.'], 'warnings' => []];
        }
        if (($xpath->query('//xbrli:context[@id="current_period_end"]')->length ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['The balance-sheet instant context is missing or ambiguous.'], 'warnings' => []];
        }
        if (($xpath->query('//ix:hidden')->length ?? 0) !== 1
            || ($xpath->query('//ix:resources')->length ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['The Inline XBRL hidden or resources block is missing or ambiguous.'], 'warnings' => []];
        }
        foreach (self::REVISION_FACTS as $concept) {
            if (($xpath->query('//*[@name="bus:' . $concept . '"]')->length ?? 0) > 0) {
                return ['success' => false, 'errors' => ['The source artifact already contains revised-report facts.'], 'warnings' => []];
            }
        }

        $approvalDate = trim((string)($declarations['revision_approval_date'] ?? ''));
        $originalApprovalDate = trim((string)($declarations['original_approval_date'] ?? ''));
        if (!$this->validDate($originalApprovalDate)) {
            return [
                'success' => false,
                'errors' => ['The original accounts approval date is missing or invalid.'],
                'warnings' => [],
            ];
        }
        $ordinaryApprovalDate = $this->ordinaryApprovalDate($xpath);
        if ($ordinaryApprovalDate === '' || $ordinaryApprovalDate !== $approvalDate) {
            return [
                'success' => false,
                'errors' => [
                    'The revision approval date must match the board approval and '
                    . 'DateAuthorisationFinancialStatementsForIssue in the ordinary accounts artifact.',
                ],
                'warnings' => [],
            ];
        }

        $titlePage = $xpath->query(
            '//xhtml:div[contains(concat(" ", normalize-space(@class), " "), " titlepage ")]'
        )->item(0);
        if (!$titlePage instanceof \DOMElement) {
            return ['success' => false, 'errors' => ['The ordinary accounts title page is missing.'], 'warnings' => []];
        }
        $coverHeading = $xpath->query('.//xhtml:h2', $titlePage)->item(0);
        if ($coverHeading instanceof \DOMElement) {
            $coverHeading->textContent = 'REVISED ACCOUNTS';
            $coverHeading->parentNode?->insertBefore(
                $document->createElementNS(self::XHTML_NS, 'p', 'Micro-entity accounts'),
                $coverHeading->nextSibling
            );
        }
        $headTitle = $xpath->query('/xhtml:html/xhtml:head/xhtml:title')->item(0);
        if ($headTitle instanceof \DOMElement) {
            $headTitle->textContent = 'Revised micro-entity accounts';
        }
        foreach ($xpath->query('//xhtml:h2 | //xhtml:div[contains(@class, "page-header-title")]') ?: [] as $heading) {
            if ($heading instanceof \DOMElement
                && str_contains($heading->textContent, 'Notes to the Micro-entity Accounts')) {
                $heading->textContent = str_replace(
                    'Notes to the Micro-entity Accounts',
                    'Notes to the Revised Micro-entity Accounts',
                    $heading->textContent
                );
            }
        }

        $hidden = $xpath->query('//ix:hidden')->item(0);
        if (!$hidden instanceof \DOMElement) {
            return ['success' => false, 'errors' => ['The Inline XBRL hidden block is missing.'], 'warnings' => []];
        }
        $revisionMarker = $document->createElementNS(self::IX_NS, 'ix:nonNumeric');
        $revisionMarker->setAttribute('name', 'bus:ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse');
        $revisionMarker->setAttribute('contextRef', 'current_period_duration');
        $revisionMarker->appendChild($document->createTextNode('true'));
        $hidden->appendChild($revisionMarker);

        $changedSupersededFacts = $this->changedSupersededFacts($xpath, $supersededFacts);
        if ($changedSupersededFacts !== []) {
            $contextResult = $this->appendSupersededContexts($document, $xpath, $changedSupersededFacts);
            if ($contextResult !== '') {
                return ['success' => false, 'errors' => [$contextResult], 'warnings' => []];
            }
            foreach ($changedSupersededFacts as $supersededFact) {
                $this->appendSupersededFact($document, $hidden, $supersededFact);
            }
        }

        $section = $document->createElementNS(self::XHTML_NS, 'div');
        $section->setAttribute('id', 'revised-accounts-statements');
        $section->setAttribute('class', 'accountspage pagebreak revision-page');
        $this->appendRevisionPageHeader($document, $section, $xpath);
        $heading = $document->createElementNS(self::XHTML_NS, 'h2');
        $heading->appendChild($document->createTextNode('REVISED ACCOUNTS'));
        $section->appendChild($heading);

        $this->appendFactParagraph($document, $section, '', 'StatementThatRevisedReportReplacesPreviouslyFiledReportForPeriod', (string)$declarations['replaces_statement']);
        $this->appendFactParagraph($document, $section, '', 'StatementThatThisReportNowStatutoryAccountsForPeriod', (string)$declarations['statutory_accounts_statement']);
        $this->appendFactParagraph($document, $section, '', 'StatementThatThisReportHasBeenPreparedAsDatePreviouslyFiledReport', (string)$declarations['prepared_as_statement']);
        $this->appendFactParagraph($document, $section, 'Respects in which the original accounts did not comply', 'StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006', (string)$declarations['non_compliance_explanation']);
        $this->appendFactParagraph($document, $section, 'Significant amendments made to remedy those defects', 'StatementSignificantAmendmentsToPreviouslyFiledReport', (string)$declarations['significant_amendments']);
        $this->appendRevisionApprovalStatement($document, $section, $approvalDate);

        if ($titlePage->nextSibling instanceof \DOMNode) {
            $body->insertBefore($section, $titlePage->nextSibling);
        } else {
            $body->appendChild($section);
        }

        $xhtml = $document->saveXML();
        if (!is_string($xhtml) || $xhtml === '') {
            return ['success' => false, 'errors' => ['The revised XHTML could not be serialised.'], 'warnings' => []];
        }
        try {
            $xhtml = (new CompaniesHouseIxbrlDocumentPolicyService())
                ->canonicaliseGeneratedDocument($xhtml);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => ['The revised XHTML XML declaration is not Companies House compliant: ' . $exception->getMessage()],
                'warnings' => [],
            ];
        }

        $check = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $valid = $check->loadXML($xhtml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            return ['success' => false, 'errors' => ['The revised XHTML is not well-formed XML.'], 'warnings' => []];
        }
        $checkXpath = new \DOMXPath($check);
        $checkXpath->registerNamespace('ix', self::IX_NS);
        $checkXpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $checkXpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');
        foreach (self::REVISION_FACTS as $concept) {
            if (($checkXpath->query('//*[@name="bus:' . $concept . '"]')->length ?? 0) !== 1) {
                return ['success' => false, 'errors' => ['Required revised-report fact is missing or duplicated: bus:' . $concept . '.'], 'warnings' => []];
            }
        }
        if (($checkXpath->query('//ix:header')->length ?? 0) !== 1
            || ($checkXpath->query('//ix:resources')->length ?? 0) !== 1
            || ($checkXpath->query('//ix:hidden')->length ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['The revised artifact duplicated Inline XBRL resources.'], 'warnings' => []];
        }
        if ($changedSupersededFacts !== []
            && ($checkXpath->query('//xbrldi:explicitMember[@dimension="bus:OriginalRevisedDataDimension" and normalize-space(.)="bus:Superseded"]')->length ?? 0) < 1) {
            return ['success' => false, 'errors' => ['The Superseded member was not emitted for original facts.'], 'warnings' => []];
        }
        if (($checkXpath->query('//text()[normalize-space(.)="true" and not(ancestor::ix:hidden)]')->length ?? 0) > 0
            || str_contains($xhtml, 'EEL filing evidence artifact:')) {
            return ['success' => false, 'errors' => ['Internal values are visible in the revised statutory accounts.'], 'warnings' => []];
        }

        return [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'xhtml' => $xhtml,
            'fact_count' => (int)($checkXpath->query('//ix:nonFraction | //ix:nonNumeric')->length ?? 0),
            'superseded_fact_count' => count($changedSupersededFacts),
        ];
    }

    private function ordinaryApprovalDate(\DOMXPath $xpath): string
    {
        $fact = $xpath->query(
            '//*[@name="core:DateAuthorisationFinancialStatementsForIssue"]'
        )->item(0);
        if (!$fact instanceof \DOMElement) {
            return '';
        }
        $value = trim($fact->textContent);
        if ($this->validDate($value)) {
            return $value;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!j F Y', $value);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('Y-m-d') : '';
    }

    /** @return list<array<string,mixed>> */
    private function changedSupersededFacts(\DOMXPath $xpath, array $supersededFacts): array
    {
        if ($supersededFacts === []) {
            return [];
        }
        $currentValues = [];
        foreach ($xpath->query('//ix:nonFraction[not(ancestor::ix:hidden)] | //ix:hidden/ix:nonFraction') ?: [] as $fact) {
            if (!$fact instanceof \DOMElement) {
                continue;
            }
            $name = $fact->getAttribute('name');
            $context = $fact->getAttribute('contextRef');
            if ($name === '' || $context === '' || str_contains($context, 'superseded')) {
                continue;
            }
            $lexical = trim(str_replace(',', '', $fact->textContent));
            $numeric = str_ends_with($fact->getAttribute('format'), 'zerodash') ? 0.0 : (float)$lexical;
            if ($fact->getAttribute('sign') === '-') {
                $numeric *= -1;
            }
            $currentValues[$name . '|' . $context] = round($numeric, 2);
        }

        $changed = [];
        foreach ($supersededFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $context = (string)($fact['context_ref'] ?? '');
            $currentContext = match ($context) {
                'current_period_end_superseded' => 'current_period_end',
                'current_period_duration_superseded' => 'current_period_duration',
                'current_period_end_superseded_creditors_within_one_year' =>
                    'current_period_end_creditors_within_one_year',
                'current_period_end_superseded_creditors_after_one_year' =>
                    'current_period_end_creditors_after_one_year',
                default => '',
            };
            $concept = (string)($fact['concept'] ?? '');
            $currentKey = $concept . '|' . $currentContext;
            if ($concept === '' || $currentContext === '' || !array_key_exists($currentKey, $currentValues)) {
                continue;
            }
            if (abs((float)($fact['value'] ?? 0) - $currentValues[$currentKey]) < 0.005) {
                continue;
            }
            $changed[] = $fact;
        }

        usort($changed, static fn(array $left, array $right): int =>
            [(string)$left['context_ref'], (string)$left['concept']]
            <=> [(string)$right['context_ref'], (string)$right['concept']]
        );

        return $changed;
    }

    /** Returns an error string, or an empty string on success. */
    private function appendSupersededContexts(
        \DOMDocument $document,
        \DOMXPath $xpath,
        array $facts
    ): string {
        $resources = $xpath->query('//ix:resources')->item(0);
        if (!$resources instanceof \DOMElement) {
            return 'The Inline XBRL resources block is missing.';
        }
        $contextSources = [
            'current_period_end_superseded' => 'current_period_end',
            'current_period_duration_superseded' => 'current_period_duration',
            'current_period_end_superseded_creditors_within_one_year' =>
                'current_period_end_creditors_within_one_year',
            'current_period_end_superseded_creditors_after_one_year' =>
                'current_period_end_creditors_after_one_year',
        ];
        $required = [];
        foreach ($facts as $fact) {
            $required[(string)($fact['context_ref'] ?? '')] = true;
        }
        $firstUnit = $xpath->query('./xbrli:unit', $resources)->item(0);
        foreach (array_keys($required) as $contextId) {
            $sourceId = $contextSources[$contextId] ?? '';
            $source = $sourceId !== ''
                ? $xpath->query('//xbrli:context[@id="' . $sourceId . '"]')->item(0)
                : null;
            if (!$source instanceof \DOMElement) {
                return 'The source context required for superseded tagging is missing: ' . $sourceId . '.';
            }
            $clone = $source->cloneNode(true);
            if (!$clone instanceof \DOMElement) {
                return 'A superseded context could not be created.';
            }
            $clone->setAttribute('id', $contextId);
            $entity = $xpath->query('./xbrli:entity', $clone)->item(0);
            if (!$entity instanceof \DOMElement) {
                return 'A superseded context has no entity.';
            }
            $segment = $xpath->query('./xbrli:segment', $entity)->item(0);
            if (!$segment instanceof \DOMElement) {
                $segment = $document->createElementNS(
                    'http://www.xbrl.org/2003/instance',
                    'xbrli:segment'
                );
                $entity->appendChild($segment);
            }
            $member = $document->createElementNS(
                'http://xbrl.org/2006/xbrldi',
                'xbrldi:explicitMember'
            );
            $member->setAttribute('dimension', 'bus:OriginalRevisedDataDimension');
            $member->appendChild($document->createTextNode('bus:Superseded'));
            $segment->appendChild($member);
            if ($firstUnit instanceof \DOMNode) {
                $resources->insertBefore($clone, $firstUnit);
            } else {
                $resources->appendChild($clone);
            }
        }

        return '';
    }

    private function appendSupersededFact(
        \DOMDocument $document,
        \DOMElement $hidden,
        array $source
    ): void {
        $value = round((float)($source['value'] ?? 0), 2);
        $decimals = (string)($source['decimals'] ?? '2');
        $precision = $decimals === '0' ? 0 : 2;
        $fact = $document->createElementNS(self::IX_NS, 'ix:nonFraction');
        $fact->setAttribute('name', (string)$source['concept']);
        $fact->setAttribute('contextRef', (string)$source['context_ref']);
        $fact->setAttribute('unitRef', (string)($source['unit_ref'] ?? 'GBP'));
        $fact->setAttribute('decimals', $decimals);
        $fact->setAttribute('format', 'ixt:numdotdecimal');
        if ($value < 0) {
            $fact->setAttribute('sign', '-');
        }
        $fact->appendChild($document->createTextNode(number_format(abs($value), $precision, '.', '')));
        $hidden->appendChild($fact);
    }

    private function appendRevisionPageHeader(
        \DOMDocument $document,
        \DOMElement $section,
        \DOMXPath $xpath
    ): void {
        $companyName = trim((string)$xpath->query(
            '//*[@name="bus:EntityCurrentLegalOrRegisteredName"]'
        )->item(0)?->textContent);
        $companyNumber = trim((string)$xpath->query(
            '//*[@name="bus:UKCompaniesHouseRegisteredNumber"]'
        )->item(0)?->textContent);
        $header = $document->createElementNS(self::XHTML_NS, 'div');
        $header->setAttribute('class', 'page-header');
        foreach ([
            ['page-header-name', $companyName],
            ['page-header-number', 'Registered number ' . $companyNumber],
            ['page-header-title', 'REVISED ACCOUNTS'],
        ] as [$class, $value]) {
            $item = $document->createElementNS(self::XHTML_NS, 'div');
            $item->setAttribute('class', $class);
            $item->appendChild($document->createTextNode(\eel_accounts\Support\Utf8::normalize($value)));
            $header->appendChild($item);
        }
        $section->appendChild($header);
    }

    private function appendFactParagraph(
        \DOMDocument $document,
        \DOMElement $section,
        string $label,
        string $concept,
        string $value,
        bool $date = false
    ): void {
        $container = $document->createElementNS(self::XHTML_NS, 'div');
        $container->setAttribute('class', 'revision-statement keepTogether');
        if ($label !== '') {
            $heading = $document->createElementNS(self::XHTML_NS, 'h3');
            $heading->appendChild($document->createTextNode(\eel_accounts\Support\Utf8::normalize($label)));
            $container->appendChild($heading);
        }
        $paragraph = $document->createElementNS(self::XHTML_NS, 'p');
        $fact = $document->createElementNS(self::IX_NS, 'ix:nonNumeric');
        $fact->setAttribute('name', 'bus:' . $concept);
        $fact->setAttribute('contextRef', 'current_period_duration');
        if ($date) {
            $fact->setAttribute('format', 'ixt:datedaymonthyearen');
            $value = $this->displayDate($value);
        }
        $fact->appendChild($document->createTextNode(\eel_accounts\Support\Utf8::normalize($value)));
        $paragraph->appendChild($fact);
        $container->appendChild($paragraph);
        $section->appendChild($container);
    }

    private function appendRevisionApprovalStatement(
        \DOMDocument $document,
        \DOMElement $section,
        string $approvalDate
    ): void {
        $container = $document->createElementNS(self::XHTML_NS, 'div');
        $container->setAttribute('class', 'revision-statement keepTogether');
        $paragraph = $document->createElementNS(self::XHTML_NS, 'p');
        $paragraph->appendChild($document->createTextNode('These revised accounts were approved on '));
        $fact = $document->createElementNS(self::IX_NS, 'ix:nonNumeric');
        $fact->setAttribute('name', 'bus:DateApprovalRevisionReport');
        $fact->setAttribute('contextRef', 'current_period_duration');
        $fact->setAttribute('format', 'ixt:datedaymonthyearen');
        $fact->appendChild($document->createTextNode($this->displayDate($approvalDate)));
        $paragraph->appendChild($fact);
        $paragraph->appendChild($document->createTextNode('.'));
        $container->appendChild($paragraph);
        $section->appendChild($container);
    }

    private function declarations(string $periodEnd, array $input): array
    {
        $displayEnd = $this->displayDate($periodEnd);
        $approvalDate = trim((string)($input['revision_approval_date'] ?? ''));
        $originalApprovalDate = trim((string)($input['original_approval_date'] ?? ''));
        $displayApprovalDate = $this->displayDate($approvalDate);
        $displayOriginalApprovalDate = $this->displayDate($originalApprovalDate);

        return [
            'report_is_revised' => true,
            'replaces_statement' => 'These revised accounts replace the original annual accounts of '
                . (trim((string)($input['company_name'] ?? '')) !== ''
                    ? trim((string)$input['company_name'])
                    : 'the company')
                . ' for the period ended ' . $displayEnd . '.',
            'statutory_accounts_statement' => 'They are now the statutory accounts of the company for that financial year.',
            'prepared_as_statement' => 'They have been prepared as at ' . $displayOriginalApprovalDate
                . ', being the date of the original annual accounts, and not as at '
                . $displayApprovalDate
                . '. Accordingly, they do not deal with events occurring between those dates.',
            'non_compliance_explanation' => trim((string)($input['non_compliance_explanation'] ?? $input['original_non_compliance_explanation'] ?? '')),
            'significant_amendments' => trim((string)($input['significant_amendments'] ?? '')),
            'original_approval_date' => $originalApprovalDate,
            'original_approval_evidence' => (array)($input['original_approval_evidence'] ?? []),
            'revision_approval_date' => $approvalDate,
        ];
    }

    private function inputErrors(int $companyId, int $accountingPeriodId, array $input): array
    {
        $errors = [];
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            $errors[] = 'Select a valid company and accounting period.';
        }
        if ((int)($input['original_document_id'] ?? 0) <= 0) {
            $errors[] = 'Select the exact original Companies House filing.';
        }
        $originalApprovalDate = trim((string)($input['original_approval_date'] ?? ''));
        if (!$this->validDate($originalApprovalDate)) {
            $errors[] = 'The original accounts approval date is missing or invalid.';
        }
        $nonCompliance = trim((string)($input['non_compliance_explanation'] ?? $input['original_non_compliance_explanation'] ?? ''));
        if ($nonCompliance === '') {
            $errors[] = 'Explain how the original accounts did not comply with the Companies Act 2006.';
        } elseif (mb_strlen($nonCompliance) > 8000) {
            $errors[] = 'The original non-compliance explanation must not exceed 8,000 characters.';
        }
        $amendments = trim((string)($input['significant_amendments'] ?? ''));
        if ($amendments === '') {
            $errors[] = 'Describe the significant amendments made to the original accounts.';
        } elseif (mb_strlen($amendments) > 8000) {
            $errors[] = 'The significant-amendments description must not exceed 8,000 characters.';
        } elseif (mb_strtolower($amendments) === mb_strtolower($nonCompliance)) {
            $errors[] = 'The original non-compliance and significant-amendments disclosures must be distinct.';
        }
        $approvalDate = trim((string)($input['revision_approval_date'] ?? ''));
        if (!$this->validDate($approvalDate)) {
            $errors[] = 'Enter a valid revision approval date.';
        } elseif ($this->validDate($originalApprovalDate) && $approvalDate <= $originalApprovalDate) {
            $errors[] = 'The revision approval date must be later than the original accounts approval date.';
        }

        return $errors;
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function displayDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('j F Y') : $date;
    }

    private function managedDirectory(int $companyId): string
    {
        if ($this->outputDirectory !== null && trim($this->outputDirectory) !== '') {
            return rtrim($this->outputDirectory, '\\/');
        }

        $company = \InterfaceDB::fetchOne(
            'SELECT company_number FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        );
        $companyNumber = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim((string)($company['company_number'] ?? ''))));
        if ($companyNumber === '') {
            throw new \RuntimeException('A Companies House number is required to store the revised iXBRL artifact.');
        }

        return rtrim((string)PROJECT_ROOT, '\\/')
            . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . $companyNumber
            . DIRECTORY_SEPARATOR . 'ixbrl';
    }

    private function removeManagedArtifact(string $path, int $companyId): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }
        $managed = realpath($this->managedDirectory($companyId));
        $directory = realpath(dirname($path));
        if ($managed !== false && $directory !== false && strcasecmp($managed, $directory) === 0) {
            @unlink($path);
        }
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn(mixed $item): mixed => $this->canonicalValue($item), $value);
            } else {
                ksort($value);
                foreach ($value as $key => $item) {
                    $value[$key] = $this->canonicalValue($item);
                }
            }
        }
        return \eel_accounts\Support\PersistentJson::encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalValue($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }
}
