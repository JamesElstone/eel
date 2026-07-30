<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Filesystem verification that cannot safely be expressed by the SQL
 * migration. A legacy Companies House table is removed only after this pass.
 */
final class GovTalkExchangeLedgerMigrationService
{
    public function verifyAndFinalize(): int
    {
        if (!\InterfaceDB::tableExists('govtalk_protocol_exchanges')) {
            return 0;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT e.*, a.authority AS archive_authority,
                    a.environment AS archive_environment,
                    a.archive_path
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.authority = :authority
             ORDER BY e.id',
            ['authority' => 'companies_house']
        );
        foreach ($rows as $row) {
            if ((string)$row['archive_authority'] !== 'companies_house'
                || (string)$row['archive_environment'] !== (string)$row['environment']
                || ((int)($row['submission_id'] ?? 0) <= 0
                    && (int)($row['preflight_id'] ?? 0) <= 0)
                || (int)($row['hmrc_submission_id'] ?? 0) > 0) {
                throw new \RuntimeException(
                    'A migrated Companies House exchange does not match its archive identity.'
                );
            }
            $requestBytes = $this->verifyArtifact(
                $row['request_path'] ?? null,
                $row['request_sha256'] ?? null,
                (string)$row['archive_path'],
                false
            );
            $responseBytes = $this->verifyArtifact(
                $row['response_path'] ?? null,
                $row['response_sha256'] ?? null,
                (string)$row['archive_path'],
                true
            );
            \InterfaceDB::prepareExecute(
                'UPDATE govtalk_protocol_exchanges
                 SET request_bytes = :request_bytes,
                     response_bytes = :response_bytes,
                     updated_at = :updated_at
                 WHERE id = :id AND authority = :authority',
                [
                    'request_bytes' => $requestBytes,
                    'response_bytes' => $responseBytes,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int)$row['id'],
                    'authority' => 'companies_house',
                ]
            );
        }
        if (\InterfaceDB::tableExists('companies_house_protocol_exchanges_legacy')) {
            $legacyCount = (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM companies_house_protocol_exchanges_legacy'
            );
            $migratedCount = (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM govtalk_protocol_exchanges
                 WHERE authority = :authority',
                ['authority' => 'companies_house']
            );
            $missing = (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM companies_house_protocol_exchanges_legacy old
                 LEFT JOIN govtalk_protocol_exchanges current
                   ON current.id = old.id
                  AND current.authority = :authority
                  AND current.environment = old.environment
                  AND current.transaction_id = old.transaction_id
                  AND (current.request_sha256 <=> old.request_sha256)
                  AND (current.response_sha256 <=> old.response_sha256)
                 WHERE current.id IS NULL',
                ['authority' => 'companies_house']
            );
            if ($legacyCount !== $migratedCount || $missing !== 0) {
                throw new \RuntimeException(
                    'The Companies House exchange-ledger copy could not be verified; '
                    . 'the legacy table has been retained.'
                );
            }
            \InterfaceDB::execute('DROP TABLE companies_house_protocol_exchanges_legacy');
        }

        return count($rows);
    }

    private function verifyArtifact(
        mixed $pathValue,
        mixed $shaValue,
        string $archivePath,
        bool $optional
    ): ?int {
        $path = trim((string)$pathValue);
        $sha256 = strtolower(trim((string)$shaValue));
        if ($path === '' && $sha256 === '' && $optional) {
            return null;
        }
        if ($path === '' || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \RuntimeException(
                'A migrated GovTalk evidence pathname or checksum is incomplete.'
            );
        }
        $resolvedPath = realpath($path);
        $resolvedArchive = realpath($archivePath);
        if (!is_string($resolvedPath)
            || !is_string($resolvedArchive)
            || !$this->pathWithin($resolvedPath, $resolvedArchive)
            || !is_file($resolvedPath)) {
            throw new \RuntimeException(
                'A migrated GovTalk evidence file is outside its private archive.'
            );
        }
        $actualSha = hash_file('sha256', $resolvedPath);
        $bytes = filesize($resolvedPath);
        if (!is_string($actualSha)
            || !hash_equals($sha256, strtolower($actualSha))
            || !is_int($bytes)) {
            throw new \RuntimeException(
                'A migrated GovTalk evidence file failed its integrity check.'
            );
        }

        return $bytes;
    }

    private function pathWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent . '/');
    }
}
