<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\YearEndTaxFreezeService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndTaxFreezeService $service): void {
        $harness->check(\eel_accounts\Service\YearEndTaxFreezeService::class, 'builds a deterministic calculation-only manifest', static function () use ($harness, $service): void {
            $first = yearEndTaxFreezePeriods();
            $second = array_reverse($first);
            $second[0]['filing_identity'] = ['utr' => 'future-filing-only-value'];
            $second[0]['warnings'] = ['A filing-stage warning that is not a hard diagnostic.'];

            $left = $service->build(49, 79, $first, [], 2);
            $right = $service->build(49, 79, $second, [], 2);

            $harness->assertSame('ready_for_approval', (string)($left['freeze_status'] ?? ''));
            $harness->assertSame((string)($left['freeze_manifest_hash'] ?? ''), (string)($right['freeze_manifest_hash'] ?? 'different'));
            $harness->assertSame(2, count((array)(($left['freeze_manifest'] ?? [])['periods'] ?? [])));
            $harness->assertSame(false, array_key_exists('filing_identity', (array)(($left['freeze_manifest'] ?? [])['periods'][0] ?? [])));
        });

        $harness->check(\eel_accounts\Service\YearEndTaxFreezeService::class, 'changes the approval hash when an amount-affecting fact changes', static function () use ($harness, $service): void {
            $periods = yearEndTaxFreezePeriods();
            $initial = $service->build(49, 79, $periods, [], 2);
            $periods[1]['capital_allowances'] = 25.00;
            $changed = $service->build(49, 79, $periods, [], 2);

            $harness->assertTrue((string)($initial['freeze_manifest_hash'] ?? '') !== (string)($changed['freeze_manifest_hash'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\YearEndTaxFreezeService::class, 'blocks approval for amount-affecting diagnostics and missing CT computations', static function () use ($harness, $service): void {
            $periods = yearEndTaxFreezePeriods();
            $periods[0]['hard_gate_diagnostics'] = [[
                'code' => 'nominal_unknown_treatment',
                'category' => 'nominal_treatment',
                'amount_affecting' => true,
                'message' => 'Resolve the unknown tax treatment.',
                'workflow_page' => 'nominals',
            ]];
            $blocked = $service->build(49, 79, [$periods[0]], [], 2);

            $harness->assertSame('blocked', (string)($blocked['freeze_status'] ?? ''));
            $harness->assertSame(2, (int)($blocked['blocking_diagnostic_count'] ?? 0));
            $harness->assertSame(null, $service->approvalBasis($blocked));
            $harness->assertTrue(in_array('nominal_unknown_treatment', array_column((array)($blocked['blocking_diagnostics'] ?? []), 'code'), true));
            $harness->assertTrue(in_array('ct_period_computation_count', array_column((array)($blocked['blocking_diagnostics'] ?? []), 'code'), true));
        });

        $harness->check(\eel_accounts\Service\YearEndTaxFreezeService::class, 'produces an acknowledgement basis only for a ready calculation', static function () use ($harness, $service): void {
            $ready = $service->build(49, 79, yearEndTaxFreezePeriods(), [], 2);
            $basis = $service->approvalBasis($ready);

            $harness->assertSame('tax_readiness_acknowledgement', (string)($basis['check_code'] ?? ''));
            $harness->assertSame(\eel_accounts\Service\YearEndTaxFreezeService::BASIS_VERSION, (string)(($basis['freeze_manifest'] ?? [])['basis_version'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\YearEndTaxFreezeService::class, 'makes approval stale when the calculation basis changes', static function () use ($harness, $service): void {
            $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
            $initial = $service->build(49, 79, yearEndTaxFreezePeriods(), [], 2);
            $initialBasis = (array)$service->approvalBasis($initial);
            $stored = [
                'basis_version' => \eel_accounts\Service\YearEndAcknowledgementService::BASIS_VERSION,
                'basis_hash' => $acknowledgements->hashBasis($initialBasis),
            ];
            $periods = yearEndTaxFreezePeriods();
            $periods[1]['estimated_corporation_tax'] = 1.00;
            $changedBasis = $service->approvalBasis($service->build(49, 79, $periods, [], 2));

            $harness->assertSame(true, (bool)($acknowledgements->evaluate($stored, $initialBasis)['current'] ?? false));
            $harness->assertSame(false, (bool)($acknowledgements->evaluate($stored, $changedBasis)['current'] ?? true));
        });
    }
);

/** @return list<array<string, mixed>> */
function yearEndTaxFreezePeriods(): array
{
    return [
        [
            'available' => true,
            'ct_period_id' => 6,
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
            'accounting_profit' => -118.66,
            'depreciation_add_back' => 184.28,
            'capital_allowances' => 628.84,
            'taxable_before_losses' => -563.22,
            'loss_created_in_period' => 563.22,
            'losses_carried_forward' => 563.22,
            'taxable_profit' => 0,
            'associated_company_count' => 0,
            'ordinary_corporation_tax' => 0,
            's455_tax' => 0,
            'estimated_corporation_tax' => 0,
            'prepayment_preview_reliable' => true,
            'accounting_allocation_basis' => ['method' => 'inclusive_day_apportionment'],
            'capital_allowance_breakdown' => [['asset_id' => 101, 'allowance_amount' => 628.84]],
            'ct_rate_bands' => [],
            'hard_gate_diagnostics' => [],
        ],
        [
            'available' => true,
            'ct_period_id' => 7,
            'period_start' => '2023-09-05',
            'period_end' => '2023-09-30',
            'accounting_profit' => -8.45,
            'depreciation_add_back' => 13.13,
            'capital_allowances' => 0,
            'taxable_before_losses' => 4.68,
            'losses_brought_forward' => 563.22,
            'losses_used' => 4.68,
            'losses_carried_forward' => 558.54,
            'taxable_profit' => 0,
            'associated_company_count' => 0,
            'ordinary_corporation_tax' => 0,
            's455_tax' => 0,
            'estimated_corporation_tax' => 0,
            'prepayment_preview_reliable' => true,
            'accounting_allocation_basis' => ['method' => 'inclusive_day_apportionment'],
            'capital_allowance_breakdown' => [],
            'ct_rate_bands' => [],
            'hard_gate_diagnostics' => [],
        ],
    ];
}
