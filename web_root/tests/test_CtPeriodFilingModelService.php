<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';

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

        $preExistingNominalIds = array_map('intval', array_column(\InterfaceDB::fetchAll(
            "SELECT id FROM nominal_accounts WHERE code IN ('1000', '1200', '2100', '4000')"
        ), 'id'));
        $preExistingSubtypeIds = array_map('intval', array_column(\InterfaceDB::fetchAll(
            "SELECT id FROM nominal_account_subtypes WHERE code IN ('bank', 'director_loan_asset', 'director_loan_liability')"
        ), 'id'));

        $h->check($service::class, 'loads one and two CT periods from one current post-Year-End approval', static function () use ($h, $service): void {
            foreach ([1, 2] as $periodCount) {
                $fixture = ctPeriodFilingModelFixture($periodCount);
                $approval = (array)$fixture['filing_approval'];
                $h->assertSame($periodCount, count((array)($approval['ct_basis_ids'] ?? [])));
                foreach ($fixture['ct_period_ids'] as $ctPeriodId) {
                    $model = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $ctPeriodId);
                    $h->assertSame(true, (bool)($model['available'] ?? false));
                    $h->assertSame((int)$approval['approval_id'], (int)($model['approval']['id'] ?? 0));
                    $h->assertSame((string)$approval['approval_hash'], (string)($model['approval']['basis_hash'] ?? ''));
                    $h->assertSame($ctPeriodId, (int)($model['model']['ct_period']['id'] ?? 0));
                    $h->assertSame((string)$model['basis_hash'], (string)($model['seal']['basis_hash'] ?? ''));
                }
            }
        });

        $h->check($service::class, 'derives and freezes two independently fileable CT periods for a long accounting period', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture(2, [], [], [], true, true);
            $periods = (new \eel_accounts\Service\CorporationTaxPeriodService())
                ->fetchForAccountingPeriod($fixture['company_id'], $fixture['accounting_period_id']);
            $h->assertCount(2, $periods);
            $h->assertSame('2022-09-05', (string)$periods[0]['period_start']);
            $h->assertSame('2023-09-04', (string)$periods[0]['period_end']);
            $h->assertSame('2023-09-05', (string)$periods[1]['period_start']);
            $h->assertSame('2023-09-30', (string)$periods[1]['period_end']);

            $first = $service->build($fixture['company_id'], $fixture['accounting_period_id'], (int)$periods[0]['id']);
            $second = $service->build($fixture['company_id'], $fixture['accounting_period_id'], (int)$periods[1]['id']);
            $h->assertSame(true, (bool)$first['available']);
            $h->assertSame(true, (bool)$second['available']);
            $h->assertTrue((string)$first['basis_hash'] !== (string)$second['basis_hash']);
            $h->assertTrue((int)$first['run']['run_id'] !== (int)$second['run']['run_id']);

            $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
            $h->assertSame(false, (bool)$periodService->canSubmit($fixture['company_id'], (int)$periods[1]['id'])['ok']);
            \InterfaceDB::prepareExecute('UPDATE corporation_tax_periods SET status = :status WHERE id = :id', ['status' => 'accepted', 'id' => (int)$periods[0]['id']]);
            $h->assertSame(true, (bool)$periodService->canSubmit($fixture['company_id'], (int)$periods[1]['id'])['ok']);
        });

        $h->check($service::class, 'preserves frozen warnings and gives both filing targets the identical stored basis', static function () use ($h, $service): void {
            $warning = 'Frozen filing-stage warning retained for review.';
            $fixture = ctPeriodFilingModelFixture(1, [$warning]);
            $model = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(true, (bool)($model['available'] ?? false));
            $h->assertSame($warning, (string)($model['warning_diagnostics'][0]['message'] ?? ''));
            $h->assertTrue(str_starts_with((string)($model['warning_diagnostics'][0]['code'] ?? ''), 'frozen_warning_'));

            $rimMapping = [[
                'id' => 1, 'profile_id' => 1,
                'canonical_key' => 'identity.company_name',
                'value_type' => 'text', 'null_policy' => 'error', 'is_required' => 1, 'sort_order' => 1,
            ]];
            $computationMapping = [array_replace($rimMapping[0], ['profile_id' => 2])];
            $mappingService = new \eel_accounts\Service\CtFilingMappingService();
            $rim = $mappingService->mapFrozenFacts('ct600_rim', $model, [
                'id' => 1, 'target_type' => 'ct600_rim', 'status' => 'active', 'compatibility_status' => 'compatible',
            ], $rimMapping);
            $computation = $mappingService->mapFrozenFacts('computation_ixbrl', $model, [
                'id' => 2, 'target_type' => 'computation_ixbrl', 'status' => 'active', 'compatibility_status' => 'compatible',
            ], $computationMapping);
            $h->assertSame(true, (bool)($rim['success'] ?? false));
            $h->assertSame((string)$rim['basis_hash'], (string)$computation['basis_hash']);
            $h->assertSame($rim['canonical_values'], $computation['canonical_values']);
        });

        $h->check($service::class, 'makes approval, facts and CT bases stale after a disclosure edit while retaining history', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $before = ctPeriodFilingModelEvidenceCounts();
            $changed = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->saveField(
                $fixture['company_id'], $fixture['accounting_period_id'],
                'going_concern_basis_appropriate', 0, 'test'
            );
            $h->assertSame(true, (bool)($changed['success'] ?? false));
            $status = (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService())
                ->status($fixture['company_id'], $fixture['accounting_period_id']);
            $h->assertSame('stale', (string)($status['state'] ?? ''));
            $h->assertSame($before, ctPeriodFilingModelEvidenceCounts());
            $h->assertSame(false, (bool)($service->build(
                $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]
            )['available'] ?? true));
            $freshness = (new \eel_accounts\Service\IxbrlFactBuilderService())->getRunFreshness(
                (int)$fixture['filing_approval']['fact_run_id']
            );
            $h->assertSame('stale', (string)($freshness['state'] ?? ''));
        });

        $h->check($service::class, 'invalidates approval on unlock and requires a new approval after relock', static function () use ($h): void {
            $fixture = ctPeriodFilingModelFixture();
            $approvalService = new \eel_accounts\Service\IxbrlAccountsFilingApprovalService();
            $firstApprovalId = (int)$fixture['filing_approval']['approval_id'];

            \InterfaceDB::execute(
                'UPDATE year_end_reviews
                 SET is_locked = 0, locked_at = NULL, locked_by = NULL
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $fixture['company_id'], 'accounting_period_id' => $fixture['accounting_period_id']]
            );
            $unlocked = $approvalService->status($fixture['company_id'], $fixture['accounting_period_id']);
            $h->assertSame('stale', (string)($unlocked['state'] ?? ''));
            $h->assertSame(false, (bool)($unlocked['can_approve'] ?? true));

            \InterfaceDB::execute(
                'UPDATE year_end_reviews
                 SET is_locked = 1, locked_at = :locked_at, locked_by = :locked_by
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                [
                    'locked_at' => '2026-07-19 13:00:00', 'locked_by' => 'relock-test',
                    'company_id' => $fixture['company_id'], 'accounting_period_id' => $fixture['accounting_period_id'],
                ]
            );
            $relocked = $approvalService->status($fixture['company_id'], $fixture['accounting_period_id']);
            $h->assertSame('stale', (string)($relocked['state'] ?? ''));
            $h->assertSame(true, (bool)($relocked['can_approve'] ?? false));
            $replacement = $approvalService->approveAndBuildFacts(
                $fixture['company_id'], $fixture['accounting_period_id'], 'relock-test'
            );
            $h->assertTrue((int)($replacement['approval_id'] ?? 0) > $firstApprovalId);
            $h->assertSame('current', (string)($approvalService->status(
                $fixture['company_id'], $fixture['accounting_period_id']
            )['state'] ?? ''));
        });

        $h->check($service::class, 'rejects a changed calculation seal or computation after approval', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $runId = $fixture['run_ids'][0];
            $summary = json_decode((string)\InterfaceDB::fetchColumn(
                'SELECT summary_json FROM corporation_tax_computation_runs WHERE id = :id', ['id' => $runId]
            ), true);
            unset($summary['accounting_profit']);
            \InterfaceDB::execute(
                'UPDATE corporation_tax_computation_runs SET summary_json = :summary WHERE id = :id',
                ['summary' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'id' => $runId]
            );
            $changed = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($changed['available'] ?? true));
            if (!str_contains(implode(' ', (array)$changed['errors']), 'changed since filing approval')) {
                throw new RuntimeException('Unexpected changed-computation errors: ' . implode(' ', (array)$changed['errors']));
            }

            $fixture = ctPeriodFilingModelFixture();
            $runId = $fixture['run_ids'][0];
            $summary = json_decode((string)\InterfaceDB::fetchColumn(
                'SELECT summary_json FROM corporation_tax_computation_runs WHERE id = :id', ['id' => $runId]
            ), true);
            unset($summary['frozen_calculation_basis']);
            \InterfaceDB::execute(
                'UPDATE corporation_tax_computation_runs SET summary_json = :summary WHERE id = :id',
                ['summary' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'id' => $runId]
            );
            $missingSeal = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($missingSeal['available'] ?? true));
            if (!str_contains(strtolower(implode(' ', (array)$missingSeal['errors'])), 'current disclosures and filing basis')) {
                throw new RuntimeException('Unexpected missing-seal errors: ' . implode(' ', (array)$missingSeal['errors']));
            }
        });

        $h->check($service::class, 'rolls approval, CT bases and fact run back when fact construction fails', static function () use ($h): void {
            $fixture = ctPeriodFilingModelFixture(1, [], [], [], false);
            $before = ctPeriodFilingModelEvidenceCounts();
            try {
                (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService(
                    static function (): int { throw new RuntimeException('Injected fact-build failure.'); }
                ))->approveAndBuildFacts($fixture['company_id'], $fixture['accounting_period_id'], 'test');
                $h->assertTrue(false, 'Expected the injected fact build to fail.');
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'Injected fact-build failure')) {
                    throw new RuntimeException('Unexpected fact-build rollback error: ' . $exception->getMessage());
                }
            }
            $h->assertSame($before, ctPeriodFilingModelEvidenceCounts());
        });

        \InterfaceDB::execute(
            'DELETE FROM companies WHERE company_name = :name',
            ['name' => 'Approved Filing Fixture']
        );
        $nominalCleanupSql = "DELETE FROM nominal_accounts WHERE code IN ('1000', '1200', '2100', '4000')";
        if ($preExistingNominalIds !== []) {
            $nominalCleanupSql .= ' AND id NOT IN (' . implode(',', $preExistingNominalIds) . ')';
        }
        \InterfaceDB::execute($nominalCleanupSql);
        $subtypeCleanupSql = "DELETE FROM nominal_account_subtypes
            WHERE code IN ('bank', 'director_loan_asset', 'director_loan_liability')";
        if ($preExistingSubtypeIds !== []) {
            $subtypeCleanupSql .= ' AND id NOT IN (' . implode(',', $preExistingSubtypeIds) . ')';
        }
        \InterfaceDB::execute($subtypeCleanupSql);
        return;

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

        $h->check($service::class, 'keeps approved identity after live company presentation changes', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            \InterfaceDB::execute(
                'UPDATE companies SET company_name = :name, company_number = :number WHERE id = :id',
                ['name' => 'Later Live Name', 'number' => '87654321', 'id' => $fixture['company_id']]
            );
            $result = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(true, (bool)($result['available'] ?? false));
            $h->assertSame('Approved Filing Fixture', (string)($result['model']['identity']['company_name'] ?? ''));
            $h->assertSame('12345678', (string)($result['model']['identity']['company_number'] ?? ''));
        });

        $h->check($service::class, 'fails closed for a missing or altered final seal and required fact', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $runId = $fixture['run_ids'][0];
            $summary = json_decode((string)\InterfaceDB::fetchColumn('SELECT summary_json FROM corporation_tax_computation_runs WHERE id = :id', ['id' => $runId]), true);
            unset($summary['frozen_filing_basis']);
            \InterfaceDB::execute('UPDATE corporation_tax_computation_runs SET summary_json = :summary WHERE id = :id', ['summary' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'id' => $runId]);
            $missingSeal = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($missingSeal['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$missingSeal['errors']), 'no frozen filing-basis seal'));

            $fixture = ctPeriodFilingModelFixture();
            $runId = $fixture['run_ids'][0];
            $summary = json_decode((string)\InterfaceDB::fetchColumn('SELECT summary_json FROM corporation_tax_computation_runs WHERE id = :id', ['id' => $runId]), true);
            unset($summary['accounting_profit']);
            \InterfaceDB::execute('UPDATE corporation_tax_computation_runs SET summary_json = :summary WHERE id = :id', ['summary' => json_encode($summary, JSON_UNESCAPED_SLASHES), 'id' => $runId]);
            $missingFact = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $h->assertSame(false, (bool)($missingFact['available'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$missingFact['errors']), 'accounting_profit'));
        });

        $h->check($service::class, 'maps both filing targets from the identical sealed penny facts', static function () use ($h, $service): void {
            $fixture = ctPeriodFilingModelFixture();
            $model = $service->build($fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_ids'][0]);
            $mappingService = new \eel_accounts\Service\CtFilingMappingService();
            $results = [];
            foreach ([\eel_accounts\Service\CtFilingMappingService::TARGET_RIM, \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION] as $target) {
                $profileId = $target === \eel_accounts\Service\CtFilingMappingService::TARGET_RIM ? 1 : 2;
                $mapping = [
                    'id' => 1,
                    'profile_id' => $profileId,
                    'canonical_key' => 'computation.summary.accounting_profit',
                    'value_type' => 'numeric',
                    'null_policy' => 'error',
                    'is_required' => 1,
                    'sort_order' => 1,
                ];
                $results[$target] = $mappingService->mapFrozenFacts($target, $model, [
                    'id' => $profileId,
                    'target_type' => $target,
                    'status' => 'active',
                    'compatibility_status' => 'compatible',
                ], [$mapping]);
            }
            $rim = $results[\eel_accounts\Service\CtFilingMappingService::TARGET_RIM];
            $computation = $results[\eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION];
            $h->assertSame(true, (bool)($rim['success'] ?? false));
            $h->assertSame((string)$rim['basis_hash'], (string)$computation['basis_hash']);
            $h->assertSame(100.0, (float)($rim['canonical_values']['computation.summary.accounting_profit'] ?? 0));
            $h->assertSame($rim['canonical_values'], $computation['canonical_values']);
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
    array $summaryOverrides = [],
    bool $approve = true,
    bool $deriveLongPeriod = false
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
        'ixbrl_accounts_disclosures',
        'ixbrl_accounts_filing_approvals',
        'ct_period_filing_bases',
    ] as $table) {
        if (!\InterfaceDB::tableExists($table)) {
            throw new RuntimeException('Required CT filing model test table is unavailable: ' . $table);
        }
    }
    ixbrl_test_ensure_frs105_thresholds();
    \InterfaceDB::execute(
        'INSERT INTO companies (
            company_name, company_number, is_active, company_status, companies_house_type,
            companies_house_jurisdiction, registered_office_address_line_1,
            registered_office_locality, registered_office_postal_code, registered_office_country
         ) VALUES (
            :name, :number, 1, :status, :type, :jurisdiction, :address, :locality, :postcode, :country
         )',
        [
            'name' => 'Approved Filing Fixture', 'number' => '12345678', 'status' => 'active',
            'type' => 'ltd', 'jurisdiction' => 'england-wales', 'address' => '1 Filing Street',
            'locality' => 'Testford', 'postcode' => 'TE5 1AA', 'country' => 'United Kingdom',
        ]
    );
    $companyId = ctPeriodFilingModelLastInsertId();
    $accountingStart = $deriveLongPeriod ? '2022-09-05' : '2023-01-01';
    $accountingEnd = $deriveLongPeriod ? '2023-09-30' : '2023-12-31';
    \InterfaceDB::execute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :start, :end)',
        ['company_id' => $companyId, 'label' => 'Approved filing fixture', 'start' => $accountingStart, 'end' => $accountingEnd]
    );
    $accountingPeriodId = ctPeriodFilingModelLastInsertId();
    $salesNominalId = ixbrl_test_assign_sales_nominal($companyId);
    ixbrl_test_assign_director_loan_nominals($companyId);
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('default_currency', 'GBP', 'char');
    $settings->flush();
    StandardNominalTestFixture::ensureNominals(['1000']);
    \InterfaceDB::execute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId, 'period_id' => $accountingPeriodId, 'source_type' => 'manual',
            'source_ref' => 'ct-filing-sales-' . $companyId, 'journal_date' => '2023-06-30',
            'description' => 'Supported trading evidence',
        ]
    );
    $salesJournalId = ctPeriodFilingModelLastInsertId();
    \InterfaceDB::execute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, 100, 0, :description)',
        ['journal_id' => $salesJournalId, 'nominal_id' => StandardNominalTestFixture::id('1000'), 'description' => 'Cash']
    );
    \InterfaceDB::execute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, 0, 100, :description)',
        ['journal_id' => $salesJournalId, 'nominal_id' => $salesNominalId, 'description' => 'Sales']
    );

    $periodDefinitions = $periodCount === 2
        ? [
            ['sequence_no' => 1, 'period_start' => '2023-01-01', 'period_end' => '2023-10-31'],
            ['sequence_no' => 2, 'period_start' => '2023-11-01', 'period_end' => '2023-12-31'],
        ]
        : [['sequence_no' => 1, 'period_start' => '2023-01-01', 'period_end' => '2023-12-31']];
    if ($deriveLongPeriod) {
        $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);
        if (empty($sync['success'])) {
            throw new RuntimeException('Long-period fixture could not derive CT periods: ' . implode(' ', (array)($sync['errors'] ?? [])));
        }
        $periodDefinitions = array_map(static fn(array $period): array => [
            'sequence_no' => (int)$period['sequence_no'],
            'period_start' => (string)$period['period_start'],
            'period_end' => (string)$period['period_end'],
        ], (array)$sync['periods']);
    }
    $ctPeriodIds = [];
    $manifestPeriods = [];
    foreach ($periodDefinitions as $period) {
        if ($deriveLongPeriod) {
            $ctPeriodId = (int)\InterfaceDB::fetchColumn('SELECT id FROM corporation_tax_periods WHERE accounting_period_id = :period_id AND sequence_no = :sequence_no', ['period_id' => $accountingPeriodId, 'sequence_no' => $period['sequence_no']]);
            \InterfaceDB::prepareExecute('UPDATE corporation_tax_periods SET status = :status WHERE id = :id', ['status' => 'computed', 'id' => $ctPeriodId]);
        } else {
            \InterfaceDB::execute(
                'INSERT INTO corporation_tax_periods (company_id, accounting_period_id, sequence_no, period_start, period_end, status)
                 VALUES (:company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status)',
                $period + ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'status' => 'computed']
            );
            $ctPeriodId = ctPeriodFilingModelLastInsertId();
        }
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
        'filing_identity' => [
            'company' => ['id' => $companyId, 'name' => 'Approved Filing Fixture', 'number' => '12345678'],
            'accounting_period' => [
                'id' => $accountingPeriodId,
                'start_date' => $accountingStart,
                'end_date' => $accountingEnd,
            ],
            'ct_periods' => array_map(static fn(array $period, int $index): array => [
                'id' => $ctPeriodIds[$index],
                'sequence_no' => (int)$period['sequence_no'],
                'start_date' => (string)$period['period_start'],
                'end_date' => (string)$period['period_end'],
            ], $periodDefinitions, array_keys($periodDefinitions)),
        ],
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
            'disallowable_add_backs' => 0.00,
            'capital_add_backs' => 0.00,
            'depreciation_add_back' => 0.00,
            'capital_allowances' => 0.00,
            'taxable_before_losses' => 100.00,
            'taxable_profit' => 100.00,
            'taxable_loss' => 0.00,
            'loss_created_in_period' => 0.00,
            'losses_brought_forward' => 0.00,
            'losses_used' => 0.00,
            'losses_carried_forward' => 0.00,
            'ordinary_corporation_tax' => 19.00,
            's455_tax' => 0.00,
            'estimated_corporation_tax' => 19.00,
            'associated_company_count' => 0,
            'ct_rate_bands' => [[
                'financial_year' => 'FY2023',
                'basis' => 'flat_main_rate',
                'taxable_profit' => 100.00,
                'main_rate' => 0.19,
                'small_profits_rate' => 0.19,
                'liability' => 19.00,
                'marginal_relief' => 0.00,
            ]],
            'capital_allowance_breakdown' => [
                'rows' => [],
                'asset_calculations' => [],
            ],
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

    if ($blockers === []) {
        \InterfaceDB::beginTransaction();
        try {
            $seal = (new \eel_accounts\Service\CorporationTaxComputationService())
                ->sealSummariesForYearEndLock($companyId, $accountingPeriodId);
            if (empty($seal['success'])) {
                throw new RuntimeException('CT filing fixture could not be sealed: ' . implode(' ', (array)($seal['errors'] ?? [])));
            }
            \InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $exception;
        }

        $savedDisclosures = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
            $companyId,
            $accountingPeriodId,
            [
                'accounting_standard' => 'FRS_105', 'average_number_employees' => 1,
                'is_still_trading' => 1, 'accounts_approval_date' => '2024-01-31',
                'approving_director_name' => 'Fixture Director',
                'prepared_under_small_companies_regime' => 1, 'audit_exempt_section_477' => 1,
                'directors_acknowledge_responsibilities' => 1, 'members_have_not_required_audit' => 1,
                'micro_entity_eligibility_confirmed' => 1, 'going_concern_basis_appropriate' => 1,
                'has_material_off_balance_sheet_arrangements' => 0,
                'has_director_advances_credits_or_guarantees' => 0,
                'has_financial_commitments_guarantees_or_contingencies' => 0,
            ],
            'test'
        );
        if (empty($savedDisclosures['success'])) {
            throw new RuntimeException('CT filing disclosures failed: ' . implode(' ', (array)($savedDisclosures['errors'] ?? [])));
        }
        $scopeService = new \eel_accounts\Service\CorporationTaxFilingScopeService();
        foreach (array_keys($scopeService->definitions()) as $scopeField) {
            $savedScope = $scopeService->saveAnswer($companyId, $accountingPeriodId, $scopeField, 'no', 'test');
            if (empty($savedScope['success'])) {
                throw new RuntimeException('CT filing scope failed: ' . implode(' ', (array)($savedScope['errors'] ?? [])));
            }
        }
        $ct600aService = new \eel_accounts\Service\Ct600aService();
        foreach ($ctPeriodIds as $ctPeriodId) {
            $savedReview = $ct600aService->saveReview(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                array_fill_keys(array_keys($ct600aService->reviewQuestions()), 'no'),
                'director',
                'Fixture Director',
                'No section 464A arrangements in the synthetic fixture.'
            );
            if (empty($savedReview['success'])) {
                throw new RuntimeException('CT600A review failed: ' . implode(' ', (array)($savedReview['errors'] ?? [])));
            }
        }
        if ($approve) {
            $filingApproval = (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService())
                ->approveAndBuildFacts($companyId, $accountingPeriodId, 'test', 'CT filing model fixture.');
        }
    }

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'ct_period_ids' => $ctPeriodIds,
        'run_ids' => $runIds,
        'manifest_hash' => $manifestHash,
        'filing_approval' => $filingApproval ?? null,
    ];
}

/** @return array<string, int> */
function ctPeriodFilingModelEvidenceCounts(): array
{
    return [
        'tax_approvals' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM year_end_review_acknowledgements'),
        'filing_approvals' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_accounts_filing_approvals'),
        'ct_bases' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ct_period_filing_bases'),
        'computation_runs' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_computation_runs'),
        'fact_runs' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs'),
        'facts' => (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_facts'),
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
