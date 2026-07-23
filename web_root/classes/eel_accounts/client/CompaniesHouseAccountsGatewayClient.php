<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

final class CompaniesHouseAccountsGatewayClient implements CompaniesHouseAccountsGatewayTransportInterface
{
    private const ENDPOINT = 'https://xmlgw.companieshouse.gov.uk/v1-0/xmlgw/Gateway';
    private const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    private const ENVELOPE_SCHEMA = 'http://xmlgw.companieshouse.gov.uk/v1-0/schema/Egov_ch-v2-0.xsd';
    private const FORM_NAMESPACE = 'http://xmlgw.companieshouse.gov.uk/Header';
    private const FORM_SCHEMA = 'http://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/FormSubmission-v2-11.xsd';
    private const STATUS_NAMESPACE = 'http://xmlgw.companieshouse.gov.uk';
    private const STATUS_SCHEMA = 'http://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetSubmissionStatus-v2-9.xsd';
    private const COMPANY_DATA_SCHEMA = 'http://xmlgw.companieshouse.gov.uk/v1-0/schema/CompanyData-v3-6.xsd';
    private const STATUS_ACK_SCHEMA = 'http://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetStatusAck-v1-1.xsd';
    private const GET_DOCUMENT_SCHEMA = 'http://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetDocument-v1-1.xsd';
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';
    private const MAX_DOCUMENT_BYTES = 30000000;

    /** @var array<string, string> */
    private const NORMALIZED_STATUSES = [
        'ACCEPT' => 'accepted',
        'REJECT' => 'rejected',
        'PENDING' => 'pending',
        'PARKED' => 'parked',
        'INTERNAL_FAILURE' => 'internal_failure',
    ];

    /** @var null|\Closure(array): array */
    private ?\Closure $httpTransport;

    /** @var null|\Closure(string): array */
    private ?\Closure $credentialLoader;

    /** @var null|\Closure(string): array */
    private ?\Closure $outputCredentialLoader;

    /** @var null|\Closure(): string */
    private ?\Closure $transactionIdFactory;

    /** @var null|\Closure(string,string): array */
    private ?\Closure $requestValidator;

    private int $timeoutSeconds;
    private int $maxResponseBytes;
    private int $minimumIntervalMicroseconds;

    private static float $lastRequestAt = 0.0;

    public function __construct(
        ?callable $httpTransport = null,
        ?callable $credentialLoader = null,
        ?callable $transactionIdFactory = null,
        array $config = [],
        ?callable $requestValidator = null,
        ?callable $outputCredentialLoader = null
    ) {
        $this->httpTransport = $httpTransport === null ? null : \Closure::fromCallable($httpTransport);
        $this->credentialLoader = $credentialLoader === null ? null : \Closure::fromCallable($credentialLoader);
        $this->transactionIdFactory = $transactionIdFactory === null
            ? null
            : \Closure::fromCallable($transactionIdFactory);
        $this->requestValidator = $requestValidator === null ? null : \Closure::fromCallable($requestValidator);
        $this->outputCredentialLoader = $outputCredentialLoader === null
            ? null
            : \Closure::fromCallable($outputCredentialLoader);
        $this->timeoutSeconds = max(1, (int)($config['timeout_seconds'] ?? 30));
        $this->maxResponseBytes = max(1, (int)($config['max_response_bytes'] ?? 42000000));
        $this->minimumIntervalMicroseconds = max(
            0,
            (int)($config['minimum_interval_microseconds'] ?? 500000)
        );
    }

    public function checkCompanyAuthentication(
        string $companyNumber,
        string $companyAuthenticationCode,
        string $environment,
        string $schemaManifestSha256,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null
    ): array {
        try {
            $environment = $this->normaliseEnvironment($environment);
            $companyNumber = $this->companyNumber($companyNumber);
            $companyAuthenticationCode = $this->companyAuthenticationCode($companyAuthenticationCode);
            $credentials = $this->outputCredentials($environment);
            $transactionId = $this->transactionId();
            $requestXml = $this->buildCompanyDataRequest(
                $companyNumber,
                $companyAuthenticationCode,
                $credentials,
                $environment,
                $transactionId
            );
            $this->validateOperationRequest(
                $requestXml,
                $schemaManifestSha256,
                'CompanyData-v3-6.xsd',
                'CompanyDataRequest'
            );
        } catch (\Throwable $exception) {
            return $this->failureResult($environment, $exception->getMessage(), false, '');
        }

        return $this->sendProtocolRequest(
            'company-data',
            $environment,
            $transactionId,
            $requestXml,
            $this->secretValues($credentials, $companyAuthenticationCode),
            $beforeSend,
            $afterReceive,
            $schemaManifestSha256,
            fn(array $response, string $redactedRequest, array $secrets): array =>
                $this->parseCompanyDataResponse(
                    $response,
                    $environment,
                    $companyNumber,
                    $transactionId,
                    $redactedRequest,
                    $secrets
                )
        );
    }

    public function prepareAccounts(
        array $payload,
        string $environment,
        string $schemaManifestSha256
    ): CompaniesHousePreparedAccountsRequest
    {
        $environment = $this->normaliseEnvironment($environment);
        $credentials = $this->credentials($environment, true);
        $payload = $this->normaliseSubmissionPayload($payload);
        $transactionId = $this->transactionId();
        $requestXml = $this->buildSubmissionRequest($payload, $credentials, $environment, $transactionId);
        $secrets = $this->secretValues($credentials, (string)$payload['company_authentication_code']);
        $redactedRequest = $this->redactXml($requestXml, $secrets);

        $validation = $this->requestValidator instanceof \Closure
            ? ($this->requestValidator)($requestXml, $schemaManifestSha256)
            : (new \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator())
                ->validateAccountsRequest($requestXml, $schemaManifestSha256);
        if (empty($validation['success'])
            || (int)($validation['snapshot_id'] ?? 0) <= 0
            || !hash_equals(strtolower($schemaManifestSha256), strtolower((string)($validation['manifest_sha256'] ?? '')))) {
            throw new \RuntimeException('The prepared Companies House request was not validated against the selected schema snapshot.');
        }

        return new CompaniesHousePreparedAccountsRequest(
            $environment,
            (string)$payload['submission_number'],
            $transactionId,
            $requestXml,
            $redactedRequest,
            $secrets,
            (int)$validation['snapshot_id'],
            strtolower($schemaManifestSha256)
        );
    }

