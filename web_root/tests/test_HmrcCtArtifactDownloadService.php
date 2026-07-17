<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/bootstrap.php';

use eel_accounts\Service\HmrcCtArtifactDownloadService;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eel-ct600-download-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$file = $root . DIRECTORY_SEPARATOR . 'ct600.xml';
$contents = '<IRenvelope>safe</IRenvelope>';
file_put_contents($file, $contents);
$hash = hash('sha256', $contents);

$repository = new class($hash) {
    public function __construct(private string $hash) {}
    public function fetchOwned(int $submissionId, int $companyId, int $accountingPeriodId, int $ctPeriodId): ?array
    {
        if ([$submissionId, $companyId, $accountingPeriodId, $ctPeriodId] !== [91, 49, 79, 6]) {
            return null;
        }
        return [
            'environment' => 'TIL',
            'ct600_xml_path' => 'packages/ct600.xml',
            'ct600_sha256' => $this->hash,
        ];
    }
};
$storage = new class($file, $contents, $hash) {
    public function __construct(private string $file, private string $contents, private string $hash) {}
    public function readVerified(string $key, string $hash): string
    {
        if ($key !== 'packages/ct600.xml' || !hash_equals($this->hash, $hash)) {
            throw new RuntimeException('verification failed');
        }
        return $this->contents;
    }
    public function resolveForRead(string $key): string { return $this->file; }
};

$service = new HmrcCtArtifactDownloadService($repository, $storage);
$resolved = $service->resolve(91, 49, 79, 6, 'ct600');
if ($resolved['path'] !== $file || $resolved['size_bytes'] !== strlen($contents)
    || !str_contains($resolved['filename'], 'ct-period-6-til-submission-91.xml')) {
    throw new RuntimeException('Owned artifact was not resolved safely.');
}

try {
    $service->resolve(91, 50, 79, 6, 'ct600');
    throw new RuntimeException('Cross-company artifact access was not rejected.');
} catch (DomainException) {
}

try {
    $service->resolve(91, 49, 79, 6, '../manifest');
    throw new RuntimeException('Unknown artifact selector was not rejected.');
} catch (InvalidArgumentException) {
}

@unlink($file);
@rmdir($root);
