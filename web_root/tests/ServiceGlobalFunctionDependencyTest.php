<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

final class ServiceGlobalFunctionDependencyTestHarness
{
    /** @var list<string> */
    private array $languageConstructs = [
        'array',
        'echo',
        'empty',
        'eval',
        'exit',
        'include',
        'include_once',
        'isset',
        'list',
        'print',
        'require',
        'require_once',
        'unset',
    ];

    public function run(): void
    {
        $serviceFiles = $this->serviceFiles();
        $report = [];

        foreach ($serviceFiles as $serviceFile) {
            $missingFunctions = $this->missingFunctionsForFile($serviceFile);
            if ($missingFunctions === []) {
                continue;
            }

            $report[basename($serviceFile, '.php')] = $missingFunctions;
        }

        if ($report === []) {
            fwrite(STDOUT, "No missing global function dependencies found in service classes.\n");
            return;
        }

        fwrite(STDOUT, "Missing global function dependencies detected:\n");

        foreach ($report as $serviceName => $missingFunctions) {
            fwrite(STDOUT, '- ' . $serviceName . ': ' . implode(', ', $missingFunctions) . "\n");
        }
    }

    /**
     * @return list<string>
     */
    private function serviceFiles(): array
    {
        $files = glob(APP_CLASSES . 'service' . DIRECTORY_SEPARATOR . '*Service.php');

        if ($files === false) {
            throw new RuntimeException('Unable to enumerate service files.');
        }

        sort($files);

        return array_values(
            array_filter(
                $files,
                static fn(string $file): bool => is_file($file)
            )
        );
    }

    /**
     * @return list<string>
     */
    private function missingFunctionsForFile(string $serviceFile): array
    {
        $source = file_get_contents($serviceFile);
        if ($source === false) {
            throw new RuntimeException('Unable to read service file: ' . $serviceFile);
        }

        $tokens = token_get_all($source);
        $calls = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $functionName = $token[1];
            $lowerName = strtolower($functionName);

            if (in_array($lowerName, $this->languageConstructs, true)) {
                continue;
            }

            $previousMeaningful = $this->previousMeaningfulToken($tokens, $index);
            $nextMeaningful = $this->nextMeaningfulToken($tokens, $index);

            if (!$this->isPossibleFunctionCall($previousMeaningful, $nextMeaningful)) {
                continue;
            }

            if (function_exists($functionName)) {
                continue;
            }

            $calls[$lowerName] = $functionName;
        }

        ksort($calls);

        return array_values($calls);
    }

    private function isPossibleFunctionCall(mixed $previousMeaningful, mixed $nextMeaningful): bool
    {
        if ($nextMeaningful !== '(') {
            return false;
        }

        if (!is_array($previousMeaningful)) {
            return $previousMeaningful !== '->' && $previousMeaningful !== '::' && $previousMeaningful !== '\\';
        }

        return !in_array(
            $previousMeaningful[0],
            [
                T_FUNCTION,
                T_NEW,
                T_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
                T_NAME_QUALIFIED,
                T_NAME_FULLY_QUALIFIED,
                T_NAME_RELATIVE,
            ],
            true
        );
    }

    private function previousMeaningfulToken(array $tokens, int $index): mixed
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function nextMeaningfulToken(array $tokens, int $index): mixed
    {
        $count = count($tokens);

        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token : $token;
        }

        return null;
    }
}

(new ServiceGlobalFunctionDependencyTestHarness())->run();
