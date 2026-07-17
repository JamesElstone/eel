<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

/**
 * Corporation Tax Online uses the legacy XML Transaction Engine.  Keep its
 * environment and credentials separate from the REST/MTD HMRC configuration.
 */
final class HmrcCtConfigurationService
{
    public const TEST = 'TEST';
    public const TIL = 'TIL';
    public const LIVE = 'LIVE';

    public function environment(): string
    {
        return self::normaliseEnvironment((string)\AppConfigurationStore::get(
            'runtime.hmrc_ct_environment',
            self::TEST
        ));
    }

    public static function normaliseEnvironment(string $environment): string
    {
        $environment = strtoupper(trim($environment));

        return in_array($environment, [self::TEST, self::TIL, self::LIVE], true)
            ? $environment
            : self::TEST;
    }

    /** @return array<string, mixed> */
    public function profile(?string $environment = null): array
    {
        $environment = self::normaliseEnvironment($environment ?? $this->environment());
        $configured = \AppConfigurationStore::get('hmrc.ct600_xml', []);
        $configured = is_array($configured) ? $configured : [];
        $isTest = $environment === self::TEST;
        $projectRoot = dirname(rtrim(APP_ROOT, '\\/'));
        $runtimeRoot = $projectRoot . DIRECTORY_SEPARATOR . 'third_party'
            . DIRECTORY_SEPARATOR . 'hmrc_ct600' . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'HMRC-CT-2014-v1-994';
        $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
        $uploadRoot = trim((string)($uploads['upload_base_dir'] ?? ''));
        if ($uploadRoot === '') {
            $uploadRoot = $projectRoot . DIRECTORY_SEPARATOR . 'runtime';
        }

        $testCompanyIds = [];
        foreach ((array)($configured['test_company_ids'] ?? []) as $testCompanyId) {
            $testCompanyId = (int)$testCompanyId;
            if ($testCompanyId > 0) {
                $testCompanyIds[] = $testCompanyId;
            }
        }
        $liveCompanyIds = [];
        foreach ((array)($configured['live_company_ids'] ?? []) as $liveCompanyId) {
            $liveCompanyId = (int)$liveCompanyId;
            if ($liveCompanyId > 0) {
                $liveCompanyIds[] = $liveCompanyId;
            }
        }

        return [
            'environment' => $environment,
            'endpoint' => $isTest
                ? (string)($configured['test_submission_endpoint'] ?? 'https://test-transaction-engine.tax.service.gov.uk/submission')
                : (string)($configured['live_submission_endpoint'] ?? 'https://transaction-engine.tax.service.gov.uk/submission'),
            'poll_endpoint' => $isTest
                ? (string)($configured['test_poll_endpoint'] ?? 'https://test-transaction-engine.tax.service.gov.uk/poll')
                : (string)($configured['live_poll_endpoint'] ?? 'https://transaction-engine.tax.service.gov.uk/poll'),
            'class' => $environment === self::TIL ? 'HMRC-CT-CT600-TIL' : 'HMRC-CT-CT600',
            'gateway_test' => $isTest ? '1' : '0',
            'credential_provider' => (string)($configured['credential_provider'] ?? 'HMRC'),
            'credential_tag' => (string)($configured['credential_tag'] ?? 'CT600_XML'),
            'credential_environment' => $isTest ? self::TEST : self::LIVE,
            'vendor_id' => trim((string)($configured['vendor_id'] ?? '')),
            'product' => trim((string)($configured['product'] ?? 'EEL Accounts')),
            'version' => trim((string)($configured['version'] ?? '1.0')),
            'schema_version' => trim((string)($configured['schema_version'] ?? '2026-v1.994')),
            'rim_version' => trim((string)($configured['rim_version'] ?? '1.994')),
            'schema_path' => trim((string)($configured['schema_path'] ?? ($runtimeRoot . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xsd'))),
            'envelope_schema_path' => trim((string)($configured['envelope_schema_path'] ?? ($runtimeRoot . DIRECTORY_SEPARATOR . 'envelope-v2-0-HMRC.xsd'))),
            'schematron_xslt_path' => trim((string)($configured['schematron_xslt_path'] ?? ($runtimeRoot . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xslt'))),
            'artifact_root' => trim((string)($configured['artifact_root'] ?? ($uploadRoot . DIRECTORY_SEPARATOR . 'hmrc_ct600'))),
            'timeout_seconds' => max(5, (int)($configured['timeout_seconds'] ?? 30)),
            // HMRC's limit is stated as 25 MB. Use the conservative decimal
            // byte boundary consistently for both package and transport.
            'max_message_bytes' => max(1, (int)($configured['max_message_bytes'] ?? 25_000_000)),
            // ETS must never receive live-company data. Each synthetic
            // company must therefore be explicitly allowlisted server-side.
            'test_company_ids' => array_values(array_unique($testCompanyIds)),
            // LIVE is a separate, deliberately narrow server-side switch.
            // A company must be explicitly allowlisted only after its CT
            // Online enrolment and the SDST assurance route are confirmed.
            'live_enabled' => !empty($configured['live_enabled']),
            'sdst_assurance_confirmed' => !empty($configured['sdst_assurance_confirmed']),
            'live_company_ids' => array_values(array_unique($liveCompanyIds)),
        ];
    }

    public function isSyntheticTestCompany(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        return in_array($companyId, (array)$this->profile(self::TEST)['test_company_ids'], true);
    }

    /** @return array{ok:bool,errors:list<string>} */
    public function liveEnablementStatus(int $companyId): array
    {
        $profile = $this->profile(self::LIVE);
        $errors = [];
        if (empty($profile['live_enabled'])) {
            $errors[] = 'LIVE CT600 filing is disabled by server configuration.';
        }
        if (empty($profile['sdst_assurance_confirmed'])) {
            $errors[] = 'Record that HMRC SDST testing and assurance requirements have been confirmed before LIVE filing.';
        }
        if (!in_array($companyId, (array)$profile['live_company_ids'], true)) {
            $errors[] = 'Confirm Corporation Tax Online enrolment and add this company to the server-controlled LIVE CT600 allowlist.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Returns the secret only to the gateway/orchestrator.  Callers must never
     * serialise this value into a submission, event, exception, or response.
     *
     * @return array{ok: bool, sender_id: string, password: string, errors: array<int, string>}
     */
    public function credentials(?string $environment = null): array
    {
        $profile = $this->profile($environment);

        try {
            $credential = \SecurityStore::loadCredential(
                (string)$profile['credential_provider'],
                (string)$profile['credential_tag'],
                (string)$profile['credential_environment'],
                \SecurityStore::apiKeysPath()
            );
        } catch (\Throwable $exception) {
            return ['ok' => false, 'sender_id' => '', 'password' => '', 'errors' => [$exception->getMessage()]];
        }

        $secret = trim((string)($credential['api_key'] ?? ''));
        [$senderId, $password] = array_pad(explode(':', $secret, 2), 2, '');
        $senderId = trim($senderId);
        $password = trim($password);
        $errors = [];
        if ($senderId === '') {
            $errors[] = 'The HMRC CT XML Sender ID is missing.';
        }
        if ($password === '') {
            $errors[] = 'The HMRC CT XML password is missing.';
        }

        return [
            'ok' => $errors === [],
            'sender_id' => $senderId,
            'password' => $password,
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function credentialStatus(?string $environment = null): array
    {
        $profile = $this->profile($environment);
        $credential = $this->credentials((string)$profile['environment']);
        $errors = (array)($credential['errors'] ?? []);
        if (!preg_match('/^\d{4}$/', (string)$profile['vendor_id'])) {
            $errors[] = 'A four-digit HMRC XML Vendor ID is not configured.';
        }

        return [
            'ok' => $errors === [],
            'environment' => (string)$profile['environment'],
            'credential_environment' => (string)$profile['credential_environment'],
            'sender_id_present' => trim((string)($credential['sender_id'] ?? '')) !== '',
            'vendor_id_present' => preg_match('/^\d{4}$/', (string)$profile['vendor_id']) === 1,
            'errors' => $errors,
        ];
    }
}
