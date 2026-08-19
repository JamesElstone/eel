<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlDirectorsReportContentService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlDirectorsReportContentService $service
    ): void {
        $harness->check($service::class, 'splits confirmation notes by lines and sentence punctuation without losing Unicode', static function () use ($harness, $service): void {
            $sentences = new ReflectionMethod($service::class, 'sentences');
            $sentences->setAccessible(true);
            $harness->assertSame([
                'First sentence.',
                'Second sentence!',
                'Third question?',
                'Café résumé without punctuation',
            ], $sentences->invoke(
                $service,
                "First sentence. Second sentence!\nThird question?\r\nCafé résumé without punctuation"
            ));
        });

        $harness->check($service::class, 'returns a deterministic blank source when no context is selected', static function () use ($harness, $service): void {
            $result = $service->fetch(0, 0);
            $harness->assertFalse((bool)$result['available']);
            $harness->assertTrue((bool)$result['review_notes_blank']);
            $harness->assertSame(hash('sha256', ''), $result['review_notes_hash']);
            $harness->assertSame([], $result['confirmation_sentences']);
        });

        $harness->check($service::class, 'canonicalises the legacy database apostrophe without changing valid Unicode', static function () use ($harness, $service): void {
            foreach ([
                'companies',
                'accounting_periods',
                'year_end_reviews',
                'year_end_review_acknowledgements',
            ] as $table) {
                if (!\InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }

            $canonical = new ReflectionMethod($service::class, 'canonicalNarrativeText');
            $canonical->setAccessible(true);
            $harness->assertSame(
                "Caf\u{00E9} \u{2014} the company\u{2019}s report.",
                $canonical->invoke($service, "Caf\u{00E9} \u{2014} the company\u{2019}s report.")
            );

            \InterfaceDB::beginTransaction();
            try {
                $companyId = random_int(830000000, 839999000);
                $accountingPeriodId = $companyId + 1;
                \InterfaceDB::execute(
                    'INSERT INTO companies (id, company_name, company_number, is_active)
                     VALUES (:id, :company_name, :company_number, 1)',
                    [
                        'id' => $companyId,
                        'company_name' => 'Directors Report UTF-8 Fixture Limited',
                        'company_number' => 'DR' . substr((string)$companyId, -6),
                    ]
                );
                \InterfaceDB::execute(
                    'INSERT INTO accounting_periods (
                        id, company_id, label, period_start, period_end
                     ) VALUES (
                        :id, :company_id, :label, :period_start, :period_end
                     )',
                    [
                        'id' => $accountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'Directors report UTF-8 fixture',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                \InterfaceDB::execute(
                    'INSERT INTO year_end_reviews (
                        company_id, accounting_period_id, is_locked, locked_at,
                        locked_by, review_notes
                     ) VALUES (
                        :company_id, :accounting_period_id, 1, :locked_at,
                        :locked_by, :review_notes
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'locked_at' => '2026-08-19 12:00:00',
                        'locked_by' => 'utf8-test',
                        'review_notes' => "The company\u{00E2}\u{20AC}\u{2122}s year-end narrative.",
                    ]
                );
                \InterfaceDB::execute(
                    'INSERT INTO year_end_review_acknowledgements (
                        company_id, accounting_period_id, check_code,
                        acknowledged_at, acknowledged_by, note,
                        basis_version, basis_hash
                     ) VALUES (
                        :company_id, :accounting_period_id, :check_code,
                        :acknowledged_at, :acknowledged_by, :note,
                        :basis_version, :basis_hash
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'check_code' => 'utf8_directors_report',
                        'acknowledged_at' => '2026-08-19 12:01:00',
                        'acknowledged_by' => 'utf8-test',
                        'note' => "Director\u{00E2}\u{20AC}\u{2122}s confirmation.",
                        'basis_version' => 'utf8-test-v1',
                        'basis_hash' => str_repeat('a', 64),
                    ]
                );

                $result = $service->fetch($companyId, $accountingPeriodId);
                $harness->assertTrue((bool)($result['available'] ?? false));
                $harness->assertSame(
                    "The company\u{2019}s year-end narrative.",
                    (string)($result['review_notes'] ?? '')
                );
                $harness->assertSame(
                    hash('sha256', "The company\u{2019}s year-end narrative."),
                    (string)($result['review_notes_hash'] ?? '')
                );
                $harness->assertSame(
                    "Director\u{2019}s confirmation.",
                    (string)($result['source_acknowledgements'][0]['note'] ?? '')
                );
                $harness->assertSame(
                    ["Director\u{2019}s confirmation."],
                    (array)($result['confirmation_sentences'] ?? [])
                );
            } finally {
                \InterfaceDB::rollBack();
            }
        });
    }
);
