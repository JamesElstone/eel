<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HmrcCorporationTaxSubmissionService
{
    public function validatePackage(int $companyId, int $taxYearId, string $mode): array
    {
        $this->ensureSchema();
        $mode = HelperFramework::normaliseEnvironmentMode($mode);
        $draft = $this->createSubmissionDraft($companyId, $taxYearId, $mode);
        $submissionId = (int)($draft['submission_id'] ?? 0);
        $errors = (array)($draft['errors'] ?? []);
        $warnings = [];

        $company = (new CompanyRepository())->fetchCompanyDetails($companyId);
        $taxYear = (new TaxYearRepository())->fetchTaxYear($companyId, $taxYearId);
        if (!is_array($company) || empty($company['is_active'])) {
            $errors[] = 'Company exists and active check failed.';
        }
        if ($taxYear === null) {
            $errors[] = 'Tax year exists and belongs to company check failed.';
        }
        $settings = $companyId > 0 ? (new CompanySettingsStore($companyId))->all() : [];
        if (trim((string)($settings['utr'] ?? '')) === '') {
            $errors[] = 'UTR is missing.';
        }
        if ($taxYear !== null) {
            $days = (new DateTimeImmutable((string)$taxYear['period_start']))->diff(new DateTimeImmutable((string)$taxYear['period_end']))->days + 1;
            if ($days > 365) {
                $errors[] = 'CT period exceeds 12 months; split periods may be required.';
            }
        }

        $tbTotals = (new IxbrlTrialBalanceService())->getTotals($companyId, $taxYearId);
        if ((int)($tbTotals['row_count'] ?? 0) <= 0) {
            $errors[] = 'Trial balance has no posted journal rows.';
        } elseif (empty($tbTotals['is_balanced'])) {
            $errors[] = 'Trial balance is unbalanced.';
        }

        $package = new HmrcSubmissionPackageService();
        $accounts = $package->locateAccountsIxbrl($companyId, $taxYearId);
        $computations = $package->locateComputationsIxbrl($companyId, $taxYearId);
        $ct600 = (new Ct600BuilderService())->buildCt600Xml($companyId, $taxYearId);
        $credentials = (new HmrcApiClient())->credentialsConfigured($mode);
        $warnings = array_merge($warnings, (array)($accounts['warnings'] ?? []), (array)($computations['warnings'] ?? []), (array)($ct600['warnings'] ?? []), $this->companiesHouseComparisonWarnings($companyId, $taxYearId));
        $errors = array_merge($errors, (array)($accounts['errors'] ?? []), (array)($computations['errors'] ?? []), (array)($ct600['errors'] ?? []));
        if (empty($credentials['ok'])) {
            $errors[] = 'HMRC CT600 API credentials are not configured for ' . $mode . '.';
        }

        $validation = [
            'ok' => $errors === [],
            'warnings' => $warnings,
            'errors' => $errors,
            'accounts' => $accounts,
            'computations' => $computations,
            'ct600' => $ct600,
            'mode' => $mode,
        ];

        if ($submissionId > 0) {
            InterfaceDB::prepareExecute(
                'UPDATE hmrc_ct600_submissions
                 SET status = :status,
                     ct600_xml_path = :ct600_xml_path,
                     accounts_ixbrl_path = :accounts_ixbrl_path,
                     computations_ixbrl_path = :computations_ixbrl_path,
                     validation_json = :validation_json
                 WHERE id = :id',
                [
                    'status' => $errors === [] ? 'ready' : 'validation_failed',
                    'ct600_xml_path' => $ct600['path'] ?? null,
                    'accounts_ixbrl_path' => $accounts['path'] ?? null,
                    'computations_ixbrl_path' => $computations['path'] ?? null,
                    'validation_json' => json_encode($validation, JSON_UNESCAPED_SLASHES),
                    'id' => $submissionId,
                ]
            );
            $this->event($submissionId, $errors === [] ? 'success' : 'error', $errors === [] ? 'Package validation passed.' : 'Package validation failed.', $validation);
        }

        return ['success' => $errors === [], 'submission_id' => $submissionId, 'errors' => $errors, 'warnings' => $warnings, 'validation' => $validation];
    }

    public function createSubmissionDraft(int $companyId, int $taxYearId, string $mode): array
    {
        $this->ensureSchema();
        $mode = HelperFramework::normaliseEnvironmentMode($mode);
        if ($companyId <= 0 || $taxYearId <= 0) {
            return ['success' => false, 'errors' => ['Select a company and accounting period.'], 'submission_id' => 0];
        }

        $token = 'draft:' . bin2hex(random_bytes(8));
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct600_submissions (company_id, tax_year_id, mode, status, hmrc_response_summary)
             VALUES (:company_id, :tax_year_id, :mode, :status, :token)',
            ['company_id' => $companyId, 'tax_year_id' => $taxYearId, 'mode' => $mode, 'status' => 'draft', 'token' => $token]
        );
        $id = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM hmrc_ct600_submissions WHERE company_id = :company_id AND tax_year_id = :tax_year_id AND hmrc_response_summary = :token ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'tax_year_id' => $taxYearId, 'token' => $token]
        );
        if ($id > 0) {
            InterfaceDB::prepareExecute('UPDATE hmrc_ct600_submissions SET hmrc_response_summary = NULL WHERE id = :id', ['id' => $id]);
            $this->event($id, 'info', 'Submission draft created.', ['mode' => $mode]);
        }

        return ['success' => $id > 0, 'errors' => $id > 0 ? [] : ['Could not create submission draft.'], 'submission_id' => $id];
    }

    public function submit(int $submissionId, callable $logger): array
    {
        $this->ensureSchema();
        $submission = $this->getSubmission($submissionId);
        if ($submission === null) {
            return ['success' => false, 'errors' => ['Submission draft not found.']];
        }
        InterfaceDB::prepareExecute('UPDATE hmrc_ct600_submissions SET status = :status WHERE id = :id', ['status' => 'submitting', 'id' => $submissionId]);
        $this->event($submissionId, 'info', 'HMRC submission started.');
        $logger('info', 'Building submission envelope.');
        $package = new HmrcSubmissionPackageService();
        $envelope = $package->buildSubmissionEnvelope($submissionId);
        if (empty($envelope['ok'])) {
            return $this->failSubmission($submissionId, (array)($envelope['errors'] ?? ['Could not build package.']));
        }

        $hash = $package->hashPackage($submissionId);
        InterfaceDB::prepareExecute('UPDATE hmrc_ct600_submissions SET package_hash = :hash, request_body_path = :path WHERE id = :id', ['hash' => $hash, 'path' => $envelope['path'], 'id' => $submissionId]);
        $logger('info', 'Package hash: ' . $hash);

        $headers = is_array(json_decode((string)($submission['request_headers_json'] ?? ''), true)) ? json_decode((string)$submission['request_headers_json'], true) : [];
        $response = (new HmrcApiClient())->submitCorporationTaxReturn(['body' => (string)$envelope['body']], (string)$submission['mode'], $headers);
        $logger('info', 'Response status: ' . (int)($response['status_code'] ?? 0));
        $responsePath = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'responses';
        if (!is_dir($responsePath)) {
            mkdir($responsePath, 0775, true);
        }
        $bodyPath = $responsePath . DIRECTORY_SEPARATOR . 'hmrc_ct600_response_' . $submissionId . '.txt';
        file_put_contents($bodyPath, (string)($response['body'] ?? $response['error'] ?? ''));
        $accepted = !empty($response['success']);
        $summary = $this->responseSummary($response);
        InterfaceDB::prepareExecute(
            'UPDATE hmrc_ct600_submissions
             SET status = :status,
                 hmrc_response_code = :response_code,
                 hmrc_response_summary = :summary,
                 hmrc_submission_reference = :reference,
                 hmrc_correlation_id = :correlation,
                 response_headers_json = :response_headers,
                 response_body_path = :response_body_path,
                 submitted_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'status' => $accepted ? 'accepted' : 'rejected',
                'response_code' => (int)($response['status_code'] ?? 0),
                'summary' => $summary,
                'reference' => $this->extractReference($response),
                'correlation' => $this->extractCorrelation($response),
                'response_headers' => json_encode((array)($response['headers'] ?? []), JSON_UNESCAPED_SLASHES),
                'response_body_path' => $bodyPath,
                'id' => $submissionId,
            ]
        );
        $this->event($submissionId, $accepted ? 'success' : 'error', $accepted ? 'HMRC submission accepted.' : 'HMRC submission rejected or failed.', $response);

        return ['success' => $accepted, 'errors' => $accepted ? [] : [$summary], 'response' => $response];
    }

    public function getSubmissionHistory(int $companyId, ?int $taxYearId = null): array
    {
        $this->ensureSchema();
        if ($companyId <= 0) {
            return [];
        }
        $params = ['company_id' => $companyId];
        $where = 's.company_id = :company_id';
        if ($taxYearId !== null && $taxYearId > 0) {
            $where .= ' AND s.tax_year_id = :tax_year_id';
            $params['tax_year_id'] = $taxYearId;
        }

        return InterfaceDB::fetchAll(
            'SELECT s.*, c.company_name, ty.label AS tax_year_label, ty.period_start, ty.period_end
             FROM hmrc_ct600_submissions s
             INNER JOIN companies c ON c.id = s.company_id
             INNER JOIN tax_years ty ON ty.id = s.tax_year_id
             WHERE ' . $where . '
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 50',
            $params
        );
    }

    public function getLatestSubmission(int $companyId, int $taxYearId): ?array
    {
        $history = $this->getSubmissionHistory($companyId, $taxYearId);

        return $history[0] ?? null;
    }

    public function event(int $submissionId, string $level, string $message, array $context = []): void
    {
        $this->ensureSchema();
        if ($submissionId <= 0) {
            return;
        }
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_submission_events (submission_id, event_level, event_message, event_context_json)
             VALUES (:submission_id, :level, :message, :context)',
            [
                'submission_id' => $submissionId,
                'level' => in_array($level, ['debug', 'info', 'warning', 'error', 'success'], true) ? $level : 'info',
                'message' => $message,
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function ensureSchema(): void
    {
        if (!InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS hmrc_ct600_submissions (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    tax_year_id INT NOT NULL,
                    mode ENUM('TEST','LIVE') NOT NULL,
                    status ENUM('draft','validating','validation_failed','ready','submitting','accepted','rejected','failed') NOT NULL,
                    submission_type ENUM('original','amendment') NOT NULL DEFAULT 'original',
                    ct600_xml_path VARCHAR(1000) NULL,
                    accounts_ixbrl_path VARCHAR(1000) NULL,
                    computations_ixbrl_path VARCHAR(1000) NULL,
                    package_hash CHAR(64) NULL,
                    hmrc_submission_reference VARCHAR(255) NULL,
                    hmrc_correlation_id VARCHAR(255) NULL,
                    hmrc_response_code INT NULL,
                    hmrc_response_summary TEXT NULL,
                    request_headers_json LONGTEXT NULL,
                    response_headers_json LONGTEXT NULL,
                    request_body_path VARCHAR(1000) NULL,
                    response_body_path VARCHAR(1000) NULL,
                    validation_json LONGTEXT NULL,
                    submitted_by VARCHAR(100) NULL,
                    submitted_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_hmrc_ct600_company_tax_year (company_id, tax_year_id),
                    KEY idx_hmrc_ct600_mode_status (mode, status),
                    CONSTRAINT fk_hmrc_ct600_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_hmrc_ct600_tax_year FOREIGN KEY (tax_year_id) REFERENCES tax_years(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!InterfaceDB::tableExists('hmrc_submission_events')) {
            InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS hmrc_submission_events (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    submission_id BIGINT NOT NULL,
                    event_level ENUM('debug','info','warning','error','success') NOT NULL DEFAULT 'info',
                    event_message TEXT NOT NULL,
                    event_context_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_hmrc_submission_events_submission (submission_id),
                    CONSTRAINT fk_hmrc_submission_events_submission FOREIGN KEY (submission_id) REFERENCES hmrc_ct600_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!InterfaceDB::tableExists('tax_loss_pools')) {
            InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS tax_loss_pools (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    origin_tax_year_id INT NOT NULL,
                    loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    used_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    notes TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_tax_loss_pools_company (company_id),
                    KEY idx_tax_loss_pools_origin_year (origin_tax_year_id),
                    CONSTRAINT fk_tax_loss_pools_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_tax_loss_pools_origin_year FOREIGN KEY (origin_tax_year_id) REFERENCES tax_years(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    private function getSubmission(int $submissionId): ?array
    {
        $row = InterfaceDB::fetchOne('SELECT * FROM hmrc_ct600_submissions WHERE id = :id LIMIT 1', ['id' => $submissionId]);

        return is_array($row) ? $row : null;
    }

    private function failSubmission(int $submissionId, array $errors): array
    {
        InterfaceDB::prepareExecute(
            'UPDATE hmrc_ct600_submissions SET status = :status, hmrc_response_summary = :summary WHERE id = :id',
            ['status' => 'failed', 'summary' => implode('; ', $errors), 'id' => $submissionId]
        );
        $this->event($submissionId, 'error', 'Submission failed.', ['errors' => $errors]);

        return ['success' => false, 'errors' => $errors];
    }

    private function responseSummary(array $response): string
    {
        $error = trim((string)($response['error'] ?? ''));
        if ($error !== '') {
            return $error;
        }
        $body = trim((string)($response['body'] ?? ''));

        return $body !== '' ? mb_substr($body, 0, 1000) : 'No response body.';
    }

    private function extractReference(array $response): ?string
    {
        $headers = array_change_key_case((array)($response['headers'] ?? []), CASE_LOWER);
        foreach (['x-submission-reference', 'submission-reference', 'x-correlation-id'] as $key) {
            if (trim((string)($headers[$key] ?? '')) !== '') {
                return trim((string)$headers[$key]);
            }
        }

        return null;
    }

    private function extractCorrelation(array $response): ?string
    {
        $headers = array_change_key_case((array)($response['headers'] ?? []), CASE_LOWER);

        return trim((string)($headers['x-correlation-id'] ?? '')) ?: null;
    }

    private function companiesHouseComparisonWarnings(int $companyId, int $taxYearId): array
    {
        $taxYear = (new TaxYearRepository())->fetchTaxYear($companyId, $taxYearId);
        if ($taxYear === null || !InterfaceDB::tableExists('companies_house_document_facts')) {
            return [];
        }
        $count = (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM companies_house_documents d
             WHERE d.company_id = :company_id
               AND d.significant_date = :period_end',
            ['company_id' => $companyId, 'period_end' => (string)$taxYear['period_end']]
        );
        if ($count <= 0) {
            return [];
        }

        return ['Companies House has already been filed for this period. If generated figures differ, amended accounts may be required separately.'];
    }
}
