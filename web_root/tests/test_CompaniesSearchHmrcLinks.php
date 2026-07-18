<?php
declare(strict_types=1);

$cardFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'companies_search.php';
$source = file_get_contents($cardFile);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read Companies Search card source.');
}

foreach ([
    'HMRC - Corporation Tax accounting periods',
    'HMRC - First company accounts and return',
    'HMRC - Company Tax Return obligations',
    'https://www.gov.uk/corporation-tax-accounting-period',
    'https://www.gov.uk/first-company-accounts-and-return/overview',
    'https://www.gov.uk/guidance/company-tax-return-obligations',
] as $needle) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('Missing Companies Search HMRC link: ' . $needle);
    }
}

echo "Companies Search HMRC link checks passed.\n";
