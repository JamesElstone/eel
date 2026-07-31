<?php
/**
 * Arelle iXBRL validator adapter.
 *
 * This file belongs to the third-party Arelle integration boundary. It does
 * not vendor Arelle itself; it shells out to a configured Arelle command.
 */
declare(strict_types=1);

final class ArelleIxbrlValidator
{
    private string $validatorVersion = '';

    public function __construct(
        private readonly array $config = [],
        private readonly ?string $rootPath = null,
    ) {
    }

    public function validate(
        string $ixbrlPath,
        array $additionalPackages = [],
        array $ignoredDiagnosticCodes = []
    ): array
    {
        $started = microtime(true);
        $ixbrlPath = trim($ixbrlPath);
        if ($ixbrlPath === '' || !is_file($ixbrlPath)) {
            return $this->result(false, 'error', ['The iXBRL/XHTML file could not be found.'], [], '', $started);
        }

        $config = $this->loadConfig();
        if ($config === null || empty($config['enabled'])) {
            return $this->result(false, 'not_configured', ['Arelle is not configured. Run third_party/arelle/bin/install_arelle.bat.'], [], '', $started);
        }

        $arelleCommand = trim((string)($config['arelle_cmd'] ?? ''));
        if ($arelleCommand === '' || !is_file($arelleCommand)) {
            return $this->result(false, 'not_configured', ['Configured Arelle command was not found.'], [], '', $started);
        }
        $this->validatorVersion = $this->detectVersion($arelleCommand);
        if ($this->validatorVersion === '') {
            return $this->result(false, 'error', ['The configured Arelle version could not be identified.'], [], '', $started);
        }

        $logsPath = trim((string)($config['logs_path'] ?? ''));
        if ($logsPath === '') {
            $logsPath = $this->rootPath() . DIRECTORY_SEPARATOR . 'logs';
        }
        if (!is_dir($logsPath) && !mkdir($logsPath, 0775, true) && !is_dir($logsPath)) {
            return $this->result(false, 'error', ['Could not create Arelle log directory.'], [], '', $started);
        }

        $cachePath = trim((string)($config['cache_path'] ?? ''));
        if ($cachePath === '') {
            $cachePath = $this->rootPath() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache';
        }
        if (!is_dir($cachePath) && !mkdir($cachePath, 0775, true) && !is_dir($cachePath)) {
            return $this->result(false, 'error', ['Could not create Arelle cache directory.'], [], '', $started);
        }

        $packages = $this->configuredPackages((array)($config['packages'] ?? []));
        foreach ($additionalPackages as $additionalPackage) {
            $additionalPackage = trim((string)$additionalPackage);
            $resolved = $additionalPackage !== '' ? realpath($additionalPackage) : false;
            if ($resolved === false || !is_file($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'zip') {
                return $this->result(false, 'error', ['An additional Arelle taxonomy package is missing or invalid.'], [], '', $started);
            }
            $packages[] = $resolved;
        }
        $packages = array_values(array_unique($packages));

        $timeout = max(1, (int)($config['timeout_seconds'] ?? 180));
        $command = $this->buildCommand(
            $arelleCommand,
            $ixbrlPath,
            (array)($config['flags'] ?? ['--validate']),
            $packages,
            $cachePath,
            !array_key_exists('offline', $config) || !empty($config['offline'])
        );
        $execution = $this->runCommand($command, $timeout);
        $logPath = $this->writeLog($logsPath, $command, $execution);
        $parsed = $this->parseDiagnostics(
            (string)($execution['stdout'] ?? ''),
            (string)($execution['stderr'] ?? '')
        );
        $diagnostics = $parsed['diagnostics'];
        $ignoredCodes = array_fill_keys(array_map(
            static fn(mixed $code): string => strtoupper(trim((string)$code)),
            $ignoredDiagnosticCodes
        ), true);
        $unignoredDiagnostics = array_values(array_filter(
            $diagnostics,
            static fn(array $diagnostic): bool => !isset($ignoredCodes[strtoupper(trim((string)($diagnostic['code'] ?? '')))])
        ));
        $errorDiagnostics = array_values(array_filter(
            $unignoredDiagnostics,
            static fn(array $diagnostic): bool => in_array((string)$diagnostic['severity'], ['fatal', 'error'], true)
        ));
        $warningDiagnostics = array_values(array_filter(
            $unignoredDiagnostics,
            static fn(array $diagnostic): bool => (string)$diagnostic['severity'] === 'warning'
        ));
        $errors = array_map([$this, 'diagnosticMessage'], $errorDiagnostics);
        $warnings = array_map([$this, 'diagnosticMessage'], $warningDiagnostics);

        if (!empty($execution['timed_out'])) {
            return $this->result(
                false,
                'error',
                ['Arelle validation timed out after ' . $timeout . ' seconds.'],
                $warnings,
                $logPath,
                $started,
                $execution,
                $diagnostics,
                [],
                $warningDiagnostics
            );
        }

        $exitCode = (int)($execution['exit_code'] ?? 1);
        $hasIgnoredError = count($errorDiagnostics) < count(array_filter(
            $diagnostics,
            static fn(array $diagnostic): bool => in_array((string)$diagnostic['severity'], ['fatal', 'error'], true)
        ));
        if ($errors !== [] || ($exitCode !== 0 && !$hasIgnoredError)) {
            if ($errors === []) {
                $errors[] = 'Arelle exited with code ' . $exitCode . '.';
            }

            return $this->result(
                false,
                'failed',
                $errors,
                $warnings,
                $logPath,
                $started,
                $execution,
                $diagnostics,
                $errorDiagnostics,
                $warningDiagnostics
            );
        }

        return $this->result(
            true,
            'passed',
            [],
            $warnings,
            $logPath,
            $started,
            $execution,
            $diagnostics,
            [],
            $warningDiagnostics
        );
    }

    /** Check installation/configuration without requiring a generated artifact. */
    public function configurationStatus(): array
    {
        $config = $this->loadConfig();
        if ($config === null || empty($config['enabled'])) {
            return [
                'installed' => false,
                'status' => 'not_configured',
                'detail' => 'Arelle is not configured. Run third_party/arelle/bin/install_arelle.bat.',
            ];
        }

        $command = trim((string)($config['arelle_cmd'] ?? ''));
        if ($command === '' || !is_file($command)) {
            return [
                'installed' => false,
                'status' => 'not_configured',
                'detail' => 'The configured Arelle command was not found.',
            ];
        }

        return [
            'installed' => true,
            'status' => 'installed',
            'detail' => 'Arelle is installed and configured.',
        ];
    }

    private function loadConfig(): ?array
    {
        return $this->config === [] ? null : $this->config;
    }

    private function rootPath(): string
    {
        return rtrim((string)($this->rootPath ?? dirname(__DIR__)), '\\/');
    }

    private function configuredPackages(array $configuredPackages): array
    {
        $packages = [];
        foreach ($configuredPackages as $configuredPackage) {
            $configuredPackage = trim((string)$configuredPackage);
            if ($configuredPackage === '') {
                continue;
            }
            if (!$this->isAbsolutePath($configuredPackage)) {
                $configuredPackage = $this->rootPath() . DIRECTORY_SEPARATOR . $configuredPackage;
            }

            if (is_dir($configuredPackage)) {
                $zipFiles = glob(rtrim($configuredPackage, '\\/') . DIRECTORY_SEPARATOR . '*.zip') ?: [];
                sort($zipFiles, SORT_STRING);
                foreach ($zipFiles as $zipFile) {
                    $packages[] = $zipFile;
                }
                continue;
            }

            $packages[] = $configuredPackage;
        }

        return array_values(array_unique($packages));
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }

    private function buildCommand(
        string $arelleCommand,
        string $ixbrlPath,
        array $flags,
        array $packages,
        string $cachePath,
        bool $offline
    ): string
    {
        $parts = [escapeshellarg($arelleCommand)];
        $flags = $flags !== [] ? $flags : ['--validate'];
        if (!in_array('--validationExitCode', $flags, true)) {
            $flags[] = '--validationExitCode';
        }
        foreach ($flags as $flag) {
            $flag = trim((string)$flag);
            if ($flag !== '') {
                $parts[] = escapeshellarg($flag);
            }
        }
        $parts[] = escapeshellarg('--cacheDirectory');
        $parts[] = escapeshellarg($cachePath);
        if ($offline) {
            $parts[] = escapeshellarg('--internetConnectivity=offline');
        }
        foreach ($packages as $package) {
            $package = trim((string)$package);
            if ($package === '') {
                continue;
            }
            $parts[] = escapeshellarg('--package');
            $parts[] = escapeshellarg($package);
        }
        $parts[] = escapeshellarg('--file');
        $parts[] = escapeshellarg($ixbrlPath);

        return implode(' ', $parts);
    }

    private function runCommand(string $command, int $timeoutSeconds): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Could not start Arelle process.', 'timed_out' => false];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (empty($status['running'])) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $timedOut ? 124 : $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
        ];
    }

    private function writeLog(string $logsPath, string $command, array $execution): string
    {
        $path = rtrim($logsPath, '\\/') . DIRECTORY_SEPARATOR . 'arelle_validation_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.log';
        $body = 'Command: ' . $command . "\n"
            . 'Exit code: ' . (string)($execution['exit_code'] ?? '') . "\n"
            . 'Timed out: ' . (!empty($execution['timed_out']) ? 'yes' : 'no') . "\n\n"
            . "STDOUT\n------\n" . (string)($execution['stdout'] ?? '') . "\n\n"
            . "STDERR\n------\n" . (string)($execution['stderr'] ?? '') . "\n";
        file_put_contents($path, $body);

        return $path;
    }

    /**
     * Extract bracketed Arelle diagnostics without losing either process stream.
     *
     * @return array{diagnostics: list<array<string, mixed>>}
     */
    private function parseDiagnostics(string $stdout, string $stderr): array
    {
        $diagnostics = [];
        $seen = [];
        foreach (['stdout' => $stdout, 'stderr' => $stderr] as $stream => $output) {
            foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $rawLine) {
                $diagnostic = $this->parseDiagnosticLine((string)$rawLine, $stream);
                if ($diagnostic === null) {
                    continue;
                }
                $key = implode("\x1F", [
                    (string)$diagnostic['severity'],
                    strtolower((string)$diagnostic['code']),
                    (string)$diagnostic['message'],
                    (string)($diagnostic['source_document'] ?? ''),
                    (string)($diagnostic['line'] ?? ''),
                    (string)($diagnostic['column'] ?? ''),
                    (string)($diagnostic['fact_reference'] ?? ''),
                ]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $diagnostics[] = $diagnostic;
            }
        }

        return ['diagnostics' => $diagnostics];
    }

    /** @return array<string, mixed>|null */
    private function parseDiagnosticLine(string $rawLine, string $stream): ?array
    {
        $line = trim($rawLine);
        if ($line === '') {
            return null;
        }

        $code = '';
        $message = '';
        $explicitSeverity = '';
        if (preg_match('/^(?:(?<severity>fatal|critical|error|exception|traceback|warning|warn|information|info)\s*[:\-]?\s*)?\[(?<code>[^\]]+)\]\s*(?<message>.*)$/i', $line, $matches) === 1) {
            $code = trim((string)$matches['code']);
            $message = trim((string)$matches['message']);
            $explicitSeverity = trim((string)($matches['severity'] ?? ''));
        } elseif (preg_match('/^(?<severity>fatal|critical|error|exception|traceback|warning|warn|information|info)\b\s*:?[[:space:]]*(?<message>.*)$/i', $line, $matches) === 1) {
            $explicitSeverity = trim((string)$matches['severity']);
            $code = $explicitSeverity;
            $message = trim((string)$matches['message']);
        } else {
            return null;
        }

        if ($code === '') {
            return null;
        }
        if ($message === '') {
            $message = $line;
        }
        $severity = $this->diagnosticSeverity($code, $explicitSeverity, $message);
        $location = $this->diagnosticLocation($message);

        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'stream' => $stream,
            'source_document' => $location['source_document'],
            'line' => $location['line'],
            'column' => $location['column'],
            'fact_reference' => $location['fact_reference'],
            'raw_line' => $rawLine,
        ];
    }

    private function diagnosticSeverity(string $code, string $explicitSeverity, string $message = ''): string
    {
        if ($explicitSeverity === '' && preg_match('/^(?:info(?:[.:].*)?|debug)\b/i', trim($code)) === 1) {
            return 'information';
        }
        $value = strtolower($explicitSeverity !== '' ? $explicitSeverity : $code . ' ' . $message);
        if ($explicitSeverity === '' && preg_match('/^(fatal|critical|error|exception|traceback|warning|warn|information|info)\b/i', $message, $matches) === 1) {
            $value = strtolower((string)$matches[1]);
        }
        if (preg_match('/fatal|critical|exception|traceback/', $value) === 1) {
            return 'fatal';
        }
        if (preg_match('/warning|warn/', $value) === 1) {
            return 'warning';
        }
        if (preg_match('/error|invalid|inconsistency|failure|missing/', $value) === 1) {
            return 'error';
        }
        if (preg_match('/information|info/', $value) === 1) {
            return 'information';
        }

        // Jurisdictional Arelle plug-ins commonly emit rule codes without an
        // explicit severity (for example, HMRC.5.3 or JFCVC.3312). Informational
        // and debug records are handled above; retain the other findings as
        // visible validation errors rather than hiding them as "unknown".
        return 'error';
    }

    /** @return array{source_document: ?string, line: ?int, column: ?int, fact_reference: ?string} */
    private function diagnosticLocation(string $message): array
    {
        $sourceDocument = null;
        $lineNumber = null;
        $columnNumber = null;
        if (preg_match('/(?<document>(?:[A-Za-z]:[\\\\\/]|\/)[^:\r\n]+):(?<line>\d+)(?::(?<column>\d+))?/', $message, $matches) === 1) {
            $sourceDocument = (string)$matches['document'];
            $lineNumber = (int)$matches['line'];
            $columnNumber = isset($matches['column']) && $matches['column'] !== '' ? (int)$matches['column'] : null;
        } elseif (preg_match('/(?<document>(?:[A-Za-z]:[\\\\\/]|\/)[^\r\n]*?)(?=\s+(?:line\s+\d+|at\b)|$)/i', $message, $matches) === 1) {
            $sourceDocument = trim((string)$matches['document']);
        }
        if ($lineNumber === null && preg_match('/\bline\s+(?<line>\d+)(?:\s*(?:,|:)?\s*column\s+(?<column>\d+))?/i', $message, $matches) === 1) {
            $lineNumber = (int)$matches['line'];
            $columnNumber = isset($matches['column']) && $matches['column'] !== '' ? (int)$matches['column'] : null;
        }

        $factReference = null;
        if (preg_match('/\bFact\s+(?<fact>[^\s,;]+)(?:\s+context\s+(?<context>[^\s,;.]+))?/i', $message, $matches) === 1) {
            $factReference = (string)$matches['fact'];
            if (isset($matches['context']) && $matches['context'] !== '') {
                $factReference .= ' (context ' . (string)$matches['context'] . ')';
            }
        }

        return [
            'source_document' => $sourceDocument,
            'line' => $lineNumber,
            'column' => $columnNumber,
            'fact_reference' => $factReference,
        ];
    }

    /** @param array<string, mixed> $diagnostic */
    private function diagnosticMessage(array $diagnostic): string
    {
        $prefix = ucfirst((string)$diagnostic['severity']) . ' [' . (string)$diagnostic['code'] . ']';
        $message = trim((string)$diagnostic['message']);

        return $message === '' ? $prefix : $prefix . ' ' . $message;
    }

    private function detectVersion(string $arelleCommand): string
    {
        $execution = $this->runCommand(escapeshellarg($arelleCommand) . ' --version', 15);
        if (!empty($execution['timed_out']) || (int)($execution['exit_code'] ?? 1) !== 0) {
            return '';
        }
        $output = trim((string)($execution['stdout'] ?? '') . "\n" . (string)($execution['stderr'] ?? ''));
        $line = trim((string)((preg_split('/\R/', $output) ?: [])[0] ?? ''));
        return $line === '' ? '' : substr($line, 0, 100);
    }

    private function result(
        bool $ok,
        string $status,
        array $errors,
        array $warnings,
        string $logPath,
        float $started,
        array $execution = [],
        array $diagnostics = [],
        array $errorDiagnostics = [],
        array $warningDiagnostics = []
    ): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'validator' => 'arelle',
            'version' => $this->validatorVersion,
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'diagnostics' => array_values($diagnostics),
            'error_diagnostics' => array_values($errorDiagnostics),
            'warning_diagnostics' => array_values($warningDiagnostics),
            'exit_code' => array_key_exists('exit_code', $execution) ? (int)$execution['exit_code'] : null,
            'raw_stdout' => (string)($execution['stdout'] ?? ''),
            'raw_stderr' => (string)($execution['stderr'] ?? ''),
            'log_path' => $logPath,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}
