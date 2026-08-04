<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Taxonomy-neutral Inline XBRL rendering and managed storage. */
final class IxbrlGeneratorService
{
    public const IX_NAMESPACE = 'http://www.xbrl.org/2013/inlineXBRL';
    public const XBRLI_NAMESPACE = 'http://www.xbrl.org/2003/instance';

    public function renderDocument(array $document): string
    {
        $namespaces = array_merge([
            'ix' => self::IX_NAMESPACE,
            'xbrli' => self::XBRLI_NAMESPACE,
            'xbrldi' => 'http://xbrl.org/2006/xbrldi',
            'iso4217' => 'http://www.xbrl.org/2003/iso4217',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'link' => 'http://www.xbrl.org/2003/linkbase',
            'xlink' => 'http://www.w3.org/1999/xlink',
        ], (array)($document['namespaces'] ?? []));
        $namespaceAttributes = '';
        foreach ($namespaces as $prefix => $uri) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', (string)$prefix) || trim((string)$uri) === '') {
                throw new \InvalidArgumentException('Invalid Inline XBRL namespace declaration.');
            }
            $namespaceAttributes .= ' xmlns:' . $prefix . '="' . $this->escape((string)$uri) . '"';
        }
        $references = '';
        foreach ((array)($document['schema_refs'] ?? []) as $href) {
            $references .= '<link:schemaRef xlink:type="simple" xlink:href="' . $this->escape((string)$href) . '"/>';
        }
        $resources = '';
        foreach ((array)($document['contexts'] ?? []) as $context) {
            $resources .= $this->renderContext((array)$context);
        }
        foreach ((array)($document['units'] ?? []) as $unit) {
            $resources .= $this->renderUnit((array)$unit);
        }
        $title = $this->escape((string)($document['title'] ?? 'Inline XBRL'));
        $metadata = '';
        foreach ((array)($document['metadata'] ?? []) as $name => $content) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', (string)$name) !== 1) {
                throw new \InvalidArgumentException('Invalid Inline XBRL metadata name.');
            }
            $metadata .= '<meta name="' . $this->escape((string)$name) . '" content="' . $this->escape((string)$content) . '"/>';
        }
        $stylesheet = trim((string)($document['stylesheet'] ?? ''));
        if ($stylesheet !== '' && str_contains(strtolower($stylesheet), '</style')) {
            throw new \InvalidArgumentException('Inline XBRL stylesheets cannot contain markup.');
        }
        $style = $stylesheet !== ''
            ? '<style type="text/css">' . $this->escape($stylesheet) . '</style>'
            : '';
        $documentPrefix = (string)($document['document_prefix']
            ?? CompaniesHouseIxbrlDocumentPolicyService::DOCUMENT_PREFIX);
        if ($documentPrefix === '' || !str_starts_with($documentPrefix, '<?xml version="1.0"')) {
            throw new \InvalidArgumentException('An authority-specific XML document prefix is required.');
        }
        return $documentPrefix
            . '<html xmlns="http://www.w3.org/1999/xhtml"' . $namespaceAttributes . ' xml:lang="en"><head><title>' . $title . '</title>'
            . '<meta http-equiv="Content-Type" content="application/xhtml+xml; charset=UTF-8"/>' . $metadata . $style . '</head><body>'
            . '<div style="display:none"><ix:header><ix:references>' . $references . '</ix:references><ix:resources>'
            . $resources . '</ix:resources></ix:header></div>' . (string)($document['body'] ?? '') . '</body></html>';
    }

    public function renderFact(array $fact): string
    {
        $name = trim((string)($fact['qname'] ?? ''));
        $context = trim((string)($fact['context_ref'] ?? ''));
        if (!$this->validQName($name) || $context === '') {
            throw new \InvalidArgumentException('Inline XBRL facts require a QName and context.');
        }
        $numeric = !empty($fact['numeric']);
        $attributes = ' name="' . $this->escape($name) . '" contextRef="' . $this->escape($context) . '"';
        $format = trim((string)($fact['format'] ?? ''));
        if ($format !== '' && !$this->validQName($format)) {
            throw new \InvalidArgumentException('Inline XBRL fact formats must be QNames.');
        }
        if ($format !== '') {
            $attributes .= ' format="' . $this->escape($format) . '"';
        }
        if (($fact['value'] ?? null) === null) {
            return '<ix:' . ($numeric ? 'nonFraction' : 'nonNumeric') . $attributes . ' xsi:nil="true"/>';
        }
        $hasDisplayValue = array_key_exists('display_value', $fact);
        if ($hasDisplayValue && $format === '') {
            throw new \InvalidArgumentException('A transformed Inline XBRL display value requires a format.');
        }
        if ($numeric) {
            $decimals = (string)($fact['decimals'] ?? '2');
            $attributes .= ' unitRef="' . $this->escape((string)($fact['unit_ref'] ?? 'GBP')) . '" decimals="' . $this->escape($decimals) . '"';
            $precision = max(0, (int)$decimals);
            $value = (float)$fact['value'];
            $rendered = $hasDisplayValue
                ? trim((string)$fact['display_value'])
                : number_format(abs($value), $precision, '.', '');
            if ($rendered === '') {
                throw new \InvalidArgumentException('A transformed numeric display value cannot be empty.');
            }
            if ($value < 0.0 && abs($value) > 0.0) {
                $attributes .= ' sign="-"';
            }
            return '<ix:nonFraction' . $attributes . '>' . $this->escape($rendered) . '</ix:nonFraction>';
        }
        $rendered = $hasDisplayValue
            ? (string)$fact['display_value']
            : (is_bool($fact['value']) ? ($fact['value'] ? 'true' : 'false') : (string)$fact['value']);
        return '<ix:nonNumeric' . $attributes . '>' . $this->escape($rendered) . '</ix:nonNumeric>';
    }

    public function validateStructure(string $xhtml, array $requiredSchemaRefs = []): array
    {
        $errors = [];
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        if (!$document->loadXML($xhtml, LIBXML_NONET)) {
            $errors[] = 'The generated document is not well-formed XHTML.';
        } else {
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('ix', self::IX_NAMESPACE);
            $xpath->registerNamespace('xbrli', self::XBRLI_NAMESPACE);
            if ($xpath->query('//ix:header')->length !== 1) {
                $errors[] = 'The generated document must contain one Inline XBRL header.';
            }
            if ($xpath->query('//xbrli:context')->length === 0) {
                $errors[] = 'The generated document contains no XBRL contexts.';
            }
            foreach ($requiredSchemaRefs as $schemaRef) {
                if (!str_contains($xhtml, (string)$schemaRef)) {
                    $errors[] = 'The required taxonomy schema reference is missing: ' . (string)$schemaRef;
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $errors;
    }

    /** Store a content-addressed artifact without ever replacing an existing file. */
    public function storeImmutableArtifact(
        int $companyId,
        string $companyNumber,
        int $accountingPeriodId,
        int $approvalId,
        int $runId,
        string $destination,
        string $periodStart,
        string $periodEnd,
        string $xhtml
    ): array {
        $directory = rtrim((new FileCheckService())->getIxbrlDirectory($companyId), '\\/');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the company iXBRL directory.');
        }
        $sha256 = hash('sha256', $xhtml);
        $filename = (new IxbrlArtifactFilenameService())->build(
            $companyNumber,
            $accountingPeriodId,
            $approvalId,
            $runId,
            $destination,
            $periodStart,
            $periodEnd,
            $sha256
        );
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            if (!is_string($existingHash) || !hash_equals($sha256, strtolower($existingHash))) {
                throw new \RuntimeException('An immutable iXBRL artifact filename is already occupied by different content.');
            }
            return ['directory' => $directory, 'filename' => $filename, 'path' => $path, 'sha256' => $sha256, 'created' => false];
        }
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not create immutable iXBRL export file.');
        }
        $created = true;
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $xhtml) !== strlen($xhtml) || !fflush($handle)) {
                throw new \RuntimeException('Could not write immutable iXBRL export file.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
        fclose($handle);
        return ['directory' => $directory, 'filename' => $filename, 'path' => $path, 'sha256' => $sha256, 'created' => $created];
    }

    public function removeManagedArtifact(string $path, int $companyId): void
    {
        if (trim($path) === '' || !is_file($path)) {
            return;
        }
        $root = realpath((new FileCheckService())->getIxbrlDirectory($companyId));
        $resolved = realpath($path);
        if ($root !== false && $resolved !== false && str_starts_with(strtolower($resolved), strtolower(rtrim($root, '\\/') . DIRECTORY_SEPARATOR))) {
            @unlink($resolved);
        }
    }

    public function escape(string $value): string
    {
        return \eel_accounts\Support\Utf8::xml($value);
    }

    private function renderContext(array $context): string
    {
        $id = trim((string)($context['id'] ?? ''));
        $identifier = trim((string)($context['identifier'] ?? ''));
        if ($id === '' || $identifier === '') {
            throw new \InvalidArgumentException('An XBRL context requires an id and entity identifier.');
        }
        $period = isset($context['instant'])
            ? '<xbrli:instant>' . $this->escape((string)$context['instant']) . '</xbrli:instant>'
            : '<xbrli:startDate>' . $this->escape((string)($context['start_date'] ?? '')) . '</xbrli:startDate><xbrli:endDate>' . $this->escape((string)($context['end_date'] ?? '')) . '</xbrli:endDate>';
        $dimensions = '';
        foreach ((array)($context['dimensions'] ?? []) as $dimension => $member) {
            if (!$this->validQName((string)$dimension) || !$this->validQName((string)$member)) {
                throw new \InvalidArgumentException('Invalid XBRL dimension mapping.');
            }
            $dimensions .= '<xbrldi:explicitMember dimension="' . $this->escape((string)$dimension) . '">' . $this->escape((string)$member) . '</xbrldi:explicitMember>';
        }
        foreach ((array)($context['typed_dimensions'] ?? []) as $typedDimension) {
            $typedDimension = (array)$typedDimension;
            $dimension = trim((string)($typedDimension['dimension'] ?? ''));
            $domain = trim((string)($typedDimension['domain'] ?? ''));
            $value = trim((string)($typedDimension['value'] ?? ''));
            if (!$this->validQName($dimension) || !$this->validQName($domain) || $value === '') {
                throw new \InvalidArgumentException('Invalid typed XBRL dimension mapping.');
            }
            $dimensions .= '<xbrldi:typedMember dimension="' . $this->escape($dimension) . '"><' . $domain . '>'
                . $this->escape($value) . '</' . $domain . '></xbrldi:typedMember>';
        }
        $container = trim((string)($context['dimension_container'] ?? 'scenario')) ?: 'scenario';
        if (!in_array($container, ['segment', 'scenario'], true)) {
            throw new \InvalidArgumentException('Invalid XBRL dimension container.');
        }
        $segment = $dimensions !== '' && $container === 'segment'
            ? '<xbrli:segment>' . $dimensions . '</xbrli:segment>'
            : '';
        $scenario = $dimensions !== '' && $container === 'scenario'
            ? '<xbrli:scenario>' . $dimensions . '</xbrli:scenario>'
            : '';
        return '<xbrli:context id="' . $this->escape($id) . '"><xbrli:entity><xbrli:identifier scheme="' . $this->escape((string)($context['scheme'] ?? 'http://www.companieshouse.gov.uk/')) . '">' . $this->escape($identifier) . '</xbrli:identifier>' . $segment . '</xbrli:entity><xbrli:period>' . $period . '</xbrli:period>' . $scenario . '</xbrli:context>';
    }

    private function renderUnit(array $unit): string
    {
        $id = trim((string)($unit['id'] ?? ''));
        $measure = trim((string)($unit['measure'] ?? ''));
        if ($id === '' || !$this->validQName($measure)) {
            throw new \InvalidArgumentException('An XBRL unit requires an id and QName measure.');
        }
        return '<xbrli:unit id="' . $this->escape($id) . '"><xbrli:measure>' . $this->escape($measure) . '</xbrli:measure></xbrli:unit>';
    }

    private function validQName(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*:[A-Za-z_][A-Za-z0-9_.-]*$/', $value) === 1;
    }
}
