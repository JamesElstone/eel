<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Scripts\Ixbrl;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Artifact-only pre-submission inspection for revised FRS 105 accounts.
 *
 * Taxonomy semantics are delegated to Arelle. Companies House-specific
 * acceptance is deliberately a separate mandatory layer whose result must be
 * supplied from the official TEST or LIVE XML Gateway. The local checks must
 * never be described as a substitute for either external validator.
 */
final class RevisedAccountsPreSubmissionValidator
{
    public const REPORT_VERSION = 'revised-accounts-pre-submission-v1';

    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const XBRLI_NS = 'http://www.xbrl.org/2003/instance';
    private const XBRLDI_NS = 'http://xbrl.org/2006/xbrldi';
    private const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const COMPANIES_HOUSE_SCHEME = 'http://www.companieshouse.gov.uk/';
    private const SUPERSEDED_DIMENSION = 'bus:OriginalRevisedDataDimension';
    private const SUPERSEDED_MEMBER = 'bus:Superseded';
    private const MONEY_TOLERANCE = 0.005;
    /**
     * The FRS 105 Format 2 statement rows are emitted even when their value is
     * zero. Their presence is part of the selected presentation profile, so a
     * missing zero-valued fact must not be silently treated as numeric zero.
     *
     * @var list<string>
     */
    private const REQUIRED_CURRENT_NUMERIC_FACTS = [
        'core:TurnoverRevenue',
        'core:OtherOperatingIncomeFormat2',
        'core:RawMaterialsConsumablesUsed',
        'core:GrossProfitLoss',
        'core:StaffCostsEmployeeBenefitsExpense',
        'core:DepreciationAmortisationImpairmentExpense',
        'core:OtherExternalCharges',
        'core:OperatingProfitLoss',
        'core:TaxTaxCreditOnProfitOrLossOnOrdinaryActivities',
        'core:ProfitLoss',
        'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset',
        'core:FixedAssets',
        'core:CurrentAssets',
        'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
        'core:Creditors',
        'core:NetCurrentAssetsLiabilities',
        'core:TotalAssetsLessCurrentLiabilities',
        'core:ProvisionsForLiabilitiesBalanceSheetSubtotal',
        'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal',
        'core:NetAssetsLiabilities',
        'core:Equity',
    ];
    private const MAX_COMPANIES_HOUSE_RESPONSE_BYTES = 10_000_000;

    /**
     * Taxonomy marker facts have a zero-length fixed item type. Their meaning
     * is supplied by a dimension/member or, for EntityTradingStatus, by the
     * taxonomy's default member. Arelle remains authoritative for the DTS.
     *
     * @var array<string, string>
     */
    private const EMPTY_MARKER_STRATEGIES = [
        'bus:CountryFormationOrIncorporation' => 'dimensional_marker',
        'bus:LegalFormEntity' => 'dimensional_marker',
        'bus:EntityTradingStatus' => 'taxonomy_default_marker',
        'bus:AccountingStandardsApplied' => 'dimensional_marker',
        'bus:AccountsStatusAuditedOrUnaudited' => 'dimensional_marker',
        'bus:AccountsType' => 'dimensional_marker',
        'core:DirectorSigningFinancialStatements' => 'dimensional_marker',
    ];
    /**
     * Revised-report facts are injected after the ordinary profile fact build,
     * so they are not rows in ixbrl_fact_mappings. These verified 2026
     * taxonomy semantics keep the artifact report diagnostic-complete; Arelle
     * remains authoritative for the DTS declaration.
     *
     * @var array<string, array<string, string>>
     */
    private const REVISED_FACT_METADATA = [
        'bus:ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse' => [
            'value_type' => 'boolean',
            'period_type' => 'duration',
            'source_key' => 'revision.is_revised_report',
            'label' => 'Report is an amended or revised version',
        ],
        'bus:StatementThatRevisedReportReplacesPreviouslyFiledReportForPeriod' => [
            'value_type' => 'text',
            'period_type' => 'duration',
            'source_key' => 'revision.replacement_statement',
            'label' => 'Revised report replaces the previously filed report',
        ],
        'bus:StatementThatThisReportNowStatutoryAccountsForPeriod' => [
            'value_type' => 'text',
            'period_type' => 'duration',
            'source_key' => 'revision.statutory_accounts_statement',
            'label' => 'Revised report is now the statutory accounts',
        ],
        'bus:StatementThatThisReportHasBeenPreparedAsDatePreviouslyFiledReport' => [
            'value_type' => 'text',
            'period_type' => 'duration',
            'source_key' => 'revision.original_date_basis_statement',
            'label' => 'Original annual-accounts date basis',
        ],
        'bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006' => [
            'value_type' => 'text',
            'period_type' => 'duration',
            'source_key' => 'revision.non_compliance_explanation',
            'label' => 'Original non-compliance',
        ],
        'bus:StatementSignificantAmendmentsToPreviouslyFiledReport' => [
            'value_type' => 'text',
            'period_type' => 'duration',
            'source_key' => 'revision.significant_amendments',
            'label' => 'Significant amendments',
        ],
        'bus:DateApprovalRevisionReport' => [
            'value_type' => 'date',
            'period_type' => 'duration',
            'source_key' => 'revision.approval_date',
            'label' => 'Date revision approved',
        ],
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $mappingsByConcept = [];

    /** @param array<int, array<string, mixed>> $taxonomyMappings */
    public function __construct(array $taxonomyMappings = [])
    {
        foreach ($taxonomyMappings as $mapping) {
            $concept = trim((string)($mapping['taxonomy_concept'] ?? ''));
            if ($concept !== '') {
                $this->mappingsByConcept[$concept][] = $mapping;
            }
        }
    }

    /**
     * @param array<string, mixed>|null $taxonomyResult Raw Arelle result.
     * @param array<string, mixed>|null $companiesHouseResult Captured official
     *        Companies House validator/gateway result.
     * @return array<string, mixed>
     */
    public function validate(
        string $path,
        ?array $taxonomyResult = null,
        ?array $companiesHouseResult = null
    ): array {
        $resolvedPath = $this->resolveReadablePath($path);
        $source = file_get_contents($resolvedPath);
        if (!is_string($source) || $source === '') {
            throw new RuntimeException('The revised-accounts XHTML artifact is empty.');
        }

        $artifactSha256 = hash('sha256', $source);
        [$document, $xmlLayer] = $this->loadDocument($source);

        $base = [
            'report_version' => self::REPORT_VERSION,
            'generated_at_utc' => gmdate('c'),
            'artifact' => [
                'filename' => basename($resolvedPath),
                'path' => $resolvedPath,
                'sha256' => $artifactSha256,
                'bytes' => strlen($source),
            ],
        ];

        if (!$document instanceof DOMDocument) {
            $notRun = $this->layer(
                'FAIL',
                ['This layer could not run because the artifact is not well-formed XML.']
            );
            $layers = [
                'xml' => $xmlLayer,
                'xhtml_inline_xbrl' => $notRun,
                'taxonomy' => $this->normaliseTaxonomyLayer(
                    $taxonomyResult,
                    $artifactSha256
                ),
                'context_units' => $notRun,
                'arithmetic' => $notRun,
                'duplicates' => $notRun,
                'visible_tagged_reconciliation' => $notRun,
                'companies_house' => $this->normaliseCompaniesHouseLayer(
                    $companiesHouseResult,
                    $artifactSha256
                ),
            ];

            return $base + [
                'layers' => $layers,
                'fact_context_unit_validation' => [
                    'facts' => [],
                    'contexts' => [],
                    'units' => [],
                    'errors' => $xmlLayer['errors'],
                    'warnings' => [],
                ],
                'visible_tagged_reconciliation' => [
                    'rows' => [],
                    'untagged_visible_numbers' => [],
                    'hidden_numeric_facts' => [],
                    'errors' => [],
                    'warnings' => [],
                ],
                'overall_status' => 'FAIL',
                'filing_ready' => false,
            ];
        }

        $xpath = $this->xpath($document);
        $contexts = $this->contexts($xpath);
        $units = $this->units($xpath);
        $facts = $this->facts($xpath, $contexts, $units);

        $xhtmlLayer = $this->validateXhtml($document, $xpath, $source);
        $contextUnitResult = $this->validateContextsUnitsAndFacts($contexts, $units, $facts);
        $arithmeticResult = $this->validateArithmetic($facts, $contexts);
        $duplicateResult = $this->validateDuplicates($facts, $contexts);
        $reconciliation = $this->visibleTaggedReconciliation($xpath, $facts, $contexts);

        $layers = [
            'xml' => $xmlLayer,
            'xhtml_inline_xbrl' => $xhtmlLayer,
            'taxonomy' => $this->normaliseTaxonomyLayer(
                $taxonomyResult,
                $artifactSha256
            ),
            'context_units' => $this->layerFromResult($contextUnitResult),
            'arithmetic' => $this->layerFromResult($arithmeticResult),
            'duplicates' => $this->layerFromResult($duplicateResult),
            'visible_tagged_reconciliation' => $this->layerFromResult($reconciliation),
            'companies_house' => $this->normaliseCompaniesHouseLayer(
                $companiesHouseResult,
                $artifactSha256,
                $this->artifactCompanyNumber($contexts)
            ),
        ];
        $overallStatus = $this->overallStatus($layers);

        return $base + [
            'layers' => $layers,
            'fact_context_unit_validation' => [
                'facts' => array_values($facts),
                'contexts' => array_values($contexts),
                'units' => array_values($units),
                'errors' => $contextUnitResult['errors'],
                'warnings' => $contextUnitResult['warnings'],
            ],
            'arithmetic_validation' => $arithmeticResult,
            'duplicate_fact_validation' => $duplicateResult,
            'visible_tagged_reconciliation' => $reconciliation,
            'overall_status' => $overallStatus,
            'filing_ready' => $overallStatus !== 'FAIL',
        ];
    }

    /** @return array{0: DOMDocument|null, 1: array<string, mixed>} */
    private function loadDocument(string $source): array
    {
        $errors = [];
        $warnings = [];
        if (!str_starts_with(
            $source,
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
        )) {
            $errors[] = 'The deterministic UTF-8 XML declaration is missing or differs from the filing profile.';
        }
        if (preg_match('/<!DOCTYPE\b/i', $source) === 1) {
            $errors[] = 'A DOCTYPE is prohibited in the generated filing artifact.';
        }

        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($source, LIBXML_NONET | LIBXML_COMPACT);
            $parseErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            foreach ($parseErrors as $parseError) {
                $errors[] = sprintf(
                    'XML line %d, column %d: %s',
                    (int)$parseError->line,
                    (int)$parseError->column,
                    trim((string)$parseError->message)
                );
            }
            if ($errors === []) {
                $errors[] = 'The artifact is not well-formed XML.';
            }
            return [null, $this->layer('FAIL', $errors, $warnings, [
                'network_resolution' => 'disabled_with_LIBXML_NONET',
            ])];
        }

        return [$document, $this->layer(
            $errors === [] ? 'PASS' : 'FAIL',
            $errors,
            $warnings,
            ['network_resolution' => 'disabled_with_LIBXML_NONET']
        )];
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xhtml', self::XHTML_NS);
        $xpath->registerNamespace('ix', self::IX_NS);
        $xpath->registerNamespace('xbrli', self::XBRLI_NS);
        $xpath->registerNamespace('xbrldi', self::XBRLDI_NS);
        $xpath->registerNamespace('link', self::LINK_NS);
        return $xpath;
    }

