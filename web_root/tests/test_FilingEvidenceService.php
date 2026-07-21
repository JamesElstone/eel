<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\FilingEvidenceService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\FilingEvidenceService $service): void {
        $h->check($service::class, 'normalises grouped bundle and artifact references without weakening their type', static function () use ($h, $service): void {
            $h->assertSame(
                'EEL-FE-0123456789ABCDEF0123456789ABCDEF',
                $service->normaliseReference('eel-fe-0123-4567-89ab-cdef-0123-4567-89ab-cdef')
            );
            $h->assertSame(
                'EEL-AR-ABCDEF0123456789ABCDEF0123456789',
                $service->normaliseReference('EEL AR ABCD EF01 2345 6789 ABCD EF01 2345 6789')
            );
            $h->assertSame('', $service->normaliseReference('EEL-FE-not-an-id'));
        });

        $h->check($service::class, 'resolves legacy backfill only inside its company scope', static function () use ($h, $service): void {
            $row = \InterfaceDB::fetchOne('SELECT * FROM filing_evidence_bundles ORDER BY id LIMIT 1');
            if (!is_array($row)) { return; }
            $found = $service->resolve((int)$row['company_id'], (string)$row['evidence_id']);
            $hidden = $service->resolve((int)$row['company_id'] + 999999, (string)$row['evidence_id']);
            $h->assertSame(true, (bool)($found['found'] ?? false));
            $h->assertSame((int)$row['id'], (int)($found['bundle_id'] ?? 0));
            $h->assertSame(false, (bool)($hidden['found'] ?? true));
        });

        $h->check($service::class, 'reads frozen overview calculations and artifacts without following the latest run pointer', static function () use ($h, $service): void {
            $row = \InterfaceDB::fetchOne('SELECT * FROM filing_evidence_bundles ORDER BY id LIMIT 1');
            if (!is_array($row)) { return; }
            $overview = $service->overview((int)$row['company_id'], (int)$row['id']);
            $calculations = $service->calculations((int)$row['company_id'], (int)$row['id']);
            $artifacts = $service->artifacts((int)$row['company_id'], (int)$row['id']);
            $h->assertSame(true, (bool)($overview['available'] ?? false));
            $h->assertTrue(count((array)($overview['ct_periods'] ?? [])) >= 1);
            $h->assertSame(true, (bool)($calculations['available'] ?? false));
            $h->assertSame(true, (bool)($artifacts['available'] ?? false));
        });
    }
);
