<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Enforces destination policy that generic XBRL validation cannot infer. */
final class IxbrlAuthorityValidationService
{
    public function __construct(
        private readonly ?IxbrlAuthorityProfileService $profiles = null
    ) {
    }

    /**
     * @return array{
     *   ok: bool,
     *   profile: array<string, mixed>,
     *   profile_fingerprint: string,
     *   errors: list<array{code: string, message: string, location: string, details: array<string, mixed>}>
     * }
     */
    public function validate(string $xhtml, string|IxbrlAuthorityProfile $profile): array
    {
        $profile = $this->resolveProfile($profile);
        $errors = [];
        $policy = $profile->documentPolicy();

        if ($xhtml === '' || preg_match('//u', $xhtml) !== 1) {
            $errors[] = $this->error(
                'ixbrl.document.invalid_utf8',
                'The iXBRL document must contain valid UTF-8 XML.',
                '/',
                $profile
            );

            return $this->result($profile, $errors);
        }
        if (($policy['bom_forbidden'] ?? false) && str_starts_with($xhtml, "\xEF\xBB\xBF")) {
            $errors[] = $this->error(
                'ixbrl.document.bom_forbidden',
                'The authority profile does not permit a UTF-8 byte-order mark.',
                '/',
                $profile
            );
        }
        if (($policy['doctype_forbidden'] ?? false) && preg_match('/<!DOCTYPE/i', $xhtml) === 1) {
            $errors[] = $this->error(
                'ixbrl.document.doctype_forbidden',
                'The authority profile does not permit a DOCTYPE declaration.',
                '/',
                $profile
            );
        }
        if (($policy['entity_declarations_forbidden'] ?? false) && preg_match('/<!ENTITY/i', $xhtml) === 1) {
            $errors[] = $this->error(
                'ixbrl.document.entity_declaration_forbidden',
                'The authority profile does not permit entity declarations.',
                '/',
                $profile
            );
        }

        $declarationMode = (string)($policy['xml_declaration_mode'] ?? '');
        if ($declarationMode === 'forbidden' && preg_match('/\A<\?xml\b/i', $xhtml) === 1) {
            $errors[] = $this->error(
                'ixbrl.document.xml_declaration_forbidden',
                'The HMRC embedded iXBRL document must not contain an XML declaration.',
                '/',
                $profile
            );
        } elseif ($declarationMode === 'exact') {
            $requiredPrefix = (string)($policy['required_prefix'] ?? '');
            if ($requiredPrefix === '' || !str_starts_with($xhtml, $requiredPrefix)) {
                $errors[] = $this->error(
                    'ixbrl.document.xml_declaration_mismatch',
                    'The iXBRL document does not start with the exact declaration required by the authority profile.',
                    '/',
                    $profile,
                    ['required_prefix' => $requiredPrefix]
                );
            }
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT);
            $xmlErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            $first = $xmlErrors[0] ?? null;
            $errors[] = $this->error(
                'ixbrl.document.not_well_formed',
                'The iXBRL document is not well-formed XML.',
                $first instanceof \LibXMLError && $first->line > 0 ? 'line ' . $first->line : '/',
                $profile,
                ['xml_error' => $first instanceof \LibXMLError ? trim($first->message) : 'unknown XML error']
            );

            return $this->result($profile, $errors);
        }

        $root = $document->documentElement;
        if (!$root instanceof \DOMElement
            || $root->localName !== (string)($policy['root_local_name'] ?? 'html')
            || $root->namespaceURI !== (string)($policy['root_namespace'] ?? IxbrlAuthorityProfileService::XHTML_NAMESPACE)) {
            $errors[] = $this->error(
                'ixbrl.document.root_mismatch',
                'The iXBRL document root does not match the authority XHTML policy.',
                '/',
                $profile,
                [
                    'actual_local_name' => $root?->localName,
                    'actual_namespace' => $root?->namespaceURI,
                    'expected_local_name' => (string)($policy['root_local_name'] ?? 'html'),
                    'expected_namespace' => (string)($policy['root_namespace'] ?? ''),
                ]
            );
        }