    public function sendPreparedAccounts(
        CompaniesHousePreparedAccountsRequest $request,
        ?callable $afterReceive = null
    ): array
    {
        try {
            $response = $this->send($request->requestXml());
        } catch (\Throwable $exception) {
            return array_replace(
                $this->failureResult(
                    $request->environment(),
                    $this->redactText($exception->getMessage(), $request->secrets()),
                    true,
                    $request->submissionNumber()
                ),
                [
                    'transaction_id' => $request->transactionId(),
                    'request_xml' => $request->redactedRequestXml(),
                ]
            );
        }

        $captureError = $this->captureResponse(
            $afterReceive,
            'submit',
            $request->environment(),
            $request->transactionId(),
            (string)($response['body'] ?? ''),
            (int)($response['status_code'] ?? 0)
        );
        $result = $this->parseSubmissionResponse(
            $response,
            $request->environment(),
            $request->submissionNumber(),
            $request->transactionId(),
            $request->redactedRequestXml(),
            $request->secrets()
        );
        $responseValidationError = $this->responseValidationError(
            (string)($response['body'] ?? ''),
            $request->schemaManifestSha256()
        );
        if ($responseValidationError !== '') {
            $result['success'] = false;
            $result['transport_unknown'] = true;
            $result['error'] = $responseValidationError;
        }
        if ($captureError !== '') {
            $result['evidence_error'] = $captureError;
        }

        return $result;
    }

