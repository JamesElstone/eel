<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

interface ApiCredentialCatalogProviderInterface
{
    /**
     * Return a list of allowed credential selector combinations.
     *
     * Each entry requires provider, gateway, tag, and environment. Optional
     * provider_label, gateway_label, tag_label, and environment_label values
     * are safe display text for the editor.
     *
     * @return list<array<string, mixed>>
     */
    public function credentialCatalog(): array;
}