    /** @return array<string, array<string, mixed>> */
    private function contexts(DOMXPath $xpath): array
    {
        $contexts = [];
        foreach ($this->query($xpath, '//xbrli:context') as $contextNode) {
            if (!$contextNode instanceof DOMElement) {
                continue;
            }
            $id = trim($contextNode->getAttribute('id'));
            $identifier = $this->firstElement($xpath, './xbrli:entity/xbrli:identifier', $contextNode);
            $instant = $this->firstText($xpath, './xbrli:period/xbrli:instant', $contextNode);
            $start = $this->firstText($xpath, './xbrli:period/xbrli:startDate', $contextNode);
            $end = $this->firstText($xpath, './xbrli:period/xbrli:endDate', $contextNode);
            $forever = $this->query($xpath, './xbrli:period/xbrli:forever', $contextNode)->length > 0;
            $dimensions = [];
            foreach ($this->query(
                $xpath,
                './/xbrldi:explicitMember | .//xbrldi:typedMember',
                $contextNode
            ) as $memberNode) {
                if (!$memberNode instanceof DOMElement) {
                    continue;
                }
                $dimensions[] = [
                    'kind' => $memberNode->localName === 'typedMember' ? 'typed' : 'explicit',
                    'dimension' => trim($memberNode->getAttribute('dimension')),
                    'member' => $memberNode->localName === 'typedMember'
                        ? $this->normaliseText($memberNode->textContent)
                        : trim($this->normaliseText($memberNode->textContent)),
                    'line' => $memberNode->getLineNo(),
                ];
            }
            usort($dimensions, static fn(array $left, array $right): int => strcmp(
                (string)$left['dimension'] . '|' . (string)$left['member'],
                (string)$right['dimension'] . '|' . (string)$right['member']
            ));

            $period = $instant !== ''
                ? ['type' => 'instant', 'instant' => $instant, 'start' => null, 'end' => null]
                : ($forever
                    ? ['type' => 'forever', 'instant' => null, 'start' => null, 'end' => null]
                    : ['type' => 'duration', 'instant' => null, 'start' => $start, 'end' => $end]);

            $context = [
                'id' => $id,
                'entity_identifier' => $identifier?->textContent !== null
                    ? trim($identifier->textContent)
                    : '',
                'entity_scheme' => $identifier?->getAttribute('scheme') ?? '',
                'period' => $period,
                'dimensions' => $dimensions,
                'facts' => [],
                'line' => $contextNode->getLineNo(),
                'semantic_signature' => '',
            ];
            $context['semantic_signature'] = $this->contextSignature($context);
            $contexts[$id . '#' . count($contexts)] = $context;
        }

        return $contexts;
    }

    /** @return array<string, array<string, mixed>> */
    private function units(DOMXPath $xpath): array
    {
        $units = [];
        foreach ($this->query($xpath, '//xbrli:unit') as $unitNode) {
            if (!$unitNode instanceof DOMElement) {
                continue;
            }
            $id = trim($unitNode->getAttribute('id'));
            $measures = [];
            foreach ($this->query($xpath, './/xbrli:measure', $unitNode) as $measure) {
                $measures[] = $this->normaliseText($measure->textContent);
            }
            $units[$id . '#' . count($units)] = [
                'id' => $id,
                'measures' => $measures,
                'line' => $unitNode->getLineNo(),
                'facts' => [],
            ];
        }
        return $units;
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @param array<string, array<string, mixed>> $units
     * @return array<int, array<string, mixed>>
     */
    private function facts(DOMXPath $xpath, array &$contexts, array &$units): array
    {
        $contextIndex = $this->uniqueIndex($contexts, 'id');
        $unitIndex = $this->uniqueIndex($units, 'id');
        $facts = [];
        foreach ($this->query(
            $xpath,
            '//ix:nonNumeric | //ix:nonFraction | //ix:fraction'
        ) as $factNode) {
            if (!$factNode instanceof DOMElement) {
                continue;
            }
            $name = trim($factNode->getAttribute('name'));
            $contextRef = trim($factNode->getAttribute('contextRef'));
            $context = isset($contextIndex[$contextRef])
                ? $contexts[$contextIndex[$contextRef]]
                : null;
            $mapping = $this->mappingForContext($name, $context);
            $revisionMetadata = self::REVISED_FACT_METADATA[$name] ?? [];
            $isNumeric = in_array($factNode->localName, ['nonFraction', 'fraction'], true);
            $rawValue = $this->normaliseText($factNode->textContent);
            $machineValue = $isNumeric
                ? $this->numericMachineValue($factNode, $rawValue)
                : $this->nonNumericMachineValue($factNode, $rawValue);
            $hidden = $this->hasAncestor($factNode, self::IX_NS, 'hidden');
            $sourceKey = trim((string)(
                $mapping['source_key']
                    ?? $revisionMetadata['source_key']
                    ?? ''
            ));
            $factKey = trim((string)($mapping['fact_key'] ?? ''));
            $expectedDimensions = json_decode(
                (string)($mapping['dimensions_json'] ?? ''),
                true
            );
            $fact = [
                'index' => count($facts) + 1,
                'element' => 'ix:' . $factNode->localName,
                'name' => $name,
                'namespace_uri' => $this->qnameNamespace($factNode, $name),
                'qname_resolved' => $this->qnameNamespace($factNode, $name) !== null,
                'context_ref' => $contextRef,
                'unit_ref' => trim($factNode->getAttribute('unitRef')),
                'decimals' => $factNode->hasAttribute('decimals')
                    ? $factNode->getAttribute('decimals')
                    : null,
                'precision' => $factNode->hasAttribute('precision')
                    ? $factNode->getAttribute('precision')
                    : null,
                'format' => trim($factNode->getAttribute('format')),
                'sign' => trim($factNode->getAttribute('sign')),
                'scale' => trim($factNode->getAttribute('scale')),
                'nil' => strtolower(trim($factNode->getAttributeNS(
                    'http://www.w3.org/2001/XMLSchema-instance',
                    'nil'
                ))),
                'raw_value' => $rawValue,
                'machine_value' => $machineValue,
                'is_numeric' => $isNumeric,
                'hidden' => $hidden,
                'empty' => $rawValue === '',
                'empty_marker_strategy' => self::EMPTY_MARKER_STRATEGIES[$name] ?? null,
                'original_revised_status' => is_array($context)
                    && $this->contextHasMember(
                        $context,
                        self::SUPERSEDED_DIMENSION,
                        self::SUPERSEDED_MEMBER
                    )
                        ? 'superseded_original'
                        : 'current_revised',
                'expected_value_type' => $mapping['value_type']
                    ?? $revisionMetadata['value_type']
                    ?? null,
                'expected_period_type' => $mapping['period_type']
                    ?? $revisionMetadata['period_type']
                    ?? null,
                'expected_unit_ref' => $mapping['unit_ref'] ?? null,
                'expected_dimensions' => is_array($expectedDimensions)
                    ? $expectedDimensions
                    : [],
                'source_application_field' => $sourceKey !== '' ? $sourceKey : null,
                'source_fact_key' => $factKey !== '' ? $factKey : null,
                'taxonomy_label' => trim((string)(
                    $mapping['label']
                        ?? $revisionMetadata['label']
                        ?? ''
                )),
                'line' => $factNode->getLineNo(),
                '_node' => $factNode,
            ];
            $facts[] = $fact;
            if (isset($contextIndex[$contextRef])) {
                $contexts[$contextIndex[$contextRef]]['facts'][] = [
                    'name' => $name,
                    'line' => $factNode->getLineNo(),
                    'hidden' => $hidden,
                ];
            }
            $unitRef = (string)$fact['unit_ref'];
            if ($unitRef !== '' && isset($unitIndex[$unitRef])) {
                $units[$unitIndex[$unitRef]]['facts'][] = [
                    'name' => $name,
                    'line' => $factNode->getLineNo(),
                    'context_ref' => $contextRef,
                ];
            }
        }

        return $facts;
    }

    /** @return array<string, mixed> */
    private function validateXhtml(
        DOMDocument $document,
        DOMXPath $xpath,
        string $source
    ): array {
        $errors = [];
        $warnings = [];
        $root = $document->documentElement;
        if (!$root instanceof DOMElement
            || $root->localName !== 'html'
            || $root->namespaceURI !== self::XHTML_NS) {
            $errors[] = 'The document root must be an XHTML html element.';
        } elseif (strtolower($root->getAttributeNS(self::XML_NS, 'lang')) !== 'en') {
            $errors[] = 'The XHTML root must carry xml:lang="en".';
        }

        $headerCount = $this->query($xpath, '//ix:header')->length;
        $hiddenCount = $this->query($xpath, '//ix:header/ix:hidden')->length;
        $referencesCount = $this->query($xpath, '//ix:header/ix:references')->length;
        $resourcesCount = $this->query($xpath, '//ix:header/ix:resources')->length;
        foreach ([
            'ix:header' => $headerCount,
            'ix:hidden' => $hiddenCount,
            'ix:references' => $referencesCount,
            'ix:resources' => $resourcesCount,
        ] as $component => $count) {
            if ($count !== 1) {
                $errors[] = 'Exactly one ' . $component . ' is required; found ' . $count . '.';
            }
        }

        $schemaRefs = $this->query($xpath, '//ix:references/link:schemaRef');
        if ($schemaRefs->length !== 1) {
            $errors[] = 'Exactly one taxonomy schemaRef is required.';
        }
        $entryPoints = [];
        foreach ($schemaRefs as $schemaRef) {
            if ($schemaRef instanceof DOMElement) {
                $entryPoints[] = trim($schemaRef->getAttributeNS(self::XLINK_NS, 'href'));
            }
        }
        if (count($entryPoints) === 1
            && preg_match(
                '#^https://xbrl\.frc\.org\.uk/FRS-102/\d{4}-\d{2}-\d{2}/FRS-102-\d{4}-\d{2}-\d{2}\.xsd$#',
                $entryPoints[0]
            ) !== 1) {
            $errors[] = 'The taxonomy schemaRef is not a recognised FRC FRS 102 entry point.';
        }

        if ($this->query(
            $xpath,
            '//ix:nonNumeric[ancestor::ix:nonNumeric or ancestor::ix:nonFraction]'
            . ' | //ix:nonFraction[ancestor::ix:nonNumeric or ancestor::ix:nonFraction]'
        )->length > 0) {
            $errors[] = 'Inline facts must not be nested inside other Inline facts.';
        }

        $factIds = [];
        foreach ($this->query(
            $xpath,
            '//ix:nonNumeric[@id] | //ix:nonFraction[@id] | //ix:fraction[@id]'
        ) as $fact) {
            if (!$fact instanceof DOMElement) {
                continue;
            }
            $id = trim($fact->getAttribute('id'));
            if ($id !== '' && isset($factIds[$id])) {
                $errors[] = 'Duplicate Inline fact id: ' . $id . '.';
            }
            $factIds[$id] = true;
        }

        $continuationIds = [];
        foreach ($this->query($xpath, '//ix:continuation') as $continuation) {
            if ($continuation instanceof DOMElement) {
                $continuationIds[trim($continuation->getAttribute('id'))] = true;
            }
        }
        foreach ($this->query(
            $xpath,
            '//*[@continuedAt]'
        ) as $continued) {
            if (!$continued instanceof DOMElement) {
                continue;
            }
            $target = trim($continued->getAttribute('continuedAt'));
            if ($target === '' || !isset($continuationIds[$target])) {
                $errors[] = 'Broken Inline continuation reference: ' . $target . '.';
            }
        }

        if ($this->query(
            $xpath,
            '//xhtml:script | //xhtml:iframe | //xhtml:object | //xhtml:embed'
        )->length > 0) {
            $errors[] = 'Scripts and active embedded content are not permitted.';
        }
        $selfContained = true;
        if ($this->query($xpath, '//xhtml:link')->length > 0) {
            $errors[] = 'The filing must not contain external stylesheet or resource link elements.';
            $selfContained = false;
        }
        $resourceAttributes = [
            'src',
            'href',
            'data',
            'action',
            'formaction',
            'poster',
            'background',
        ];
        foreach ($this->query($xpath, '//@*') as $attribute) {
            if (!$attribute instanceof \DOMAttr
                || !in_array(strtolower($attribute->localName), $resourceAttributes, true)) {
                continue;
            }
            $owner = $attribute->ownerElement;
            if ($owner instanceof DOMElement
                && $owner->namespaceURI === self::LINK_NS
                && $owner->localName === 'schemaRef'
                && $attribute->namespaceURI === self::XLINK_NS) {
                continue;
            }
            $value = trim($attribute->value);
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $value) === 1
                && !str_starts_with(strtolower($value), 'data:')) {
                $errors[] = 'External resource reference is prohibited at XML line '
                    . ($owner?->getLineNo() ?? 0) . ': ' . $value . '.';
                $selfContained = false;
            }
        }
        if (preg_match('/@import\b/i', $source) === 1) {
            $errors[] = 'CSS @import is prohibited in the self-contained filing.';
            $selfContained = false;
        }
        if (preg_match(
            '#url\s*\(\s*[\'"]?\s*(?!(?:data):)(?:[a-z][a-z0-9+.-]*:|//)#i',
            $source
        ) === 1) {
            $errors[] = 'The embedded stylesheet contains an external URL.';
            $selfContained = false;
        }
        if ($this->query(
            $xpath,
            '//xhtml:meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="refresh"]'
        )->length > 0) {
            $errors[] = 'Meta refresh is prohibited in the filing document.';
            $selfContained = false;
        }
        if ($this->query($xpath, '//@*[starts-with(local-name(), "on")]')->length > 0) {
            $errors[] = 'Inline event-handler attributes are not permitted.';
        }

