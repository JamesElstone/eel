<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_import_exportCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'nominals_import_export';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $pageUrl = $this->buildPageUrl('nominals', ['company_id' => $selectedCompanyId]);

        return '<section class="eel-card-fragment" data-card="nominals-import-export">
            <div class="card nominals-import-export">
                <div class="card-header">
                    <h2 class="card-title">Import / Export Nominals</h2>
                </div>
                <div class="card-body">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
                        <form method="post" action="' . HelperFramework::escape($pageUrl) . '">
                            <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                            <input type="hidden" name="global_action" value="export_nominal_accounts">
                            <button class="button primary" type="submit">Export Nominals</button>
                        </form>
                    </div>

                    <form method="post" action="' . HelperFramework::escape($pageUrl) . '" enctype="multipart/form-data" data-ajax-card-form="true" data-ajax-card-update="nominals-categories,nominals-accounts,nominals-import-export,nominals-add-category,nominals-add-account">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="global_action" value="import_nominal_accounts">
                        <div class="form-row">
                            <label for="nominals_import_json">Import nominals (JSON formatted)</label>
                            <input class="input" id="nominals_import_json" type="file" name="nominals_import_json" accept=".json,application/json" required>
                            <div class="helper">Import updates existing categories and accounts by code, then creates any missing ones. Nothing is deleted.</div>
                        </div>
                        <div style="margin-top: 12px;">
                            <button class="button primary" type="submit">Import Nominals</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
