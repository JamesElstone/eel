<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HmrcSubmissionAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ((string)$request->input('stream_log', '') === '1') {
            $this->stream($request);
            exit;
        }

        return ActionResultFramework::success(['page.context']);
    }

    private function stream(RequestFramework $request): void
    {
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $service = new HmrcCorporationTaxSubmissionService();
        $fraud = new HmrcFraudPreventionHeaderService();
        $companyId = (int)$request->input('company_id', 0);
        $taxYearId = (int)$request->input('tax_year_id', 0);
        $intent = trim((string)$request->input('intent', 'hmrc_validate_package'));
        $mode = HelperFramework::normaliseEnvironmentMode((string)$request->input('mode', 'TEST'));
        $line = function (string $level, string $message): void {
            echo '[' . gmdate('Y-m-d H:i:s') . 'Z] [' . strtoupper($level) . '] ' . $this->logSafe($message) . PHP_EOL;
            flush();
        };

        $line('info', 'Selected company ID: ' . $companyId);
        $line('info', 'Selected tax year ID: ' . $taxYearId);
        $line('info', 'API mode: ' . $mode);

        if ($intent === 'hmrc_submit_live') {
            $phrase = trim((string)$request->input('live_confirmation', ''));
            if ($mode !== 'LIVE' || $phrase !== 'SUBMIT LIVE CT600') {
                $line('error', 'LIVE submission blocked. API mode must be LIVE and the confirmation phrase must match exactly.');
                return;
            }
        }

        if ($intent === 'hmrc_test_fraud_headers') {
            $headers = $fraud->buildHeadersFromRequest($request, $companyId, $mode);
            $local = $fraud->validateLocally($headers);
            $line('info', 'Fraud prevention headers generated: ' . count($headers));
            foreach ((array)$local['warnings'] as $warning) {
                $line('warning', (string)$warning);
            }
            foreach ((array)$local['errors'] as $error) {
                $line('error', (string)$error);
            }
            $line('info', 'HMRC fraud prevention validator request starting.');
            $response = (new HmrcApiClient())->testFraudPreventionHeaders($headers, $mode);
            $line('info', 'HMRC endpoint target: ' . (string)($response['endpoint'] ?? ''));
            $line('info', 'Response status: ' . (int)($response['status_code'] ?? 0));
            $line(!empty($response['success']) ? 'success' : 'error', !empty($response['success']) ? 'Fraud prevention validator completed.' : (string)($response['error'] ?? $response['body'] ?? 'Fraud prevention validator failed.'));
            return;
        }

        $line('info', 'Generated package checks starting.');
        $validation = $service->validatePackage($companyId, $taxYearId, $mode);
        $submissionId = (int)($validation['submission_id'] ?? 0);
        $line('info', 'Submission draft ID: ' . $submissionId);
        foreach ((array)($validation['warnings'] ?? []) as $warning) {
            $line('warning', (string)$warning);
        }
        foreach ((array)($validation['errors'] ?? []) as $error) {
            $line('error', (string)$error);
        }
        $line(empty($validation['success']) ? 'error' : 'success', empty($validation['success']) ? 'Package validation failed.' : 'Package validation passed.');

        if ($submissionId > 0) {
            $headers = $fraud->buildHeadersFromRequest($request, $companyId, $mode);
            $local = $fraud->validateLocally($headers);
            InterfaceDB::prepareExecute(
                'UPDATE hmrc_ct600_submissions SET request_headers_json = :headers WHERE id = :id',
                ['headers' => json_encode($fraud->redactHeadersForStorage($headers), JSON_UNESCAPED_SLASHES), 'id' => $submissionId]
            );
            $line('info', 'Fraud prevention header status: ' . (empty($local['ok']) ? 'local issues found' : 'locally complete'));
        }

        if (!in_array($intent, ['hmrc_submit_test', 'hmrc_submit_live'], true)) {
            return;
        }
        if (empty($validation['success'])) {
            $line('error', 'Submission stopped because package validation did not pass.');
            return;
        }
        if ($intent === 'hmrc_submit_test' && $mode !== 'TEST') {
            $line('error', 'TEST submission refused because selected mode is not TEST.');
            return;
        }

        $authorisation = new HmrcSubmissionAuthorisationService();
        $authority = $authorisation->validate($request, $companyId, $intent);
        if (empty($authority['success'])) {
            foreach ((array)($authority['errors'] ?? []) as $error) {
                $line('error', (string)$error);
            }

            return;
        }

        $authorisation->recordConfirmation($submissionId, $companyId);

        $line('info', 'HMRC request started.');
        $result = $service->submit($submissionId, $line);
        $line(empty($result['success']) ? 'error' : 'success', empty($result['success']) ? 'Final result: failed or rejected.' : 'Final result: accepted.');
    }

    private function logSafe(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/client_secret=[^&\s]+/i', 'client_secret=[redacted]', $value) ?? $value;

        return $value;
    }
}