    public function getSubmissionStatus(
        string $submissionNumber,
        string $environment,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null,
        string $schemaManifestSha256 = ''
    ): array
    {
        try {
            $environment = $this->normaliseEnvironment($environment);
            $submissionNumber = $this->submissionNumber($submissionNumber);
            $credentials = $this->credentials($environment, false);
            $transactionId = $this->transactionId();
            $requestXml = $this->buildStatusRequest(
                $submissionNumber,
                $credentials,
                $environment,
                $transactionId
            );
            if ($schemaManifestSha256 !== '') {
                $this->validateOperationRequest(
                    $requestXml,
                    $schemaManifestSha256,
                    'GetSubmissionStatus-v2-9.xsd',
                    'GetSubmissionStatus'
                );
            }
        } catch (\Throwable $exception) {
            return $this->failureResult($environment, $exception->getMessage(), false, $submissionNumber);
        }

        $secrets = $this->secretValues($credentials);
        $redactedRequest = $this->redactXml($requestXml, $secrets);
        try {
            if ($beforeSend !== null) {
                $beforeSend([
                    'operation' => 'status',
                    'environment' => $environment,
                    'transaction_id' => $transactionId,
                    'request_xml' => $requestXml,
                    'request_sha256' => hash('sha256', $requestXml),
                    'request_bytes' => strlen($requestXml),
                ]);
            }
        } catch (\Throwable) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    'The Companies House status request evidence could not be persisted; nothing was sent.',
                    false,
                    $submissionNumber
                ),
                [
                    'pre_send_failure' => true,
                    'transaction_id' => $transactionId,
                    'request_xml' => $redactedRequest,
                ]
            );
        }

        try {
            $response = $this->send($requestXml);
        } catch (\Throwable $exception) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    $this->redactText($exception->getMessage(), $secrets),
                    false,
                    $submissionNumber
                ),
                [
                    'transaction_id' => $transactionId,
                    'request_xml' => $redactedRequest,
                ]
            );
        }

        $captureError = $this->captureResponse(
            $afterReceive,
            'status',
            $environment,
            $transactionId,
            (string)($response['body'] ?? ''),
            (int)($response['status_code'] ?? 0)
        );
        $result = $this->parseStatusResponse(
            $response,
            $environment,
            $submissionNumber,
            $transactionId,
            $redactedRequest,
            $secrets
        );
        if ($schemaManifestSha256 !== '') {
            $responseValidationError = $this->responseValidationError(
                (string)($response['body'] ?? ''),
                $schemaManifestSha256
            );
            if ($responseValidationError !== '') {
                $result['success'] = false;
                $result['transport_unknown'] = true;
                $result['error'] = $responseValidationError;
            }
        }
        if ($captureError !== '') {
            $result['evidence_error'] = $captureError;
        }

        return $result;
    }

    public function acknowledgeSubmissionStatus(
        string $environment,
        string $schemaManifestSha256,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null
    ): array {
        try {
            $environment = $this->normaliseEnvironment($environment);
            $credentials = $this->credentials($environment, false);
            $transactionId = $this->transactionId();
            $requestXml = $this->buildStatusAckRequest($credentials, $environment, $transactionId);
            $this->validateOperationRequest(
                $requestXml,
                $schemaManifestSha256,
                'GetStatusAck-v1-1.xsd',
                'StatusAck'
            );
        } catch (\Throwable $exception) {
            return $this->failureResult($environment, $exception->getMessage(), false, '');
        }

        return $this->sendProtocolRequest(
            'status-ack',
            $environment,
            $transactionId,
            $requestXml,
            $this->secretValues($credentials),
            $beforeSend,
            $afterReceive,
            $schemaManifestSha256,
            fn(array $response, string $redactedRequest, array $secrets): array =>
                $this->parseAcknowledgementResponse(
                    $response,
                    $environment,
                    $transactionId,
                    $redactedRequest,
                    $secrets
                )
        );
    }

    public function getDocument(
        string $documentRequestKey,
        string $environment,
        string $schemaManifestSha256,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null
    ): array {
        try {
            $environment = $this->normaliseEnvironment($environment);
            $documentRequestKey = trim($documentRequestKey);
            if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $documentRequestKey)) {
                throw new \InvalidArgumentException('The Companies House document request key is invalid.');
            }
            $credentials = $this->credentials($environment, false);
            $transactionId = $this->transactionId();
            $requestXml = $this->buildGetDocumentRequest(
                $documentRequestKey,
                $credentials,
                $environment,
                $transactionId
            );
            $this->validateOperationRequest(
                $requestXml,
                $schemaManifestSha256,
                'GetDocument-v1-1.xsd',
                'GetDocument'
            );
        } catch (\Throwable $exception) {
            return $this->failureResult($environment, $exception->getMessage(), false, '');
        }

        return $this->sendProtocolRequest(
            'get-document',
            $environment,
            $transactionId,
            $requestXml,
            $this->secretValues($credentials),
            $beforeSend,
            $afterReceive,
            $schemaManifestSha256,
            fn(array $response, string $redactedRequest, array $secrets): array =>
                $this->parseDocumentResponse(
                    $response,
                    $environment,
                    $documentRequestKey,
                    $transactionId,
                    $redactedRequest,
                    $secrets
                )
        );
    }

    private function normaliseEnvironment(string $environment): string
    {
        $environment = strtoupper(trim($environment));

        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            throw new \InvalidArgumentException('Companies House accounts filing environment must be TEST or LIVE.');
        }

        return $environment;
    }

    private function credentials(string $environment, bool $packageReferenceRequired): array
    {
        if ($this->credentialLoader instanceof \Closure) {
            $credentials = ($this->credentialLoader)($environment);
        } else {
            $credentials = (new \eel_accounts\Service\CompaniesHouseAccountsCredentialService())
                ->load($environment);
        }

        if (!is_array($credentials)) {
            throw new \RuntimeException('Companies House accounts filing credentials could not be loaded.');
        }

        $credentials = [
            'presenter_id' => trim((string)($credentials['presenter_id'] ?? '')),
            'presenter_code' => trim((string)($credentials['presenter_code'] ?? '')),
            'package_reference' => trim((string)($credentials['package_reference'] ?? '')),
        ];

        if ($credentials['presenter_id'] === '' || $credentials['presenter_code'] === '') {
            throw new \RuntimeException(
                'Companies House accounts filing presenter credentials are not configured for ' . $environment . '.'
            );
        }

        if ($packageReferenceRequired && $credentials['package_reference'] === '') {
            throw new \RuntimeException(
                'Companies House accounts filing package reference is not configured for ' . $environment . '.'
            );
        }

        return $credentials;
    }

    private function outputCredentials(string $environment): array
    {
        $credentials = $this->outputCredentialLoader instanceof \Closure
            ? ($this->outputCredentialLoader)($environment)
            : (new \eel_accounts\Service\CompaniesHouseCompanyDataCredentialService())->load($environment);
        if (!is_array($credentials)) {
            throw new \RuntimeException('Companies House CompanyData XML Output credentials could not be loaded.');
        }
        $credentials = [
            'presenter_id' => trim((string)($credentials['presenter_id'] ?? '')),
            'presenter_code' => trim((string)($credentials['presenter_code'] ?? '')),
            'package_reference' => '',
        ];
        if ($credentials['presenter_id'] === '' || $credentials['presenter_code'] === '') {
            throw new \RuntimeException(
                'Companies House CompanyData XML Output credentials are not configured for ' . $environment . '.'
            );
        }

        return $credentials;
    }

    private function companyNumber(string $companyNumber): string
    {
        $companyNumber = strtoupper(trim($companyNumber));
        if (!preg_match('/^(?:[0-9]{1,8}|[A-Z]{2}[0-9]{6})$/D', $companyNumber)) {
            throw new \InvalidArgumentException('The Companies House company number is invalid.');
        }

        return $companyNumber;
    }

    private function companyAuthenticationCode(string $code): string
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9]{6}$/D', $code)) {
            throw new \InvalidArgumentException(
                'The Companies House company authentication code must contain exactly 6 letters or numbers.'
            );
        }

        return $code;
    }

    private function normaliseSubmissionPayload(array $payload): array
    {
        $companyNumber = trim((string)($payload['company_number'] ?? ''));
        if (!preg_match('/^[0-9]{1,8}$/', $companyNumber)) {
            throw new \InvalidArgumentException('Companies House company_number must contain 1 to 8 digits.');
        }

        $companyName = trim((string)($payload['company_name'] ?? ''));
        if (strlen($companyName) < 3 || strlen($companyName) > 160) {
            throw new \InvalidArgumentException('Companies House company_name must contain 3 to 160 bytes.');
        }

        $companyAuthenticationCode = $this->companyAuthenticationCode(
            (string)($payload['company_authentication_code'] ?? '')
        );

        $submissionNumber = $this->submissionNumber((string)($payload['submission_number'] ?? ''));
        $dateSigned = trim((string)($payload['date_signed'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateSigned);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $dateSigned) {
            throw new \InvalidArgumentException('Companies House date_signed must be a valid YYYY-MM-DD date.');
        }

        $accountsXml = (string)($payload['accounts_xml'] ?? '');
        $documentBytes = strlen($accountsXml);
        if ($documentBytes === 0 || $documentBytes > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException(
                'Companies House accounts_xml must contain between 1 and 30000000 bytes.'
            );
        }
        (new \eel_accounts\Service\CompaniesHouseIxbrlDocumentPolicyService())
            ->assertSubmissionCompliant($accountsXml);

        $filename = trim((string)($payload['filename'] ?? ('Accounts-' . $submissionNumber . '.xml')));
        if ($filename === '' || strlen($filename) > 32 || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
            throw new \InvalidArgumentException('Companies House attachment filename must contain 1 to 32 bytes.');
        }

        $customerReference = trim((string)($payload['customer_reference'] ?? ''));
        if ($customerReference !== '' && !preg_match('/^[A-Za-z0-9]{1,25}$/', $customerReference)) {
            throw new \InvalidArgumentException(
                'Companies House customer_reference must contain at most 25 alphanumeric characters.'
            );
        }

        $language = strtoupper(trim((string)($payload['language'] ?? 'EN')));
        if (!in_array($language, ['EN', 'CY'], true)) {
            throw new \InvalidArgumentException('Companies House filing language must be EN or CY.');
        }

        $companyType = strtoupper(trim((string)($payload['company_type'] ?? '')));
        if ($companyType !== '' && !in_array($companyType, ['EW', 'SC', 'NI', 'R', 'OC', 'SO', 'NC'], true)) {
            throw new \InvalidArgumentException('Companies House company_type is not supported by FormSubmission v2.11.');
        }

        return [
            'company_number' => $companyNumber,
            'company_type' => $companyType,
            'company_name' => $companyName,
            'company_authentication_code' => $companyAuthenticationCode,
            'submission_number' => $submissionNumber,
            'date_signed' => $dateSigned,
            'accounts_xml' => $accountsXml,
            'filename' => $filename,
            'customer_reference' => $customerReference,
            'language' => $language,
        ];
    }

    private function submissionNumber(string $submissionNumber): string
    {
        $submissionNumber = trim($submissionNumber);

        if (!preg_match('/^[A-Za-z0-9]{6}$/', $submissionNumber)) {
            throw new \InvalidArgumentException(
                'Companies House submission number must contain exactly 6 alphanumeric characters.'
            );
        }

        return $submissionNumber;
    }

    private function transactionId(): string
    {
        $transactionId = $this->transactionIdFactory instanceof \Closure
            ? strtoupper(trim((string)($this->transactionIdFactory)()))
            : strtoupper(bin2hex(random_bytes(16)));

        if (!preg_match('/^[0-9A-F]{1,32}$/', $transactionId)) {
            throw new \RuntimeException(
                'Companies House transaction ID must contain 1 to 32 hexadecimal characters.'
            );
        }

        return $transactionId;
    }

    private function buildSubmissionRequest(
        array $payload,
        array $credentials,
        string $environment,
        string $transactionId
    ): string {
        $document = $this->envelopeDocument(
            'Accounts',
            $environment,
            $transactionId,
            $credentials,
            'submit'
        );
        $body = $this->firstElement($document, 'Body');
        $formSubmission = $document->createElementNS(self::FORM_NAMESPACE, 'FormSubmission');
        $formSubmission->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::FORM_NAMESPACE . ' ' . self::FORM_SCHEMA
        );
        $body->appendChild($formSubmission);

        $formHeader = $document->createElementNS(self::FORM_NAMESPACE, 'FormHeader');
        $formSubmission->appendChild($formHeader);
        $this->appendText($document, $formHeader, self::FORM_NAMESPACE, 'CompanyNumber', $payload['company_number']);
        if ($payload['company_type'] !== '') {
            $this->appendText($document, $formHeader, self::FORM_NAMESPACE, 'CompanyType', $payload['company_type']);
        }
        $this->appendText($document, $formHeader, self::FORM_NAMESPACE, 'CompanyName', $payload['company_name']);
        $this->appendText(
            $document,
            $formHeader,
            self::FORM_NAMESPACE,
            'CompanyAuthenticationCode',
            $payload['company_authentication_code']
        );
        $this->appendText(
            $document,
            $formHeader,
            self::FORM_NAMESPACE,
            'PackageReference',
            $credentials['package_reference']
        );
        $this->appendText($document, $formHeader, self::FORM_NAMESPACE, 'Language', $payload['language']);
        $this->appendText($document, $formHeader, self::FORM_NAMESPACE, 'FormIdentifier', 'Accounts');
        $this->appendText(
            $document,
            $formHeader,
            self::FORM_NAMESPACE,
            'SubmissionNumber',
            $payload['submission_number']
        );
        if ($payload['customer_reference'] !== '') {
            $this->appendText(
                $document,
                $formHeader,
                self::FORM_NAMESPACE,
                'CustomerReference',
                $payload['customer_reference']
            );
        }

        $this->appendText(
            $document,
            $formSubmission,
            self::FORM_NAMESPACE,
            'DateSigned',
            $payload['date_signed']
        );
        $formSubmission->appendChild($document->createElementNS(self::FORM_NAMESPACE, 'Form'));

        $attachment = $document->createElementNS(self::FORM_NAMESPACE, 'Document');
        $formSubmission->appendChild($attachment);
        $this->appendText(
            $document,
            $attachment,
            self::FORM_NAMESPACE,
            'Data',
            base64_encode($payload['accounts_xml'])
        );
        $this->appendText(
            $document,
            $attachment,
            self::FORM_NAMESPACE,
            'Filename',
            $payload['filename']
        );
        $this->appendText($document, $attachment, self::FORM_NAMESPACE, 'ContentType', 'application/xml');
        $this->appendText($document, $attachment, self::FORM_NAMESPACE, 'Category', 'ACCOUNTS');

        return $this->saveXml($document);
    }

    private function buildStatusRequest(
        string $submissionNumber,
        array $credentials,
        string $environment,
        string $transactionId
    ): string {
        $document = $this->envelopeDocument(
            'GetSubmissionStatus',
            $environment,
            $transactionId,
            $credentials
        );
        $body = $this->firstElement($document, 'Body');
        $statusRequest = $document->createElementNS(self::STATUS_NAMESPACE, 'GetSubmissionStatus');
        $statusRequest->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::STATUS_NAMESPACE . ' ' . self::STATUS_SCHEMA
        );
        $body->appendChild($statusRequest);
        $this->appendText(
            $document,
            $statusRequest,
            self::STATUS_NAMESPACE,
            'SubmissionNumber',
            $submissionNumber
        );
        $this->appendText(
            $document,
            $statusRequest,
            self::STATUS_NAMESPACE,
            'PresenterID',
            $credentials['presenter_id']
        );

        return $this->saveXml($document);
    }

    private function buildCompanyDataRequest(
        string $companyNumber,
        string $companyAuthenticationCode,
        array $credentials,
        string $environment,
        string $transactionId
    ): string {
        $document = $this->envelopeDocument(
            'CompanyDataRequest',
            $environment,
            $transactionId,
            $credentials
        );
        $body = $this->firstElement($document, 'Body');
        $request = $document->createElementNS(self::STATUS_NAMESPACE, 'CompanyDataRequest');
        $request->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::STATUS_NAMESPACE . ' ' . self::COMPANY_DATA_SCHEMA
        );
        $body->appendChild($request);
        $this->appendText($document, $request, self::STATUS_NAMESPACE, 'CompanyNumber', $companyNumber);
        $this->appendText(
            $document,
            $request,
            self::STATUS_NAMESPACE,
            'CompanyAuthenticationCode',
            $companyAuthenticationCode
        );
        $this->appendText($document, $request, self::STATUS_NAMESPACE, 'MadeUpDate', gmdate('Y-m-d'));

        return $this->saveXml($document);
    }

    private function buildStatusAckRequest(
        array $credentials,
        string $environment,
        string $transactionId
    ): string {
        $document = $this->envelopeDocument('StatusAck', $environment, $transactionId, $credentials);
        $body = $this->firstElement($document, 'Body');
        $request = $document->createElementNS(self::STATUS_NAMESPACE, 'StatusAck');
        $request->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::STATUS_NAMESPACE . ' ' . self::STATUS_ACK_SCHEMA
        );
        $body->appendChild($request);

        return $this->saveXml($document);
    }

    private function buildGetDocumentRequest(
        string $documentRequestKey,
        array $credentials,
        string $environment,
        string $transactionId
    ): string {
        $document = $this->envelopeDocument('GetDocument', $environment, $transactionId, $credentials);
        $body = $this->firstElement($document, 'Body');
        $request = $document->createElementNS(self::STATUS_NAMESPACE, 'GetDocument');
        $request->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::STATUS_NAMESPACE . ' ' . self::GET_DOCUMENT_SCHEMA
        );
        $body->appendChild($request);
        $this->appendText(
            $document,
            $request,
            self::STATUS_NAMESPACE,
            'DocRequestKey',
            $documentRequestKey
        );

        return $this->saveXml($document);
    }

    private function envelopeDocument(
        string $class,
        string $environment,
        string $transactionId,
        array $credentials,
        ?string $function = null
    ): \DOMDocument {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkMessage');
        $root->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::ENVELOPE_NAMESPACE . ' ' . self::ENVELOPE_SCHEMA
        );
        $document->appendChild($root);

        $this->appendText($document, $root, self::ENVELOPE_NAMESPACE, 'EnvelopeVersion', '1.0');
        $header = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Header');
        $root->appendChild($header);
        $messageDetails = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'MessageDetails');
        $header->appendChild($messageDetails);
        $this->appendText($document, $messageDetails, self::ENVELOPE_NAMESPACE, 'Class', $class);
        $this->appendText($document, $messageDetails, self::ENVELOPE_NAMESPACE, 'Qualifier', 'request');
        if ($function !== null) {
            $this->appendText($document, $messageDetails, self::ENVELOPE_NAMESPACE, 'Function', $function);
        }
        $this->appendText(
            $document,
            $messageDetails,
            self::ENVELOPE_NAMESPACE,
            'TransactionID',
            $transactionId
        );
        $this->appendText(
            $document,
            $messageDetails,
            self::ENVELOPE_NAMESPACE,
            'GatewayTest',
            $environment === 'TEST' ? '1' : '0'
        );

        $senderDetails = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'SenderDetails');
        $header->appendChild($senderDetails);
        $idAuthentication = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'IDAuthentication');
        $senderDetails->appendChild($idAuthentication);
        $this->appendText(
            $document,
            $idAuthentication,
            self::ENVELOPE_NAMESPACE,
            'SenderID',
            $this->softwareFilingHash($credentials['presenter_id'])
        );
        $authentication = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Authentication');
        $idAuthentication->appendChild($authentication);
        $this->appendText($document, $authentication, self::ENVELOPE_NAMESPACE, 'Method', 'clear');
        $this->appendText(
            $document,
            $authentication,
            self::ENVELOPE_NAMESPACE,
            'Value',
            $this->softwareFilingHash($credentials['presenter_code'])
        );

        $govTalkDetails = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkDetails');
        $root->appendChild($govTalkDetails);
        $keys = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Keys');
        $govTalkDetails->appendChild($keys);
        if ($class === 'Accounts') {
            $key = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Key');
            $key->setAttribute('Type', 'FormType');
            $key->appendChild($document->createTextNode('Accounts'));
            $keys->appendChild($key);
        }

        $root->appendChild($document->createElementNS(self::ENVELOPE_NAMESPACE, 'Body'));

        return $document;
    }

    private function appendText(
        \DOMDocument $document,
        \DOMElement $parent,
        string $namespace,
        string $name,
        string $value
    ): \DOMElement {
        $element = $document->createElementNS($namespace, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private function firstElement(\DOMDocument $document, string $localName): \DOMElement
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="' . $localName . '"]');
        $node = $nodes === false ? null : $nodes->item(0);

        if (!$node instanceof \DOMElement) {
            throw new \RuntimeException('Unable to build Companies House XML envelope.');
        }

        return $node;
    }

    private function saveXml(\DOMDocument $document): string
    {
        $xml = $document->saveXML();

        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Unable to serialise Companies House XML envelope.');
        }

        return $xml;
    }

    private function softwareFilingHash(string $value): string
    {
        return 'md5#' . md5($value);
    }

    private function send(string $requestXml): array
    {
        $this->throttle();
        $request = [
            'transport' => 'http',
            'method' => 'POST',
            'url' => self::ENDPOINT,
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml; charset=UTF-8',
            ],
            'auth' => 'none',
            'body' => $requestXml,
            'timeout_seconds' => $this->timeoutSeconds,
            'max_response_bytes' => $this->maxResponseBytes,
            'follow_location' => false,
        ];

        $response = $this->httpTransport instanceof \Closure
            ? ($this->httpTransport)($request)
            : \ApiHelperOutbound::request($request);

        if (!is_array($response)) {
            throw new \RuntimeException('Companies House XML Gateway transport returned an invalid response.');
        }

        $body = (string)($response['body'] ?? '');
        if (strlen($body) > $this->maxResponseBytes) {
            throw new \RuntimeException('Companies House XML Gateway response exceeded the allowed size limit.');
        }

        return $response;
    }

    private function validateOperationRequest(
        string $requestXml,
        string $schemaManifestSha256,
        string $schemaName,
        string $elementName
    ): void {
        $validation = $this->requestValidator instanceof \Closure
            ? ($this->requestValidator)($requestXml, $schemaManifestSha256)
            : (new \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator())->validateOperationRequest(
                $requestXml,
                $schemaManifestSha256,
                $schemaName,
                $elementName,
                self::STATUS_NAMESPACE
            );
        if (empty($validation['success'])
            || (int)($validation['snapshot_id'] ?? 0) <= 0
            || !hash_equals(
                strtolower($schemaManifestSha256),
                strtolower((string)($validation['manifest_sha256'] ?? ''))
            )) {
            throw new \RuntimeException(
                'The Companies House ' . $elementName . ' request was not validated against the selected schema snapshot.'
            );
        }
    }

    private function sendProtocolRequest(
        string $operation,
        string $environment,
        string $transactionId,
        string $requestXml,
        array $secrets,
        ?callable $beforeSend,
        ?callable $afterReceive,
        string $schemaManifestSha256,
        callable $parser
    ): array {
        $redactedRequest = $this->redactXml($requestXml, $secrets);
        try {
            if ($beforeSend !== null) {
                $beforeSend([
                    'operation' => $operation,
                    'environment' => $environment,
                    'transaction_id' => $transactionId,
                    'request_xml' => $requestXml,
                    'request_sha256' => hash('sha256', $requestXml),
                    'request_bytes' => strlen($requestXml),
                ]);
            }
        } catch (\Throwable) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    'The Companies House ' . $operation . ' request evidence could not be persisted; nothing was sent.',
                    false,
                    null
                ),
                [
                    'pre_send_failure' => true,
                    'transaction_id' => $transactionId,
                    'request_xml' => $redactedRequest,
                ]
            );
        }

        try {
            $response = $this->send($requestXml);
        } catch (\Throwable $exception) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    $this->redactText($exception->getMessage(), $secrets),
                    true,
                    null
                ),
                ['transaction_id' => $transactionId, 'request_xml' => $redactedRequest]
            );
        }

        $captureError = $this->captureResponse(
            $afterReceive,
            $operation,
            $environment,
            $transactionId,
            (string)($response['body'] ?? ''),
            (int)($response['status_code'] ?? 0)
        );
        $result = $parser($response, $redactedRequest, $secrets);
        $responseValidationError = $this->responseValidationError(
            (string)($response['body'] ?? ''),
            $schemaManifestSha256
        );
        if ($responseValidationError !== '') {
            $result['success'] = false;
            $result['transport_unknown'] = true;
            $result['error'] = $responseValidationError;
        }
        if ($captureError !== '') {
            $result['evidence_error'] = $captureError;
        }

        return $result;
    }

    private function responseValidationError(string $responseXml, string $schemaManifestSha256): string
    {
        try {
            $validation = $this->requestValidator instanceof \Closure
                ? ($this->requestValidator)($responseXml, $schemaManifestSha256)
                : (new \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator())
                    ->validateEnvelopeResponse($responseXml, $schemaManifestSha256);
            if (empty($validation['success'])
                || !hash_equals(
                    strtolower($schemaManifestSha256),
                    strtolower((string)($validation['manifest_sha256'] ?? ''))
                )) {
                throw new \RuntimeException(
                    'Companies House response validation did not use the selected schema snapshot.'
                );
            }
            return '';
        } catch (\Throwable $exception) {
            return 'The Companies House response could not be validated against the pinned schema snapshot: '
                . $exception->getMessage();
        }
    }

    private function captureResponse(
        ?callable $afterReceive,
        string $operation,
        string $environment,
        string $transactionId,
        string $responseXml,
        int $statusCode
    ): string {
        if ($afterReceive === null || $responseXml === '') {
            return '';
        }
        try {
            $afterReceive([
                'operation' => $operation,
                'environment' => $environment,
                'transaction_id' => $transactionId,
                'status_code' => $statusCode,
                'response_xml' => $responseXml,
                'response_sha256' => hash('sha256', $responseXml),
                'response_bytes' => strlen($responseXml),
            ]);
            return '';
        } catch (\Throwable) {
            return 'The exact Companies House response could not be added to the private transmission archive.';
        }
    }

    private function throttle(): void
    {
        if ($this->minimumIntervalMicroseconds === 0) {
            return;
        }

        $now = microtime(true);
        $elapsedMicroseconds = (int)(($now - self::$lastRequestAt) * 1000000);
        $remaining = $this->minimumIntervalMicroseconds - $elapsedMicroseconds;

        if (self::$lastRequestAt > 0.0 && $remaining > 0) {
            usleep($remaining);
        }

        self::$lastRequestAt = microtime(true);
    }

    private function parseSubmissionResponse(
        array $response,
        string $environment,
        string $submissionNumber,
        string $transactionId,
        string $redactedRequest,
        array $secrets
    ): array {
        $statusCode = (int)($response['status_code'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $redactedResponse = $this->redactXml($body, $secrets);

        try {
            $document = $this->parseXml($body);
            $gatewayErrors = $this->gatewayErrors($document);
            $qualifier = $this->firstText($document, 'Qualifier');
            $fatalErrors = array_values(array_filter(
                $gatewayErrors,
                static fn(array $error): bool => strtolower((string)$error['type']) !== 'warning'
            ));
            $success = $statusCode >= 200
                && $statusCode < 300
                && $fatalErrors === []
                && strtolower($qualifier) !== 'error';

            return [
                'success' => $success,
                'transport_unknown' => !$success && $gatewayErrors === [],
                'status_code' => $statusCode,
                'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                'endpoint' => self::ENDPOINT,
                'environment' => $environment,
                'submission_number' => $submissionNumber,
                'transaction_id' => $transactionId,
                'response_transaction_id' => $this->firstText($document, 'TransactionID'),
                'qualifier' => $qualifier,
                'acknowledged' => strtolower($qualifier) === 'acknowledgement',
                'gateway_timestamp' => $this->firstText($document, 'GatewayTimestamp'),
                'gateway_errors' => $gatewayErrors,
                'request_xml' => $redactedRequest,
                'response_xml' => $redactedResponse,
                'error' => $success ? '' : $this->responseError($statusCode, $gatewayErrors),
            ];
        } catch (\Throwable $exception) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    $this->redactText($exception->getMessage(), $secrets),
                    true,
                    $submissionNumber
                ),
                [
                    'status_code' => $statusCode,
                    'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                    'transaction_id' => $transactionId,
                    'request_xml' => $redactedRequest,
                    'response_xml' => $redactedResponse,
                ]
            );
        }
    }

    private function parseStatusResponse(
        array $response,
        string $environment,
        string $submissionNumber,
        string $transactionId,
        string $redactedRequest,
        array $secrets
    ): array {
        $statusCode = (int)($response['status_code'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $redactedResponse = $this->redactXml($body, $secrets);

        try {
            $document = $this->parseXml($body);
            $gatewayErrors = $this->gatewayErrors($document);
            $fatalErrors = array_values(array_filter(
                $gatewayErrors,
                static fn(array $error): bool => strtolower((string)$error['type']) !== 'warning'
            ));

            if ($statusCode < 200 || $statusCode >= 300 || $fatalErrors !== []) {
                return array_replace(
                    $this->failureResult(
                        $environment,
                        $this->responseError($statusCode, $gatewayErrors),
                        false,
                        $submissionNumber
                    ),
                    [
                        'status_code' => $statusCode,
                        'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                        'transaction_id' => $transactionId,
                        'gateway_errors' => $gatewayErrors,
                        'request_xml' => $redactedRequest,
                        'response_xml' => $redactedResponse,
                    ]
                );
            }

            $statuses = $this->submissionStatuses($document, $submissionNumber);
            if ($statuses === []) {
                throw new \RuntimeException(
                    'Companies House XML Gateway returned no status for submission ' . $submissionNumber . '.'
                );
            }

            $latest = $statuses[count($statuses) - 1];
            $rawStatus = (string)$latest['status_code'];
            if (!isset(self::NORMALIZED_STATUSES[$rawStatus])) {
                throw new \RuntimeException(
                    'Companies House XML Gateway returned unsupported submission status ' . $rawStatus . '.'
                );
            }

            return [
                'success' => true,
                'transport_unknown' => false,
                'status_code' => $statusCode,
                'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                'endpoint' => self::ENDPOINT,
                'environment' => $environment,
                'submission_number' => $submissionNumber,
                'transaction_id' => $transactionId,
                'response_transaction_id' => $this->firstText($document, 'TransactionID'),
                'qualifier' => $this->firstText($document, 'Qualifier'),
                'gateway_timestamp' => $this->firstText($document, 'GatewayTimestamp'),
                'submission_status' => $rawStatus,
                'normalized_status' => self::NORMALIZED_STATUSES[$rawStatus],
                'accepted' => $rawStatus === 'ACCEPT',
                'company_number' => (string)$latest['company_number'],
                'customer_reference' => (string)$latest['customer_reference'],
                'document_request_key' => (string)$latest['document_request_key'],
                'rejections' => (array)$latest['rejections'],
                'examiner' => (array)$latest['examiner'],
                'statuses' => $statuses,
                'gateway_errors' => $gatewayErrors,
                'request_xml' => $redactedRequest,
                'response_xml' => $redactedResponse,
                'error' => '',
            ];
        } catch (\Throwable $exception) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    $this->redactText($exception->getMessage(), $secrets),
                    false,
                    $submissionNumber
                ),
                [
                    'status_code' => $statusCode,
                    'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                    'transaction_id' => $transactionId,
                    'request_xml' => $redactedRequest,
                    'response_xml' => $redactedResponse,
                ]
            );
        }
    }

    private function parseCompanyDataResponse(
        array $response,
        string $environment,
        string $companyNumber,
        string $transactionId,
        string $redactedRequest,
        array $secrets
    ): array {
        $parsed = $this->parseProtocolResponse(
            $response,
            $environment,
            $transactionId,
            $redactedRequest,
            $secrets
        );
        if (empty($parsed['success'])) {
            return $parsed;
        }
        try {
            $document = $this->parseXml((string)($response['body'] ?? ''));
            $returnedCompanyNumber = strtoupper($this->firstText($document, 'CompanyNumber'));
            if ($returnedCompanyNumber === '' || $returnedCompanyNumber !== strtoupper($companyNumber)) {
                throw new \RuntimeException(
                    'Companies House CompanyData did not return the requested company identity.'
                );
            }
            $companyName = $this->firstText($document, 'CompanyName');
            if ($companyName === '') {
                throw new \RuntimeException(
                    'Companies House CompanyData returned no authenticated company data.'
                );
            }
            $parsed['company_number'] = $returnedCompanyNumber;
            $parsed['company_name'] = $companyName;
            $parsed['authenticated'] = true;

            return $parsed;
        } catch (\Throwable $exception) {
            $parsed['success'] = false;
            $parsed['authenticated'] = false;
            $parsed['error'] = $this->redactText($exception->getMessage(), $secrets);
            return $parsed;
        }
    }

    private function parseAcknowledgementResponse(
        array $response,
        string $environment,
        string $transactionId,
        string $redactedRequest,
        array $secrets
    ): array {
        return $this->parseProtocolResponse(
            $response,
            $environment,
            $transactionId,
            $redactedRequest,
            $secrets
        );
    }

    private function parseDocumentResponse(
        array $response,
        string $environment,
        string $documentRequestKey,
        string $transactionId,
        string $redactedRequest,
        array $secrets
    ): array {
        $parsed = $this->parseProtocolResponse(
            $response,
            $environment,
            $transactionId,
            $redactedRequest,
            $secrets
        );
        if (empty($parsed['success'])) {
            return $parsed;
        }
        try {
            $document = $this->parseXml((string)($response['body'] ?? ''));
            $encoded = preg_replace('/\s+/', '', $this->firstText($document, 'DocumentData')) ?? '';
            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || $decoded === '' || strlen($decoded) > self::MAX_DOCUMENT_BYTES) {
                throw new \RuntimeException('Companies House returned invalid document data.');
            }
            if (!str_starts_with($decoded, '%PDF-')) {
                throw new \RuntimeException('Companies House returned document data that is not a PDF.');
            }
            $parsed += [
                'document_request_key' => $documentRequestKey,
                'company_number' => strtoupper($this->firstText($document, 'CompanyNumber')),
                'document_date' => $this->firstText($document, 'DocumentDate'),
                'document_type' => $this->firstText($document, 'DocumentType'),
                'document_id' => $this->firstText($document, 'DocumentID'),
                'document_data' => $decoded,
                'document_sha256' => hash('sha256', $decoded),
                'document_bytes' => strlen($decoded),
            ];
            return $parsed;
        } catch (\Throwable $exception) {
            $parsed['success'] = false;
            $parsed['error'] = $this->redactText($exception->getMessage(), $secrets);
            return $parsed;
        }
    }

    private function parseProtocolResponse(
        array $response,
        string $environment,
        string $transactionId,
        string $redactedRequest,
        array $secrets
    ): array {
        $statusCode = (int)($response['status_code'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $redactedResponse = $this->redactXml($body, $secrets);
        try {
            $document = $this->parseXml($body);
            $gatewayErrors = $this->gatewayErrors($document);
            $fatalErrors = array_values(array_filter(
                $gatewayErrors,
                static fn(array $error): bool => strtolower((string)$error['type']) !== 'warning'
            ));
            $qualifier = strtolower($this->firstText($document, 'Qualifier'));
            $success = $statusCode >= 200
                && $statusCode < 300
                && $fatalErrors === []
                && $qualifier !== 'error';

            return [
                'success' => $success,
                'transport_unknown' => !$success && $gatewayErrors === [] && $qualifier === '',
                'status_code' => $statusCode,
                'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                'endpoint' => self::ENDPOINT,
                'environment' => $environment,
                'transaction_id' => $transactionId,
                'response_transaction_id' => $this->firstText($document, 'TransactionID'),
                'qualifier' => $qualifier,
                'gateway_timestamp' => $this->firstText($document, 'GatewayTimestamp'),
                'gateway_errors' => $gatewayErrors,
                'request_xml' => $redactedRequest,
                'response_xml' => $redactedResponse,
                'error' => $success ? '' : $this->responseError($statusCode, $gatewayErrors),
            ];
        } catch (\Throwable $exception) {
            return array_replace(
                $this->failureResult(
                    $environment,
                    $this->redactText($exception->getMessage(), $secrets),
                    true,
                    null
                ),
                [
                    'status_code' => $statusCode,
                    'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                    'transaction_id' => $transactionId,
                    'request_xml' => $redactedRequest,
                    'response_xml' => $redactedResponse,
                ]
            );
        }
    }

    private function parseXml(string $xml): \DOMDocument
    {
        if ($xml === '') {
            throw new \RuntimeException('Companies House XML Gateway returned an empty response.');
        }

        if (strlen($xml) > $this->maxResponseBytes) {
            throw new \RuntimeException('Companies House XML Gateway response exceeded the allowed size limit.');
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException('Companies House XML Gateway response contained a prohibited document type.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new \RuntimeException('Companies House XML Gateway returned malformed XML.');
        }

        return $document;
    }

    private function gatewayErrors(\DOMDocument $document): array
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="GovTalkErrors"]/*[local-name()="Error"]');
        $errors = [];

        if ($nodes === false) {
            return $errors;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $errors[] = [
                'raised_by' => $this->relativeText($xpath, $node, 'RaisedBy'),
                'number' => $this->relativeText($xpath, $node, 'Number'),
                'type' => $this->relativeText($xpath, $node, 'Type'),
                'texts' => $this->relativeTexts($xpath, $node, 'Text'),
                'locations' => $this->relativeTexts($xpath, $node, 'Location'),
            ];
        }

        return $errors;
    }

    private function submissionStatuses(\DOMDocument $document, string $submissionNumber): array
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="SubmissionStatus"]/*[local-name()="Status"]');
        $statuses = [];

        if ($nodes === false) {
            return $statuses;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $number = $this->relativeText($xpath, $node, 'SubmissionNumber');
            if ($number !== $submissionNumber) {
                continue;
            }

            $rejections = [];
            $rejectNodes = $xpath->query(
                './*[local-name()="Rejections"]/*[local-name()="Reject"]',
                $node
            );
            if ($rejectNodes !== false) {
                foreach ($rejectNodes as $rejectNode) {
                    if (!$rejectNode instanceof \DOMElement) {
                        continue;
                    }

                    $rejections[] = [
                        'code' => $this->relativeText($xpath, $rejectNode, 'RejectCode'),
                        'description' => $this->relativeText($xpath, $rejectNode, 'Description'),
                        'instance_number' => $this->relativeText($xpath, $rejectNode, 'InstanceNumber'),
                    ];
                }
            }

            $examinerNodes = $xpath->query('./*[local-name()="Examiner"]', $node);
            $examinerNode = $examinerNodes === false ? null : $examinerNodes->item(0);
            $examiner = ['telephone' => '', 'comment' => ''];
            if ($examinerNode instanceof \DOMElement) {
                $examiner = [
                    'telephone' => $this->relativeText($xpath, $examinerNode, 'Telephone'),
                    'comment' => $this->relativeText($xpath, $examinerNode, 'Comment'),
                ];
            }

            $statuses[] = [
                'submission_number' => $number,
                'status_code' => strtoupper($this->relativeText($xpath, $node, 'StatusCode')),
                'company_number' => $this->relativeText($xpath, $node, 'CompanyNumber'),
                'customer_reference' => $this->relativeText($xpath, $node, 'CustomerReference'),
                'document_request_key' => $this->relativeText($xpath, $node, 'DocRequestKey'),
                'rejections' => $rejections,
                'examiner' => $examiner,
            ];
        }

        return $statuses;
    }

    private function firstText(\DOMDocument $document, string $localName): string
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="' . $localName . '"]');
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMNode ? trim($node->textContent) : '';
    }

    private function relativeText(\DOMXPath $xpath, \DOMElement $parent, string $localName): string
    {
        $nodes = $xpath->query('./*[local-name()="' . $localName . '"]', $parent);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMNode ? trim($node->textContent) : '';
    }

    private function relativeTexts(\DOMXPath $xpath, \DOMElement $parent, string $localName): array
    {
        $nodes = $xpath->query('./*[local-name()="' . $localName . '"]', $parent);
        $values = [];

        if ($nodes === false) {
            return $values;
        }

        foreach ($nodes as $node) {
            $values[] = trim($node->textContent);
        }

        return $values;
    }

    private function responseError(int $statusCode, array $gatewayErrors): string
    {
        $messages = [];

        foreach ($gatewayErrors as $error) {
            $number = trim((string)($error['number'] ?? ''));
            foreach ((array)($error['texts'] ?? []) as $text) {
                $messages[] = ($number === '' ? '' : $number . ': ') . trim((string)$text);
            }
        }

        if ($messages !== []) {
            return implode(' ', $messages);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return 'Companies House XML Gateway returned HTTP status ' . $statusCode . '.';
        }

        return 'Companies House XML Gateway rejected the request.';
    }

    private function failureResult(
        string $environment,
        string $error,
        bool $transportUnknown,
        ?string $submissionNumber
    ): array {
        return [
            'success' => false,
            'transport_unknown' => $transportUnknown,
            'status_code' => 0,
            'headers' => [],
            'endpoint' => self::ENDPOINT,
            'environment' => strtoupper(trim($environment)),
            'submission_number' => $submissionNumber,
            'gateway_errors' => [],
            'request_xml' => '',
            'response_xml' => '',
            'error' => $error,
        ];
    }

    private function secretValues(array $credentials, string $companyAuthenticationCode = ''): array
    {
        $values = [
            (string)($credentials['presenter_id'] ?? ''),
            (string)($credentials['presenter_code'] ?? ''),
            (string)($credentials['package_reference'] ?? ''),
            $companyAuthenticationCode,
        ];

        foreach ([(string)($credentials['presenter_id'] ?? ''), (string)($credentials['presenter_code'] ?? '')] as $value) {
            if ($value !== '') {
                $values[] = $this->softwareFilingHash($value);
            }
        }

        $values = array_values(array_unique(array_filter(
            $values,
            static fn(string $value): bool => $value !== ''
        )));
        usort($values, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        return $values;
    }

    private function redactXml(string $xml, array $secrets): string
    {
        if ($xml === '') {
            return '';
        }

        foreach (
            [
                'SenderID',
                'Value',
                'CompanyAuthenticationCode',
                'PresenterID',
                'AuthenticationCode',
                'PackageReference',
                'Data',
            ] as $tag
        ) {
            $pattern = '~(<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?' . $tag
                . '\b[^>]*>).*?(</(?:[A-Za-z_][A-Za-z0-9_.-]*:)?' . $tag . '\s*>)~is';
            $xml = (string)preg_replace($pattern, '$1[REDACTED]$2', $xml);
        }

        return $this->redactText($xml, $secrets);
    }

    private function redactText(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
        }

        return $text;
    }
}
