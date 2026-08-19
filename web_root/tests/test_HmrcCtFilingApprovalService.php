<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtFilingApprovalService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\HmrcCtFilingApprovalService $service
    ): void {
        $invoke = static function (string $method, mixed ...$arguments) use ($service): mixed {
            $reflection = new ReflectionMethod($service, $method);
            $reflection->setAccessible(true);
            return $reflection->invoke($service, ...$arguments);
        };

        $h->check($service::class, 'fails closed without an accounting context', static function () use ($h, $service): void {
            $status = $service->status(0, 0);
            $h->assertSame('absent', (string)($status['state'] ?? ''));
            $h->assertSame(false, (bool)($status['current'] ?? true));
            $h->assertSame(false, (bool)($status['can_approve'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)($status['errors'] ?? [])), 'Select a company'));
        });

        $h->check($service::class, 'uses a separate versioned HMRC filing basis', static function () use ($h, $service): void {
            $h->assertSame('hmrc-ct-filing-approval-v1', $service::BASIS_VERSION);
        });

        $h->check($service::class, 'uses an append-only period-basis junction', static function () use ($h): void {
            $migration = (string)file_get_contents(
                dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema'
                . DIRECTORY_SEPARATOR . 'migrations'
                . DIRECTORY_SEPARATOR . '2026_08_03_003_hmrc_ct_approval_period_basis_links.sql'
            );
            $schema = (string)file_get_contents(
                dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema'
                . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
            );
            $table = 'hmrc_ct_filing_approval_period_bases';

            $h->assertTrue(InterfaceDB::tableExists($table));
            foreach ([
                'hmrc_ct_filing_approval_id', 'ct_period_filing_basis_id',
                'ct_period_id', 'basis_hash', 'created_at',
            ] as $column) {
                $h->assertTrue(InterfaceDB::columnExists($table, $column));
            }
            $h->assertTrue(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table));
            $h->assertTrue(str_contains($migration, 'INSERT IGNORE INTO ' . $table));
            $h->assertTrue(str_contains(
                $migration,
                'REFERENCES hmrc_ct_filing_approvals (id) ON DELETE RESTRICT'
            ));
            $h->assertTrue(str_contains($schema, 'CREATE TABLE `' . $table . '`'));
            $h->assertTrue(str_contains(
                $schema,
                'UNIQUE KEY `uq_hmrc_ct_approval_period` (`hmrc_ct_filing_approval_id`,`ct_period_id`)'
            ));
            $dropJunction = strpos($schema, 'DROP TABLE IF EXISTS `hmrc_ct_filing_approval_period_bases`');
            $dropValidation = strpos($schema, 'DROP TABLE IF EXISTS `ixbrl_validation_runs`');
            $dropArtifacts = strpos($schema, 'DROP TABLE IF EXISTS `ixbrl_accounts_artifacts`');
            $dropApprovals = strpos($schema, 'DROP TABLE IF EXISTS `hmrc_ct_filing_approvals`');
            $h->assertTrue(is_int($dropJunction) && is_int($dropValidation));
            $h->assertTrue(is_int($dropArtifacts) && is_int($dropApprovals));
            $h->assertTrue($dropJunction < $dropValidation);
            $h->assertTrue($dropValidation < $dropArtifacts);
            $h->assertTrue($dropArtifacts < $dropApprovals);
            $h->assertTrue(str_contains(
                $schema,
                "('2026_08_03_003_hmrc_ct_approval_period_basis_links.sql')"
            ));
        });

        $h->check($service::class, 'accepts only native accounts evidence or verified legacy combined evidence', static function () use ($h, $invoke): void {
            $native = ['basis_version' => \eel_accounts\Service\IxbrlAccountsFilingApprovalService::BASIS_VERSION];
            $h->assertSame(true, (bool)$invoke('isRecognisedAccountsApproval', $native, $native));

            $unknown = ['basis_version' => 'accounts-filing-approval-v999'];
            $h->assertSame(false, (bool)$invoke('isRecognisedAccountsApproval', $unknown, $unknown));

            $legacy = [
                'basis_version' => 'accounts-filing-approval-v8',
                'corporation_tax_return_authorisation' => ['declarant_status' => 'Director'],
                'corporation_tax_filing_scope' => ['answers' => ['ct600b' => 'no']],
                'ct_periods' => [['id' => 91]],
            ];
            $h->assertSame(true, (bool)$invoke(
                'isRecognisedAccountsApproval',
                ['basis_version' => 'accounts-filing-approval-v8', 'basis_json' => json_encode($legacy)],
                $legacy
            ));
            unset($legacy['ct_periods']);
            $h->assertSame(false, (bool)$invoke(
                'isRecognisedAccountsApproval',
                ['basis_version' => 'accounts-filing-approval-v8', 'basis_json' => json_encode($legacy)],
                $legacy
            ));
        });

        $h->check($service::class, 'canonicalises nested approval evidence deterministically', static function () use ($h, $invoke): void {
            $left = $invoke('canonicalJson', [
                'z' => ['b' => 2, 'a' => 1],
                'a' => [['d' => 4, 'c' => 3]],
            ]);
            $right = $invoke('canonicalJson', [
                'a' => [['c' => 3, 'd' => 4]],
                'z' => ['a' => 1, 'b' => 2],
            ]);
            $h->assertSame($left, $right);
            $h->assertSame(hash('sha256', (string)$left), hash('sha256', (string)$right));
        });

        $h->check($service::class, 'freezes a structured return authorisation without display-only fields', static function () use ($h, $invoke): void {
            $snapshot = (array)$invoke('authorisationSnapshot', [
                'id' => 41,
                'declarant_name' => ' Jane Smith ',
                'declarant_status' => 'Director',
                'declarant_party_id' => null,
                'declarant_director_id' => 17,
                'declarant_role_id' => null,
                'original_unfiled_confirmed' => 1,
                'authority_confirmed' => 1,
                'declaration_confirmed' => 1,
                'saved_at' => '2026-08-03 12:30:00',
                'saved_by' => 'user:7',
                'saved_by_display_name' => 'Ignored Display Name',
            ]);

            $h->assertSame(41, (int)$snapshot['id']);
            $h->assertSame('Jane Smith', (string)$snapshot['declarant_name']);
            $h->assertSame(17, (int)$snapshot['declarant_director_id']);
            $h->assertSame(true, (bool)$snapshot['declaration_confirmed']);
            $h->assertSame('2026-08-03 12:30:00', (string)$snapshot['declared_at']);
            $h->assertSame(false, array_key_exists('saved_by_display_name', $snapshot));
        });

        $h->check($service::class, 'matches an existing approval only to the exact request declaration', static function () use ($h, $invoke, $service): void {
            $authorisation = [
                'id' => 41,
                'declarant_name' => 'Jane Smith',
                'declarant_status' => 'Director',
                'declarant_director_id' => 17,
                'original_unfiled_confirmed' => 1,
                'authority_confirmed' => 1,
                'declaration_confirmed' => 1,
                'saved_at' => '2026-08-03 12:30:00',
                'saved_by' => 'user:7',
            ];
            $snapshot = (array)$invoke('authorisationSnapshot', $authorisation);
            $json = (string)$invoke('canonicalJson', $snapshot);
            $approval = [
                'return_authorisation_json' => $json,
                'return_authorisation_hash' => hash('sha256', $json),
            ];
            $h->assertSame(true, $service->approvalMatchesAuthorisation($approval, $authorisation));
            $authorisation['saved_by'] = 'user:8';
            $h->assertSame(false, $service->approvalMatchesAuthorisation($approval, $authorisation));
            $approval['return_authorisation_hash'] = str_repeat('0', 64);
            $h->assertSame(false, $service->approvalMatchesAuthorisation($approval, $authorisation));
        });

        $h->check($service::class, 'rejects stale UTR scope and profile inputs embedded in period evidence', static function () use ($h, $invoke): void {
            $row = ['ct_period_status' => 'submitted', 'ct_period_id' => 91];
            $inputs = [
                'utr' => '1234567890',
                'ct_scope' => ['revision' => 4],
                'supported_return_profile' => ['profile_code' => 'ordinary_frs105'],
            ];
            $model = [
                'filing_identity' => ['utr' => '1234567890'],
                'corporation_tax_filing_scope' => $inputs['ct_scope'],
                'supported_return_profile' => $inputs['supported_return_profile'],
            ];
            $invoke('assertCurrentHmrcInputs', 49, 79, $row, $model, $inputs);

            foreach ([
                ['utr', '9999999999', 'UTR changed'],
                ['ct_scope', ['revision' => 5], 'filing scope changed'],
                ['supported_return_profile', ['profile_code' => 'changed'], 'return profile changed'],
            ] as [$key, $changed, $message]) {
                $stale = $inputs;
                $stale[$key] = $changed;
                try {
                    $invoke('assertCurrentHmrcInputs', 49, 79, $row, $model, $stale);
                    $h->assertTrue(false, 'Expected stale HMRC input rejection.');
                } catch (RuntimeException $exception) {
                    $h->assertTrue(str_contains($exception->getMessage(), $message));
                }
            }
        });

        $h->check($service::class, 'matches only the complete native frozen approval', static function () use ($h, $invoke): void {
            $authorisation = [
                'id' => 41,
                'declarant_name' => 'Jane Smith',
                'declarant_status' => 'Director',
            ];
            $authorisationJson = (string)$invoke('canonicalJson', $authorisation);
            $scope = ['scope_version' => 'scope-v1', 'revision' => 2, 'answers' => ['ct600b' => 'no']];
            $scopeJson = (string)$invoke('canonicalJson', $scope);
            $basis = [
                'basis_version' => \eel_accounts\Service\HmrcCtFilingApprovalService::BASIS_VERSION,
                'accounts_filing_approval' => ['id' => 11, 'basis_hash' => str_repeat('a', 64)],
            ];
            $basisJson = (string)$invoke('canonicalJson', $basis);
            $candidate = [
                'accounts_approval' => ['id' => 11, 'basis_hash' => str_repeat('a', 64)],
                'return_authorisation' => $authorisation,
                'return_authorisation_json' => $authorisationJson,
                'return_authorisation_hash' => hash('sha256', $authorisationJson),
                'ct_scope_json' => $scopeJson,
                'ct_scope_hash' => hash('sha256', $scopeJson),
                'basis_json' => $basisJson,
                'basis_hash' => hash('sha256', $basisJson),
            ];
            $approval = [
                'basis_version' => \eel_accounts\Service\HmrcCtFilingApprovalService::BASIS_VERSION,
                'accounts_filing_approval_id' => 11,
                'accounts_filing_approval_hash' => str_repeat('a', 64),
                'return_authorisation_id' => 41,
                'return_authorisation_json' => $authorisationJson,
                'return_authorisation_hash' => hash('sha256', $authorisationJson),
                'ct_scope_json' => $scopeJson,
                'ct_scope_hash' => hash('sha256', $scopeJson),
                'basis_json' => $basisJson,
                'basis_hash' => hash('sha256', $basisJson),
            ];

            $h->assertSame(true, (bool)$invoke('nativeApprovalMatchesCandidate', $approval, $candidate));
            $approval['return_authorisation_hash'] = str_repeat('b', 64);
            $h->assertSame(false, (bool)$invoke('nativeApprovalMatchesCandidate', $approval, $candidate));
        });

        $h->check($service::class, 'adapts a verified legacy combined approval without inventing a native id', static function () use ($h, $invoke): void {
            $approvalBasis = [
                'corporation_tax_return_authorisation' => [
                    'declarant_name' => 'Jane Smith',
                    'declarant_status' => 'Director',
                    'declaration_at' => '2026-08-03 12:30:00',
                    'declarant_party_id' => null,
                    'declarant_director_id' => 17,
                    'declarant_role_id' => null,
                    'original_unfiled_confirmed' => true,
                    'authority_confirmed' => true,
                    'declaration_confirmed' => true,
                ],
                'corporation_tax_filing_scope' => [
                    'scope_version' => 'scope-v1',
                    'revision' => 2,
                    'answers' => ['ct600b' => 'no'],
                    'basis_hash' => str_repeat('c', 64),
                ],
                'ct_periods' => [[
                    'id' => 31,
                    'computation_run_id' => 51,
                    'computation_hash' => str_repeat('d', 64),
                    'calculation_basis_version' => 'calc-v1',
                    'calculation_basis_hash' => str_repeat('e', 64),
                ]],
            ];
            $approvalJson = (string)$invoke('canonicalJson', $approvalBasis);
            $approval = [
                'id' => 11,
                'basis_json' => $approvalJson,
                'basis_hash' => hash('sha256', $approvalJson),
                'approved_by' => 'user:7',
                'approved_at' => '2026-08-03 12:35:00',
            ];
            $candidateBasisJson = (string)$invoke('canonicalJson', ['candidate' => 'hmrc']);
            $candidate = [
                'accounts_approval' => $approval,
                'return_authorisation' => [
                    'id' => 41,
                    'declarant_name' => 'Jane Smith',
                    'declarant_status' => 'Director',
                    'declarant_party_id' => null,
                    'declarant_director_id' => 17,
                    'declarant_role_id' => null,
                    'original_unfiled_confirmed' => true,
                    'authority_confirmed' => true,
                    'declaration_confirmed' => true,
                    'declared_at' => '2026-08-03 12:30:00',
                ],
                'ct_scope' => [
                    'scope_version' => 'scope-v1',
                    'revision' => 2,
                    'answers' => ['ct600b' => 'no'],
                ],
                'ct_scope_hash' => str_repeat('c', 64),
                'ct_period_bases' => [[
                    'ct_period_id' => 31,
                    'computation_run_id' => 51,
                    'computation_hash' => str_repeat('d', 64),
                    'calculation_basis_version' => 'calc-v1',
                    'calculation_basis_hash' => str_repeat('e', 64),
                ]],
                'basis_json' => $candidateBasisJson,
                'basis_hash' => hash('sha256', $candidateBasisJson),
            ];

            $adapter = $invoke('legacyAdapter', $approval, $candidate);
            $h->assertTrue(is_array($adapter));
            $h->assertSame(true, array_key_exists('id', $adapter));
            $h->assertSame(null, $adapter['id']);
            $h->assertSame(false, (bool)($adapter['persisted'] ?? true));
            $h->assertSame('legacy_combined', (string)($adapter['source'] ?? ''));
            $h->assertSame(11, (int)($adapter['legacy_combined_approval_id'] ?? 0));

            $candidate['ct_period_bases'][0]['calculation_basis_hash'] = str_repeat('f', 64);
            $h->assertSame(null, $invoke('legacyAdapter', $approval, $candidate));
        });

        $h->check($service::class, 'keeps the atomic append-only insert while allowing an outer workflow transaction', static function () use ($h): void {
            $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'HmrcCtFilingApprovalService.php');
            $h->assertTrue(str_contains($source, '\\InterfaceDB::transaction'));
            $h->assertFalse(str_contains($source, 'must own its transaction'));
            $h->assertFalse(str_contains($source, 'if (\\InterfaceDB::inTransaction())'));
            $h->assertTrue(str_contains($source, 'INSERT INTO hmrc_ct_filing_approvals'));
            $h->assertTrue(str_contains($source, 'INSERT INTO hmrc_ct_filing_approval_period_bases'));
            $h->assertTrue(str_contains($source, 'approval_basis.ct_period_filing_basis_id'));
            $h->assertFalse(str_contains($source, 'SELECT COALESCE('));
            $h->assertTrue(str_contains($source, '$expectedBasisIds'));
            $h->assertFalse(str_contains($source, 'SET hmrc_ct_filing_approval_id ='));
            $h->assertFalse(str_contains($source, 'UPDATE ct_period_filing_bases'));
            $h->assertTrue(str_contains($source, '$this->verifyPersisted'));
            $h->assertTrue(str_contains($source, '$this->nativeApprovalLinksMatchCandidate'));
            $h->assertTrue(str_contains($source, "'legacy_combined_approval_id'"));
            $h->assertTrue(str_contains($source, 'isRecognisedAccountsApproval'));
            $h->assertTrue(str_contains($source, 'accounts-filing-approval-v[1-8]'));
            $h->assertTrue(str_contains($source, '$expectedAuthorisation'));
            $h->assertTrue(str_contains($source, 'new Ct600aService())->build('));
            $h->assertTrue(str_contains($source, "forgetNamespace('tax.ct600a')"));
        });

        $h->check($service::class, 'uses cached stored-identity verification for the render read model', static function () use ($h): void {
            $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'HmrcCtFilingApprovalService.php');
            $readModel = strstr($source, 'public function statusForReadModel(');
            $readModel = strstr((string)$readModel, 'public function current(', true);
            $h->assertTrue(is_string($readModel));
            $h->assertTrue(str_contains((string)$readModel, 'hmrc.ct-filing-approval.status-read-model'));
            $h->assertTrue(str_contains((string)$readModel, 'statusForReadModel('));
            $h->assertTrue(str_contains((string)$readModel, 'hmrc_ct_filing_approval_period_bases'));
            $h->assertTrue(str_contains((string)$readModel, 'latest_computation_run_id'));
            $h->assertTrue(str_contains((string)$readModel, "hash('sha256', \$basisJson)"));
            $h->assertFalse(str_contains((string)$readModel, 'Ct600aService'));
            $h->assertFalse(str_contains((string)$readModel, '$this->candidate('));
        });
    }
);
