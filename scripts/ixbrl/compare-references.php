<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Scripts\Ixbrl;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Produces a compact, deterministic structural comparison of iXBRL references.
 *
 * This deliberately does not validate taxonomy semantics. Its purpose is to
 * make version and presentation differences between reference documents easy
 * to inspect without resolving an external DTD or making a network request.
 */
final class ReferenceComparisonDiagnostic
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const XBRLI_NS = 'http://www.xbrl.org/2003/instance';
    private const XBRLDI_NS = 'http://xbrl.org/2006/xbrldi';
    private const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** @param list<string> $paths */
    public function compare(array $paths): array
    {
        if (count($paths) < 2) {
            throw new InvalidArgumentException('Supply at least two iXBRL/XHTML files to compare.');
        }

        $documents = [];
        foreach ($paths as $path) {
            $documents[] = $this->inspect((string)$path);
        }

        return ['documents' => $documents];
    }

    public function inspect(string $path): array
    {
        $path = trim($path);
        $resolvedPath = $path !== '' ? realpath($path) : false;
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new InvalidArgumentException('Reference iXBRL/XHTML file could not be read: ' . $path);
        }

        $source = file_get_contents($resolvedPath);
        if (!is_string($source) || $source === '') {
            throw new RuntimeException('Reference iXBRL/XHTML file was empty: ' . $resolvedPath);
        }

        $document = $this->loadXml($source, $resolvedPath);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xhtml', self::XHTML_NS);
        $xpath->registerNamespace('ix', self::IX_NS);
        $xpath->registerNamespace('xbrli', self::XBRLI_NS);
        $xpath->registerNamespace('xbrldi', self::XBRLDI_NS);
        $xpath->registerNamespace('link', self::LINK_NS);

        $facts = $this->query($xpath, '//ix:nonNumeric | //ix:nonFraction | //ix:fraction');
        $contexts = $this->query($xpath, '//xbrli:context');
        $units = $this->units($xpath);

        return [
            'filename' => basename($resolvedPath),
            'sha256' => hash('sha256', $source),
            'taxonomy_entry_points' => $this->taxonomyEntryPoints($xpath),
            'root_namespaces' => $this->rootNamespaces($document, $xpath),
            'fact_count' => $facts->length,
            'fact_type_counts' => [
                'nonNumeric' => $this->query($xpath, '//ix:nonNumeric')->length,
                'nonFraction' => $this->query($xpath, '//ix:nonFraction')->length,
                'fraction' => $this->query($xpath, '//ix:fraction')->length,
            ],
            'context_count' => $contexts->length,
            'context_ids' => $this->attributeValues($contexts, 'id'),
            'unit_count' => count($units),
            'units' => $units,
            'accounts_type' => $this->accountsType($xpath, $facts),
            'page_wrapper_counts' => [
                'accountspage' => $this->classCount($xpath, 'accountspage'),
                'titlepage' => $this->classCount($xpath, 'titlepage'),
                'pagebreak' => $this->classCount($xpath, 'pagebreak'),
                'keepTogether' => $this->classCount($xpath, 'keepTogether'),
            ],
            'visible_statement_headings' => $this->visibleHeadings($xpath),
        ];
    }

    public function encode(array $comparison): string
    {
        $json = json_encode(
            $comparison,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json)) {
            throw new RuntimeException('The iXBRL comparison report could not be encoded as JSON.');
        }

        return $json . PHP_EOL;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $paths = array_slice($arguments, 1);
        if (count($paths) < 2) {
            fwrite(
                STDERR,
                'Usage: php scripts/ixbrl/compare-references.php <first.xhtml> <second.xhtml> [more.xhtml ...]'
                . PHP_EOL
            );
            return 2;
        }

        try {
            $diagnostic = new self();
            fwrite(STDOUT, $diagnostic->encode($diagnostic->compare($paths)));
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'iXBRL comparison failed: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function loadXml(string $source, string $path): DOMDocument
    {
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;

        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($source, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            $detail = isset($errors[0]) ? trim((string)$errors[0]->message) : 'unknown XML error';
            throw new RuntimeException('Reference is not well-formed XML: ' . $path . ' (' . $detail . ')');
        }

        return $document;
    }

    /** @return list<string> */
    private function taxonomyEntryPoints(DOMXPath $xpath): array
    {
        $entryPoints = [];
        foreach ($this->query($xpath, '//link:schemaRef') as $schemaRef) {
            if (!$schemaRef instanceof DOMElement) {
                continue;
            }
            $href = trim($schemaRef->getAttributeNS(self::XLINK_NS, 'href'));
            if ($href !== '') {
                $entryPoints[] = $href;
            }
        }

        return array_values(array_unique($entryPoints));
    }

    /** @return array<string, string> */
    private function rootNamespaces(DOMDocument $document, DOMXPath $xpath): array
    {
        $root = $document->documentElement;
        if (!$root instanceof DOMElement) {
            return [];
        }

        $namespaces = [];
        foreach ($this->query($xpath, 'namespace::*', $root) as $namespaceNode) {
            $nodeName = (string)$namespaceNode->nodeName;
            if ($nodeName === 'xmlns') {
                $prefix = '';
            } elseif (str_starts_with($nodeName, 'xmlns:')) {
                $prefix = substr($nodeName, strlen('xmlns:'));
            } else {
                continue;
            }
            if ($prefix === 'xml') {
                continue;
            }
            $namespaces[$prefix] = (string)$namespaceNode->nodeValue;
        }
        ksort($namespaces, SORT_STRING);

        return $namespaces;
    }

    /** @return list<array{id: string, measure: string}> */
    private function units(DOMXPath $xpath): array
    {
        $units = [];
        foreach ($this->query($xpath, '//xbrli:unit') as $unit) {
            if (!$unit instanceof DOMElement) {
                continue;
            }
            $measure = $this->query($xpath, './xbrli:measure', $unit)->item(0);
            $units[] = [
                'id' => $unit->getAttribute('id'),
                'measure' => $measure instanceof DOMNode ? $this->normaliseText($measure->textContent) : '',
            ];
        }
        usort(
            $units,
            static fn(array $left, array $right): int => strcmp($left['id'], $right['id'])
        );

        return $units;
    }

    private function accountsType(DOMXPath $xpath, \DOMNodeList $facts): array
    {
        $accountTypeFacts = [];
        foreach ($facts as $fact) {
            if (!$fact instanceof DOMElement
                || $this->qnameLocalName($fact->getAttribute('name')) !== 'AccountsType') {
                continue;
            }
            $accountTypeFacts[] = [
                'name' => $fact->getAttribute('name'),
                'context_ref' => $fact->getAttribute('contextRef'),
                'value' => $this->normaliseText($fact->textContent),
            ];
        }

        $dimensions = [];
        foreach ($this->query($xpath, '//xbrldi:explicitMember') as $member) {
            if (!$member instanceof DOMElement
                || $this->qnameLocalName($member->getAttribute('dimension')) !== 'AccountsTypeDimension') {
                continue;
            }
            $dimensions[] = [
                'dimension' => $member->getAttribute('dimension'),
                'member' => $this->normaliseText($member->textContent),
            ];
        }

        return [
            'fact_present' => $accountTypeFacts !== [],
            'dimension_present' => $dimensions !== [],
            'facts' => $accountTypeFacts,
            'dimensions' => $dimensions,
        ];
    }

    private function classCount(DOMXPath $xpath, string $className): int
    {
        $expression = '//*[contains(concat(" ", normalize-space(@class), " "), " '
            . $className
            . ' ")]';

        return $this->query($xpath, $expression)->length;
    }

    /** @return list<string> */
    private function visibleHeadings(DOMXPath $xpath): array
    {
        $headings = [];
        foreach ($this->query($xpath, '//xhtml:h1 | //xhtml:h2 | //xhtml:h3') as $heading) {
            if (!$heading instanceof DOMElement || $this->isHidden($heading)) {
                continue;
            }
            $text = $this->normaliseText($heading->textContent);
            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    private function isHidden(DOMNode $node): bool
    {
        for ($ancestor = $node; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if ($ancestor->namespaceURI === self::IX_NS && $ancestor->localName === 'hidden') {
                return true;
            }
            if ($ancestor->hasAttribute('hidden')) {
                return true;
            }
            if (preg_match('/(?:^|\s)hidden(?:\s|$)/', $ancestor->getAttribute('class')) === 1) {
                return true;
            }
            if (preg_match('/(?:^|;)\s*display\s*:\s*none\s*(?:;|$)/i', $ancestor->getAttribute('style')) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function attributeValues(\DOMNodeList $nodes, string $attribute): array
    {
        $values = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $values[] = $node->getAttribute($attribute);
            }
        }

        return $values;
    }

    private function qnameLocalName(string $qname): string
    {
        $position = strrpos($qname, ':');

        return $position === false ? $qname : substr($qname, $position + 1);
    }

    private function normaliseText(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function query(DOMXPath $xpath, string $expression, ?DOMNode $contextNode = null): \DOMNodeList
    {
        $result = $xpath->query($expression, $contextNode);
        if ($result === false) {
            throw new RuntimeException('Invalid diagnostic XPath expression: ' . $expression);
        }

        return $result;
    }
}

$scriptPath = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
$resolvedScriptPath = $scriptPath !== '' ? realpath($scriptPath) : false;
if (PHP_SAPI === 'cli'
    && $resolvedScriptPath !== false
    && strcasecmp($resolvedScriptPath, __FILE__) === 0) {
    exit(ReferenceComparisonDiagnostic::main($argv));
}
