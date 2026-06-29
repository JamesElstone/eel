<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlRenderService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlRenderService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'refuses generation when no fact run exists', static function () use ($harness, $service): void {
            $result = $service->generatePreview(0, 0);
            $harness->assertSame(false, $result['success']);
        });

        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'renders Inline XBRL facts with contexts and units', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'renderXhtml');
            $method->setAccessible(true);
            $xhtml = (string)$method->invoke($service, ixbrlRenderFixtureFacts());

            $harness->assertTrue(str_contains($xhtml, '<ix:header>'));
            $harness->assertTrue(str_contains($xhtml, '<link:schemaRef'));
            $harness->assertTrue(str_contains($xhtml, '<xbrli:context id="current_period_end"'));
            $harness->assertTrue(str_contains($xhtml, '<xbrli:unit id="GBP"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonFraction name="uk-gaap:FixedAssets"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="uk-gaap:MicroEntityAccountsStatement"'));
            $harness->assertSame(false, str_contains($xhtml, 'data-ixbrl-concept'));

            $validator = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml));
        });
    }
);

function ixbrlRenderFixtureFacts(): array
{
    return [
        ixbrlRenderFact('entity_name', 'uk-bus:EntityCurrentLegalOrRegisteredName', 'Entity name', 'text', null, 'Example Limited', null, null, null, 'entity'),
        ixbrlRenderFact('company_number', 'uk-bus:UKCompaniesHouseRegisteredNumber', 'Company number', 'text', null, '01234567', null, null, null, 'entity'),
        ixbrlRenderFact('period_start', 'uk-bus:StartDateForPeriodCoveredByReport', 'Period start', 'date', null, null, '2026-01-01', null, null, 'current_period_duration'),
        ixbrlRenderFact('period_end', 'uk-bus:EndDateForPeriodCoveredByReport', 'Period end', 'date', null, null, '2026-12-31', null, null, 'current_period_duration'),
        ixbrlRenderFact('fixed_assets', 'uk-gaap:FixedAssets', 'Fixed assets', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('current_assets', 'uk-gaap:CurrentAssets', 'Current assets', 'numeric', 500.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('creditors_within_one_year', 'uk-gaap:CreditorsDueWithinOneYear', 'Creditors within one year', 'numeric', 50.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('net_assets_liabilities', 'uk-gaap:NetAssetsLiabilities', 'Net assets', 'numeric', 1050.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('equity', 'uk-gaap:CapitalAndReserves', 'Capital and reserves', 'numeric', 1050.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('micro_entity_statement', 'uk-gaap:MicroEntityAccountsStatement', 'Micro entity statement', 'text', null, 'Prepared under FRS 105.', null, null, null, 'entity'),
    ];
}

function ixbrlRenderFact(
    string $key,
    string $concept,
    string $label,
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
        'label' => $label,
        'value_type' => $type,
        'numeric_value' => $numeric,
        'text_value' => $text,
        'date_value' => $date,
        'unit_ref' => $unit,
        'decimals_value' => $decimals,
        'context_ref' => $context,
    ];
}
