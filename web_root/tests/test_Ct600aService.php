<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600aService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\Ct600aService $service): void {
        $period = ['period_start' => '2023-01-01', 'period_end' => '2023-12-31'];
        $review = ['current' => true, 'complete' => true, 'errors' => []];
        $base = ['errors' => [], 'window_status' => 'window_complete', 'lots' => [], 'repayment_allocations' => []];

        $h->check($service::class, 'records a complete negative section 464A position without emitting CT600A', static function () use ($h, $service, $period, $review, $base): void {
            $model = $service->buildFromEvidence($period, $base, [], $review, '2025-12-31');
            $h->assertSame(false, (bool)$model['required']);
            $h->assertSame(true, (bool)$model['complete']);
            $h->assertSame(0.0, (float)$model['tax_payable']);
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

        $h->check($service::class, 'routes post-filing relief to a separate L2P claim', static function () use ($h, $service, $period, $review, $base): void {
            $filedPeriod = $period + ['status' => 'accepted'];
            $events = [[
                'id' => 5, 'event_kind' => 'later_repayment', 'event_date' => '2024-03-01',
                'origin_date' => '2023-02-01', 'amount' => 100.0, 'party_id' => 10,
                'party_name' => 'Jamie Example', 'rate' => 0.3375, 'matching_status' => 'clear',
            ]];
            $model = $service->buildFromEvidence($filedPeriod, $base, $events, $review, '2025-12-31');
            $h->assertSame(0.0, (float)$model['part2']['relief_due']);
            $h->assertSame(1, count($model['separate_l2p_claim_events']));
        });
    }
);
