<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAccountsReportService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlAccountsReportService $service): void {
        $harness->check($service::class, 'rejects an invalid company and accounting period', static function () use ($harness, $service): void {
            $thrown = false;
            try {
                $service->build(0, 0);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            $harness->assertTrue($thrown);
        });

        $harness->check($service::class, 'declares an explicit report-basis version', static function () use ($harness, $service): void {
            $harness->assertSame('ixbrl-accounts-report-v5', $service::BASIS_VERSION);
        });

        $harness->check($service::class, 'freezes the selected director id with the officer-name snapshot', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'disclosureBasis');
            $method->setAccessible(true);
            $basis = $method->invoke($service, [
                'accounts_approval_date' => '2026-07-24',
                'approving_director_id' => 17,
                'approving_director_name' => 'James Elstone',
                'updated_at' => '2026-07-24 12:00:00',
            ]);

            $harness->assertSame(17, (int)($basis['approving_director_id'] ?? 0));
            $harness->assertSame('James Elstone', (string)($basis['approving_director_name'] ?? ''));
            $harness->assertFalse(array_key_exists('updated_at', $basis));
        });

        $harness->check($service::class, 'limits director taxonomy disclosures to linked directors and recalculates their totals', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'filterDirectorLoanDisclosure');
            $method->setAccessible(true);
            $summary = (array)$method->invoke($service, [
                'has_company_to_director_exposure' => true,
                'total_advances' => 350.0,
                'total_cash_repayments' => 60.0,
                'disclosures' => [
                    [
                        'director_id' => 10,
                        'director_name' => 'Linked party name',
                        'advances' => 100.0,
                        'cash_repayments' => 25.0,
                    ],
                    [
                        'director_id' => 20,
                        'director_name' => 'Shareholder only',
                        'advances' => 250.0,
                        'cash_repayments' => 35.0,
                    ],
                ],
            ], [
                10 => ['linked_director_id' => 77, 'director_name' => 'Companies House Director'],
            ]);

            $harness->assertCount(1, (array)$summary['disclosures']);
            $harness->assertSame(10, (int)$summary['disclosures'][0]['party_id']);
            $harness->assertSame(77, (int)$summary['disclosures'][0]['director_id']);
            $harness->assertSame('Companies House Director', (string)$summary['disclosures'][0]['director_name']);
            $harness->assertSame(100.0, (float)$summary['total_advances']);
            $harness->assertSame(25.0, (float)$summary['total_cash_repayments']);
        });

        $harness->check($service::class, 'retains only director-linked canonical party facts for director disclosures', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'directorPartyFacts');
            $method->setAccessible(true);
            $facts = (array)$method->invoke($service, [
                'party_facts' => [
                    ['party_id' => 10, 'linked_director_id' => 77, 'terms_revision' => 3],
                    ['party_id' => 20, 'linked_director_id' => null, 'terms_revision' => 2],
                ],
            ]);

            $harness->assertCount(1, $facts);
            $harness->assertSame(10, (int)$facts[0]['party_id']);
            $harness->assertSame(3, (int)$facts[0]['terms_revision']);
        });

        $harness->check($service::class, 'requires a valid Director Loan Year End approval when the locked report has loan activity', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'assertDirectorLoanApproval');
            $blocked = false;
            try {
                $method->invoke($service, ['has_activity' => true], [
                    'available' => false,
                    'basis_version' => '',
                    'basis_hash' => '',
                ], 'selected accounting period');
            } catch (ReflectionException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $blocked = str_contains($exception->getMessage(), 'Approve the Director Loan Year End review');
            }
            $harness->assertSame(true, $blocked);

            $method->invoke($service, ['has_activity' => true], [
                'available' => true,
                'basis_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
                'basis_hash' => str_repeat('a', 64),
            ], 'selected accounting period');
            $method->invoke($service, ['has_activity' => false], [
                'available' => false,
                'basis_version' => '',
                'basis_hash' => '',
            ], 'selected accounting period');
        });

        $harness->check($service::class, 'links the stored Director Loan Year End approval hash and party facts into the report basis', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number)
                     VALUES (:company_name, :company_number)',
                    ['company_name' => 'Report Approval Fixture Limited', 'company_number' => 'RAF' . $marker]
                );
                $companyId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM companies WHERE company_number = :company_number',
                    ['company_number' => 'RAF' . $marker]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => 'Report approval fixture',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $periodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id',
                    ['company_id' => $companyId]
                );
                $saved = (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
                    $companyId,
                    $periodId,
                    \eel_accounts\Service\DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE,
                    [
                        'facts' => [
                            'party_facts' => [[
                                'party_id' => 42,
                                'linked_director_id' => 7,
                                'terms_revision' => 5,
                            ]],
                        ],
                    ],
                    'test',
                    '',
                    true,
                    \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
                );
                $harness->assertSame(true, (bool)($saved['success'] ?? false));

                $method = new ReflectionMethod($service, 'directorLoanYearEndApproval');
                $method->setAccessible(true);
                $approval = (array)$method->invoke($service, $companyId, $periodId);
                $harness->assertSame(
                    (string)($saved['acknowledgement']['basis_hash'] ?? ''),
                    (string)($approval['basis_hash'] ?? '')
                );
                $harness->assertSame(42, (int)(($approval['party_facts'] ?? [])[0]['party_id'] ?? 0));
                $harness->assertSame(
                    \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
                    (string)($approval['basis_version'] ?? '')
                );

                $replacement = (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
                    $companyId,
                    $periodId,
                    \eel_accounts\Service\DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE,
                    [
                        'facts' => [
                            'party_facts' => [[
                                'party_id' => 42,
                                'linked_director_id' => 7,
                                'terms_revision' => 6,
                            ]],
                        ],
                    ],
                    'test',
                    '',
                    true,
                    \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
                );
                $replacementApproval = (array)$method->invoke($service, $companyId, $periodId);
                $harness->assertFalse(hash_equals(
                    (string)($approval['basis_hash'] ?? ''),
                    (string)($replacementApproval['basis_hash'] ?? '')
                ));
                $harness->assertSame(
                    (string)($replacement['acknowledgement']['basis_hash'] ?? ''),
                    (string)($replacementApproval['basis_hash'] ?? '')
                );
                $harness->assertSame(
                    6,
                    (int)(($replacementApproval['party_facts'] ?? [])[0]['terms_revision'] ?? 0)
                );
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);
