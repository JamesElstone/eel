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
    public function __construct(
        private readonly ?string $configPath = null,
        private readonly ?string $rootPath = null,
    ) {
    }

    public function validate(string $ixbrlPath): array
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

        $timeout = max(1, (int)($config['timeout_seconds'] ?? 180));
        $command = $this->buildCommand(
            $arelleCommand,
            $ixbrlPath,
            (array)($config['flags'] ?? ['--validate']),
            $this->configuredPackages((array)($config['packages'] ?? [])),
            $cachePath,
            !array_key_exists('offline', $config) || !empty($config['offline'])
        );
        $execution = $this->runCommand($command, $timeout);
        $logPath = $this->writeLog($logsPath, $command, $execution);
        $output = trim((string)$execution['stdout'] . "\n" . (string)$execution['stderr']);
        $errors = $this->matchingLines(
            $output,
            '/(?:^|[\s\[])(?:error|exception|traceback|critical)(?=$|[\s:\]])/i'
        );
        $warnings = $this->matchingLines(
            $output,
            '/(?:^|[\s\[])(?:warning|warn)(?=$|[\s:\]])/i'
        );

        if (!empty($execution['timed_out'])) {
            return $this->result(false, 'error', ['Arelle validation timed out after ' . $timeout . ' seconds.'], $warnings, $logPath, $started);
        }

        $exitCode = (int)($execution['exit_code'] ?? 1);
        if ($exitCode !== 0 || $errors !== []) {
            if ($errors === []) {
                $errors[] = 'Arelle exited with code ' . $exitCode . '.';
            }

            return $this->result(false, 'failed', $errors, $warnings, $logPath, $started);
        }

        return $this->result(true, 'passed', [], $warnings, $logPath, $started);
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
        $path = $this->configPath ?? ($this->rootPath() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'arelle.config.php');
        if (!is_file($path)) {
            return null;
        }

        $config = require $path;

        return is_array($config) ? $config : null;
    }

    private function rootPath(): string
    {
        return rtrim((string)($this->rootPath ?? dirname(__DIR__)), '\\/');
    }

    private function configuredPackages(array $configuredPackages): array
    {
        if ($configuredPackages === []) {
            $configuredPackages = [$this->rootPath() . DIRECTORY_SEPARATOR . 'taxonomies'];
        }

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

    private function matchingLines(string $text, string $pattern): array
    {
        $matches = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '' && preg_match($pattern, $line) === 1) {
                $matches[] = $line;
            }
        }

        return array_values(array_unique($matches));
    }

    private function result(bool $ok, string $status, array $errors, array $warnings, string $logPath, float $started): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'validator' => 'arelle',
            'version' => '',
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'log_path' => $logPath,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}
