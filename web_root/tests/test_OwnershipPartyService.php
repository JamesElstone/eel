<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\OwnershipPartyService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\OwnershipPartyService $service): void {
        $harness->check(get_class($service), 'requires a selected company', static function () use ($harness, $service): void {
            $summary = $service->fetchSummary(0);
            $harness->assertSame(false, (bool)($summary['available'] ?? true));
            $harness->assertSame([], $service->effectiveParties(0, '2026-07-19'));
        });
        $harness->check(get_class($service), 'rejects legacy shareholder roles', static function () use ($harness, $service): void {
            $result = $service->saveRole([
                'role_type' => 'shareholder',
                'effective_from' => '2026-07-19',
            ]);
            $harness->assertSame(false, (bool)($result['success'] ?? true));
        });
        $harness->check(get_class($service), 'uses filing-only invalidations for administrative role actions', static function () use ($harness): void {
            $action = new IncorporationAction();
            $method = new ReflectionMethod($action, 'changedFacts');
            $method->setAccessible(true);

            $administrativeFacts = (array)$method->invoke(
                $action,
                'save_ownership_role',
                ['success' => true, 'role_type' => 'authorised_agent']
            );
            $harness->assertSame([
                'page.context',
                'ownership.parties',
                'ct600.authorisers',
                'ixbrl.disclosures',
            ], $administrativeFacts);

            $loanFacts = (array)$method->invoke(
                $action,
                'save_ownership_role',
                ['success' => true, 'role_type' => 'participator']
            );
            $harness->assertTrue(in_array('tax.s455', $loanFacts, true));
            $harness->assertTrue(in_array('year.end.checklist', $loanFacts, true));
        });
        $harness->check(get_class($service), 'separates filing authorities from loan roles across a locked period', static function () use ($harness, $service): void {
            foreach (['companies', 'accounting_periods', 'year_end_reviews', 'company_parties', 'company_party_roles'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' schema is not available.');
                }
            }
            if (!InterfaceDB::columnExists('ct600_return_authorisations', 'declarant_role_id')) {
                $harness->skip('The filing-authority role migration is not applied.');
            }

            InterfaceDB::beginTransaction();
            try {
                $lastInsertId = static fn(): int => (int)(InterfaceDB::fetchColumn(
                    strtolower(InterfaceDB::driverName()) === 'sqlite'
                        ? 'SELECT last_insert_rowid()'
                        : 'SELECT LAST_INSERT_ID()'
                ) ?: 0);
                $marker = strtoupper(substr(hash('sha256', __FILE__ . microtime(true)), 0, 10));
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
                    ['name' => 'Ownership Authority Fixture Limited', 'number' => 'OAF' . $marker]
                );
                $companyId = $lastInsertId();
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => 'Locked authority fixture',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $periodId = $lastInsertId();
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews (
                        company_id, accounting_period_id, is_locked, locked_at, locked_by
                     ) VALUES (
                        :company_id, :period_id, 1, CURRENT_TIMESTAMP, :actor
                     )',
                    ['company_id' => $companyId, 'period_id' => $periodId, 'actor' => 'test']
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO company_parties (company_id, party_type, legal_name)
                     VALUES (:company_id, :party_type, :legal_name)',
                    ['company_id' => $companyId, 'party_type' => 'individual', 'legal_name' => 'Authority Person']
                );
                $partyId = $lastInsertId();

                $agent = $service->saveRole([
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'role_type' => 'authorised_agent',
                    'effective_from' => '2025-01-01',
                ]);
                $harness->assertSame(true, (bool)($agent['success'] ?? false));

                $duplicate = $service->saveRole([
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'role_type' => 'authorised_agent',
                    'effective_from' => '2025-06-01',
                    'effective_to' => '2025-06-30',
                ]);
                $harness->assertSame(false, (bool)($duplicate['success'] ?? true));

                $secretary = $service->saveRole([
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'role_type' => 'company_secretary',
                    'effective_from' => '2025-06-01',
                ]);
                $harness->assertSame(true, (bool)($secretary['success'] ?? false));
                $harness->assertSame([], $service->effectiveParties($companyId, '2025-06-15'));

                $roleId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM company_party_roles
                     WHERE company_id = :company_id AND party_id = :party_id AND role_type = :role_type',
                    ['company_id' => $companyId, 'party_id' => $partyId, 'role_type' => 'authorised_agent']
                );
                $ended = $service->endRole($companyId, $roleId, '2025-12-31');
                $harness->assertSame(true, (bool)($ended['success'] ?? false));

                try {
                    $service->saveRole([
                        'company_id' => $companyId,
                        'party_id' => $partyId,
                        'role_type' => 'participator',
                        'effective_from' => '2025-01-01',
                    ]);
                    $harness->assertTrue(false, 'Expected the locked-period guard to reject a participator role.');
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains($exception->getMessage(), 'Unlock every affected accounting period'));
                }
            } finally {
                \eel_accounts\Support\RequestCache::clear();
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);
