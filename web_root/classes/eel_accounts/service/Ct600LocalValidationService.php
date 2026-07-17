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
 * Runs the local HMRC CT600 V1.994 validation gate over a final GovTalk request.
 *
 * HMRC distributes both a Schematron source file (`.sch`) and the compiled
 * executable XSLT (`.xslt`).  PHP cannot execute the Schematron source
 * directly, so the signed-off XSLT is the business-rule implementation.  The
 * source is read only to map HMRC error numbers back to their assertion IDs.
 */
final class Ct600LocalValidationService
{
    public const RIM_VERSION = '1.994';
    public const VALIDATOR = 'HMRC-CT-2014-v1-994';

    private const CT_XSD_SHA256 = '733a13f046310bb989bafd05977b00375de1a713528f2ae0aae21595b14f5808';
    private const ENVELOPE_XSD_SHA256 = 'c8e7b611d250e85f0a04e17e84c912675257d058c39546df2987143208845fb3';
    private const BUSINESS_XSLT_SHA256 = 'e1a06d2589ec1fe370a6e01777ce5550c60673ba318e6eba400bea0413176c54';
    private const SCHEMATRON_SHA256 = 'cf17b3d06b3029b1450cda14a3c7c2bf768dcb352a820927168c18b4f4f3304d';

    private readonly string $ctSchemaPath;
    private readonly string $envelopeSchemaPath;
    private readonly string $businessRulesXsltPath;
    private readonly string $schematronSourcePath;
    private readonly IrmarkService $irMarkService;

    public function __construct(
        ?string $ctSchemaPath = null,
        ?string $envelopeSchemaPath = null,
        ?string $businessRulesXsltPath = null,
        ?string $schematronSourcePath = null,
        ?IrmarkService $irMarkService = null,
    ) {
        $runtimeRoot = $this->defaultRuntimeRoot();
        $this->ctSchemaPath = $ctSchemaPath
            ?? $runtimeRoot . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xsd';
        $this->envelopeSchemaPath = $envelopeSchemaPath
            ?? $runtimeRoot . DIRECTORY_SEPARATOR . 'envelope-v2-0-HMRC.xsd';
        $this->businessRulesXsltPath = $businessRulesXsltPath
            ?? $runtimeRoot . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xslt';
        $this->schematronSourcePath = $schematronSourcePath
            ?? $runtimeRoot . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.sch';
        $this->irMarkService = $irMarkService ?? new IrmarkService();
    }

    /** @return array<string, mixed> */
    public function validate(string $govTalkXml): array
    {
        return $this->validateFinalPackage($govTalkXml);
    }

