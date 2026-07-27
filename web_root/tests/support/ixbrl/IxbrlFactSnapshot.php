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

    /**
     * @return array{
     *   facts:list<array<string,string|bool>>,
     *   contexts:array<string,string>,
     *   units:array<string,string>,
     *   schema_refs:list<string>,
     *   visible_text:list<string>,
     *   counts:array<string,int>
     * }
     */
    public function inspect(string $xhtml): array
    {
        $document = $this->load($xhtml);
        $xpath = $this->xpath($document);
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
            ];
        }
        usort($facts, static fn(array $left, array $right): int =>
            strcmp(self::encode($left), self::encode($right))
        );

        $contexts = $this->canonicalElements($xpath, '//xbrli:context');
        $units = $this->canonicalElements($xpath, '//xbrli:unit');
        $schemaRefs = [];
        foreach ($xpath->query('//link:schemaRef') ?: [] as $schemaRef) {
            if ($schemaRef instanceof DOMElement) {
                $schemaRefs[] = $schemaRef->getAttributeNS(self::XLINK_NS, 'href');
            }
        }
        sort($schemaRefs, SORT_STRING);

        return [
            'facts' => $facts,
            'contexts' => $contexts,
            'units' => $units,
            'schema_refs' => $schemaRefs,
            'visible_text' => $this->visibleText($xpath),
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
                'contexts' => count($contexts),
                'units' => count($units),
            ],
        ];
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

        return [
            'passed' => $beforeFacts === $afterFacts
                && $before['contexts'] === $after['contexts']
                && $before['units'] === $after['units']
                && $before['schema_refs'] === $after['schema_refs']
                && $before['visible_text'] === $after['visible_text'],
            'facts_unchanged_except_allowlist' => $beforeFacts === $afterFacts,
            'contexts_unchanged' => $before['contexts'] === $after['contexts'],
            'units_unchanged' => $before['units'] === $after['units'],
            'schema_refs_unchanged' => $before['schema_refs'] === $after['schema_refs'],
            'other_visible_text_unchanged' => $before['visible_text'] === $after['visible_text'],
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
