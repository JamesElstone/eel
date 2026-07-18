<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'TestOutput.php';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR
    . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR
    . 'HmrcSubmissionPackageService.php';

$service = new \eel_accounts\Service\HmrcSubmissionPackageService();
foreach ([
    $service->locateComputationsIxbrl(49, 79),
    $service->locateComputationsIxbrlForCtPeriod(49, 6),
    $service->buildSubmissionEnvelope(1),
] as $result) {
    if (($result['ok'] ?? true) !== false
        || ($result['errors'][0] ?? '') !== 'CT600 submission is not implemented.') {
        throw new RuntimeException('CT600 package operation did not fail closed.');
    }
}
if ($service->hashPackage(1) !== '') {
    throw new RuntimeException('Disabled CT600 package hashing returned a value.');
}

test_output_line('HmrcSubmissionPackageService: fails closed without database access.');