    /**
     * @return array{
     *   ok: bool,
     *   status: string,
     *   validator: string,
     *   rim_version: string,
     *   errors: list<array<string, mixed>>,
     *   warnings: list<array<string, mixed>>,
     *   checks: array<string, array<string, mixed>>,
     *   artifact_hashes: array<string, string>
     * }
     */
    public function validateFinalPackage(string $govTalkXml): array
    {
        $errors = [];
        $warnings = [];
        $checks = [
            'artifacts' => ['status' => 'not_run'],
            'xml' => ['status' => 'not_run'],
            'govtalk_xsd' => ['status' => 'not_run'],
            'ct_xsd' => ['status' => 'not_run'],
            'irmark' => ['status' => 'not_run'],
            'business_rules' => [
                'status' => 'not_run',
                'engine' => 'PHP XSL/libxslt',
                'source' => 'CT-2014-v1-994.xslt',
            ],
        ];

        $artifactResult = $this->validateArtifacts();
        $checks['artifacts'] = [
            'status' => $artifactResult['errors'] === [] ? 'passed' : 'failed',
            'release' => self::VALIDATOR,
        ];
        $errors = array_merge($errors, $artifactResult['errors']);
        if ($artifactResult['errors'] !== []) {
            foreach (['xml', 'govtalk_xsd', 'ct_xsd', 'irmark', 'business_rules'] as $check) {
                $checks[$check]['status'] = 'skipped';
            }

            return $this->result($errors, $warnings, $checks, $artifactResult['hashes']);
        }

        $secrets = $this->extractAuthenticationSecrets($govTalkXml);
        $parse = $this->parseXml($govTalkXml, 'xml', $secrets);
        $errors = array_merge($errors, $parse['errors']);
        $warnings = array_merge($warnings, $parse['warnings']);
        if (!$parse['document'] instanceof \DOMDocument) {
            $checks['xml'] = ['status' => 'failed'];
            foreach (['govtalk_xsd', 'ct_xsd', 'irmark', 'business_rules'] as $check) {
                $checks[$check]['status'] = 'skipped';
            }

            return $this->result($errors, $warnings, $checks, $artifactResult['hashes']);
        }

        $document = $parse['document'];
        $structureErrors = $this->structureErrors($document);
        $errors = array_merge($errors, $structureErrors);
        $checks['xml'] = ['status' => $structureErrors === [] ? 'passed' : 'failed'];

        $envelopeValidation = $this->validateSchema(
            $document,
            $this->envelopeSchemaPath,
            'govtalk_xsd',
            $secrets
        );
        $checks['govtalk_xsd'] = ['status' => $envelopeValidation['valid'] ? 'passed' : 'failed'];
        $errors = array_merge($errors, $envelopeValidation['errors']);
        $warnings = array_merge($warnings, $envelopeValidation['warnings']);

        $ctDocument = $this->extractCtDocument($document);
        if (!$ctDocument instanceof \DOMDocument) {
            $checks['ct_xsd'] = ['status' => 'skipped'];
        } else {
            $ctValidation = $this->validateSchema(
                $ctDocument,
                $this->ctSchemaPath,
                'ct_xsd',
                $secrets
            );
            $checks['ct_xsd'] = ['status' => $ctValidation['valid'] ? 'passed' : 'failed'];
            $errors = array_merge($errors, $ctValidation['errors']);
            $warnings = array_merge($warnings, $ctValidation['warnings']);
        }

        $irMarkValidation = $this->validateIrmark($document, $govTalkXml, $secrets);
        $checks['irmark'] = ['status' => $irMarkValidation['valid'] ? 'passed' : 'failed'];
        $errors = array_merge($errors, $irMarkValidation['errors']);

        $schemaReady = $structureErrors === []
            && $envelopeValidation['valid']
            && isset($ctValidation)
            && $ctValidation['valid'];
        if (!$schemaReady) {
            $checks['business_rules']['status'] = 'skipped';
        } else {
            $businessValidation = $this->validateBusinessRules($document, $secrets);
            $checks['business_rules'] = array_replace(
                $checks['business_rules'],
                [
                    'status' => $businessValidation['status'],
                    'error_count' => count($businessValidation['errors']),
                    'warning_count' => count($businessValidation['warnings']),
                ]
            );
            $errors = array_merge($errors, $businessValidation['errors']);
            $warnings = array_merge($warnings, $businessValidation['warnings']);
        }

        return $this->result($errors, $warnings, $checks, $artifactResult['hashes']);
    }

