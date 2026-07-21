<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'TestOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestBootstrap.php';

$cardFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'tax_rates_ct600_rim.php';
$source = file_get_contents($cardFile);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read HMRC CT600 RIM card source.');
}

$required = [
    "'hmrc_ct_rim_catalogue'",
    "'hmrc_ct_computation_catalogue'",
    'HMRC CT filing artefacts',
    'CT600 return RIM schemas',
    'Computation iXBRL taxonomies',
    'Source updated:',
    'Checked:',
    'hmrc_ct_artifacts_refresh',
    'hmrc_ct_rim_delete',
    'hmrc_ct_computation_delete',
    'Refresh and install HMRC filing artefacts',
    'Filing Mappings',
    'Unsupported',
    'Install required',
    'Awaiting official package',
    'active compatible',
    'concepts',
    'dimensions',
    'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts',
    'HmrcCtComputationCatalogueService::SOURCE_URL',
    'https://www.gov.uk/government/publications/taxonomies-accepted-by-hm-revenue-and-customs/taxonomies-accepted-by-hmrc',
    'https://www.gov.uk/government/news/new-version-of-company-tax-return-form-introduced',
    'https://www.gov.uk/government/publications/corporation-tax-company-tax-return-ct600-2008-version-2',
    'https://www.gov.uk/government/publications/corporation-tax-company-tax-return-ct600-2015-version-3',
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('Missing HMRC CT600 RIM card requirement: ' . $needle);
    }
}

if (strpos($source, '<th>Action</th>') === false || strpos($source, 'data-chicken-check="true"') === false || strpos($source, 'button-inline danger') === false) {
    throw new RuntimeException('The HMRC CT600 RIM card is missing the confirmed delete action.');
}

if (strpos($source, 'name="local_path"') !== false || strpos($source, 'name="directory"') !== false || strpos($source, 'CtComputationTaxonomy') !== false) {
    throw new RuntimeException('The unified artefact card still exposes manual computation-taxonomy administration.');
}

$card = new _tax_rates_ct600_rimCard();
$html = $card->render(['services' => [
    'hmrc_ct_rim_catalogue' => [],
    'hmrc_ct_computation_catalogue' => [
        [
            'id' => 24,
            'taxonomy_version' => '2024',
            'artifact_version' => 'V1.0.0',
            'applicable_from' => '2015-04-01',
            'applicable_to' => '2026-03-31',
            'package_state' => 'not_downloaded',
            'download_url' => \eel_accounts\Service\HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL,
        ],
        [
            'taxonomy_version' => '2024',
            'artifact_version' => 'V1.0.0',
            'applicable_from' => '2015-04-01',
            'applicable_to' => '2026-03-31',
            'package_state' => 'failed',
            'download_url' => null,
        ],
        [
            'taxonomy_version' => '2025',
            'artifact_version' => 'V1.0.0',
            'applicable_from' => '2015-04-01',
            'applicable_to' => null,
            'package_state' => 'not_downloaded',
            'download_url' => null,
        ],
        [
            'taxonomy_version' => '2026',
            'artifact_version' => 'V1.0.0',
            'applicable_from' => '2015-04-01',
            'applicable_to' => null,
            'package_state' => 'not_downloaded',
            'download_url' => null,
        ],
    ],
]]);
foreach (['CT600 return RIM schemas', 'Computation iXBRL taxonomies', 'Install required', 'Awaiting official package', 'Unsupported'] as $needle) {
    if (!str_contains($html, $needle)) {
        throw new RuntimeException('The unified artefact card did not render status: ' . $needle);
    }
}

$rimTitlePosition = strpos($html, 'CT600 return RIM schemas');
$linksPosition = strpos($html, 'Filing Mappings');
$sourcePosition = strpos($html, 'Source updated:');
if ($rimTitlePosition === false || $linksPosition === false || $sourcePosition === false
    || !($rimTitlePosition < $linksPosition && $linksPosition < $sourcePosition)) {
    throw new RuntimeException('The artefact links are not directly below the CT600 RIM section title.');
}

$computationTitlePosition = strpos($html, 'Computation iXBRL taxonomies');
$computationLinksPosition = strpos($html, 'HMRC - Computation iXBRL Specifications');
$computationHelperPosition = strpos($html, 'HMRC acceptance, official package availability');
if ($computationTitlePosition === false || $computationLinksPosition === false || $computationHelperPosition === false
    || !($computationTitlePosition < $computationLinksPosition && $computationLinksPosition < $computationHelperPosition)) {
    throw new RuntimeException('The HMRC computation links are not directly below the computation section title.');
}
if (!str_contains(
    $html,
    '<a class="button button-inline" href="' . \eel_accounts\Service\HmrcCtComputationCatalogueService::SOURCE_URL . '"'
)) {
    throw new RuntimeException('The HMRC computation specification link is not rendered as a button.');
}
if (!str_contains($html, 'hmrc_ct_computation_delete')) {
    throw new RuntimeException('The computation taxonomy table is missing its confirmed delete action.');
}

test_output_line('HmrcCt600RimCard: renders the unified filing artefact controls.');
