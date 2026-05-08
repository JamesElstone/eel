<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class FirstUserBootstrapService
{
    public function __construct(private readonly string $appName)
    {
    }

    public function codeState(): array
    {
        $path = $this->codePath();

        if (!is_file($path)) {
            $code = HelperFramework::generateBootstrapCode();
            $payload = $this->appName . " bootstrap code\n\nCode: " . $code . "\n";

            if (@file_put_contents($path, $payload, LOCK_EX) === false || !is_file($path)) {
                throw new RuntimeException('The bootstrap code file could not be created. Check /secure/bootstrap_code.txt permissions.');
            }
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('The bootstrap code file could not be read. Check /secure/bootstrap_code.txt.');
        }

        if (preg_match('/Code:\s*([0-9A-Fa-f\s]+)/', $contents, $matches) !== 1) {
            throw new RuntimeException('The bootstrap code file does not contain a valid bootstrap code.');
        }

        $storedCode = trim((string)($matches[1] ?? ''));
        if ($storedCode === '' || !HelperFramework::isValidBootstrapCodeFormat($storedCode)) {
            throw new RuntimeException('The bootstrap code file does not contain a valid bootstrap code.');
        }

        return [
            'path' => $path,
            'code' => $storedCode,
        ];
    }

    public function validateCode(string $enteredCode, ?array $bootstrapState): ?string
    {
        if ($bootstrapState === null) {
            return 'Bootstrap code validation is unavailable.';
        }

        if (!HelperFramework::isValidBootstrapCodeFormat($enteredCode)) {
            return 'Bootstrap code must contain only hexadecimal characters and spaces.';
        }

        if (!HelperFramework::bootstrapCodeMatches($enteredCode, (string)($bootstrapState['code'] ?? ''))) {
            return 'Bootstrap code was not recognised.';
        }

        return null;
    }

    public function deleteCodeFile(?array $bootstrapState): void
    {
        $path = is_array($bootstrapState) ? trim((string)($bootstrapState['path'] ?? '')) : '';

        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('The bootstrap code file could not be removed after first-user creation.');
        }

        if (!@unlink($path) && is_file($path)) {
            throw new RuntimeException('The bootstrap code file could not be removed after first-user creation.');
        }
    }

    public function codePath(): string
    {
        $appRoot = rtrim(APP_ROOT, '\\/');
        $repositoryRoot = dirname($appRoot);

        return $repositoryRoot . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'bootstrap_code.txt';
    }
}
