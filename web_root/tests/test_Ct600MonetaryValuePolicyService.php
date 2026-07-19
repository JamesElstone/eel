<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600MonetaryValuePolicyService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\Ct600MonetaryValuePolicyService $service
    ): void {
        $h->check($service::class, 'serializes pound-and-pence values without reducing precision', static function () use ($h, $service): void {
            $type = 'ct:CTpoundPenceStructure';
            $path = 'CompanyTaxReturn/CompanyTaxCalculation/CorporationTax';
            $h->assertSame('0.00', $service->serialize(0, $type, $path));
            $h->assertSame('123.40', $service->serialize('123.40', $type, $path));
            $h->assertSame('123.45', $service->serialize(123.45, $type, $path));
            $h->assertSame('-123.45', $service->serialize('-123.45', 'ct:CT_IRmonetaryStructure', $path));
            $h->assertSame('123.46', $service->serialize('123.455', $type, $path));
        });

        $h->check($service::class, 'rounds declared whole-pound datatypes half up', static function () use ($h, $service): void {
            $type = 'ct:CTwholePoundStructure';
            $path = 'CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/Profits';
            $h->assertSame('123.00', $service->serialize('123.49', $type, $path));
            $h->assertSame('124.00', $service->serialize('123.50', $type, $path));
            $h->assertSame('-124.00', $service->serialize('-123.50', $type, $path));
        });

        $h->check($service::class, 'applies explicit target-path rounding overrides', static function () use ($h, $service): void {
            $path = 'IRenvelope/CompanyTaxReturn/CTF/TonnageTax/QualifyingShips/Total';
            $h->assertSame('123.00', $service->serialize('123.99', 'ct:IRnonNegativeWholeUnitsMonetaryStructure', $path));
        });

        $h->check($service::class, 'fails closed for malformed and ambiguous inputs', static function () use ($h, $service): void {
            foreach ([
                ['12.34', 'ct:UnknownDecimalType', 'CompanyTaxReturn/Unknown'],
                ['12.34', 'ct:UnexpectedWholeUnitsMonetaryStructure', 'CompanyTaxReturn/UnknownWholeAmount'],
                ['not-money', 'ct:CTpoundPenceStructure', 'CompanyTaxReturn/Tax'],
                ['12.34', 'ct:CTwholePoundStructure', ''],
            ] as [$value, $type, $path]) {
                $failed = false;
                try {
                    $service->serialize($value, $type, $path);
                } catch (\InvalidArgumentException) {
                    $failed = true;
                }
                $h->assertTrue($failed);
            }
        });
    }
);
