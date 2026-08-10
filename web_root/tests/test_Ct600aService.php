<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600aService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\Ct600aService $service): void {
        $period = ['period_start' => '2023-01-01', 'period_end' => '2023-12-31'];
        $review = ['current' => true, 'complete' => true, 'errors' => []];
        $base = ['errors' => [], 'window_status' => 'window_complete', 'lots' => [], 'repayment_allocations' => []];

        $h->check($service::class, 'removes only the observation clock from the immutable filing projection', static function () use ($h, $service): void {
            $first = [
                'required' => false,
                'window_status' => 'window_complete',
                'repayment_window_end' => '2024-06-30',
                'evidence_cutoff' => '2026-08-09 23:59:59',
                's455' => [
                    'evidence_cutoff' => '2026-08-09 23:59:59',
                    'basis' => [
                        'evidence_cutoff' => '2026-08-09 23:59:59',
                        'gross_principal' => 0.0,
                    ],
                ],
            ];
            $second = $first;
            $second['evidence_cutoff'] = '2026-08-10 23:59:59';
            $second['s455']['evidence_cutoff'] = '2026-08-10 23:59:59';
            $second['s455']['basis']['evidence_cutoff'] = '2026-08-10 23:59:59';

            $firstProjection = $service->filingBasisProjection($first);
            $secondProjection = $service->filingBasisProjection($second);
            $h->assertSame($firstProjection, $secondProjection);
            $h->assertSame(
                hash('sha256', json_encode($firstProjection, JSON_THROW_ON_ERROR)),
                hash('sha256', json_encode($secondProjection, JSON_THROW_ON_ERROR))
            );
            $h->assertFalse(array_key_exists('evidence_cutoff', $firstProjection));
            $h->assertFalse(array_key_exists('evidence_cutoff', $firstProjection['s455']));
            $h->assertFalse(array_key_exists('evidence_cutoff', $firstProjection['s455']['basis']));
            $h->assertSame('2024-06-30', $firstProjection['repayment_window_end']);
            $h->assertSame('window_complete', $firstProjection['window_status']);
            $h->assertSame('2026-08-09 23:59:59', $first['evidence_cutoff']);

            $changed = $second;
            $changed['window_status'] = 'provisional_window_open';
            $h->assertTrue(
                $service->filingBasisProjection($changed) !== $firstProjection
            );
        });

        $h->check($service::class, 'records a complete negative section 464A position without emitting CT600A', static function () use ($h, $service, $period, $review, $base): void {
            $model = $service->buildFromEvidence($period, $base, [], $review, '2025-12-31');
            $h->assertSame(false, (bool)$model['required']);
            $h->assertSame(true, (bool)$model['complete']);
            $h->assertSame(0.0, (float)$model['tax_payable']);
        });

        $h->check($service::class, 'records a Section 455 narrative only for advances fully repaid in the same CT period', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base;
            $s455['lots'] = [[
                'transaction_id' => 129, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-06-01', 'original_amount' => 253.0,
                'remaining_at_period_end' => 0.0, 'rate' => 0.3375,
            ]];
            $s455['repayment_allocations'] = [[
                'loan_transaction_id' => 129, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'repayment_date' => '2023-08-15', 'amount' => 253.0, 'rate' => 0.3375,
            ]];

            $model = $service->buildFromEvidence($period, $s455, [], $review, '2025-12-31');

            $h->assertSame('repaid_within_period', (string)$model['section_455_narrative']);
            $h->assertSame(true, (bool)$model['before_end_period']);
            $h->assertSame(false, (bool)$model['required']);
            $h->assertSame(0.0, (float)$model['part1']['total_loans']);
            $h->assertSame(0.0, (float)$model['tax_payable']);
        });

        $h->check($service::class, 'does not carry an earlier CT period repayment into a later period narrative', static function () use ($h, $service, $review, $base): void {
            $laterPeriod = ['period_start' => '2023-09-05', 'period_end' => '2023-09-30'];
            $s455 = $base;
            $s455['lots'] = [[
                'transaction_id' => 129, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-06-01', 'original_amount' => 253.0,
                'remaining_at_period_end' => 0.0, 'rate' => 0.3375,
            ]];
            $s455['repayment_allocations'] = [[
                'loan_transaction_id' => 129, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'repayment_date' => '2023-08-15', 'amount' => 253.0, 'rate' => 0.3375,
            ]];

            $model = $service->buildFromEvidence($laterPeriod, $s455, [], $review, '2025-12-31');

            $h->assertSame(null, $model['section_455_narrative']);
            $h->assertSame(false, (bool)$model['before_end_period']);
            $h->assertSame(false, (bool)$model['required']);
            $h->assertSame(0.0, (float)$model['tax_payable']);
        });

        $h->check($service::class, 'does not claim no amount is reportable when an earlier loan remains outstanding', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base;
            $s455['lots'] = [[
                'transaction_id' => 129, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-06-01', 'original_amount' => 253.0,
                'remaining_at_period_end' => 0.0, 'rate' => 0.3375,
            ]];
            $s455['all_lots'] = [
                $s455['lots'][0],
                [
                    'transaction_id' => 12, 'party_id' => 10, 'party_name' => 'Jamie Example',
                    'origin_date' => '2022-12-01', 'original_amount' => 50.0,
                    'remaining_at_period_end' => 50.0, 'rate' => 0.3375,
                ],
            ];
            $s455['repayment_allocations'] = [[
                'loan_transaction_id' => 129, 'repayment_date' => '2023-08-15', 'amount' => 253.0,
            ]];

            $model = $service->buildFromEvidence($period, $s455, [], $review, '2025-12-31');

            $h->assertSame(null, $model['section_455_narrative']);
        });

        $h->check($service::class, 'uses Yes/No section 464A and 464C risk declarations', static function () use ($h, $service): void {
            $questions = $service->reviewQuestions();

            $h->assertTrue(str_starts_with((string)$questions['missing_parties'], 'Are any participators'));
            $h->assertTrue(str_starts_with((string)$questions['unrecorded_value'], 'Was any value'));
            $h->assertTrue(str_starts_with((string)$questions['indirect_benefit'], 'Was any benefit'));
            $h->assertTrue(str_starts_with((string)$questions['replacement_extraction'], 'Was a repayment'));
        });

        $h->check($service::class, 'calculates Parts 1 and 2 at the original loan rate', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base + [];
            $s455['lots'] = [[
                'transaction_id' => 1, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-06-01', 'remaining_at_period_end' => 1000.0, 'rate' => 0.3375,
            ]];
            $s455['repayment_allocations'] = [[
                'party_id' => 10, 'party_name' => 'Jamie Example', 'repayment_date' => '2024-03-01',
                'amount' => 100.0, 'rate' => 0.3375,
            ]];
            $model = $service->buildFromEvidence($period, $s455, [], $review, '2025-12-31');
            $h->assertSame(1000.0, (float)$model['part1']['total_loans']);
            $h->assertSame(337.5, (float)$model['part1']['tax_chargeable']);
            $h->assertSame(33.75, (float)$model['part2']['relief_due']);
            $h->assertSame(303.75, (float)$model['tax_payable']);
            $h->assertSame(1000.0, (float)$model['total_loans_outstanding']);
        });

        $h->check($service::class, 'does not allow section 464A return-payment relief after 29 October 2024', static function () use ($h, $service, $period, $review, $base): void {
            $events = [[
                'id' => 1, 'event_kind' => 's464a_benefit', 'event_date' => '2023-07-01',
                'amount' => 1000.0, 'party_id' => 10, 'party_name' => 'Jamie Example', 'rate' => 0.3375,
                'matching_status' => 'clear',
            ], [
                'id' => 2, 'event_kind' => 's464a_return_payment', 'event_date' => '2024-10-30',
                'amount' => 1000.0, 'party_id' => 10, 'party_name' => 'Jamie Example', 'rate' => 0.3375,
                'matching_status' => 'clear',
            ]];
            $model = $service->buildFromEvidence($period, $base, $events, $review, '2025-12-31');
            $h->assertSame(0.0, (float)$model['part2']['relief_due']);
            $h->assertSame(0.0, (float)$model['part3']['relief_due']);
            $h->assertSame(337.5, (float)$model['tax_payable']);
        });

        $h->check($service::class, 'blocks relief with a potential section 464C replacement extraction', static function () use ($h, $service, $period, $review, $base): void {
            $events = [[
                'id' => 3, 'event_kind' => 'later_repayment', 'event_date' => '2024-03-01',
                'amount' => 100.0, 'party_id' => 10, 'party_name' => 'Jamie Example', 'rate' => 0.3375,
                'matching_status' => 'potential_464c',
            ]];
            $model = $service->buildFromEvidence($period, $base, $events, $review, '2025-12-31');
            $h->assertSame(false, (bool)$model['complete']);
            $h->assertTrue(str_contains(implode(' ', $model['blocking_errors']), '464C'));
            $h->assertSame(0.0, (float)$model['part2']['relief_due']);
        });

        $h->check($service::class, 'surfaces unattributed loan movements as one aggregated filing blocker', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base;
            $s455['errors'] = [
                'Loan transaction #5465 is not linked to a confirmed ownership party.',
                'Loan transaction #6140 is not linked to a confirmed ownership party.',
            ];
            $model = $service->buildFromEvidence($period, $s455, [], $review, '2025-12-31');

            $h->assertSame(2, (int)$model['unattributed_loan_movement_count']);
            $h->assertTrue(str_contains(implode(' ', (array)$model['blocking_errors']), '2 participator-loan transaction(s) require party attribution'));
            $h->assertSame(false, (bool)$model['complete']);
        });

        $h->check($service::class, 'surfaces an incomplete section 464A review as a filing blocker', static function () use ($h, $service, $period, $base): void {
            $incompleteReview = [
                'current' => false,
                'complete' => false,
                'errors' => ['Complete and approve the section 464A review.'],
            ];
            $model = $service->buildFromEvidence($period, $base, [], $incompleteReview, '2025-12-31');

            $h->assertTrue(in_array('Complete and approve the section 464A review.', (array)$model['blocking_errors'], true));
            $h->assertSame(false, (bool)$model['review_complete']);
            $h->assertSame(false, (bool)$model['complete']);
        });

        $h->check($service::class, 'reduces period-end loans for an evidenced release before the period end', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base;
            $s455['lots'] = [[
                'transaction_id' => 4, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-02-01', 'remaining_at_period_end' => 1000.0, 'rate' => 0.3375,
            ]];
            $events = [[
                'id' => 4, 'event_kind' => 'release', 'event_date' => '2023-11-01',
                'origin_date' => '2023-02-01', 'amount' => 200.0, 'party_id' => 10,
                'party_name' => 'Jamie Example', 'rate' => 0.3375, 'matching_status' => 'clear',
            ]];
            $model = $service->buildFromEvidence($period, $s455, $events, $review, '2025-12-31');
            $h->assertSame(800.0, (float)$model['part1']['total_loans']);
            $h->assertSame(270.0, (float)$model['tax_payable']);
            $h->assertSame(true, (bool)$model['before_end_period']);
        });

        $h->check($service::class, 'preserves filed A80 and identifies a post-filing early-relief claim separately from L2P', static function () use ($h, $service, $period, $review, $base): void {
            $filedPeriod = $period + ['status' => 'accepted'];
            $events = [[
                'id' => 5, 'event_kind' => 'later_repayment', 'event_date' => '2024-03-01',
                'origin_date' => '2023-02-01', 'amount' => 100.0, 'party_id' => 10,
                'party_name' => 'Jamie Example', 'rate' => 0.3375, 'matching_status' => 'clear',
            ]];
            $model = $service->buildFromEvidence($filedPeriod, $base, $events, $review, '2025-12-31');
            $h->assertSame(0.0, (float)$model['part2']['relief_due']);
            $h->assertSame(1, count($model['separate_l2p_claim_events']));
            $h->assertSame('early_post_filing_claim', (string)$model['separate_l2p_claim_events'][0]['claim_type']);
            $h->assertSame(0.0, (float)$model['separate_l2p_relief_due']);
        });

        $h->check($service::class, 'carries prior-period transaction lots into A75 without charging them again in A15', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base;
            $s455['lots'] = [[
                'transaction_id' => 8, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-06-01', 'remaining_at_period_end' => 1000.0, 'rate' => 0.3375,
            ]];
            $s455['all_lots'] = [[
                'transaction_id' => 7, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2022-06-01', 'remaining_at_period_end' => 400.0, 'rate' => 0.3375,
            ], $s455['lots'][0]];
            $model = $service->buildFromEvidence($period, $s455, [], $review, '2025-12-31');

            $h->assertSame(1000.0, (float)$model['part1']['total_loans']);
            $h->assertSame(400.0, (float)$model['derived_prior_period_outstanding']);
            $h->assertSame(1400.0, (float)$model['total_loans_outstanding']);
        });

        $h->check($service::class, 'recognises A70 only when the repayment accounting period tax due date is reached', static function () use ($h, $service, $period, $review, $base): void {
            $s455 = $base;
            $s455['lots'] = [[
                'transaction_id' => 9, 'party_id' => 10, 'party_name' => 'Jamie Example',
                'origin_date' => '2023-06-01', 'remaining_at_period_end' => 1000.0, 'rate' => 0.3375,
            ]];
            $s455['all_repayment_allocations'] = [[
                'loan_transaction_id' => 9, 'repayment_transaction_id' => 10,
                'party_id' => 10, 'party_name' => 'Jamie Example',
                'loan_date' => '2023-06-01', 'repayment_date' => '2024-11-01',
                'amount' => 100.0, 'rate' => 0.3375,
                'repayment_accounting_period_end' => '2024-12-31',
                'relief_due_date' => '2025-10-01',
            ]];

            $beforeDue = $service->buildFromEvidence($period, $s455, [], $review, '2025-09-30');
            $onDue = $service->buildFromEvidence($period, $s455, [], $review, '2025-10-01');
            $filed = $service->buildFromEvidence($period + ['status' => 'accepted'], $s455, [], $review, '2025-10-01');
            $h->assertSame(0.0, (float)$beforeDue['part3']['relief_due']);
            $h->assertSame(33.75, (float)$onDue['part3']['relief_due']);
            $h->assertSame(303.75, (float)$onDue['tax_payable']);
            $h->assertSame(337.5, (float)$filed['tax_payable']);
            $h->assertSame(33.75, (float)$filed['separate_l2p_relief_due']);
            $h->assertSame(1, count((array)$filed['separate_l2p_claim_events']));
            $h->assertSame('later_l2p', (string)$filed['separate_l2p_claim_events'][0]['claim_type']);
        });
    }
);
