<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'nominals';
    }

    public function title(): string
    {
        return 'Nominals';
    }

    public function subtitle(): string
    {
        return 'Maintain nominal accounts, subtypes, and import or export tools for the shared chart.';
    }

    public function showsTaxYearSelector(): bool
    {
        return false;
    }

    public function cards(): array
    {
        return [
            'nominals_accounts',
            'nominals_add_account',
            'nominals_categories',
            'nominals_add_category',
            'nominals_account_types',
            'nominals_import_export',
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $nominalRepository = new NominalAccountRepository();
        $subtypeRepository = new NominalSubtypeRepository();
        $editNominalId = max(0, (int)$request->input('edit_nominal_id', $request->query('edit_nominal_id', 0)));
        $editSubtypeId = max(0, (int)$request->input('edit_subtype_id', $request->query('edit_subtype_id', 0)));
        $nominalCatalog = $nominalRepository->fetchNominalAccountCatalog();
        $nominalSubtypes = $subtypeRepository->fetchNominalSubtypes();

        return [
            'nominal_account_catalog' => $nominalCatalog,
            'nominal_subtypes' => $nominalSubtypes,
            'editing_nominal' => $this->findRowById($nominalCatalog, $editNominalId),
            'editing_subtype' => $this->findRowById($nominalSubtypes, $editSubtypeId),
        ];
    }

    private function findRowById(array $rows, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }
}
