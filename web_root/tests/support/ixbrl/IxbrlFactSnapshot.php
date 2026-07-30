<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Tests\Support\Ixbrl;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds deterministic, fact-level snapshots for before-and-after iXBRL checks.
 *
 * QName values are expanded to namespace URI plus local name so a harmless
 * prefix change cannot disguise a taxonomy change.
 */
final class IxbrlFactSnapshot
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const XBRLI_NS = 'http://www.xbrl.org/2003/instance';
    private const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * @return array{
     *   facts:list<array<string,string|bool>>,
     *   numeric_facts:list<array<string,string|bool>>,
     *   contexts:array<string,string>,
     *   units:array<string,string>,
     *   schema_refs:list<string>,
     *   visible_text:list<string>,
     *   normalised_visible_text:string,
     *   xml_ids:list<array<string,int|string>>,
     *   duplicate_xml_ids:array<string,list<array<string,int|string>>>,
     *   invalid_xml_ids:list<array<string,int|string>>,
     *   ids_unique:bool,
     *   duplicate_facts:array{
     *     equivalent:list<array<string,mixed>>,
     *     conflicting:list<array<string,mixed>>
     *   },
     *   counts:array<string,int>
     * }
     */
    public function inspect(string $xhtml): array
    {
        $document = $this->load($xhtml);
        $xpath = $this->xpath($document);
        $contexts = $this->canonicalElements($xpath, '//xbrli:context');
        $units = $this->canonicalElements($xpath, '//xbrli:unit');
        $contextSignatures = $this->semanticElementSignatures($xpath, '//xbrli:context');
        $unitSignatures = $this->semanticElementSignatures($xpath, '//xbrli:unit');
        $facts = [];
        foreach ($xpath->query('//ix:nonNumeric | //ix:nonFraction | //ix:fraction') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $facts[] = [
                'kind' => $node->localName,
                'qname' => $this->expandedQName($node, $node->getAttribute('name')),
                'value' => $this->factValue($node),
                'context_ref' => $node->getAttribute('contextRef'),
                'unit_ref' => $node->getAttribute('unitRef'),
                'sign' => $node->getAttribute('sign'),
                'scale' => $node->getAttribute('scale'),
                'decimals' => $node->getAttribute('decimals'),
                'precision' => $node->getAttribute('precision'),
                'format' => $this->expandedQName($node, $node->getAttribute('format')),
                'nil' => $node->getAttributeNS(self::XSI_NS, 'nil'),
                'hidden' => $this->hasAncestor($node, self::IX_NS, 'hidden'),
                'xml_lang' => $this->inheritedXmlLang($node),
            ];
        }
        usort($facts, static fn(array $left, array $right): int =>
            strcmp(self::encode($left), self::encode($right))
        );

        $numericFacts = array_values(array_filter(
            $facts,
            static fn(array $fact): bool => in_array(
                (string)$fact['kind'],
                ['nonFraction', 'fraction'],
                true
            )
        ));
        $schemaRefs = [];
        foreach ($xpath->query('//link:schemaRef') ?: [] as $schemaRef) {
            if ($schemaRef instanceof DOMElement) {
                $schemaRefs[] = $schemaRef->getAttributeNS(self::XLINK_NS, 'href');
            }
        }
        sort($schemaRefs, SORT_STRING);
        $idInspection = $this->inspectXmlIds($document);
        $duplicateFacts = $this->duplicateFactAnalysis(
            $facts,
            $contextSignatures,
            $unitSignatures
        );

        return [
            'facts' => $facts,
            'numeric_facts' => $numericFacts,
            'contexts' => $contexts,
            'units' => $units,
            'schema_refs' => $schemaRefs,
            'visible_text' => $this->visibleText($xpath),
            'normalised_visible_text' => $this->normalisedVisibleTextFromXPath($xpath),
            'xml_ids' => $idInspection['ids'],
            'duplicate_xml_ids' => $idInspection['duplicates'],
            'invalid_xml_ids' => $idInspection['invalid'],
            'ids_unique' => $idInspection['duplicates'] === []
                && $idInspection['invalid'] === [],
            'duplicate_facts' => $duplicateFacts,
            'counts' => [
                'facts' => count($facts),
                'non_numeric' => count(array_filter(
                    $facts,
                    static fn(array $fact): bool => $fact['kind'] === 'nonNumeric'
                )),
                'non_fraction' => count(array_filter(
                    $facts,
                    static fn(array $fact): bool => $fact['kind'] === 'nonFraction'
                )),
                'fraction' => count(array_filter(
                    $facts,
                    static fn(array $fact): bool => $fact['kind'] === 'fraction'
                )),
                'numeric_facts' => count($numericFacts),
                'contexts' => count($contexts),
                'units' => count($units),
                'xml_ids' => count($idInspection['ids']),
                'duplicate_xml_ids' => count($idInspection['duplicates']),
                'invalid_xml_ids' => count($idInspection['invalid']),
                'equivalent_duplicate_fact_groups' => count($duplicateFacts['equivalent']),
                'conflicting_duplicate_fact_groups' => count($duplicateFacts['conflicting']),
            ],
        ];
    }

    /**
     * Returns the copied/normalised body text while retaining literal
     * accounting punctuation wrapped around inline facts.
     */
    public function normalisedVisibleText(string $xhtml): string
    {
        $document = $this->load($xhtml);

        return $this->normalisedVisibleTextFromXPath($this->xpath($document));
    }

    /** @return list<array<string,string|bool>> */
    public function numericFactInventory(string $xhtml): array
    {
        return $this->inspect($xhtml)['numeric_facts'];
    }

    /**
     * @param list<string> $allowedTextFactQNames Expanded QName strings.
     * @return array<string,mixed>
     */
    public function compare(
        string $beforeXhtml,
        string $afterXhtml,
        array $allowedTextFactQNames = []
    ): array {
        $before = $this->inspect($beforeXhtml);
        $after = $this->inspect($afterXhtml);
        $allowed = array_fill_keys($allowedTextFactQNames, true);
        [$beforeFacts, $beforeAllowed] = $this->maskAllowedFactValues($before['facts'], $allowed);
        [$afterFacts, $afterAllowed] = $this->maskAllowedFactValues($after['facts'], $allowed);
        $beforeIntegrityPassed = (bool)$before['ids_unique']
            && $before['duplicate_facts']['conflicting'] === [];
        $afterIntegrityPassed = (bool)$after['ids_unique']
            && $after['duplicate_facts']['conflicting'] === [];

        return [
            'passed' => $beforeFacts === $afterFacts
                && $before['numeric_facts'] === $after['numeric_facts']
                && $before['contexts'] === $after['contexts']
                && $before['units'] === $after['units']
                && $before['schema_refs'] === $after['schema_refs']
                && $before['visible_text'] === $after['visible_text']
                && $beforeIntegrityPassed
                && $afterIntegrityPassed,
            'facts_unchanged_except_allowlist' => $beforeFacts === $afterFacts,
            'numeric_facts_unchanged' => $before['numeric_facts'] === $after['numeric_facts'],
            'contexts_unchanged' => $before['contexts'] === $after['contexts'],
            'units_unchanged' => $before['units'] === $after['units'],
            'schema_refs_unchanged' => $before['schema_refs'] === $after['schema_refs'],
            'other_visible_text_unchanged' => $before['visible_text'] === $after['visible_text'],
            'before_integrity_passed' => $beforeIntegrityPassed,
            'after_integrity_passed' => $afterIntegrityPassed,
            'before_ids_unique' => (bool)$before['ids_unique'],
            'after_ids_unique' => (bool)$after['ids_unique'],
            'before_duplicate_facts' => $before['duplicate_facts'],
            'after_duplicate_facts' => $after['duplicate_facts'],
            'duplicate_fact_analysis_unchanged' =>
                $before['duplicate_facts'] === $after['duplicate_facts'],
            'before_counts' => $before['counts'],
            'after_counts' => $after['counts'],
            'allowed_fact_values_before' => $beforeAllowed,
            'allowed_fact_values_after' => $afterAllowed,
            'before_snapshot_sha256' => hash('sha256', self::encode($before)),
            'after_snapshot_sha256' => hash('sha256', self::encode($after)),
        ];
    }

    private function load(string $xhtml): DOMDocument
    {
        if (trim($xhtml) === '') {
            throw new InvalidArgumentException('The iXBRL XHTML is empty.');
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $loaded = $document->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            $detail = $errors !== [] ? ' ' . trim((string)$errors[0]->message) : '';
            throw new RuntimeException('The iXBRL XHTML is not well-formed XML.' . $detail);
        }

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xhtml', self::XHTML_NS);
        $xpath->registerNamespace('ix', self::IX_NS);
        $xpath->registerNamespace('xbrli', self::XBRLI_NS);
        $xpath->registerNamespace('link', self::LINK_NS);
        $xpath->registerNamespace('xml', self::XML_NS);

        return $xpath;
    }

    /** @return array<string,string> */
    private function canonicalElements(DOMXPath $xpath, string $query): array
    {
        $elements = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $id = $node->getAttribute('id');
            $canonical = $node->C14N(true, false);
            if ($id === '' || !is_string($canonical)) {
                throw new RuntimeException('An iXBRL resource could not be canonicalised.');
            }
            $elements[$id] = $canonical;
        }
        ksort($elements, SORT_STRING);

        return $elements;
    }

    /**
     * Canonicalises contexts and units without their identifying attribute.
     * Facts using separately named but structurally equivalent resources
     * therefore share the same semantic aspect signature.
     *
     * @return array<string,string>
     */
    private function semanticElementSignatures(DOMXPath $xpath, string $query): array
    {
        $signatures = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $id = trim($node->getAttribute('id'));
            $canonicalDocument = new DOMDocument('1.0', 'UTF-8');
            $clone = $canonicalDocument->importNode($node, true);
            if ($id === '' || !$clone instanceof DOMElement) {
                throw new RuntimeException('An iXBRL resource could not be semantically canonicalised.');
            }
            $canonicalDocument->appendChild($clone);
            $clone->removeAttribute('id');
            $canonical = $clone->C14N(true, false);
            if (!is_string($canonical) || $canonical === '') {
                throw new RuntimeException('An iXBRL resource could not be semantically canonicalised.');
            }
            $signatures[$id] = hash('sha256', $canonical);
        }
        ksort($signatures, SORT_STRING);

        return $signatures;
    }

    /**
     * @return array{
     *   ids:list<array<string,int|string>>,
     *   duplicates:array<string,list<array<string,int|string>>>,
     *   invalid:list<array<string,int|string>>
     * }
     */
    private function inspectXmlIds(DOMDocument $document): array
    {
        $ids = [];
        $byValue = [];
        $invalid = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            foreach ([
                [
                    'attribute' => 'id',
                    'value' => $element->getAttribute('id'),
                    'present' => $element->hasAttribute('id'),
                ],
                [
                    'attribute' => 'xml:id',
                    'value' => $element->getAttributeNS(self::XML_NS, 'id'),
                    'present' => $element->hasAttributeNS(self::XML_NS, 'id'),
                ],
            ] as $candidate) {
                if (!$candidate['present']) {
                    continue;
                }
                $record = [
                    'value' => (string)$candidate['value'],
                    'attribute' => (string)$candidate['attribute'],
                    'element' => '{' . ($element->namespaceURI ?? '') . '}' . $element->localName,
                    'line' => $element->getLineNo(),
                ];
                $ids[] = $record;
                if (trim((string)$candidate['value']) === '') {
                    $invalid[] = $record;
                    continue;
                }
                $byValue[(string)$candidate['value']][] = $record;
            }
        }
        usort($ids, static fn(array $left, array $right): int =>
            strcmp(self::encode($left), self::encode($right))
        );
        usort($invalid, static fn(array $left, array $right): int =>
            strcmp(self::encode($left), self::encode($right))
        );
        $duplicates = array_filter(
            $byValue,
            static fn(array $occurrences): bool => count($occurrences) > 1
        );
        foreach ($duplicates as &$occurrences) {
            usort($occurrences, static fn(array $left, array $right): int =>
                strcmp(self::encode($left), self::encode($right))
            );
        }
        unset($occurrences);
        ksort($duplicates, SORT_STRING);

        return [
            'ids' => $ids,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
        ];
    }

    /**
     * Classifies repeated facts that share the same XBRL aspects. Equivalent
     * occurrences have identical fact payloads; conflicting occurrences have
     * different values or accuracy/transformation attributes.
     *
     * @param list<array<string,string|bool>> $facts
     * @param array<string,string> $contextSignatures
     * @param array<string,string> $unitSignatures
     * @return array{
     *   equivalent:list<array<string,mixed>>,
     *   conflicting:list<array<string,mixed>>
     * }
     */
    private function duplicateFactAnalysis(
        array $facts,
        array $contextSignatures,
        array $unitSignatures
    ): array {
        $groups = [];
        foreach ($facts as $fact) {
            $contextRef = (string)$fact['context_ref'];
            $unitRef = (string)$fact['unit_ref'];
            $aspect = [
                'kind' => (string)$fact['kind'],
                'qname' => (string)$fact['qname'],
                'context_signature' => $contextSignatures[$contextRef]
                    ?? '__MISSING_CONTEXT__:' . $contextRef,
                'unit_signature' => $unitRef === ''
                    ? ''
                    : ($unitSignatures[$unitRef] ?? '__MISSING_UNIT__:' . $unitRef),
                'xml_lang' => (string)$fact['xml_lang'],
            ];
            $key = self::encode($aspect);
            $groups[$key]['aspect'] = $aspect;
            $groups[$key]['facts'][] = $fact;
        }

        $analysis = ['equivalent' => [], 'conflicting' => []];
        foreach ($groups as $group) {
            $groupFacts = (array)$group['facts'];
            if (count($groupFacts) < 2) {
                continue;
            }
            $payloads = [];
            $contextRefs = [];
            $unitRefs = [];
            foreach ($groupFacts as $fact) {
                $payload = [
                    'value' => (string)$fact['value'],
                    'sign' => (string)$fact['sign'],
                    'scale' => (string)$fact['scale'],
                    'decimals' => (string)$fact['decimals'],
                    'precision' => (string)$fact['precision'],
                    'format' => (string)$fact['format'],
                    'nil' => (string)$fact['nil'],
                ];
                $payloads[self::encode($payload)] = $payload;
                $contextRefs[(string)$fact['context_ref']] = true;
                if ((string)$fact['unit_ref'] !== '') {
                    $unitRefs[(string)$fact['unit_ref']] = true;
                }
            }
            ksort($payloads, SORT_STRING);
            ksort($contextRefs, SORT_STRING);
            ksort($unitRefs, SORT_STRING);
            $record = [
                'kind' => (string)$group['aspect']['kind'],
                'qname' => (string)$group['aspect']['qname'],
                'xml_lang' => (string)$group['aspect']['xml_lang'],
                'context_refs' => array_keys($contextRefs),
                'unit_refs' => array_keys($unitRefs),
                'occurrence_count' => count($groupFacts),
                'payloads' => array_values($payloads),
            ];
            $classification = count($payloads) === 1 ? 'equivalent' : 'conflicting';
            $analysis[$classification][] = $record;
        }
        foreach ($analysis as &$records) {
            usort($records, static fn(array $left, array $right): int =>
                strcmp(self::encode($left), self::encode($right))
            );
        }
        unset($records);

        return $analysis;
    }

    /** @return list<string> */
    private function visibleText(DOMXPath $xpath): array
    {
        $text = [];
        $query = '//xhtml:body//text()['
            . 'not(ancestor::ix:header) and '
            . 'not(ancestor::xhtml:style) and '
            . 'not(ancestor::ix:nonNumeric['
            . '@name="bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006" or '
            . '@name="bus:StatementSignificantAmendmentsToPreviouslyFiledReport"'
            . '])'
            . ']';
        foreach ($xpath->query($query) ?: [] as $node) {
            $value = preg_replace('/\s+/u', ' ', trim((string)$node->nodeValue)) ?? '';
            if ($value !== '') {
                $text[] = $value;
            }
        }

        return $text;
    }

    private function normalisedVisibleTextFromXPath(DOMXPath $xpath): string
    {
        $body = $xpath->query('//xhtml:body')->item(0);
        if (!$body instanceof DOMElement) {
            return '';
        }
        $text = '';
        $this->appendVisibleText($body, $text);

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function appendVisibleText(DOMNode $node, string &$text): void
    {
        if ($node instanceof DOMElement) {
            if (($node->namespaceURI === self::IX_NS && $node->localName === 'header')
                || ($node->namespaceURI === self::XHTML_NS
                    && in_array($node->localName, ['script', 'style'], true))) {
                return;
            }
            $block = $node->namespaceURI === self::XHTML_NS
                && in_array($node->localName, [
                    'address',
                    'article',
                    'aside',
                    'blockquote',
                    'br',
                    'caption',
                    'dd',
                    'div',
                    'dl',
                    'dt',
                    'figcaption',
                    'figure',
                    'footer',
                    'h1',
                    'h2',
                    'h3',
                    'h4',
                    'h5',
                    'h6',
                    'header',
                    'hr',
                    'li',
                    'main',
                    'nav',
                    'ol',
                    'p',
                    'section',
                    'table',
                    'tbody',
                    'td',
                    'tfoot',
                    'th',
                    'thead',
                    'tr',
                    'ul',
                ], true);
            if ($block) {
                $text .= ' ';
            }
            foreach ($node->childNodes as $child) {
                $this->appendVisibleText($child, $text);
            }
            if ($block) {
                $text .= ' ';
            }
            return;
        }
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $text .= $node->nodeValue;
        }
    }

    private function factValue(DOMElement $fact): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($fact->textContent));
    }

    private function expandedQName(DOMElement $element, string $lexical): string
    {
        $lexical = trim($lexical);
        if ($lexical === '') {
            return '';
        }
        $parts = explode(':', $lexical, 2);
        $prefix = count($parts) === 2 ? $parts[0] : null;
        $localName = count($parts) === 2 ? $parts[1] : $parts[0];
        $namespace = $element->lookupNamespaceURI($prefix);

        return '{' . ($namespace ?? '') . '}' . $localName;
    }

    private function inheritedXmlLang(DOMElement $element): string
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttributeNS(self::XML_NS, 'lang')) {
                return $node->getAttributeNS(self::XML_NS, 'lang');
            }
        }

        return '';
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

    /**
     * @param list<array<string,string|bool>> $facts
     * @param array<string,bool> $allowed
     * @return array{0:list<array<string,string|bool>>,1:array<string,list<string>>}
     */
    private function maskAllowedFactValues(array $facts, array $allowed): array
    {
        $values = [];
        foreach ($facts as &$fact) {
            $qname = (string)$fact['qname'];
            if (!isset($allowed[$qname])) {
                continue;
            }
            $values[$qname][] = (string)$fact['value'];
            $fact['value'] = '__ALLOWED_TEXT_CHANGE__';
        }
        unset($fact);
        ksort($values, SORT_STRING);

        return [$facts, $values];
    }

    private static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
