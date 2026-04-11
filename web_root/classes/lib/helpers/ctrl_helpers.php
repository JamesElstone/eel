<?php
declare(strict_types=1);

function ctrl_config(): array
{
    return FrameWorkHelper::config();
}

function ctrl_normalise_environment_mode(?string $environment): string
{
    $environment = strtoupper(trim((string)$environment));

    return $environment === 'LIVE' ? 'LIVE' : 'TEST';
}

function ctrl_accounting_period_label(DateTimeInterface|string $periodStart, DateTimeInterface|string $periodEnd): string
{
    $start = $periodStart instanceof DateTimeInterface ? $periodStart : new DateTimeImmutable((string)$periodStart);
    $end = $periodEnd instanceof DateTimeInterface ? $periodEnd : new DateTimeImmutable((string)$periodEnd);

    return $start->format('j M Y') . ' to ' . $end->format('j M Y');
}

function ctrl_company_upload_subdirectory(int|string $companyId, string $category, string $uploadsRoot): string
{
    $normalisedRoot = rtrim($uploadsRoot, '\\/');
    $normalisedCompanyId = preg_replace('/[^A-Za-z0-9_-]+/', '', trim((string)$companyId)) ?? '';
    $normalisedCategory = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($category)) ?? '';

    if ($normalisedCompanyId === '' || $normalisedCategory === '') {
        throw new InvalidArgumentException('Company upload path inputs must not be empty.');
    }

    return $normalisedRoot
        . DIRECTORY_SEPARATOR
        . 'company'
        . DIRECTORY_SEPARATOR
        . $normalisedCompanyId
        . DIRECTORY_SEPARATOR
        . $normalisedCategory;
}

function ctrl_company_upload_relative_path(int|string $companyId, string $category, string $filename): string
{
    $normalisedCompanyId = preg_replace('/[^A-Za-z0-9_-]+/', '', trim((string)$companyId)) ?? '';
    $normalisedCategory = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($category)) ?? '';
    $normalisedFilename = ltrim(str_replace(['\\', '/'], '/', trim($filename)), '/');

    if ($normalisedCompanyId === '' || $normalisedCategory === '' || $normalisedFilename === '') {
        throw new InvalidArgumentException('Company upload relative path inputs must not be empty.');
    }

    return 'company/' . $normalisedCompanyId . '/' . $normalisedCategory . '/' . $normalisedFilename;
}

function ctrl_hmrc_runtime_token_get(array $config): ?array
{
    $key = ctrl_hmrc_runtime_token_key($config);
    $tokens = is_array($GLOBALS['ctrl_hmrc_runtime_tokens'] ?? null) ? $GLOBALS['ctrl_hmrc_runtime_tokens'] : [];

    return is_array($tokens[$key] ?? null) ? $tokens[$key] : null;
}

function ctrl_hmrc_runtime_token_set(array $config, string $token, ?int $expiresAt = null): void
{
    $key = ctrl_hmrc_runtime_token_key($config);
    $tokens = is_array($GLOBALS['ctrl_hmrc_runtime_tokens'] ?? null) ? $GLOBALS['ctrl_hmrc_runtime_tokens'] : [];
    $tokens[$key] = [
        'access_token' => $token,
        'expires_at' => $expiresAt,
    ];
    $GLOBALS['ctrl_hmrc_runtime_tokens'] = $tokens;
}

function ctrl_hmrc_runtime_token_key(array $config): string
{
    $mode = ctrl_normalise_environment_mode((string)($config['mode'] ?? 'TEST'));
    $baseUrl = trim((string)($config['base_url'] ?? $config['test_base_url'] ?? $config['live_base_url'] ?? ''));
    $client = trim((string)($config['credential_tag'] ?? 'HMRC'));

    return $mode . '|' . $baseUrl . '|' . $client;
}
