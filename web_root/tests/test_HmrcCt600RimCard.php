<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'TestOutput.php';

$cardFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'tax_rates_ct600_rim.php';
$source = file_get_contents($cardFile);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read HMRC CT600 RIM card source.');
}

$required = [
    "'hmrc_ct_rim_catalogue'",
    'Source updated:',
    'Checked:',
    'hmrc_ct_rim_refresh',
    'hmrc_ct_rim_delete',
    'Refresh HMRC CT600 RIM Catalogue',
    'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts',
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

test_output_line('HmrcCt600RimCard: renders the expected catalogue controls.');
