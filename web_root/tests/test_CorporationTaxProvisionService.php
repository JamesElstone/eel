<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\CorporationTaxProvisionService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Service\CorporationTaxProvisionService::class, 'accepts optional precomputed CT period summaries', static function () use ($harness): void {
            $method = new ReflectionMethod(\eel_accounts\Service\CorporationTaxProvisionService::class, 'fetchAccountingPeriodPosition');
            $parameters = $method->getParameters();

            $harness->assertCount(3, $parameters);
            $harness->assertSame('precomputedPeriodSummaries', $parameters[2]->getName());
            $harness->assertSame(true, $parameters[2]->allowsNull());
            $harness->assertSame(true, $parameters[2]->isOptional());
        });

        $harness->check(\eel_accounts\Service\CorporationTaxProvisionService::class, 'precomputed CT summaries preserve the accounting-period provision', static function () use ($harness): void {
            if (!InterfaceDB::tableExists('corporation_tax_periods')) {
                $harness->skip('Corporation Tax periods are not available on the default InterfaceDB connection.');
            }

            $row = InterfaceDB::fetchOne(
                'SELECT company_id, accounting_period_id
                 FROM corporation_tax_periods
                 WHERE status <> :superseded_status
                 ORDER BY company_id ASC, accounting_period_id ASC, sequence_no ASC, id ASC
                 LIMIT 1',
                ['superseded_status' => 'superseded']
            );
            if (!is_array($row)) {
                $harness->skip('No Corporation Tax periods are available for provision comparison.');
            }

            $companyId = (int)$row['company_id'];
            $accountingPeriodId = (int)$row['accounting_period_id'];
            $computation = new \eel_accounts\Service\CorporationTaxComputationService();
            $provisionService = new \eel_accounts\Service\CorporationTaxProvisionService($computation);
            $summary = (new \eel_accounts\Service\YearEndTaxReadinessService(null, $computation, $provisionService))
                ->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
            if (empty($summary['available'])) {
                $harness->skip('The selected accounting period has no available CT summary.');
            }

            $fromPrecomputed = $provisionService->fetchAccountingPeriodPosition(
                $companyId,
                $accountingPeriodId,
                (array)($summary['periods'] ?? [])
            );
            $standalone = (new \eel_accounts\Service\CorporationTaxProvisionService())
                ->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);

            $harness->assertSame((bool)($standalone['available'] ?? false), (bool)($fromPrecomputed['available'] ?? false));
            foreach (['estimated_corporation_tax', 'posted_corporation_tax_charge', 'unposted_corporation_tax_adjustment', 'status'] as $field) {
                $harness->assertSame($standalone[$field] ?? null, $fromPrecomputed[$field] ?? null);
            }
            $harness->assertSame(count((array)($standalone['periods'] ?? [])), count((array)($fromPrecomputed['periods'] ?? [])));
        });
    }
);
