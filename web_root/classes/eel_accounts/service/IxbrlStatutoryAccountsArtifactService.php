<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Resolves the one accounts artifact that is valid for each statutory filing destination. */
final class IxbrlStatutoryAccountsArtifactService
{
    private ?\Closure $ordinaryLocator;
    private ?\Closure $filingKindLocator;
    private ?\Closure $revisedLocator;

    public function __construct(
        ?callable $ordinaryLocator = null,
        ?callable $filingKindLocator = null,
        ?callable $revisedLocator = null
    ) {
        $this->ordinaryLocator = $ordinaryLocator !== null ? \Closure::fromCallable($ordinaryLocator) : null;
        $this->filingKindLocator = $filingKindLocator !== null ? \Closure::fromCallable($filingKindLocator) : null;
        $this->revisedLocator = $revisedLocator !== null ? \Closure::fromCallable($revisedLocator) : null;
    }

    public function locate(int $companyId, int $accountingPeriodId, bool $approvalPinnedOnly = false): array
    {
        $ordinary = $this->ordinaryLocator !== null
            ? (array)($this->ordinaryLocator)($companyId, $accountingPeriodId)
            : (new IxbrlFilingArtifactService())->locate($companyId, $accountingPeriodId, $approvalPinnedOnly);
        if (empty($ordinary['ok'])) {
            return $ordinary;
        }
        $filingKind = $this->filingKindLocator !== null
            ? (string)($this->filingKindLocator)($companyId, $accountingPeriodId)
            : (string)((new CompaniesHouseAccountsSubmissionService())
                ->fetchContext($companyId, $accountingPeriodId)['filing_kind'] ?? 'original');
        if ($filingKind !== 'revised') {
            return $ordinary;
        }
        $revised = $this->revisedLocator !== null
            ? (array)($this->revisedLocator)($companyId, $accountingPeriodId)
            : (new IxbrlArtifactDownloadService())->revisedAccounts($companyId, $accountingPeriodId);
        if (empty($revised['ok'])) {
            return $revised;
        }
        return array_replace($ordinary, $revised, [
            'basis_hash' => (string)($ordinary['basis_hash'] ?? ''),
            'filing_approval_id' => (int)($ordinary['filing_approval_id'] ?? 0),
            'destination' => 'shared_revised_accounts',
        ]);
    }
}
