<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Catalog of explicitly supported authority-specific iXBRL policies. */
final class IxbrlAuthorityProfileService
{
    public const HMRC_CT_ACCOUNTS = 'hmrc_ct_accounts';
    public const HMRC_CT_COMPUTATION = 'hmrc_ct_computation';
    public const COMPANIES_HOUSE_ACCOUNTS = 'companies_house_accounts';

    public const TRANSFORMATION_REGISTRY_2011 =
        'http://www.xbrl.org/inlineXBRL/transformation/2011-07-31';
    public const TRANSFORMATION_REGISTRY_2015 =
        'http://www.xbrl.org/inlineXBRL/transformation/2015-02-26';

    public const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
    public const INLINE_XBRL_NAMESPACE = 'http://www.xbrl.org/2013/inlineXBRL';

    private const HMRC_CT_ACCOUNTS_VERSION = '1.0.0';
    private const HMRC_CT_COMPUTATION_VERSION = '1.1.0';
    private const COMPANIES_HOUSE_ACCOUNTS_VERSION = '1.0.0';
    private const SUPPORTED_TRANSFORMS = [
        'numdotdecimal',
        'datedaymonthyearen',
        'dateyearmonthday',
        'zerodash',
    ];

    /** @var array<string, IxbrlAuthorityProfile>|null */
    private ?array $profiles = null;

    public function profile(string $key): IxbrlAuthorityProfile
    {
        $key = strtolower(trim($key));
        $profiles = $this->profiles();
        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException('Unsupported iXBRL authority profile: ' . $key);
        }

        return $profiles[$key];
    }

    /** @return array<string, IxbrlAuthorityProfile> */
    public function all(): array
    {
        return $this->profiles();
    }

    /** @return array<string, IxbrlAuthorityProfile> */
    private function profiles(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $hmrcDocumentPolicy = [
            'xml_declaration_mode' => 'exact',
            'document_prefix' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n",
            'required_prefix' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n",
            'utf8_required' => true,
            'bom_allowed' => false,
            'bom_forbidden' => true,
            'doctype_allowed' => false,
            'doctype_forbidden' => true,
            'entity_declarations_allowed' => false,
            'entity_declarations_forbidden' => true,
            'root_local_name' => 'html',
            'root_namespace' => self::XHTML_NAMESPACE,
            'embedded_document' => true,
        ];
        $companiesHouseDocumentPolicy = [
            'xml_declaration_mode' => 'exact',
            'document_prefix' => '<?xml version="1.0"?>' . "\n",
            'required_prefix' => '<?xml version="1.0"?>' . "\n",
            'utf8_required' => true,
            'bom_allowed' => false,
            'bom_forbidden' => true,
            'doctype_allowed' => false,
            'doctype_forbidden' => true,
            'entity_declarations_allowed' => false,
            'entity_declarations_forbidden' => true,
            'root_local_name' => 'html',
            'root_namespace' => self::XHTML_NAMESPACE,
            'embedded_document' => false,
        ];
        $hmrcComputationFactPolicy = [
            'version' => 'hmrc-ct-computation-mandatory-facts-v1',
            'allowed_namespaces' => [
                'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                'http://www.hmrc.gov.uk/schemas/ct/comp/2025-01-01',
            ],
            'namespace_anchor_fact' => 'CompanyName',
            'required_facts' => [
                ['local_name' => 'CompanyName'],
                ['local_name' => 'TaxReference'],
                ['local_name' => 'PeriodOfAccountStartDate'],
                ['local_name' => 'PeriodOfAccountEndDate'],
                ['local_name' => 'StartOfPeriodCoveredByReturn'],
                ['local_name' => 'EndOfPeriodCoveredByReturn'],
                [
                    'local_name' => 'CompanyIsAPartnerInAFirm',
                    'allowed_lexical_values' => ['false'],
                ],
            ],
        ];

        return $this->profiles = [
            self::HMRC_CT_ACCOUNTS => new IxbrlAuthorityProfile(
                self::HMRC_CT_ACCOUNTS,
                'HMRC',
                self::HMRC_CT_ACCOUNTS_VERSION,
                self::TRANSFORMATION_REGISTRY_2011,
                self::SUPPORTED_TRANSFORMS,
                $hmrcDocumentPolicy
            ),
            self::HMRC_CT_COMPUTATION => new IxbrlAuthorityProfile(
                self::HMRC_CT_COMPUTATION,
                'HMRC',
                self::HMRC_CT_COMPUTATION_VERSION,
                self::TRANSFORMATION_REGISTRY_2011,
                self::SUPPORTED_TRANSFORMS,
                $hmrcDocumentPolicy,
                $hmrcComputationFactPolicy
            ),
            self::COMPANIES_HOUSE_ACCOUNTS => new IxbrlAuthorityProfile(
                self::COMPANIES_HOUSE_ACCOUNTS,
                'COMPANIES_HOUSE',
                self::COMPANIES_HOUSE_ACCOUNTS_VERSION,
                self::TRANSFORMATION_REGISTRY_2015,
                self::SUPPORTED_TRANSFORMS,
                $companiesHouseDocumentPolicy
            ),
        ];
    }
}
