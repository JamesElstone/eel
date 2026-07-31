<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ixbrl'
    . DIRECTORY_SEPARATOR . 'IxbrlFactSnapshot.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlRevisedAccountsArtifactService $service): void {
        $harness->check($service::class, 'validates revision declarations before locating an artifact', static function () use ($harness, $service): void {
            $result = $service->prepare(0, 0, []);
            $harness->assertFalse((bool)($result['success'] ?? true));
            $harness->assertTrue((array)($result['errors'] ?? []) !== []);
        });

        $harness->check($service::class, 'rejects a revision approval date that is not later than the original approval date', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'inputErrors');
            $method->setAccessible(true);
            $errors = (array)$method->invoke($service, 49, 80, [
                'original_document_id' => 89,
                'original_approval_date' => '2025-06-28',
                'revision_approval_date' => '2025-06-28',
                'non_compliance_explanation' => 'The original report contained an error.',
                'significant_amendments' => 'The reported amounts were corrected.',
            ]);

            $harness->assertTrue(str_contains(
                implode(' ', $errors),
                'must be later than the original accounts approval date'
            ));
        });

        $harness->check($service::class, 'creates a separate revised page and hidden superseded facts without weakening the XML envelope', static function () use ($harness, $service): void {
            $source = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n"
                . '<html xmlns="http://www.w3.org/1999/xhtml"'
                . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
                . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
                . ' xmlns:xbrldi="http://xbrl.org/2006/xbrldi"'
                . ' xmlns:bus="http://xbrl.frc.org.uk/cd/2026-01-01/business"'
                . ' xmlns:core="http://xbrl.frc.org.uk/fr/2026-01-01/core"'
                . ' xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2015-02-26"'
                . ' xmlns:iso4217="http://www.xbrl.org/2003/iso4217" lang="en" xml:lang="en">'
                . '<head><title>Micro-entity accounts</title></head><body>'
                . '<div class="ixbrl-header"><ix:header><ix:hidden></ix:hidden><ix:resources>'
                . '<xbrli:context id="current_period_duration"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">01234567</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2025-01-01</xbrli:startDate><xbrli:endDate>2025-12-31</xbrli:endDate></xbrli:period></xbrli:context>'
                . '<xbrli:context id="current_period_end"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">01234567</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>2025-12-31</xbrli:instant></xbrli:period></xbrli:context>'
                . '<xbrli:context id="current_period_end_creditors_within_one_year"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">01234567</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>2025-12-31</xbrli:instant></xbrli:period></xbrli:context>'
                . '<xbrli:context id="current_period_end_creditors_after_one_year"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">01234567</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>2025-12-31</xbrli:instant></xbrli:period></xbrli:context>'
                . '<xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>'
                . '</ix:resources></ix:header></div>'
                . '<div class="accountspage titlepage"><h1><ix:nonNumeric name="bus:EntityCurrentLegalOrRegisteredName" contextRef="current_period_duration">Example Limited</ix:nonNumeric></h1>'
                . '<p><ix:nonNumeric name="bus:UKCompaniesHouseRegisteredNumber" contextRef="current_period_duration">01234567</ix:nonNumeric></p>'
                . '<h2>MICRO-ENTITY ACCOUNTS</h2></div>'
                . '<div class="accountspage pagebreak" id="preserved">'
                . '<table class="page-header" id="ordinary-notes-header"><colgroup>'
                . '<col class="page-header-name-column"/><col class="page-header-number-column"/>'
                . '</colgroup><tbody><tr><td class="page-header-name">'
                . '<ix:nonNumeric id="ordinary-header-name-fact" name="bus:EntityCurrentLegalOrRegisteredName" contextRef="current_period_duration">Example Limited</ix:nonNumeric>'
                . '</td><td class="page-header-number">Registered number '
                . '<ix:nonNumeric id="ordinary-header-number-fact" name="bus:UKCompaniesHouseRegisteredNumber" contextRef="current_period_duration">01234567</ix:nonNumeric>'
                . '</td></tr><tr><td class="page-header-title" colspan="2">'
                . 'Notes to the Micro-entity Accounts · For the period ended '
                . '<ix:nonNumeric id="ordinary-header-end-fact" name="bus:EndDateForPeriodCoveredByReport" contextRef="current_period_end" format="ixt:datedaymonthyearen">31 December 2025</ix:nonNumeric>'
                . '</td></tr></tbody></table>'
                . '<h2>Notes to the Micro-entity Accounts</h2>'
                . '<ix:nonNumeric name="core:DateAuthorisationFinancialStatementsForIssue" contextRef="current_period_end" format="ixt:datedaymonthyearen">21 July 2026</ix:nonNumeric>'
                . '<ix:nonFraction name="core:FixedAssets" contextRef="current_period_end" unitRef="GBP" decimals="2" format="ixt:numdotdecimal">100.00</ix:nonFraction>'
                . '<ix:nonFraction name="core:Equity" contextRef="current_period_end" unitRef="GBP" decimals="2" format="ixt:numdotdecimal">100.00</ix:nonFraction>'
                . '<ix:nonFraction name="core:Creditors" contextRef="current_period_end_creditors_within_one_year" unitRef="GBP" decimals="2" format="ixt:numdotdecimal">279.00</ix:nonFraction>'
                . '<ix:nonFraction name="core:Creditors" contextRef="current_period_end_creditors_after_one_year" unitRef="GBP" decimals="2" format="ixt:numdotdecimal">1035.63</ix:nonFraction></div>'
                . '<div id="hmrc-revision-explanation"><p><ix:nonNumeric name="bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006" contextRef="current_period_duration">The original report contained an error.</ix:nonNumeric></p></div>'
                . '</body></html>';
            $oldDeclarations = [
                'replaces_statement' => 'These revised accounts replace the previously filed report.',
                'statutory_accounts_statement' => 'These are now the statutory accounts.',
                'prepared_as_statement' => 'Prepared by reference to the original accounts, not the revision date, and excluding intervening events.',
                'non_compliance_explanation' => 'The original report contained an error.',
                'significant_amendments' => 'The comparative figures were corrected.',
                'original_approval_date' => '2025-05-29',
                'revision_approval_date' => '2026-07-21',
            ];
            $superseded = [[
                'concept' => 'core:FixedAssets',
                'context_ref' => 'current_period_end_superseded',
                'value' => 0.0,
                'unit_ref' => 'GBP',
                'decimals' => '2',
                'source_document_id' => 90,
            ], [
                'concept' => 'core:Creditors',
                'context_ref' => 'current_period_end_superseded_creditors_within_one_year',
                'value' => 64.0,
                'unit_ref' => 'GBP',
                'decimals' => '2',
                'source_document_id' => 90,
            ], [
                'concept' => 'core:Creditors',
                'context_ref' => 'current_period_end_superseded_creditors_after_one_year',
                'value' => 0.0,
                'unit_ref' => 'GBP',
                'decimals' => '2',
                'source_document_id' => 90,
            ], [
                'concept' => 'core:Equity',
                'context_ref' => 'current_period_end_superseded',
                'value' => -4567.80,
                'unit_ref' => 'GBP',
                'decimals' => '2',
                'source_document_id' => 90,
            ]];
            $result = $service->transform(
                $source,
                $oldDeclarations,
                'EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF',
                $superseded
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $xhtml = (string)($result['xhtml'] ?? '');
            $harness->assertTrue(str_starts_with(
                $xhtml,
                '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n"
            ));
            $harness->assertFalse(str_contains($xhtml, '<!DOCTYPE'));
            $harness->assertTrue(str_contains($xhtml, 'id="preserved"'));
            $harness->assertFalse(str_contains($xhtml, ' lang="en"'));
            $harness->assertTrue(str_contains($xhtml, ' xml:lang="en"'));
            $harness->assertTrue(str_contains($xhtml, 'REVISED ACCOUNTS'));
            $harness->assertTrue(str_contains($xhtml, 'Micro-entity accounts'));
            $harness->assertTrue(str_contains($xhtml, 'class="accountspage pagebreak revision-page"'));
            $harness->assertFalse(str_contains($xhtml, 'id="hmrc-revision-explanation"'));
            $harness->assertFalse(str_contains($xhtml, 'data-revision-explanation'));
            $harness->assertTrue(str_contains($xhtml, 'These revised accounts were approved on '));
            $harness->assertTrue(str_contains($xhtml, 'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:OriginalRevisedDataDimension">bus:Superseded'));
            $harness->assertTrue(str_contains($xhtml, 'name="core:FixedAssets" contextRef="current_period_end_superseded"'));
            $harness->assertFalse(str_contains($xhtml, 'name="core:Equity" contextRef="current_period_end_superseded"'));
            $harness->assertTrue(str_contains($xhtml, 'format="ixt:datedaymonthyearen">21 July 2026'));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<div class="evidence-footer">Evidence ID: EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF</div>'
            ));
            $harness->assertSame(3, (int)($result['superseded_fact_count'] ?? 0));
            $factDocument = new DOMDocument();
            $harness->assertTrue($factDocument->loadXML($xhtml, LIBXML_NONET));
            $factXpath = new DOMXPath($factDocument);
            $factXpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            $factXpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
            $revisionPage = $factXpath->query('//xhtml:div[@id="revised-accounts-statements"]')->item(0);
            $harness->assertTrue($revisionPage instanceof DOMElement);
            $pageHeaders = $factXpath->query(
                '//xhtml:table[contains(concat(" ", normalize-space(@class), " "), " page-header ")]'
            );
            $harness->assertSame(2, $pageHeaders->length);
            $harness->assertSame(0, $factXpath->query(
                '//xhtml:div[contains(concat(" ", normalize-space(@class), " "), " page-header ")]'
            )->length);
            $revisionHeader = $factXpath->query(
                '//xhtml:div[@id="revised-accounts-statements"]'
                . '/xhtml:table[contains(concat(" ", normalize-space(@class), " "), " page-header ")]'
            )->item(0);
            $harness->assertTrue($revisionHeader instanceof DOMElement);
            $harness->assertTrue(str_contains($revisionHeader->textContent, 'REVISED ACCOUNTS'));
            $harness->assertSame(1, $factXpath->query(
                '//xhtml:h2[normalize-space(.)="Notes to the Revised Micro-entity Accounts"]'
            )->length);
            $notesHeader = $factXpath->query(
                '//xhtml:table[@id="ordinary-notes-header"]/xhtml:tbody/xhtml:tr'
                . '/xhtml:td[contains(concat(" ", normalize-space(@class), " "), " page-header-title ")]'
            )->item(0);
            $harness->assertTrue($notesHeader instanceof DOMElement);
            $harness->assertTrue(str_contains(
                $notesHeader->textContent,
                'Notes to the Revised Micro-entity Accounts · For the period ended 31 December 2025'
            ));
            $harness->assertSame(1, $factXpath->query(
                '//xhtml:table[@id="ordinary-notes-header"]'
                . '//ix:nonNumeric[@name="bus:EndDateForPeriodCoveredByReport"'
                . ' and @contextRef="current_period_end"]'
            )->length);
            foreach ([
                'bus:EntityCurrentLegalOrRegisteredName',
                'bus:UKCompaniesHouseRegisteredNumber',
                'bus:EndDateForPeriodCoveredByReport',
            ] as $headerConcept) {
                $facts = $factXpath->query(
                    '//xhtml:table[contains(concat(" ", normalize-space(@class), " "), " page-header ")]'
                    . '//ix:nonNumeric[@name="' . $headerConcept . '"]'
                );
                $harness->assertSame(2, $facts->length);
                $first = $facts->item(0);
                $second = $facts->item(1);
                $harness->assertTrue($first instanceof DOMElement);
                $harness->assertTrue($second instanceof DOMElement);
                $harness->assertSame($first->getAttribute('contextRef'), $second->getAttribute('contextRef'));
                $harness->assertSame($first->getAttribute('format'), $second->getAttribute('format'));
                $harness->assertSame(trim($first->textContent), trim($second->textContent));
            }
            $ids = [];
            foreach ($factXpath->query('//*[@id]') as $elementWithId) {
                if ($elementWithId instanceof DOMElement) {
                    $ids[] = $elementWithId->getAttribute('id');
                }
            }
            $harness->assertSame(count($ids), count(array_unique($ids)));
            $harness->assertTrue(in_array('ordinary-notes-header-revision', $ids, true));
            $harness->assertTrue(in_array('ordinary-header-end-fact-revision', $ids, true));
            $harness->assertTrue(str_contains($revisionPage->textContent, 'The original report contained an error.'));
            $harness->assertSame(1, $factXpath->query(
                '//ix:nonNumeric[@name="bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006"]'
            )->length);
            $harness->assertTrue(str_contains($revisionPage->textContent, '29 May 2025'));
            $harness->assertTrue(str_contains($revisionPage->textContent, '21 July 2026'));
            $harness->assertSame(
                $factXpath->query('//ix:nonFraction | //ix:nonNumeric')->length,
                (int)($result['fact_count'] ?? 0)
            );
            $harness->assertTrue((int)($result['fact_count'] ?? 0) > 4);

            $negativeEquityOnly = $service->transform(
                $source,
                $oldDeclarations,
                '',
                [[
                    'concept' => 'core:Equity',
                    'context_ref' => 'current_period_end_superseded',
                    'value' => -4567.80,
                    'unit_ref' => 'GBP',
                    'decimals' => '2',
                    'source_document_id' => 90,
                ]]
            );
            $harness->assertTrue((bool)($negativeEquityOnly['success'] ?? false));
            $harness->assertSame(0, (int)($negativeEquityOnly['superseded_fact_count'] ?? -1));
            $harness->assertFalse(str_contains(
                (string)($negativeEquityOnly['xhtml'] ?? ''),
                'id="current_period_end_superseded"'
            ));
            $harness->assertTrue(str_starts_with(
                (string)(($negativeEquityOnly['warnings'] ?? [])[0] ?? ''),
                'IXBRL-HMRC-NEGATIVE-EQUITY:'
            ));

            $sourceWithOmittedNegativeEquity = str_replace(
                '<ix:nonFraction name="core:Equity" contextRef="current_period_end" unitRef="GBP" decimals="2" format="ixt:numdotdecimal">100.00</ix:nonFraction>',
                '<ix:nonFraction name="core:NetAssetsLiabilities" contextRef="current_period_end" unitRef="GBP" decimals="2" format="ixt:numdotdecimal" sign="-">4567.80</ix:nonFraction>',
                $source
            );
            $positiveSupersededEquity = $service->transform(
                $sourceWithOmittedNegativeEquity,
                $oldDeclarations,
                '',
                [[
                    'concept' => 'core:Equity',
                    'context_ref' => 'current_period_end_superseded',
                    'value' => 100.0,
                    'unit_ref' => 'GBP',
                    'decimals' => '2',
                    'source_document_id' => 90,
                ]]
            );
            $harness->assertTrue((bool)($positiveSupersededEquity['success'] ?? false));
            $harness->assertSame(1, (int)($positiveSupersededEquity['superseded_fact_count'] ?? 0));
            $harness->assertTrue(str_contains(
                (string)($positiveSupersededEquity['xhtml'] ?? ''),
                'name="core:Equity" contextRef="current_period_end_superseded"'
            ));

            $enhancedDeclarations = $oldDeclarations;
            $enhancedDeclarations['non_compliance_explanation'] =
                'Creditors falling due within one year were originally reported as £64.00.';
            $enhancedDeclarations['significant_amendments'] =
                'Creditors falling due within one year were restated from £64.00 to £279.00, and '
                . 'creditors falling due after more than one year were restated from £0.00 to £1,035.63.';
            $enhanced = $service->transform(
                $source,
                $enhancedDeclarations,
                '',
                $superseded
            );
            $harness->assertTrue((bool)($enhanced['success'] ?? false));
            $enhancedXhtml = (string)($enhanced['xhtml'] ?? '');
            $document = new DOMDocument();
            $harness->assertTrue($document->loadXML($enhancedXhtml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
            $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            $amendments = $xpath->query(
                '//ix:nonNumeric[@name="bus:StatementSignificantAmendmentsToPreviouslyFiledReport"]'
            );
            $harness->assertSame(1, $amendments->length);
            $amendment = $amendments->item(0);
            $harness->assertTrue($amendment instanceof DOMElement);
            $harness->assertSame('current_period_duration', $amendment->getAttribute('contextRef'));
            $sentence = 'Creditors falling due within one year were restated from £64.00 to £279.00, and '
                . 'creditors falling due after more than one year were restated from £0.00 to £1,035.63.';
            $harness->assertSame(1, substr_count($amendment->textContent, $sentence));
            $harness->assertSame(1, substr_count($enhancedXhtml, $sentence));
            $harness->assertSame(1, $xpath->query('//ix:nonFraction[@name="core:Creditors" and @contextRef="current_period_end_creditors_within_one_year"]')->length);
            $harness->assertSame(1, $xpath->query('//ix:nonFraction[@name="core:Creditors" and @contextRef="current_period_end_creditors_after_one_year"]')->length);
            $harness->assertSame(1, $xpath->query('//ix:hidden//ix:nonFraction[@name="core:Creditors" and @contextRef="current_period_end_superseded_creditors_within_one_year"]')->length);
            $harness->assertSame(1, $xpath->query('//ix:hidden//ix:nonFraction[@name="core:Creditors" and @contextRef="current_period_end_superseded_creditors_after_one_year"]')->length);
            $harness->assertSame(
                0,
                $xpath->query(
                    '//ix:hidden//ix:nonNumeric[@name="bus:StatementSignificantAmendmentsToPreviouslyFiledReport"]'
                )->length
            );
            $harness->assertSame(
                1,
                $xpath->query(
                    '//xhtml:div[@id="revised-accounts-statements"]'
                    . '//ix:nonNumeric[@name="bus:StatementSignificantAmendmentsToPreviouslyFiledReport"]'
                )->length
            );

            $comparison = (new \eel_accounts\Tests\Support\Ixbrl\IxbrlFactSnapshot())->compare(
                $xhtml,
                $enhancedXhtml,
                [
                    '{http://xbrl.frc.org.uk/cd/2026-01-01/business}'
                        . 'StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006',
                    '{http://xbrl.frc.org.uk/cd/2026-01-01/business}'
                        . 'StatementSignificantAmendmentsToPreviouslyFiledReport',
                ]
            );
            $harness->assertTrue((bool)$comparison['passed']);
            $harness->assertTrue((bool)$comparison['facts_unchanged_except_allowlist']);
            $harness->assertTrue((bool)$comparison['contexts_unchanged']);
            $harness->assertTrue((bool)$comparison['units_unchanged']);
            $harness->assertTrue((bool)$comparison['other_visible_text_unchanged']);
        });

        $harness->check($service::class, 'fails rather than emit conflicting revision and board approval dates', static function () use ($harness, $service): void {
            $source = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n"
                . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ix="http://www.xbrl.org/2013/inlineXBRL" xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:core="http://xbrl.frc.org.uk/fr/2026-01-01/core" lang="en" xml:lang="en"><head><title>Accounts</title></head><body>'
                . '<div><ix:header><ix:hidden></ix:hidden><ix:resources><xbrli:context id="current_period_duration"/><xbrli:context id="current_period_end"/></ix:resources></ix:header></div>'
                . '<div class="accountspage titlepage"><h2>MICRO-ENTITY ACCOUNTS</h2></div>'
                . '<p><ix:nonNumeric name="core:DateAuthorisationFinancialStatementsForIssue" contextRef="current_period_end">2026-07-20</ix:nonNumeric></p>'
                . '</body></html>';
            $result = $service->transform($source, [
                'replaces_statement' => 'Replacement.',
                'statutory_accounts_statement' => 'Statutory.',
                'prepared_as_statement' => 'Date basis.',
                'non_compliance_explanation' => 'Original error.',
                'significant_amendments' => 'Corrections made.',
                'original_approval_date' => '2025-05-29',
                'revision_approval_date' => '2026-07-21',
            ]);
            $harness->assertFalse((bool)($result['success'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)$result['errors']), 'must match'));
        });

        $harness->check($service::class, 'builds the complete statutory date-basis wording', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'declarations');
            $method->setAccessible(true);
            $declarations = (array)$method->invoke($service, '2025-12-31', [
                'non_compliance_explanation' => 'The original omitted assets.',
                'significant_amendments' => 'Assets and reserves were corrected.',
                'original_approval_date' => '2025-05-29',
                'revision_approval_date' => '2026-07-21',
            ]);
            $wording = (string)$declarations['prepared_as_statement'];
            $harness->assertTrue(str_contains($wording, 'prepared as at 29 May 2025'));
            $harness->assertTrue(str_contains($wording, 'not as at 21 July 2026'));
            $harness->assertTrue(str_contains($wording, 'do not deal with events occurring between'));
        });
    }
);