    /** @return array{errors: list<array<string, mixed>>, hashes: array<string, string>} */
    private function validateArtifacts(): array
    {
        $files = [
            'CT XSD' => [$this->ctSchemaPath, self::CT_XSD_SHA256],
            'GovTalk XSD' => [$this->envelopeSchemaPath, self::ENVELOPE_XSD_SHA256],
            'compiled Schematron XSLT' => [$this->businessRulesXsltPath, self::BUSINESS_XSLT_SHA256],
            'Schematron source' => [$this->schematronSourcePath, self::SCHEMATRON_SHA256],
        ];
        $errors = [];
        $hashes = [];

        foreach ($files as $label => [$path, $expectedHash]) {
            if (!is_file($path) || !is_readable($path)) {
                $errors[] = $this->issue(
                    'artifacts',
                    'HMRC_ARTIFACT_MISSING',
                    'The pinned HMRC V1.994 ' . $label . ' is missing or unreadable.'
                );
                continue;
            }
            $actualHash = hash_file('sha256', $path);
            if (!is_string($actualHash) || $actualHash === '') {
                $errors[] = $this->issue(
                    'artifacts',
                    'HMRC_ARTIFACT_HASH_FAILED',
                    'The pinned HMRC V1.994 ' . $label . ' could not be fingerprinted.'
                );
                continue;
            }
            $actualHash = strtolower($actualHash);
            $hashes[$this->artifactKey($label)] = $actualHash;
            if (!hash_equals($expectedHash, $actualHash)) {
                $errors[] = $this->issue(
                    'artifacts',
                    'HMRC_ARTIFACT_HASH_MISMATCH',
                    'The pinned HMRC V1.994 ' . $label . ' does not match the approved release.'
                );
            }
        }

        return ['errors' => $errors, 'hashes' => $hashes];
    }

