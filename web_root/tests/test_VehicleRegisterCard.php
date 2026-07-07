<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_vehicle_registerCard::class, static function (GeneratedServiceClassTestHarness $harness, _vehicle_registerCard $card): void {
    $harness->check(_vehicle_registerCard::class, 'declares vehicle service with selected company context', static function () use ($harness, $card): void {
        $services = $card->services();
        $vehicleRegisterService = (array)($services[0] ?? []);
        $params = (array)($vehicleRegisterService['params'] ?? []);

        $harness->assertSame('vehicleRegister', $vehicleRegisterService['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\VehicleService::class, $vehicleRegisterService['service'] ?? null);
        $harness->assertSame('fetchRegister', $vehicleRegisterService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
    });

    $harness->check(_vehicle_registerCard::class, 'renders editable vehicle rows with warnings and disabled row save', static function () use ($harness, $card): void {
        $html = $card->render(vehicleRegisterCardContext([
            'warnings' => [
                'Unreviewed motor vehicles remain in 1320 for this accounting period.',
            ],
            'vehicle_types' => [
                'unreviewed' => 'Unreviewed',
                'car' => 'Car',
                'van' => 'Van',
            ],
            'acquisition_conditions' => [
                '' => 'Select',
                'new_unused' => 'New and Unused',
                'second_hand' => 'Second Hand',
            ],
            'vehicle_colours' => [
                '' => 'Not recorded',
                'Blue' => 'Blue',
                'White' => 'White',
            ],
            'rows' => [[
                'id' => 44,
                'asset_code' => 'FA-33-1',
                'description' => 'Ford Transit',
                'purchase_date' => '2026-07-01',
                'cost' => 12000,
                'nominal_code' => '1320',
                'nominal_name' => 'Motor Vehicles',
                'vehicle_type' => 'unreviewed',
                'tax_review_status' => 'unreviewed',
                'registration_mark' => 'AB12 CDE',
                'make_model' => 'Ford Transit',
                'colour' => 'White',
                'payload_kg' => '1100.00',
                'warnings' => [
                    'Vehicle asset is still in 1320 and must be reviewed before year end.',
                ],
            ]],
        ]));

        $harness->assertTrue(str_contains($html, '<span class="badge warning">Warning</span>'));
        $harness->assertTrue(str_contains($html, 'Unreviewed motor vehicles remain in 1320'));
        $harness->assertTrue(str_contains($html, 'data-vehicle-row="true"'));
        $harness->assertTrue(str_contains($html, 'class="vehicle-register-table"'));
        $harness->assertTrue(str_contains($html, 'vehicle-register-controls vehicle-facts-controls'));
        $harness->assertTrue(str_contains($html, 'vehicle-register-controls vehicle-tax-controls'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Vehicle"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_vehicle_details"'));
        $harness->assertTrue(str_contains($html, 'name="vehicle_type"'));
        $harness->assertTrue(str_contains($html, 'name="vehicle_type" data-vehicle-watch data-no-submit-on-change="true"'));
        $harness->assertTrue(str_contains($html, 'name="colour" data-vehicle-watch data-no-submit-on-change="true"'));
        $harness->assertTrue(str_contains($html, '<option value="White" selected>White</option>'));
        $harness->assertTrue(str_contains($html, 'name="acquisition_condition" form="vehicle-row-44" data-vehicle-watch data-no-submit-on-change="true"'));
        $harness->assertTrue(str_contains($html, 'name="registration_mark"'));
        $harness->assertTrue(str_contains($html, 'name="co2_emissions_g_km"'));
        $harness->assertTrue(str_contains($html, 'name="payload_kg"'));
        $harness->assertSame(false, str_contains($html, 'Contract date'));
        $harness->assertSame(false, str_contains($html, 'name="contract_date"'));
        $harness->assertTrue(str_contains($html, 'data-vehicle-watch'));
        $harness->assertTrue(str_contains($html, 'data-vehicle-save disabled'));
        $harness->assertTrue(str_contains($html, '<label class="checkbox-row"><span>Zero emission</span><input type="checkbox"'));
        $harness->assertTrue(str_contains($html, 'FA-33-1'));
        $harness->assertTrue(str_contains($html, 'Ford Transit'));
        $harness->assertTrue(str_contains($html, 'AB12 CDE'));
        $harness->assertTrue(str_contains($html, '$ 12,000.00'));
    });

    $harness->check(_vehicle_registerCard::class, 'renders schema migration empty state', static function () use ($harness, $card): void {
        $html = $card->render(vehicleRegisterCardContext([
            'schema_ready' => false,
        ]));

        $harness->assertTrue(str_contains($html, 'Run the vehicle register migration before reviewing vehicles.'));
    });

    $harness->check(_vehicle_registerCard::class, 'renders no vehicle empty state', static function () use ($harness, $card): void {
        $html = $card->render(vehicleRegisterCardContext([
            'rows' => [],
        ]));

        $harness->assertTrue(str_contains($html, 'No vehicle assets are waiting for review in the selected accounting period.'));
    });
});

function vehicleRegisterCardContext(array $vehicleRegister): array
{
    return [
        'company' => [
            'id' => 33,
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#36;',
                'default_bank_nominal_id' => 8,
            ],
        ],
        'services' => [
            'vehicleRegister' => $vehicleRegister,
        ],
    ];
}