        return $this->result($errors, $warnings, [
            'inline_xbrl_namespace' => self::IX_NS,
            'taxonomy_entry_points' => $entryPoints,
            'header_count' => $headerCount,
            'hidden_count' => $hiddenCount,
            'references_count' => $referencesCount,
            'resources_count' => $resourcesCount,
            'self_contained' => $selfContained,
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @param array<string, array<string, mixed>> $units
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function validateContextsUnitsAndFacts(
        array $contexts,
        array $units,
        array $facts
    ): array {
        $errors = [];
        $warnings = [];
        $contextIds = [];
        $unitIds = [];
        $entityIdentifiers = [];

        foreach ($contexts as $context) {
            $id = (string)$context['id'];
            if ($id === '') {
                $errors[] = 'A context at line ' . $context['line'] . ' has no id.';
            } elseif (isset($contextIds[$id])) {
                $errors[] = 'Duplicate context id: ' . $id . '.';
            }
            $contextIds[$id] = true;

            $identifier = strtoupper(trim((string)$context['entity_identifier']));
            $scheme = trim((string)$context['entity_scheme']);
            if (preg_match('/^[A-Z0-9]{8}$/', $identifier) !== 1) {
                $errors[] = 'Context ' . $id . ' has an invalid Companies House identifier: '
                    . $identifier . '.';
            }
            if ($scheme !== self::COMPANIES_HOUSE_SCHEME) {
                $errors[] = 'Context ' . $id . ' uses the wrong entity identifier scheme: '
                    . $scheme . '.';
            }
            $entityIdentifiers[$scheme . '|' . $identifier] = true;

            $period = (array)$context['period'];
            if (($period['type'] ?? '') === 'instant') {
                if (!$this->validIsoDate((string)($period['instant'] ?? ''))) {
                    $errors[] = 'Context ' . $id . ' has an invalid instant date.';
                }
            } elseif (($period['type'] ?? '') === 'duration') {
                $start = (string)($period['start'] ?? '');
                $end = (string)($period['end'] ?? '');
                if (!$this->validIsoDate($start) || !$this->validIsoDate($end)) {
                    $errors[] = 'Context ' . $id . ' has an invalid duration period.';
                } elseif ($start > $end) {
                    $errors[] = 'Context ' . $id . ' starts after it ends.';
                }
            } else {
                $errors[] = 'Context ' . $id . ' does not use an instant or duration period.';
            }

            $seenDimensions = [];
            foreach ((array)$context['dimensions'] as $dimension) {
                $dimensionName = trim((string)($dimension['dimension'] ?? ''));
                $member = trim((string)($dimension['member'] ?? ''));
                if ($dimensionName === '' || $member === '') {
                    $errors[] = 'Context ' . $id . ' contains an incomplete dimension/member.';
                }
                if (isset($seenDimensions[$dimensionName])) {
                    $errors[] = 'Context ' . $id . ' repeats dimension ' . $dimensionName
                        . ' with members ' . $seenDimensions[$dimensionName] . ' and ' . $member . '.';
                }
                $seenDimensions[$dimensionName] = $member;
            }
        }
        if (count($entityIdentifiers) > 1) {
            $errors[] = 'Contexts contain conflicting entity identifiers.';
        }

        foreach ($units as $unit) {
            $id = (string)$unit['id'];
            if ($id === '') {
                $errors[] = 'A unit at line ' . $unit['line'] . ' has no id.';
            } elseif (isset($unitIds[$id])) {
                $errors[] = 'Duplicate unit id: ' . $id . '.';
            }
            $unitIds[$id] = true;
            $measures = (array)$unit['measures'];
            if ($measures === []) {
                $errors[] = 'Unit ' . $id . ' has no measure.';
            }
            if ($id === 'GBP' && $measures !== ['iso4217:GBP']) {
                $errors[] = 'The GBP unit must use iso4217:GBP.';
            }
            if ($id === 'pure' && $measures !== ['xbrli:pure']) {
                $errors[] = 'The pure unit must use xbrli:pure.';
            }
        }

        foreach ($facts as $fact) {
            $where = $this->factWhere($fact);
            if (empty($fact['qname_resolved'])) {
                $errors[] = $where . ' has an unresolved concept QName.';
            }
            $contextRef = (string)$fact['context_ref'];
            if ($contextRef === '' || !isset($contextIds[$contextRef])) {
                $errors[] = $where . ' has missing contextRef target ' . $contextRef . '.';
                continue;
            }
            $context = $this->contextById($contexts, $contextRef);
            $expectedPeriod = (string)($fact['expected_period_type'] ?? '');
            if ($expectedPeriod !== ''
                && is_array($context)
                && (string)$context['period']['type'] !== $expectedPeriod) {
                $errors[] = $where . ' expects a ' . $expectedPeriod
                    . ' context but uses ' . (string)$context['period']['type'] . '.';
            }

            if (is_array($context)
                && ($fact['expected_value_type'] ?? null) !== null) {
                $expectedDimensions = (array)($fact['expected_dimensions'] ?? []);
                $actualDimensions = [];
                foreach ((array)$context['dimensions'] as $dimension) {
                    $actualDimensions[(string)$dimension['dimension']] =
                        (string)$dimension['member'];
                }
                foreach ($expectedDimensions as $dimension => $member) {
                    if (($actualDimensions[$dimension] ?? null) !== $member) {
                        $errors[] = $where . ' requires dimension ' . $dimension
                            . ' = ' . $member . '.';
                    }
                }
                foreach ($actualDimensions as $dimension => $member) {
                    if (!array_key_exists($dimension, $expectedDimensions)
                        && $dimension !== self::SUPERSEDED_DIMENSION) {
                        $errors[] = $where . ' uses an unexpected dimension '
                            . $dimension . ' = ' . $member . '.';
                    }
                }
            }

            if (!empty($fact['is_numeric'])) {
                $unitRef = (string)$fact['unit_ref'];
                if ($unitRef === '' || !isset($unitIds[$unitRef])) {
                    $errors[] = $where . ' has missing unitRef target ' . $unitRef . '.';
                }
                if ($fact['decimals'] === null) {
                    $errors[] = $where . ' must use decimals.';
                }
                if ($fact['precision'] !== null) {
                    $errors[] = $where . ' must not use precision.';
                }
                $expectedUnit = trim((string)($fact['expected_unit_ref'] ?? ''));
                if ($expectedUnit !== '' && $unitRef !== $expectedUnit) {
                    $errors[] = $where . ' expects unit ' . $expectedUnit
                        . ' but uses ' . $unitRef . '.';
                }
                if ($fact['machine_value'] === null && $fact['nil'] !== 'true') {
                    $errors[] = $where . ' has an invalid numeric lexical value.';
                }
                if ((string)$fact['scale'] !== ''
                    && preg_match('/^-?\d+$/', (string)$fact['scale']) !== 1) {
                    $errors[] = $where . ' has an invalid scale.';
                }
                if (!in_array((string)$fact['sign'], ['', '-'], true)) {
                    $errors[] = $where . ' has an invalid sign attribute.';
                }
            } else {
                if ((string)$fact['unit_ref'] !== '') {
                    $errors[] = $where . ' is non-numeric but has a unitRef.';
                }
                if (!empty($fact['empty'])
                    && ($fact['empty_marker_strategy'] ?? null) === null
                    && $fact['nil'] !== 'true') {
                    $errors[] = $where . ' is unexpectedly empty.';
                }
                if (($fact['expected_value_type'] ?? null) === 'boolean'
                    && !in_array((string)$fact['machine_value'], ['true', 'false'], true)) {
                    $errors[] = $where . ' has an invalid Boolean lexical value.';
                }
                if (($fact['expected_value_type'] ?? null) === 'date'
                    && !$this->validIsoDate((string)$fact['machine_value'])) {
                    $errors[] = $where . ' does not transform to an ISO date.';
                }
            }
        }

        foreach ($contexts as $context) {
            if ((array)$context['facts'] === []) {
                $warnings[] = 'Context ' . $context['id'] . ' is unused.';
            }
        }
        foreach ($units as $unit) {
            $used = false;
            foreach ($facts as $fact) {
                if ((string)$fact['unit_ref'] === (string)$unit['id']) {
                    $used = true;
                    break;
                }
            }
            if (!$used) {
                $warnings[] = 'Unit ' . $unit['id'] . ' is unused.';
            }
        }

        $reportingEnd = $this->reportingEndDate($facts);
        if ($reportingEnd === null) {
            $errors[] = 'The current reporting end date could not be determined from the tagged balance-sheet date.';
        } else {
            foreach (self::REQUIRED_CURRENT_NUMERIC_FACTS as $requiredConcept) {
                $present = false;
                foreach ($facts as $fact) {
                    if ((string)$fact['name'] !== $requiredConcept
                        || empty($fact['is_numeric'])
                        || ($fact['original_revised_status'] ?? '') !== 'current_revised') {
                        continue;
                    }
                    $context = $this->contextById(
                        $contexts,
                        (string)$fact['context_ref']
                    );
                    if (is_array($context)
                        && $this->contextEndsOn($context, $reportingEnd)) {
                        $present = true;
                        break;
                    }
                }
                if (!$present) {
                    $errors[] = 'Required current-period Format 2 fact '
                        . $requiredConcept . ' is missing.';
                }
            }
        }

        return $this->result($errors, $warnings, [
            'context_count' => count($contexts),
            'unit_count' => count($units),
            'fact_count' => count($facts),
            'entity_identifier_count' => count($entityIdentifiers),
            'taxonomy_semantics_note' => 'Arelle is authoritative for concept declarations, periodType and dimensional validity.',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<string, array<string, mixed>> $contexts
     * @return array<string, mixed>
     */
    private function validateArithmetic(array $facts, array $contexts): array
    {
        $errors = [];
        $warnings = [];
        $checks = [];
        $observed = [];

        $reportingEnd = $this->reportingEndDate($facts);
        $current = function (array $fact) use ($contexts, $reportingEnd): bool {
            if (($fact['original_revised_status'] ?? '') !== 'current_revised'
                || $reportingEnd === null) {
                return false;
            }
            $context = $this->contextById(
                $contexts,
                (string)$fact['context_ref']
            );
            return is_array($context)
                && $this->contextEndsOn($context, $reportingEnd);
        };
        $superseded = function (array $fact) use ($contexts, $reportingEnd): bool {
            if (($fact['original_revised_status'] ?? '') !== 'superseded_original'
                || $reportingEnd === null) {
                return false;
            }
            $context = $this->contextById(
                $contexts,
                (string)$fact['context_ref']
            );
            return is_array($context)
                && $this->contextEndsOn($context, $reportingEnd);
        };

        $currentValue = function (
            string $concept,
            ?string $dimension = null,
            ?string $member = null
        ) use ($facts, $contexts, $current): ?float {
            return $this->numericFactValue(
                $facts,
                $contexts,
                $concept,
                $current,
                $dimension,
                $member
            );
        };
        $originalValue = function (
            string $concept,
            ?string $dimension = null,
            ?string $member = null
        ) use ($facts, $contexts, $superseded): ?float {
            return $this->numericFactValue(
                $facts,
                $contexts,
                $concept,
                $superseded,
                $dimension,
                $member
            );
        };

        $turnover = $currentValue('core:TurnoverRevenue');
        $rawMaterials = $currentValue('core:RawMaterialsConsumablesUsed');
        $depreciation = $currentValue('core:DepreciationAmortisationImpairmentExpense');
        $otherCharges = $currentValue('core:OtherExternalCharges');
        $grossProfit = $currentValue('core:GrossProfitLoss');
        $operatingProfit = $currentValue('core:OperatingProfitLoss');
        $profitLoss = $currentValue('core:ProfitLoss');
        $otherIncome = $currentValue('core:OtherOperatingIncomeFormat2');
        $staffCosts = $currentValue('core:StaffCostsEmployeeBenefitsExpense');
        $tax = $currentValue('core:TaxTaxCreditOnProfitOrLossOnOrdinaryActivities');
        $observed += [
            'turnover' => $turnover,
            'raw_materials_consumables' => $rawMaterials,
            'depreciation_write_offs' => $depreciation,
            'other_external_charges' => $otherCharges,
            'gross_profit_loss' => $grossProfit,
            'operating_profit_loss' => $operatingProfit,
            'profit_loss' => $profitLoss,
        ];
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_gross_profit',
            'turnover - raw materials = gross profit/loss',
            [$turnover, $rawMaterials, $grossProfit],
            $turnover !== null && $rawMaterials !== null
                ? $turnover - $rawMaterials
                : null,
            $grossProfit
        );
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_operating_profit',
            'gross profit/loss + other income - staff costs - depreciation - other charges = operating profit/loss',
            [
                $grossProfit,
                $otherIncome,
                $staffCosts,
                $depreciation,
                $otherCharges,
                $operatingProfit,
            ],
            $grossProfit !== null && $otherIncome !== null && $staffCosts !== null
                && $depreciation !== null && $otherCharges !== null
                ? $grossProfit + $otherIncome - $staffCosts
                    - $depreciation - $otherCharges
                : null,
            $operatingProfit
        );
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_profit_after_tax',
            'operating profit/loss - tax = profit/loss',
            [$operatingProfit, $tax, $profitLoss],
            $operatingProfit !== null && $tax !== null
                ? $operatingProfit - $tax
                : null,
            $profitLoss
        );
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_profit_and_loss',
            'turnover + other income - raw materials - staff costs - depreciation - other charges - tax = profit/loss',
            [
                $turnover,
                $otherIncome,
                $rawMaterials,
                $staffCosts,
                $depreciation,
                $otherCharges,
                $tax,
                $profitLoss,
            ],
            $turnover !== null && $rawMaterials !== null && $depreciation !== null
                && $otherIncome !== null && $staffCosts !== null
                && $otherCharges !== null && $tax !== null
                    ? $turnover + $otherIncome - $rawMaterials - $staffCosts
                        - $depreciation - $otherCharges - $tax
                    : null,
            $profitLoss
        );

        $fixedAssets = $currentValue('core:FixedAssets');
        $currentAssets = $currentValue('core:CurrentAssets');
        $prepayments = $currentValue(
            'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal'
        );
        $creditorsWithin = $currentValue(
            'core:Creditors',
            'core:MaturitiesOrExpirationPeriodsDimension',
            'core:WithinOneYear'
        );
        $netCurrent = $currentValue('core:NetCurrentAssetsLiabilities');
        $totalAssetsLessCurrent = $currentValue('core:TotalAssetsLessCurrentLiabilities');
        $creditorsAfter = $currentValue(
            'core:Creditors',
            'core:MaturitiesOrExpirationPeriodsDimension',
            'core:AfterOneYear'
        );
        $provisions = $currentValue('core:ProvisionsForLiabilitiesBalanceSheetSubtotal');
        $accruals = $currentValue(
            'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal'
        );
        $calledUpUnpaid = $currentValue(
            'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset'
        );
        $netAssets = $currentValue('core:NetAssetsLiabilities');
        $equity = $currentValue('core:Equity');
        $observed += [
            'fixed_assets' => $fixedAssets,
            'current_assets' => $currentAssets,
            'prepayments_accrued_income' => $prepayments,
            'creditors_within_one_year' => $creditorsWithin,
            'net_current_assets_liabilities' => $netCurrent,
            'total_assets_less_current_liabilities' => $totalAssetsLessCurrent,
            'net_assets_liabilities' => $netAssets,
            'capital_and_reserves' => $equity,
        ];
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_net_current_assets_liabilities',
            'current assets + separately presented prepayments - creditors within one year = net current assets/liabilities',
            [$currentAssets, $prepayments, $creditorsWithin, $netCurrent],
            $currentAssets !== null && $prepayments !== null && $creditorsWithin !== null
                ? $currentAssets + $prepayments - $creditorsWithin
                : null,
            $netCurrent
        );
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_total_assets_less_current_liabilities',
            'called-up share capital not paid + fixed assets + net current assets/liabilities = total assets less current liabilities',
            [$calledUpUnpaid, $fixedAssets, $netCurrent, $totalAssetsLessCurrent],
            $calledUpUnpaid !== null && $fixedAssets !== null && $netCurrent !== null
                ? $calledUpUnpaid + $fixedAssets + $netCurrent
                : null,
            $totalAssetsLessCurrent
        );
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_net_assets',
            'total assets less current liabilities - creditors after one year - provisions - accruals = net assets/liabilities',
            [
                $totalAssetsLessCurrent,
                $creditorsAfter,
                $provisions,
                $accruals,
                $netAssets,
            ],
            $totalAssetsLessCurrent !== null && $creditorsAfter !== null
                && $provisions !== null && $accruals !== null
                ? $totalAssetsLessCurrent - $creditorsAfter - $provisions - $accruals
                : null,
            $netAssets
        );
        $this->arithmeticCheck(
            $checks,
            $errors,
            'current_equity',
            'net assets/liabilities = capital and reserves',
            [$netAssets, $equity],
            $netAssets,
            $equity
        );

        $originalCurrentAssets = $originalValue('core:CurrentAssets');
        $originalPrepayments = $originalValue(
            'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal'
        );
        $originalCreditors = $originalValue(
            'core:Creditors',
            'core:MaturitiesOrExpirationPeriodsDimension',
            'core:WithinOneYear'
        );
        $originalNetCurrent = $originalValue('core:NetCurrentAssetsLiabilities');
        $originalTotal = $originalValue('core:TotalAssetsLessCurrentLiabilities');
        $originalCalledUpUnpaid = $originalValue(
            'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset'
        );
        $originalFixedAssets = $originalValue('core:FixedAssets');
        $originalCreditorsAfter = $originalValue(
            'core:Creditors',
            'core:MaturitiesOrExpirationPeriodsDimension',
            'core:AfterOneYear'
        );
        $originalProvisions = $originalValue(
            'core:ProvisionsForLiabilitiesBalanceSheetSubtotal'
        );
        $originalAccruals = $originalValue(
            'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal'
        );
        $originalNetAssets = $originalValue('core:NetAssetsLiabilities');
        $originalEquity = $originalValue('core:Equity');
        $observed += [
            'superseded_current_assets' => $originalCurrentAssets,
            'superseded_creditors_within_one_year' => $originalCreditors,
            'superseded_net_current_assets_liabilities' => $originalNetCurrent,
            'superseded_total_assets_less_current_liabilities' => $originalTotal,
            'superseded_net_assets_liabilities' => $originalNetAssets,
            'superseded_capital_and_reserves' => $originalEquity,
        ];
        $this->supersededArithmeticCheck(
            $checks,
            $errors,
            $warnings,
            'superseded_net_current_assets',
            'superseded current assets + separately presented superseded prepayments - superseded creditors within one year = superseded net current assets',
            [$originalCurrentAssets, $originalCreditors, $originalNetCurrent],
            [$originalPrepayments],
            $originalCurrentAssets !== null && $originalCreditors !== null
                ? $originalCurrentAssets + ($originalPrepayments ?? 0.0)
                    - $originalCreditors
                : null,
            $originalNetCurrent
        );
        $this->supersededArithmeticCheck(
            $checks,
            $errors,
            $warnings,
            'superseded_total_assets_less_current_liabilities',
            'superseded called-up share capital not paid + fixed assets + net current assets/liabilities = superseded total assets less current liabilities',
            [$originalNetCurrent, $originalTotal],
            [$originalCalledUpUnpaid, $originalFixedAssets],
            $originalNetCurrent !== null
                ? ($originalCalledUpUnpaid ?? 0.0)
                    + ($originalFixedAssets ?? 0.0)
                    + $originalNetCurrent
                : null,
            $originalTotal
        );
        $this->supersededArithmeticCheck(
            $checks,
            $errors,
            $warnings,
            'superseded_net_assets',
            'superseded total assets less current liabilities - long-term creditors - provisions - accruals = superseded net assets',
            [$originalTotal, $originalNetAssets],
            [$originalCreditorsAfter, $originalProvisions, $originalAccruals],
            $originalTotal !== null
                ? $originalTotal
                    - ($originalCreditorsAfter ?? 0.0)
                    - ($originalProvisions ?? 0.0)
                    - ($originalAccruals ?? 0.0)
                : null,
            $originalNetAssets
        );
        $this->supersededArithmeticCheck(
            $checks,
            $errors,
            $warnings,
            'superseded_capital_and_reserves',
            'superseded net assets/liabilities = superseded capital and reserves',
            [$originalNetAssets, $originalEquity],
            [],
            $originalNetAssets,
            $originalEquity
        );

        return $this->result($errors, $warnings, [
            'tolerance' => self::MONEY_TOLERANCE,
            'reporting_end' => $reportingEnd,
            'observed_values' => $observed,
            'checks' => $checks,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<string, array<string, mixed>> $contexts
     * @return array<string, mixed>
     */
    private function validateDuplicates(array $facts, array $contexts): array
    {
        $errors = [];
        $warnings = [];
        $exact = [];
        $conceptContext = [];
        $semantic = [];
        $exactDuplicates = [];
        $conflicts = [];
        $semanticDuplicates = [];

        foreach ($facts as $fact) {
            $value = $this->canonicalValue($fact['machine_value']);
            $baseKey = implode('|', [
                (string)$fact['name'],
                (string)$fact['context_ref'],
                (string)$fact['unit_ref'],
            ]);
            $exactKey = implode('|', [
                $baseKey,
                (string)$fact['decimals'],
                (string)$fact['format'],
                $value,
            ]);
            if (isset($exact[$exactKey])) {
                $duplicate = [
                    'first_line' => $exact[$exactKey]['line'],
                    'duplicate_line' => $fact['line'],
                    'name' => $fact['name'],
                    'context_ref' => $fact['context_ref'],
                    'value' => $fact['machine_value'],
                ];
                $exactDuplicates[] = $duplicate;
                $errors[] = 'Exact duplicate fact ' . $fact['name'] . ' in context '
                    . $fact['context_ref'] . ' at lines ' . $duplicate['first_line']
                    . ' and ' . $duplicate['duplicate_line'] . '.';
            } else {
                $exact[$exactKey] = $fact;
            }
            if (isset($conceptContext[$baseKey])) {
                $previous = $conceptContext[$baseKey];
                $previousValue = $this->canonicalValue($previous['machine_value']);
                if ($previousValue !== $value) {
                    $conflict = [
                        'first_line' => $previous['line'],
                        'conflicting_line' => $fact['line'],
                        'name' => $fact['name'],
                        'context_ref' => $fact['context_ref'],
                        'first_value' => $previous['machine_value'],
                        'conflicting_value' => $fact['machine_value'],
                    ];
                    $conflicts[] = $conflict;
                    $errors[] = 'Conflicting facts for ' . $fact['name'] . ' in context '
                        . $fact['context_ref'] . '.';
                } elseif ((string)$previous['raw_value'] !== (string)$fact['raw_value']) {
                    $warnings[] = 'Duplicate ' . $fact['name'] . ' facts differ only by formatting.';
                }
                if ((bool)$previous['hidden'] !== (bool)$fact['hidden']) {
                    $warnings[] = $fact['name'] . ' is represented both visibly and in ix:hidden'
                        . ' for context ' . $fact['context_ref'] . '.';
                }
            } else {
                $conceptContext[$baseKey] = $fact;
            }

            $context = $this->contextById($contexts, (string)$fact['context_ref']);
            if (is_array($context)) {
                $semanticKey = (string)$fact['name'] . '|'
                    . (string)$context['semantic_signature'] . '|'
                    . (string)$fact['unit_ref'];
                if (isset($semantic[$semanticKey])
                    && (string)$semantic[$semanticKey]['context_ref']
                        !== (string)$fact['context_ref']) {
                    $semanticDuplicate = [
                        'name' => $fact['name'],
                        'first_context_ref' => $semantic[$semanticKey]['context_ref'],
                        'duplicate_context_ref' => $fact['context_ref'],
                        'first_value' => $semantic[$semanticKey]['machine_value'],
                        'duplicate_value' => $fact['machine_value'],
                    ];
                    $semanticDuplicates[] = $semanticDuplicate;
                    if ($this->canonicalValue($semanticDuplicate['first_value'])
                        !== $this->canonicalValue($semanticDuplicate['duplicate_value'])) {
                        $errors[] = 'Semantically identical contexts carry conflicting '
                            . $fact['name'] . ' values.';
                    } else {
                        $warnings[] = 'Semantically identical contexts '
                            . $semanticDuplicate['first_context_ref'] . ' and '
                            . $semanticDuplicate['duplicate_context_ref']
                            . ' carry the same ' . $fact['name'] . ' fact.';
                    }
                } else {
                    $semantic[$semanticKey] = $fact;
                }
            }
        }

        foreach ($contexts as $context) {
            $id = strtolower((string)$context['id']);
            if ((str_contains($id, 'original') || str_contains($id, 'superseded'))
                && !$this->contextHasMember(
                    $context,
                    self::SUPERSEDED_DIMENSION,
                    self::SUPERSEDED_MEMBER
                )) {
                $errors[] = 'Context ' . $context['id']
                    . ' appears to represent original data without the Superseded member.';
            }
        }

        return $this->result($errors, $warnings, [
            'exact_duplicates' => $exactDuplicates,
            'conflicting_facts' => $conflicts,
            'semantic_context_duplicates' => $semanticDuplicates,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<string, array<string, mixed>> $contexts
     * @return array<string, mixed>
     */
    private function visibleTaggedReconciliation(
        DOMXPath $xpath,
        array &$facts,
        array $contexts
    ): array {
        $errors = [];
        $warnings = [];
        $rows = [];
        $hiddenNumeric = [];

        foreach ($facts as &$fact) {
            $node = $fact['_node'] ?? null;
            if (!$node instanceof DOMElement) {
                continue;
            }
            $label = $this->visibleLabel($node);
            if ($label === '') {
                $label = (string)($fact['taxonomy_label'] ?? '');
            }
            if ($label === '') {
                $label = (string)$fact['name'];
            }
            $presentationText = !empty($fact['hidden'])
                ? ''
                : $this->presentationText($node);
            $hiddenReason = null;
            if (!empty($fact['hidden'])) {
                if (!empty($fact['is_numeric'])
                    && ($fact['original_revised_status'] ?? '')
                        === 'superseded_original') {
                    $hiddenReason = 'Superseded original value required for revised-accounts tagging; not part of the visible revised statements.';
                } elseif (!empty($fact['is_numeric'])) {
                    $hiddenReason = 'No recognised artifact-level reason was identified.';
                    $warnings[] = 'Hidden numeric fact ' . $fact['name'] . ' at line '
                        . $fact['line'] . ' requires a documented reason.';
                } elseif (($fact['empty_marker_strategy'] ?? null) !== null) {
                    $hiddenReason = 'Zero-length taxonomy marker whose meaning is supplied by its dimensional context or taxonomy-default member.';
                } elseif (isset(self::REVISED_FACT_METADATA[(string)$fact['name']])) {
                    $hiddenReason = 'Required revised-report metadata retained non-visibly where the corresponding statutory disclosure is presented separately.';
                } else {
                    $hiddenReason = 'Required company, period, production or filing metadata retained in the non-visible Inline XBRL header.';
                }
            }
            if (!empty($fact['hidden']) && !empty($fact['is_numeric'])) {
                $hiddenNumeric[] = [
                    'name' => $fact['name'],
                    'context_ref' => $fact['context_ref'],
                    'machine_value' => $fact['machine_value'],
                    'reason_hidden' => $hiddenReason,
                    'line' => $fact['line'],
                ];
            }

            $presentationSign = 'not_applicable';
            if (!empty($fact['is_numeric']) && empty($fact['hidden'])) {
                $machine = is_float($fact['machine_value'])
                    ? $fact['machine_value']
                    : (is_int($fact['machine_value']) ? (float)$fact['machine_value'] : null);
                $presentationSign = $this->presentationSign($presentationText);
                if ($machine !== null && $machine < 0
                    && !in_array($presentationSign, ['negative_parentheses', 'negative_minus'], true)) {
                    $errors[] = 'Negative fact ' . $fact['name'] . ' at line '
                        . $fact['line'] . ' appears visually positive.';
                }
            }

            $row = [
                'visible_label' => $label,
                'visible_value' => !empty($fact['hidden']) ? null : $presentationText,
                'xbrl_concept' => $fact['name'],
                'context_id' => $fact['context_ref'],
                'unit' => $fact['unit_ref'] !== '' ? $fact['unit_ref'] : null,
                'machine_readable_value' => $fact['machine_value'],
                'original_revised_status' => $fact['original_revised_status'],
                'source_ledger_or_accounts_field' => $fact['source_application_field'],
                'source_fact_key' => $fact['source_fact_key'],
                'hidden' => $fact['hidden'],
                'reason_hidden' => $hiddenReason,
                'presentation_sign' => $presentationSign,
                'xhtml_line' => $fact['line'],
            ];
            $rows[] = $row;
            unset($fact['_node']);
        }
        unset($fact);

        $untagged = [];
        foreach ($this->query(
            $xpath,
            '//xhtml:td[contains(concat(" ", normalize-space(@class), " "), " amount ")]'
            . '[not(.//ix:nonFraction) and not(.//ix:fraction)]'
        ) as $amountCell) {
            if (!$amountCell instanceof DOMElement) {
                continue;
            }
            $text = $this->normaliseText($amountCell->textContent);
            if (preg_match('/\d/', $text) !== 1) {
                continue;
            }
            $numeric = $this->presentationNumericValue($text);
            if ($numeric === null) {
                continue;
            }
            $label = $this->visibleLabel($amountCell);
            $entry = [
                'visible_label' => $label,
                'visible_value' => $text,
                'numeric_value' => $numeric,
                'xhtml_line' => $amountCell->getLineNo(),
            ];
            $untagged[] = $entry;
            $warnings[] = 'Visible numeric amount "' . $label . '" at line '
                . $amountCell->getLineNo() . ' is not directly tagged.';
        }

        return $this->result($errors, $warnings, [
            'rows' => $rows,
            'untagged_visible_numbers' => $untagged,
            'hidden_numeric_facts' => $hiddenNumeric,
            'scope_note' => 'Narrative numbers inside a tagged text block are not treated as standalone financial-table amounts.',
        ]);
    }

    /**
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>
     */
    private function normaliseTaxonomyLayer(
        ?array $raw,
        string $artifactSha256
    ): array
    {
        if ($raw === null) {
            return $this->layer('NOT RUN', [
                'Arelle taxonomy/Inline XBRL validation did not run.'
            ], [], [
                'mandatory' => true,
                'corrective_action' => 'Configure Arelle and rerun the pre-submission command.',
            ]);
        }
        $status = strtolower(trim((string)($raw['status'] ?? '')));
        $errors = array_values(array_map('strval', (array)($raw['errors'] ?? [])));
        $warnings = array_values(array_map('strval', (array)($raw['warnings'] ?? [])));
        $validatedHash = strtolower(trim((string)($raw['validated_sha256'] ?? '')));
        if ($validatedHash === ''
            || !hash_equals(strtolower($artifactSha256), $validatedHash)) {
            $errors[] = 'The Arelle result does not match this artifact SHA-256.';
        }
        $errors = array_values(array_unique($errors));
        $passed = !empty($raw['ok']) && $status === 'passed' && $errors === [];
        return $this->layer(
            $passed ? ($warnings === [] ? 'PASS' : 'PASS WITH WARNINGS') : 'FAIL',
            $passed ? [] : ($errors !== [] ? $errors : [
                'Arelle did not return a successful taxonomy validation result.'
            ]),
            $warnings,
            [
                'mandatory' => true,
                'validator' => (string)($raw['validator'] ?? 'arelle'),
                'version' => (string)($raw['version'] ?? ''),
                'status' => $status,
                'log_path' => (string)($raw['log_path'] ?? ''),
                'duration_ms' => (int)($raw['duration_ms'] ?? 0),
                'validated_sha256' => $validatedHash !== '' ? $validatedHash : null,
                'taxonomy_package_id' => $raw['taxonomy_package_id'] ?? null,
                'taxonomy_sha256' => $raw['taxonomy_sha256'] ?? null,
                'scope' => 'Inline XBRL specification, DTS taxonomy semantics, dimensions and available taxonomy rules.',
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>
     */
    private function normaliseCompaniesHouseLayer(
        ?array $raw,
        string $artifactSha256,
        ?string $artifactCompanyNumber = null
    ): array {
        if ($raw === null) {
            return $this->layer('NOT RUN', [
                'No official Companies House TEST/LIVE XML Gateway validation result was supplied.'
            ], [], [
                'mandatory' => true,
                'official_validation_run' => false,
                'corrective_action' => 'Run the Companies House TEST validation/submission procedure and rerun with --companies-house-result.',
            ]);
        }

        $errors = array_values(array_map('strval', (array)($raw['errors'] ?? [])));
        $warnings = array_values(array_map('strval', (array)($raw['warnings'] ?? [])));
        $resultHash = strtolower(trim((string)($raw['artifact_sha256'] ?? '')));
        $status = strtolower(trim((string)($raw['status'] ?? '')));
        $environment = strtoupper(trim((string)($raw['environment'] ?? '')));
        $official = ($raw['official'] ?? false) === true;
        $submissionNumber = trim((string)($raw['submission_number'] ?? ''));
        $declaredResponsePath = trim((string)($raw['response_artifact'] ?? ''));
        $declaredResponseHash = strtolower(trim(
            (string)($raw['response_artifact_sha256'] ?? '')
        ));
        $declaredResponseTransactionId = trim(
            (string)($raw['response_transaction_id'] ?? '')
        );
        $responseEvidence = null;
        if (!$official) {
            $errors[] = 'The supplied result is not marked as an official Companies House result.';
        }
        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            $errors[] = 'The Companies House result environment must be TEST or LIVE.';
        }
        if ($resultHash === '' || !hash_equals(strtolower($artifactSha256), $resultHash)) {
            $errors[] = 'The Companies House result does not match this artifact SHA-256.';
        }
        if ($status !== 'accepted') {
            $errors[] = 'The preserved XML Gateway evidence must report an accepted filing.';
        }
        if ($submissionNumber === '') {
            $errors[] = 'The Companies House result must identify the accepted submission number.';
        }
        if ($declaredResponsePath === '') {
            $errors[] = 'The Companies House result must reference a preserved response artifact.';
        }
        if ($declaredResponseHash === '') {
            $errors[] = 'The Companies House result must include response_artifact_sha256.';
        }
        if ($declaredResponseTransactionId === '') {
            $errors[] = 'The Companies House result must identify the response transaction ID.';
        }

        $resolvedResponsePath = $this->resolveCompaniesHouseEvidencePath(
            $declaredResponsePath,
            trim((string)($raw['_source_path'] ?? ''))
        );
        if ($declaredResponsePath !== '' && $resolvedResponsePath === null) {
            $errors[] = 'The preserved Companies House response artifact is missing or unreadable.';
        } elseif ($resolvedResponsePath !== null) {
            try {
                $responseEvidence = $this->inspectCompaniesHouseAcceptanceResponse(
                    $resolvedResponsePath,
                    $submissionNumber
                );
                $actualResponseHash = (string)$responseEvidence['sha256'];
                if ($declaredResponseHash === ''
                    || !hash_equals($actualResponseHash, $declaredResponseHash)) {
                    $errors[] = 'The preserved Companies House response does not match response_artifact_sha256.';
                }
                foreach ((array)$responseEvidence['errors'] as $responseError) {
                    $errors[] = (string)$responseError;
                }
                foreach ((array)$responseEvidence['warnings'] as $responseWarning) {
                    $warnings[] = (string)$responseWarning;
                }
                $actualResponseTransactionId = trim(
                    (string)($responseEvidence['response_transaction_id'] ?? '')
                );
                if ($actualResponseTransactionId === '') {
                    $errors[] = 'The preserved Companies House response has no transaction ID.';
                } elseif ($declaredResponseTransactionId !== ''
                    && !hash_equals(
                        $actualResponseTransactionId,
                        $declaredResponseTransactionId
                    )) {
                    $errors[] = 'The Companies House response transaction ID differs from the result metadata.';
                }
                $responseCompanyNumber = $this->normaliseCompanyNumber(
                    (string)($responseEvidence['company_number'] ?? '')
                );
                if ($responseCompanyNumber === '') {
                    $errors[] = 'The accepted Companies House response has no company number.';
                } elseif ($artifactCompanyNumber !== null
                    && !hash_equals(
                        $this->normaliseCompanyNumber($artifactCompanyNumber),
                        $responseCompanyNumber
                    )) {
                    $errors[] = 'The accepted Companies House response company number does not match the iXBRL entity identifier.';
                }
            } catch (\Throwable $exception) {
                $errors[] = 'The preserved Companies House response could not be verified: '
                    . $exception->getMessage();
            }
        }
        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        $passed = $errors === [];

        return $this->layer(
            $passed ? ($warnings === [] ? 'PASS' : 'PASS WITH WARNINGS') : 'FAIL',
            $errors,
            $warnings,
            [
                'mandatory' => true,
                'official_validation_run' => $official,
                'environment' => $environment,
                'status' => $status,
                'validator' => (string)($raw['validator'] ?? 'Companies House XML Gateway'),
                'validated_at' => (string)($raw['validated_at'] ?? ''),
                'artifact_sha256' => $resultHash,
                'result_source_path' => (string)($raw['_source_path'] ?? ''),
                'result_source_sha256' => (string)($raw['_source_sha256'] ?? ''),
                'submission_number' => $submissionNumber,
                'response_artifact' => $resolvedResponsePath
                    ?? $declaredResponsePath,
                'response_artifact_sha256' => $declaredResponseHash,
                'response_transaction_id' => $declaredResponseTransactionId,
                'response_evidence' => $responseEvidence,
                'preview_artifacts' => array_values((array)($raw['preview_artifacts'] ?? [])),
            ]
        );
    }

    private function resolveCompaniesHouseEvidencePath(
        string $declaredPath,
        string $resultSourcePath
    ): ?string {
        if ($declaredPath === '') {
            return null;
        }
        $candidates = [$declaredPath];
        $absolute = preg_match(
            '/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/',
            $declaredPath
        ) === 1;
        if (!$absolute && $resultSourcePath !== '') {
            $candidates[] = dirname($resultSourcePath)
                . DIRECTORY_SEPARATOR . $declaredPath;
        }
        foreach (array_reverse($candidates) as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved) && is_readable($resolved)) {
                return $resolved;
            }
        }
        return null;
    }

    /** @param array<string, array<string, mixed>> $contexts */
    private function artifactCompanyNumber(array $contexts): ?string
    {
        $identifiers = [];
        foreach ($contexts as $context) {
            if ((string)($context['entity_scheme'] ?? '')
                !== self::COMPANIES_HOUSE_SCHEME) {
                continue;
            }
            $identifier = $this->normaliseCompanyNumber(
                (string)($context['entity_identifier'] ?? '')
            );
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
        }
        return count($identifiers) === 1
            ? (string)array_key_first($identifiers)
            : null;
    }

    private function normaliseCompanyNumber(string $companyNumber): string
    {
        return strtoupper((string)preg_replace(
            '/[^A-Z0-9]/i',
            '',
            trim($companyNumber)
        ));
    }

    /**
     * Confirm acceptance from the same GovTalk GetSubmissionStatus structure
     * parsed by CompaniesHouseAccountsGatewayClient. Result JSON is only an
     * index: it cannot turn a missing, altered or rejected response into PASS.
     *
     * @return array<string, mixed>
     */
    private function inspectCompaniesHouseAcceptanceResponse(
        string $path,
        string $submissionNumber
    ): array {
        $xml = file_get_contents($path);
        if (!is_string($xml) || $xml === '') {
            throw new RuntimeException('The response artifact is empty.');
        }
        if (strlen($xml) > self::MAX_COMPANIES_HOUSE_RESPONSE_BYTES) {
            throw new RuntimeException('The response artifact exceeds the size limit.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false
            || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('The response artifact contains a prohibited document type.');
        }

        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new RuntimeException('The response artifact is malformed XML.');
        }

        $xpath = new DOMXPath($document);
        $firstText = static function (string $localName) use ($xpath): string {
            $nodes = $xpath->query('//*[local-name()="' . $localName . '"]');
            $node = $nodes === false ? null : $nodes->item(0);
            return $node instanceof DOMNode ? trim($node->textContent) : '';
        };
        $relativeText = static function (
            DOMElement $parent,
            string $localName
        ) use ($xpath): string {
            $nodes = $xpath->query(
                './*[local-name()="' . $localName . '"]',
                $parent
            );
            $node = $nodes === false ? null : $nodes->item(0);
            return $node instanceof DOMNode ? trim($node->textContent) : '';
        };

        $errors = [];
        $warnings = [];
        $messageClass = $firstText('Class');
        $qualifier = strtolower($firstText('Qualifier'));
        if ($messageClass !== 'GetSubmissionStatus') {
            $errors[] = 'The response is not a GetSubmissionStatus response.';
        }
        if ($qualifier !== 'response') {
            $errors[] = 'The GetSubmissionStatus response qualifier is not response.';
        }

        $gatewayErrorNodes = $xpath->query(
            '//*[local-name()="GovTalkErrors"]/*[local-name()="Error"]'
        );
        if ($gatewayErrorNodes !== false) {
            foreach ($gatewayErrorNodes as $gatewayErrorNode) {
                if (!$gatewayErrorNode instanceof DOMElement) {
                    continue;
                }
                $type = strtolower($relativeText($gatewayErrorNode, 'Type'));
                $number = $relativeText($gatewayErrorNode, 'Number');
                $texts = [];
                $textNodes = $xpath->query(
                    './*[local-name()="Text"]',
                    $gatewayErrorNode
                );
                if ($textNodes !== false) {
                    foreach ($textNodes as $textNode) {
                        $text = trim($textNode->textContent);
                        if ($text !== '') {
                            $texts[] = $text;
                        }
                    }
                }
                $message = 'Companies House gateway '
                    . ($type !== '' ? $type : 'error')
                    . ($number !== '' ? ' ' . $number : '')
                    . ($texts !== [] ? ': ' . implode(' ', $texts) : '');
                if ($type === 'warning') {
                    $warnings[] = $message;
                } else {
                    $errors[] = $message;
                }
            }
        }

        $matchingStatuses = [];
        $statusNodes = $xpath->query(
            '//*[local-name()="SubmissionStatus"]/*[local-name()="Status"]'
        );
        if ($statusNodes !== false) {
            foreach ($statusNodes as $statusNode) {
                if (!$statusNode instanceof DOMElement) {
                    continue;
                }
                if ($relativeText($statusNode, 'SubmissionNumber') === $submissionNumber) {
                    $matchingStatuses[] = $statusNode;
                }
            }
        }
        if ($submissionNumber === '' || $matchingStatuses === []) {
            $errors[] = 'The response contains no status for the declared submission number.';
            $statusCode = '';
            $companyNumber = '';
            $rejectionCount = 0;
        } else {
            $latestStatus = $matchingStatuses[count($matchingStatuses) - 1];
            $statusCode = strtoupper($relativeText($latestStatus, 'StatusCode'));
            $companyNumber = $relativeText($latestStatus, 'CompanyNumber');
            $rejectionNodes = $xpath->query(
                './*[local-name()="Rejections"]/*[local-name()="Reject"]',
                $latestStatus
            );
            $rejectionCount = $rejectionNodes === false ? 0 : $rejectionNodes->length;
            if ($statusCode !== 'ACCEPT') {
                $errors[] = 'The matching Companies House submission status is '
                    . ($statusCode !== '' ? $statusCode : 'missing')
                    . ', not ACCEPT.';
            }
            if ($rejectionCount > 0) {
                $errors[] = 'The matching Companies House submission contains rejection details.';
            }
        }

        return [
            'path' => $path,
            'sha256' => hash('sha256', $xml),
            'bytes' => strlen($xml),
            'message_class' => $messageClass,
            'qualifier' => $qualifier,
            'response_transaction_id' => $firstText('TransactionID'),
            'gateway_timestamp' => $firstText('GatewayTimestamp'),
            'submission_number' => $submissionNumber,
            'submission_status' => $statusCode,
            'company_number' => $companyNumber,
            'rejection_count' => $rejectionCount,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @param array<string, array<string, mixed>> $layers */
    private function overallStatus(array $layers): string
    {
        foreach ($layers as $layer) {
            if (in_array((string)($layer['status'] ?? ''), ['FAIL', 'NOT RUN'], true)) {
                return 'FAIL';
            }
        }
        foreach ($layers as $layer) {
            if ((string)($layer['status'] ?? '') === 'PASS WITH WARNINGS') {
                return 'PASS WITH WARNINGS';
            }
        }
        return 'PASS';
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function layerFromResult(array $result): array
    {
        return $this->layer(
            (string)$result['status'],
            (array)$result['errors'],
            (array)$result['warnings'],
            (array)$result['evidence']
        );
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function result(
        array $errors,
        array $warnings,
        array $evidence = []
    ): array {
        return [
            'status' => $errors !== []
                ? 'FAIL'
                : ($warnings !== [] ? 'PASS WITH WARNINGS' : 'PASS'),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function layer(
        string $status,
        array $errors = [],
        array $warnings = [],
        array $evidence = []
    ): array {
        return [
            'status' => $status,
            'blocking' => in_array($status, ['FAIL', 'NOT RUN'], true),
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param list<string> $errors
     * @param list<?float> $required
     */
    private function arithmeticCheck(
        array &$checks,
        array &$errors,
        string $key,
        string $expression,
        array $required,
        ?float $calculated,
        ?float $reported
    ): void {
        if (in_array(null, $required, true) || $calculated === null || $reported === null) {
            $checks[] = [
                'key' => $key,
                'expression' => $expression,
                'calculated' => $calculated,
                'reported' => $reported,
                'difference' => null,
                'status' => 'FAIL',
            ];
            $errors[] = 'Arithmetic check ' . $key . ' is missing a required tagged fact.';
            return;
        }
        $difference = round($calculated - $reported, 6);
        $passed = abs($difference) < self::MONEY_TOLERANCE;
        $checks[] = [
            'key' => $key,
            'expression' => $expression,
            'calculated' => round($calculated, 2),
            'reported' => round($reported, 2),
            'difference' => $difference,
            'status' => $passed ? 'PASS' : 'FAIL',
        ];
        if (!$passed) {
            $errors[] = 'Arithmetic check ' . $key . ' does not reconcile: calculated '
                . number_format($calculated, 2, '.', '') . ', reported '
                . number_format($reported, 2, '.', '') . '.';
        }
    }

    /**
     * Superseded facts are intentionally emitted only where the stored
     * original value changed. A relation is therefore blocking when all
     * essential facts are present. Missing optional components are treated as
     * zero only when that produces the reported relation; otherwise the check
     * is explicitly skipped with a warning instead of inventing an original
     * value from the revised document.
     *
     * @param list<array<string, mixed>> $checks
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<?float> $essential
     * @param list<?float> $optionalComponents
     */
    private function supersededArithmeticCheck(
        array &$checks,
        array &$errors,
        array &$warnings,
        string $key,
        string $expression,
        array $essential,
        array $optionalComponents,
        ?float $calculated,
        ?float $reported
    ): void {
        if (in_array(null, $essential, true)
            || $calculated === null
            || $reported === null) {
            $checks[] = [
                'key' => $key,
                'expression' => $expression,
                'calculated' => $calculated,
                'reported' => $reported,
                'difference' => null,
                'status' => 'NOT APPLICABLE',
                'reason' => 'The changed-only superseded fact set does not contain every essential fact.',
            ];
            $warnings[] = 'Arithmetic check ' . $key
                . ' was not applicable because the revised artifact contains only changed superseded facts.';
            return;
        }

        $difference = round($calculated - $reported, 6);
        if (abs($difference) >= self::MONEY_TOLERANCE
            && in_array(null, $optionalComponents, true)) {
            $checks[] = [
                'key' => $key,
                'expression' => $expression,
                'calculated' => round($calculated, 2),
                'reported' => round($reported, 2),
                'difference' => $difference,
                'status' => 'NOT APPLICABLE',
                'reason' => 'One or more unchanged or omitted original components are not present as superseded facts.',
            ];
            $warnings[] = 'Arithmetic check ' . $key
                . ' could not be completed from the changed-only superseded fact set.';
            return;
        }

        $this->arithmeticCheck(
            $checks,
            $errors,
            $key,
            $expression,
            $essential,
            $calculated,
            $reported
        );
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<string, array<string, mixed>> $contexts
     */
    private function numericFactValue(
        array $facts,
        array $contexts,
        string $concept,
        callable $filter,
        ?string $dimension = null,
        ?string $member = null
    ): ?float {
        $matches = [];
        foreach ($facts as $fact) {
            if ((string)$fact['name'] !== $concept
                || empty($fact['is_numeric'])
                || !$filter($fact)
                || !is_float($fact['machine_value'])) {
                continue;
            }
            $context = $this->contextById($contexts, (string)$fact['context_ref']);
            if (!is_array($context)) {
                continue;
            }
            if ($dimension !== null
                && !$this->contextHasMember($context, $dimension, (string)$member)) {
                continue;
            }
            if ($dimension === null) {
                $hasOtherNonRevisionDimension = false;
                foreach ((array)$context['dimensions'] as $axis) {
                    if ((string)$axis['dimension'] !== self::SUPERSEDED_DIMENSION) {
                        $hasOtherNonRevisionDimension = true;
                    }
                }
                if ($hasOtherNonRevisionDimension) {
                    continue;
                }
            }
            $matches[] = $fact['machine_value'];
        }
        return count($matches) === 1 ? (float)$matches[0] : null;
    }

    /** @param array<int, array<string, mixed>> $facts */
    private function reportingEndDate(array $facts): ?string
    {
        foreach ([
            'bus:BalanceSheetDate',
            'bus:EndDateForPeriodCoveredByReport',
        ] as $concept) {
            $values = [];
            foreach ($facts as $fact) {
                if ((string)($fact['name'] ?? '') !== $concept
                    || ($fact['original_revised_status'] ?? '') !== 'current_revised') {
                    continue;
                }
                $value = trim((string)($fact['machine_value'] ?? ''));
                if ($this->validIsoDate($value)) {
                    $values[$value] = true;
                }
            }
            if (count($values) === 1) {
                return (string)array_key_first($values);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $context */
    private function contextEndsOn(array $context, string $reportingEnd): bool
    {
        $period = (array)($context['period'] ?? []);
        return match ((string)($period['type'] ?? '')) {
            'instant' => (string)($period['instant'] ?? '') === $reportingEnd,
            'duration' => (string)($period['end'] ?? '') === $reportingEnd,
            default => false,
        };
    }

    /** @param array<string, mixed> $context */
    private function contextHasMember(
        array $context,
        string $dimension,
        string $member
    ): bool {
        foreach ((array)($context['dimensions'] ?? []) as $axis) {
            if ((string)($axis['dimension'] ?? '') === $dimension
                && (string)($axis['member'] ?? '') === $member) {
                return true;
            }
        }
        return false;
    }

    /**
     * Selects the mapping variant whose dimensional profile matches the fact
     * context. Concepts such as core:Creditors legitimately have separate
     * within-one-year and after-one-year mappings.
     *
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>
     */
    private function mappingForContext(string $concept, ?array $context): array
    {
        $candidates = $this->mappingsByConcept[$concept] ?? [];
        if ($candidates === [] || $context === null) {
            return $candidates[0] ?? [];
        }
        $actual = [];
        foreach ((array)($context['dimensions'] ?? []) as $dimension) {
            $actual[(string)($dimension['dimension'] ?? '')] =
                (string)($dimension['member'] ?? '');
        }
        foreach ($candidates as $candidate) {
            $expected = json_decode(
                (string)($candidate['dimensions_json'] ?? ''),
                true
            );
            $expected = is_array($expected) ? $expected : [];
            $matches = true;
            foreach ($expected as $dimension => $member) {
                if (($actual[$dimension] ?? null) !== $member) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $candidate;
            }
        }
        return $candidates[0];
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @return array<string, mixed>|null
     */
    private function contextById(array $contexts, string $id): ?array
    {
        foreach ($contexts as $context) {
            if ((string)$context['id'] === $id) {
                return $context;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $context */
    private function contextSignature(array $context): string
    {
        return hash('sha256', json_encode([
            'entity_identifier' => $context['entity_identifier'],
            'entity_scheme' => $context['entity_scheme'],
            'period' => $context['period'],
            'dimensions' => array_map(
                static fn(array $dimension): array => [
                    'kind' => $dimension['kind'],
                    'dimension' => $dimension['dimension'],
                    'member' => $dimension['member'],
                ],
                (array)$context['dimensions']
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, array<string, mixed>> $records */
    private function uniqueIndex(array $records, string $field): array
    {
        $index = [];
        $duplicates = [];
        foreach ($records as $key => $record) {
            $value = (string)($record[$field] ?? '');
            if (isset($index[$value])) {
                $duplicates[$value] = true;
            } else {
                $index[$value] = $key;
            }
        }
        foreach (array_keys($duplicates) as $duplicate) {
            unset($index[$duplicate]);
        }
        return $index;
    }

    private function numericMachineValue(DOMElement $fact, string $raw): ?float
    {
        if (strtolower(trim($fact->getAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'nil'
        ))) === 'true') {
            return null;
        }
        $format = $this->qnameLocalName($fact->getAttribute('format'));
        if ($format === 'zerodash' && preg_match('/^[-–—]$/u', trim($raw)) === 1) {
            $value = 0.0;
        } else {
            $normalised = str_replace([',', ' ', "\u{00A0}", '£'], '', trim($raw));
            if (preg_match('/^\((.*)\)$/', $normalised, $matches) === 1) {
                $normalised = '-' . $matches[1];
            }
            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $normalised) !== 1) {
                return null;
            }
            $value = (float)$normalised;
        }
        $scale = trim($fact->getAttribute('scale'));
        if ($scale !== '' && preg_match('/^-?\d+$/', $scale) === 1) {
            $value *= 10 ** (int)$scale;
        }
        if (trim($fact->getAttribute('sign')) === '-') {
            $value = -abs($value);
        }
        return $value;
    }

    private function nonNumericMachineValue(DOMElement $fact, string $raw): ?string
    {
        if (strtolower(trim($fact->getAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'nil'
        ))) === 'true') {
            return null;
        }
        $format = $this->qnameLocalName($fact->getAttribute('format'));
        if ($format === 'datedaymonthyearen') {
            $date = DateTimeImmutable::createFromFormat('!j F Y', trim($raw));
            return $date instanceof DateTimeImmutable
                && $date->format('j F Y') === trim($raw)
                    ? $date->format('Y-m-d')
                    : null;
        }
        if ($format === 'dateyearmonthday') {
            return $this->validIsoDate(trim($raw)) ? trim($raw) : null;
        }
        return $raw;
    }

    private function validIsoDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function qnameNamespace(DOMElement $node, string $qname): ?string
    {
        $parts = explode(':', $qname, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $namespace = $node->lookupNamespaceURI($parts[0]);
        return is_string($namespace) && $namespace !== '' ? $namespace : null;
    }

    private function qnameLocalName(string $qname): string
    {
        $parts = explode(':', trim($qname), 2);
        return count($parts) === 2 ? $parts[1] : $parts[0];
    }

    private function hasAncestor(DOMNode $node, string $namespace, string $localName): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMNode; $parent = $parent->parentNode) {
            if ($parent->namespaceURI === $namespace && $parent->localName === $localName) {
                return true;
            }
        }
        return false;
    }

    private function visibleLabel(DOMElement $node): string
    {
        for ($parent = $node; $parent instanceof DOMNode; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement
                && $parent->namespaceURI === self::XHTML_NS
                && $parent->localName === 'tr') {
                foreach ($parent->getElementsByTagNameNS(self::XHTML_NS, 'th') as $heading) {
                    if ($heading instanceof DOMElement
                        && str_contains(' ' . $heading->getAttribute('class') . ' ', ' description ')) {
                        return $this->normaliseText($heading->textContent);
                    }
                }
            }
        }
        return '';
    }

    private function presentationText(DOMElement $node): string
    {
        if ($node->localName === 'nonFraction' || $node->localName === 'fraction') {
            for ($parent = $node->parentNode; $parent instanceof DOMNode; $parent = $parent->parentNode) {
                if ($parent instanceof DOMElement
                    && $parent->namespaceURI === self::XHTML_NS
                    && in_array($parent->localName, ['td', 'th'], true)) {
                    return $this->normaliseText($parent->textContent);
                }
            }
        }
        return $this->normaliseText($node->textContent);
    }

    private function presentationSign(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^\(.*\)$/u', $text) === 1) {
            return 'negative_parentheses';
        }
        if (preg_match('/^[−-]/u', $text) === 1) {
            return 'negative_minus';
        }
        if (preg_match('/^[-–—]$/u', $text) === 1) {
            return 'zero_dash';
        }
        return 'positive_or_zero';
    }

    private function presentationNumericValue(string $text): ?float
    {
        $normalised = trim(str_replace([',', '£', "\u{00A0}", ' '], '', $text));
        $negative = preg_match('/^\((.*)\)$/u', $normalised, $matches) === 1;
        if ($negative) {
            $normalised = $matches[1];
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $normalised) !== 1) {
            return null;
        }
        return ($negative ? -1 : 1) * (float)$normalised;
    }

    /** @param array<string, mixed> $fact */
    private function factWhere(array $fact): string
    {
        return 'Fact ' . (string)$fact['name'] . ' at XHTML line ' . (string)$fact['line']
            . ' (context ' . (string)$fact['context_ref'] . ', value '
            . json_encode($fact['raw_value'], JSON_UNESCAPED_UNICODE) . ')';
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return rtrim(rtrim(number_format((float)$value, 10, '.', ''), '0'), '.');
        }
        return (string)$value;
    }

    private function resolveReadablePath(string $path): string
    {
        $path = trim($path);
        $resolved = $path !== '' ? realpath($path) : false;
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException(
                'The revised-accounts XHTML artifact could not be read: ' . $path
            );
        }
        return $resolved;
    }

    private function normaliseText(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function firstText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string
    {
        $node = $this->query($xpath, $expression, $context)->item(0);
        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    private function firstElement(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null
    ): ?DOMElement {
        $node = $this->query($xpath, $expression, $context)->item(0);
        return $node instanceof DOMElement ? $node : null;
    }

    private function query(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null
    ): DOMNodeList {
        $result = $xpath->query($expression, $context);
        if (!$result instanceof DOMNodeList) {
            throw new RuntimeException('Invalid internal XPath expression: ' . $expression);
        }
        return $result;
    }
}