    /** @return list<array<string, mixed>> */
    private function structureErrors(\DOMDocument $document): array
    {
        $root = $document->documentElement;
        if (
            !$root instanceof \DOMElement
            || $root->localName !== 'GovTalkMessage'
            || $root->namespaceURI !== GovTalkEnvelopeBuilder::ENVELOPE_NAMESPACE
        ) {
            return [$this->issue(
                'xml',
                'GOVTALK_ROOT_INVALID',
                'The final package document element must be a GovTalk 2.0 GovTalkMessage.'
            )];
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('hd', GovTalkEnvelopeBuilder::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ct', Ct600XmlBuilder::CT_NAMESPACE);
        $bodyElements = $xpath->query('/hd:GovTalkMessage/hd:Body/*');
        $ctEnvelopes = $xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope');
        if (
            $bodyElements === false
            || $ctEnvelopes === false
            || $bodyElements->length !== 1
            || $ctEnvelopes->length !== 1
        ) {
            return [$this->issue(
                'xml',
                'CT_BODY_INVALID',
                'The final GovTalk Body must contain exactly one CT/5 IRenvelope.'
            )];
        }

        return [];
    }

    private function extractCtDocument(\DOMDocument $document): ?\DOMDocument
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('hd', GovTalkEnvelopeBuilder::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ct', Ct600XmlBuilder::CT_NAMESPACE);
        $nodes = $xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope');
        if ($nodes === false || $nodes->length !== 1 || !$nodes->item(0) instanceof \DOMElement) {
            return null;
        }

        $ctDocument = new \DOMDocument('1.0', 'UTF-8');
        $ctDocument->preserveWhiteSpace = true;
        $ctDocument->appendChild($ctDocument->importNode($nodes->item(0), true));

        return $ctDocument;
    }

    /** @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    private function validateSchema(
        \DOMDocument $document,
        string $schemaPath,
        string $stage,
        array $secrets,
    ): array {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $valid = $document->schemaValidate($schemaPath);
            $libxmlErrors = libxml_get_errors();
        } catch (\Throwable $exception) {
            $valid = false;
            $libxmlErrors = libxml_get_errors();
            $libxmlErrors[] = $exception;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $errors = [];
        $warnings = [];
        foreach ($libxmlErrors as $libxmlError) {
            if ($libxmlError instanceof \LibXMLError) {
                $severity = $libxmlError->level === \LIBXML_ERR_WARNING ? 'warning' : 'error';
                $issue = $this->issue(
                    $stage,
                    'XSD-' . (string)$libxmlError->code,
                    $this->sanitize((string)$libxmlError->message, $secrets),
                    '',
                    (int)$libxmlError->line,
                    (int)$libxmlError->column,
                    $severity
                );
            } else {
                $severity = 'error';
                $issue = $this->issue(
                    $stage,
                    'XSD_RUNTIME_ERROR',
                    $this->sanitize($libxmlError->getMessage(), $secrets)
                );
            }
            if ($severity === 'warning') {
                $warnings[] = $issue;
            } else {
                $errors[] = $issue;
            }
        }
        if (!$valid && $errors === []) {
            $errors[] = $this->issue(
                $stage,
                'XSD_VALIDATION_FAILED',
                $stage === 'ct_xsd'
                    ? 'The CT/5 return did not validate against the pinned V1.994 RIM XSD.'
                    : 'The request did not validate against the pinned GovTalk 2.0 HMRC XSD.'
            );
        }

        return ['valid' => $valid && $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @return array{valid: bool, errors: list<array<string, mixed>>} */
    private function validateIrmark(\DOMDocument $document, string $govTalkXml, array $secrets): array
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('hd', GovTalkEnvelopeBuilder::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ct', Ct600XmlBuilder::CT_NAMESPACE);
        $nodes = $xpath->query(
            '/hd:GovTalkMessage/hd:Body/ct:IRenvelope/ct:IRheader/ct:IRmark[@Type="generic"]'
        );
        if ($nodes === false || $nodes->length !== 1) {
            return ['valid' => false, 'errors' => [$this->issue(
                'irmark',
                'IRMARK_MISSING',
                'The final CT600 package must contain exactly one generic IRmark.'
            )]];
        }
        $stored = trim((string)$nodes->item(0)?->textContent);
        if ($stored === '') {
            return ['valid' => false, 'errors' => [$this->issue(
                'irmark',
                'IRMARK_EMPTY',
                'The generic IRmark has not been applied to the final GovTalk package.'
            )]];
        }

        try {
            $calculated = $this->irMarkService->calculateFromGovTalkXml($govTalkXml);
            $expected = trim((string)($calculated['irmark'] ?? ''));
        } catch (\Throwable $exception) {
            return ['valid' => false, 'errors' => [$this->issue(
                'irmark',
                'IRMARK_VALIDATION_ERROR',
                $this->sanitize($exception->getMessage(), $secrets)
            )]];
        }
        if ($expected === '' || !hash_equals($expected, $stored)) {
            return ['valid' => false, 'errors' => [$this->issue(
                'irmark',
                'IRMARK_MISMATCH',
                'The stored generic IRmark does not match the final GovTalk Body.'
            )]];
        }

        return ['valid' => true, 'errors' => []];
    }

    /** @return array{status: string, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    private function validateBusinessRules(\DOMDocument $document, array $secrets): array
    {
        if (!class_exists(\XSLTProcessor::class)) {
            return [
                'status' => 'unavailable',
                'errors' => [$this->issue(
                    'business_rules',
                    'XSL_EXTENSION_MISSING',
                    'PHP ext-xsl is required to run the pinned HMRC V1.994 business rules.'
                )],
                'warnings' => [],
            ];
        }
        if (
            !method_exists(\XSLTProcessor::class, 'setSecurityPrefs')
            || !defined('XSL_SECPREF_WRITE_FILE')
            || !defined('XSL_SECPREF_CREATE_DIRECTORY')
            || !defined('XSL_SECPREF_READ_NETWORK')
            || !defined('XSL_SECPREF_WRITE_NETWORK')
        ) {
            return [
                'status' => 'unavailable',
                'errors' => [$this->issue(
                    'business_rules',
                    'XSL_SECURITY_UNAVAILABLE',
                    'The installed PHP XSL runtime cannot safely execute the HMRC business-rule stylesheet.'
                )],
                'warnings' => [],
            ];
        }

        $stylesheetParse = $this->parseXmlFile($this->businessRulesXsltPath, 'business_rules', $secrets);
        if (!$stylesheetParse['document'] instanceof \DOMDocument) {
            return [
                'status' => 'error',
                'errors' => $stylesheetParse['errors'],
                'warnings' => $stylesheetParse['warnings'],
            ];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $processor = new \XSLTProcessor();
            $processor->setSecurityPrefs(
                \XSL_SECPREF_WRITE_FILE
                | \XSL_SECPREF_CREATE_DIRECTORY
                | \XSL_SECPREF_READ_NETWORK
                | \XSL_SECPREF_WRITE_NETWORK
            );
            $imported = $processor->importStylesheet($stylesheetParse['document']);
            $output = $imported ? $processor->transformToXml($document) : false;
            $libxmlErrors = libxml_get_errors();
        } catch (\Throwable $exception) {
            $output = false;
            $libxmlErrors = libxml_get_errors();
            $libxmlErrors[] = $exception;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!is_string($output) || trim($output) === '') {
            $errors = [];
            foreach ($libxmlErrors as $libxmlError) {
                $message = $libxmlError instanceof \LibXMLError
                    ? (string)$libxmlError->message
                    : $libxmlError->getMessage();
                $errors[] = $this->issue(
                    'business_rules',
                    'XSLT_RUNTIME_ERROR',
                    $this->sanitize($message, $secrets)
                );
            }
            if ($errors === []) {
                $errors[] = $this->issue(
                    'business_rules',
                    'XSLT_RUNTIME_ERROR',
                    'The pinned HMRC V1.994 business-rule transform did not produce a result.'
                );
            }

            return ['status' => 'error', 'errors' => $this->uniqueIssues($errors), 'warnings' => []];
        }

        $resultParse = $this->parseXml($output, 'business_rules', $secrets);
        if (!$resultParse['document'] instanceof \DOMDocument) {
            return [
                'status' => 'error',
                'errors' => $resultParse['errors'],
                'warnings' => $resultParse['warnings'],
            ];
        }

        $ruleErrors = $this->parseBusinessErrors($resultParse['document'], $secrets);

        return [
            'status' => $ruleErrors === [] ? 'passed' : 'failed',
            'errors' => $ruleErrors,
            'warnings' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function parseBusinessErrors(\DOMDocument $document, array $secrets): array
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('err', 'http://www.govtalk.gov.uk/CM/errorresponse');
        $nodes = $xpath->query('/err:ErrorResponse/err:Error');
        if ($nodes === false) {
            return [$this->issue(
                'business_rules',
                'XSLT_RESULT_INVALID',
                'The HMRC business-rule result could not be read.'
            )];
        }
        $ruleMap = $this->schematronRuleMap();
        $errors = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $number = $this->childText($node, 'Number');
            $text = $this->sanitize($this->childText($node, 'Text'), $secrets);
            $location = $this->sanitize($this->childText($node, 'Location'), $secrets);
            $type = $this->sanitize($this->childText($node, 'Type'), $secrets);
            $matches = (array)($ruleMap[$number] ?? []);
            if (count($matches) > 1 && $text !== '') {
                $messageMatches = array_values(array_filter(
                    $matches,
                    fn(array $rule): bool => $this->normaliseMessage((string)($rule['message'] ?? ''))
                        === $this->normaliseMessage($text)
                ));
                if ($messageMatches !== []) {
                    $matches = $messageMatches;
                }
            }
            $ruleCodes = array_values(array_unique(array_filter(array_map(
                static fn(array $rule): string => (string)($rule['rule_code'] ?? ''),
                $matches
            ))));
            $assertionIds = array_values(array_unique(array_filter(array_map(
                static fn(array $rule): string => (string)($rule['assertion_id'] ?? ''),
                $matches
            ))));
            $ruleCode = count($ruleCodes) === 1
                ? $ruleCodes[0]
                : ($number !== '' ? 'HMRC-' . $number : 'HMRC_BUSINESS_RULE');
            $errors[] = $this->issue(
                'business_rules',
                $ruleCode,
                $text !== '' ? $text : 'The HMRC V1.994 business rules rejected the return.',
                $location,
                0,
                0,
                'error',
                [
                    'number' => $number,
                    'type' => $type,
                    'rule_codes' => $ruleCodes,
                    'assertion_ids' => $assertionIds,
                ]
            );
        }

        return $this->uniqueIssues($errors);
    }

    /** @return array<string, list<array{rule_code: string, assertion_id: string, message: string}>> */
    private function schematronRuleMap(): array
    {
        $parse = $this->parseXmlFile($this->schematronSourcePath, 'artifacts', []);
        if (!$parse['document'] instanceof \DOMDocument) {
            return [];
        }
        $xpath = new \DOMXPath($parse['document']);
        $xpath->registerNamespace('sch', 'http://purl.oclc.org/dsdl/schematron');
        $diagnostics = [];
        $diagnosticNodes = $xpath->query('//sch:diagnostic[starts-with(@id, "errorCode.")]');
        if ($diagnosticNodes !== false) {
            foreach ($diagnosticNodes as $diagnostic) {
                if (!$diagnostic instanceof \DOMElement) {
                    continue;
                }
                $code = substr($diagnostic->getAttribute('id'), strlen('errorCode.'));
                $diagnostics[$code] = trim($diagnostic->textContent);
            }
        }
        $messages = [];
        $messageNodes = $xpath->query('//sch:diagnostic[starts-with(@id, "transactional.") and not(@xml:lang)]');
        if ($messageNodes !== false) {
            foreach ($messageNodes as $diagnostic) {
                if (!$diagnostic instanceof \DOMElement) {
                    continue;
                }
                $code = substr($diagnostic->getAttribute('id'), strlen('transactional.'));
                $messages[$code] = trim($diagnostic->textContent);
            }
        }

        $map = [];
        $assertions = $xpath->query('//sch:assert');
        if ($assertions !== false) {
            foreach ($assertions as $assertion) {
                if (!$assertion instanceof \DOMElement) {
                    continue;
                }
                if (!preg_match('/(?:^|\s)errorCode\.([^\s]+)/', $assertion->getAttribute('diagnostics'), $match)) {
                    continue;
                }
                $ruleCode = (string)$match[1];
                $number = (string)($diagnostics[$ruleCode] ?? '');
                if ($number === '') {
                    continue;
                }
                $map[$number][] = [
                    'rule_code' => $ruleCode,
                    'assertion_id' => $assertion->getAttribute('id'),
                    'message' => (string)($messages[$ruleCode] ?? trim($assertion->textContent)),
                ];
            }
        }

        return $map;
    }

    /** @return array{document: ?\DOMDocument, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    private function parseXml(string $xml, string $stage, array $secrets): array
    {
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET | \LIBXML_COMPACT);
            $libxmlErrors = libxml_get_errors();
        } catch (\Throwable $exception) {
            $loaded = false;
            $libxmlErrors = libxml_get_errors();
            $libxmlErrors[] = $exception;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        [$errors, $warnings] = $this->libxmlIssues($libxmlErrors, $stage, 'XML_PARSE', $secrets);
        if (!$loaded && $errors === []) {
            $errors[] = $this->issue($stage, 'XML_PARSE_FAILED', 'The XML document is not well formed.');
        }

        return [
            'document' => $loaded ? $document : null,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @return array{document: ?\DOMDocument, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    private function parseXmlFile(string $path, string $stage, array $secrets): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return [
                'document' => null,
                'errors' => [$this->issue(
                    $stage,
                    'XML_ARTIFACT_READ_FAILED',
                    'A pinned HMRC V1.994 validation artifact could not be read.'
                )],
                'warnings' => [],
            ];
        }

        return $this->parseXml($contents, $stage, $secrets);
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function libxmlIssues(array $libxmlErrors, string $stage, string $prefix, array $secrets): array
    {
        $errors = [];
        $warnings = [];
        foreach ($libxmlErrors as $libxmlError) {
            $isWarning = $libxmlError instanceof \LibXMLError
                && $libxmlError->level === \LIBXML_ERR_WARNING;
            $message = $libxmlError instanceof \LibXMLError
                ? (string)$libxmlError->message
                : $libxmlError->getMessage();
            $issue = $this->issue(
                $stage,
                $prefix . ($libxmlError instanceof \LibXMLError ? '-' . $libxmlError->code : '_ERROR'),
                $this->sanitize($message, $secrets),
                '',
                $libxmlError instanceof \LibXMLError ? (int)$libxmlError->line : 0,
                $libxmlError instanceof \LibXMLError ? (int)$libxmlError->column : 0,
                $isWarning ? 'warning' : 'error'
            );
            if ($isWarning) {
                $warnings[] = $issue;
            } else {
                $errors[] = $issue;
            }
        }

        return [$this->uniqueIssues($errors), $this->uniqueIssues($warnings)];
    }

    /** @return array<string, mixed> */
    private function result(array $errors, array $warnings, array $checks, array $hashes): array
    {
        $errors = $this->uniqueIssues($errors);
        $warnings = $this->uniqueIssues($warnings);

        return [
            'ok' => $errors === [],
            'status' => $errors === [] ? 'passed' : 'failed',
            'validator' => self::VALIDATOR,
            'rim_version' => self::RIM_VERSION,
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
            'artifact_hashes' => $hashes,
        ];
    }

    /** @return array<string, mixed> */
    private function issue(
        string $stage,
        string $code,
        string $text,
        string $location = '',
        int $line = 0,
        int $column = 0,
        string $severity = 'error',
        array $extra = [],
    ): array {
        return array_merge([
            'stage' => $stage,
            'severity' => $severity,
            'code' => $code,
            'rule_code' => $code,
            'text' => $text,
            'location' => $location,
            'line' => max(0, $line),
            'column' => max(0, $column),
        ], $extra);
    }

    /** @return list<array<string, mixed>> */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $key = json_encode($issue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($key)) {
                $unique[$key] = $issue;
            }
        }

        return array_values($unique);
    }

