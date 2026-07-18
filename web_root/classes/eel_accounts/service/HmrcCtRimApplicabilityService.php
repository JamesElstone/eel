<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Reads CT600 form applicability calculated from downloaded primary XSDs.
 */
final class HmrcCtRimApplicabilityService
{
    /** @return array{applicable_from:?string, applicable_to:?string} */
    public function forFormVersion(string $formVersion): array
    {
        $row = \InterfaceDB::fetchOne('SELECT applicable_from, applicable_to FROM hmrc_ct_rim_packages WHERE form_version = :form_version AND applicability_status IN (\'confirmed\', \'open_start\') ORDER BY applicable_from ASC LIMIT 1', ['form_version' => strtoupper(trim($formVersion))]);
        return ['applicable_from' => is_array($row) ? ($row['applicable_from'] ?? null) : null, 'applicable_to' => is_array($row) ? ($row['applicable_to'] ?? null) : null];
    }
}
