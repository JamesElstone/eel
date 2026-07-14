<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class HmrcCorporationTaxSubmissionService
{
    public function validatePackage(int $companyId, int $ctPeriodId, string $mode): array
    {
        $this->ensureSchema();
        $mode = \HelperFramework::normaliseEnvironmentMode($mode);
        $draft = $this->createSubmissionDraft($companyId, $ctPeriodId, $mode);
        $submissionId = (int)($draft['submission_id'] ?? 0);
        $errors = (array)($draft['errors'] ?? []);
        $warnings = [];

        $company = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
        $ctPeriodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $ctPeriod = $ctPeriodService->fetch($companyId, $ctPeriodId);
        $accountingPeriodId = (int)($ctPeriod['accounting_period_id'] ?? 0);
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($company) || empty($company['is_active'])) {
            $errors[] = 'Company exists and active check failed.';
        }
        if ($accountingPeriod === null) {
            $errors[] = 'Accounting period exists and belongs to company check failed.';
        }
        if ($ctPeriod === null) {
            $errors[] = 'CT period exists and belongs to company check failed.';
        }
        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];
        if (trim((string)($settings['utr'] ?? '')) === '') {
            $errors[] = 'UTR is missing.';
        }
        if ($ctPeriod !== null) {
            $days = (new \DateTimeImmutable((string)$ctPeriod['period_start']))->diff(new \DateTimeImmutable((string)$ctPeriod['period_end']))->days + 1;
            if ($days > 365) {
                $errors[] = 'CT period exceeds 12 months.';
            }
        }
        $sequence = $ctPeriodService->canSubmit($companyId, $ctPeriodId);
        $errors = array_merge($errors, (array)($sequence['errors'] ?? []));
        if ($ctPeriod !== null) {
            $computation = (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
            $persistence = (array)($computation['computation_persistence'] ?? []);
            if (empty($computation['available'])) {
                $errors[] = 'A current CT computation could not be built for submission.';
            } elseif (empty($persistence['current'])) {
                $errors[] = (string)($persistence['status'] ?? '') === 'stale'
                    ? 'The latest persisted CT computation is stale. Refresh the Year End review and complete the final close before validating a submission.'
                    : 'Complete the final Year End close to persist the reviewed CT computation before validating a submission.';
            }
        }

        $tbTotals = (new \eel_accounts\Service\IxbrlTrialBalanceService())->getTotals($companyId, $accountingPeriodId);
        if ((int)($tbTotals['row_count'] ?? 0) <= 0) {
            $errors[] = 'Trial balance has no posted journal rows.';
        } elseif (empty($tbTotals['is_balanced'])) {
            $errors[] = 'Trial balance is unbalanced.';
        }

        $package = new \eel_accounts\Service\HmrcSubmissionPackageService();
        $accounts = $package->locateAccountsIxbrl($companyId, $accountingPeriodId);
        $computations = $package->locateComputationsIxbrlForCtPeriod($companyId, $ctPeriodId);
        $ct600 = (new \eel_accounts\Service\Ct600BuilderService())->buildCt600XmlForCtPeriod($companyId, $ctPeriodId);
        $credentials = (new \eel_accounts\Client\HmrcApiClient())->credentialsConfigured($mode);
        $warnings = array_merge($warnings, (array)($accounts['warnings'] ?? []), (array)($computations['warnings'] ?? []), (array)($ct600['warnings'] ?? []), $this->companiesHouseComparisonWarnings($companyId, $accountingPeriodId));
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
            \InterfaceDB::prepareExecute(
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
            (new \eel_accounts\Service\CorporationTaxPeriodService())->markLatestSubmission($ctPeriodId, $submissionId, $errors === [] ? 'ready' : 'validation_failed');
            $this->event($submissionId, $errors === [] ? 'success' : 'error', $errors === [] ? 'Package validation passed.' : 'Package validation failed.', $validation);
        }

        return ['success' => $errors === [], 'submission_id' => $submissionId, 'errors' => $errors, 'warnings' => $warnings, 'validation' => $validation];
    }

    public function createSubmissionDraft(int $companyId, int $ctPeriodId, string $mode): array
    {
        $this->ensureSchema();
        $mode = \HelperFramework::normaliseEnvironmentMode($mode);
        $ctPeriod = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
        $accountingPeriodId = (int)($ctPeriod['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $ctPeriodId <= 0 || $accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['Select a company, accounting period, and CT period.'], 'submission_id' => 0];
        }

        $token = 'draft:' . bin2hex(random_bytes(8));
        \InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct600_submissions (company_id, accounting_period_id, ct_period_id, mode, status, hmrc_response_summary)
             VALUES (:company_id, :accounting_period_id, :ct_period_id, :mode, :status, :token)',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'ct_period_id' => $ctPeriodId, 'mode' => $mode, 'status' => 'draft', 'token' => $token]
        );
        $id = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM hmrc_ct600_submissions WHERE company_id = :company_id AND ct_period_id = :ct_period_id AND hmrc_response_summary = :token ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'ct_period_id' => $ctPeriodId, 'token' => $token]
        );
        if ($id > 0) {
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct600_submissions SET hmrc_response_summary = NULL WHERE id = :id', ['id' => $id]);
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
        $ctPeriodId = (int)($submission['ct_period_id'] ?? 0);
        $sequence = (new \eel_accounts\Service\CorporationTaxPeriodService())->canSubmit((int)$submission['company_id'], $ctPeriodId);
        if (empty($sequence['ok'])) {
            return ['success' => false, 'errors' => (array)($sequence['errors'] ?? ['Earlier CT periods must be completed first.'])];
        }
        $computation = (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId((int)$submission['company_id'], $ctPeriodId);
        $persistence = (array)($computation['computation_persistence'] ?? []);
        if (empty($computation['available']) || empty($persistence['current'])) {
            return [
                'success' => false,
                'errors' => [
                    (string)($persistence['status'] ?? '') === 'stale'
                        ? 'Submission blocked because the persisted CT computation is stale. Refresh the Year End review and complete the final close.'
                        : 'Submission blocked until the final Year End close persists the reviewed CT computation.',
                ],
            ];
        }
        \InterfaceDB::prepareExecute('UPDATE hmrc_ct600_submissions SET status = :status WHERE id = :id', ['status' => 'submitting', 'id' => $submissionId]);
        (new \eel_accounts\Service\CorporationTaxPeriodService())->markLatestSubmission($ctPeriodId, $submissionId, 'submitting');
        $this->event($submissionId, 'info', 'HMRC submission started.');
        $logger('info', 'Building submission envelope.');
        $package = new \eel_accounts\Service\HmrcSubmissionPackageService();
        $envelope = $package->buildSubmissionEnvelope($submissionId);
        if (empty($envelope['ok'])) {
            return $this->failSubmission($submissionId, (array)($envelope['errors'] ?? ['Could not build package.']));
        }

        $hash = $package->hashPackage($submissionId);
        \InterfaceDB::prepareExecute('UPDATE hmrc_ct600_submissions SET package_hash = :hash, request_body_path = :path WHERE id = :id', ['hash' => $hash, 'path' => $envelope['path'], 'id' => $submissionId]);
        $logger('info', 'Package hash: ' . $hash);

        $headers = is_array(json_decode((string)($submission['request_headers_json'] ?? ''), true)) ? json_decode((string)$submission['request_headers_json'], true) : [];
        $response = (new \eel_accounts\Client\HmrcApiClient())->submitCorporationTaxReturn(['body' => (string)$envelope['body']], (string)$submission['mode'], $headers);
        $logger('info', 'Response status: ' . (int)($response['status_code'] ?? 0));
        $responsePath = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'responses';
        if (!is_dir($responsePath)) {
            mkdir($responsePath, 0775, true);
        }
        $bodyPath = $responsePath . DIRECTORY_SEPARATOR . 'hmrc_ct600_response_' . $submissionId . '.txt';
        file_put_contents($bodyPath, (string)($response['body'] ?? $response['error'] ?? ''));
        $accepted = !empty($response['success']);
        $summary = $this->responseSummary($response);
        \InterfaceDB::prepareExecute(
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
        (new \eel_accounts\Service\CorporationTaxPeriodService())->markLatestSubmission($ctPeriodId, $submissionId, $accepted ? 'accepted' : 'rejected');

        return ['success' => $accepted, 'errors' => $accepted ? [] : [$summary], 'response' => $response];
    }

    public function getSubmissionHistory(int $companyId, ?int $accountingPeriodId = null): array
    {
        $this->ensureSchema();
        if ($companyId <= 0) {
            return [];
        }
        $params = ['company_id' => $companyId];
        $where = 's.company_id = :company_id';
        if ($accountingPeriodId !== null && $accountingPeriodId > 0) {
            $where .= ' AND s.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        return \InterfaceDB::fetchAll(
            'SELECT s.*, c.company_name, ap.label AS accounting_period_label, ap.period_start AS accounting_period_start, ap.period_end AS accounting_period_end,
                    ctp.sequence_no AS ct_period_sequence_no, ctp.period_start, ctp.period_end
             FROM hmrc_ct600_submissions s
             INNER JOIN companies c ON c.id = s.company_id
             INNER JOIN accounting_periods ap ON ap.id = s.accounting_period_id
             LEFT JOIN corporation_tax_periods ctp ON ctp.id = s.ct_period_id
             WHERE ' . $where . '
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 50',
            $params
        );
    }

    public function getLatestSubmission(int $companyId, int $accountingPeriodId): ?array
    {
        $history = $this->getSubmissionHistory($companyId, $accountingPeriodId);

        return $history[0] ?? null;
    }

    public function getLatestSubmissionForCtPeriod(int $companyId, int $ctPeriodId): ?array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM hmrc_ct600_submissions
             WHERE company_id = :company_id
               AND ct_period_id = :ct_period_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'ct_period_id' => $ctPeriodId]
        );

        return is_array($row) ? $row : null;
    }

    public function event(int $submissionId, string $level, string $message, array $context = []): void
    {
        $this->ensureSchema();
        if ($submissionId <= 0) {
            return;
        }
        \InterfaceDB::prepareExecute(
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
        (new \eel_accounts\Service\CorporationTaxPeriodService())->ensureSchema();
        if (!\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS hmrc_ct600_submissions (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    accounting_period_id INT NOT NULL,
                    ct_period_id INT NULL,
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
                    KEY idx_hmrc_ct600_company_accounting_period (company_id, accounting_period_id),
                    KEY idx_hmrc_ct600_ct_period (ct_period_id),
                    KEY idx_hmrc_ct600_mode_status (mode, status),
                    CONSTRAINT fk_hmrc_ct600_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_hmrc_ct600_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_hmrc_ct600_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods(id) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!\InterfaceDB::tableExists('hmrc_submission_events')) {
            \InterfaceDB::prepareExecute(
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
        if (!\InterfaceDB::tableExists('tax_loss_carryforwards')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS tax_loss_carryforwards (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    origin_accounting_period_id INT NOT NULL,
                    origin_ct_period_id INT NULL,
                    amount_originated DECIMAL(12,2) NOT NULL,
                    amount_used DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    amount_remaining DECIMAL(12,2) NOT NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'open',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_tax_loss_origin (company_id, origin_accounting_period_id),
                    KEY idx_tax_loss_origin_ct_period (origin_ct_period_id),
                    KEY fk_tax_loss_accounting_period (origin_accounting_period_id),
                    CONSTRAINT fk_tax_loss_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_tax_loss_accounting_period FOREIGN KEY (origin_accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_tax_loss_origin_ct_period FOREIGN KEY (origin_ct_period_id) REFERENCES corporation_tax_periods(id) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!\InterfaceDB::tableExists('tax_loss_movement_history')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS tax_loss_movement_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    accounting_period_id INT NOT NULL,
                    ct_period_id INT NULL,
                    computation_hash VARCHAR(64) NOT NULL,
                    loss_created DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    loss_brought_forward DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    loss_utilised DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    loss_carried_forward DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    taxable_before_losses DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    taxable_profit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_tax_loss_history_period (company_id, accounting_period_id, computed_at),
                    KEY idx_tax_loss_history_hash (company_id, accounting_period_id, computation_hash),
                    KEY idx_tax_loss_history_ct_period (ct_period_id),
                    KEY fk_tax_loss_history_accounting_period (accounting_period_id),
                    CONSTRAINT fk_tax_loss_history_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_tax_loss_history_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_tax_loss_history_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods(id) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    private function getSubmission(int $submissionId): ?array
    {
        $row = \InterfaceDB::fetchOne('SELECT * FROM hmrc_ct600_submissions WHERE id = :id LIMIT 1', ['id' => $submissionId]);

        return is_array($row) ? $row : null;
    }

    private function failSubmission(int $submissionId, array $errors): array
    {
        \InterfaceDB::prepareExecute(
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

    private function companiesHouseComparisonWarnings(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null || !\InterfaceDB::tableExists('companies_house_document_facts')) {
            return [];
        }
        $count = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM companies_house_documents d
             WHERE d.company_id = :company_id
               AND d.significant_date = :period_end',
            ['company_id' => $companyId, 'period_end' => (string)$accountingPeriod['period_end']]
        );
        if ($count <= 0) {
            return [];
        }

        return ['Companies House has already been filed for this period. If generated figures differ, amended accounts may be required separately.'];
    }
}
