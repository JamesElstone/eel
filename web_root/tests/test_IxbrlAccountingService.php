<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAccountingService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlAccountingService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'refuses generation when no fact run exists', static function () use ($harness, $service): void {
            $result = $service->generatePreview(0, 0);
            $harness->assertSame(false, $result['success']);
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'renders the FRC 2026 Inline XBRL profile with valid contexts units and signs', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $facts = ixbrlRenderFixtureFacts();
            foreach ($facts as &$fact) {
                if ((string)$fact['fact_key'] === 'other_charges') {
                    $fact['source_json'] = json_encode([
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                        'source_rows' => [[
                            'label' => 'Labour services',
                            'amount' => 1000.0,
                        ]],
                    ]);
                }
            }
            unset($fact);
            $xhtml = (string)$method->invoke(
                $service,
                $facts,
                false,
                'EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF'
            );

            $harness->assertTrue(str_starts_with(
                $xhtml,
                '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            ));
            $harness->assertFalse(str_contains($xhtml, '<!DOCTYPE'));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<div class="ixbrl-header" style="display:none"><ix:header>'
            ));
            $harness->assertTrue(str_contains($xhtml, 'FRS-102/2026-01-01/FRS-102-2026-01-01.xsd'));
            $harness->assertTrue(str_contains($xhtml, 'xmlns:core="http://xbrl.frc.org.uk/fr/2026-01-01/core"'));
            $harness->assertTrue(str_contains($xhtml, 'xmlns:countries="http://xbrl.frc.org.uk/cd/2026-01-01/countries"'));
            $harness->assertTrue(str_contains($xhtml, '<xbrli:unit id="pure"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonFraction name="core:FixedAssets"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonFraction name="core:GrossProfitLoss"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonFraction name="core:OperatingProfitLoss"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime"'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="core:MaturitiesOrExpirationPeriodsDimension">core:WithinOneYear'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:EntityOfficersDimension">bus:Director1'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:AccountingStandardsDimension">bus:Micro-entities'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:AccountsStatusDimension">bus:AuditExempt-NoAccountantsReport'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:AccountsTypeDimension">bus:FullAccounts'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="countries:CountriesRegionsDimension">countries:EnglandWales'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:EntityContactTypeDimension">bus:RegisteredOffice'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="core:DirectorSigningFinancialStatements"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="bus:EntityTradingStatus" contextRef="current_period_duration"></ix:nonNumeric>'));
            $harness->assertTrue(str_contains($xhtml, 'name="core:ProfitLoss" contextRef="current_period_duration" unitRef="GBP" decimals="2" format="ixt:numdotdecimal" sign="-">127.11'));
            $harness->assertTrue(str_contains($xhtml, '</ix:nonFraction>)'));
            $harness->assertTrue(str_contains($xhtml, 'name="core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal"'));
            $harness->assertTrue(str_contains($xhtml, 'name="core:DateAuthorisationFinancialStatementsForIssue" contextRef="current_period_end"'));
            $harness->assertTrue(str_contains($xhtml, 'format="ixt:datedaymonthyearen">31 December 2025'));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<ix:nonNumeric name="bus:EntityCurrentLegalOrRegisteredName"'
                . ' contextRef="current_period_duration">Example Limited</ix:nonNumeric>'
                . ' is a private company limited by shares'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'incorporated and registered in England and Wales'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'Registered office: <ix:nonNumeric name="bus:AddressLine1"'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'cover the period from <ix:nonNumeric name="bus:StartDateForPeriodCoveredByReport"'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'presented in pounds sterling (GBP) to the nearest penny.'
            ));
            $harness->assertTrue(substr_count($xhtml, 'class="accountspage') >= 4);
            $harness->assertTrue(str_contains(
                $xhtml,
                '<div class="evidence-footer">Evidence ID: EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF</div>'
            ));
            $harness->assertFalse(str_contains($xhtml, '<section'));
            $harness->assertFalse(str_contains($xhtml, ' lang="en"'));
            $harness->assertTrue(str_contains($xhtml, ' xml:lang="en"'));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<table class="note-table director-loan-table"><colgroup><col class="description-column"/>'
                . '<col class="amount-column"/></colgroup>'
            ));

            $document = new DOMDocument();
            $harness->assertTrue($document->loadXML($xhtml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
            $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            foreach ([
                'bus:EntityCurrentLegalOrRegisteredName' => 5,
                'bus:UKCompaniesHouseRegisteredNumber' => 5,
                'bus:CountryFormationOrIncorporation' => 1,
                'bus:LegalFormEntity' => 1,
                'bus:AddressLine1' => 1,
                'bus:AddressLine2' => 1,
                'bus:AddressLine3' => 1,
                'bus:PostalCodeZip' => 1,
                'bus:StartDateForPeriodCoveredByReport' => 1,
                'bus:EndDateForPeriodCoveredByReport' => 7,
            ] as $concept => $expectedOccurrences) {
                $harness->assertSame(
                    0,
                    $xpath->query('//ix:hidden//*[@name="' . $concept . '"]')->length
                );
                $harness->assertSame(
                    $expectedOccurrences,
                    $xpath->query('//*[@name="' . $concept . '"]')->length
                );
            }
            $harness->assertSame(3, $xpath->query(
                '//xhtml:table[contains(@class, "page-header")]'
                . '//ix:nonNumeric[@name="bus:EntityCurrentLegalOrRegisteredName"]'
            )->length);
            $harness->assertSame(3, $xpath->query(
                '//xhtml:table[contains(@class, "page-header")]'
                . '//ix:nonNumeric[@name="bus:UKCompaniesHouseRegisteredNumber"]'
            )->length);
            $harness->assertSame(3, $xpath->query(
                '//xhtml:table[contains(@class, "page-header")]'
                . '//ix:nonNumeric[@name="bus:EndDateForPeriodCoveredByReport"]'
            )->length);
            $headers = $xpath->query('//xhtml:table[contains(@class, "page-header")]');
            $harness->assertSame(3, $headers->length);
            $harness->assertSame(0, $xpath->query('//xhtml:table[@role]')->length);
            foreach ($headers as $header) {
                $harness->assertSame(2, $xpath->query('./xhtml:tbody/xhtml:tr', $header)->length);
                $harness->assertSame(2, $xpath->query('./xhtml:tbody/xhtml:tr[1]/xhtml:td', $header)->length);
                $harness->assertSame(
                    '2',
                    (string)$xpath->query('./xhtml:tbody/xhtml:tr[2]/xhtml:td', $header)->item(0)?->attributes?->getNamedItem('colspan')?->nodeValue
                );
            }
            $profitAndLossLabels = [];
            foreach ($xpath->query(
                '//xhtml:h2[normalize-space(.)="Profit and loss account"]'
                . '/following-sibling::xhtml:table[contains(@class, "financial-table")]'
                . '/xhtml:tbody/xhtml:tr/xhtml:th'
            ) as $label) {
                $profitAndLossLabels[] = trim((string)$label->textContent);
            }
            $harness->assertSame([
                'Turnover',
                'Other income',
                'Raw materials and consumables',
                'Gross profit / (loss)',
                'Staff costs',
                'Depreciation and other amounts written off assets',
                'Other charges',
                'Operating profit / (loss)',
                'Tax on profit / (loss)',
                'Profit / (loss) for the financial year',
            ], $profitAndLossLabels);
            $harness->assertFalse(str_contains($xhtml, 'Management gross profit / (loss)'));
            $harness->assertTrue(str_contains($xhtml, 'For the period ended'));
            $harness->assertFalse(str_contains($xhtml, '>for the period ended'));
            $harness->assertSame(1, $xpath->query(
                '//ix:nonFraction[@name="direp:AdvancesCreditsMadeInPeriodDirectors"'
                . ' and @format="ixt:zerodash"]'
            )->length);
            $style = (string)$xpath->query('/xhtml:html/xhtml:head/xhtml:style')->item(0)?->textContent;
            $printStart = strpos($style, '@media print {');
            $harness->assertTrue($printStart !== false);
            $printCss = substr($style, (int)$printStart);
            $harness->assertTrue(str_contains($style, 'size: A4 portrait;'));
            $harness->assertTrue(str_contains($style, 'margin: 12mm 2cm 14mm;'));
            $harness->assertTrue(str_contains($style, '.page-header {'));
            $harness->assertTrue(str_contains($style, 'table-layout: fixed;'));
            $harness->assertTrue(str_contains($style, '.page-header-number-column { width: 36%; }'));
            $harness->assertFalse(str_contains($style, 'calc(3 * 12%)'));
            $harness->assertFalse(str_contains($style, 'display: grid;'));
            $harness->assertFalse(str_contains($style, 'grid-template-columns:'));
            $harness->assertFalse(preg_match(
                '/\.accountspage\s*\{[^}]*\bwidth\s*:\s*210mm/is',
                $printCss
            ) === 1);
            $harness->assertFalse(preg_match(
                '/\.accountspage\s*\{[^}]*\bmin-height\s*:\s*297mm/is',
                $printCss
            ) === 1);
            $harness->assertTrue(preg_match(
                '/\.accountspage\s*\{[^}]*\bwidth\s*:\s*auto/is',
                $printCss
            ) === 1);
            $harness->assertTrue(preg_match(
                '/\.accountspage\s*\{[^}]*\bmin-height\s*:\s*0/is',
                $printCss
            ) === 1);
            $harness->assertTrue(preg_match(
                '/\.accountspage\s*\{[^}]*\bpadding\s*:\s*0/is',
                $printCss
            ) === 1);
            $harness->assertSame(1, substr_count($printCss, 'break-before: page;'));
            $harness->assertSame(1, substr_count($printCss, 'page-break-before: always;'));
            $harness->assertFalse(str_contains($printCss, 'break-after:'));
            $harness->assertFalse(str_contains($printCss, 'page-break-after:'));
            $harness->assertTrue(str_contains(
                $printCss,
                '.accountspage + .accountspage'
            ));
            $harness->assertTrue(str_contains(
                $printCss,
                '.financial-table, .note-table,'
            ));
            $harness->assertTrue(str_contains($printCss, 'max-width: 100%;'));
            $harness->assertTrue(str_contains($printCss, 'overflow: visible;'));

            $pages = $xpath->query(
                '//xhtml:body/xhtml:div[contains(concat(" ", normalize-space(@class), " "), " accountspage ")]'
            );
            $harness->assertSame(4, $pages->length);
            $seenPages = [];
            foreach ($pages as $index => $page) {
                $harness->assertTrue($page instanceof DOMElement);
                $visible = preg_replace('/\s+/u', ' ', trim($page->textContent)) ?? '';
                $harness->assertTrue($visible !== '');
                $canonical = $page->C14N(true, false);
                $harness->assertTrue(is_string($canonical) && $canonical !== '');
                $harness->assertFalse(isset($seenPages[$canonical]));
                $seenPages[$canonical] = true;
                if ($index === 0) {
                    $harness->assertFalse(str_contains(
                        ' ' . $page->getAttribute('class') . ' ',
                        ' pagebreak '
                    ));
                }
            }

            $validator = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml));
            $inconsistentDuplicate = str_replace(
                '</body>',
                '<p><ix:nonNumeric name="bus:EntityCurrentLegalOrRegisteredName" contextRef="current_period_duration">Other Limited</ix:nonNumeric></p></body>',
                $xhtml
            );
            $harness->assertTrue(in_array(
                'Inconsistent duplicate Inline XBRL fact was generated: bus:EntityCurrentLegalOrRegisteredName.',
                $validator->invoke($service, $inconsistentDuplicate),
                true
            ));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'omits only negative equity facts while preserving visible amounts and net assets', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            $currentAmounts = [
                'creditors_within_one_year' => 5667.80,
                'net_current_assets_liabilities' => -5167.80,
                'total_assets_less_current_liabilities' => -4167.80,
                'net_assets_liabilities' => -4567.80,
                'equity' => -4567.80,
            ];
            foreach ($facts as &$fact) {
                $key = (string)$fact['fact_key'];
                if (array_key_exists($key, $currentAmounts)) {
                    $fact['numeric_value'] = $currentAmounts[$key];
                }
            }
            unset($fact);

            $comparativeKeys = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                if (!empty($mapping['comparative_enabled'])) {
                    $comparativeKeys[(string)$mapping['fact_key']] = true;
                }
            }
            $comparativeAmounts = [
                'creditors_within_one_year' => 50.0,
                'net_current_assets_liabilities' => 450.0,
                'total_assets_less_current_liabilities' => 1450.0,
                'net_assets_liabilities' => 1050.0,
                'equity' => 1050.0,
            ];
            foreach (array_values($facts) as $fact) {
                $key = (string)$fact['fact_key'];
                if (!isset($comparativeKeys[$key])) {
                    continue;
                }
                $comparative = $fact;
                $comparative['context_ref'] = str_replace(
                    'current_',
                    'comparative_',
                    (string)$fact['context_ref']
                );
                $comparative['source_json'] = json_encode([
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                ]);
                if (array_key_exists($key, $comparativeAmounts)) {
                    $comparative['numeric_value'] = $comparativeAmounts[$key];
                }
                $facts[] = $comparative;
            }

            $render = new ReflectionMethod($service, 'renderXhtml');
            $render->setAccessible(true);
            $xhtml = (string)$render->invoke($service, $facts, true);
            $document = new DOMDocument();
            $harness->assertTrue($document->loadXML($xhtml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
            $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            $equityRow = $xpath->query(
                '//xhtml:tr[xhtml:th[normalize-space(.)="Capital and reserves"]]'
            )->item(0);
            $harness->assertTrue($equityRow instanceof DOMElement);
            $harness->assertSame('(4,567.80)', trim((string)$xpath->query('./xhtml:td[1]', $equityRow)->item(0)?->textContent));
            $harness->assertSame(0, $xpath->query(
                './/ix:nonFraction[@name="core:Equity" and @contextRef="current_period_end"]',
                $equityRow
            )->length);
            $harness->assertSame(1, $xpath->query(
                './/ix:nonFraction[@name="core:Equity" and @contextRef="comparative_period_end"]',
                $equityRow
            )->length);
            $harness->assertSame(1, $xpath->query(
                '//ix:nonFraction[@name="core:NetAssetsLiabilities"'
                . ' and @contextRef="current_period_end" and @sign="-"]'
            )->length);

            $validator = new ReflectionMethod($service, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml, $facts));

            $invalidNegativeEquity = str_replace(
                '</ix:hidden>',
                '<ix:nonFraction name="core:Equity" contextRef="current_period_end" unitRef="GBP"'
                . ' decimals="2" format="ixt:numdotdecimal" sign="-">4567.80</ix:nonFraction></ix:hidden>',
                $xhtml
            );
            $harness->assertTrue(in_array(
                'A negative core:Equity fact must not be emitted because it fails HMRC.5.3.',
                $validator->invoke($service, $invalidNegativeEquity, $facts),
                true
            ));

            $withoutNetAssets = preg_replace(
                '/<ix:nonFraction name="core:NetAssetsLiabilities" contextRef="current_period_end"[^>]*>[^<]*<\\/ix:nonFraction>/',
                '',
                $xhtml
            );
            $harness->assertTrue(is_string($withoutNetAssets));
            $harness->assertTrue(in_array(
                'Omitted negative core:Equity requires a matching core:NetAssetsLiabilities fact for context current_period_end.',
                $validator->invoke($service, $withoutNetAssets, $facts),
                true
            ));

            $warningMethod = new ReflectionMethod($service, 'negativeEquityOmissionWarnings');
            $warningMethod->setAccessible(true);
            $warnings = (array)$warningMethod->invoke($service, $facts);
            $harness->assertSame(1, count($warnings));
            $harness->assertTrue(str_starts_with(
                (string)$warnings[0],
                'IXBRL-HMRC-NEGATIVE-EQUITY:'
            ));

            foreach ($facts as &$fact) {
                $key = (string)$fact['fact_key'];
                $context = (string)$fact['context_ref'];
                if (str_starts_with($context, 'current_') && array_key_exists($key, $comparativeAmounts)) {
                    $fact['numeric_value'] = $comparativeAmounts[$key];
                } elseif (str_starts_with($context, 'comparative_') && array_key_exists($key, $currentAmounts)) {
                    $fact['numeric_value'] = $currentAmounts[$key];
                }
            }
            unset($fact);
            $inverseXhtml = (string)$render->invoke($service, $facts, true);
            $inverseDocument = new DOMDocument();
            $harness->assertTrue($inverseDocument->loadXML($inverseXhtml, LIBXML_NONET));
            $inverseXpath = new DOMXPath($inverseDocument);
            $inverseXpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            $harness->assertSame(1, $inverseXpath->query(
                '//ix:nonFraction[@name="core:Equity" and @contextRef="current_period_end"]'
            )->length);
            $harness->assertSame(0, $inverseXpath->query(
                '//ix:nonFraction[@name="core:Equity" and @contextRef="comparative_period_end"]'
            )->length);
            $harness->assertSame([], $validator->invoke($service, $inverseXhtml, $facts));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'keeps zero equity tagged and detects a missing non-negative equity fact', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            $amounts = [
                'creditors_within_one_year' => 1100.0,
                'net_current_assets_liabilities' => -600.0,
                'total_assets_less_current_liabilities' => 400.0,
                'net_assets_liabilities' => 0.0,
                'equity' => 0.0,
            ];
            foreach ($facts as &$fact) {
                $key = (string)$fact['fact_key'];
                if (array_key_exists($key, $amounts)) {
                    $fact['numeric_value'] = $amounts[$key];
                }
            }
            unset($fact);
            $render = new ReflectionMethod($service, 'renderXhtml');
            $render->setAccessible(true);
            $xhtml = (string)$render->invoke($service, $facts);
            $harness->assertTrue(str_contains(
                $xhtml,
                'name="core:Equity" contextRef="current_period_end" unitRef="GBP" decimals="2" format="ixt:zerodash">-</ix:nonFraction>'
            ));
            $validator = new ReflectionMethod($service, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml, $facts));
            $withoutEquity = preg_replace(
                '/<ix:nonFraction name="core:Equity" contextRef="current_period_end"[^>]*>[^<]*<\\/ix:nonFraction>/',
                '',
                $xhtml
            );
            $harness->assertTrue(is_string($withoutEquity));
            $harness->assertTrue(in_array(
                'Non-negative core:Equity is missing or mismatched for context current_period_end.',
                $validator->invoke($service, $withoutEquity, $facts),
                true
            ));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'renders an untagged AP80 gross-profit bridge from subcontractor provenance', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            foreach ($facts as &$fact) {
                $amounts = [
                    'turnover' => 27972.34,
                    'raw_materials_consumables' => 17957.69,
                    'gross_profit_loss' => 10014.65,
                    'depreciation_write_offs' => 0.0,
                    'other_charges' => 141.0,
                    'operating_profit_loss' => 9873.65,
                    'profit_loss' => 9873.65,
                ];
                $key = (string)$fact['fact_key'];
                if (array_key_exists($key, $amounts)) {
                    $fact['numeric_value'] = $amounts[$key];
                }
                if ($key === 'other_charges') {
                    $fact['source_json'] = json_encode([
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                        'source_rows' => [[
                            'label' => 'Electrical subcontractors',
                            'amount' => 141.0,
                        ]],
                    ]);
                }
            }
            unset($fact);

            $render = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $render->setAccessible(true);
            $xhtml = (string)$render->invoke($service, $facts);
            $harness->assertTrue(str_contains(
                $xhtml,
                'The statutory gross profit / (loss) subtotal is turnover less raw materials and consumables. '
                . 'Subcontractor labour is included within other charges.'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<table class="financial-table gross-profit-bridge-table"><colgroup><col class="description-column"/>'
                . '<col class="amount-column"/></colgroup>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<tr><th class="description" scope="row">Statutory gross profit / (loss)</th>'
                . '<td class="amount">10,014.65</td></tr>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<tr><th class="description" scope="row">Less: subcontractor labour included in other charges</th>'
                . '<td class="amount">(141.00)</td></tr>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<tr class="subtotal"><th class="description" scope="row">Management gross profit / (loss)</th>'
                . '<td class="amount">9,873.65</td></tr>'
            ));
            $harness->assertSame(1, substr_count($xhtml, 'name="core:GrossProfitLoss"'));
            $validator = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'rejects a required fact that exists only in a comparative context', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            foreach ($facts as &$fact) {
                if ((string)$fact['fact_key'] === 'accounts_status') {
                    $fact['context_ref'] = 'comparative_period_duration_accounts_status';
                }
            }
            unset($fact);
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $thrown = false;
            try {
                $method->invoke($service, $facts);
            } catch (Throwable $exception) {
                $thrown = str_contains($exception->getMessage(), 'accounts_status');
            }
            $harness->assertTrue($thrown);
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'renders the evidence identifier in the final accounts page footer', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $left = (string)$method->invoke($service, ixbrlRenderFixtureFacts(), false, 'EEL-AR-FIRST');
            $right = (string)$method->invoke($service, ixbrlRenderFixtureFacts(), false, 'EEL-AR-SECOND');
            $harness->assertFalse($left === $right);
            $harness->assertTrue(str_contains($left, 'Evidence ID: EEL-AR-FIRST'));
            $harness->assertTrue(str_contains($right, 'Evidence ID: EEL-AR-SECOND'));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'binds the selected director name to the zero-length signing marker and Director1 context', static function () use ($harness, $service): void {
            $render = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $render->setAccessible(true);
            $xhtml = (string)$render->invoke($service, ixbrlRenderFixtureFacts());
            $document = new DOMDocument();
            $harness->assertTrue($document->loadXML($xhtml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
            $xpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');

            $markers = $xpath->query('//ix:nonNumeric[@name="core:DirectorSigningFinancialStatements"]');
            $names = $xpath->query('//ix:nonNumeric[@name="bus:NameEntityOfficer"]');
            $harness->assertSame(1, $markers->length);
            $harness->assertSame(1, $names->length);
            $marker = $markers->item(0);
            $name = $names->item(0);
            $harness->assertTrue($marker instanceof DOMElement);
            $harness->assertTrue($name instanceof DOMElement);
            $harness->assertSame('', $marker->textContent);
            $harness->assertSame('Example Director', trim($name->textContent));
            $harness->assertSame($marker->getAttribute('contextRef'), $name->getAttribute('contextRef'));
            $members = $xpath->query(
                '//xbrli:context[@id="' . $marker->getAttribute('contextRef') . '"]'
                . '//xbrldi:explicitMember[@dimension="bus:EntityOfficersDimension"]'
            );
            $harness->assertSame(1, $members->length);
            $harness->assertSame('bus:Director1', trim((string)$members->item(0)?->textContent));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'rejects altered director signing markers contexts and members', static function () use ($harness, $service): void {
            $render = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $render->setAccessible(true);
            $xhtml = (string)$render->invoke($service, ixbrlRenderFixtureFacts());
            $validator = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'validateInlineXbrl');
            $validator->setAccessible(true);

            $marker = '<ix:nonNumeric name="core:DirectorSigningFinancialStatements" contextRef="current_period_duration_director_1"></ix:nonNumeric>';
            $nonEmptyMarker = str_replace(
                $marker,
                '<ix:nonNumeric name="core:DirectorSigningFinancialStatements" contextRef="current_period_duration_director_1">Example Director</ix:nonNumeric>',
                $xhtml
            );
            $harness->assertTrue($nonEmptyMarker !== $xhtml);
            $harness->assertTrue(in_array(
                'DirectorSigningFinancialStatements must be a zero-length taxonomy marker.',
                $validator->invoke($service, $nonEmptyMarker),
                true
            ));

            $name = '<ix:nonNumeric name="bus:NameEntityOfficer" contextRef="current_period_duration_director_1">Example Director</ix:nonNumeric>';
            $blankName = str_replace(
                $name,
                '<ix:nonNumeric name="bus:NameEntityOfficer" contextRef="current_period_duration_director_1"></ix:nonNumeric>',
                $xhtml
            );
            $harness->assertTrue($blankName !== $xhtml);
            $harness->assertTrue(in_array(
                'NameEntityOfficer must contain the approving director name.',
                $validator->invoke($service, $blankName),
                true
            ));

            $mismatchedContext = str_replace(
                'name="bus:NameEntityOfficer" contextRef="current_period_duration_director_1"',
                'name="bus:NameEntityOfficer" contextRef="current_period_duration"',
                $xhtml
            );
            $harness->assertTrue($mismatchedContext !== $xhtml);
            $harness->assertTrue(in_array(
                'DirectorSigningFinancialStatements and NameEntityOfficer must use the same director context.',
                $validator->invoke($service, $mismatchedContext),
                true
            ));

            $wrongMember = str_replace(
                'dimension="bus:EntityOfficersDimension">bus:Director1',
                'dimension="bus:EntityOfficersDimension">bus:Director2',
                $xhtml
            );
            $harness->assertTrue($wrongMember !== $xhtml);
            $harness->assertTrue(in_array(
                'The approving-director context must identify bus:Director1 through bus:EntityOfficersDimension.',
                $validator->invoke($service, $wrongMember),
                true
            ));

            $duplicateMarker = str_replace($marker, $marker . $marker, $xhtml);
            $harness->assertTrue($duplicateMarker !== $xhtml);
            $harness->assertTrue(in_array(
                'Exactly one DirectorSigningFinancialStatements marker fact is required.',
                $validator->invoke($service, $duplicateMarker),
                true
            ));

            $duplicateName = str_replace($name, $name . $name, $xhtml);
            $harness->assertTrue($duplicateName !== $xhtml);
            $harness->assertTrue(in_array(
                'Exactly one NameEntityOfficer fact is required for the approving director.',
                $validator->invoke($service, $duplicateName),
                true
            ));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'keeps visible accounting signs dates and separators consistent with machine facts', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $xhtml = (string)$method->invoke($service, ixbrlRenderFixtureFacts());
            $document = new DOMDocument();
            $harness->assertTrue($document->loadXML($xhtml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');

            $loss = $xpath->query('//ix:nonFraction[@name="core:ProfitLoss"]')->item(0);
            $harness->assertTrue($loss instanceof DOMElement);
            $harness->assertSame('-', $loss->getAttribute('sign'));
            $harness->assertSame('127.11', trim($loss->textContent));
            $harness->assertTrue(str_starts_with(trim((string)$loss->parentNode?->textContent), '('));

            $creditor = $xpath->query(
                '//ix:nonFraction[@name="core:Creditors" and @contextRef="current_period_end_creditors_within_one_year"]'
            )->item(0);
            $harness->assertTrue($creditor instanceof DOMElement);
            $harness->assertSame('', $creditor->getAttribute('sign'));
            $harness->assertSame('50.00', trim($creditor->textContent));
            $harness->assertTrue(str_starts_with(trim((string)$creditor->parentNode?->textContent), '('));

            $turnover = $xpath->query('//ix:nonFraction[@name="core:TurnoverRevenue"]')->item(0);
            $harness->assertSame('1,000.00', trim((string)$turnover?->textContent));
            $approval = $xpath->query(
                '//ix:nonNumeric[@name="core:DateAuthorisationFinancialStatementsForIssue"]'
            )->item(0);
            $harness->assertSame('ixt:datedaymonthyearen', $approval?->getAttribute('format'));
            $harness->assertSame('1 March 2026', trim((string)$approval?->textContent));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'keeps separately presented prepayments outside current assets and inside net current assets', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $xhtml = (string)$method->invoke($service, ixbrlRenderFixtureFacts());
            $document = new DOMDocument();
            $harness->assertTrue($document->loadXML($xhtml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
            $value = static function (DOMXPath $xpath, string $concept): float {
                $fact = $xpath->query('//ix:nonFraction[@name="' . $concept . '"]')->item(0);
                if (!$fact instanceof DOMElement) {
                    return 0.0;
                }
                $amount = (float)str_replace(',', '', trim($fact->textContent));
                return $fact->getAttribute('sign') === '-' ? -$amount : $amount;
            };
            $currentAssets = $value($xpath, 'core:CurrentAssets');
            $prepayment = $value(
                $xpath,
                'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal'
            );
            $creditors = $value($xpath, 'core:Creditors');
            $netCurrent = $value($xpath, 'core:NetCurrentAssetsLiabilities');
            $harness->assertSame(475.0, $currentAssets);
            $harness->assertSame(25.0, $prepayment);
            $harness->assertSame(450.0, $currentAssets + $prepayment - $creditors);
            $harness->assertSame(450.0, $netCurrent);
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'renders principal activity and renumbers the existing disclosure notes', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            $comparativeKeys = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                if (!empty($mapping['comparative_enabled'])) {
                    $comparativeKeys[(string)$mapping['fact_key']] = true;
                }
            }
            foreach ($facts as $fact) {
                if (!isset($comparativeKeys[(string)$fact['fact_key']])) {
                    continue;
                }
                $comparative = $fact;
                $comparative['context_ref'] = str_replace('current_', 'comparative_', (string)$fact['context_ref']);
                $comparative['source_json'] = json_encode(['period_start' => '2024-01-01', 'period_end' => '2024-12-31']);
                if ((string)$fact['fact_key'] === 'other_charges') {
                    $comparative['source_json'] = json_encode([
                        'period_start' => '2024-01-01',
                        'period_end' => '2024-12-31',
                        'source_rows' => [[
                            'label' => 'Electrical subcontractors',
                            'amount' => 50.0,
                        ]],
                    ]);
                }
                if ((string)$fact['fact_key'] === 'average_number_employees') {
                    $comparative['numeric_value'] = 3.0;
                }
                if (in_array((string)$fact['fact_key'], [
                    'director_advances_made',
                    'director_cash_repayments',
                ], true)) {
                    $comparative['numeric_value'] = 253.0;
                }
                $facts[] = $comparative;
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $xhtml = (string)$method->invoke($service, $facts);
            $harness->assertTrue(str_contains(
                $xhtml,
                '<span class="note-number">1.</span> Principal activity'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'name="bus:DescriptionPrincipalActivities" contextRef="current_period_duration"'
                . '>The principal activity of the company during the period was Electrical installation.</ix:nonNumeric>'
            ));
            $harness->assertSame(1, substr_count($xhtml, 'name="bus:DescriptionPrincipalActivities"'));
            foreach ([
                '<span class="note-number">2.</span> Employees',
                '<span class="note-number">3.</span> Advances and credits to directors',
                '<span class="note-number">4.</span> Off-balance-sheet arrangements',
                '<span class="note-number">5.</span> Financial commitments',
                '<span class="note-number">6.</span> Contingent liabilities',
            ] as $noteHeading) {
                $harness->assertTrue(str_contains($xhtml, $noteHeading));
            }
            $harness->assertTrue(str_contains($xhtml, 'name="core:AverageNumberEmployeesDuringPeriod" contextRef="comparative_period_duration"'));
            $harness->assertTrue(str_contains($xhtml, '(comparative period:'));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<table class="note-table director-loan-table"><colgroup><col class="description-column"/>'
                . '<col class="amount-column"/><col class="amount-column"/></colgroup>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<th class="amount" scope="col">2025<br/><span>£</span></th>'
                . '<th class="amount" scope="col">2024<br/><span>£</span></th>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<table class="financial-table gross-profit-bridge-table"><colgroup><col class="description-column"/>'
                . '<col class="amount-column"/><col class="amount-column"/></colgroup>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<tr><th class="description" scope="row">Less: subcontractor labour included in other charges</th>'
                . '<td class="amount">–</td><td class="amount">(50.00)</td></tr>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                '<tr class="subtotal"><th class="description" scope="row">Management gross profit / (loss)</th>'
                . '<td class="amount">900.00</td><td class="amount">850.00</td></tr>'
            ));
            foreach ([
                'direp:AdvancesCreditsMadeInPeriodDirectors' => 'comparative_period_duration',
                'direp:AdvancesCreditsRepaidInPeriodDirectors' => 'comparative_period_duration',
                'direp:AdvancesCreditsDirectors' => 'comparative_period_end',
            ] as $concept => $contextRef) {
                $harness->assertTrue(str_contains(
                    $xhtml,
                    'name="' . $concept . '" contextRef="' . $contextRef . '"'
                ));
            }
            $harness->assertTrue(str_contains(
                $xhtml,
                'name="direp:AdvancesCreditsMadeInPeriodDirectors" contextRef="comparative_period_duration"'
                . ' unitRef="GBP" decimals="2" format="ixt:numdotdecimal">253.00</ix:nonFraction>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'name="direp:AdvancesCreditsRepaidInPeriodDirectors" contextRef="comparative_period_duration"'
                . ' unitRef="GBP" decimals="2" format="ixt:numdotdecimal">253.00</ix:nonFraction>'
            ));
            $harness->assertTrue(str_contains(
                $xhtml,
                'name="direp:AdvancesCreditsDirectors" contextRef="comparative_period_end"'
                . ' unitRef="GBP" decimals="2" format="ixt:zerodash">-</ix:nonFraction>'
            ));
            $validator = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml));
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'rejects a missing comparative fact when a prior locked period exists', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $method->setAccessible(true);
            $thrown = false;
            try {
                $method->invoke($service, ixbrlRenderFixtureFacts(), true);
            } catch (Throwable $exception) {
                $thrown = str_contains($exception->getMessage(), 'comparative-period')
                    && str_contains($exception->getMessage(), 'turnover');
            }
            $harness->assertTrue($thrown);
        });

        $harness->check(\eel_accounts\Service\IxbrlAccountingService::class, 'keeps the current director-advance narrative identical in HMRC and revised accounts', static function () use ($harness, $service): void {
            $narrative = (new \eel_accounts\Service\IxbrlTaxonomyProfileService())->directorLoanStatementText([
                'disclosures' => [[
                    'director_name' => 'Fixture Director',
                    'advances' => 120.00,
                    'cash_repayments' => 20.00,
                    'amounts_legally_set_off' => 0.00,
                    'amounts_written_off' => 0.00,
                    'amounts_waived' => 0.00,
                    'closing_company_to_director_balance' => 100.00,
                    'interest_rate' => '0%',
                    'main_terms' => 'Unsecured',
                    'repayment_conditions' => 'No fixed repayment date was agreed',
                ]],
            ]);
            $facts = ixbrlRenderFixtureFacts();
            foreach ($facts as &$fact) {
                if ((string)($fact['fact_key'] ?? '') === 'no_director_advances_or_credits') {
                    $fact['text_value'] = $narrative;
                }
            }
            unset($fact);
            $render = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
            $render->setAccessible(true);
            $hmrc = (string)$render->invoke($service, $facts, false);
            $revised = (new \eel_accounts\Service\IxbrlRevisedAccountsArtifactService())->transform($hmrc, [
                'replaces_statement' => 'These revised accounts replace the previously filed report.',
                'statutory_accounts_statement' => 'These are now the statutory accounts.',
                'prepared_as_statement' => 'Prepared by reference to the original accounts.',
                'non_compliance_explanation' => 'The original report contained an error.',
                'significant_amendments' => 'The comparative figures were corrected.',
                'original_approval_date' => '2025-05-29',
                'revision_approval_date' => '2026-03-01',
            ]);
            $harness->assertTrue((bool)($revised['success'] ?? false));
            $directorAdvanceFact = static function (string $xhtml): string {
                $document = new DOMDocument();
                if (!$document->loadXML($xhtml, LIBXML_NONET)) {
                    throw new RuntimeException('The generated accounts iXBRL is not well-formed XML.');
                }
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
                $facts = $xpath->query('//ix:nonNumeric[@name="direp:GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates"]');
                if (($facts?->length ?? 0) !== 1) {
                    throw new RuntimeException('The current director-advance narrative fact is missing or duplicated.');
                }
                return trim((string)$facts?->item(0)?->textContent);
            };
            $hmrcNarrative = $directorAdvanceFact($hmrc);
            $revisedNarrative = $directorAdvanceFact((string)$revised['xhtml']);
            $harness->assertSame($narrative, $hmrcNarrative);
            $harness->assertSame($hmrcNarrative, $revisedNarrative);
            $harness->assertSame(1, substr_count($hmrcNarrative, 'Interest rate: 0%.'));
            $harness->assertSame(1, substr_count($revisedNarrative, 'Interest rate: 0%.'));
        });
        $harness->check(
            \eel_accounts\Service\IxbrlAccountingService::class,
            'does not render a Companies House revision explanation in the HMRC accounting artifact',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod(\eel_accounts\Service\IxbrlAccountingService::class, 'renderXhtml');
                $method->setAccessible(true);
                $facts = ixbrlRenderFixtureFacts();
                $facts[] = ixbrlRenderFact(
                    'companies_house_revision_explanation',
                    'bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006',
                    'text',
                    null,
                    'The originally filed accounts omitted fixed assets.',
                    null,
                    null,
                    null,
                    'current_period_duration'
                );
                $xhtml = (string)$method->invoke($service, $facts, false, '');

                $harness->assertFalse(str_contains($xhtml, 'id="hmrc-revision-explanation"'));
                $harness->assertFalse(str_contains(
                    $xhtml,
                    'name="bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006"'
                ));
                $harness->assertFalse(str_contains($xhtml, 'The originally filed accounts omitted fixed assets.'));
            }
        );
    }
);

