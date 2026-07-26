<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAccountingService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlAccountingService $service
    ): void {
        $harness->check(
            $service::class,
            'accepts the FRC 2026 zero-length categorical markers and trading-status default',
            static function () use ($harness, $service): void {
                $harness->assertSame(
                    [],
                    ixbrlCategoricalValidationErrors($service, ixbrlCategoricalFixture())
                );
                foreach ([
                    'bus:EntityHasNeverTraded',
                    'bus:EntityNoLongerTradingButTradedInPast',
                ] as $member) {
                    $harness->assertSame(
                        [],
                        ixbrlCategoricalValidationErrors(
                            $service,
                            ixbrlCategoricalFixture($member)
                        )
                    );
                }
            }
        );

        $harness->check(
            $service::class,
            'rejects literal values in every FRC 2026 fixed-item categorical fact',
            static function () use ($harness, $service): void {
                $contexts = [
                    'bus:CountryFormationOrIncorporation' => 'country',
                    'bus:LegalFormEntity' => 'legal-form',
                    'bus:EntityTradingStatus' => 'trading-status',
                    'bus:AccountingStandardsApplied' => 'accounting-standards',
                    'bus:AccountsStatusAuditedOrUnaudited' => 'accounts-status',
                    'bus:AccountsType' => 'accounts-type',
                ];
                foreach ($contexts as $concept => $contextId) {
                    $empty = '<ix:nonNumeric name="' . $concept . '" contextRef="'
                        . $contextId . '"></ix:nonNumeric>';
                    $nonEmpty = '<ix:nonNumeric name="' . $concept . '" contextRef="'
                        . $contextId . '">text</ix:nonNumeric>';
                    $mutated = str_replace($empty, $nonEmpty, ixbrlCategoricalFixture());
                    $harness->assertTrue($mutated !== ixbrlCategoricalFixture());
                    $harness->assertTrue(in_array(
                        $concept . ' must be a zero-length taxonomy marker.',
                        ixbrlCategoricalValidationErrors($service, $mutated),
                        true
                    ));
                }
            }
        );

        $harness->check(
            $service::class,
            'rejects categorical facts attached to the wrong dimension members',
            static function () use ($harness, $service): void {
                $mutations = [
                    [
                        'countries:EnglandWales',
                        'countries:UnitedKingdom',
                        'bus:CountryFormationOrIncorporation',
                        'countries:CountriesRegionsDimension=countries:EnglandWales',
                    ],
                    [
                        'bus:PrivateLimitedCompanyLtd',
                        'bus:PublicLimitedCompanyPLC',
                        'bus:LegalFormEntity',
                        'bus:LegalFormEntityDimension=bus:PrivateLimitedCompanyLtd',
                    ],
                    [
                        'bus:Micro-entities',
                        'bus:FRS102',
                        'bus:AccountingStandardsApplied',
                        'bus:AccountingStandardsDimension=bus:Micro-entities',
                    ],
                    [
                        'bus:AuditExempt-NoAccountantsReport',
                        'bus:Audited',
                        'bus:AccountsStatusAuditedOrUnaudited',
                        'bus:AccountsStatusDimension=bus:AuditExempt-NoAccountantsReport',
                    ],
                    [
                        'bus:FullAccounts',
                        'bus:AbridgedAccounts',
                        'bus:AccountsType',
                        'bus:AccountsTypeDimension=bus:FullAccounts',
                    ],
                ];
                foreach ($mutations as [$from, $to, $concept, $profile]) {
                    $mutated = str_replace($from, $to, ixbrlCategoricalFixture());
                    $harness->assertTrue(in_array(
                        $concept . ' must use the taxonomy dimension profile ' . $profile . '.',
                        ixbrlCategoricalValidationErrors($service, $mutated),
                        true
                    ));
                }
            }
        );

        $harness->check(
            $service::class,
            'rejects an explicitly emitted trading default or unrelated trading context',
            static function () use ($harness, $service): void {
                $explicitDefault = ixbrlCategoricalFixture('bus:EntityTradingDefault');
                $errors = ixbrlCategoricalValidationErrors($service, $explicitDefault);
                $harness->assertTrue(in_array(
                    'bus:EntityTradingDefault is a taxonomy default and must not be emitted explicitly.',
                    $errors,
                    true
                ));
                $harness->assertTrue(in_array(
                    'bus:EntityTradingStatus must use the implicit trading default, '
                    . 'bus:EntityHasNeverTraded or bus:EntityNoLongerTradingButTradedInPast.',
                    $errors,
                    true
                ));

                $wrongContext = str_replace(
                    'name="bus:EntityTradingStatus" contextRef="trading-status"',
                    'name="bus:EntityTradingStatus" contextRef="accounts-type"',
                    ixbrlCategoricalFixture()
                );
                $harness->assertTrue(in_array(
                    'bus:EntityTradingStatus must use the implicit trading default, '
                    . 'bus:EntityHasNeverTraded or bus:EntityNoLongerTradingButTradedInPast.',
                    ixbrlCategoricalValidationErrors($service, $wrongContext),
                    true
                ));
            }
        );

        $harness->check(
            $service::class,
            'rejects instant categorical contexts and duplicate dimensions',
            static function () use ($harness, $service): void {
                $instant = ixbrlCategoricalContextAsInstant(
                    ixbrlCategoricalFixture(),
                    'country'
                );
                $harness->assertTrue(in_array(
                    'bus:CountryFormationOrIncorporation must use a duration context.',
                    ixbrlCategoricalValidationErrors($service, $instant),
                    true
                ));

                $duplicate = str_replace(
                    '<xbrldi:explicitMember dimension="countries:CountriesRegionsDimension">'
                    . 'countries:EnglandWales</xbrldi:explicitMember>',
                    '<xbrldi:explicitMember dimension="countries:CountriesRegionsDimension">'
                    . 'countries:EnglandWales</xbrldi:explicitMember>'
                    . '<xbrldi:explicitMember dimension="countries:CountriesRegionsDimension">'
                    . 'countries:EnglandWales</xbrldi:explicitMember>',
                    ixbrlCategoricalFixture()
                );
                $dimensionErrors = ixbrlContextDimensionValidationErrors($service, $duplicate);
                $harness->assertTrue(in_array(
                    'Context country contains duplicate dimension countries:CountriesRegionsDimension.',
                    $dimensionErrors,
                    true
                ));
            }
        );
    }
);

