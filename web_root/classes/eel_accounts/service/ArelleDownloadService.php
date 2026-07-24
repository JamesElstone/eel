<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/** Installs Arelle without relying on OS-specific repository scripts. */
final class ArelleDownloadService
{
    private const PYPI_URL = 'https://pypi.org/pypi/arelle-release/json';

    public function status(): array
    {
        $configured = (array)\AppConfigurationStore::get('arelle', []);
        $command = trim((string)($configured['arelle_cmd'] ?? ''));
        if ($command === '' || !is_file($command)) {
            return ['installed' => false, 'version' => '', 'command' => $command, 'detail' => 'No Arelle command has been installed for this server.'];
        }
        $result = $this->run([ $command, '--version' ], 20);
        if ($result['exit_code'] !== 0) {
            return ['installed' => false, 'version' => '', 'command' => $command, 'detail' => 'The configured Arelle command could not be run.'];
        }
        return ['installed' => true, 'version' => trim($result['stdout']), 'command' => $command, 'detail' => 'Arelle is installed and configured.'];
    }

    /** @return array{version:string,command:string} */
    public function downloadAndInstall(): array
    {
        $version = $this->latestVersion();
        $python = $this->pythonCommand();
        $root = rtrim(PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'third_party' . DIRECTORY_SEPARATOR . 'arelle';
        $runtime = $root . DIRECTORY_SEPARATOR . 'runtime';
        $venv = $runtime . DIRECTORY_SEPARATOR . 'venv';
        if (!is_dir($runtime) && !mkdir($runtime, 0775, true) && !is_dir($runtime)) {
            throw new \RuntimeException('The Arelle runtime directory could not be created.');
        }

        $this->mustRun([$python, '-m', 'venv', $venv], 'Python could not create the Arelle virtual environment.');
        $venvPython = $this->venvPython($venv);
        $this->mustRun([$venvPython, '-m', 'pip', 'install', '--upgrade', 'pip'], 'pip could not be upgraded in the Arelle environment.');
        $this->mustRun([$venvPython, '-m', 'pip', 'install', '--upgrade', 'arelle-release==' . $version], 'Arelle could not be installed.');

        $command = $this->arelleCommand($venv);
        if (!is_file($command)) {
            throw new \RuntimeException('Arelle installed but its command-line executable was not created.');
        }
        $this->mustRun([$command, '--version'], 'The installed Arelle command did not start.');
        $sample = $root . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR . 'smoke_inline_xbrl.xhtml';
        if (!is_file($sample)) {
            throw new \RuntimeException('The bundled Arelle smoke-test file is missing.');
        }
        $this->mustRun([$command, '--validate', '--validationExitCode', '--file', $sample], 'The Arelle iXBRL smoke test failed.');

        $current = (array)\AppConfigurationStore::get('arelle', []);
        $current['enabled'] = true;
        $current['arelle_cmd'] = $command;
        $current['timeout_seconds'] = max(30, (int)($current['timeout_seconds'] ?? 180));
        $current['logs_path'] = $current['logs_path'] ?? rtrim(PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'file_logs' . DIRECTORY_SEPARATOR . 'arelle';
        $current['cache_path'] = $current['cache_path'] ?? $runtime . DIRECTORY_SEPARATOR . 'cache';
        $current['offline'] = true;
        $current['flags'] = ['--validate', '--validationExitCode'];
        \AppConfigurationStore::set('arelle', $current);

        return ['version' => $version, 'command' => $command];
    }

    private function latestVersion(): string
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('PHP cURL is required to check the Arelle release.'); }
        $handle = curl_init(self::PYPI_URL);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'EEL Accounts Arelle installer']);
        $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        $version = is_string($body) ? (string)((json_decode($body, true)['info']['version'] ?? '')) : '';
        if ($status < 200 || $status >= 300 || $error !== '' || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new \RuntimeException('The current Arelle release could not be read from PyPI' . ($error !== '' ? ': ' . $error : '.') );
        }
        return $version;
    }

    private function pythonCommand(): string
    {
        foreach (PHP_OS_FAMILY === 'Windows' ? ['python'] : ['python3', 'python'] as $python) {
            $result = $this->run([$python, '-c', 'import sys; print(sys.version_info[:2] >= (3, 10))'], 15);
            if ($result['exit_code'] === 0 && trim($result['stdout']) === 'True') { return $python; }
        }
        throw new \RuntimeException('Python 3.10 or newer was not found on the server PATH.');
    }

    private function venvPython(string $venv): string { return $venv . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'Scripts\\python.exe' : 'bin/python'); }
    private function arelleCommand(string $venv): string { return $venv . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'Scripts\\arelleCmdLine.exe' : 'bin/arelleCmdLine'); }
    private function mustRun(array $arguments, string $message): void { $result = $this->run($arguments, 240); if ($result['exit_code'] !== 0) { throw new \RuntimeException($message . ' ' . trim($result['stderr'])); } }
    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function run(array $arguments, int $timeout): array
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Could not start process.']; }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $stdout = ''; $stderr = ''; $started = microtime(true);
        while (true) { $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]); $state = proc_get_status($process); if (!$state['running']) { break; } if (microtime(true) - $started > $timeout) { proc_terminate($process); $stderr .= ' Process timed out.'; break; } usleep(10000); }
        $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
