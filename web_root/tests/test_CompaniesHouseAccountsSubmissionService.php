<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseAccountsSubmissionService $service): void {
        $invokePrivate = static function (
            object $target,
            string $methodName,
            mixed ...$arguments
        ): mixed {
            $method = new ReflectionMethod($target, $methodName);
            $method->setAccessible(true);

            return $method->invoke($target, ...$arguments);
        };

        $harness->check($service::class, 'returns an empty context for an invalid selection', static function () use ($harness, $service): void {
            $context = $service->fetchContext(0, 0);
            $harness->assertFalse((bool)($context['can_prepare'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)($context['blockers'] ?? [])), 'valid company'));
        });

        $harness->check(
            $service::class,
            'keeps the signed Revised classification when the live observation changes',
            static function () use ($harness, $service, $invokePrivate): void {
                $basis = [
                    'contract_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
                    'check_code' => 'companies_house_mismatch_acknowledgement',
                    'facts' => [
                        'filing_kind' => 'revised',
                        'filing_reason' => 'exact_period_filing_found',
                        'mismatch_count' => 2,
                        'filing_evidence' => ['document_row_id' => 90],
                    ],
                    'questions' => [],
                    'answers' => [],
                ];
                $basisHash = (new \eel_accounts\Service\YearEndAcknowledgementService())
                    ->hashBasis($basis);
                $review = [
                    'available' => true,
                    'check_code' => 'companies_house_mismatch_acknowledgement',
                    'acknowledgement_current' => true,
                    'acknowledged_at' => '2026-08-01 10:00:00',
                    'acknowledged_by' => 'reviewer',
                    'acknowledgement' => [
                        'basis_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
                        'basis_hash' => $basisHash,
                        'basis_json' => json_encode($basis, JSON_THROW_ON_ERROR),
                    ],
                    'display' => [
                        'mismatch_count' => 0,
                        'comparison' => [
                            'filing_kind' => 'original',
                            'filing_reason' => 'new_live_observation',
                        ],
                    ],
                ];

                $classification = $invokePrivate($service, 'classificationFromReview', $review);

                $harness->assertSame('approved_basis', (string)$classification['source']);
                $harness->assertSame('revised', (string)$classification['filing_kind']);
                $harness->assertTrue((bool)$classification['correction_required']);
                $harness->assertTrue((bool)$classification['approved']);
                $harness->assertSame($basisHash, (string)$classification['approval_basis_hash']);

                $review['acknowledgement']['basis_hash'] = hash('sha256', 'tampered-review-basis');
                $fallback = $invokePrivate($service, 'classificationFromReview', $review);
                $harness->assertSame('live_comparison', (string)$fallback['source']);
                $harness->assertSame('original', (string)$fallback['filing_kind']);
                $harness->assertFalse((bool)$fallback['approved']);
            }
        );

        $harness->check(
            $service::class,
            'clears filing outstanding only for a consistent verified revised observation',
            static function () use ($harness, $service, $invokePrivate): void {
                $verified = $invokePrivate($service, 'filingReconciliation', true, true, [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'verified',
                    'revision_reconciled' => true,
                    'filing_outstanding' => false,
                    'mismatch_count' => 0,
                ]);
                $harness->assertFalse((bool)$verified['filing_outstanding']);
                $harness->assertTrue((bool)$verified['revision_reconciled']);

                $unavailable = $invokePrivate($service, 'filingReconciliation', true, true, [
                    'available' => false,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'verified',
                    'revision_reconciled' => true,
                    'filing_outstanding' => false,
                    'mismatch_count' => 0,
                ]);
                $harness->assertTrue((bool)$unavailable['filing_outstanding']);
                $harness->assertFalse((bool)$unavailable['revision_reconciled']);

                $inconsistent = $invokePrivate($service, 'filingReconciliation', true, true, [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'verified',
                    'revision_reconciled' => true,
                    'filing_outstanding' => false,
                ]);
                $harness->assertTrue((bool)$inconsistent['filing_outstanding']);
            }
        );

        $harness->check(
            $service::class,
            'waits for each accepted LIVE amendment to appear in the Companies House register',
            static function () use ($harness, $invokePrivate): void {
                $accepted = [
                    'id' => 65,
                    'company_id' => 49,
                    'accounting_period_id' => 79,
                    'original_document_id' => 90,
                    'filing_type' => 'revised',
                    'lifecycle' => 'accepted',
                    'environment' => 'LIVE',
                    'filing_metadata' => [
                        'original_document_id' => 90,
                        'superseded_document_id' => 90,
                    ],
                ];
                $acceptedLookups = 0;
                $subject = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                    acceptedSubmissionResolver: static function (
                        int $companyId,
                        int $accountingPeriodId,
                        string $environment
                    ) use (&$accepted, &$acceptedLookups): array {
                        $acceptedLookups++;
                        return $accepted;
                    },
                    periodFilingResolver: static fn(): array => [
                        'original' => ['id' => 90, 'parse_status' => 'parsed_latest_year'],
                        'latest_revision' => ['id' => 102, 'parse_status' => 'parsed_latest_year'],
                    ]
                );

                $awaiting = [
                    'available' => true,
                    'has_revised_filing' => false,
                    'reconciliation_state' => 'awaiting',
                    'filing_outstanding' => true,
                ];
                $firstAmendmentBlocker = $invokePrivate(
                    $subject,
                    'acceptedFilingRegisterResyncBlocker',
                    49,
                    79,
                    'LIVE',
                    $awaiting
                );
                $harness->assertTrue(str_contains(
                    (string)$firstAmendmentBlocker,
                    'accepted revised accounts filing is awaiting Companies House register resync'
                ));

                $accepted['filing_metadata']['superseded_document_id'] = 101;
                $sameRevision = [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'mismatch',
                    'filing_outstanding' => true,
                    'filing' => ['id' => 101],
                ];
                $furtherAmendmentBlocker = $invokePrivate(
                    $subject,
                    'acceptedFilingRegisterResyncBlocker',
                    49,
                    79,
                    'LIVE',
                    $sameRevision
                );
                $harness->assertTrue(str_contains(
                    (string)$furtherAmendmentBlocker,
                    'accepted revised accounts filing is awaiting Companies House register resync'
                ));

                $newRevision = $sameRevision;
                $newRevision['filing']['id'] = 102;
                $harness->assertSame(
                    null,
                    $invokePrivate(
                        $subject,
                        'acceptedFilingRegisterResyncBlocker',
                        49,
                        79,
                        'LIVE',
                        $newRevision
                    )
                );
                unset($accepted['filing_metadata']['superseded_document_id']);
                $harness->assertSame(
                    null,
                    $invokePrivate(
                        $subject,
                        'acceptedFilingRegisterResyncBlocker',
                        49,
                        79,
                        'LIVE',
                        $newRevision
                    )
                );
                $accepted['filing_metadata'] = [];
                $accepted['original_document_id'] = 0;
                $harness->assertTrue(str_contains(
                    (string)$invokePrivate(
                        $subject,
                        'acceptedFilingRegisterResyncBlocker',
                        49,
                        79,
                        'LIVE',
                        $newRevision
                    ),
                    'awaiting Companies House register resync'
                ));
                $accepted['filing_metadata'] = [
                    'original_document_id' => 90,
                    'superseded_document_id' => 101,
                ];
                $accepted['original_document_id'] = 90;
                $context = [
                    'company' => ['company_number' => '01234567'],
                    'accounting_period' => ['period_end' => '2023-09-30'],
                    'reconciliation' => $newRevision,
                ];
                $harness->assertSame(
                    102,
                    $invokePrivate(
                        $subject,
                        'supersededDocumentIdForPreparation',
                        49,
                        79,
                        $context,
                        90
                    )
                );

                $harness->assertSame(
                    null,
                    $invokePrivate(
                        $subject,
                        'acceptedFilingRegisterResyncBlocker',
                        49,
                        79,
                        'TEST',
                        $awaiting
                    )
                );
                $harness->assertSame(5, $acceptedLookups);

                foreach (['fetchContext', 'fetchTransmissionContext', 'prepareRevision'] as $methodName) {
                    $method = new ReflectionMethod($subject, $methodName);
                    $source = file($method->getFileName());
                    $harness->assertTrue(is_array($source));
                    $body = implode('', array_slice(
                        $source,
                        $method->getStartLine() - 1,
                        $method->getEndLine() - $method->getStartLine() + 1
                    ));
                    $harness->assertTrue(str_contains($body, 'acceptedFilingRegisterResyncBlocker('));
                }
            }
        );

        $harness->check(
            $service::class,
            'blocks accepted LIVE register waits before gateway and sequence side effects',
            static function () use ($harness): void {
                $gatewayCalls = 0;
                $sequenceCalls = 0;
                $gateway = new class(static function () use (&$gatewayCalls): void {
                    $gatewayCalls++;
                }) implements \eel_accounts\Client\CompaniesHouseAccountsGatewayTransportInterface {
                    public function __construct(private readonly Closure $record)
                    {
                    }

                    public function checkCompanyAuthentication(
                        string $companyNumber,
                        string $companyAuthenticationCode,
                        string $environment,
                        array $schemaInventory,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function prepareAccounts(
                        array $payload,
                        string $environment,
                        array $schemaInventory
                    ): \eel_accounts\Client\CompaniesHousePreparedAccountsRequest {
                        ($this->record)();
                        throw new RuntimeException('Gateway preparation must not run.');
                    }

                    public function sendPreparedAccounts(
                        \eel_accounts\Client\CompaniesHousePreparedAccountsRequest $request,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function getSubmissionStatus(
                        string $submissionNumber,
                        string $environment,
                        \eel_accounts\Client\GovTalkConversationContext $conversation,
                        array $schemaInventory = []
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function acknowledgeSubmissionStatus(
                        string $environment,
                        array $schemaInventory,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function getDocument(
                        string $documentRequestKey,
                        string $environment,
                        array $schemaInventory,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }
                };
                $observation = [
                    'available' => true,
                    'has_revised_filing' => false,
                    'reconciliation_state' => 'awaiting',
                    'filing_outstanding' => true,
                ];
                $accepted = [
                    'id' => 65,
                    'original_document_id' => 90,
                    'filing_metadata' => ['superseded_document_id' => 90],
                ];
                $submission = [
                    'id' => 79,
                    'company_id' => 49,
                    'accounting_period_id' => 79,
                    'original_document_id' => 90,
                    'filing_type' => 'revised',
                    'lifecycle' => 'prepared',
                    'environment' => 'LIVE',
                    'filing_metadata' => ['superseded_document_id' => 90],
                ];
                $subject = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                    gatewayClient: $gateway,
                    revisedObservationResolver: static function () use (&$observation): array {
                        return $observation;
                    },
                    submissionResolver: static function () use (&$submission): array {
                        return $submission;
                    },
                    sequenceAllocator: static function () use (&$sequenceCalls): array {
                        $sequenceCalls++;
                        return ['submission_number' => '999999'];
                    },
                    acceptedSubmissionResolver: static function () use (&$accepted): array {
                        return $accepted;
                    }
                );

                $firstResult = $subject->submitRevision(79, 'ABC123', 'tester');
                $harness->assertFalse((bool)$firstResult['success']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$firstResult['errors']),
                    'awaiting Companies House register resync'
                ));
                $harness->assertSame(0, $gatewayCalls);
                $harness->assertSame(0, $sequenceCalls);

                $accepted['filing_metadata']['superseded_document_id'] = 101;
                $submission['filing_metadata']['superseded_document_id'] = 101;
                $observation = [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'mismatch',
                    'filing_outstanding' => true,
                    'mismatch_count' => 1,
                    'filing' => ['id' => 101],
                ];
                $sameResult = $subject->submitRevision(79, 'ABC123', 'tester');
                $harness->assertFalse((bool)$sameResult['success']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$sameResult['errors']),
                    'awaiting Companies House register resync'
                ));
                $harness->assertSame(0, $gatewayCalls);
                $harness->assertSame(0, $sequenceCalls);

                $submission['filing_metadata']['superseded_document_id'] = 102;
                $observation['filing']['id'] = 102;
                $newResult = $subject->submitRevision(79, 'ABC12', 'tester');
                $harness->assertFalse((bool)$newResult['success']);
                $harness->assertFalse(str_contains(
                    implode(' ', (array)$newResult['errors']),
                    'awaiting Companies House register resync'
                ));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$newResult['errors']),
                    'authentication code must contain exactly 6'
                ));
                $harness->assertSame(0, $gatewayCalls);
                $harness->assertSame(0, $sequenceCalls);
            }
        );

        $harness->check(
            $service::class,
            'blocks reconciled and stale-lineage prepared drafts in both transmission contexts',
            static function () use ($harness, $service, $invokePrivate): void {
                $submission = [
                    'filing_type' => 'revised',
                    'lifecycle' => 'prepared',
                    'original_document_id' => 90,
                    'filing_metadata' => ['superseded_document_id' => 90],
                ];
                $blocker = $invokePrivate($service, 'draftLineageSubmissionBlocker', $submission, [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'verified',
                    'revision_reconciled' => true,
                    'filing_outstanding' => false,
                ]);
                $harness->assertTrue(is_string($blocker));
                $harness->assertTrue(str_contains((string)$blocker, 'no further filing is outstanding'));
                $harness->assertFalse((bool)$invokePrivate(
                    $service,
                    'transmissionCanSubmit',
                    'prepared',
                    [(string)$blocker]
                ));
                foreach (['fetchContext', 'fetchTransmissionContext'] as $methodName) {
                    $method = new ReflectionMethod($service, $methodName);
                    $source = file($method->getFileName());
                    $harness->assertTrue(is_array($source));
                    $body = implode('', array_slice(
                        $source,
                        $method->getStartLine() - 1,
                        $method->getEndLine() - $method->getStartLine() + 1
                    ));
                    $harness->assertTrue(str_contains($body, 'draftLineageSubmissionBlocker('));
                    $harness->assertTrue(str_contains($body, 'transmissionCanSubmit('));
                }

                $mismatch = [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'mismatch',
                    'filing_outstanding' => true,
                    'filing' => ['id' => 101],
                ];
                $stale = $invokePrivate($service, 'draftLineageSubmissionBlocker', $submission, $mismatch);
                $harness->assertTrue(str_contains((string)$stale, 'changed the superseded filing source'));
                $submission['filing_metadata']['superseded_document_id'] = 101;
                $harness->assertSame(
                    null,
                    $invokePrivate($service, 'draftLineageSubmissionBlocker', $submission, $mismatch)
                );
                $unverifiable = $mismatch;
                $unverifiable['available'] = false;
                $unverifiable['reconciliation_state'] = 'unverifiable';
                $harness->assertTrue(str_contains(
                    (string)$invokePrivate($service, 'draftLineageSubmissionBlocker', $submission, $unverifiable),
                    'cannot be verified'
                ));
            }
        );

        $harness->check(
            $service::class,
            'direct submission fails before gateway or sequence side effects after reconciliation',
            static function () use ($harness): void {
                $gatewayCalls = 0;
                $sequenceCalls = 0;
                $recordGatewayCall = static function () use (&$gatewayCalls): void {
                    $gatewayCalls++;
                };
                $gateway = new class($recordGatewayCall) implements
                    \eel_accounts\Client\CompaniesHouseAccountsGatewayTransportInterface {
                    public function __construct(private readonly Closure $record)
                    {
                    }

                    public function checkCompanyAuthentication(
                        string $companyNumber,
                        string $companyAuthenticationCode,
                        string $environment,
                        array $schemaInventory,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function prepareAccounts(
                        array $payload,
                        string $environment,
                        array $schemaInventory
                    ): \eel_accounts\Client\CompaniesHousePreparedAccountsRequest {
                        ($this->record)();
                        throw new RuntimeException('Gateway preparation must not run.');
                    }

                    public function sendPreparedAccounts(
                        \eel_accounts\Client\CompaniesHousePreparedAccountsRequest $request,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function getSubmissionStatus(
                        string $submissionNumber,
                        string $environment,
                        \eel_accounts\Client\GovTalkConversationContext $conversation,
                        array $schemaInventory = []
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function acknowledgeSubmissionStatus(
                        string $environment,
                        array $schemaInventory,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }

                    public function getDocument(
                        string $documentRequestKey,
                        string $environment,
                        array $schemaInventory,
                        \eel_accounts\Client\GovTalkConversationContext $conversation
                    ): array {
                        ($this->record)();
                        return [];
                    }
                };
                $observation = [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'verified',
                    'revision_reconciled' => true,
                    'filing_outstanding' => false,
                    'mismatch_count' => 0,
                ];
                $submission = [
                    'id' => 79,
                    'company_id' => 49,
                    'accounting_period_id' => 79,
                    'original_document_id' => 90,
                    'filing_type' => 'revised',
                    'lifecycle' => 'prepared',
                    'environment' => 'TEST',
                    'filing_metadata' => ['superseded_document_id' => 90],
                ];
                $subject = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                    gatewayClient: $gateway,
                    revisedObservationResolver: static function () use (&$observation): array {
                        return $observation;
                    },
                    submissionResolver: static function () use (&$submission): array {
                        return $submission;
                    },
                    sequenceAllocator: static function () use (&$sequenceCalls): array {
                        $sequenceCalls++;
                        return ['submission_number' => '999999'];
                    },
                );

                $result = $subject->submitRevision(79, 'ABC123', 'tester');
                $harness->assertFalse((bool)$result['success']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$result['errors']),
                    'no further filing is outstanding'
                ));
                $harness->assertSame(0, $gatewayCalls);
                $harness->assertSame(0, $sequenceCalls);

                $observation = [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'mismatch',
                    'revision_reconciled' => false,
                    'filing_outstanding' => true,
                    'mismatch_count' => 1,
                    'filing' => ['id' => 101],
                ];
                $staleResult = $subject->submitRevision(79, 'ABC123', 'tester');
                $harness->assertFalse((bool)$staleResult['success']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$staleResult['errors']),
                    'changed the superseded filing source'
                ));
                $harness->assertSame(0, $gatewayCalls);
                $harness->assertSame(0, $sequenceCalls);

                $observation['available'] = false;
                $observation['reconciliation_state'] = 'unverifiable';
                $unverifiableResult = $subject->submitRevision(79, 'ABC123', 'tester');
                $harness->assertFalse((bool)$unverifiableResult['success']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$unverifiableResult['errors']),
                    'cannot be verified'
                ));
                $harness->assertSame(0, $gatewayCalls);
                $harness->assertSame(0, $sequenceCalls);
            }
        );

        $harness->check(
            $service::class,
            'uses latest parsed AAMD as the superseded source without rebasing the original',
            static function () use ($harness, $invokePrivate): void {
                $parseStatus = 'parsed_latest_year';
                $resolverCalls = 0;
                $subject = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                    periodFilingResolver: static function () use (&$parseStatus, &$resolverCalls): array {
                        $resolverCalls++;
                        return [
                            'original' => ['id' => 90, 'parse_status' => 'parsed_latest_year'],
                            'latest_revision' => ['id' => 101, 'parse_status' => $parseStatus],
                        ];
                    }
                );
                $baseContext = [
                    'company' => ['company_number' => '01234567'],
                    'accounting_period' => ['period_end' => '2023-09-30'],
                ];
                $awaiting = $baseContext + ['reconciliation' => [
                    'available' => true,
                    'has_revised_filing' => false,
                    'reconciliation_state' => 'awaiting',
                ]];
                $harness->assertSame(
                    90,
                    $invokePrivate($subject, 'supersededDocumentIdForPreparation', 49, 79, $awaiting, 90)
                );
                $harness->assertSame(0, $resolverCalls);

                $mismatch = $baseContext + ['reconciliation' => [
                    'available' => true,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'mismatch',
                    'filing' => ['id' => 101],
                ]];
                $harness->assertSame(
                    101,
                    $invokePrivate($subject, 'supersededDocumentIdForPreparation', 49, 79, $mismatch, 90)
                );
                $harness->assertSame(1, $resolverCalls);

                $parseStatus = 'parse_failed';
                $thrown = false;
                try {
                    $invokePrivate($subject, 'supersededDocumentIdForPreparation', 49, 79, $mismatch, 90);
                } catch (RuntimeException $exception) {
                    $thrown = str_contains($exception->getMessage(), 'has not been parsed successfully');
                }
                $harness->assertTrue($thrown);

                $unverifiable = $baseContext + ['reconciliation' => [
                    'available' => false,
                    'has_revised_filing' => true,
                    'reconciliation_state' => 'unverifiable',
                ]];
                $thrown = false;
                try {
                    $invokePrivate($subject, 'supersededDocumentIdForPreparation', 49, 79, $unverifiable, 90);
                } catch (RuntimeException $exception) {
                    $thrown = str_contains($exception->getMessage(), 'cannot be verified and parsed');
                }
                $harness->assertTrue($thrown);
            }
        );

        $harness->check(
            $service::class,
            'builds further-amendment disclosure rows from latest AAMD facts',
            static function () use ($harness, $service, $invokePrivate): void {
                $model = [
                    'current' => ['buckets' => ['current_assets' => 250.0]],
                    'disclosures' => ['profit_loss_not_delivered_section_444' => 1],
                    'director_loan_disclosure' => [],
                ];
                $facts = [[
                    'concept' => 'core:CurrentAssets',
                    'context_ref' => 'current_period_end_superseded',
                    'value' => 200.0,
                    'source_short_name' => 'CurrentAssets',
                    'source_document_id' => 101,
                ]];
                $rows = $invokePrivate($service, 'supersededComparisonRows', $model, $facts);
                $harness->assertSame(1, count($rows));
                $harness->assertSame(200.0, (float)$rows[0]['filed_value']);
                $harness->assertSame(250.0, (float)$rows[0]['app_value']);
                $harness->assertSame('fail', (string)$rows[0]['status']);

                $texts = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    ['variance_explanation' => 'Initial-AA explanation must not be reused.'],
                    [],
                    ['rows' => $rows],
                    $model,
                    $facts,
                    true
                );
                $combined = (string)$texts['non_compliance_explanation'] . ' '
                    . (string)$texts['significant_amendments'];
                $harness->assertTrue(str_contains($combined, 'superseded revised accounts reported current assets'));
                $harness->assertFalse(str_contains($combined, 'Initial-AA explanation must not be reused.'));
                $harness->assertFalse(str_contains($combined, 'original accounts reported current assets'));
            }
        );

        $harness->check(
            $service::class,
            'does not repeat unchanged AAMD prepayment current-asset or director-loan narratives',
            static function () use ($harness, $service, $invokePrivate): void {
                $model = [
                    'disclosures' => ['profit_loss_not_delivered_section_444' => 1],
                    'current' => [
                        'buckets' => [
                            'fixed_assets' => 50.0,
                            'current_assets' => 100.0,
                            'prepayments_accrued_income' => 20.0,
                        ],
                        'director_loan_reporting_presentation' => [
                            'applicable' => true,
                            'classification' => 'within_one_year',
                        ],
                    ],
                    'director_loan_disclosure' => [
                        'has_company_to_director_exposure' => true,
                        'total_advances' => 25.0,
                        'disclosures' => [['director_name' => 'Test Director']],
                    ],
                ];
                $facts = [
                    [
                        'concept' => 'core:FixedAssets',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 0.0,
                        'source_short_name' => 'FixedAssets',
                    ],
                    [
                        'concept' => 'core:CurrentAssets',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 100.0,
                        'source_short_name' => 'CurrentAssets',
                    ],
                    [
                        'concept' => 'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 20.0,
                        'source_short_name' => 'PrepaymentsAccruedIncome',
                    ],
                ];
                $rows = $invokePrivate($service, 'supersededComparisonRows', $model, $facts);
                $statuses = [];
                foreach ($rows as $row) {
                    $statuses[(string)$row['metric_key']] = (string)$row['status'];
                }
                $harness->assertSame('fail', $statuses['fixed_assets'] ?? '');
                $harness->assertSame('ok', $statuses['current_assets'] ?? '');
                $harness->assertSame('ok', $statuses['prepayments_accrued_income'] ?? '');

                $further = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    [],
                    [],
                    ['rows' => $rows],
                    $model,
                    $facts,
                    true
                );
                $furtherText = mb_strtolower(
                    (string)$further['non_compliance_explanation'] . ' '
                        . (string)$further['significant_amendments']
                );
                $harness->assertTrue(str_contains($furtherText, 'fixed assets'));
                foreach (['prepayments', 'current assets', 'director-loan', 'participator-loan'] as $unrelated) {
                    $harness->assertFalse(str_contains($furtherText, $unrelated));
                }

                $firstAmendment = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    [],
                    [],
                    ['rows' => $rows],
                    $model,
                    $facts,
                    false
                );
                $legacyText = mb_strtolower(
                    (string)$firstAmendment['non_compliance_explanation'] . ' '
                        . (string)$firstAmendment['significant_amendments']
                );
                $harness->assertTrue(str_contains($legacyText, 'prepayments'));
                $harness->assertTrue(str_contains($legacyText, 'current assets'));
                $harness->assertTrue(str_contains($legacyText, 'director-loan'));
            }
        );

        $harness->check(
            $service::class,
            'does not infer a director-loan note defect from a non-director creditor delta',
            static function () use ($harness, $service, $invokePrivate): void {
                $model = [
                    'disclosures' => ['profit_loss_not_delivered_section_444' => 1],
                    'current' => [
                        'buckets' => [
                            'creditors_within_one_year' => 100.0,
                            'creditors_after_more_than_one_year' => 0.0,
                        ],
                        'director_loan_reporting_presentation' => [
                            'applicable' => true,
                            'classification' => 'within_one_year',
                        ],
                    ],
                    'director_loan_disclosure' => [
                        'has_company_to_director_exposure' => true,
                        'total_advances' => 25.0,
                        'disclosures' => [['director_name' => 'Test Director']],
                    ],
                ];
                $facts = [
                    [
                        'concept' => 'core:Creditors',
                        'context_ref' => 'current_period_end_superseded_creditors_within_one_year',
                        'value' => 80.0,
                        'source_short_name' => 'Creditors',
                    ],
                    [
                        'concept' => 'core:Creditors',
                        'context_ref' => 'current_period_end_superseded_creditors_after_one_year',
                        'value' => 0.0,
                        'source_short_name' => 'Creditors',
                    ],
                ];
                $rows = $invokePrivate($service, 'supersededComparisonRows', $model, $facts);
                $texts = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    [],
                    [],
                    ['rows' => $rows],
                    $model,
                    $facts,
                    true
                );
                $combined = mb_strtolower(
                    (string)$texts['non_compliance_explanation'] . ' '
                        . (string)$texts['significant_amendments']
                );
                $harness->assertTrue(str_contains($combined, 'creditors'));
                $harness->assertTrue(str_contains($combined, '£80.00'));
                $harness->assertTrue(str_contains($combined, '£100.00'));
                foreach (['director-loan', 'participator-loan', 'director loan'] as $unsupported) {
                    $harness->assertFalse(str_contains($combined, $unsupported));
                }
            }
        );

        $harness->check(
            $service::class,
            'keeps further-amendment wording to filed-to-app deltas without unsupported causes',
            static function () use ($harness, $service, $invokePrivate): void {
                $model = [
                    'disclosures' => ['profit_loss_not_delivered_section_444' => 1],
                    'current' => ['buckets' => [
                        'fixed_assets' => 50.0,
                        'depreciation_write_offs' => 25.0,
                        'current_assets' => 100.0,
                        'prepayments_accrued_income' => 20.0,
                        'net_assets_liabilities' => 100.0,
                        'equity_capital_reserves' => 100.0,
                    ]],
                    'director_loan_disclosure' => [],
                ];
                $facts = [
                    [
                        'concept' => 'core:FixedAssets',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 40.0,
                        'source_short_name' => 'FixedAssets',
                    ],
                    [
                        'concept' => 'core:CurrentAssets',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 100.0,
                        'source_short_name' => 'CurrentAssets',
                    ],
                    [
                        'concept' => 'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 10.0,
                        'source_short_name' => 'PrepaymentsAccruedIncome',
                    ],
                    [
                        'concept' => 'core:NetAssetsLiabilities',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 90.0,
                        'source_short_name' => 'NetAssetsLiabilities',
                    ],
                    [
                        'concept' => 'core:Equity',
                        'context_ref' => 'current_period_end_superseded',
                        'value' => 100.0,
                        'source_short_name' => 'Equity',
                    ],
                ];
                $rows = $invokePrivate($service, 'supersededComparisonRows', $model, $facts);
                $texts = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    [],
                    [],
                    ['rows' => $rows],
                    $model,
                    $facts,
                    true
                );
                $combined = mb_strtolower(
                    (string)$texts['non_compliance_explanation'] . ' '
                        . (string)$texts['significant_amendments']
                );
                foreach (['fixed assets', 'prepayments and accrued income', 'net assets/liabilities'] as $delta) {
                    $harness->assertTrue(str_contains($combined, $delta));
                }
                foreach ([
                    'depreciation',
                    'did not separately identify',
                    'presented separately',
                    'capital and reserves corrected',
                    'net assets and capital and reserves',
                ] as $unsupported) {
                    $harness->assertFalse(str_contains($combined, $unsupported));
                }
            }
        );

        $harness->check(
            $service::class,
            'pins revised-account preparation to the resolver original document',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'exactOriginalDocument'
                );
                $source = file($method->getFileName());
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains($body, 'CompaniesHousePeriodFilingResolverService'));
                $harness->assertTrue(str_contains($body, "\$resolution['original']"));
                $harness->assertFalse(str_contains($body, 'ORDER BY d.filing_date DESC'));
            }
        );

        $harness->check(
            $service::class,
            'retains an integrity-checked accepted Revised artifact after reconciliation',
            static function () use ($harness, $service, $invokePrivate): void {
                $path = tempnam(test_tmp_directory(), 'eel-accepted-revised-');
                $harness->assertTrue(is_string($path));
                file_put_contents((string)$path, '<html xmlns="http://www.w3.org/1999/xhtml"><body>accepted</body></html>');
                try {
                    $sha256 = hash_file('sha256', (string)$path);
                    $state = $invokePrivate($service, 'retainedAcceptedArtifactState', [
                        'lifecycle' => 'accepted',
                        'artifact_path' => $path,
                        'artifact_sha256' => $sha256,
                        'filing_type' => 'revised',
                        'ixbrl_generation_run_id' => 75,
                        'filing_metadata' => [
                            'presentation_version' => 'historic-accepted-presentation',
                        ],
                    ]);
                    $harness->assertSame('retained', (string)$state['state']);
                    $harness->assertTrue((bool)$state['current']);
                    $harness->assertTrue((bool)$state['reusable']);
                    $harness->assertTrue((bool)$state['accepted']);
                    $harness->assertSame(
                        'historic-accepted-presentation',
                        (string)$state['presentation_version']
                    );

                    $tampered = $invokePrivate($service, 'retainedAcceptedArtifactState', [
                        'lifecycle' => 'accepted',
                        'artifact_path' => $path,
                        'artifact_sha256' => hash('sha256', 'different-bytes'),
                    ]);
                    $harness->assertSame('tampered', (string)$tampered['state']);
                    $harness->assertFalse((bool)$tampered['reusable']);
                } finally {
                    @unlink((string)$path);
                }
            }
        );

        $harness->check(
            $service::class,
            'explains how to create a replacement after a numbered submission',
            static function () use ($harness): void {
                $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts'
                    . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR
                    . 'CompaniesHouseAccountsSubmissionService.php');
                $harness->assertTrue(is_string($source));
                $harness->assertTrue(str_contains(
                    (string)$source,
                    'The currently generated Companies House iXBRL has already been submitted.'
                ));
                $harness->assertTrue(str_contains(
                    (string)$source,
                    'To send a new submission regenerate the iXBRL on the Disclosure page.'
                ));
            }
        );

        $harness->check(
            $service::class,
            'separates the current approval artifact from an older active submission',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'fetchContext'
                );
                $source = file($method->getFileName());
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains($body, 'currentArtifactSubmission('));
                $harness->assertTrue(str_contains($body, 'activeSubmission('));
                $harness->assertTrue(str_contains($body, "'active_submission' => \$activeSubmission"));
                $harness->assertTrue(str_contains($body, "'latest_submission' => \$latestSubmission"));
                $harness->assertFalse(str_contains(
                    $body,
                    'already active and must be resolved before preparing another'
                ));
            }
        );

        $harness->check(
            $service::class,
            'enforces the older active-submission lock in the transmission service',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $source = file($method->getFileName());
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains($body, 'activeSubmission('));
                $harness->assertTrue(str_contains(
                    $body,
                    'is still active for an earlier prepared basis. Resolve it before transmitting another Companies House filing.'
                ));
            }
        );

        $harness->check($service::class, 'rejects a submission filing-kind lookup without a valid context', static function () use ($harness, $service): void {
            $harness->assertSame(null, $service->submissionFilingKindForContext(0, 0, 0));
        });

        $harness->check(
            $service::class,
            'keeps the authentication check independent of filing readiness and generated accounts',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'checkCompanyAuthentication'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains(
                    $body,
                    "->installedSchemasForOperation('company_data')"
                ));
                foreach ([
                    'fetchContext(',
                    'fetchTransmissionContext(',
                    'preparedArtifactState(',
                    'readiness(',
                    'isLocked(',
                    'revisionReadiness(',
                ] as $filingPrerequisite) {
                    $harness->assertFalse(str_contains($body, $filingPrerequisite));
                }
            }
        );

        $harness->check(
            $service::class,
            'requires and consumes a verified CompanyData check without sending a second check',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertFalse(str_contains($body, 'performCompanyDataPreflight('));
                $harness->assertTrue(str_contains($body, 'authenticationCheckReady('));
                $harness->assertTrue(str_contains($body, 'consumePreflight('));
                $harness->assertFalse(str_contains($body, 'verifiedPreflightId'));
            }
        );

        $harness->check(
            $service::class,
            'does not require an accepted TEST filing before LIVE accounts submission',
            static function () use ($harness): void {
                $path = (new ReflectionClass(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class
                ))->getFileName();
                $source = is_string($path) ? file_get_contents($path) : false;
                $harness->assertTrue(is_string($source));
                $harness->assertFalse(str_contains((string)$source, 'testAccepted('));
                $harness->assertFalse(str_contains(
                    (string)$source,
                    'submission must be accepted before LIVE filing'
                ));
                $harness->assertFalse(str_contains(
                    (string)$source,
                    'is required before LIVE filing'
                ));
            }
        );

        $harness->check(
            $service::class,
            'reports detailed and correctly named transmission progress',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                foreach ([
                    'Starting the Companies House ',
                    'Checking the locked Year End, filing declarations and taxonomy compatibility.',
                    'Verifying the prepared iXBRL against the current approved filing basis.',
                    'Verified prepared ',
                    'Verified the current presenter credentials and company authentication code with Companies House.',
                    'Allocated Companies House submission number ',
                    'Validated GovTalk transaction ',
                    'Archived the exact accounts iXBRL and validated GovTalk request',
                    'Recorded immutable request evidence ',
                    'Waiting for the Companies House gateway acknowledgement',
                    'Gateway response received; recording the transmission outcome.',
                    'status is pending, not yet accepted.',
                ] as $message) {
                    $harness->assertTrue(str_contains($body, $message));
                }
                $harness->assertSame(1, substr_count($body, 'preparedArtifactState('));
                $harness->assertTrue(
                    strpos($body, 'Starting the Companies House ') < strpos($body, 'preparedArtifactState(')
                );
                $harness->assertTrue(
                    strpos($body, 'Verifying the prepared iXBRL against the current approved filing basis.')
                    < strpos($body, 'preparedArtifactState(')
                );
                $harness->assertTrue(str_contains($body, "'Companies House acknowledged the ' . \$filingLabel"));
                $harness->assertFalse(str_contains(
                    $body,
                    'Companies House acknowledged the revised-accounts submission.'
                ));
            }
        );

        $harness->check(
            $service::class,
            'reports polling StatusAck and accepted-document progress',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'refreshStatus'
                );
                $harness->assertSame(3, $method->getNumberOfParameters());
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                foreach ([
                    'Starting Companies House status continuation',
                    'Requesting the latest submission status from Companies House.',
                    'Received Companies House status: ',
                    'Sending the mandatory StatusAck',
                    'Companies House acknowledged the StatusAck.',
                    'Checking whether an accepted filed document is available.',
                ] as $message) {
                    $harness->assertTrue(str_contains($body, $message));
                }
            }
        );

        $harness->check(
            $service::class,
            'reloads the latest immutable schema file inventory for each filing operation',
            static function () use ($harness, $service, $invokePrivate): void {
                $file = static fn(string $suffix, string $hash): array => [
                    'source_url' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/' . $suffix,
                    'relative_path' => 'v1-0/schema/' . $suffix,
                    'sha256' => $hash,
                ];
                $submission = ['filing_metadata_json' => json_encode([
                    'schema_validations' => [
                        ['operation'=>'accounts','preflight_id'=>null,'validated_at'=>'2026-07-29 10:00:00']
                            + $file('Egov_ch-v2-0.xsd', str_repeat('a', 64)),
                        ['operation'=>'accounts','preflight_id'=>null,'validated_at'=>'2026-07-29 10:00:00']
                            + $file('CompanyData-v3-6.xsd', str_repeat('b', 64)),
                        ['operation'=>'company_data','preflight_id'=>41,'validated_at'=>'2026-07-29 11:00:00']
                            + $file('CompanyData-v3-6.xsd', str_repeat('b', 64)),
                        ['operation'=>'company_data','preflight_id'=>42,'validated_at'=>'2026-07-29 12:00:00']
                            + $file('CompanyData-v3-6.xsd', str_repeat('c', 64)),
                    ],
                ], JSON_THROW_ON_ERROR)];

                $accounts = $invokePrivate(
                    $service,
                    'schemaInventoryFromSubmission',
                    $submission,
                    'accounts'
                );
                $companyData = $invokePrivate(
                    $service,
                    'schemaInventoryFromSubmission',
                    $submission,
                    'company_data'
                );

                $harness->assertSame(2, count($accounts['files']));
                $harness->assertSame(str_repeat('a', 64), $accounts['files'][0]['sha256']);
                $harness->assertSame(1, count($companyData['files']));
                $harness->assertSame(str_repeat('c', 64), $companyData['files'][0]['sha256']);
            }
        );

        $harness->check(
            $service::class,
            'applies the configured taxonomy and Gateway date policy before filing',
            static function () use ($harness, $invokePrivate): void {
                $compatibility = new \eel_accounts\Service\IxbrlTaxonomyCompatibilityService([
                    'companies_house_gateway_available_from' => '2027-01-01',
                ]);
                $guarded = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                    taxonomyCompatibilityService: $compatibility
                );
                $assessment = $invokePrivate(
                    $guarded,
                    'taxonomyCompatibilityForPeriod',
                    ['period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                    '2026-07-26'
                );

                $harness->assertSame(false, (bool)($assessment['compatible'] ?? true));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($assessment['errors'] ?? [])),
                    '2027-01-01'
                ));
                $harness->assertSame(false, (bool)($assessment['gateway_response_confirmed'] ?? true));
            }
        );

        $harness->check(
            $service::class,
            'preserves complete Arelle diagnostics in revised artifact evidence metadata',
            static function () use ($harness, $service, $invokePrivate): void {
                $validation = [
                    'status' => 'passed',
                    'version' => 'Arelle 2.43.0',
                    'validated_at' => '2026-07-26T12:00:00Z',
                    'validated_sha256' => str_repeat('a', 64),
                    'errors' => [],
                    'warnings' => ['A reviewable taxonomy warning.'],
                    'log_path' => 'logs/arelle/example.log',
                ];
                $metadata = $invokePrivate(
                    $service,
                    'revisedArtifactEvidenceMetadata',
                    987,
                    $validation,
                    73
                );

                $harness->assertSame(987, (int)$metadata['base_run_id']);
                $harness->assertSame(73, (int)$metadata['fact_count']);
                $harness->assertSame(
                    \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::PRESENTATION_VERSION,
                    (string)$metadata['presentation_version']
                );
                $harness->assertSame(
                    $validation,
                    (array)$metadata['arelle_validation']
                );
                $harness->assertSame(
                    ['A reviewable taxonomy warning.'],
                    (array)$metadata['arelle_validation']['warnings']
                );
                $harness->assertSame(
                    'logs/arelle/example.log',
                    (string)$metadata['arelle_validation']['log_path']
                );
            }
        );

        $harness->check(
            $service::class,
            'requires exact authority validation evidence before a Companies House artifact is current',
            static function () use ($harness, $service, $invokePrivate): void {
                $path = tempnam(test_tmp_directory(), 'ch-current-artifact-');
                if ($path === false) {
                    $harness->skip('Could not create a temporary revised-accounts artifact.');
                }
                file_put_contents(
                    $path,
                    '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ix="http://www.xbrl.org/2013/inlineXBRL">'
                    . '<body><ix:nonNumeric name="bus:Name" contextRef="current">Example</ix:nonNumeric>'
                    . '<ix:nonFraction name="core:Assets" contextRef="current" unitRef="GBP">1</ix:nonFraction>'
                    . '</body></html>'
                );
                try {
                    $submission = [
                        'id' => 712,
                        'company_id' => 49,
                        'accounting_period_id' => 79,
                        'ixbrl_generation_run_id' => 18,
                        'filing_type' => 'revised',
                        'environment' => 'TEST',
                        'revision_declarations' => [
                            'original_approval_date' => '2025-05-29',
                            'revision_approval_date' => '2026-07-21',
                        ],
                        'filing_metadata' => [
                            'presentation_version' =>
                                \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::PRESENTATION_VERSION,
                        ],
                        'revised_artifact_path' => $path,
                        'revised_artifact_sha256' => hash_file('sha256', $path),
                        'basis_hash' => str_repeat('b', 64),
                    ];
                    $current = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('unvalidated', (string)$current['state']);
                    $harness->assertSame(false, (bool)$current['current']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$current['errors']),
                        'matching passed authority-profile validation record'
                    ));
                    $harness->assertSame(
                        \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::PRESENTATION_VERSION,
                        (string)$current['presentation_version']
                    );

                    $legacyPresentation = $submission;
                    $legacyPresentation['filing_metadata']['presentation_version'] =
                        'companies-house-revised-accounts-presentation-v1';
                    $legacy = $invokePrivate($service, 'preparedArtifactState', $legacyPresentation, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('stale', (string)$legacy['state']);
                    $harness->assertSame(false, (bool)$legacy['current']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$legacy['errors']),
                        'earlier presentation profile'
                    ));
                    $missingPresentation = $submission;
                    unset($missingPresentation['filing_metadata']['presentation_version']);
                    $missing = $invokePrivate($service, 'preparedArtifactState', $missingPresentation, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('stale', (string)$missing['state']);
                    $harness->assertSame(false, (bool)$missing['current']);

                    $stale = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 19,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('unvalidated', (string)$stale['state']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$stale['errors']),
                        'matching passed authority-profile validation record'
                    ));
                    $newer = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 713,
                    ]);
                    $harness->assertSame('unvalidated', (string)$newer['state']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$newer['errors']),
                        'matching passed authority-profile validation record'
                    ));

                    file_put_contents($path, '<html>changed</html>');
                    $tampered = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('tampered', (string)$tampered['state']);
                    $harness->assertSame(false, (bool)$tampered['current']);
                } finally {
                    @unlink($path);
                }
            }
        );

        $harness->check(
            $service::class,
            'does not reopen HMRC artifacts when deciding whether prepared Companies House XML can be sent',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains($body, 'preparedArtifactState('));
                $harness->assertFalse(str_contains($body, '$this->readiness('));
                $harness->assertFalse(str_contains($body, 'IxbrlFilingArtifactService'));
                $harness->assertTrue(str_contains($body, "hash('sha256', \$accountsXml)"));
                $harness->assertFalse(str_contains($body, "hash_file('sha256', \$artifactPath)"));
            }
        );

        $harness->check(
            $service::class,
            'classifies legacy revised artifacts with invalid approval dates as invalid',
            static function () use ($harness, $service, $invokePrivate): void {
                $state = $invokePrivate($service, 'preparedArtifactState', [
                    'filing_type' => 'revised',
                    'revision_declarations' => [
                        'original_approval_date' => '2025-06-28',
                        'revision_approval_date' => '2025-06-28',
                    ],
                    'artifact_path' => 'missing-legacy-revised-artifact.xhtml',
                ]);

                $harness->assertSame('invalid', (string)$state['state']);
                $harness->assertFalse((bool)$state['current']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$state['errors']),
                    'must be later than the original accounts approval date'
                ));
            }
        );

        $harness->check(
            $service::class,
            'keeps transmitted Companies House validation provenance immutable during developer revalidation',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'revalidatePreparedArtifact'
                );
                $source = file($method->getFileName());
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));

                $harness->assertTrue(str_contains(
                    $body,
                    "(string)(\$submission['lifecycle'] ?? '') !== 'prepared'"
                ));
                $harness->assertTrue(str_contains(
                    $body,
                    'WHERE id = :submission_id AND lifecycle = :lifecycle'
                ));
                foreach ([
                    "(string)(\$artifactEvidence['filing_kind'] ?? '') !== \$filingKind",
                    "(int)(\$artifactEvidence['generation_run_id'] ?? 0) !== \$baseRunId",
                    "(string)(\$artifactEvidence['profile_key'] ?? '') !== \$profile->key()",
                    "(string)(\$artifactEvidence['profile_version'] ?? '') !== \$profile->version()",
                    "(string)(\$currentSubmission['lifecycle'] ?? '') !== 'prepared'",
                ] as $guard) {
                    $harness->assertTrue(str_contains($body, $guard));
                }
            }
        );

        $harness->check(
            $service::class,
            'freezes and rechecks the approved Original or Revised classification before transmission',
            static function () use ($harness): void {
                $provenance = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'classificationProvenanceError'
                );
                $source = file($provenance->getFileName());
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $provenance->getStartLine() - 1,
                    $provenance->getEndLine() - $provenance->getStartLine() + 1
                ));
                foreach ([
                    "\$metadata['classification']",
                    "\$this->filingClassification(\$companyId, \$accountingPeriodId)",
                    "!hash_equals(\$frozenHash, \$currentHash)",
                ] as $guard) {
                    $harness->assertTrue(str_contains($body, $guard));
                }

                $submit = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $submitSource = file($submit->getFileName());
                $harness->assertTrue(is_array($submitSource));
                $submitBody = implode('', array_slice(
                    $submitSource,
                    $submit->getStartLine() - 1,
                    $submit->getEndLine() - $submit->getStartLine() + 1
                ));
                $harness->assertTrue(substr_count($submitBody, 'classificationProvenanceError(') >= 2);
            }
        );

        $harness->check(
            $service::class,
            'uses the frozen disclosure basis as the authoritative revised-accounts approval date',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
                $approval = [
                    'approved_at' => '2026-07-17 09:30:00',
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => [
                                'accounts_approval_date' => '2026-07-24',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];

                $date = $policy->resolveApprovalDate(
                    $approval,
                    ['revision_approval_date' => '2026-07-24'],
                    '2026-07-24',
                    '2025-06-28'
                );

                $harness->assertSame('2026-07-24', $date);
                $harness->assertFalse(str_starts_with((string)$date, '2026-07-17'));
            }
        );

        $harness->check(
            $service::class,
            'blocks a revised approval date that is not later than the original accounts approval date',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
                $error = $policy->revisionApprovalDateError(
                    '2025-06-28',
                    '2025-06-28'
                );

                $harness->assertTrue(is_string($error));
                $harness->assertTrue(str_contains(
                    (string)$error,
                    'must be later than the original accounts approval date'
                ));
                $harness->assertSame(
                    null,
                    $policy->revisionApprovalDateError(
                        '2025-06-28',
                        '2026-07-17'
                    )
                );
            }
        );

        $harness->check(
            $service::class,
            'rejects supplied or current approval dates that conflict with the frozen basis',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
                $approval = [
                    'approved_at' => '2026-07-17 09:30:00',
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => [
                                'accounts_approval_date' => '2026-07-24',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];

                $suppliedConflict = null;
                try {
                    $policy->resolveApprovalDate(
                        $approval,
                        ['revision_approval_date' => '2026-07-17'],
                        '2026-07-24',
                        '2025-06-28'
                    );
                } catch (RuntimeException $exception) {
                    $suppliedConflict = $exception;
                }
                $harness->assertTrue($suppliedConflict instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    mb_strtolower((string)$suppliedConflict?->getMessage()),
                    'conflicts'
                ));

                $currentConflict = null;
                try {
                    $policy->resolveApprovalDate(
                        $approval,
                        [],
                        '2026-07-17',
                        '2025-06-28'
                    );
                } catch (RuntimeException $exception) {
                    $currentConflict = $exception;
                }
                $harness->assertTrue($currentConflict instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    mb_strtolower((string)$currentConflict?->getMessage()),
                    'conflicts'
                ));
            }
        );

        $harness->check(
            $service::class,
            'rejects an invalid approval date in the frozen filing basis',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
                $approval = [
                    'approved_at' => '2026-07-24 09:30:00',
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => [
                                'accounts_approval_date' => '2026-02-30',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];

                $caught = null;
                try {
                    $policy->resolveApprovalDate(
                        $approval,
                        [],
                        '2026-02-28',
                        '2025-06-28'
                    );
                } catch (RuntimeException $exception) {
                    $caught = $exception;
                }

                $harness->assertTrue($caught instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    mb_strtolower((string)$caught?->getMessage()),
                    'valid accounts approval date'
                ));
            }
        );

        $harness->check(
            $service::class,
            'builds distinct non-compliance and amendment disclosures from comparison and model data',
            static function () use ($harness, $service, $invokePrivate): void {
                $genericExplanation = 'The original accounts omitted adjustments found by the comparison';
                $comparison = [
                    'rows' => [
                        [
                            'metric_key' => 'fixed_assets',
                            'label' => 'Fixed assets',
                            'app_value' => 431.43,
                            'filed_value' => 0.0,
                            'variance' => 431.43,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'current_assets',
                            'label' => 'Current assets',
                            'app_value' => 1115.54,
                            'filed_value' => 275.0,
                            'variance' => 840.54,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'prepayments_accrued_income',
                            'label' => 'Prepayments and accrued income',
                            'app_value' => 140.55,
                            'filed_value' => 0.0,
                            'variance' => 140.55,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'creditors_within_one_year',
                            'label' => 'Creditors due within one year',
                            'app_value' => 1314.63,
                            'filed_value' => 64.0,
                            'variance' => 1250.63,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'creditors_after_more_than_one_year',
                            'label' => 'Creditors due after more than one year',
                            'app_value' => 0.0,
                            'filed_value' => 0.0,
                            'variance' => 0.0,
                            'status' => 'pass',
                        ],
                        [
                            'metric_key' => 'net_assets_liabilities',
                            'label' => 'Net assets',
                            'app_value' => 372.89,
                            'filed_value' => 211.0,
                            'variance' => 161.89,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'equity_capital_reserves',
                            'label' => 'Capital and reserves',
                            'app_value' => 372.89,
                            'filed_value' => 211.0,
                            'variance' => 161.89,
                            'status' => 'fail',
                        ],
                    ],
                ];
                $model = [
                    'disclosures' => [
                        'profit_loss_not_delivered_section_444' => 0,
                    ],
                    'current' => [
                        'buckets' => [
                            'fixed_assets' => 431.43,
                            'depreciation_write_offs' => 197.41,
                            'current_assets' => 1115.54,
                            'prepayments_accrued_income' => 140.55,
                            'creditors_within_one_year' => 1314.63,
                            'creditors_after_more_than_one_year' => 0.0,
                            'net_assets_liabilities' => 372.89,
                            'equity_capital_reserves' => 372.89,
                        ],
                        'director_loan_reporting_presentation' => [
                            'applicable' => true,
                            'classification' => 'within_one_year',
                            'within_one_year' => 1035.63,
                            'after_more_than_one_year' => 0.0,
                            'party_facts' => [[
                                'reportable_liability' => 1035.63,
                                'repayable_on_demand' => true,
                                'terms' => ['repayable_on_demand' => true],
                            ]],
                        ],
                    ],
                    'director_loan_disclosure' => [
                        'has_company_to_director_exposure' => true,
                        'total_advances' => 253.0,
                        'total_cash_repayments' => 0.0,
                        'total_amounts_legally_set_off' => 253.0,
                        'total_director_funding' => 1288.63,
                        'closing_company_liability' => 1035.63,
                        'total_amounts_written_off' => 0.0,
                        'total_amounts_waived' => 0.0,
                        'disclosures' => [
                            ['director_name' => 'Test Director'],
                        ],
                    ],
                ];

                $texts = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    ['variance_explanation' => $genericExplanation],
                    [
                        'non_compliance_explanation' => $genericExplanation,
                        'significant_amendments' => $genericExplanation,
                    ],
                    $comparison,
                    $model,
                    [
                        [
                            'concept' => 'core:Creditors',
                            'context_ref' => 'current_period_end_superseded_creditors_within_one_year',
                            'value' => 64.0,
                        ],
                        [
                            'concept' => 'core:Creditors',
                            'context_ref' => 'current_period_end_superseded_creditors_after_one_year',
                            'value' => 0.0,
                        ],
                    ]
                );

                $nonCompliance = (string)($texts['non_compliance_explanation'] ?? '');
                $amendments = (string)($texts['significant_amendments'] ?? '');
                $amendmentsLower = mb_strtolower($amendments);


                $harness->assertTrue($nonCompliance !== '');
                $harness->assertTrue($amendments !== '');
                $harness->assertFalse(mb_strtolower($nonCompliance) === $amendmentsLower);
                $harness->assertTrue(str_contains(mb_strtolower($nonCompliance), 'original accounts'));
                foreach ([
                    'fixed assets',
                    'depreciation',
                    'current assets',
                    'prepayments',
                    'creditors falling due within one year',
                    'participator-loan',
                    'repayable on demand',
                    'net assets',
                    'capital and reserves',
                ] as $expectedPhrase) {
                    if (!str_contains($amendmentsLower, $expectedPhrase)) {
                        throw new RuntimeException(
                            'The amendments disclosure is missing: ' . $expectedPhrase . '.'
                        );
                    }
                }
                $harness->assertTrue(str_contains($amendments, '£431.43'));
                $harness->assertTrue(str_contains($amendments, '£64.00'));
                $harness->assertTrue(str_contains($amendments, '£1,314.63'));
                $harness->assertTrue(str_contains($amendments, '£279.00'));
                $harness->assertTrue(str_contains($amendments, '£1,035.63'));
                $harness->assertTrue(str_contains($amendments, '£1,288.63'));
                $harness->assertTrue(str_contains($amendments, '£253.00'));
                $harness->assertTrue(str_contains($amendments, '£0.00'));
                $harness->assertTrue(str_contains(
                    $amendmentsLower,
                    'does not change the company’s total net assets'
                ));
                $harness->assertTrue(str_contains(
                    $nonCompliance,
                    'originally reported as £64.00'
                ));
                $harness->assertFalse(str_contains(
                    $amendments,
                    'revised from £279.00'
                ));
                $harness->assertFalse(str_contains(
                    $amendments,
                    '£1,035.63 due after more than one year'
                ));
                $harness->assertSame(
                    '(£58.54)',
                    $invokePrivate($service, 'revisionMoney', -58.54)
                );
                $harness->assertTrue(str_contains($amendments, 'Fixed assets were restated'));
            }
        );

        $harness->check(
            $service::class,
            'suppresses generated profit and loss change wording when Section 444 non-delivery is elected',
            static function () use ($harness, $service, $invokePrivate): void {
                $supplied = 'User supplied explanation about an exceptional correction';
                $texts = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    ['variance_explanation' => $supplied],
                    [
                        'non_compliance_explanation' => $supplied,
                        'significant_amendments' => 'User supplied amendments remain unchanged',
                    ],
                    ['rows' => [[
                        'metric_key' => 'turnover',
                        'label' => 'Turnover',
                        'app_value' => 1000.0,
                        'filed_value' => 500.0,
                        'variance' => 500.0,
                        'status' => 'fail',
                    ], [
                        'metric_key' => 'fixed_assets',
                        'label' => 'Fixed assets',
                        'app_value' => 400.0,
                        'filed_value' => 200.0,
                        'variance' => 200.0,
                        'status' => 'fail',
                    ]]],
                    [
                        'disclosures' => ['profit_loss_not_delivered_section_444' => 1],
                        'current' => [
                            'buckets' => [
                                'fixed_assets' => 400.0,
                                'depreciation_write_offs' => 75.0,
                            ],
                            'director_loan_reporting_presentation' => [],
                        ],
                        'director_loan_disclosure' => [],
                    ],
                    []
                );
                $combined = mb_strtolower(
                    (string)$texts['non_compliance_explanation'] . ' '
                    . (string)$texts['significant_amendments']
                );
                $harness->assertTrue(str_contains($combined, mb_strtolower($supplied)));
                $harness->assertTrue(str_contains($combined, 'user supplied amendments remain unchanged'));
                $harness->assertTrue(str_contains($combined, 'fixed assets'));
                $harness->assertFalse(str_contains($combined, 'turnover'));
                $harness->assertFalse(str_contains($combined, 'depreciation'));
                $harness->assertFalse(str_contains($combined, 'profit'));
            }
        );

        $harness->check(
            $service::class,
            'states each changed creditor maturity class from original and revised fact models',
            static function () use ($harness, $service, $invokePrivate): void {
                $facts = static fn(float $within, float $after): array => [
                    [
                        'concept' => 'core:Creditors',
                        'context_ref' => 'current_period_end_superseded_creditors_within_one_year',
                        'value' => $within,
                    ],
                    [
                        'concept' => 'core:Creditors',
                        'context_ref' => 'current_period_end_superseded_creditors_after_one_year',
                        'value' => $after,
                    ],
                ];
                $sentence = static fn(array $facts, float $within, float $after): string => (string)$invokePrivate(
                    $service,
                    'creditorMaturityRestatementSentence',
                    $facts,
                    [
                        'creditors_within_one_year' => $within,
                        'creditors_after_more_than_one_year' => $after,
                    ]
                );

                $harness->assertSame(
                    'Creditors falling due within one year were restated from £64.00 to £279.00.',
                    $sentence($facts(64.0, 0.0), 279.0, 0.0)
                );
                $harness->assertSame(
                    'Creditors falling due after more than one year were restated from £0.00 to £1,035.63.',
                    $sentence($facts(64.0, 0.0), 64.0, 1035.63)
                );
                $harness->assertSame(
                    'Creditors falling due within one year were restated from £64.00 to £279.00, and creditors falling due after more than one year were restated from £0.00 to £1,035.63.',
                    $sentence($facts(64.0, 0.0), 279.0, 1035.63)
                );
                $harness->assertSame('', $sentence($facts(64.0, 0.0), 64.0, 0.0));
            }
        );
    }
);