    /** @return list<string> */
    private function extractAuthenticationSecrets(string $xml): array
    {
        $secrets = [];
        if (preg_match_all(
            '/<(?:[A-Za-z_][\w.-]*:)?IDAuthentication\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.-]*:)?IDAuthentication\s*>/is',
            $xml,
            $blocks
        )) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all(
                    '/<(?:[A-Za-z_][\w.-]*:)?(?:SenderID|Value)\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.-]*:)?(?:SenderID|Value)\s*>/is',
                    (string)$block,
                    $values
                )) {
                    foreach ($values[1] as $value) {
                        $value = trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if ($value !== '') {
                            $secrets[] = $value;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    private function sanitize(string $message, array $secrets): string
    {
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $message) ?? '';
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }
        $projectRoot = defined('PROJECT_ROOT') ? (string)PROJECT_ROOT : dirname(__DIR__, 5);
        foreach ([$projectRoot, dirname($this->ctSchemaPath)] as $path) {
            $path = rtrim((string)$path, '\\/');
            if ($path !== '') {
                $message = str_ireplace([$path, str_replace('\\', '/', $path)], '[LOCAL_PATH]', $message);
            }
        }
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? '';
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 1997) . '...';
        }

        return $message !== '' ? $message : 'Validation failed without a diagnostic message.';
    }

    private function childText(\DOMElement $parent, string $localName): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    private function normaliseMessage(string $message): string
    {
        return strtolower(preg_replace('/\s+/u', ' ', trim($message)) ?? '');
    }

    private function artifactKey(string $label): string
    {
        return match ($label) {
            'CT XSD' => 'ct_xsd',
            'GovTalk XSD' => 'govtalk_xsd',
            'compiled Schematron XSLT' => 'business_xslt',
            default => 'schematron_source',
        };
    }

    private function defaultRuntimeRoot(): string
    {
        $projectRoot = defined('PROJECT_ROOT')
            ? (string)PROJECT_ROOT
            : dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

        return rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'third_party'
            . DIRECTORY_SEPARATOR . 'hmrc_ct600' . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . self::VALIDATOR;
    }
}
