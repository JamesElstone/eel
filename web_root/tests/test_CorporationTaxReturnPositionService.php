<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxReturnPositionService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CorporationTaxReturnPositionService $service): void {
        $summary = [
            'available' => true,
            'ordinary_corporation_tax' => 1900.0,
            's455_tax' => 270.0,
            'estimated_corporation_tax' => 2170.0,
        ];
        $ct600a = [
            'available' => true,
            'complete' => true,
            'part1' => ['total_loans' => 800.0, 'tax_chargeable' => 270.0],
            'part2' => ['relief_due' => 0.0],
            'part3' => ['relief_due' => 0.0],
            'total_loans_outstanding' => 800.0,
            'tax_payable' => 270.0,
            'blocking_errors' => [],
        ];

        $h->check($service::class, 'adds CT600A A80 exactly once to ordinary Corporation Tax', static function () use ($h, $service, $summary, $ct600a): void {
            $position = $service->fromModels(0, 0, 1, $summary, $ct600a);

            $h->assertSame(1900.0, (float)$position['ct600_boxes']['475']);
            $h->assertSame(270.0, (float)$position['ct600_boxes']['480']);
            $h->assertSame(2170.0, (float)$position['ct600_boxes']['510']);
            $h->assertSame(2170.0, (float)$position['tax_payable']);
        });

        $h->check($service::class, 'keeps Net S455 diagnostic and A80 payable distinct', static function () use ($h, $service, $summary, $ct600a): void {
            $model = $ct600a;
            $model['part1']['tax_chargeable'] = 400.0;
            $model['part3']['relief_due'] = 130.0;
            $model['tax_payable'] = 270.0;
            $position = $service->fromModels(0, 0, 1, $summary, $model);

            $h->assertSame(270.0, (float)$position['s455_tax']);
            $h->assertSame(270.0, (float)$position['ct600a_tax']);
            $h->assertSame(2170.0, (float)$position['tax_payable']);
        });

        $h->check($service::class, 'supports a section 464A-only A80 amount without inferring a component', static function () use ($h, $service, $summary, $ct600a): void {
            $ordinaryOnly = $summary;
            $ordinaryOnly['s455_tax'] = 0.0;
            $arrangements = $ct600a;
            $arrangements['tax_payable'] = 337.5;
            $position = $service->fromModels(0, 0, 1, $ordinaryOnly, $arrangements);

            $h->assertSame(0.0, (float)$position['s455_tax']);
            $h->assertSame(337.5, (float)$position['ct600a_tax']);
            $h->assertSame(2237.5, (float)$position['tax_payable']);
            $h->assertTrue(!array_key_exists('s464a_tax', $position));
        });

        $h->check($service::class, 'keeps return liability, L2P relief and partial payment separate', static function () use ($h, $service): void {
            $aggregate = $service->aggregatePositions(
                1,
                2,
                [[
                    'available' => true,
                    'complete' => true,
                    'ordinary_corporation_tax' => 1900.0,
                    's455_tax' => 270.0,
                    'ct600a_tax' => 270.0,
                ]],
                ['available' => true, 'amount_paid' => 1000.0],
                ['available' => true, 'relief_receivable' => 100.0]
            );

            $h->assertSame(2170.0, (float)$aggregate['tax_payable']);
            $h->assertSame(1170.0, (float)$aggregate['payment_outstanding']);
            $h->assertSame(100.0, (float)$aggregate['l2p_relief_receivable']);
            $h->assertSame(2070.0, (float)$aggregate['estimated_tax_charge']);
        });
    }
);
