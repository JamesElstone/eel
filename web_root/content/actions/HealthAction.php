<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HealthAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ($request->post('intent', '') !== 'check') {
            return ActionResultFramework::none();
        }

        $companyId = (new AccountingContextService())->authCompanyId();

        return ActionResultFramework::success(
            ['settings_setup_health'],
            ['Setup health refreshed.'],
            [],
            $this->buildHealthContext($companyId)
        );
    }

    public function buildHealthContext(int $companyId): array
    {
        return (new SetupHealthService())->buildContext($companyId);
    }
}
