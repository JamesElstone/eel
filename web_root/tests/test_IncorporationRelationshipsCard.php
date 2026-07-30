<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_incorporation_relationshipsCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_relationshipsCard $card
): void {
    $harness->check(_incorporation_relationshipsCard::class, 'keeps shareholder status derived from share allocations', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'services' => ['ownership' => [
                'available' => true,
                'parties' => [[
                    'id' => 12,
                    'legal_name' => 'Example Owner',
                    'roles' => [[
                        'id' => 3,
                        'role_type' => 'participator',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'effective_holdings' => [[
                        'id' => 8,
                        'effective_from' => '2026-01-01',
                    ]],
                ], [
                    'id' => 13,
                    'legal_name' => 'Unrelated Party',
                    'roles' => [],
                    'effective_holdings' => [],
                ]],
            ]],
        ]);

        $harness->assertSame(
            'Record effective ownership and filing-authority relationships. Shareholder status is derived from effective share allocations, and each party may hold multiple roles.',
            $card->helper([])
        );
        $harness->assertTrue(str_contains($html, '<option value="participator">Participator</option>'));
        $harness->assertTrue(str_contains($html, '<option value="associate">Associate</option>'));
        $harness->assertTrue(str_contains($html, '<option value="company_secretary">Company Secretary</option>'));
        $harness->assertTrue(str_contains($html, '<option value="authorised_agent">Authorised Agent</option>'));
        $harness->assertTrue(str_contains($html, '<option value="authorised_employee">Authorised Employee</option>'));
        $harness->assertTrue(str_contains($html, '<option value="tax_agent_or_accountant">Tax Agent or Accountant</option>'));
        $harness->assertTrue(str_contains($html, '<option value="liquidator">Liquidator</option>'));
        $harness->assertFalse(str_contains($html, '<option value="shareholder">Shareholder</option>'));
        $harness->assertTrue(str_contains($html, 'Shareholder (calculated)'));
        $harness->assertTrue(str_contains($html, '<th>Manage</th>'));
        $harness->assertTrue(str_contains($html, 'aria-label="Last effective date"'));
        $harness->assertTrue(str_contains($html, '>End role</button>'));
        $harness->assertFalse(str_contains($html, 'End an ownership role'));
        $harness->assertTrue(str_contains($html, '<th>Relationship</th>'));
        $harness->assertTrue(str_contains($html, '<label>Relationship</label><select'));
        $harness->assertTrue(str_contains($html, '>Add new Relationship</h4>'));
        $harness->assertFalse(str_contains($html, '<th>Role</th>'));
        $harness->assertFalse(str_contains($html, '<label>Role</label>'));
        $harness->assertFalse(str_contains($html, '>Add effective role</h4>'));
        $harness->assertTrue(str_contains($html, '<option value="12">Example Owner</option>'));
        $harness->assertTrue(str_contains($html, '<option value="13">Unrelated Party</option>'));
    });
});
