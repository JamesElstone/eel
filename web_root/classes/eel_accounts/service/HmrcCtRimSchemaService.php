<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimSchemaService
{
    private const APPLICABILITY_XPATH = 'CompanyTaxReturn/CompanyInformation/PeriodCovered/From/minInclusive/@value';

    public function catalogueValidationFiles(int $packageId, string $directory): array
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_rim_files')) { return []; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $type = strtolower($file->getExtension());
            if (!$file->isFile() || !in_array($type, ['xsd', 'sch', 'xslt'], true)) { continue; }
            $path = $file->getPathname();
            $archivePath = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($path, strlen(rtrim($directory, '\\/'))), '\\/'));
            $role = $type === 'sch' ? 'schematron' : ($type === 'xslt' ? 'transform' : (stripos($archivePath, 'envelope') !== false ? 'envelope_schema' : null));
            \InterfaceDB::prepareExecute('INSERT INTO hmrc_ct_rim_files (package_id, archive_path, extracted_path, file_type, file_size, sha256, file_role) VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_size, :sha256, :file_role) ON DUPLICATE KEY UPDATE extracted_path = VALUES(extracted_path), file_type = VALUES(file_type), file_size = VALUES(file_size), sha256 = VALUES(sha256), file_role = COALESCE(VALUES(file_role), file_role)', [
                'package_id' => $packageId,
                'archive_path' => $archivePath,
                'extracted_path' => $path,
                'file_type' => $type,
                'file_size' => (int)$file->getSize(),
                'sha256' => (string)(hash_file('sha256', $path) ?: ''),
                'file_role' => $role,
            ]);
        }
        $files = \InterfaceDB::fetchAll('SELECT * FROM hmrc_ct_rim_files WHERE package_id = :package_id ORDER BY archive_path ASC', ['package_id' => $packageId]);
        $this->catalogueComponents($packageId, $files);
        return $files;
    }

    public function analyseApplicability(int $packageId, string $directory, string $formVersion): array
    {
        $files = $this->catalogueValidationFiles($packageId, $directory);
        $candidates = [];
        foreach ($files as $file) {
            if ((string)($file['file_type'] ?? '') !== 'xsd' || preg_match('~(^|[\\/])(diffs?|old|previous)([\\/]|$)~i', (string)$file['archive_path']) === 1) { continue; }
            $xml = @simplexml_load_file((string)$file['extracted_path']);
            if (!$xml instanceof \SimpleXMLElement || !$xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]')) { continue; }
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_files SET file_role = \'primary_schema\' WHERE id = :id', ['id' => (int)$file['id']]);
            $dates = $xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]//*[local-name()="element" and @name="CompanyInformation"]//*[local-name()="element" and @name="PeriodCovered"]//*[local-name()="element" and @name="From"]//*[local-name()="minInclusive"]/@value');
            $date = count((array)$dates) === 1 ? trim((string)$dates[0]) : null;
            $candidates[] = ['file_id' => (int)$file['id'], 'date' => $this->isDate($date) ? $date : null, 'score' => stripos(basename((string)$file['archive_path']), 'CT-') === 0 ? 10 : 0];
        }
        if ($candidates === []) {
            return ['status' => 'failed', 'applicable_from' => null, 'source_file_id' => null, 'xpath' => null, 'error' => 'The HMRC CT600 primary XSD was not found.'];
        }
        $dated = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['date'] !== null));
        if ($dated === []) {
            $best = $candidates[0];
            return strtoupper(trim($formVersion)) === 'V2'
                ? ['status' => 'open_start', 'applicable_from' => null, 'source_file_id' => $best['file_id'], 'xpath' => null]
                : ['status' => 'failed', 'applicable_from' => null, 'source_file_id' => $best['file_id'], 'xpath' => null, 'error' => 'The HMRC CT600 primary XSD did not expose an applicability date.'];
        }
        usort($dated, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $best = $dated[0];
        foreach ($dated as $candidate) {
            if ($candidate['score'] === $best['score'] && $candidate['date'] !== $dated[0]['date']) {
                return ['status' => 'ambiguous', 'applicable_from' => null, 'source_file_id' => null, 'xpath' => self::APPLICABILITY_XPATH, 'error' => 'The HMRC CT600 XSD applicability date was ambiguous.'];
            }
        }
        return ['status' => 'confirmed', 'applicable_from' => $dated[0]['date'], 'source_file_id' => $dated[0]['file_id'], 'xpath' => self::APPLICABILITY_XPATH];
    }

    public function applyApplicability(int $packageId, string $directory, string $formVersion): array
    {
        $result = $this->analyseApplicability($packageId, $directory, $formVersion);
        \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET applicable_from = :applicable_from, applicability_source_file_id = :source_file_id, applicability_xpath = :xpath, applicability_extracted_at = :extracted_at, applicability_status = :status WHERE id = :id', [
            'applicable_from' => $result['applicable_from'] ?? null,
            'source_file_id' => $result['source_file_id'] ?? null,
            'xpath' => $result['xpath'] ?? null,
            'extracted_at' => gmdate('Y-m-d H:i:s'),
            'status' => $result['status'],
            'id' => $packageId,
        ]);
        return $result;
    }

    public function recalculateWindows(): void
    {
        $rows = \InterfaceDB::fetchAll('SELECT form_version, MIN(applicable_from) AS applicable_from FROM hmrc_ct_rim_packages WHERE applicability_status IN (\'confirmed\', \'open_start\') GROUP BY form_version ORDER BY (applicable_from IS NOT NULL) ASC, applicable_from ASC, form_version ASC');
        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1]['applicable_from'] ?? null;
            $to = $next !== null ? (new \DateTimeImmutable((string)$next))->modify('-1 day')->format('Y-m-d') : null;
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET applicable_to = :applicable_to WHERE form_version = :form_version', ['applicable_to' => $to, 'form_version' => (string)$row['form_version']]);
        }
    }

    private function isDate(?string $value): bool
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) { return false; }
        try { return (new \DateTimeImmutable($value))->format('Y-m-d') === $value; } catch (\Throwable) { return false; }
    }

    private function catalogueComponents(int $packageId, array $files): void
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_rim_components')) { return; }
        \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_rim_components WHERE package_id = :package_id', ['package_id' => $packageId]);
        $components = $this->inspectSchemaFiles(array_values(array_filter(
            $files,
            static fn(array $file): bool => (string)($file['file_type'] ?? '') === 'xsd'
        )));
        $hasMetadata = \InterfaceDB::columnsExists('hmrc_ct_rim_components', [
            'parent_path', 'sequence_order', 'is_leaf', 'source_file_id',
        ]);
        foreach ($components as $component) {
            if ($hasMetadata) {
                \InterfaceDB::prepareExecute(
                    'INSERT IGNORE INTO hmrc_ct_rim_components
                     (package_id, component_path, parent_path, element_name, namespace_uri, data_type,
                      min_occurs, max_occurs, is_required, sequence_order, is_leaf, source_file_id)
                     VALUES (:package_id, :component_path, :parent_path, :element_name, :namespace_uri, :data_type,
                             :min_occurs, :max_occurs, :is_required, :sequence_order, :is_leaf, :source_file_id)',
                    ['package_id' => $packageId] + $component
                );
                continue;
            }
            \InterfaceDB::prepareExecute(
                'INSERT IGNORE INTO hmrc_ct_rim_components
                 (package_id, component_path, element_name, namespace_uri, data_type, min_occurs, max_occurs, is_required)
                 VALUES (:package_id, :component_path, :element_name, :namespace_uri, :data_type, :min_occurs, :max_occurs, :is_required)',
                ['package_id' => $packageId] + $component
            );
        }

        if (\InterfaceDB::tableExists('ct_filing_mapping_profiles')) {
            (new CtFilingMappingService())->prepareMappingsForPackage(
                CtFilingMappingService::TARGET_RIM,
                $packageId,
                'hmrc-rim-catalogue'
            );
        }
    }

    /**
     * Inspect one XSD without database access. This is also useful to review an
     * HMRC artefact before it is admitted to the package catalogue.
     *
     * @return list<array<string, int|string|null>>
     */
    public function inspectSchemaFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('The RIM schema file was not found.');
        }
        return $this->inspectSchemaFiles([[
            'id' => null,
            'archive_path' => basename($path),
            'extracted_path' => $path,
            'file_type' => 'xsd',
        ]]);
    }

    /** @return list<array<string, int|string|null>> */
    private function inspectSchemaFiles(array $files): array
    {
        $previous = libxml_use_internal_errors(true);
        $schemas = [];
        $elements = [];
        $types = [];
        $groups = [];
        try {
            foreach ($files as $file) {
                $document = new \DOMDocument();
                if (!$document->load((string)($file['extracted_path'] ?? ''), LIBXML_NONET)) {
                    libxml_clear_errors();
                    continue;
                }
                $schema = $document->documentElement;
                if (!$schema instanceof \DOMElement
                    || $schema->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') {
                    continue;
                }
                $xpath = new \DOMXPath($document);
                $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
                $context = [
                    'document' => $document,
                    'schema' => $schema,
                    'xpath' => $xpath,
                    'namespace' => $schema->getAttribute('targetNamespace'),
                    'file_id' => isset($file['id']) ? (int)$file['id'] : null,
                ];
                $schemas[] = $context;
                foreach ($xpath->query('/xs:schema/xs:element[@name]') ?: [] as $node) {
                    if ($node instanceof \DOMElement) {
                        $elements[$this->schemaKey($context['namespace'], $node->getAttribute('name'))] = [$node, $context];
                    }
                }
                foreach ($xpath->query('/xs:schema/xs:complexType[@name] | /xs:schema/xs:simpleType[@name]') ?: [] as $node) {
                    if ($node instanceof \DOMElement) {
                        $types[$this->schemaKey($context['namespace'], $node->getAttribute('name'))] = [$node, $context];
                    }
                }
                foreach ($xpath->query('/xs:schema/xs:group[@name]') ?: [] as $node) {
                    if ($node instanceof \DOMElement) {
                        $groups[$this->schemaKey($context['namespace'], $node->getAttribute('name'))] = [$node, $context];
                    }
                }
            }

            $indexes = ['elements' => $elements, 'types' => $types, 'groups' => $groups];
            $rows = [];
            $order = 0;
            foreach ($schemas as $context) {
                foreach ($context['xpath']->query('/xs:schema/xs:element[@name]') ?: [] as $root) {
                    if (!$root instanceof \DOMElement) { continue; }
                    $this->walkElement($root, $context, '', true, $indexes, [], $rows, $order);
                }
            }
            return array_values($rows);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function walkElement(
        \DOMElement $declaration,
        array $context,
        string $parentPath,
        bool $ancestorsRequired,
        array $indexes,
        array $typeStack,
        array &$rows,
        int &$order
    ): void {
        $effective = $declaration;
        if ($declaration->hasAttribute('ref')) {
            $reference = $this->resolveQName($declaration->getAttribute('ref'), $declaration, $context['namespace']);
            $resolved = $indexes['elements'][$this->schemaKey($reference['namespace'], $reference['local'])] ?? null;
            if (is_array($resolved)) {
                [$effective, $context] = $resolved;
            }
        }
        $name = $declaration->getAttribute('name') ?: $effective->getAttribute('name');
        if ($name === '') { return; }
        $path = $parentPath === '' ? $name : $parentPath . '/' . $name;
        if (isset($rows[$path])) { return; }
        $min = $declaration->hasAttribute('minOccurs') ? max(0, (int)$declaration->getAttribute('minOccurs')) : 1;
        $max = $declaration->getAttribute('maxOccurs') ?: '1';
        $type = $this->elementType($effective, $context, $indexes);
        [$children, $childStack] = $this->elementChildren($effective, $context, $indexes, $typeStack);
        $required = $ancestorsRequired && $min > 0;
        $rows[$path] = [
            'component_path' => $path,
            'parent_path' => $parentPath !== '' ? $parentPath : null,
            'element_name' => $name,
            'namespace_uri' => $context['namespace'] !== '' ? $context['namespace'] : null,
            'data_type' => $type,
            'min_occurs' => $min,
            'max_occurs' => $max,
            'is_required' => $required ? 1 : 0,
            'sequence_order' => ++$order,
            'is_leaf' => $children === [] ? 1 : 0,
            'source_file_id' => $context['file_id'],
        ];
        foreach ($children as [$child, $childContext, $branchRequired]) {
            $this->walkElement($child, $childContext, $path, $required && $branchRequired, $indexes, $childStack, $rows, $order);
        }
    }

    private function elementType(\DOMElement $element, array $context, array $indexes): ?string
    {
        $declared = trim($element->getAttribute('type'));
        if ($declared !== '') { return $declared; }
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') { continue; }
            if ($child->localName === 'simpleType') {
                return $this->simpleTypeBase($child);
            }
            if ($child->localName === 'complexType') {
                foreach ($child->childNodes as $contentContainer) {
                    if (!$contentContainer instanceof \DOMElement
                        || $contentContainer->namespaceURI !== 'http://www.w3.org/2001/XMLSchema'
                        || !in_array($contentContainer->localName, ['simpleContent', 'complexContent'], true)) {
                        continue;
                    }
                    foreach ($contentContainer->childNodes as $content) {
                        if ($content instanceof \DOMElement
                            && $content->namespaceURI === 'http://www.w3.org/2001/XMLSchema'
                            && in_array($content->localName, ['extension', 'restriction'], true)
                            && $content->hasAttribute('base')) {
                            return $content->getAttribute('base');
                        }
                    }
                }
            }
        }
        return null;
    }

    private function simpleTypeBase(\DOMElement $simpleType): ?string
    {
        foreach ($simpleType->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') { continue; }
            if ($child->hasAttribute('base')) { return $child->getAttribute('base'); }
            if ($child->hasAttribute('itemType')) { return $child->getAttribute('itemType'); }
            if ($child->hasAttribute('memberTypes')) { return $child->getAttribute('memberTypes'); }
        }
        return null;
    }

    /** @return array{0:list<array{0:\DOMElement,1:array,2:bool}>,1:array} */
    private function elementChildren(\DOMElement $element, array $context, array $indexes, array $typeStack): array
    {
        $typeNode = null;
        $typeContext = $context;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->namespaceURI === 'http://www.w3.org/2001/XMLSchema'
                && $child->localName === 'complexType') {
                $typeNode = $child;
                break;
            }
        }
        $declaredType = trim($element->getAttribute('type'));
        if ($typeNode === null && $declaredType !== '') {
            $qname = $this->resolveQName($declaredType, $element, $context['namespace']);
            $key = $this->schemaKey($qname['namespace'], $qname['local']);
            $resolved = $indexes['types'][$key] ?? null;
            if (is_array($resolved) && !isset($typeStack[$key])) {
                [$typeNode, $typeContext] = $resolved;
                $typeStack[$key] = true;
            }
        }
        if (!$typeNode instanceof \DOMElement || $typeNode->localName !== 'complexType') {
            return [[], $typeStack];
        }
        return [$this->particleElements($typeNode, $typeContext, $indexes, $typeStack), $typeStack];
    }

    /** @return list<array{0:\DOMElement,1:array,2:bool}> */
    private function particleElements(
        \DOMElement $node,
        array $context,
        array $indexes,
        array $typeStack,
        bool $branchRequired = true
    ): array
    {
        $rows = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') { continue; }
            if ($child->localName === 'element') {
                $rows[] = [$child, $context, $branchRequired];
                continue;
            }
            if (in_array($child->localName, ['sequence', 'choice', 'all', 'complexContent', 'simpleContent', 'extension', 'restriction'], true)) {
                $particleRequired = $branchRequired
                    && (!$child->hasAttribute('minOccurs') || (int)$child->getAttribute('minOccurs') > 0);
                if (in_array($child->localName, ['extension', 'restriction'], true) && $child->hasAttribute('base')) {
                    $base = $this->resolveQName($child->getAttribute('base'), $child, $context['namespace']);
                    $key = $this->schemaKey($base['namespace'], $base['local']);
                    $resolved = $indexes['types'][$key] ?? null;
                    if (is_array($resolved) && !isset($typeStack[$key])) {
                        [$baseNode, $baseContext] = $resolved;
                        $nextStack = $typeStack;
                        $nextStack[$key] = true;
                        $rows = array_merge($rows, $this->particleElements($baseNode, $baseContext, $indexes, $nextStack, $particleRequired));
                    }
                }
                // A required choice requires one branch, not every branch.
                $childBranchesRequired = $child->localName === 'choice' ? false : $particleRequired;
                $rows = array_merge($rows, $this->particleElements($child, $context, $indexes, $typeStack, $childBranchesRequired));
                continue;
            }
            if ($child->localName === 'group' && $child->hasAttribute('ref')) {
                $group = $this->resolveQName($child->getAttribute('ref'), $child, $context['namespace']);
                $resolved = $indexes['groups'][$this->schemaKey($group['namespace'], $group['local'])] ?? null;
                if (is_array($resolved)) {
                    [$groupNode, $groupContext] = $resolved;
                    $groupRequired = $branchRequired
                        && (!$child->hasAttribute('minOccurs') || (int)$child->getAttribute('minOccurs') > 0);
                    $rows = array_merge($rows, $this->particleElements($groupNode, $groupContext, $indexes, $typeStack, $groupRequired));
                }
            }
        }
        return $rows;
    }

    /** @return array{namespace:string,local:string} */
    private function resolveQName(string $qname, \DOMElement $node, string $defaultNamespace): array
    {
        $parts = explode(':', trim($qname), 2);
        if (count($parts) === 2) {
            return ['namespace' => (string)($node->lookupNamespaceURI($parts[0]) ?? ''), 'local' => $parts[1]];
        }
        return ['namespace' => $defaultNamespace, 'local' => $parts[0]];
    }

    private function schemaKey(string $namespace, string $localName): string
    {
        return $namespace . '|' . $localName;
    }
}
