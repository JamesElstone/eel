<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

final class GoldenFilingArtifactReviewPack
{
    public const EXPORT_FLAG = '--export-filing-artifacts';
    public const EXPECTED_ACCOUNTING_PERIODS = 4;
    public const EXPECTED_CT_PERIODS = 5;
    public const EXPECTED_PRIMARY_ARTIFACTS = 18;

    /** @var list<array<string,mixed>> */
    private array $records = [];
    /** @var array<int,array<string,mixed>> */
    private array $periods = [];
    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly string $uploadRoot,
        private readonly string $outputRoot,
        private readonly string $runId
    ) {
        if (preg_match('/^\d{8}T\d{6}Z-[a-f0-9]{8}$/D', $runId) !== 1) {
            throw new InvalidArgumentException('The Golden artefact review run identifier is invalid.');
        }
    }

    /** @param list<string> $arguments */
    public static function requested(array $arguments): bool
    {
        return in_array(self::EXPORT_FLAG, $arguments, true);
    }

    public static function create(string $uploadRoot, string $outputRoot): self
    {
        return new self(
            $uploadRoot,
            $outputRoot,
            gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4))
        );
    }

    public function stagingDirectory(string $artifactKind): string
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $artifactKind) !== 1) {
            throw new InvalidArgumentException('The Golden artefact staging kind is invalid.');
        }
        $runDirectory = $this->join(
            $this->uploadRoot,
            'golden-filing-artifacts-' . $this->runId
        );
        $directory = $this->join($runDirectory, $artifactKind);
        $this->ensureDirectory($directory);
        test_register_cleanup_path($runDirectory);

        return $directory;
    }

    /** @param array<string,mixed> $result */
    public function captureForIds(
        int $companyId,
        int $accountingPeriodId,
        array $result,
        bool $includeCompaniesHouseProfitLoss = false
    ): void
    {
        $company = InterfaceDB::fetchOne(
            'SELECT id, company_name, company_number AS companies_house_number
             FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        );
        $period = InterfaceDB::fetchOne(
            'SELECT id, label, period_start, period_end FROM accounting_periods
             WHERE company_id = :company_id AND id = :id LIMIT 1',
            ['company_id' => $companyId, 'id' => $accountingPeriodId]
        );
        $ctPeriods = InterfaceDB::fetchAll(
            'SELECT id, sequence_no, period_start, period_end
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status <> :superseded
             ORDER BY sequence_no, id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'superseded' => 'superseded',
            ]
        );
        if (!is_array($company) || !is_array($period)) {
            $this->errors[] = 'Accounting period #' . $accountingPeriodId
                . ' could not be described for the review pack.';
            return;
        }
        $this->capturePeriod(
            $company,
            $period,
            $ctPeriods,
            $result,
            $includeCompaniesHouseProfitLoss
        );
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $period
     * @param list<array<string,mixed>> $ctPeriods
     * @param array<string,mixed> $result
     */
    public function capturePeriod(
        array $company,
        array $period,
        array $ctPeriods,
        array $result,
        bool $includeCompaniesHouseProfitLoss = false
    ): void
    {
        $accountingPeriodId = (int)($period['id'] ?? 0);
        if ($accountingPeriodId <= 0 || isset($this->periods[$accountingPeriodId])) {
            throw new InvalidArgumentException('Each valid Golden accounting period may be captured once.');
        }
        $periodContext = [
            'company_id' => (int)($company['id'] ?? 0),
            'company_name' => (string)($company['company_name'] ?? ''),
            'company_number' => (string)($company['companies_house_number'] ?? ''),
            'accounting_period_id' => $accountingPeriodId,
            'label' => (string)($period['label'] ?? ''),
            'period_start' => (string)($period['period_start'] ?? ''),
            'period_end' => (string)($period['period_end'] ?? ''),
            'outcome' => (string)($result['outcome'] ?? 'failed'),
            'errors' => $this->messages((array)($result['errors'] ?? [])),
            'warnings' => $this->messages((array)($result['warnings'] ?? [])),
        ];
        $this->periods[$accountingPeriodId] = $periodContext;
        $stages = (array)($result['stages'] ?? []);
        $this->captureStage('hmrc_accounts_ixbrl', (array)($stages['hmrc_accounts'] ?? []), $periodContext);
        $this->captureStage(
            'companies_house_accounts_ixbrl',
            (array)($stages['companies_house_accounts'] ?? []),
            $periodContext,
            [],
            $includeCompaniesHouseProfitLoss ? 'included' : 'omitted'
        );

        $computationStages = (array)($stages['hmrc_computations'] ?? []);
        $ct600Stages = (array)($stages['hmrc_ct600'] ?? []);
        foreach ($ctPeriods as $ctPeriod) {
            $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
            $ctContext = [
                'ct_period_id' => $ctPeriodId,
                'sequence_no' => (int)($ctPeriod['sequence_no'] ?? 0),
                'ct_period_start' => (string)($ctPeriod['period_start'] ?? ''),
                'ct_period_end' => (string)($ctPeriod['period_end'] ?? ''),
            ];
            $this->captureStage(
                'hmrc_computation_ixbrl',
                (array)($computationStages[$ctPeriodId] ?? []),
                $periodContext,
                $ctContext
            );
            $this->captureStage(
                'ct600_xml',
                (array)($ct600Stages[$ctPeriodId] ?? []),
                $periodContext,
                $ctContext
            );
        }
    }

    /** @return array{success:bool,index_path:string,manifest_path:string,artifact_count:int,errors:list<string>} */
    public function publish(): array
    {
        $runDirectory = $this->join($this->outputRoot, $this->runId);
        $this->ensureDirectory($runDirectory);
        $published = [];
        foreach ($this->records as $index => $record) {
            $published[] = $this->publishRecord($runDirectory, $record, $index);
        }

        ksort($this->periods);
        $successful = array_values(array_filter(
            $published,
            static fn(array $record): bool => (string)($record['status'] ?? '') === 'passed'
                && trim((string)($record['relative_path'] ?? '')) !== ''
        ));
        $failed = array_values(array_filter(
            $published,
            static fn(array $record): bool => (string)($record['outcome'] ?? '') !== 'missing'
                && ((string)($record['status'] ?? '') !== 'passed'
                    || trim((string)($record['relative_path'] ?? '')) === '')
        ));
        $missing = array_values(array_filter(
            $published,
            static fn(array $record): bool => (string)($record['outcome'] ?? '') === 'missing'
        ));
        $complete = count($this->periods) === self::EXPECTED_ACCOUNTING_PERIODS
            && count($this->ctPeriodIds()) === self::EXPECTED_CT_PERIODS
            && count($published) === self::EXPECTED_PRIMARY_ARTIFACTS
            && count($successful) === self::EXPECTED_PRIMARY_ARTIFACTS
            && $this->errors === [];
        if (count($this->periods) !== self::EXPECTED_ACCOUNTING_PERIODS) {
            $this->errors[] = 'Expected ' . self::EXPECTED_ACCOUNTING_PERIODS
                . ' accounting periods; captured ' . count($this->periods) . '.';
        }
        if (count($this->ctPeriodIds()) !== self::EXPECTED_CT_PERIODS) {
            $this->errors[] = 'Expected ' . self::EXPECTED_CT_PERIODS
                . ' CT periods; captured ' . count($this->ctPeriodIds()) . '.';
        }
        if (count($published) !== self::EXPECTED_PRIMARY_ARTIFACTS) {
            $this->errors[] = 'Expected ' . self::EXPECTED_PRIMARY_ARTIFACTS
                . ' primary artefacts; captured ' . count($published) . '.';
        }
        if (count($successful) !== self::EXPECTED_PRIMARY_ARTIFACTS) {
            $this->errors[] = 'Only ' . count($successful) . ' of '
                . self::EXPECTED_PRIMARY_ARTIFACTS . ' primary artefacts passed validation.';
        }
        $this->errors = $this->messages($this->errors);
        $complete = $complete && $this->errors === [];

        $manifest = [
            'schema_version' => 1,
            'run_id' => $this->runId,
            'generated_at_utc' => gmdate('c'),
            'status' => $complete ? 'complete' : 'partial',
            'expected' => [
                'accounting_periods' => self::EXPECTED_ACCOUNTING_PERIODS,
                'ct_periods' => self::EXPECTED_CT_PERIODS,
                'primary_artifacts' => self::EXPECTED_PRIMARY_ARTIFACTS,
            ],
            'actual' => [
                'accounting_periods' => count($this->periods),
                'ct_periods' => count($this->ctPeriodIds()),
                'captured_stages' => count($published),
                'primary_artifacts' => count(array_filter(
                    $published,
                    static fn(array $record): bool => trim((string)($record['relative_path'] ?? '')) !== ''
                )),
                'passed_artifacts' => count($successful),
                'failed_stages' => count($failed),
                'missing_stages' => count($missing),
            ],
            'stages' => [
                'expected' => array_map([$this, 'stageSummary'], $published),
                'successful' => array_map([$this, 'stageSummary'], $successful),
                'failed' => array_map([$this, 'stageSummary'], $failed),
                'missing' => array_map([$this, 'stageSummary'], $missing),
            ],
            'errors' => $this->errors,
            'periods' => array_values($this->periods),
            'artifacts' => $published,
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        $manifestPath = $this->join($runDirectory, 'manifest.json');
        $this->writeFile($manifestPath, $manifestJson);
        $indexPath = $this->join($runDirectory, 'index.html');
        $this->writeFile($indexPath, $this->renderIndex($manifest));
        $this->writeFile($this->join($this->outputRoot, 'latest.txt'), $indexPath . PHP_EOL);

        return [
            'success' => $complete,
            'index_path' => $indexPath,
            'manifest_path' => $manifestPath,
            'artifact_count' => count($successful),
            'errors' => $this->errors,
        ];
    }

    /** @param array<string,mixed> $period @param array<string,mixed> $ctPeriod */
    private function captureStage(
        string $kind,
        array $stage,
        array $period,
        array $ctPeriod = [],
        string $profitLossDelivery = ''
    ): void
    {
        $artifact = (array)($stage['artifact'] ?? []);
        $record = array_merge($period, $ctPeriod, [
            'kind' => $kind,
            'authority' => (string)($artifact['authority'] ?? ($kind === 'companies_house_accounts_ixbrl'
                ? 'COMPANIES_HOUSE' : 'HMRC')),
            'outcome' => (string)($stage['outcome'] ?? 'missing'),
            'status' => (string)($artifact['validation_status'] ?? ''),
            'original_filename' => (string)($artifact['filename'] ?? ''),
            'source_path' => (string)($artifact['path'] ?? ''),
            'sha256' => strtolower((string)($artifact['sha256'] ?? '')),
            'validation_log_path' => (string)($artifact['validation_log_path'] ?? ''),
            'validation' => (array)($artifact['validation'] ?? []),
            'validation_json' => (string)($artifact['validation_json'] ?? ''),
            'errors' => $this->messages((array)($stage['errors'] ?? [])),
            'warnings' => $this->messages((array)($stage['warnings'] ?? [])),
        ]);
        if ($kind === 'companies_house_accounts_ixbrl') {
            $record['profit_loss_delivery'] = $profitLossDelivery;
        }
        try {
            $record['_bytes'] = $this->readVerifiedArtifact(
                (string)$record['source_path'],
                (string)$record['sha256']
            );
            test_register_cleanup_path((string)$record['source_path']);
            $logPath = trim((string)$record['validation_log_path']);
            if ($logPath !== '') {
                $record['_validation_log_bytes'] = $this->readContainedFile($logPath);
                test_register_cleanup_path($logPath);
            }
        } catch (Throwable $exception) {
            $record['errors'][] = $exception->getMessage();
            $record['errors'] = $this->messages((array)$record['errors']);
            $this->errors[] = $this->recordLabel($record) . ': ' . $exception->getMessage();
        }
        if ((string)$record['outcome'] !== 'succeeded') {
            $this->errors[] = $this->recordLabel($record) . ' outcome was '
                . (string)$record['outcome'] . '.';
        }
        if ((string)$record['status'] !== 'passed') {
            $this->errors[] = $this->recordLabel($record) . ' validation status was '
                . ((string)$record['status'] !== '' ? (string)$record['status'] : 'missing') . '.';
        }
        $this->records[] = $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function stageSummary(array $record): array
    {
        $summary = [
            'accounting_period_id' => (int)($record['accounting_period_id'] ?? 0),
            'ct_period_id' => isset($record['ct_period_id']) ? (int)$record['ct_period_id'] : null,
            'authority' => (string)($record['authority'] ?? ''),
            'kind' => (string)($record['kind'] ?? ''),
            'outcome' => (string)($record['outcome'] ?? 'missing'),
            'validation_status' => (string)($record['status'] ?? ''),
            'relative_path' => (string)($record['relative_path'] ?? ''),
            'errors' => array_values((array)($record['errors'] ?? [])),
            'warnings' => array_values((array)($record['warnings'] ?? [])),
        ];
        if (isset($record['profit_loss_delivery'])) {
            $summary['profit_loss_delivery'] = (string)$record['profit_loss_delivery'];
        }
        return $summary;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function publishRecord(string $runDirectory, array $record, int $index): array
    {
        $periodDirectory = 'AP-' . (int)$record['accounting_period_id'] . '_'
            . $this->dateSegment((string)$record['period_start']) . '_to_'
            . $this->dateSegment((string)$record['period_end']);
        $relativeDirectory = $periodDirectory;
        if ((int)($record['ct_period_id'] ?? 0) > 0) {
            $relativeDirectory .= DIRECTORY_SEPARATOR . 'CTP-' . (int)$record['ct_period_id'] . '_'
                . $this->dateSegment((string)$record['ct_period_start']) . '_to_'
                . $this->dateSegment((string)$record['ct_period_end']);
        }
        $directory = $this->join($runDirectory, $relativeDirectory);
        $this->ensureDirectory($directory);
        $filename = match ((string)$record['kind']) {
            'hmrc_accounts_ixbrl' => 'hmrc-accounts.xhtml',
            'companies_house_accounts_ixbrl' => match ((string)($record['profit_loss_delivery'] ?? '')) {
                'included' => 'companies-house-accounts-with-profit-and-loss.xhtml',
                'omitted' => 'companies-house-accounts-without-profit-and-loss.xhtml',
                default => 'companies-house-accounts.xhtml',
            },
            'hmrc_computation_ixbrl' => 'hmrc-computation.xhtml',
            'ct600_xml' => 'ct600.xml',
            default => 'artifact-' . ($index + 1) . '.bin',
        };
        $relativePath = '';
        if (isset($record['_bytes']) && is_string($record['_bytes'])) {
            $path = $this->join($directory, $filename);
            $this->writeFile($path, $record['_bytes']);
            $relativePath = $this->relativePath($runDirectory, $path);
        }

        $validationDirectory = $this->join($directory, 'validation');
        $validationLogRelativePath = '';
        if (isset($record['_validation_log_bytes']) && is_string($record['_validation_log_bytes'])) {
            $this->ensureDirectory($validationDirectory);
            $logPath = $this->join($validationDirectory, pathinfo($filename, PATHINFO_FILENAME) . '.log');
            $this->writeFile($logPath, $record['_validation_log_bytes']);
            $validationLogRelativePath = $this->relativePath($runDirectory, $logPath);
        }
        $validationEvidence = (array)($record['validation'] ?? []);
        $validationJson = trim((string)($record['validation_json'] ?? ''));
        if ($validationEvidence !== [] || $validationJson !== '') {
            $this->ensureDirectory($validationDirectory);
            if ($validationEvidence === [] && $validationJson !== '') {
                $decoded = json_decode($validationJson, true);
                $validationEvidence = is_array($decoded) ? $decoded : ['raw' => $validationJson];
            }
            $evidencePath = $this->join(
                $validationDirectory,
                pathinfo($filename, PATHINFO_FILENAME) . '.validation.json'
            );
            $this->writeFile(
                $evidencePath,
                json_encode(
                    $validationEvidence,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . PHP_EOL
            );
            $record['validation_evidence_path'] = $this->relativePath($runDirectory, $evidencePath);
        } else {
            $record['validation_evidence_path'] = '';
        }
        $record['relative_path'] = $relativePath;
        $record['validation_log_relative_path'] = $validationLogRelativePath;
        unset(
            $record['_bytes'],
            $record['_validation_log_bytes'],
            $record['source_path'],
            $record['validation_log_path'],
            $record['validation_json']
        );
        return $record;
    }

    /** @param array<string,mixed> $manifest */
    private function renderIndex(array $manifest): string
    {
        $rows = '';
        foreach ((array)$manifest['artifacts'] as $artifact) {
            $artifact = (array)$artifact;
            $path = (string)($artifact['relative_path'] ?? '');
            $link = $path !== ''
                ? '<a href="' . $this->escape(str_replace(DIRECTORY_SEPARATOR, '/', $path)) . '">open artefact</a>'
                : '<span class="missing">missing</span>';
            $evidencePath = (string)($artifact['validation_evidence_path'] ?? '');
            $evidence = $evidencePath !== ''
                ? '<a href="' . $this->escape(str_replace(DIRECTORY_SEPARATOR, '/', $evidencePath)) . '">evidence</a>'
                : '—';
            $errors = implode(' ', (array)($artifact['errors'] ?? []));
            $rows .= '<tr><td>' . $this->escape((string)$artifact['accounting_period_id']) . '</td>'
                . '<td>' . $this->escape((string)($artifact['ct_period_id'] ?? '—')) . '</td>'
                . '<td>' . $this->escape((string)$artifact['kind']) . '</td>'
                . '<td>' . $this->escape((string)($artifact['profit_loss_delivery'] ?? '—')) . '</td>'
                . '<td>' . $this->escape((string)$artifact['status']) . '</td>'
                . '<td>' . $link . '</td><td>' . $evidence . '</td>'
                . '<td><code>' . $this->escape((string)$artifact['sha256']) . '</code></td>'
                . '<td>' . $this->escape($errors) . '</td></tr>';
        }
        $status = (string)$manifest['status'];
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Golden filing artefact review</title><style>'
            . 'body{font-family:system-ui,sans-serif;margin:2rem;color:#17202a}table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #ccd1d1;padding:.55rem;text-align:left;vertical-align:top}th{background:#f4f6f7}'
            . 'code{font-size:.78rem;word-break:break-all}.complete{color:#176b36}.partial,.missing{color:#a93226}'
            . '</style></head><body><h1>Golden filing artefact review</h1><p>Run <code>'
            . $this->escape((string)$manifest['run_id']) . '</code> — <strong class="' . $this->escape($status) . '">'
            . $this->escape($status) . '</strong></p><p><a href="manifest.json">Open manifest</a></p>'
            . '<table><thead><tr><th>AP</th><th>CT period</th><th>Artefact</th><th>P&amp;L delivery</th><th>Validation</th>'
            . '<th>File</th><th>Evidence</th><th>SHA-256</th><th>Errors</th></tr></thead><tbody>'
            . $rows . '</tbody></table></body></html>';
    }

    private function readVerifiedArtifact(string $path, string $expectedHash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
            throw new RuntimeException('The recorded artefact SHA-256 is missing or invalid.');
        }
        $bytes = $this->readContainedFile($path);
        $actualHash = hash('sha256', $bytes);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('The generated artefact does not match its recorded SHA-256.');
        }
        return $bytes;
    }

    private function readContainedFile(string $path): string
    {
        $root = realpath($this->uploadRoot);
        $resolved = realpath($path);
        if (!is_string($root) || !is_string($resolved) || !is_file($resolved)) {
            throw new RuntimeException('A generated review file could not be found.');
        }
        $rootKey = strtolower(rtrim(str_replace('\\', '/', $root), '/')) . '/';
        $pathKey = strtolower(str_replace('\\', '/', $resolved));
        if (!str_starts_with($pathKey, $rootKey)) {
            throw new RuntimeException('A generated review file is outside the configured test upload root.');
        }
        $bytes = file_get_contents($resolved);
        if (!is_string($bytes)) {
            throw new RuntimeException('A generated review file could not be read.');
        }
        return $bytes;
    }

    /** @return list<int> */
    private function ctPeriodIds(): array
    {
        $ids = [];
        foreach ($this->records as $record) {
            $id = (int)($record['ct_period_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        sort($ids);
        return array_values($ids);
    }

    /** @param array<string,mixed> $record */
    private function recordLabel(array $record): string
    {
        return 'AP ' . (int)($record['accounting_period_id'] ?? 0)
            . ((int)($record['ct_period_id'] ?? 0) > 0
                ? ' / CT period ' . (int)$record['ct_period_id'] : '')
            . ' / ' . (string)($record['kind'] ?? 'artefact');
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create Golden artefact review directory: ' . $path);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not write Golden artefact review file: ' . $path);
        }
    }

    private function join(string ...$parts): string
    {
        $path = array_shift($parts) ?? '';
        foreach ($parts as $part) {
            $path = rtrim($path, '\\/') . DIRECTORY_SEPARATOR . ltrim($part, '\\/');
        }
        return $path;
    }

    private function relativePath(string $root, string $path): string
    {
        return ltrim(substr($path, strlen(rtrim($root, '\\/'))), '\\/');
    }

    private function dateSegment(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? $date : 'unknown-date';
    }

    /** @param array<mixed> $messages @return list<string> */
    private function messages(array $messages): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $message): string => trim((string)$message),
            $messages
        ))));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
