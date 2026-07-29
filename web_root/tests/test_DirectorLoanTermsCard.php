<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function directorLoanTermsCardXpath(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $document = new DOMDocument();
    if (!$document->loadHTML(
        '<!doctype html><html><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING
    )) {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        throw new RuntimeException('The Director Loan terms card HTML could not be parsed.');
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_director_loan_termsCard::class, static function (GeneratedServiceClassTestHarness $harness, _director_loan_termsCard $card): void {
    $context = [
        'company' => ['id' => 47, 'accounting_period_id' => 74],
        'page' => ['page_id' => 'loans', 'page_cards' => ['director_loan_terms']],
        'services' => [
            'partyTerms' => [
                'success' => true,
                'is_locked' => false,
                'parties' => [
                    [
                        'party' => ['id' => 9, 'legal_name' => 'On-demand party', 'party_type' => 'director'],
                        'terms' => [
                            'interest_rate_percent' => 0,
                            'security_type' => 'unsecured',
                            'repayable_on_demand' => 1,
                            'repayment_timing' => 'after_12_months',
                            'deferment_right_confirmed' => 1,
                            'set_off_right_confirmed' => 1,
                            'settlement_intention' => 'net',
                            'advance_terms' => [
                                'interest_rate_percent' => 3.25,
                                'security_type' => 'secured',
                                'repayment_basis' => 'fixed_date',
                                'fixed_repayment_date' => '2027-04-05',
                            ],
                            'advance_terms_explicit' => true,
                        ],
                        'explicit' => true,
                    ],
                    [
                        'party' => ['id' => 10, 'legal_name' => 'Long-term party', 'party_type' => 'director'],
                        'terms' => [
                            'interest_rate_percent' => 1.5,
                            'security_type' => 'secured',
                            'repayable_on_demand' => 0,
                            'repayment_timing' => 'after_12_months',
                            'deferment_right_confirmed' => 1,
                            'set_off_right_confirmed' => 0,
                            'settlement_intention' => 'independently',
                        ],
                        'explicit' => true,
                    ],
                    [
                        'party' => ['id' => 11, 'legal_name' => 'Current party', 'party_type' => 'director'],
                        'terms' => [
                            'interest_rate_percent' => 2,
                            'security_type' => 'unsecured',
                            'repayable_on_demand' => 0,
                            'repayment_timing' => 'after_12_months',
                            'deferment_right_confirmed' => 0,
                            'set_off_right_confirmed' => 0,
                            'settlement_intention' => 'simultaneous',
                        ],
                        'explicit' => true,
                    ],
                    [
                        'party' => ['id' => 12, 'legal_name' => 'Unconfigured party', 'party_type' => 'director'],
                        'terms' => [
                            'interest_rate_percent' => 0,
                            'security_type' => 'unsecured',
                            'repayable_on_demand' => 1,
                            'repayment_timing' => 'within_12_months',
                            'deferment_right_confirmed' => 0,
                            'set_off_right_confirmed' => 0,
                            'settlement_intention' => 'independently',
                        ],
                        'explicit' => false,
                    ],
                ],
            ],
        ],
    ];

    $harness->check(_director_loan_termsCard::class, 'uses one required canonical repayment-basis control', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);
        $xpath = directorLoanTermsCardXpath($html);
        $creditorSelect = '//form[@id="participator-loan-terms-add"]'
            . '//select[@name="repayment_basis"]';

        $harness->assertTrue(str_contains($html, 'name="csrf_token"'));
        $harness->assertSame(1, $xpath->query($creditorSelect)?->length);
        $harness->assertSame(1, $xpath->query($creditorSelect . '[@required]')?->length);
        foreach (['', 'on_demand', 'within_12_months', 'after_12_months'] as $value) {
            $harness->assertSame(1, $xpath->query(
                $creditorSelect . '/option[@value="' . $value . '"]'
            )?->length);
        }
        $harness->assertSame(1, $xpath->query(
            $creditorSelect . '/option[@value="" and @selected]'
        )?->length);
        $harness->assertSame(0, $xpath->query(
            $creditorSelect . '/option[@value="fixed_date" or @value="no_fixed_date"]'
        )?->length);
        $harness->assertSame(false, str_contains($html, 'Applies to the entire party balance at the accounting-period end.'));
        $harness->assertSame(false, str_contains($html, 'name="repayable_on_demand"'));
        $harness->assertSame(false, str_contains($html, 'name="repayment_timing"'));
        $harness->assertSame(false, str_contains($html, 'name="deferment_right_confirmed"'));
    });

    $harness->check(_director_loan_termsCard::class, 'derives legacy repayment terms using reporting precedence', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'data-repayment-basis="on_demand"'));
        $harness->assertTrue(str_contains($html, 'data-repayment-basis="after_12_months"'));
        $harness->assertTrue(str_contains($html, 'data-repayment-basis="within_12_months"'));
        $harness->assertTrue(str_contains($html, 'data-repayment-basis=""'));
        $harness->assertSame(false, str_contains($html, 'data-repayable-on-demand='));
        $harness->assertSame(false, str_contains($html, 'data-repayment-timing='));
        $harness->assertSame(false, str_contains($html, 'data-deferment-right-confirmed='));
        $harness->assertTrue(str_contains($html, 'Repayment basis'));
        $harness->assertTrue(str_contains($html, 'Not selected'));
        $harness->assertSame(false, str_contains($html, '>On demand</th>'));
        $harness->assertSame(false, str_contains($html, '>Repayment</th>'));
        $harness->assertSame(false, str_contains($html, '>Deferral 12+ months</th>'));
    });

    $harness->check(_director_loan_termsCard::class, 'prefills only the canonical repayment-basis field', static function () use ($harness): void {
        $projectJs = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');

        $harness->assertTrue(str_contains($projectJs, "setValue('repayment_basis', String(option.dataset.repaymentBasis || ''));"));
        $harness->assertSame(false, str_contains($projectJs, "setChecked('repayable_on_demand'"));
        $harness->assertSame(false, str_contains($projectJs, "setValue('repayment_timing'"));
        $harness->assertSame(false, str_contains($projectJs, "setChecked('deferment_right_confirmed'"));
    });

    $harness->check(_director_loan_termsCard::class, 'separates creditor and statutory-disclosure terms registers', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);
        $xpath = directorLoanTermsCardXpath($html);
        $creditorTable = '//h3[normalize-space(.)="Participator-to-company funding (creditor) terms register"]'
            . '/parent::section//table[1]';
        $advanceTable = '//h3[normalize-space(.)="Company-to-participator advance (statutory disclosure) terms register"]'
            . '/parent::section//table[1]';

        $harness->assertSame(1, $xpath->query($creditorTable)?->length);
        $harness->assertSame(1, $xpath->query($advanceTable)?->length);
        $harness->assertSame(false, str_contains($html, 'Complete this only from the advance agreement.'));
        $harness->assertSame(0, $xpath->query(
            $creditorTable . '//th[normalize-space(.)="Advance repayment condition"]'
        )?->length);
        $harness->assertSame(1, $xpath->query(
            $advanceTable . '//th[normalize-space(.)="Advance repayment condition"]'
        )?->length);
        $harness->assertSame(1, $xpath->query(
            $advanceTable . '//th[normalize-space(.)="Fixed repayment date"]'
        )?->length);
        $harness->assertSame(1, $xpath->query(
            $advanceTable . '//tr[td[1][normalize-space(.)="On-demand party"]]'
                . '/td[5][normalize-space(.)="Fixed repayment date"]'
        )?->length);
        $harness->assertSame(1, $xpath->query(
            $advanceTable . '//tr[td[1][normalize-space(.)="On-demand party"]]'
                . '/td[6][normalize-space(.)="2027-04-05"]'
        )?->length);
        $harness->assertTrue($xpath->query(
            $advanceTable . '//td[5][normalize-space(.)="Not confirmed"]'
        )?->length > 0);
        $harness->assertSame(0, $xpath->query(
            $advanceTable . '//td[normalize-space(.)="Repayable on demand"]'
        )?->length);
    });
});