/**
 * @return list<string>
 */
function ixbrlCategoricalValidationErrors(
    \eel_accounts\Service\IxbrlAccountingService $service,
    string $xhtml
): array {
    [$xpath] = ixbrlCategoricalXPath($xhtml);
    $method = new ReflectionMethod($service, 'categoricalMarkerValidationErrors');
    $method->setAccessible(true);

    return (array)$method->invoke($service, $xpath);
}

/**
 * @return list<string>
 */
function ixbrlContextDimensionValidationErrors(
    \eel_accounts\Service\IxbrlAccountingService $service,
    string $xhtml
): array {
    [$xpath] = ixbrlCategoricalXPath($xhtml);
    $method = new ReflectionMethod($service, 'contextDimensionValidationErrors');
    $method->setAccessible(true);

    return (array)$method->invoke($service, $xpath);
}

/**
 * @return array{0: DOMXPath, 1: DOMDocument}
 */
function ixbrlCategoricalXPath(string $xhtml): array
{
    $document = new DOMDocument();
    if (!$document->loadXML($xhtml, LIBXML_NONET)) {
        throw new RuntimeException('The categorical-fact fixture is not valid XML.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
    $xpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');

    return [$xpath, $document];
}

function ixbrlCategoricalFixture(?string $tradingMember = null): string
{
    $tradingDimensions = $tradingMember === null
        ? []
        : ['bus:EntityTradingStatusDimension' => $tradingMember];
    $contexts = [
        ixbrlCategoricalContext('country', [
            'countries:CountriesRegionsDimension' => 'countries:EnglandWales',
        ]),
        ixbrlCategoricalContext('legal-form', [
            'bus:LegalFormEntityDimension' => 'bus:PrivateLimitedCompanyLtd',
        ]),
        ixbrlCategoricalContext('trading-status', $tradingDimensions),
        ixbrlCategoricalContext('accounting-standards', [
            'bus:AccountingStandardsDimension' => 'bus:Micro-entities',
        ]),
        ixbrlCategoricalContext('accounts-status', [
            'bus:AccountsStatusDimension' => 'bus:AuditExempt-NoAccountantsReport',
        ]),
        ixbrlCategoricalContext('accounts-type', [
            'bus:AccountsTypeDimension' => 'bus:FullAccounts',
        ]),
    ];

    return '<html xmlns="http://www.w3.org/1999/xhtml"'
        . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
        . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
        . ' xmlns:xbrldi="http://xbrl.org/2006/xbrldi"'
        . ' xmlns:bus="http://xbrl.frc.org.uk/cd/2026-01-01/business"'
        . ' xmlns:countries="http://xbrl.frc.org.uk/cd/2026-01-01/countries">'
        . '<body><div><ix:header><ix:hidden>'
        . '<ix:nonNumeric name="bus:CountryFormationOrIncorporation" contextRef="country"></ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:LegalFormEntity" contextRef="legal-form"></ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:EntityTradingStatus" contextRef="trading-status"></ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:AccountingStandardsApplied" contextRef="accounting-standards"></ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:AccountsStatusAuditedOrUnaudited" contextRef="accounts-status"></ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:AccountsType" contextRef="accounts-type"></ix:nonNumeric>'
        . '</ix:hidden><ix:resources>' . implode('', $contexts) . '</ix:resources>'
        . '</ix:header></div></body></html>';
}

/**
 * @param array<string, string> $dimensions
 */
function ixbrlCategoricalContext(string $id, array $dimensions): string
{
    $segment = '';
    if ($dimensions !== []) {
        $members = '';
        foreach ($dimensions as $dimension => $member) {
            $members .= '<xbrldi:explicitMember dimension="' . $dimension . '">'
                . $member . '</xbrldi:explicitMember>';
        }
        $segment = '<xbrli:segment>' . $members . '</xbrli:segment>';
    }

    return '<xbrli:context id="' . $id . '"><xbrli:entity>'
        . '<xbrli:identifier scheme="http://www.companieshouse.gov.uk/">14337285</xbrli:identifier>'
        . $segment . '</xbrli:entity><xbrli:period>'
        . '<xbrli:startDate>2022-09-05</xbrli:startDate>'
        . '<xbrli:endDate>2023-09-30</xbrli:endDate>'
        . '</xbrli:period></xbrli:context>';
}

function ixbrlCategoricalContextAsInstant(string $xhtml, string $contextId): string
{
    [$xpath, $document] = ixbrlCategoricalXPath($xhtml);
    $contexts = $xpath->query('//xbrli:context[@id="' . $contextId . '"]');
    $context = $contexts instanceof DOMNodeList ? $contexts->item(0) : null;
    if (!$context instanceof DOMElement) {
        throw new RuntimeException('The requested categorical test context is missing.');
    }
    $period = $xpath->query('./xbrli:period', $context)->item(0);
    if (!$period instanceof DOMElement) {
        throw new RuntimeException('The requested categorical test period is missing.');
    }
    while ($period->firstChild !== null) {
        $period->removeChild($period->firstChild);
    }
    $instant = $document->createElementNS(
        'http://www.xbrl.org/2003/instance',
        'xbrli:instant',
        '2023-09-30'
    );
    $period->appendChild($instant);

    return (string)$document->saveXML();
}
