<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'UnifiedApprovalWorkflowTestFixture.php';

$workflowFixture = UnifiedApprovalWorkflowTestFixture::seed();

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlFilingApprovalWorkflowService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\IxbrlFilingApprovalWorkflowService $service
    ) use ($workflowFixture): void {
        $companyId = (int)$workflowFixture['company_id'];
        $accountingPeriodId = (int)$workflowFixture['accounting_period_id'];
        $staleApprovalId = (int)$workflowFixture['stale_approval_id'];
        $invoke = static function (string $method, mixed ...$arguments) use ($service): mixed {
            $reflection = new ReflectionMethod($service, $method);
            $reflection->setAccessible(true);
            return $reflection->invoke($service, ...$arguments);
        };

        /** @return array<string,mixed> */
        $ap79Input = static function () use ($companyId, $accountingPeriodId): array {
            $disclosure = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->fetch(
                $companyId,
                $accountingPeriodId
            );
            $input = array_replace(
                (array)($disclosure['disclosures'] ?? []),
                (array)($disclosure['trading_status_answers'] ?? [])
            );
            $authorisation = (new \eel_accounts\Service\Ct600ReturnAuthorisationService())->fetch(
                $companyId,
                $accountingPeriodId
            );
            if ((int)($authorisation['declarant_director_id'] ?? 0) > 0) {
                $input['declarant_authority'] = 'director:'
                    . (int)$authorisation['declarant_director_id'];
            } elseif ((int)($authorisation['declarant_role_id'] ?? 0) > 0) {
                $input['declarant_authority'] = 'party-role:'
                    . (int)$authorisation['declarant_role_id'];
            }
            foreach ([
                'original_unfiled_confirmed',
                'authority_confirmed',
                'declaration_confirmed',
            ] as $field) {
                $input[$field] = !empty($authorisation[$field]) ? '1' : '0';
            }
            $input['ixbrl_approval_editing'] = '1';
            return $input;
        };

        /** @return array<string,list<array<string,mixed>>> */
        $ap79RelevantRows = static function () use ($companyId, $accountingPeriodId): array {
            $params = ['company_id' => $companyId, 'period_id' => $accountingPeriodId];
            return [
                'accounts_approvals' => InterfaceDB::fetchAll(
                    'SELECT * FROM ixbrl_accounts_filing_approvals
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     ORDER BY id',
                    $params
                ),
                'hmrc_approvals' => InterfaceDB::fetchAll(
                    'SELECT * FROM hmrc_ct_filing_approvals
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     ORDER BY id',
                    $params
                ),
                'ct_bases' => InterfaceDB::fetchAll(
                    'SELECT * FROM ct_period_filing_bases
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     ORDER BY id',
                    $params
                ),
                'hmrc_basis_links' => InterfaceDB::fetchAll(
                    'SELECT link.*
                     FROM hmrc_ct_filing_approval_period_bases link
                     INNER JOIN hmrc_ct_filing_approvals approval
                       ON approval.id = link.hmrc_ct_filing_approval_id
                     WHERE approval.company_id = :company_id
                       AND approval.accounting_period_id = :period_id
                     ORDER BY link.hmrc_ct_filing_approval_id, link.ct_period_id',
                    $params
                ),
                'approved_fact_runs' => InterfaceDB::fetchAll(
                    'SELECT * FROM ixbrl_generation_runs
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                       AND filing_approval_id IS NOT NULL
                     ORDER BY id',
                    $params
                ),
                'approved_facts' => InterfaceDB::fetchAll(
                    'SELECT fact.*
                     FROM ixbrl_generation_facts fact
                     INNER JOIN ixbrl_generation_runs run ON run.id = fact.run_id
                     WHERE run.company_id = :company_id
                       AND run.accounting_period_id = :period_id
                       AND run.filing_approval_id IS NOT NULL
                     ORDER BY fact.id',
                    $params
                ),
                'authority_artifacts' => InterfaceDB::fetchAll(
                    'SELECT * FROM ixbrl_accounts_artifacts
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     ORDER BY id',
                    $params
                ),
                'audit_log' => InterfaceDB::fetchAll(
                    'SELECT * FROM year_end_audit_log
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     ORDER BY id',
                    $params
                ),
                'evidence_bundles' => InterfaceDB::fetchAll(
                    'SELECT * FROM filing_evidence_bundles
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     ORDER BY id',
                    $params
                ),
                'evidence_loan_snapshots' => InterfaceDB::fetchAll(
                    'SELECT child.* FROM filing_evidence_loan_snapshots child
                     INNER JOIN filing_evidence_bundles bundle ON bundle.id = child.bundle_id
                     WHERE bundle.company_id = :company_id AND bundle.accounting_period_id = :period_id
                     ORDER BY child.id',
                    $params
                ),
                'evidence_ct_snapshots' => InterfaceDB::fetchAll(
                    'SELECT child.* FROM filing_evidence_ct_snapshots child
                     INNER JOIN filing_evidence_bundles bundle ON bundle.id = child.bundle_id
                     WHERE bundle.company_id = :company_id AND bundle.accounting_period_id = :period_id
                     ORDER BY child.id',
                    $params
                ),
                'evidence_artifacts' => InterfaceDB::fetchAll(
                    'SELECT child.* FROM filing_evidence_artifacts child
                     INNER JOIN filing_evidence_bundles bundle ON bundle.id = child.bundle_id
                     WHERE bundle.company_id = :company_id AND bundle.accounting_period_id = :period_id
                     ORDER BY child.id',
                    $params
                ),
                'evidence_events' => InterfaceDB::fetchAll(
                    'SELECT child.* FROM filing_evidence_events child
                     INNER JOIN filing_evidence_bundles bundle ON bundle.id = child.bundle_id
                     WHERE bundle.company_id = :company_id AND bundle.accounting_period_id = :period_id
                     ORDER BY child.id',
                    $params
                ),
                'evidence_sections' => InterfaceDB::fetchAll(
                    'SELECT child.* FROM filing_evidence_section_snapshots child
                     INNER JOIN filing_evidence_bundles bundle ON bundle.id = child.bundle_id
                     WHERE bundle.company_id = :company_id AND bundle.accounting_period_id = :period_id
                     ORDER BY child.id',
                    $params
                ),
            ];
        };

        /** @return array<string,int> */
        $ap79ImmutableCounts = static function () use ($ap79RelevantRows): array {
            $rows = $ap79RelevantRows();
            return array_map('count', $rows);
        };

        /** @return array<string,mixed> */
        $ap79StoredState = static function () use (
            $companyId,
            $accountingPeriodId,
            $ap79RelevantRows
        ): array {
            $params = ['company_id' => $companyId, 'period_id' => $accountingPeriodId];
            return [
                'disclosure' => InterfaceDB::fetchOne(
                    'SELECT * FROM ixbrl_accounts_disclosures
                     WHERE company_id = :company_id AND accounting_period_id = :period_id',
                    $params
                ),
                'authorisation' => InterfaceDB::fetchOne(
                    'SELECT * FROM ct600_return_authorisations
                     WHERE company_id = :company_id AND accounting_period_id = :period_id',
                    $params
                ),
                'relevant_rows' => $ap79RelevantRows(),
            ];
        };

        $requireAp79 = static function (
            GeneratedServiceClassTestHarness $h,
            \eel_accounts\Service\IxbrlFilingApprovalWorkflowService $service,
            bool $requireStaleV8 = false
        ) use ($companyId, $accountingPeriodId, $staleApprovalId): array {
            $status = $service->status($companyId, $accountingPeriodId);
            $authorisation = (new \eel_accounts\Service\Ct600ReturnAuthorisationService())->fetch(
                $companyId,
                $accountingPeriodId
            );
            $disclosure = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->fetch(
                $companyId,
                $accountingPeriodId
            );
            $activePeriods = (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM corporation_tax_periods
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND status <> :superseded',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'superseded' => 'superseded',
                ]
            );
            $h->assertSame(true, (bool)($status['available'] ?? false));
            $h->assertSame(64, strlen((string)($status['state_token'] ?? '')));
            $h->assertTrue($authorisation !== []);
            $h->assertSame(true, (bool)($disclosure['complete'] ?? false));
            $h->assertSame(true, (bool)($disclosure['profile_supported'] ?? false));
            $h->assertSame(2, $activePeriods);
            if ($requireStaleV8) {
                $h->assertSame($staleApprovalId, (int)(($status['accounts'] ?? [])['approval_id'] ?? 0));
                $h->assertSame(
                    'accounts-filing-approval-v8',
                    (string)((($status['accounts'] ?? [])['approval'] ?? [])['basis_version'] ?? '')
                );
                $h->assertSame(false, (bool)(($status['accounts'] ?? [])['native_current'] ?? true));
                $h->assertSame(0, (int)(($status['hmrc'] ?? [])['approval_id'] ?? 0));
                $h->assertSame(false, (bool)($status['both_current'] ?? true));
                $h->assertSame(true, (bool)($status['can_approve'] ?? false));
                $h->assertSame([], (array)($status['blockers'] ?? []));
            }
            return $status;
        };

        $h->check($service::class, 'fails closed without an accounting context', static function () use ($h, $service): void {
            $status = $service->status(0, 0);
            $h->assertSame(false, (bool)($status['can_approve'] ?? true));
            $h->assertSame(false, (bool)($status['both_current'] ?? true));
            $h->assertSame('', (string)($status['state_token'] ?? 'missing'));
            $h->assertTrue(str_contains(implode(' ', (array)($status['blockers'] ?? [])), 'Select a company'));
            $h->assertTrue(str_contains(
                implode(' ', (array)($status['external_blockers'] ?? [])),
                'Select a company'
            ));
            $h->assertSame([], (array)($status['form_blockers'] ?? []));
        });

        $h->check($service::class, 'canonicalises the concurrency token deterministically', static function () use ($h, $invoke): void {
            $left = [
                'state_version' => 'test',
                'lock' => ['locked_at' => '2026-08-03 10:00:00', 'id' => 7],
                'periods' => [['run' => ['hash' => 'abc', 'id' => 12], 'id' => 4]],
            ];
            $right = [
                'periods' => [['id' => 4, 'run' => ['id' => 12, 'hash' => 'abc']]],
                'lock' => ['id' => 7, 'locked_at' => '2026-08-03 10:00:00'],
                'state_version' => 'test',
            ];
            $first = (string)$invoke('tokenForSnapshot', $left);
            $second = (string)$invoke('tokenForSnapshot', $right);

            $h->assertSame(64, strlen($first));
            $h->assertSame($first, $second);
            $right['periods'][0]['run']['hash'] = 'changed';
            $h->assertFalse(hash_equals($first, (string)$invoke('tokenForSnapshot', $right)));
        });

        $h->check($service::class, 'allows draft saving but blocks direct approval when a required Directors Report has blank Year End Notes', static function () use (
            $h,
            $service,
            $companyId,
            $accountingPeriodId,
            $ap79Input,
            $ap79ImmutableCounts
        ): void {
            InterfaceDB::beginTransaction();
            InterfaceDB::prepareExecute(
                'UPDATE year_end_reviews
                 SET review_notes = NULL
                 WHERE company_id = :company_id AND accounting_period_id = :period_id',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            InterfaceDB::prepareExecute(
                'UPDATE ixbrl_accounts_disclosures
                 SET directors_report_exempt_section_415a = 0
                 WHERE company_id = :company_id AND accounting_period_id = :period_id',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            \eel_accounts\Support\RequestCache::reset();
            $status = $service->status($companyId, $accountingPeriodId);
            $blockers = implode(' ', (array)($status['form_blockers'] ?? []));
            $h->assertTrue(str_contains($blockers, 'Enter Year End Notes'));
            $h->assertFalse((bool)($status['can_approve'] ?? true));

            $input = $ap79Input();
            $input['directors_report_exempt_section_415a'] = '0';
            $input['profit_loss_not_delivered_section_444'] = '1';
            $before = $ap79ImmutableCounts();
            $draft = $service->saveDraft(
                $companyId,
                $accountingPeriodId,
                $input,
                'workflow-test',
                (string)$status['state_token']
            );
            $h->assertTrue((bool)($draft['success'] ?? false));

            $afterDraftStatus = $service->status($companyId, $accountingPeriodId);
            try {
                $service->approveAll(
                    $companyId,
                    $accountingPeriodId,
                    $input,
                    'workflow-test',
                    '',
                    (string)$afterDraftStatus['state_token']
                );
                $h->assertTrue(false);
            } catch (RuntimeException $exception) {
                $h->assertTrue(str_contains($exception->getMessage(), 'Enter Year End Notes'));
            }
            $h->assertSame($before, $ap79ImmutableCounts());
        });

        $h->check($service::class, 'detects a database state change before any draft write', static function () use ($h, $invoke): void {
            InterfaceDB::beginTransaction();
            $number = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:name, :number, 1)',
                ['name' => 'Workflow token fixture', 'number' => $number]
            );
            $companyId = (int)(InterfaceDB::fetchColumn(
                InterfaceDB::driverName() === 'sqlite'
                    ? 'SELECT last_insert_rowid()'
                    : 'SELECT LAST_INSERT_ID()'
            ) ?: 0);
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Workflow token ' . $companyId,
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $accountingPeriodId = (int)(InterfaceDB::fetchColumn(
                InterfaceDB::driverName() === 'sqlite'
                    ? 'SELECT last_insert_rowid()'
                    : 'SELECT LAST_INSERT_ID()'
            ) ?: 0);

            $before = (string)$invoke(
                'tokenForSnapshot',
                (array)$invoke('stateSnapshot', $companyId, $accountingPeriodId, false)
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (
                    company_id, accounting_period_id, is_locked, locked_at, locked_by
                 ) VALUES (
                    :company_id, :period_id, 1, :locked_at, :locked_by
                 )',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'locked_at' => '2026-08-03 10:00:00',
                    'locked_by' => 'workflow-test',
                ]
            );
            $after = (string)$invoke(
                'tokenForSnapshot',
                (array)$invoke('stateSnapshot', $companyId, $accountingPeriodId, false)
            );
            $h->assertFalse(hash_equals($before, $after));

            try {
                $invoke('assertExpectedState', $companyId, $accountingPeriodId, $before);
                $h->assertTrue(false, 'Expected the stale state token to be rejected.');
            } catch (RuntimeException $exception) {
                $h->assertTrue(str_contains($exception->getMessage(), 'changed after this page was loaded'));
            }
            $invoke('assertExpectedState', $companyId, $accountingPeriodId, $after);
            $h->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM ixbrl_accounts_disclosures
                 WHERE company_id = :company_id AND accounting_period_id = :period_id',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            ));
            $h->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM ct600_return_authorisations
                 WHERE company_id = :company_id AND accounting_period_id = :period_id',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            ));
        });

        $h->check($service::class, 'keeps draft and approval responsibilities separated', static function () use ($h): void {
            $source = (string)file_get_contents(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlFilingApprovalWorkflowService.php'
            );
            $draft = strstr($source, 'public function saveDraft(');
            $draft = strstr((string)$draft, 'public function approveAll(', true);

            $h->assertTrue(str_contains((string)$draft, 'saveDraftIfChanged('));
            $h->assertFalse(str_contains((string)$draft, 'approveAndBuildFacts('));
            $h->assertFalse(str_contains((string)$draft, 'prepareHmrcCtPeriodFilingBases('));
            $h->assertFalse(str_contains((string)$draft, 'HmrcCtFilingApprovalService'));
            $h->assertTrue(str_contains($source, '\\InterfaceDB::transaction'));
            $h->assertTrue(str_contains($source, 'approveAndBuildFacts('));
            $h->assertTrue(str_contains($source, 'prepareHmrcCtPeriodFilingBases('));
            $h->assertTrue(str_contains($source, "'already_current' => true"));
            $h->assertTrue(str_contains($source, "&& !\$bothCurrent"));
            $h->assertFalse(str_contains($source, 'Transmit'));
        });

        $h->check(
            $service::class,
            'reuses an unchanged AP79 draft without writing immutable evidence or CT authorisation',
            static function () use (
                $h,
                $service,
                $ap79Input,
                $ap79StoredState,
                $requireAp79,
                $companyId,
                $accountingPeriodId
            ): void {
                $status = $requireAp79($h, $service);
                $before = $ap79StoredState();
                $beforeAuthorisation = (array)($before['authorisation'] ?? []);

                InterfaceDB::beginTransaction();
                try {
                    $result = $service->saveDraft(
                        $companyId,
                        $accountingPeriodId,
                        $ap79Input(),
                        'workflow-integration-test',
                        (string)$status['state_token']
                    );
                    $inside = $ap79StoredState();

                    $h->assertSame(true, (bool)($result['success'] ?? false));
                    $h->assertSame(false, (bool)($result['disclosures_changed'] ?? true));
                    $h->assertSame(false, (bool)($result['authorisation_changed'] ?? true));
                    $h->assertSame((int)$beforeAuthorisation['id'], (int)($result['authorisation_id'] ?? 0));
                    $h->assertSame($before, $inside);
                    $h->assertSame(64, strlen((string)($result['state_token'] ?? '')));
                } finally {
                    if (InterfaceDB::inTransaction()) {
                        InterfaceDB::rollBack();
                    }
                }

                $h->assertSame($before, $ap79StoredState());
            }
        );

        $h->check(
            $service::class,
            'rolls back an AP79 disclosure change when declarant validation fails later',
            static function () use (
                $h,
                $service,
                $ap79Input,
                $ap79StoredState,
                $requireAp79,
                $companyId,
                $accountingPeriodId
            ): void {
                $status = $requireAp79($h, $service);
                $before = $ap79StoredState();
                $input = $ap79Input();
                $employees = (int)($input['average_number_employees'] ?? 0);
                $input['average_number_employees'] = (string)($employees === 1 ? 2 : 1);
                $input['declarant_authority'] = 'director:9223372036854775807';
                $message = '';

                try {
                    $service->approveAll(
                        $companyId,
                        $accountingPeriodId,
                        $input,
                        'workflow-integration-test',
                        'Must roll back after declarant validation',
                        (string)$status['state_token']
                    );
                } catch (RuntimeException $exception) {
                    $message = $exception->getMessage();
                } finally {
                    // Defensive cleanup if transaction ownership itself
                    // regresses; the assertions below still expose the write.
                    if (InterfaceDB::inTransaction()) {
                        InterfaceDB::rollBack();
                    }
                }

                $h->assertTrue(str_contains($message, 'eligible authority'));
                $h->assertSame($before, $ap79StoredState());
            }
        );

        $h->check(
            $service::class,
            'creates and then idempotently reuses the complete AP79 approval evidence',
            static function () use (
                $h,
                $service,
                $ap79Input,
                $ap79ImmutableCounts,
                $ap79StoredState,
                $requireAp79,
                $companyId,
                $accountingPeriodId,
                $staleApprovalId
            ): void {
                $status = $requireAp79($h, $service, true);
                $before = $ap79StoredState();
                $beforeCounts = $ap79ImmutableCounts();
                $beforeAuthorisation = (array)$before['authorisation'];
                $first = [];
                $second = [];

                InterfaceDB::beginTransaction();
                try {
                    $first = $service->approveAll(
                        $companyId,
                        $accountingPeriodId,
                        $ap79Input(),
                        'workflow-integration-test',
                        'Rolled-back AP79 combined approval integration test',
                        (string)$status['state_token']
                    );
                    $afterFirst = $ap79ImmutableCounts();

                    $h->assertSame(true, (bool)($first['success'] ?? false));
                    $h->assertSame(true, (bool)($first['accounts_approval_created'] ?? false));
                    $h->assertSame(true, (bool)($first['fact_run_created'] ?? false));
                    $h->assertSame(true, (bool)($first['hmrc_approval_created'] ?? false));
                    $h->assertSame(false, (bool)($first['authorisation_changed'] ?? true));
                    $h->assertSame(2, count((array)($first['ct_basis_ids'] ?? [])));
                    $h->assertSame(2, count((array)($first['ct_basis_created_ids'] ?? [])));
                    $h->assertTrue((int)($first['accounts_approval_id'] ?? 0) > $staleApprovalId);
                    $h->assertTrue((int)($first['fact_run_id'] ?? 0) > 0);
                    $h->assertTrue((int)($first['hmrc_approval_id'] ?? 0) > 0);
                    $h->assertSame((int)$beforeAuthorisation['id'], (int)($first['authorisation_id'] ?? 0));

                    $storedAuthorisation = (array)(InterfaceDB::fetchOne(
                        'SELECT * FROM ct600_return_authorisations
                         WHERE company_id = :company_id AND accounting_period_id = :period_id',
                        ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
                    ) ?: []);
                    $h->assertSame((string)$beforeAuthorisation['saved_at'], (string)($storedAuthorisation['saved_at'] ?? ''));
                    $h->assertSame((string)$beforeAuthorisation['saved_by'], (string)($storedAuthorisation['saved_by'] ?? ''));
                    $h->assertSame((int)$beforeAuthorisation['id'], (int)($storedAuthorisation['id'] ?? 0));
                    $h->assertSame(
                        \eel_accounts\Service\IxbrlAccountsFilingApprovalService::BASIS_VERSION,
                        (string)InterfaceDB::fetchColumn(
                            'SELECT basis_version FROM ixbrl_accounts_filing_approvals WHERE id = :id',
                            ['id' => (int)$first['accounts_approval_id']]
                        )
                    );
                    $h->assertTrue((int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM ixbrl_generation_facts WHERE run_id = :run_id',
                        ['run_id' => (int)$first['fact_run_id']]
                    ) > 0);
                    $h->assertSame((int)$beforeCounts['accounts_approvals'] + 1, $afterFirst['accounts_approvals']);
                    $h->assertSame((int)$beforeCounts['approved_fact_runs'] + 1, $afterFirst['approved_fact_runs']);
                    $h->assertTrue($afterFirst['approved_facts'] > (int)$beforeCounts['approved_facts']);
                    $h->assertSame((int)$beforeCounts['ct_bases'] + 2, $afterFirst['ct_bases']);
                    $h->assertSame((int)$beforeCounts['hmrc_approvals'] + 1, $afterFirst['hmrc_approvals']);
                    $h->assertSame((int)$beforeCounts['hmrc_basis_links'] + 2, $afterFirst['hmrc_basis_links']);
                    $h->assertSame((int)$beforeCounts['audit_log'] + 1, $afterFirst['audit_log']);
                    $h->assertSame(
                        (int)$beforeCounts['evidence_events'] + 1,
                        $afterFirst['evidence_events']
                    );
                    foreach ([
                        'evidence_bundles',
                        'evidence_loan_snapshots',
                        'evidence_ct_snapshots',
                        'evidence_artifacts',
                        'evidence_sections',
                        'authority_artifacts',
                    ] as $evidenceKey) {
                        $h->assertSame($beforeCounts[$evidenceKey], $afterFirst[$evidenceKey]);
                    }

                    $factRunId = (int)$first['fact_run_id'];
                    $originalFactBasisHash = (string)InterfaceDB::fetchColumn(
                        'SELECT basis_hash FROM ixbrl_generation_runs WHERE id = :id',
                        ['id' => $factRunId]
                    );
                    $tamperedFactBasisHash = $originalFactBasisHash
                        === str_repeat('0', 64) ? str_repeat('1', 64) : str_repeat('0', 64);
                    InterfaceDB::prepareExecute(
                        'UPDATE ixbrl_generation_runs SET basis_hash = :basis_hash WHERE id = :id',
                        ['basis_hash' => $tamperedFactBasisHash, 'id' => $factRunId]
                    );
                    \eel_accounts\Support\RequestCache::reset();
                    $tampered = $service->status($companyId, $accountingPeriodId);
                    $h->assertSame(false, (bool)($tampered['both_current'] ?? true));
                    $h->assertSame(null, ($tampered['accounts'] ?? [])['fact_run_id'] ?? null);

                    InterfaceDB::prepareExecute(
                        'UPDATE ixbrl_generation_runs SET basis_hash = :basis_hash WHERE id = :id',
                        ['basis_hash' => $originalFactBasisHash, 'id' => $factRunId]
                    );
                    \eel_accounts\Support\RequestCache::reset();
                    $restored = $service->status($companyId, $accountingPeriodId);
                    $h->assertSame(true, (bool)($restored['both_current'] ?? false));
                    $h->assertSame($factRunId, (int)(($restored['accounts'] ?? [])['fact_run_id'] ?? 0));

                    $refreshedToken = (string)($restored['state_token'] ?? '');
                    $h->assertSame(64, strlen($refreshedToken));
                    $second = $service->approveAll(
                        $companyId,
                        $accountingPeriodId,
                        $ap79Input(),
                        'workflow-integration-test',
                        'A repeated submit must not create another audit',
                        $refreshedToken
                    );

                    $h->assertSame(true, (bool)($second['success'] ?? false));
                    $h->assertSame(true, (bool)($second['already_current'] ?? false));
                    $h->assertSame(false, (bool)($second['accounts_approval_created'] ?? true));
                    $h->assertSame(false, (bool)($second['fact_run_created'] ?? true));
                    $h->assertSame(false, (bool)($second['hmrc_approval_created'] ?? true));
                    $h->assertSame((int)$first['accounts_approval_id'], (int)($second['accounts_approval_id'] ?? 0));
                    $h->assertSame((int)$first['fact_run_id'], (int)($second['fact_run_id'] ?? 0));
                    $h->assertSame((int)$first['hmrc_approval_id'], (int)($second['hmrc_approval_id'] ?? 0));
                    $h->assertSame((array)$first['ct_basis_ids'], (array)($second['ct_basis_ids'] ?? []));
                    $h->assertSame($afterFirst, $ap79ImmutableCounts());
                } finally {
                    if (InterfaceDB::inTransaction()) {
                        InterfaceDB::rollBack();
                    }
                }

                $h->assertSame($before, $ap79StoredState());
            }
        );
    }
);
