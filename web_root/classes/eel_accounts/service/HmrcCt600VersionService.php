<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCt600VersionService
{
    public function resolveForCtPeriod(string $periodStart, string $periodEnd, ?\DateTimeImmutable $asOf = null): array
    {
        $start = $this->date($periodStart); $end = $this->date($periodEnd);
        if ($start === null || $end === null) { return $this->failure('CT period dates must use YYYY-MM-DD.'); }
        if ($end < $start) { return $this->failure('The CT period end must be on or after its start.'); }
        $maximumEnd = $start->modify('+1 year')->modify('-1 day');
        if ($end > $maximumEnd) { return $this->failure('The CT period exceeds 12 months.'); }
        $at = ($asOf ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $candidates = \InterfaceDB::fetchAll(
            'SELECT * FROM hmrc_ct_rim_packages
             WHERE package_state IN (\'verified\', \'stale\')
               AND applicability_status IN (\'confirmed\', \'open_start\')
               AND (applicable_from IS NULL OR applicable_from <= :period_start)
               AND (applicable_to IS NULL OR applicable_to >= :period_start)
             ORDER BY (applicable_from IS NULL) ASC, applicable_from DESC, id DESC',
            ['period_start' => $start->format('Y-m-d')]
        );
        $catalogue = new HmrcCtRimCatalogueService();
        $row = null;
        foreach ($candidates as $candidate) {
            $candidate = $catalogue->effectiveLifecycle($candidate);
            $liveFrom = trim((string)($candidate['live_from'] ?? ''));
            $liveTo = trim((string)($candidate['live_to'] ?? ''));
            if (strtolower((string)($candidate['hmrc_status'] ?? '')) !== 'live'
                || $liveFrom === '' || $liveFrom > $at
                || ($liveTo !== '' && $liveTo < $at)) {
                continue;
            }
            $row = $candidate;
            break;
        }
        if (!is_array($row)) { return $this->failure('No live HMRC CT600 RIM artefact is available for this CT period.'); }
        $warnings = [];
        if ($end >= new \DateTimeImmutable('2015-04-01') && $start < new \DateTimeImmutable('2015-04-01')) { $warnings[] = 'This CT period spans the V2/V3 boundary; the form version is selected from the CT period start date.'; }
        return ['ok' => true, 'period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d'), 'form_version' => (string)$row['form_version'], 'artifact_version' => (string)$row['artifact_version'], 'applicable_from' => (string)$row['applicable_from'], 'applicable_to' => (string)($row['applicable_to'] ?? ''), 'live_from' => (string)($row['live_from'] ?? ''), 'live_to' => (string)($row['live_to'] ?? ''), 'source_url' => (string)$row['source_url'], 'download_url' => (string)($row['download_url'] ?? ''), 'sha256' => (string)($row['sha256'] ?? ''), 'package_state' => (string)$row['package_state'], 'warnings' => $warnings, 'errors' => []];
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) { return null; }
        try { $date = new \DateTimeImmutable($value); return $date->format('Y-m-d') === $value ? $date : null; } catch (\Throwable) { return null; }
    }

    private function failure(string $message): array
    {
        return ['ok' => false, 'period_start' => '', 'period_end' => '', 'form_version' => '', 'artifact_version' => '', 'warnings' => [], 'errors' => [$message]];
    }
}
