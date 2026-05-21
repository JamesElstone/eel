<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_import_exportCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_import_export';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);

        return '
            <div>
                <form method="post" action="?page=nominals">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="global_action" value="export_nominal_accounts">
                    <button class="button primary" type="submit">Export Nominals</button>
                </form>
            </div>

            <form method="post" action="?page=nominals">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="global_action" value="import_nominal_accounts">
                <div class="form-row">
                    <label for="nominals_import_json">Import nominals (JSON formatted)</label>
                    <input class="input" id="nominals_import_json" type="file" name="nominals_import_json" accept=".json,application/json" required>
                    <div class="helper">Import updates existing categories and accounts by code, then creates any missing ones. Nothing is deleted.</div>
                </div>
                <div>
                    <button class="button primary" type="submit">Import Nominals</button>
                </div>
            </form>
        ';
    }

}
