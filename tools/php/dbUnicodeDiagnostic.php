<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function eel_db_unicode_diagnostic_samples(): array
{
    return [
        'ascii' => 'Plain ASCII text',
        'en_dash' => 'Before – after',
        'accented' => 'Crème brûlée',
        'cjk' => '日本語',
        'four_byte' => 'Rocket 🚀',
    ];
}

function eel_db_unicode_diagnostic_same_utf8(string $expected, string $actual): bool
{
    if (!hash_equals($expected, $actual) || !mb_check_encoding($actual, 'UTF-8')) {
        return false;
    }

    return array_map(static fn(string $character): int => mb_ord($character, 'UTF-8'), mb_str_split($expected, 1, 'UTF-8'))
        === array_map(static fn(string $character): int => mb_ord($character, 'UTF-8'), mb_str_split($actual, 1, 'UTF-8'));
}

function eel_db_unicode_diagnostic_dsn_status(string $dsn, string $driver): string
{
    if ($driver !== 'odbc') {
        return 'not applicable (not an ODBC connection)';
    }

    $detail = trim(substr($dsn, strlen('odbc:')));
    if (!str_contains($detail, '=')) {
        return 'not directly verifiable for a named DSN; require CHARSET=utf8mb4 in its ODBC configuration';
    }

    return preg_match('/(?:^|;)\s*charset\s*=\s*utf8mb4\s*(?:;|$)/i', $detail) === 1
        ? 'CHARSET=utf8mb4 present in the inline ODBC connection string'
        : 'CHARSET=utf8mb4 not found in the inline ODBC connection string';
}

function eel_db_unicode_diagnostic_character_sets(): array
{
    $result = ['server' => 'unavailable', 'connection' => 'unavailable'];
    try {
        foreach (InterfaceDB::fetchAll("SHOW VARIABLES WHERE Variable_name IN ('character_set_server', 'character_set_connection')") as $row) {
            $name = (string)($row['Variable_name'] ?? $row['variable_name'] ?? '');
            $value = (string)($row['Value'] ?? $row['value'] ?? '');
            if ($name === 'character_set_server') {
                $result['server'] = $value;
            }
            if ($name === 'character_set_connection') {
                $result['connection'] = $value;
            }
        }
    } catch (Throwable) {
    }

    return $result;
}

function eel_db_unicode_diagnostic_run(): array
{
    $driver = InterfaceDB::driverName();
    $dsn = (string)AppConfigurationStore::get('db.dsn', '', true);
    $result = [
        'driver' => $driver,
        'os_family' => PHP_OS_FAMILY,
        'dsn_status' => eel_db_unicode_diagnostic_dsn_status($dsn, $driver),
        'character_sets' => eel_db_unicode_diagnostic_character_sets(),
        'round_trip' => false,
        'json_sha256' => '',
    ];
    $table = 'eelkit_unicode_diagnostic_' . bin2hex(random_bytes(8));
    $samples = eel_db_unicode_diagnostic_samples();
    $json = json_encode(['samples' => $samples], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    try {
        InterfaceDB::execute("CREATE TEMPORARY TABLE `$table` (sample_key VARCHAR(32) PRIMARY KEY, value_text LONGTEXT NOT NULL, payload_json LONGTEXT NOT NULL) CHARACTER SET utf8mb4");
        foreach ($samples as $key => $value) {
            InterfaceDB::execute("INSERT INTO `$table` (sample_key, value_text, payload_json) VALUES (?, ?, ?)", [$key, $value, $json]);
            InterfaceDB::execute("UPDATE `$table` SET value_text = ? WHERE sample_key = ?", [$value, $key]);
        }

        $rows = InterfaceDB::fetchAll("SELECT sample_key, value_text, payload_json FROM `$table` ORDER BY sample_key");
        if (count($rows) !== count($samples)) {
            throw new RuntimeException('Unexpected number of rows returned by the Unicode diagnostic.');
        }
        foreach ($rows as $row) {
            $key = (string)$row['sample_key'];
            if (!isset($samples[$key]) || !eel_db_unicode_diagnostic_same_utf8($samples[$key], (string)$row['value_text'])) {
                throw new RuntimeException('UTF-8 round-trip mismatch for diagnostic sample ' . $key . '.');
            }
            if (!eel_db_unicode_diagnostic_same_utf8($json, (string)$row['payload_json'])) {
                throw new RuntimeException('JSON byte round-trip mismatch for diagnostic sample ' . $key . '.');
            }
        }

        $result['round_trip'] = true;
        $result['json_sha256'] = hash('sha256', $json);
        return $result;
    } finally {
        try {
            InterfaceDB::execute("DROP TEMPORARY TABLE IF EXISTS `$table`");
        } catch (Throwable) {
        }
    }
}

function eel_db_unicode_diagnostic_main(): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This tool can only be run from the command line.\n");
        return 1;
    }

    try {
        $result = eel_db_unicode_diagnostic_run();
        fwrite(STDOUT, 'PDO driver: ' . $result['driver'] . PHP_EOL);
        fwrite(STDOUT, 'OS family: ' . $result['os_family'] . PHP_EOL);
        fwrite(STDOUT, 'ODBC charset: ' . $result['dsn_status'] . PHP_EOL);
        fwrite(STDOUT, 'Server character set: ' . $result['character_sets']['server'] . PHP_EOL);
        fwrite(STDOUT, 'Connection character set: ' . $result['character_sets']['connection'] . PHP_EOL);
        fwrite(STDOUT, 'Unicode and JSON byte-exact round trip: PASS' . PHP_EOL);
        fwrite(STDOUT, 'JSON SHA-256: ' . $result['json_sha256'] . PHP_EOL);
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Unicode diagnostic failed: ' . $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, 'Confirm MariaDB/ODBC CHARSET=utf8mb4 and CREATE TEMPORARY TABLES permission, then rerun this tool.' . PHP_EOL);
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_db_unicode_diagnostic_main());
}
