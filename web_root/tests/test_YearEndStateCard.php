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

$harness->run(_year_end_stateCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_stateCard $card): void {
    $harness->check(_year_end_stateCard::class, 'declares director loan offset service', static function () use ($harness, $card): void {
        $services = $card->services();
        $offsetService = null;
        foreach ($services as $service) {
            if (($service['key'] ?? '') === 'directorLoanOffset') {
                $offsetService = $service;
                break;
            }
        }

        $harness->assertSame(\eel_accounts\Service\DirectorLoanReconciliationService::class, $offsetService['service'] ?? null);
        $harness->assertSame('fetchContext', $offsetService['method'] ?? null);
    });

    $harness->check(_year_end_stateCard::class, 'declares services with selected company context', static function () use ($harness, $card): void {
        foreach ($card->services() as $service) {
            $params = (array)($service['params'] ?? []);
            if (array_key_exists('companyId', $params)) {
                $harness->assertSame(':company.id', (string)$params['companyId']);
            }

            if (array_key_exists('accountingPeriodId', $params)) {
                $harness->assertSame(':company.accounting_period_id', (string)$params['accountingPeriodId']);
            }
        }
    });

    $harness->check(_year_end_stateCard::class, 'renders CSP-safe review notes and related workflow control', static function () use ($harness, $card): void {
        $context = yearEndStateCardDirectorLoanContext([
            'available' => false,
            'errors' => ['Director loan offset is not available.'],
        ]);
        $context['services']['yearEndChecklist']['sections'] = [
            'ledger_integrity' => [
                [
                    'title' => 'Trial balance review',
                    'status' => 'warning',
                    'detail_text' => 'Review the current imbalance.',
                    'metric_value' => '2 warnings',
                    'action_url' => '?page=trial_balance',
                ],
            ],
        ];

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'year-end-review-notes'));
        $harness->assertSame(false, str_contains($html, 'style='));
        $harness->assertSame(true, str_contains($html, 'year-end-check-panel'));
        $harness->assertSame(true, str_contains($html, 'year-end-related-workflow'));
        $harness->assertSame(true, str_contains($html, 'Open Related Workflow'));
        $harness->assertSame(false, str_contains($html, 'Open related workflow'));
    });

    $harness->check(_year_end_stateCard::class, 'renders director loan offset balances and post button', static function () use ($harness, $card): void {
        $html = $card->render(yearEndStateCardDirectorLoanContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'asset_nominal' => ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset'],
            'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
            'asset_receivable' => 1000,
            'liability_payable' => 1500,
            'offset_amount' => 1000,
            'net_position' => 500,
            'net_position_label' => 'Company owes director',
            'posted_offset_amount' => 0,
            'offset_status' => 'missing',
            'offset_status_label' => 'Missing',
            'warnings' => [],
            'can_post' => true,
        ]));

        $harness->assertSame(true, str_contains($html, 'Director loan offset'));
        $harness->assertSame(true, str_contains($html, '1200 - Director Loan Asset'));
        $harness->assertSame(true, str_contains($html, '2100 - Director Loan Liability'));
        $harness->assertSame(true, str_contains($html, 'post_director_loan_offset'));
        $harness->assertSame(true, str_contains($html, 'Post Offset Journal'));
    });

    $harness->check(_year_end_stateCard::class, 'hides post button when offset cannot be posted', static function () use ($harness, $card): void {
        $html = $card->render(yearEndStateCardDirectorLoanContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'asset_nominal' => ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset'],
            'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
            'asset_receivable' => -100,
            'liability_payable' => 1500,
            'offset_amount' => 0,
            'net_position' => 1600,
            'net_position_label' => 'Company owes director',
            'posted_offset_amount' => 0,
            'offset_status' => 'not_required',
            'offset_status_label' => 'Not required',
            'warnings' => ['Director Loan Asset has an abnormal credit balance. Review postings before offsetting.'],
            'can_post' => false,
            'post_blocked_reason' => 'Review abnormal director loan balances before posting an offset journal.',
        ]));

        $harness->assertSame(true, str_contains($html, 'abnormal credit balance'));
        $harness->assertSame(false, str_contains($html, 'post_director_loan_offset'));
        $harness->assertSame(false, str_contains($html, 'Post Offset Journal'));
    });

    $harness->check(_year_end_stateCard::class, 'renders missing nominal warning', static function () use ($harness, $card): void {
        $html = $card->render(yearEndStateCardDirectorLoanContext([
            'available' => false,
            'errors' => ['Director Loan Asset nominal 1200 is not available.'],
        ]));

        $harness->assertSame(true, str_contains($html, 'Director Loan Asset nominal 1200 is not available.'));
        $harness->assertSame(false, str_contains($html, 'Post Offset Journal'));
    });

    $harness->check(_year_end_stateCard::class, 'annotates confirmed empty month tiles', static function () use ($harness, $card): void {
        $context = yearEndStateCardDirectorLoanContext([
            'available' => false,
            'errors' => ['Director loan offset is not available.'],
        ]);
        $context['services']['yearEndChecklist']['month_tiles'] = [
            [
                'label' => 'September 2022',
                'month_short_name' => 'Sep',
                'status' => 'green',
                'transaction_count' => 0,
                'statement_upload_count' => 0,
                'posted_journal_count' => 0,
                'uncategorised_count' => 0,
                'suspense_count' => 0,
                'empty_month_confirmed' => true,
            ],
        ];

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Confirmed no activity'));
        $harness->assertSame(true, str_contains($html, '0 posted journal(s)'));
    });
});

function yearEndStateCardDirectorLoanContext(array $offset): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Director Loan Fixture Limited',
            'accounting_period_id' => 70,
        ],
        'services' => [
            'yearEndChecklist' => [
                'overall_status' => 'needs_attention',
                'last_recalculated_at' => '2026-01-01 10:00:00',
                'accounting_period' => ['id' => 70],
                'review' => ['is_locked' => false, 'review_notes' => ''],
                'month_tiles' => [],
                'sections' => [],
            ],
            'yearEndTaxReadiness' => [
                'available' => false,
                'errors' => ['Tax readiness is not available.'],
            ],
            'yearEndOpeningBalances' => [
                'available' => false,
                'errors' => ['Opening balances are not available.'],
            ],
            'yearEndAdjustments' => [
                'available' => true,
                'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
                'nominals' => [],
                'adjustments' => [],
            ],
            'directorLoanOffset' => $offset,
            'yearEndCompaniesHouseComparison' => [
                'available' => false,
                'errors' => ['No Companies House comparison is available.'],
            ],
        ],
    ];
}
