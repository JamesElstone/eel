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
