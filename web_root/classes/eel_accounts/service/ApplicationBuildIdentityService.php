<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Resolves the immutable producer identity recorded with filing evidence. */
final class ApplicationBuildIdentityService
{
    /** @return array{name:string,version:string,calculation_build:string} */
    public function snapshot(): array
    {
        $hmrc = \AppConfigurationStore::get('hmrc.ct600_xml', []);
        $hmrc = is_array($hmrc) ? $hmrc : [];
        $version = trim((string)\AppConfigurationStore::get(
            'app_version',
            (string)($hmrc['version'] ?? IxbrlTaxonomyProfileService::BASIS_VERSION)
        ));

        return [
            'name' => trim((string)\AppConfigurationStore::get('app_name', 'EEL Accounts')) ?: 'EEL Accounts',
            'version' => $version !== '' ? $version : IxbrlTaxonomyProfileService::BASIS_VERSION,
            'calculation_build' => TaxAuditBasisService::BASIS_VERSION,
        ];
    }
}
