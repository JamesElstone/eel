<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Owns the CT600 form applicability rules.
 *
 * HMRC's publication metadata identifies artefact revisions but does not
 * currently provide the CT600 form cutover dates. Keep those rules behind
 * this service so a future HMRC/API-backed update can replace this source
 * without changing catalogue refresh or version selection code.
 */
final class HmrcCtRimApplicabilityService
{
    /** @return array{applicable_from:?string, applicable_to:?string} */
    public function forFormVersion(string $formVersion): array
    {
        return match (strtoupper(trim($formVersion))) {
            'V2' => [
                'applicable_from' => '1900-01-01',
                'applicable_to' => '2015-03-31',
            ],
            'V3' => [
                'applicable_from' => '2015-04-01',
                'applicable_to' => null,
            ],
            default => [
                'applicable_from' => null,
                'applicable_to' => null,
            ],
        };
    }
}
