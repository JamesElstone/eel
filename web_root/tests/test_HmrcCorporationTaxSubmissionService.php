<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR
    . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR
    . 'HmrcCorporationTaxSubmissionService.php';

$service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService();
$result = $service->validatePackage(49, 6, 'TEST');
if (($result['success'] ?? true) !== false
    || ($result['errors'][0] ?? '') !== 'CT600 submission is not implemented.') {
    throw new RuntimeException('CT600 validation did not fail closed.');
}
if ($service->getSubmissionHistory(49, 79) !== []
    || $service->getLatestSubmission(49, 79) !== null
    || $service->getLatestSubmissionForCtPeriod(49, 6) !== null) {
    throw new RuntimeException('Disabled CT600 history access returned submission data.');
}
try {
    $service->ensureSchema();
    throw new RuntimeException('Disabled CT600 schema guard did not fail closed.');
} catch (LogicException $exception) {
    if ($exception->getMessage() !== 'CT600 submission is not implemented.') {
        throw $exception;
    }
}

echo "PASS HmrcCorporationTaxSubmissionService fails closed without database access.\n";
