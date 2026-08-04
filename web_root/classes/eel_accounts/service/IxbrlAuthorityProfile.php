<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Immutable, versioned serialization and validation policy for one iXBRL destination. */
final readonly class IxbrlAuthorityProfile
{
    /**
     * @param list<string> $allowedTransforms
     * @param array<string, mixed> $documentPolicy
     * @param array<string, mixed> $factPolicy
     */
    public function __construct(
        private string $key,
        private string $authority,
        private string $version,
        private string $transformationNamespace,
        private array $allowedTransforms,
        private array $documentPolicy,
        private array $factPolicy = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->key) !== 1) {
            throw new \InvalidArgumentException('An iXBRL authority profile key must use lowercase snake case.');
        }
        if (trim($this->authority) === '' || trim($this->version) === '') {
            throw new \InvalidArgumentException('An iXBRL authority profile requires an authority and version.');
        }
        if (filter_var($this->transformationNamespace, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('An iXBRL authority profile requires a valid transformation namespace URL.');
        }
        if ($this->allowedTransforms === []) {
            throw new \InvalidArgumentException('An iXBRL authority profile must allow at least one transformation.');
        }
        foreach ($this->allowedTransforms as $transform) {
            if (!is_string($transform) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $transform) !== 1) {
                throw new \InvalidArgumentException('An iXBRL authority profile contains an invalid transformation name.');
            }
        }
        if (!isset($this->documentPolicy['xml_declaration_mode'])) {
            throw new \InvalidArgumentException('An iXBRL authority profile requires an XML declaration policy.');
        }
        if ($this->factPolicy !== []) {
            if (trim((string)($this->factPolicy['version'] ?? '')) === '') {
                throw new \InvalidArgumentException('An iXBRL fact policy requires a version.');
            }
            $namespaces = $this->factPolicy['allowed_namespaces'] ?? null;
            if (!is_array($namespaces) || $namespaces === [] || !array_is_list($namespaces)) {
                throw new \InvalidArgumentException('An iXBRL fact policy requires allowed taxonomy namespaces.');
            }
            foreach ($namespaces as $namespace) {
                if (!is_string($namespace) || filter_var($namespace, FILTER_VALIDATE_URL) === false) {
                    throw new \InvalidArgumentException('An iXBRL fact policy contains an invalid taxonomy namespace URL.');
                }
            }
            $requiredFacts = $this->factPolicy['required_facts'] ?? null;
            if (!is_array($requiredFacts) || $requiredFacts === [] || !array_is_list($requiredFacts)) {
                throw new \InvalidArgumentException('An iXBRL fact policy requires at least one fact definition.');
            }
            foreach ($requiredFacts as $fact) {
                if (!is_array($fact)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', (string)($fact['local_name'] ?? '')) !== 1) {
                    throw new \InvalidArgumentException('An iXBRL fact policy contains an invalid fact local name.');
                }
                if (isset($fact['allowed_lexical_values'])) {
                    $values = $fact['allowed_lexical_values'];
                    if (!is_array($values) || $values === [] || !array_is_list($values)) {
                        throw new \InvalidArgumentException('An iXBRL fact policy contains an invalid lexical-value rule.');
                    }
                    foreach ($values as $value) {
                        if (!is_string($value)) {
                            throw new \InvalidArgumentException('An iXBRL fact policy lexical value must be a string.');
                        }
                    }
                }
            }
            if (isset($this->factPolicy['namespace_anchor_fact'])
                && preg_match(
                    '/^[A-Za-z_][A-Za-z0-9_.-]*$/D',
                    (string)$this->factPolicy['namespace_anchor_fact']
                ) !== 1) {
                throw new \InvalidArgumentException('An iXBRL fact policy contains an invalid namespace anchor fact.');
            }
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function authority(): string
    {
        return $this->authority;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function transformationNamespace(): string
    {
        return $this->transformationNamespace;
    }

    /** @return list<string> */
    public function allowedTransforms(): array
    {
        return array_values($this->allowedTransforms);
    }

    /** @return array<string, mixed> */
    public function documentPolicy(): array
    {
        return $this->documentPolicy;
    }

    /** @return array<string, mixed> */
    public function factPolicy(): array
    {
        return $this->factPolicy;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $profile = [
            'key' => $this->key,
            'authority' => $this->authority,
            'version' => $this->version,
            'transformation_namespace' => $this->transformationNamespace,
            'allowed_transforms' => array_values($this->allowedTransforms),
            'document_policy' => $this->documentPolicy,
        ];
        if ($this->factPolicy !== []) {
            $profile['fact_policy'] = $this->factPolicy;
        }

        return $profile;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            $this->canonicalise($this->toArray()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalise($child);
        }

        return $value;
    }
}
