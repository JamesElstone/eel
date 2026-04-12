<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CompaniesHouseHelper::class, function (GeneratedServiceClassTestHarness $harness, CompaniesHouseHelper $helper): void {
    $harness->check(CompaniesHouseHelper::class, 'builds unique registered office address lines from company settings', function () use ($harness): void {
        $lines = CompaniesHouseHelper::storedAddressLines([
            'registered_office_care_of' => 'Accounts Team',
            'registered_office_po_box' => 'PO Box 1',
            'registered_office_premises' => 'Unit 4',
            'registered_office_address_line_1' => 'High Street',
            'registered_office_address_line_2' => 'High Street',
            'registered_office_locality' => 'London',
            'registered_office_region' => 'Greater London',
            'registered_office_postal_code' => 'SW1A 1AA',
            'registered_office_country' => 'United Kingdom',
        ]);

        $harness->assertSame(
            [
                'Accounts Team',
                'PO Box 1',
                'Unit 4',
                'High Street',
                'London',
                'Greater London',
                'SW1A 1AA',
                'United Kingdom',
            ],
            $lines
        );
    });
});