        foreach ($this->validateFactPolicy($document, $profile) as $factError) {
            $errors[] = $factError;
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ix', IxbrlAuthorityProfileService::INLINE_XBRL_NAMESPACE);
        $facts = $xpath->query('//ix:nonNumeric[@format] | //ix:nonFraction[@format]');
        if ($facts !== false) {
            foreach ($facts as $fact) {
                if (!$fact instanceof \DOMElement) {
                    continue;
                }
                $format = $fact->getAttribute('format');
                $location = $fact->getNodePath() ?: '/';
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*):([A-Za-z_][A-Za-z0-9_.-]*)$/D', $format, $match) !== 1) {
                    $errors[] = $this->error(
                        'ixbrl.format.invalid_qname',
                        'An Inline XBRL format attribute is not a valid prefixed QName.',
                        $location,
                        $profile,
                        ['format' => $format]
                    );
                    continue;
                }

                $namespace = $fact->lookupNamespaceURI($match[1]);
                if ($namespace === null || $namespace === '') {
                    $errors[] = $this->error(
                        'ixbrl.format.unresolved_prefix',
                        'An Inline XBRL format prefix cannot be resolved in the document.',
                        $location,
                        $profile,
                        ['format' => $format, 'prefix' => $match[1]]
                    );
                    continue;
                }
                if (!hash_equals($profile->transformationNamespace(), $namespace)) {
                    $errors[] = $this->error(
                        'ixbrl.format.namespace_not_allowed',
                        'The transformation registry used by an Inline XBRL fact is not permitted for this authority profile.',
                        $location,
                        $profile,
                        [
                            'format' => $format,
                            'actual_namespace' => $namespace,
                            'expected_namespace' => $profile->transformationNamespace(),
                        ]
                    );
                    continue;
                }
                if (!in_array($match[2], $profile->allowedTransforms(), true)) {
                    $errors[] = $this->error(
                        'ixbrl.format.transform_not_allowed',
                        'The Inline XBRL transformation is not allowed by this authority profile.',
                        $location,
                        $profile,
                        [
                            'format' => $format,
                            'transform' => $match[2],
                            'allowed_transforms' => $profile->allowedTransforms(),
                        ]
                    );
                }
            }
        }

        return $this->result($profile, $errors);
    }

    /** @return array<string, mixed> */
    public function assertValid(string $xhtml, string|IxbrlAuthorityProfile $profile): array
    {
        $result = $this->validate($xhtml, $profile);
        if (!$result['ok']) {
            $first = $result['errors'][0];
            throw new \InvalidArgumentException((string)$first['message']);
        }

        return $result;
    }

    /**
     * @return list<array{code: string, message: string, location: string, details: array<string, mixed>}>
     */
    private function validateFactPolicy(\DOMDocument $document, IxbrlAuthorityProfile $profile): array
    {
        $policy = $profile->factPolicy();
        if ($policy === []) {
            return [];
        }

        $allowedNamespaces = array_values(array_filter(
            (array)($policy['allowed_namespaces'] ?? []),
            static fn(mixed $namespace): bool => is_string($namespace) && $namespace !== ''
        ));
        $requiredFacts = (array)($policy['required_facts'] ?? []);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ix', IxbrlAuthorityProfileService::INLINE_XBRL_NAMESPACE);
        $nodes = $xpath->query('//ix:nonNumeric[@name] | //ix:nonFraction[@name]');

        /** @var array<string, list<array{namespace: ?string, local_name: string, element: \DOMElement}>> $factsByLocalName */
        $factsByLocalName = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $name = trim($node->getAttribute('name'));
                if (preg_match('/^(?:([A-Za-z_][A-Za-z0-9_.-]*):)?([A-Za-z_][A-Za-z0-9_.-]*)$/D', $name, $match) !== 1) {
                    continue;
                }
                $prefix = (string)($match[1] ?? '');
                $localName = (string)$match[2];
                $namespace = $node->lookupNamespaceURI($prefix !== '' ? $prefix : null);
                $factsByLocalName[$localName][] = [
                    'namespace' => is_string($namespace) && $namespace !== '' ? $namespace : null,
                    'local_name' => $localName,
                    'element' => $node,
                ];
            }
        }

        $errors = [];
        $selectedNamespace = null;
        $anchorFact = (string)($policy['namespace_anchor_fact'] ?? '');
        if ($anchorFact !== '') {
            $anchorNamespaces = array_values(array_unique(array_map(
                static fn(array $candidate): string => (string)$candidate['namespace'],
                array_filter(
                    $factsByLocalName[$anchorFact] ?? [],
                    static fn(array $candidate): bool => is_string($candidate['namespace'])
                        && in_array($candidate['namespace'], $allowedNamespaces, true)
                )
            )));
            if (count($anchorNamespaces) > 1) {
                $anchorElement = $factsByLocalName[$anchorFact][0]['element'] ?? null;
                $errors[] = $this->error(
                    'ixbrl.fact.namespace_ambiguous',
                    'The iXBRL document uses more than one permitted taxonomy namespace for its namespace anchor fact.',
                    $anchorElement instanceof \DOMElement ? ($anchorElement->getNodePath() ?: '/') : '/',
                    $profile,
                    [
                        'fact_local_name' => $anchorFact,
                        'actual_namespaces' => $anchorNamespaces,
                        'allowed_namespaces' => $allowedNamespaces,
                        'fact_policy_version' => (string)($policy['version'] ?? ''),
                    ]
                );

                return $errors;
            }
            $selectedNamespace = $anchorNamespaces[0] ?? null;
        }
        foreach ($requiredFacts as $requiredFact) {
            if (!is_array($requiredFact)) {
                continue;
            }
            $localName = (string)($requiredFact['local_name'] ?? '');
            if ($localName === '') {
                continue;
            }
            $candidates = $factsByLocalName[$localName] ?? [];
            $matching = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool => is_string($candidate['namespace'])
                    && ($selectedNamespace !== null
                        ? hash_equals($selectedNamespace, $candidate['namespace'])
                        : in_array($candidate['namespace'], $allowedNamespaces, true))
            ));
            if ($matching === []) {
                if ($candidates !== []) {
                    $actualNamespaces = array_values(array_unique(array_map(
                        static fn(array $candidate): string => (string)($candidate['namespace'] ?? ''),
                        $candidates
                    )));
                    $element = $candidates[0]['element'];
                    $usesAnotherAllowedNamespace = $selectedNamespace !== null
                        && array_filter(
                            $actualNamespaces,
                            static fn(string $namespace): bool => in_array($namespace, $allowedNamespaces, true)
                                && !hash_equals($selectedNamespace, $namespace)
                        ) !== [];
                    $errors[] = $this->error(
                        $usesAnotherAllowedNamespace
                            ? 'ixbrl.fact.namespace_mismatch'
                            : 'ixbrl.fact.namespace_not_allowed',
                        $usesAnotherAllowedNamespace
                            ? 'A required iXBRL fact does not use the computation taxonomy namespace selected by the authority profile.'
                            : 'A required iXBRL fact uses a taxonomy namespace that is not permitted by this authority profile.',
                        $element->getNodePath() ?: '/',
                        $profile,
                        [
                            'fact_local_name' => $localName,
                            'actual_namespaces' => $actualNamespaces,
                            'allowed_namespaces' => $allowedNamespaces,
                            'selected_namespace' => $selectedNamespace,
                            'fact_policy_version' => (string)($policy['version'] ?? ''),
                        ]
                    );
                    continue;
                }
                $errors[] = $this->error(
                    'ixbrl.fact.required_missing',
                    'The iXBRL document is missing a fact required by this authority profile: ' . $localName . '.',
                    '/',
                    $profile,
                    [
                        'fact_local_name' => $localName,
                        'allowed_namespaces' => $allowedNamespaces,
                        'fact_policy_version' => (string)($policy['version'] ?? ''),
                    ]
                );
                continue;
            }

            $allowedLexicalValues = $requiredFact['allowed_lexical_values'] ?? null;
            foreach ($matching as $candidate) {
                $actualValue = trim($candidate['element']->textContent);
                $nilValue = strtolower(trim($candidate['element']->getAttributeNS(
                    'http://www.w3.org/2001/XMLSchema-instance',
                    'nil'
                )));
                if ($actualValue === '' || in_array($nilValue, ['true', '1'], true)) {
                    $errors[] = $this->error(
                        'ixbrl.fact.required_value_missing',
                        'A required iXBRL fact must contain a non-empty, non-nil value.',
                        $candidate['element']->getNodePath() ?: '/',
                        $profile,
                        [
                            'fact_local_name' => $localName,
                            'xsi_nil' => $nilValue,
                            'fact_policy_version' => (string)($policy['version'] ?? ''),
                        ]
                    );
                    continue;
                }
                if (!is_array($allowedLexicalValues)) {
                    continue;
                }
                if (in_array($actualValue, $allowedLexicalValues, true)) {
                    continue;
                }
                $errors[] = $this->error(
                    'ixbrl.fact.lexical_value_not_allowed',
                    'A required iXBRL fact does not contain the value permitted by this authority profile.',
                    $candidate['element']->getNodePath() ?: '/',
                    $profile,
                    [
                        'fact_local_name' => $localName,
                        'actual_value' => $actualValue,
                        'allowed_lexical_values' => array_values($allowedLexicalValues),
                        'fact_policy_version' => (string)($policy['version'] ?? ''),
                    ]
                );
            }
        }

        return $errors;
    }

    private function resolveProfile(string|IxbrlAuthorityProfile $profile): IxbrlAuthorityProfile
    {
        return is_string($profile)
            ? ($this->profiles ?? new IxbrlAuthorityProfileService())->profile($profile)
            : $profile;
    }

    /**
     * @param list<array{code: string, message: string, location: string, details: array<string, mixed>}> $errors
     * @return array<string, mixed>
     */
    private function result(IxbrlAuthorityProfile $profile, array $errors): array
    {
        return [
            'ok' => $errors === [],
            'profile' => $profile->toArray(),
            'profile_fingerprint' => $profile->fingerprint(),
            'errors' => $errors,
        ];
    }

    /** @return array{code: string, message: string, location: string, details: array<string, mixed>} */
    private function error(
        string $code,
        string $message,
        string $location,
        IxbrlAuthorityProfile $profile,
        array $details = []
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'location' => $location,
            'details' => ['profile_key' => $profile->key()] + $details,
        ];
    }
}
