<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\Ct600ReturnAuthorisationService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\Ct600ReturnAuthorisationService $service
): void {
    $harness->check($service::class, 'ships a schema-only filing-authority migration', static function () use ($harness): void {
        $migration = file_get_contents(
            dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'db_schema'
            . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '2026_07_30_004_filing_authority_roles.sql'
        );
        $harness->assertTrue(is_string($migration) && $migration !== '');
        foreach ([
            'company_secretary',
            'authorised_agent',
            'authorised_employee',
            'tax_agent_or_accountant',
            'liquidator',
            'declarant_name',
            'declarant_party_id',
            'declarant_director_id',
            'declarant_role_id',
        ] as $required) {
            $harness->assertTrue(str_contains((string)$migration, $required));
        }
        $harness->assertSame(0, preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/im', (string)$migration));
    });

    $harness->check($service::class, 'rejects malformed authority references without trusting client identity fields', static function () use ($harness, $service): void {
        $result = $service->save(0, 0, [
            'declarant_authority' => 'director:1 OR 1=1',
            'declarant_name' => 'Browser supplied name',
            'declarant_status' => 'Browser supplied status',
            'original_unfiled_confirmed' => '1',
            'authority_confirmed' => '1',
            'declaration_confirmed' => '1',
        ], 'test');

        $harness->assertSame(false, (bool)($result['success'] ?? true));
    });

    $harness->check($service::class, 'renders one required structured authority selector', static function () use ($harness): void {
        $card = new _ixbrl_accounts_disclosuresCard();
        $method = new ReflectionMethod($card, 'ct600AuthorisationPanel');
        $method->setAccessible(true);
        $html = (string)$method->invoke($card, 0, 0, []);

        $harness->assertTrue(str_contains($html, 'name="declarant_authority" required'));
        $harness->assertFalse(str_contains($html, 'name="declarant_status"'));
        $harness->assertFalse(str_contains($html, 'name="declarant_name"'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="ct600_declarant_authority"'));
        $harness->assertTrue(str_contains($html, 'data-state-target="save_ct600_return_authorisation_button"'));
        $harness->assertTrue(str_contains($html, 'id="save_ct600_return_authorisation_button" type="submit" disabled'));
        $harness->assertTrue(str_contains($html, 'covers every CT600 for that the Accounting Period.'));
        $projectScript = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');
        $harness->assertTrue(is_string($projectScript) && str_contains($projectScript, 'initialiseCt600AuthorisationForms'));
        $harness->assertTrue(str_contains((string)$projectScript, 'confirmationChanged'));
        $harness->assertTrue(str_contains((string)$projectScript, 'initialiseCt600AuthorisationForms(node)'));
    });

    $harness->check($service::class, 'resolves current people and capacities and freezes the selected authority', static function () use ($harness, $service): void {
        foreach ([
            'companies',
            'accounting_periods',
            'company_directors',
            'company_parties',
            'company_party_roles',
            'company_incorporation_share_classes',
             'company_shareholdings',
             'ct600_return_authorisations',
             'users',
        ] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' schema is not available.');
            }
        }
        foreach (['declarant_name', 'declarant_party_id', 'declarant_director_id', 'declarant_role_id'] as $column) {
            if (!InterfaceDB::columnExists('ct600_return_authorisations', $column)) {
                $harness->skip('The structured CT600 authorisation migration is not applied.');
            }
        }

        $today = new DateTimeImmutable('today');
        $todayString = $today->format('Y-m-d');
        $periodStart = $today->modify('-18 months')->format('Y-m-d');
        $periodEnd = $today->modify('-3 months')->format('Y-m-d');
        $appointedAfterYearEnd = $today->modify('-1 month')->format('Y-m-d');
        $activeFrom = $today->modify('-2 months')->format('Y-m-d');
        $expiredOn = $today->modify('-1 day')->format('Y-m-d');
        $futureFrom = $today->modify('+1 day')->format('Y-m-d');

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
                ['name' => 'CT Authority Fixture Limited', 'number' => 'CTA' . $marker]
            );
            $companyId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
                ['name' => 'Other CT Authority Fixture Limited', 'number' => 'CTB' . $marker]
            );
            $otherCompanyId = $lastInsertId();

            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'CT authority fixture',
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]
            );
            $periodId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $otherCompanyId,
                    'label' => 'Other CT authority fixture',
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]
            );
            $otherPeriodId = $lastInsertId();

            InterfaceDB::prepareExecute(
                'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role, appointed_on, is_active
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
                 )',
                [
                    'company_id' => $companyId,
                    'source' => 'test',
                    'external_key' => 'ct-authority-director-' . $marker,
                    'full_name' => 'Jane Smith',
                    'officer_role' => 'director',
                    'appointed_on' => $appointedAfterYearEnd,
                ]
            );
            $directorId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role, appointed_on, is_active
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
                 )',
                [
                    'company_id' => $otherCompanyId,
                    'source' => 'test',
                    'external_key' => 'other-ct-authority-director-' . $marker,
                    'full_name' => 'Wrong Company Director',
                    'officer_role' => 'director',
                    'appointed_on' => $activeFrom,
                ]
            );
            $otherDirectorId = $lastInsertId();

            InterfaceDB::prepareExecute(
                'INSERT INTO company_parties (company_id, party_type, legal_name, linked_director_id)
                 VALUES (:company_id, :party_type, :legal_name, :director_id)',
                [
                    'company_id' => $companyId,
                    'party_type' => 'individual',
                    'legal_name' => 'Jane Smith',
                    'director_id' => $directorId,
                ]
            );
            $janePartyId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_parties (company_id, party_type, legal_name)
                 VALUES (:company_id, :party_type, :legal_name)',
                ['company_id' => $companyId, 'party_type' => 'individual', 'legal_name' => 'Admin Only Person']
            );
            $adminOnlyPartyId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_parties (company_id, party_type, legal_name)
                 VALUES (:company_id, :party_type, :legal_name)',
                ['company_id' => $companyId, 'party_type' => 'company', 'legal_name' => 'Agent Firm Limited']
            );
            $firmPartyId = $lastInsertId();

            InterfaceDB::prepareExecute(
                'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from)
                 VALUES (:company_id, :party_id, :role_type, :effective_from)',
                [
                    'company_id' => $companyId,
                    'party_id' => $janePartyId,
                    'role_type' => 'authorised_agent',
                    'effective_from' => $activeFrom,
                ]
            );
            $agentRoleId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from)
                 VALUES (:company_id, :party_id, :role_type, :effective_from)',
                [
                    'company_id' => $companyId,
                    'party_id' => $adminOnlyPartyId,
                    'role_type' => 'company_secretary',
                    'effective_from' => $activeFrom,
                ]
            );
            $secretaryRoleId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from)
                 VALUES (:company_id, :party_id, :role_type, :effective_from)',
                [
                    'company_id' => $companyId,
                    'party_id' => $adminOnlyPartyId,
                    'role_type' => 'liquidator',
                    'effective_from' => $futureFrom,
                ]
            );
            $futureRoleId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from, effective_to)
                 VALUES (:company_id, :party_id, :role_type, :effective_from, :effective_to)',
                [
                    'company_id' => $companyId,
                    'party_id' => $adminOnlyPartyId,
                    'role_type' => 'authorised_employee',
                    'effective_from' => $activeFrom,
                    'effective_to' => $expiredOn,
                ]
            );
            $expiredRoleId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from)
                 VALUES (:company_id, :party_id, :role_type, :effective_from)',
                [
                    'company_id' => $companyId,
                    'party_id' => $firmPartyId,
                    'role_type' => 'tax_agent_or_accountant',
                    'effective_from' => $activeFrom,
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO company_incorporation_share_classes (
                    company_id, issued_at, share_class, currency, quantity, nominal_value_per_share
                 ) VALUES (
                    :company_id, :issued_at, :share_class, :currency, :quantity, :nominal
                 )',
                [
                    'company_id' => $companyId,
                    'issued_at' => $periodStart . ' 00:00:00',
                    'share_class' => 'Ordinary',
                    'currency' => 'GBP',
                    'quantity' => 1,
                    'nominal' => '1.000000',
                ]
            );
            $shareClassId = $lastInsertId();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_shareholdings (
                    company_id, party_id, share_class_id, quantity, effective_from
                 ) VALUES (
                    :company_id, :party_id, :share_class_id, 1, :effective_from
                 )',
                [
                    'company_id' => $companyId,
                    'party_id' => $janePartyId,
                    'share_class_id' => $shareClassId,
                    'effective_from' => $periodStart,
                ]
            );

            $options = $service->eligibleAuthorisers($companyId, $todayString);
            $references = array_column($options, 'reference');
            $harness->assertTrue(in_array('director:' . $directorId, $references, true));
            $harness->assertTrue(in_array('party-role:' . $agentRoleId, $references, true));
            $harness->assertTrue(in_array('party-role:' . $secretaryRoleId, $references, true));
            $harness->assertFalse(in_array('party-role:' . $futureRoleId, $references, true));
            $harness->assertFalse(in_array('party-role:' . $expiredRoleId, $references, true));
            $janeOptions = array_values(array_filter(
                $options,
                static fn(array $option): bool => (string)($option['name'] ?? '') === 'Jane Smith'
            ));
            $harness->assertCount(2, $janeOptions);
            $harness->assertSame(['Authorised Agent', 'Director'], array_values(array_column($janeOptions, 'status')));
            $harness->assertCount(0, array_filter(
                $options,
                static fn(array $option): bool => (string)($option['name'] ?? '') === 'Agent Firm Limited'
            ));

            $loanPartyIds = array_column(
                (new \eel_accounts\Service\OwnershipPartyService())->effectiveParties($companyId, $todayString),
                'id'
            );
            $harness->assertTrue(in_array($janePartyId, $loanPartyIds, true));
            $harness->assertFalse(in_array($adminOnlyPartyId, $loanPartyIds, true));

            $saved = $service->save($companyId, $periodId, [
                'declarant_authority' => 'party-role:' . $agentRoleId,
                'declarant_name' => 'Tampered Name',
                'declarant_status' => 'Tampered Status',
                'original_unfiled_confirmed' => '1',
                'authority_confirmed' => '1',
                'declaration_confirmed' => '1',
            ], 'test-user');
            $harness->assertSame(true, (bool)($saved['success'] ?? false));
            $snapshot = $service->current($companyId, $periodId);
            $harness->assertSame('Jane Smith', (string)($snapshot['declarant_name'] ?? ''));
            $harness->assertSame('Authorised Agent', (string)($snapshot['declarant_status'] ?? ''));
            $harness->assertSame($janePartyId, (int)($snapshot['declarant_party_id'] ?? 0));
            $harness->assertSame($agentRoleId, (int)($snapshot['declarant_role_id'] ?? 0));
            $harness->assertSame(0, (int)($snapshot['declarant_director_id'] ?? 0));
            $harness->assertSame('test-user', (string)($snapshot['saved_by'] ?? ''));
            $harness->assertSame('test-user', (string)($snapshot['saved_by_display_name'] ?? ''));

            InterfaceDB::prepareExecute(
                'INSERT INTO users (display_name, email_address, role_id)
                 VALUES (:display_name, :email_address, -1)',
                [
                    'display_name' => 'CT Authority Reviewer',
                    'email_address' => 'ct-authority-' . strtolower($marker) . '@example.test',
                ]
            );
            $userId = $lastInsertId();
            \UserAuthenticationService::forgetUserByIdCache($userId);
            $savedByUser = $service->save($companyId, $periodId, [
                'declarant_authority' => 'party-role:' . $agentRoleId,
                'original_unfiled_confirmed' => '1',
                'authority_confirmed' => '1',
                'declaration_confirmed' => '1',
            ], 'user:' . $userId);
            $harness->assertSame(true, (bool)($savedByUser['success'] ?? false));
            $snapshot = $service->current($companyId, $periodId);
            $harness->assertSame('user:' . $userId, (string)($snapshot['saved_by'] ?? ''));
            $harness->assertSame('CT Authority Reviewer', (string)($snapshot['saved_by_display_name'] ?? ''));
            $card = new _ixbrl_accounts_disclosuresCard();
            $panel = new ReflectionMethod($card, 'ct600AuthorisationPanel');
            $panel->setAccessible(true);
            $panelHtml = (string)$panel->invoke($card, $companyId, $periodId, []);
            $harness->assertTrue(str_contains($panelHtml, 'Last updated by</th><td>CT Authority Reviewer</td>'));
            $harness->assertFalse(str_contains($panelHtml, '>user:' . $userId . '<'));

            InterfaceDB::prepareExecute(
                'UPDATE company_party_roles SET effective_to = :effective_to WHERE id = :id',
                ['effective_to' => $expiredOn, 'id' => $agentRoleId]
            );
            \eel_accounts\Support\RequestCache::clear();
            $frozen = $service->current($companyId, $periodId);
            $harness->assertSame('Jane Smith', (string)($frozen['declarant_name'] ?? ''));
            $harness->assertSame($agentRoleId, (int)($frozen['declarant_role_id'] ?? 0));
            $newSave = $service->save($companyId, $periodId, [
                'declarant_authority' => 'party-role:' . $agentRoleId,
                'original_unfiled_confirmed' => '1',
                'authority_confirmed' => '1',
                'declaration_confirmed' => '1',
            ], 'test-user');
            $harness->assertSame(false, (bool)($newSave['success'] ?? true));

            foreach ([
                'director:' . $otherDirectorId,
                'party-role:' . $futureRoleId,
                'party-role:' . $expiredRoleId,
                'party-role:999999999999',
                'director:0',
                'malformed',
            ] as $invalidReference) {
                $invalid = $service->save($companyId, $periodId, [
                    'declarant_authority' => $invalidReference,
                    'original_unfiled_confirmed' => '1',
                    'authority_confirmed' => '1',
                    'declaration_confirmed' => '1',
                ], 'test-user');
                $harness->assertSame(false, (bool)($invalid['success'] ?? true));
            }
            $wrongPeriod = $service->save($companyId, $otherPeriodId, [
                'declarant_authority' => 'director:' . $directorId,
                'original_unfiled_confirmed' => '1',
                'authority_confirmed' => '1',
                'declaration_confirmed' => '1',
            ], 'test-user');
            $harness->assertSame(false, (bool)($wrongPeriod['success'] ?? true));
        } finally {
            \eel_accounts\Support\RequestCache::clear();
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
