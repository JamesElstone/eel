<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenLedgerSpecification.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountingOracle.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenHmrcCorporationTaxOracle.php';

$h = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$h->check(GoldenAccountsFixture::class, 'seeds one current verified bank donation without reclassifying ordinary expenses', static function () use ($h): void {
    $service = new \eel_accounts\Service\CharitableDonationService();
    $verification = $service->currentVerification(9196);
    $h->assertTrue(is_array($verification));
    $h->assertSame('cc_ew', (string)$verification['authority']);
    $h->assertSame('9999999', (string)$verification['registration_number']);
    $h->assertSame('Golden Community Charity', (string)$verification['registered_name']);

    $qualified = $service->qualifyingPaidForPeriod(
        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
        9113,
        '2024-10-01',
        '2025-09-30'
    );
    $h->assertSame(250.00, (float)$qualified['total']);
    $h->assertSame(1, count((array)$qualified['rows']));

    $donationNominalId = (int)GoldenAccountsFixture::manifest()['nominals']['charitable_donations'];
    $h->assertSame(0, (int)\InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM expense_claim_lines ecl
         INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
         WHERE ec.company_id = :company_id AND ecl.nominal_account_id = :nominal_id',
        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'nominal_id' => $donationNominalId]
    ));
    $ordinary = \InterfaceDB::fetchAll(
        'SELECT t.id, t.category_status
         FROM transactions t
         WHERE t.company_id = :company_id AND t.nominal_account_id = :nominal_id
         ORDER BY t.id',
        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'nominal_id' => 91004]
    );
    $h->assertSame(4, count($ordinary));
    foreach ($ordinary as $transaction) {
        $h->assertSame('manual', (string)$transaction['category_status']);
        $h->assertSame(null, $service->currentVerification((int)$transaction['id']));
    }
});

$h->check(GoldenAccountsFixture::class, 'carries the AP9113 donation through P and L and Corporation Tax boxes', static function () use ($h): void {
    $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary(
        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
        9113
    );
    $h->assertSame(2297.00, (float)$profitLoss['posted_operating_expense_total']);
    $h->assertSame(250.00, (float)$profitLoss['charitable_donation_expense']);
    $h->assertSame(7300.38, (float)$profitLoss['operating_expense_total']);
    $h->assertSame(1699.62, (float)$profitLoss['profit_before_tax']);

    (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod(
        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
        9113
    );
    test_confirm_ct_period_facts(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113);
    $workings = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings(
        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
        9113,
        0
    );
    $summary = (array)$workings['summary'];
    foreach ([
        'accounting_profit' => 1699.62,
        'qualifying_charitable_donation_add_back' => 250.00,
        'taxable_before_losses' => 6953.00,
        'losses_used' => 1866.00,
        'profits_before_donations_group_relief' => 5087.00,
        'qualifying_charitable_donations_paid' => 250.00,
        'qualifying_charitable_donations_claimed' => 250.00,
        'unrelieved_qualifying_charitable_donations' => 0.00,
        'taxable_profit' => 4837.00,
        'estimated_corporation_tax' => 919.03,
    ] as $field => $expected) {
        $h->assertSame(number_format($expected, 2, '.', ''), number_format((float)($summary[$field] ?? 0), 2, '.', ''));
    }
    $h->assertSame(1, count((array)($workings['charitable_donations'] ?? [])));
});

$h->check(GoldenAccountsFixture::class, 'captures immutable charitable donation filing evidence with a stable hash', static function () use ($h): void {
    \InterfaceDB::beginTransaction();
    try {
        $service = new \eel_accounts\Service\FilingEvidenceSnapshotService();
        $first = $service->prepareForLock(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113, null, []);
        $second = $service->prepareForLock(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9113, null, []);
        $find = static function (array $sections): array {
            foreach ($sections as $section) {
                if ((string)($section['section_code'] ?? '') === 'charitable_donations') {
                    return $section;
                }
            }
            return [];
        };
        $section = $find($first);
        $again = $find($second);
        $payload = json_decode((string)$section['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $h->assertSame(1, (int)$section['record_count']);
        $h->assertSame((string)$section['snapshot_hash'], (string)$again['snapshot_hash']);
        $h->assertSame(250.00, (float)$payload['totals']['verified_amount']);
        $h->assertSame('9999999', (string)$payload['records'][0]['registration_number']);
        $h->assertSame(9196, (int)$payload['records'][0]['transaction_id']);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
});
