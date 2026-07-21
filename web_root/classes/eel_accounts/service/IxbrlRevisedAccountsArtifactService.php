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
            'SELECT period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return ['success' => false, 'errors' => ['The selected accounting period was not found.'], 'warnings' => []];
        }

        $periodEnd = (string)$period['period_end'];
        $declarations = $this->declarations($periodEnd, $input);
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
            'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
            'evidence_artifact_id' => $evidenceArtifactId,
        ];
        $basisHash = hash('sha256', $this->canonicalJson($basis));

        $source = file_get_contents((string)$baseArtifact['path']);
        if (!is_string($source) || $source === '') {
            return ['success' => false, 'errors' => ['The ordinary accounts artifact could not be read.'], 'warnings' => []];
        }
        $transformed = $this->transform($source, $declarations, $evidenceArtifactId);
        if (empty($transformed['success'])) {
            return $transformed;
        }

        $directory = $this->managedDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['success' => false, 'errors' => ['Could not create the revised-accounts artifact directory.'], 'warnings' => []];
        }
        $filename = 'revised_accounts_' . $companyId . '_' . $accountingPeriodId . '_' . substr($basisHash, 0, 16) . '.xhtml';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, (string)$transformed['xhtml']) === false) {
            return ['success' => false, 'errors' => ['Could not write the revised-accounts artifact.'], 'warnings' => []];
        }

        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || $sha256 === '') {
            $this->removeManagedArtifact($path);
            return ['success' => false, 'errors' => ['The revised-accounts artifact could not be fingerprinted.'], 'warnings' => []];
        }
        $sha256 = strtolower($sha256);

        $validation = ($this->validationService ?? new IxbrlExternalValidationService())
            ->validateArtifact($path);
        if ((string)($validation['status'] ?? '') !== 'passed') {
            $this->removeManagedArtifact($path);
            return [
                'success' => false,
                'errors' => (array)($validation['errors'] ?? ['The revised accounts did not pass Arelle validation.']),
                'warnings' => (array)($validation['warnings'] ?? []),
                'validation' => $validation,
            ];
        }
        $validatedHash = strtolower(trim((string)($validation['validated_sha256'] ?? '')));
        if ($validatedHash === '' || !hash_equals($sha256, $validatedHash)) {
            $this->removeManagedArtifact($path);
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
            'declarations' => $declarations,
            'validation' => $validation,
            'evidence_artifact_id' => $evidenceArtifactId,
        ];
    }

    /** @return array{success: bool, errors: array, warnings: array, xhtml?: string} */
    public function transform(string $sourceXhtml, array $declarations, string $evidenceArtifactId = ''): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $loaded = $document->loadXML($sourceXhtml, LIBXML_NONET);
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
        if ($evidenceArtifactId !== '') {
            $head = $xpath->query('/xhtml:html/xhtml:head')->item(0);
            if (!$head instanceof \DOMElement) {
                return ['success' => false, 'errors' => ['The accounts artifact has no XHTML head.'], 'warnings' => []];
            }
            $meta = $document->createElementNS(self::XHTML_NS, 'meta');
            $meta->setAttribute('name', 'eel-evidence-artifact-id');
            $meta->setAttribute('content', $evidenceArtifactId);
            $head->appendChild($meta);
        }
        foreach (self::REVISION_FACTS as $concept) {
            if (($xpath->query('//*[@name="bus:' . $concept . '"]')->length ?? 0) > 0) {
                return ['success' => false, 'errors' => ['The source artifact already contains revised-report facts.'], 'warnings' => []];
            }
        }

        $section = $document->createElementNS(self::XHTML_NS, 'div');
        $section->setAttribute('id', 'revised-accounts-statements');
        $heading = $document->createElementNS(self::XHTML_NS, 'h2');
        $heading->appendChild($document->createTextNode('Revised accounts statements'));
        $section->appendChild($heading);

        $this->appendFactParagraph(
            $document,
            $section,
            'This report is an amended/revised version of a previously filed report: ',
            'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse',
            'true'
        );
        $this->appendFactParagraph($document, $section, '', 'StatementThatRevisedReportReplacesPreviouslyFiledReportForPeriod', (string)$declarations['replaces_statement']);
        $this->appendFactParagraph($document, $section, '', 'StatementThatThisReportNowStatutoryAccountsForPeriod', (string)$declarations['statutory_accounts_statement']);
        $this->appendFactParagraph($document, $section, '', 'StatementThatThisReportHasBeenPreparedAsDatePreviouslyFiledReport', (string)$declarations['prepared_as_statement']);
        $this->appendFactParagraph($document, $section, 'Original non-compliance: ', 'StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006', (string)$declarations['non_compliance_explanation']);
        $this->appendFactParagraph($document, $section, 'Significant amendments: ', 'StatementSignificantAmendmentsToPreviouslyFiledReport', (string)$declarations['significant_amendments']);
        $this->appendFactParagraph($document, $section, 'Revision approved on: ', 'DateApprovalRevisionReport', (string)$declarations['revision_approval_date'], true);

        $firstHeading = $xpath->query('/xhtml:html/xhtml:body/xhtml:h1')->item(0);
        if ($firstHeading instanceof \DOMNode && $firstHeading->nextSibling instanceof \DOMNode) {
            $body->insertBefore($section, $firstHeading->nextSibling);
        } else {
            $body->appendChild($section);
        }
        if ($evidenceArtifactId !== '') {
            $footer = $document->createElementNS(self::XHTML_NS, 'p');
            $footer->appendChild($document->createTextNode('EEL filing evidence artifact: ' . $evidenceArtifactId));
            $body->appendChild($footer);
        }

        $xhtml = $document->saveXML();
        if (!is_string($xhtml) || $xhtml === '') {
            return ['success' => false, 'errors' => ['The revised XHTML could not be serialised.'], 'warnings' => []];
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
        foreach (self::REVISION_FACTS as $concept) {
            if (($checkXpath->query('//*[@name="bus:' . $concept . '"]')->length ?? 0) !== 1) {
                return ['success' => false, 'errors' => ['Required revised-report fact is missing or duplicated: bus:' . $concept . '.'], 'warnings' => []];
            }
        }

        return ['success' => true, 'errors' => [], 'warnings' => [], 'xhtml' => $xhtml];
    }

    private function appendFactParagraph(
        \DOMDocument $document,
        \DOMElement $section,
        string $label,
        string $concept,
        string $value,
        bool $date = false
    ): void {
        $paragraph = $document->createElementNS(self::XHTML_NS, 'p');
        if ($label !== '') {
            $paragraph->appendChild($document->createTextNode($label));
        }
        $fact = $document->createElementNS(self::IX_NS, 'ix:nonNumeric');
        $fact->setAttribute('name', 'bus:' . $concept);
        $fact->setAttribute('contextRef', 'current_period_duration');
        if ($date) {
            $fact->setAttribute('format', 'ixt:dateyearmonthday');
        }
        $fact->appendChild($document->createTextNode($value));
        $paragraph->appendChild($fact);
        $section->appendChild($paragraph);
    }

    private function declarations(string $periodEnd, array $input): array
    {
        $displayEnd = $this->displayDate($periodEnd);

        return [
            'report_is_revised' => true,
            'replaces_statement' => 'These revised accounts replace the accounts previously delivered to the registrar for the period ended ' . $displayEnd . '.',
            'statutory_accounts_statement' => 'These revised accounts are now the statutory accounts for the period ended ' . $displayEnd . '.',
            'prepared_as_statement' => 'These revised accounts have been prepared as at the date of the previously filed accounts.',
            'non_compliance_explanation' => trim((string)($input['non_compliance_explanation'] ?? $input['original_non_compliance_explanation'] ?? '')),
            'significant_amendments' => trim((string)($input['significant_amendments'] ?? '')),
            'revision_approval_date' => trim((string)($input['revision_approval_date'] ?? '')),
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
        }
        $approvalDate = trim((string)($input['revision_approval_date'] ?? ''));
        if (!$this->validDate($approvalDate)) {
            $errors[] = 'Enter a valid revision approval date.';
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

    private function managedDirectory(): string
    {
        return $this->outputDirectory !== null && trim($this->outputDirectory) !== ''
            ? rtrim($this->outputDirectory, '\\/')
            : PROJECT_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'companies_house' . DIRECTORY_SEPARATOR . 'revised_accounts';
    }

    private function removeManagedArtifact(string $path): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }
        $managed = realpath($this->managedDirectory());
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
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not fingerprint the revised-accounts basis.');
        }

        return $json;
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