function ixbrlRenderFixtureFacts(): array
{
    return [
        ixbrlRenderFact('entity_name', 'bus:EntityCurrentLegalOrRegisteredName', 'text', null, 'Example Limited', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('company_number', 'bus:UKCompaniesHouseRegisteredNumber', 'text', null, '01234567', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('country_formation_or_incorporation', 'bus:CountryFormationOrIncorporation', 'text', null, '', null, null, null, 'current_period_duration_country_formation'),
        ixbrlRenderFact('legal_form_entity', 'bus:LegalFormEntity', 'text', null, '', null, null, null, 'current_period_duration_legal_form'),
        ixbrlRenderFact('registered_office_address_line_1', 'bus:AddressLine1', 'text', null, '1 Example Street', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('registered_office_address_line_2', 'bus:AddressLine2', 'text', null, 'Example Park', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('registered_office_address_line_3', 'bus:AddressLine3', 'text', null, 'London', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('registered_office_postal_code', 'bus:PostalCodeZip', 'text', null, 'SW1A 1AA', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('period_start', 'bus:StartDateForPeriodCoveredByReport', 'date', null, null, '2025-01-01', null, null, 'current_period_end'),
        ixbrlRenderFact('period_end', 'bus:EndDateForPeriodCoveredByReport', 'date', null, null, '2025-12-31', null, null, 'current_period_end'),
        ixbrlRenderFact('balance_sheet_date', 'bus:BalanceSheetDate', 'date', null, null, '2025-12-31', null, null, 'current_period_end'),
        ixbrlRenderFact('accounts_approval_date', 'core:DateAuthorisationFinancialStatementsForIssue', 'date', null, null, '2026-03-01', null, null, 'current_period_end'),
        ixbrlRenderFact('approving_director_name', 'bus:NameEntityOfficer', 'text', null, 'Example Director', null, null, null, 'current_period_duration_director_1'),
        ixbrlRenderFact('director_signing_financial_statements', 'core:DirectorSigningFinancialStatements', 'text', null, '', null, null, null, 'current_period_duration_director_1'),
        ixbrlRenderFact('entity_trading_status', 'bus:EntityTradingStatus', 'text', null, '', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('accounting_standards_applied', 'bus:AccountingStandardsApplied', 'text', null, '', null, null, null, 'current_period_duration_accounting_standards'),
        ixbrlRenderFact('accounts_status', 'bus:AccountsStatusAuditedOrUnaudited', 'text', null, '', null, null, null, 'current_period_duration_accounts_status'),
        ixbrlRenderFact('accounts_type', 'bus:AccountsType', 'text', null, '', null, null, null, 'current_period_duration_accounts_type'),
        ixbrlRenderFact('turnover', 'core:TurnoverRevenue', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('other_income', 'core:OtherOperatingIncomeFormat2', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('raw_materials_consumables', 'core:RawMaterialsConsumablesUsed', 'numeric', 100.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('gross_profit_loss', 'core:GrossProfitLoss', 'numeric', 900.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('staff_costs', 'core:StaffCostsEmployeeBenefitsExpense', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('depreciation_write_offs', 'core:DepreciationAmortisationImpairmentExpense', 'numeric', 27.11, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('other_charges', 'core:OtherExternalCharges', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('operating_profit_loss', 'core:OperatingProfitLoss', 'numeric', -127.11, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('tax_on_profit', 'core:TaxTaxCreditOnProfitOrLossOnOrdinaryActivities', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('profit_loss', 'core:ProfitLoss', 'numeric', -127.11, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('called_up_share_capital_not_paid', 'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('fixed_assets', 'core:FixedAssets', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('current_assets', 'core:CurrentAssets', 'numeric', 475.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('prepayments_accrued_income', 'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal', 'numeric', 25.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('creditors_within_one_year', 'core:Creditors', 'numeric', 50.0, null, null, 'GBP', '2', 'current_period_end_creditors_within_one_year'),
        ixbrlRenderFact('net_current_assets_liabilities', 'core:NetCurrentAssetsLiabilities', 'numeric', 450.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('total_assets_less_current_liabilities', 'core:TotalAssetsLessCurrentLiabilities', 'numeric', 1450.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('creditors_after_one_year', 'core:Creditors', 'numeric', 400.0, null, null, 'GBP', '2', 'current_period_end_creditors_after_one_year'),
        ixbrlRenderFact('provisions_for_liabilities', 'core:ProvisionsForLiabilitiesBalanceSheetSubtotal', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('accruals_deferred_income', 'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('net_assets_liabilities', 'core:NetAssetsLiabilities', 'numeric', 1050.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('equity', 'core:Equity', 'numeric', 1050.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('average_number_employees', 'core:AverageNumberEmployeesDuringPeriod', 'numeric', 1.0, null, null, 'pure', '0', 'current_period_duration'),
        ixbrlRenderFact('principal_activity_description', 'bus:DescriptionPrincipalActivities', 'text', null, 'The principal activity of the company during the period was Electrical installation.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('entity_dormant', 'bus:EntityDormantTruefalse', 'boolean', null, 'false', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('small_companies_regime_statement', 'direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime', 'text', null, 'Prepared under the small companies regime.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('audit_exemption_statement', 'direp:StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies', 'text', null, 'The company is entitled to audit exemption.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('directors_responsibility_statement', 'direp:StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct', 'text', null, 'The directors acknowledge their responsibilities.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('members_no_audit_statement', 'direp:StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit', 'text', null, 'The members have not required an audit.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_material_off_balance_sheet_arrangements', 'core:GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity', 'text', null, 'The company had no material off-balance sheet arrangements.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_director_advances_or_credits', 'direp:GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates', 'text', null, 'The company made no advances or credits (including loans) to directors during the period.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_director_guarantees', 'direp:GeneralDescriptionGuaranteesTheirTermsDirectors', 'text', null, 'The company entered into no guarantees on behalf of directors.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_capital_commitments', 'core:DescriptionCapitalCommitments', 'text', null, 'The company had no capital commitments.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_financial_commitments', 'core:DescriptionFinancialCommitmentsOtherThanCapitalCommitments', 'text', null, 'The company had no other financial commitments or guarantees.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_contingent_liabilities', 'core:GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement', 'text', null, 'The company had no contingent liabilities.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('director_advances_made', 'direp:AdvancesCreditsMadeInPeriodDirectors', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('director_cash_repayments', 'direp:AdvancesCreditsRepaidInPeriodDirectors', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('director_closing_advance', 'direp:AdvancesCreditsDirectors', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('production_software', 'bus:NameProductionSoftware', 'text', null, 'EEL Accounts', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('production_software_version', 'bus:VersionProductionSoftware', 'text', null, \eel_accounts\Service\IxbrlTaxonomyProfileService::BASIS_VERSION, null, null, null, 'current_period_duration'),
    ];
}

function ixbrlRenderFact(
    string $key,
    string $concept,
    string $type,
    ?float $numeric,
    ?string $text,
    ?string $date,
    ?string $unit,
    ?string $decimals,
    string $context
): array {
    return [
        'fact_key' => $key,
        'taxonomy_concept' => $concept,
        'label' => $key,
        'value_type' => $type,
        'numeric_value' => $numeric,
        'text_value' => $text,
        'date_value' => $date,
        'unit_ref' => $unit,
        'decimals_value' => $decimals,
        'context_ref' => $context,
        'source_json' => json_encode(['period_start' => '2025-01-01', 'period_end' => '2025-12-31']),
    ];
}
