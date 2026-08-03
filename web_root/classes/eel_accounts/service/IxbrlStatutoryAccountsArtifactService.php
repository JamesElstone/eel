<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Resolves the HMRC-specific statutory accounts artifact. */
final class IxbrlStatutoryAccountsArtifactService
{
    private ?\Closure $ordinaryLocator;

    public function __construct(
        ?callable $ordinaryLocator = null,
        ?callable $filingKindLocator = null,
        ?callable $revisedLocator = null
    ) {
        $this->ordinaryLocator = $ordinaryLocator !== null ? \Closure::fromCallable($ordinaryLocator) : null;
    }

    public function locate(int $companyId, int $accountingPeriodId, bool $approvalPinnedOnly = false): array
    {
        $artifact = $this->ordinaryLocator !== null
            ? (array)($this->ordinaryLocator)($companyId, $accountingPeriodId)
            : (new IxbrlFilingArtifactService())->locate($companyId, $accountingPeriodId, $approvalPinnedOnly);
        if (empty($artifact['ok'])) {
            return $artifact;
        }
        return array_replace($artifact, [
            'destination' => 'hmrc_accounts',
            'authority_profile' => IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS,
        ]);
    }
}
