<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApiCredentialCatalogService
{
    /** @var list<class-string<ApiCredentialCatalogProviderInterface>>|null */
    private ?array $providerClasses;

    /** @param list<class-string<ApiCredentialCatalogProviderInterface>>|null $providerClasses */
    public function __construct(?array $providerClasses = null)
    {
        $this->providerClasses = $providerClasses;
    }

    /** @return list<array{provider:string,gateway:string,tag:string,environment:string,provider_label:string,gateway_label:string,tag_label:string,environment_label:string}> */
    public function entries(): array
    {
        $entries = [];
        $byIdentity = [];

        foreach ($this->configuredProviderClasses() as $providerClass) {
            if (!class_exists($providerClass)) {
                throw new RuntimeException('Configured API credential catalog provider does not exist: ' . $providerClass . '.');
            }

            $provider = new $providerClass();
            if (!$provider instanceof ApiCredentialCatalogProviderInterface) {
                throw new RuntimeException('Configured API credential catalog provider must implement ApiCredentialCatalogProviderInterface: ' . $providerClass . '.');
            }

            foreach ($provider->credentialCatalog() as $rawEntry) {
                if (!is_array($rawEntry)) {
                    throw new RuntimeException('API credential catalog entries must be arrays.');
                }

                $entry = $this->normaliseEntry($rawEntry);
                $identity = $this->identity($entry);

                if (isset($byIdentity[$identity])) {
                    if ($byIdentity[$identity] !== $entry) {
                        throw new RuntimeException('Conflicting API credential catalog entries exist for ' . str_replace('|', ' / ', $identity) . '.');
                    }
                    continue;
                }

                $byIdentity[$identity] = $entry;
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array{provider:string,gateway:string,tag:string,environment:string} */
    public function requireAllowed(string $provider, string $gateway, string $tag, string $environment): array
    {
        $selection = [
            'provider' => $this->normaliseSelector($provider, 'provider'),
            'gateway' => $this->normaliseSelector($gateway, 'gateway'),
            'tag' => $this->normaliseSelector($tag, 'tag'),
            'environment' => $this->normaliseSelector($environment, 'environment'),
        ];

        foreach ($this->entries() as $entry) {
            if (
                $entry['provider'] === $selection['provider']
                && $entry['gateway'] === $selection['gateway']
                && $entry['tag'] === $selection['tag']
                && $entry['environment'] === $selection['environment']
            ) {
                return $selection;
            }
        }

        throw new RuntimeException('API credential selector is not permitted by the configured catalog: '
            . implode(' / ', $selection) . '.');
    }

    /** @return list<class-string<ApiCredentialCatalogProviderInterface>> */
    private function configuredProviderClasses(): array
    {
        $configured = $this->providerClasses ?? AppConfigurationStore::get('api_credentials.catalog_providers', []);
        if (!is_array($configured) || $configured === []) {
            throw new RuntimeException('No API credential catalog providers are configured.');
        }

        $classes = [];
        foreach ($configured as $providerClass) {
            $providerClass = ltrim(trim((string)$providerClass), '\\');
            if ($providerClass === '') {
                throw new RuntimeException('API credential catalog provider class names cannot be blank.');
            }
            $classes[] = $providerClass;
        }

        return array_values(array_unique($classes));
    }

    /** @param array<string, mixed> $entry @return array{provider:string,gateway:string,tag:string,environment:string,provider_label:string,gateway_label:string,tag_label:string,environment_label:string} */
    private function normaliseEntry(array $entry): array
    {
        $normalised = [
            'provider' => $this->normaliseSelector((string)($entry['provider'] ?? ''), 'provider'),
            'gateway' => $this->normaliseSelector((string)($entry['gateway'] ?? ''), 'gateway'),
            'tag' => $this->normaliseSelector((string)($entry['tag'] ?? ''), 'tag'),
            'environment' => $this->normaliseSelector((string)($entry['environment'] ?? ''), 'environment'),
        ];

        foreach (['provider', 'gateway', 'tag', 'environment'] as $field) {
            $label = trim((string)($entry[$field . '_label'] ?? $normalised[$field]));
            if ($label === '' || strlen($label) > 160 || str_contains($label, "\n") || str_contains($label, "\r")) {
                throw new RuntimeException('API credential catalog ' . $field . ' labels must be single-line text.');
            }
            $normalised[$field . '_label'] = $label;
        }

        return $normalised;
    }

    private function normaliseSelector(string $value, string $field): string
    {
        $value = strtoupper(trim($value));
        $maxLength = $field === 'tag' ? 127 : 63;
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,' . $maxLength . '}$/D', $value) !== 1) {
            throw new RuntimeException('API credential ' . $field . ' is invalid.');
        }

        return $value;
    }

    /** @param array{provider:string,gateway:string,tag:string,environment:string} $entry */
    private function identity(array $entry): string
    {
        return implode('|', [$entry['provider'], $entry['gateway'], $entry['tag'], $entry['environment']]);
    }
}
