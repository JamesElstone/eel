<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CtPeriodFilingModelService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\CtPeriodFilingModelService $service
    ): void {
        $h->check($service::class, 'fails closed without a complete CT context', static function () use ($h, $service): void {
            $result = $service->build(0, 0, 0);
            $h->assertSame(false, $result['available']);
        });

        $h->check($service::class, 'consumes one approved locked CT period without writing duplicate evidence', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $before = ctPeriodFilingModelEvidenceCounts();
            $result = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $after = ctPeriodFilingModelEvidenceCounts();

            $h->assertSame(true, (bool)($result['available'] ?? false));
            $h->assertSame($before, $after);
            $h->assertSame('tax_readiness_acknowledgement', (string)($result['approval']['check_code'] ?? ''));
            $h->assertSame($fixture['manifest_hash'], (string)($result['approval']['freeze_manifest_hash'] ?? ''));
            $h->assertSame(true, (bool)($result['supported_return_profile']['supported'] ?? false));
            $h->assertSame(true, (bool)($result['model']['supported_return_profile']['ordinary_trading_company_confirmed'] ?? false));
            $h->assertSame(true, (bool)($result['facts']['supported_return_profile.supported'] ?? false));
            $h->assertSame([], (array)($result['blocking_diagnostics'] ?? []));
            $h->assertSame([], (array)($result['warning_diagnostics'] ?? []));
        });

        $h->check($service::class, 'selects either CT period from one accounting-period approval', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture(2);
            foreach ($fixture['ct_period_ids'] as $ctPeriodId) {
                $result = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $ctPeriodId);
                $h->assertSame(true, (bool)($result['available'] ?? false));
                $h->assertSame($ctPeriodId, (int)($result['approval']['approved_ct_period_id'] ?? 0));
                $h->assertSame($fixture['manifest_hash'], (string)($result['approval']['freeze_manifest_hash'] ?? ''));
            }
        });

        $h->check($service::class, 'uses frozen diagnostics and does not rerun current tax hard gates', static function () use ($h, $service): void {
            $warning = 'Frozen filing-stage warning retained for review.';
            $fixture = ctPeriodFilingModelFixture(1, [$warning], [], ['unknown_treatment_amount' => 100.00]);
            $first = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $second = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);

            $h->assertSame(true, (bool)($first['available'] ?? false));
            $h->assertSame([], (array)($first['blocking_diagnostics'] ?? []));
            $h->assertSame($warning, (string)($first['warning_diagnostics'][0]['message'] ?? ''));
            $h->assertTrue(str_starts_with((string)($first['warning_diagnostics'][0]['code'] ?? ''), 'frozen_warning_'));
            $h->assertSame((string)$first['basis_hash'], (string)$second['basis_hash']);
        });

        $h->check($service::class, 'preserves an unexpected frozen blocker and rejects the approved model', static function () use ($h, $service): void {
            $blocker = [
                'code' => 'loss_brought_forward_continuity',
                'category' => 'loss',
                'severity' => 'hard_failure',
                'amount_affecting' => true,
                'message' => 'Frozen losses do not agree to the approved predecessor.',
                'workflow_page' => 'corporation_tax',
                'workflow_fields' => ['ct_period_id' => '1'],
            ];
            $fixture = ctPeriodFilingModelFixture(1, [], [$blocker]);
            $result = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);

            $h->assertSame(false, (bool)($result['available'] ?? true));
            $h->assertSame('loss_brought_forward_continuity', (string)($result['blocking_diagnostics'][0]['code'] ?? ''));
            $h->assertSame('loss', (string)($result['blocking_diagnostics'][0]['category'] ?? ''));
            $h->assertSame('corporation_tax', (string)($result['blocking_diagnostics'][0]['workflow_page'] ?? ''));
        });

        $h->check($service::class, 'rejects missing, altered and cross-period approval evidence', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            \InterfaceDB::execute(
                'UPDATE year_end_review_acknowledgements SET basis_hash = :hash WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['hash' => str_repeat('0', 64), 'company_id' => $fixture['company_id'], 'accounting_period_id' => $fixture['accounting_period_id']]
            );
            $altered = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($altered['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$altered['errors']), 'basis hash'));

            \InterfaceDB::execute(
                'DELETE FROM year_end_review_acknowledgements WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $fixture['company_id'], 'accounting_period_id' => $fixture['accounting_period_id']]
            );
            $missing = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($missing['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$missing['errors']), 'no approved Corporation Tax readiness basis'));
        });

        $h->check($service::class, 'rejects missing, incomplete and unsupported approved return profiles', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            ctPeriodFilingModelRewriteApproval($fixture, static function (array $basis): array {
                unset($basis['supported_return_profile']);
                return $basis;
            });
            $missing = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($missing['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$missing['errors']), 'no supported-return-profile assessment'));

            $fixture = ctPeriodFilingModelFixture();
            ctPeriodFilingModelRewriteApproval($fixture, static function (array $basis): array {
                unset($basis['supported_return_profile']['check_results']['frs105_micro_entity_eligibility']);
                return $basis;
            });
            $incomplete = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($incomplete['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$incomplete['errors']), 'frs105_micro_entity_eligibility'));

            $fixture = ctPeriodFilingModelFixture();
            ctPeriodFilingModelRewriteApproval($fixture, static function (array $basis): array {
                $basis['supported_return_profile']['ordinary_trading_company_confirmed'] = false;
                $basis['supported_return_profile']['supported'] = false;
                $basis['supported_return_profile']['check_results']['ordinary_uk_trading_profile'] = false;
                $basis['supported_return_profile']['failed_checks'] = [[
                    'code' => 'ordinary_uk_trading_profile',
                    'message' => 'The supported profile requires an active, non-dormant trading company.',
                ]];
                return $basis;
            });
            $unsupported = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($unsupported['available'] ?? true));
            $h->assertSame('ordinary_uk_trading_profile', (string)($unsupported['blocking_diagnostics'][0]['code'] ?? ''));
            $h->assertTrue(str_contains(implode(' ', (array)$unsupported['errors']), 'active, non-dormant trading company'));
        });

        $h->check($service::class, 'rejects a validly rehashed approval for different CT-period dates', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $row = \InterfaceDB::fetchOne(
                'SELECT basis_json FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $fixture['company_id'], 'accounting_period_id' => $fixture['accounting_period_id']]
            );
            $basis = json_decode((string)($row['basis_json'] ?? ''), true);
            $basis['freeze_manifest']['periods'][0]['period_end'] = '2023-12-30';
            $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
            $basisJson = json_encode($acknowledgements->normalizedBasis($basis), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            \InterfaceDB::execute(
                'UPDATE year_end_review_acknowledgements
                 SET basis_json = :basis_json, basis_hash = :basis_hash
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                [
                    'basis_json' => $basisJson,
                    'basis_hash' => $acknowledgements->hashBasis($basis),
                    'company_id' => $fixture['company_id'],
                    'accounting_period_id' => $fixture['accounting_period_id'],
                ]
            );
            $result = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);

            $h->assertSame(false, (bool)($result['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$result['errors']), 'period dates'));
        });

        $h->check($service::class, 'rejects changed computation, period and Tax Audit hashes', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $runId = $fixture['run_ids'][0];
            $summary = json_decode((string)\InterfaceDB::fetchColumn(
                'SELECT summary_json FROM corporation_tax_computation_runs WHERE id = :id',
                ['id' => $runId]
            ), true);
            $summary['year_end_freeze_manifest_hash'] = str_repeat('f', 64);
            \InterfaceDB::execute(
                'UPDATE corporation_tax_computation_runs SET summary_json = :summary WHERE id = :id',
                ['summary' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'id' => $runId]
            );
            $changedComputation = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($changedComputation['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$changedComputation['errors']), 'not bound'));

            $fixture = ctPeriodFilingModelFixture();
            \InterfaceDB::execute(
                'UPDATE corporation_tax_audit_snapshots SET basis_hash = :hash WHERE computation_run_id = :run_id',
                ['hash' => str_repeat('e', 64), 'run_id' => $fixture['run_ids'][0]]
            );
            $changedAudit = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($changedAudit['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$changedAudit['errors']), 'Tax Audit snapshot basis hash'));
        });
    }
);

/**
 * @param list<string> $warnings
 * @param list<array<string, mixed>> $blockers
 * @param array<string, mixed> $summaryOverrides
 * @return array<string, mixed>
 */
function ctPeriodFilingModelFixture(
    int $periodCount = 1,
    array $warnings = [],
    array $blockers = [],
    array $summaryOverrides = []
): array {
    foreach ([
        'companies',
        'accounting_periods',
        'corporation_tax_periods',
        'corporation_tax_computation_runs',
        'corporation_tax_audit_snapshots',
        'corporation_tax_audit_areas',
        'year_end_reviews',
        'year_end_review_acknowledgements',
    ] as $table) {
        if (!\InterfaceDB::tableExists($table)) {
            throw new RuntimeException('Required CT filing model test table is unavailable: ' . $table);
        }
    }
    if (!\InterfaceDB::inTransaction()) {
        \InterfaceDB::beginTransaction();
    }
    \InterfaceDB::execute(
        'INSERT INTO companies (company_name, company_number, is_active) VALUES (:name, :number, 1)',
        ['name' => 'Approved Filing Fixture', 'number' => '12345678']
    );
    $companyId = ctPeriodFilingModelLastInsertId();
    \InterfaceDB::execute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :start, :end)',
        ['company_id' => $companyId, 'label' => 'Approved filing fixture', 'start' => '2023-01-01', 'end' => '2023-12-31']
    );
    $accountingPeriodId = ctPeriodFilingModelLastInsertId();

    $periodDefinitions = $periodCount === 2
        ? [
            ['sequence_no' => 1, 'period_start' => '2023-01-01', 'period_end' => '2023-10-31'],
            ['sequence_no' => 2, 'period_start' => '2023-11-01', 'period_end' => '2023-12-31'],
        ]
        : [['sequence_no' => 1, 'period_start' => '2023-01-01', 'period_end' => '2023-12-31']];
    $ctPeriodIds = [];
    $manifestPeriods = [];
    foreach ($periodDefinitions as $period) {
        \InterfaceDB::execute(
            'INSERT INTO corporation_tax_periods (company_id, accounting_period_id, sequence_no, period_start, period_end, status)
             VALUES (:company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status)',
            $period + ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'status' => 'computed']
        );
        $ctPeriodId = ctPeriodFilingModelLastInsertId();
        $ctPeriodIds[] = $ctPeriodId;
        $manifestPeriods[] = [
            'ct_period_id' => $ctPeriodId,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'accounting_profit' => '100.00',
            'taxable_profit' => '100.00',
            'corporation_tax_liability' => '19.00',
            'blocking_diagnostic_codes' => [],
        ];
    }
    $manifest = [
        'basis_version' => \eel_accounts\Service\YearEndTaxFreezeService::BASIS_VERSION,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'periods' => $manifestPeriods,
        'totals' => ['taxable_profit' => '100.00', 'corporation_tax_liability' => '19.00'],
        'blocking_diagnostic_codes' => [],
    ];
    $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
    $manifestHash = $acknowledgements->hashBasis($manifest);
    $approvalBasis = [
        'check_code' => 'tax_readiness_acknowledgement',
        'freeze_manifest' => $manifest,
        'supported_return_profile' => ctPeriodFilingModelSupportedReturnProfile(),
    ];
    $approvalJson = json_encode($acknowledgements->normalizedBasis($approvalBasis), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $approvalHash = $acknowledgements->hashBasis($approvalBasis);

    \InterfaceDB::execute(
        'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
         VALUES (:company_id, :accounting_period_id, 1, :locked_at, :locked_by)',
        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'locked_at' => '2026-07-19 12:00:00', 'locked_by' => 'test']
    );
    \InterfaceDB::execute(
        'INSERT INTO year_end_review_acknowledgements
            (company_id, accounting_period_id, check_code, acknowledged_at, acknowledged_by,
             basis_version, basis_hash, basis_json)
         VALUES
            (:company_id, :accounting_period_id, :check_code, :acknowledged_at, :acknowledged_by,
             :basis_version, :basis_hash, :basis_json)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'check_code' => 'tax_readiness_acknowledgement',
            'acknowledged_at' => '2026-07-19 11:59:00',
            'acknowledged_by' => 'test',
            'basis_version' => \eel_accounts\Service\YearEndAcknowledgementService::BASIS_VERSION,
            'basis_hash' => $approvalHash,
            'basis_json' => $approvalJson,
        ]
    );

    $runIds = [];
    foreach ($periodDefinitions as $index => $period) {
        $ctPeriodId = $ctPeriodIds[$index];
        $computationHash = hash('sha256', 'approved-computation-' . $companyId . '-' . $ctPeriodId);
        $summary = array_replace([
            'available' => true,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'accounting_profit' => 100.00,
            'taxable_profit' => 100.00,
            'estimated_corporation_tax' => 19.00,
            'unknown_treatment_amount' => 0.00,
            'computation_hash' => $computationHash,
            'year_end_freeze_basis_version' => \eel_accounts\Service\YearEndTaxFreezeService::BASIS_VERSION,
            'year_end_freeze_manifest_hash' => $manifestHash,
            'hard_gate_diagnostics' => $blockers,
            'warnings' => $warnings,
        ], $summaryOverrides);
        \InterfaceDB::execute(
            'INSERT INTO corporation_tax_computation_runs
                (company_id, accounting_period_id, ct_period_id, period_start, period_end, status, computation_hash, summary_json)
             VALUES
                (:company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end, :status, :computation_hash, :summary_json)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'status' => 'generated',
                'computation_hash' => $computationHash,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            ]
        );
        $runId = ctPeriodFilingModelLastInsertId();
        $runIds[] = $runId;
        \InterfaceDB::execute(
            'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :ct_period_id',
            ['run_id' => $runId, 'ct_period_id' => $ctPeriodId]
        );

        $areaDetails = [];
        foreach (['accounting_profit', 'expense_treatments', 'depreciation_capital', 'capital_allowances', 'losses', 'tax_liability'] as $areaCode) {
            $detail = [
                'available' => true,
                'area_code' => $areaCode,
                'area_label' => ucwords(str_replace('_', ' ', $areaCode)),
                'amount' => 0.00,
                'expected_amount' => 0.00,
                'reconciliation_difference' => 0.00,
                'reconciliation_status' => 'reconciled',
                'rows' => [],
                'basis_version' => \eel_accounts\Service\TaxAuditBasisService::BASIS_VERSION,
                'errors' => [],
            ];
            $detail['area_hash'] = hash('sha256', ctPeriodFilingModelCanonicalJson($detail));
            $areaDetails[$areaCode] = $detail;
        }
        $snapshotHash = hash('sha256', ctPeriodFilingModelCanonicalJson(array_map(
            static fn(array $detail): string => (string)$detail['area_hash'],
            $areaDetails
        )));
        \InterfaceDB::execute(
            'INSERT INTO corporation_tax_audit_snapshots
                (computation_run_id, company_id, accounting_period_id, ct_period_id, basis_version, basis_hash, snapshot_origin)
             VALUES
                (:run_id, :company_id, :accounting_period_id, :ct_period_id, :basis_version, :basis_hash, :snapshot_origin)',
            [
                'run_id' => $runId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'basis_version' => \eel_accounts\Service\TaxAuditBasisService::BASIS_VERSION,
                'basis_hash' => $snapshotHash,
                'snapshot_origin' => 'year_end_lock',
            ]
        );
        $snapshotId = ctPeriodFilingModelLastInsertId();
        foreach ($areaDetails as $detail) {
            \InterfaceDB::execute(
                'INSERT INTO corporation_tax_audit_areas
                    (snapshot_id, area_code, area_label, amount, expected_amount, reconciliation_difference,
                     reconciliation_status, source_count, area_hash, detail_json)
                 VALUES
                    (:snapshot_id, :area_code, :area_label, 0, 0, 0, :status, 0, :area_hash, :detail_json)',
                [
                    'snapshot_id' => $snapshotId,
                    'area_code' => $detail['area_code'],
                    'area_label' => $detail['area_label'],
                    'status' => 'reconciled',
                    'area_hash' => $detail['area_hash'],
                    'detail_json' => ctPeriodFilingModelCanonicalJson($detail),
                ]
            );
        }
    }

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'ct_period_ids' => $ctPeriodIds,
        'run_ids' => $runIds,
        'manifest_hash' => $manifestHash,
    ];
}

/** @return array<string, int> */
function ctPeriodFilingModelEvidenceCounts(): array
{
    return [
        'approvals' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM year_end_review_acknowledgements'),
        'runs' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_computation_runs'),
        'snapshots' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_audit_snapshots'),
        'areas' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_audit_areas'),
    ];
}

function ctPeriodFilingModelCanonicalJson(array $value): string
{
    $normalise = static function (mixed $item) use (&$normalise): mixed {
        if (!is_array($item)) {
            return $item;
        }
        if (!array_is_list($item)) {
            ksort($item);
        }
        foreach ($item as $key => $child) {
            $item[$key] = $normalise($child);
        }
        return $item;
    };
    return (string)json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/** @return array<string, mixed> */
function ctPeriodFilingModelSupportedReturnProfile(): array
{
    $checkResults = [];
    foreach (\eel_accounts\Service\Frs105YearEndProfileService::RETURN_PROFILE_CHECK_CODES as $code) {
        $checkResults[$code] = true;
    }
    ksort($checkResults, SORT_STRING);

    return [
        'profile_code' => \eel_accounts\Service\Frs105YearEndProfileService::RETURN_PROFILE_CODE,
        'profile_version' => \eel_accounts\Service\Frs105YearEndProfileService::RETURN_PROFILE_VERSION,
        'ordinary_trading_company_confirmed' => true,
        'supported' => true,
        'check_results' => $checkResults,
        'failed_checks' => [],
    ];
}

/** @param callable(array<string, mixed>): array<string, mixed> $change */
function ctPeriodFilingModelRewriteApproval(array $fixture, callable $change): void
{
    $row = \InterfaceDB::fetchOne(
        'SELECT basis_json FROM year_end_review_acknowledgements
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
        ['company_id' => $fixture['company_id'], 'accounting_period_id' => $fixture['accounting_period_id']]
    );
    $basis = json_decode((string)($row['basis_json'] ?? ''), true);
    if (!is_array($basis)) {
        throw new RuntimeException('The filing-model approval fixture is unreadable.');
    }
    $basis = $change($basis);
    $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
    \InterfaceDB::execute(
        'UPDATE year_end_review_acknowledgements
         SET basis_json = :basis_json, basis_hash = :basis_hash
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
        [
            'basis_json' => json_encode($acknowledgements->normalizedBasis($basis), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'basis_hash' => $acknowledgements->hashBasis($basis),
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
        ]
    );
}

function ctPeriodFilingModelLastInsertId(): int
{
    return (int)\InterfaceDB::fetchColumn(
        \InterfaceDB::driverName() === 'sqlite' ? 'SELECT last_insert_rowid()' : 'SELECT LAST_INSERT_ID()'
    );
}
