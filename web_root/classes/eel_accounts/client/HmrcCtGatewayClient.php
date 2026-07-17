<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

/**
 * HMRC Corporation Tax client for Transaction Engine Document Submission
 * Protocol 2.0 conversations.
 *
 * This is deliberately independent from HmrcApiClient: Corporation Tax Online
 * is a GovTalk XML service and does not use Developer Hub OAuth or REST fraud
 * prevention headers.
 */
final class HmrcCtGatewayClient implements HmrcCtGatewayClientInterface
{
    private const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    private const CT_BODY_NAMESPACE = 'http://www.govtalk.gov.uk/taxation/CT/5';
    private const MAX_BODY_BYTES = 26214400;
    private const DEFAULT_MAX_RESPONSE_BYTES = 4194304;

    /** @var null|\Closure(array): array */
    private ?\Closure $httpTransport;

    /** @var null|\Closure(string): array */
    private ?\Closure $credentialLoader;

    /** @var null|\Closure(): string */
    private ?\Closure $transactionIdFactory;

    private array $config;
    private int $timeoutSeconds;
    private int $maxResponseBytes;
    private int $maxBodyBytes;
    private int $minimumPollInterval;

    public function __construct(
        ?callable $httpTransport = null,
        ?callable $credentialLoader = null,
        ?callable $transactionIdFactory = null,
        array $config = []
    ) {
        $this->httpTransport = $httpTransport === null ? null : \Closure::fromCallable($httpTransport);
        $this->credentialLoader = $credentialLoader === null ? null : \Closure::fromCallable($credentialLoader);
        $this->transactionIdFactory = $transactionIdFactory === null
            ? null
            : \Closure::fromCallable($transactionIdFactory);
        $this->config = $config;
        $this->timeoutSeconds = max(1, (int)($config['timeout_seconds'] ?? 30));
        $this->maxResponseBytes = max(
            1024,
            (int)($config['max_response_bytes'] ?? self::DEFAULT_MAX_RESPONSE_BYTES)
        );
        $this->maxBodyBytes = min(
            self::MAX_BODY_BYTES,
            max(1024, (int)($config['max_body_bytes'] ?? self::MAX_BODY_BYTES))
        );
        $this->minimumPollInterval = max(1, (int)($config['minimum_poll_interval'] ?? 1));
    }

    public function configurationStatus(string $environment): array
    {
        try {
            $profile = HmrcCtGatewayEnvironment::profile($environment);
            $this->credentials($profile);

            return [
                'ready' => true,
                'environment' => $profile['environment'],
                'credential_environment' => $profile['credential_environment'],
                'class' => $profile['class'],
                'gateway_test' => $profile['gateway_test'],
                'statutory' => $profile['statutory'],
                'submission_url' => $profile['submission_url'],
                'poll_url' => $profile['poll_url'],
                'credentials_present' => true,
                'blockers' => [],
            ];
        } catch (\Throwable $exception) {
            $label = strtoupper(trim($environment));

            return [
                'ready' => false,
                'environment' => $label,
                'credential_environment' => $label === 'TEST' ? 'TEST' : 'LIVE',
                'class' => '',
                'gateway_test' => '',
                'statutory' => false,
                'submission_url' => '',
                'poll_url' => '',
                'credentials_present' => false,
                'blockers' => [$exception->getMessage()],
            ];
        }
    }

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = null;
        $credentials = [];

        try {
            $profile = HmrcCtGatewayEnvironment::profile($environment);
            $credentials = $this->credentials($profile);
            $utr = $this->utr($utr);
            $transactionId = $this->transactionId($transactionId);
            $businessDocument = $this->businessDocument($filingBodyXml);
            $requestXml = $this->submissionRequest(
                $businessDocument,
                $utr,
                $profile,
                $credentials,
                $transactionId
            );

            return $this->exchange(
                'submit',
                $requestXml,
                $profile['submission_url'],
                $profile,
                $transactionId,
                '',
                $this->secretValues($credentials)
            );
        } catch (\Throwable $exception) {
            return $this->localFailure(
                'submit',
                $environment,
                $profile,
                $transactionId,
                '',
                $this->redactText($exception->getMessage(), $this->secretValues($credentials))
            );
        }
    }

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = null;

        try {
            $profile = HmrcCtGatewayEnvironment::profile($environment);
            $correlationId = $this->correlationId($correlationId);
            $transactionId = $this->transactionId($transactionId);
            $endpoint = HmrcCtGatewayEnvironment::responseEndpoint($responseEndpoint, $environment);
            $requestXml = $this->followUpRequest(
                $profile,
                'poll',
                'submit',
                $correlationId,
                $transactionId
            );

            return $this->exchange(
                'poll',
                $requestXml,
                $endpoint,
                $profile,
                $transactionId,
                $correlationId,
                []
            );
        } catch (\Throwable $exception) {
            return $this->localFailure(
                'poll',
                $environment,
                $profile,
                $transactionId,
                $correlationId,
                $exception->getMessage()
            );
        }
    }

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = null;

        try {
            $profile = HmrcCtGatewayEnvironment::profile($environment);
            $correlationId = $this->correlationId($correlationId);
            $transactionId = $this->transactionId($transactionId);
            $endpoint = HmrcCtGatewayEnvironment::responseEndpoint($responseEndpoint, $environment);
            $requestXml = $this->followUpRequest(
                $profile,
                'request',
                'delete',
                $correlationId,
                $transactionId
            );

            return $this->exchange(
                'delete',
                $requestXml,
                $endpoint,
                $profile,
                $transactionId,
                $correlationId,
                []
            );
        } catch (\Throwable $exception) {
            return $this->localFailure(
                'delete',
                $environment,
                $profile,
                $transactionId,
                $correlationId,
                $exception->getMessage()
            );
        }
    }

    public function requestData(
        array $criteria,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = null;
        $credentials = [];

        try {
            $profile = HmrcCtGatewayEnvironment::profile($environment);
            $credentials = $this->credentials($profile);
            $transactionId = $this->transactionId($transactionId);
            $criteria = $this->dataCriteria($criteria);
            $requestXml = $this->dataRequest($profile, $credentials, $criteria, $transactionId);

            return $this->exchange(
                'data_request',
                $requestXml,
                $profile['submission_url'],
                $profile,
                $transactionId,
                '',
                $this->secretValues($credentials)
            );
        } catch (\Throwable $exception) {
            return $this->localFailure(
                'data_request',
                $environment,
                $profile,
                $transactionId,
                '',
                $this->redactText($exception->getMessage(), $this->secretValues($credentials))
            );
        }
    }

    private function credentials(array $profile): array
    {
        $environment = (string)$profile['credential_environment'];

        if ($this->credentialLoader instanceof \Closure) {
            $credentials = ($this->credentialLoader)($environment);
        } else {
            $configuredProfile = $this->configuredProfile((string)$profile['environment']);
            $keysPath = trim((string)($this->config['keys_path'] ?? ''));
            $keysPath = $keysPath === '' ? null : $keysPath;
            $provider = trim((string)($configuredProfile['credential_provider'] ?? 'HMRC')) ?: 'HMRC';
            $tag = trim((string)(
                $this->config['credential_tag']
                ?? $configuredProfile['credential_tag']
                ?? 'CT600_XML'
            ));
            $storedCredential = \SecurityStore::loadCredential(
                $provider,
                $tag,
                $environment,
                $keysPath
            );
            $packedCredential = (string)($storedCredential['api_key'] ?? '');
            $separator = strpos($packedCredential, ':');

            if ($separator === false) {
                throw new \RuntimeException(
                    'HMRC CT Gateway api_key must use the protected senderId:password format.'
                );
            }

            $credentials = [
                'sender_id' => substr($packedCredential, 0, $separator),
                'password' => substr($packedCredential, $separator + 1),
                'vendor_id' => $this->config['vendor_id']
                    ?? $configuredProfile['vendor_id']
                    ?? '',
                'product' => $this->config['product']
                    ?? $configuredProfile['product']
                    ?? '',
                'version' => $this->config['version']
                    ?? $configuredProfile['version']
                    ?? '',
                'email' => $this->config['email'] ?? '',
            ];
        }

        if (!is_array($credentials)) {
            throw new \RuntimeException('HMRC CT Gateway credentials could not be loaded.');
        }

        $credentials = [
            'sender_id' => trim((string)($credentials['sender_id'] ?? $credentials['username'] ?? '')),
            'password' => (string)($credentials['password'] ?? $credentials['sender_password'] ?? ''),
            'vendor_id' => trim((string)($credentials['vendor_id'] ?? '')),
            'product' => trim((string)($credentials['product'] ?? '')),
            'version' => trim((string)($credentials['version'] ?? '')),
            'email' => trim((string)($credentials['email'] ?? '')),
        ];

        $this->printableCredential($credentials['sender_id'], 'sender ID', 1, 64);
        $this->printableCredential($credentials['password'], 'password', 1, 128);

        if (!preg_match('/^[0-9]{4}$/', $credentials['vendor_id'])) {
            throw new \RuntimeException('HMRC CT Gateway Vendor ID must contain exactly four digits.');
        }

        $this->printableCredential($credentials['product'], 'product name', 1, 64);
        $this->printableCredential($credentials['version'], 'product version', 1, 32);

        if ($credentials['email'] !== '' && filter_var($credentials['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('HMRC CT Gateway contact email is invalid.');
        }

        return $credentials;
    }

    private function configuredProfile(string $environment): array
    {
        $class = \eel_accounts\Service\HmrcCtConfigurationService::class;

        if (!class_exists($class)) {
            return [];
        }

        $service = new $class();
        $profile = $service->profile($environment);

        if (!is_array($profile)) {
            throw new \RuntimeException('HMRC CT configuration service returned an invalid profile.');
        }

        return $profile;
    }

    private function printableCredential(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);

        if ($length < $minimum || $length > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \RuntimeException(
                'HMRC CT Gateway ' . $label . ' is missing or contains invalid characters.'
            );
        }
    }

    private function utr(string $utr): string
    {
        $utr = trim($utr);

        if (!preg_match('/^[0-9]{10}$/', $utr)) {
            throw new \InvalidArgumentException('HMRC Corporation Tax UTR must contain exactly 10 digits.');
        }

        return $utr;
    }

    private function correlationId(string $correlationId): string
    {
        $correlationId = strtoupper(trim($correlationId));

        if (!preg_match('/^[0-9A-F]{1,32}$/', $correlationId)) {
            throw new \InvalidArgumentException(
                'HMRC Transaction Engine correlation ID must contain 1 to 32 hexadecimal characters.'
            );
        }

        return $correlationId;
    }

    private function transactionId(?string $transactionId): string
    {
        if ($transactionId === null || trim($transactionId) === '') {
            $transactionId = $this->transactionIdFactory instanceof \Closure
                ? (string)($this->transactionIdFactory)()
                : bin2hex(random_bytes(16));
        }

        $transactionId = strtoupper(trim($transactionId));

        if (!preg_match('/^[0-9A-F]{1,32}$/', $transactionId)) {
            throw new \InvalidArgumentException(
                'HMRC Transaction Engine transaction ID must contain 1 to 32 hexadecimal characters.'
            );
        }

        return $transactionId;
    }

    private function businessDocument(string $xml): \DOMDocument
    {
        if ($xml === '' || strlen($xml) > $this->maxBodyBytes) {
            throw new \InvalidArgumentException(
                'HMRC CT filing body must contain between 1 and ' . $this->maxBodyBytes . ' bytes.'
            );
        }

        $document = $this->parseXml($xml, 'HMRC CT filing body');
        $root = $document->documentElement;

        if (
            !$root instanceof \DOMElement
            || $root->localName !== 'IRenvelope'
            || $root->namespaceURI !== self::CT_BODY_NAMESPACE
        ) {
            throw new \InvalidArgumentException(
                'HMRC CT filing body must have one CT/5 IRenvelope document element.'
            );
        }

        return $document;
    }

    private function submissionRequest(
        \DOMDocument $businessDocument,
        string $utr,
        array $profile,
        array $credentials,
        string $transactionId
    ): string {
        $irEnvelopeXml = $businessDocument->saveXML($businessDocument->documentElement);

        if (!is_string($irEnvelopeXml) || $irEnvelopeXml === '') {
            throw new \RuntimeException('Unable to serialise the CT/5 IRenvelope for submission.');
        }

        $result = (new \eel_accounts\Service\GovTalkEnvelopeBuilder())->buildSubmission(
            $irEnvelopeXml,
            (string)$profile['environment'],
            $transactionId,
            (string)$credentials['sender_id'],
            (string)$credentials['password'],
            $utr,
            (string)$credentials['vendor_id'],
            (string)$credentials['product'],
            (string)$credentials['version']
        );

        $xml = (string)($result['xml'] ?? '');

        if ($xml === '') {
            throw new \RuntimeException('GovTalk envelope builder did not return final submission XML.');
        }

        if (strlen($xml) > $this->maxBodyBytes) {
            throw new \RuntimeException(
                'Final HMRC GovTalk request exceeded the ' . $this->maxBodyBytes . '-byte message limit.'
            );
        }

        $document = $this->parseXml($xml, 'IRmarked HMRC GovTalk request');
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]//*[local-name()="IRmark"]'
        );
        $node = $nodes === false ? null : $nodes->item(0);

        if (!$node instanceof \DOMElement || trim($node->textContent) === '') {
            throw new \RuntimeException('HMRC generic IRmark was not populated in the filing body.');
        }

        return $xml;
    }

    private function followUpRequest(
        array $profile,
        string $qualifier,
        string $function,
        string $correlationId,
        string $transactionId
    ): string {
        [$document] = $this->envelope(
            $profile,
            $qualifier,
            $function,
            $transactionId,
            $correlationId,
            false,
            null,
            null,
            false
        );

        return $this->saveXml($document);
    }

    private function dataRequest(
        array $profile,
        array $credentials,
        array $criteria,
        string $transactionId
    ): string {
        [$document, $body] = $this->envelope(
            $profile,
            'request',
            'list',
            $transactionId,
            '',
            true,
            $credentials,
            null,
            false
        );
        $this->appendText(
            $document,
            $body,
            'IncludeIdentifiers',
            $criteria['include_identifiers'] ? '1' : '0'
        );

        if ($criteria['start_at'] instanceof \DateTimeImmutable) {
            $this->appendText($document, $body, 'StartDate', $criteria['start_at']->format('d/m/Y'));
            $this->appendText($document, $body, 'StartTime', $criteria['start_at']->format('H:i:s'));
        }

        if ($criteria['end_at'] instanceof \DateTimeImmutable) {
            $this->appendText($document, $body, 'EndDate', $criteria['end_at']->format('d/m/Y'));
            $this->appendText($document, $body, 'EndTime', $criteria['end_at']->format('H:i:s'));
        }

        return $this->saveXml($document);
    }

    /**
     * @return array{0: \DOMDocument, 1: \DOMElement}
     */
    private function envelope(
        array $profile,
        string $qualifier,
        string $function,
        string $transactionId,
        string $correlationId,
        bool $includeGatewayTest,
        ?array $credentials,
        ?string $utr,
        bool $includeChannelRouting
    ): array {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkMessage');
        $document->appendChild($root);

        $this->appendText($document, $root, 'EnvelopeVersion', '2.0');
        $header = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Header');
        $root->appendChild($header);
        $messageDetails = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'MessageDetails');
        $header->appendChild($messageDetails);
        $this->appendText($document, $messageDetails, 'Class', (string)$profile['class']);
        $this->appendText($document, $messageDetails, 'Qualifier', $qualifier);
        $this->appendText($document, $messageDetails, 'Function', $function);
        $this->appendText($document, $messageDetails, 'TransactionID', $transactionId);
        $this->appendText($document, $messageDetails, 'CorrelationID', $correlationId);
        $this->appendText($document, $messageDetails, 'Transformation', 'XML');

        if ($includeGatewayTest) {
            $this->appendText(
                $document,
                $messageDetails,
                'GatewayTest',
                (string)$profile['gateway_test']
            );
        }

        $senderDetails = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'SenderDetails');
        $header->appendChild($senderDetails);

        if (is_array($credentials)) {
            $idAuthentication = $document->createElementNS(
                self::ENVELOPE_NAMESPACE,
                'IDAuthentication'
            );
            $senderDetails->appendChild($idAuthentication);
            $this->appendText(
                $document,
                $idAuthentication,
                'SenderID',
                (string)$credentials['sender_id']
            );
            $authentication = $document->createElementNS(
                self::ENVELOPE_NAMESPACE,
                'Authentication'
            );
            $idAuthentication->appendChild($authentication);
            $this->appendText($document, $authentication, 'Method', 'clear');
            $this->appendText(
                $document,
                $authentication,
                'Value',
                (string)$credentials['password']
            );

            if ((string)$credentials['email'] !== '') {
                $this->appendText(
                    $document,
                    $senderDetails,
                    'EmailAddress',
                    (string)$credentials['email']
                );
            }
        }

        $govTalkDetails = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkDetails');
        $root->appendChild($govTalkDetails);
        $keys = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Keys');
        $govTalkDetails->appendChild($keys);

        if ($utr !== null) {
            $key = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Key');
            $key->setAttribute('Type', 'UTR');
            $key->appendChild($document->createTextNode($utr));
            $keys->appendChild($key);
        }

        if ($includeChannelRouting && is_array($credentials)) {
            $channelRouting = $document->createElementNS(
                self::ENVELOPE_NAMESPACE,
                'ChannelRouting'
            );
            $govTalkDetails->appendChild($channelRouting);
            $channel = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Channel');
            $channelRouting->appendChild($channel);
            $this->appendText($document, $channel, 'URI', (string)$credentials['vendor_id']);
            $this->appendText($document, $channel, 'Product', (string)$credentials['product']);
            $this->appendText($document, $channel, 'Version', (string)$credentials['version']);
        }

        $body = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Body');
        $root->appendChild($body);

        return [$document, $body];
    }

    private function appendText(
        \DOMDocument $document,
        \DOMElement $parent,
        string $name,
        string $value
    ): \DOMElement {
        $element = $document->createElementNS(self::ENVELOPE_NAMESPACE, $name);

        if ($value !== '') {
            $element->appendChild($document->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    private function dataCriteria(array $criteria): array
    {
        $startAt = $this->dateTime($criteria['start_at'] ?? null, 'start_at');
        $endAt = $this->dateTime($criteria['end_at'] ?? null, 'end_at');

        if ($endAt instanceof \DateTimeImmutable && !$startAt instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('HMRC data request end_at requires start_at.');
        }

        if (
            $startAt instanceof \DateTimeImmutable
            && $endAt instanceof \DateTimeImmutable
            && $endAt < $startAt
        ) {
            throw new \InvalidArgumentException('HMRC data request end_at must not precede start_at.');
        }

        return [
            'include_identifiers' => !array_key_exists('include_identifiers', $criteria)
                || (bool)$criteria['include_identifiers'],
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    private function dateTime(mixed $value, string $label): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('HMRC data request ' . $label . ' is invalid.');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(
                'HMRC data request ' . $label . ' is invalid.',
                0,
                $exception
            );
        }
    }

    private function exchange(
        string $operation,
        string $requestXml,
        string $endpoint,
        array $profile,
        string $transactionId,
        string $correlationId,
        array $secrets
    ): array {
        $redactedRequest = $this->redactXml($requestXml, $secrets);
        $requestIrmark = $this->irmarkValue($requestXml);
        $request = [
            'transport' => 'http',
            'method' => 'POST',
            'url' => $endpoint,
            'headers' => [
                'Accept' => 'text/xml, application/xml',
                'Content-Type' => 'text/xml; charset=UTF-8',
            ],
            'auth' => 'none',
            'body' => $requestXml,
            'timeout_seconds' => $this->timeoutSeconds,
            'max_response_bytes' => $this->maxResponseBytes,
            'follow_location' => false,
            'max_redirects' => 0,
            'ssl_verify_peer' => true,
            'ssl_verify_host' => 2,
            'fail_on_error' => false,
        ];

        try {
            $response = $this->httpTransport instanceof \Closure
                ? ($this->httpTransport)($request)
                : \ApiHelperOutbound::request($request);

            if (!is_array($response)) {
                throw new \RuntimeException('HMRC Transaction Engine transport returned an invalid response.');
            }
        } catch (\Throwable $exception) {
            $failure = $this->baseResult(
                $operation,
                $profile,
                $endpoint,
                $transactionId,
                $correlationId
            );
            $failure['transport_unknown'] = $operation === 'submit';
            $failure['request_xml'] = $redactedRequest;
            $failure['irmark'] = $requestIrmark;
            $failure['error'] = $this->redactText($exception->getMessage(), $secrets);

            return $failure;
        }

        $statusCode = (int)($response['status_code'] ?? 0);
        $responseXml = (string)($response['body'] ?? '');
        $redactedResponse = $this->redactXml($responseXml, $secrets);

        try {
            $parsed = $this->parseResponse($responseXml, $operation, $profile);
        } catch (\Throwable $exception) {
            $failure = $this->baseResult(
                $operation,
                $profile,
                $endpoint,
                $transactionId,
                $correlationId
            );
            $failure['status_code'] = $statusCode;
            $failure['headers'] = $this->safeHeaders((array)($response['headers'] ?? []), $secrets);
            $failure['transport_unknown'] = $operation === 'submit'
                && ($statusCode === 0 || $statusCode >= 500);
            $failure['request_xml'] = $redactedRequest;
            $failure['response_xml'] = $redactedResponse;
            $failure['irmark'] = $requestIrmark;
            $failure['error'] = $this->redactText($exception->getMessage(), $secrets);

            return $failure;
        }

        $result = array_replace(
            $this->baseResult($operation, $profile, $endpoint, $transactionId, $correlationId),
            $parsed
        );
        $result['status_code'] = $statusCode;
        $result['headers'] = $this->safeHeaders((array)($response['headers'] ?? []), $secrets);
        $result['request_xml'] = $redactedRequest;
        $result['response_xml'] = $redactedResponse;
        $result['body_xml'] = $this->redactText((string)$result['body_xml'], $secrets);
        $result['irmark'] = $requestIrmark;

        if ($statusCode < 200 || $statusCode >= 300) {
            $httpError = [
                'raised_by' => 'HTTP',
                'number' => (string)$statusCode,
                'type' => 'transport',
                'texts' => ['HMRC Transaction Engine returned HTTP status ' . $statusCode . '.'],
                'locations' => [],
            ];
            $result['success'] = false;
            // Accepted-looking XML delivered with a transport error is not a
            // usable final response. The same correlation sequence can be
            // polled again, so do not expose a business filing outcome.
            $result['protocol_state'] = 'failed';
            $result['business_outcome'] = null;
            $result['cleanup_required'] = false;
            $result['errors'][] = $httpError;
            $result['error'] = $this->errorMessage($result['errors']);
            $result['transport_unknown'] = $operation === 'submit'
                && ($statusCode === 0 || $statusCode >= 500);
        }

        return $result;
    }

    private function parseResponse(string $xml, string $operation, array $profile): array
    {
        if (strlen($xml) > $this->maxResponseBytes) {
            throw new \RuntimeException('HMRC Transaction Engine response exceeded the allowed size limit.');
        }

        $document = $this->parseXml($xml, 'HMRC Transaction Engine response');
        $root = $document->documentElement;

        if (
            !$root instanceof \DOMElement
            || $root->localName !== 'GovTalkMessage'
            || $root->namespaceURI !== self::ENVELOPE_NAMESPACE
        ) {
            throw new \RuntimeException('HMRC Transaction Engine returned a non-GovTalk response.');
        }

        $xpath = new \DOMXPath($document);
        $messageDetails = $this->firstElement(
            $xpath,
            '/*[local-name()="GovTalkMessage"]/*[local-name()="Header"]/*[local-name()="MessageDetails"]'
        );

        if (!$messageDetails instanceof \DOMElement) {
            throw new \RuntimeException('HMRC Transaction Engine response omitted MessageDetails.');
        }

        $class = $this->relativeText($xpath, $messageDetails, 'Class');
        $qualifier = strtolower($this->relativeText($xpath, $messageDetails, 'Qualifier'));
        $function = strtolower($this->relativeText($xpath, $messageDetails, 'Function'));
        $transactionId = strtoupper($this->relativeText($xpath, $messageDetails, 'TransactionID'));
        $correlationId = strtoupper($this->relativeText($xpath, $messageDetails, 'CorrelationID'));
        $gatewayTimestamp = $this->relativeText($xpath, $messageDetails, 'GatewayTimestamp');
        $responseNode = $this->relativeElement($xpath, $messageDetails, 'ResponseEndPoint');
        $responseEndpoint = $responseNode instanceof \DOMElement ? trim($responseNode->textContent) : '';
        $pollInterval = null;

        if ($responseNode instanceof \DOMElement && $responseNode->hasAttribute('PollInterval')) {
            $rawInterval = trim($responseNode->getAttribute('PollInterval'));
            if ($rawInterval !== '' && !preg_match('/^[0-9]+$/', $rawInterval)) {
                throw new \RuntimeException('HMRC Transaction Engine returned an invalid poll interval.');
            }
            if ($rawInterval !== '') {
                $pollInterval = max($this->minimumPollInterval, (int)$rawInterval);
            }
        }

        if ($correlationId !== '' && !preg_match('/^[0-9A-F]{1,32}$/', $correlationId)) {
            throw new \RuntimeException('HMRC Transaction Engine returned an invalid correlation ID.');
        }

        if ($responseEndpoint !== '') {
            $responseEndpoint = HmrcCtGatewayEnvironment::responseEndpoint(
                $responseEndpoint,
                (string)$profile['environment']
            );
        } elseif (in_array($qualifier, ['acknowledgement', 'acknowledgment'], true) || $correlationId !== '') {
            $responseEndpoint = (string)$profile['poll_url'];
        }

        $errors = $this->errors($xpath);

        if ($class !== (string)$profile['class']) {
            $errors[] = [
                'raised_by' => 'Client',
                'number' => 'RESPONSE_CLASS_MISMATCH',
                'type' => 'protocol',
                'texts' => [
                    'HMRC response class ' . ($class === '' ? '(empty)' : $class)
                    . ' did not match ' . $profile['class'] . '.',
                ],
                'locations' => ['/GovTalkMessage/Header/MessageDetails/Class'],
            ];
        }

        if (
            $correlationId === ''
            && (
                ($qualifier === 'response' && $function === 'submit')
                || ($qualifier === 'response' && $function === 'delete')
            )
        ) {
            $errors[] = [
                'raised_by' => 'Client',
                'number' => 'MISSING_CORRELATION_ID',
                'type' => 'protocol',
                'texts' => ['HMRC final response did not contain a correlation ID.'],
                'locations' => ['/GovTalkMessage/Header/MessageDetails/CorrelationID'],
            ];
        }

        $bodyXml = $this->bodyXml($document, $xpath);
        $statusRecords = $this->statusRecords($xpath);
        $protocolState = 'failed';
        $businessOutcome = null;
        $success = false;
        $cleanupRequired = false;
        $deleteNotFound = false;

        if (
            in_array($qualifier, ['acknowledgement', 'acknowledgment'], true)
            && $function === 'submit'
            && $errors === []
        ) {
            if ($correlationId === '') {
                $errors[] = [
                    'raised_by' => 'Client',
                    'number' => 'MISSING_CORRELATION_ID',
                    'type' => 'protocol',
                    'texts' => ['HMRC acknowledgement did not contain a correlation ID.'],
                    'locations' => ['/GovTalkMessage/Header/MessageDetails/CorrelationID'],
                ];
            } else {
                $protocolState = 'acknowledged';
                $success = true;
            }
        } elseif ($qualifier === 'response' && $function === 'submit') {
            $protocolState = 'final_response';
            $businessOutcome = $errors === [] ? 'accepted' : 'rejected';
            $success = $errors === [];
            $cleanupRequired = $correlationId !== '';
        } elseif ($qualifier === 'error' && $function === 'submit') {
            if ($this->isTransactionEngineSubmissionError($errors)) {
                // Gateway/fatal means this request was not processed by the
                // Transaction Engine. After acknowledgement it is not a
                // Department business rejection: the original sequence stays
                // live and must be polled again.
                $protocolState = 'submission_error';
            } else {
                $protocolState = $correlationId === '' ? 'failed' : 'final_response';
                $businessOutcome = 'rejected';
                $cleanupRequired = $correlationId !== '';
            }
        } elseif ($qualifier === 'response' && $function === 'delete' && $errors === []) {
            $protocolState = 'deleted';
            $success = true;
        } elseif ($qualifier === 'error' && $function === 'delete') {
            $deleteNotFound = $this->hasErrorNumber($errors, '2000');
            if ($deleteNotFound) {
                $protocolState = 'deleted';
                $success = true;
            }
        } elseif ($qualifier === 'response' && $function === 'list' && $errors === []) {
            $protocolState = 'data_response';
            $success = true;
        }

        if (!$this->responseFunctionMatchesOperation($operation, $function)) {
            $success = false;
            $protocolState = 'failed';
            $errors[] = [
                'raised_by' => 'Client',
                'number' => 'RESPONSE_FUNCTION_MISMATCH',
                'type' => 'protocol',
                'texts' => [
                    'HMRC response function ' . ($function === '' ? '(empty)' : $function)
                    . ' was not valid for ' . $operation . '.',
                ],
                'locations' => ['/GovTalkMessage/Header/MessageDetails/Function'],
            ];
        }

        if (!$success && $errors === []) {
            $errors[] = [
                'raised_by' => 'Client',
                'number' => 'UNEXPECTED_RESPONSE',
                'type' => 'protocol',
                'texts' => [
                    'HMRC Transaction Engine returned an unexpected '
                    . ($qualifier === '' ? '(empty)' : $qualifier)
                    . '/' . ($function === '' ? '(empty)' : $function) . ' response.',
                ],
                'locations' => ['/GovTalkMessage/Header/MessageDetails'],
            ];
        }

        return [
            'success' => $success,
            'protocol_state' => $protocolState,
            'business_outcome' => $businessOutcome,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => $responseEndpoint,
            'poll_interval' => $pollInterval,
            'gateway_timestamp' => $gatewayTimestamp,
            'cleanup_required' => $cleanupRequired,
            'delete_not_found' => $deleteNotFound,
            'qualifier' => $qualifier,
            'function' => $function,
            'errors' => $errors,
            'body_xml' => $bodyXml,
            'status_records' => $statusRecords,
            'error' => $success ? '' : $this->errorMessage($errors),
        ];
    }

    private function responseFunctionMatchesOperation(string $operation, string $function): bool
    {
        return match ($operation) {
            'submit', 'poll' => $function === 'submit',
            'delete' => $function === 'delete',
            'data_request' => $function === 'list',
            default => false,
        };
    }

    /** @param list<array<string, mixed>> $errors */
    private function isTransactionEngineSubmissionError(array $errors): bool
    {
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            if (strcasecmp(trim((string)($error['raised_by'] ?? '')), 'Gateway') === 0
                && strtolower(trim((string)($error['type'] ?? ''))) === 'fatal'
            ) {
                return true;
            }
        }

        return false;
    }

    private function parseXml(string $xml, string $label): \DOMDocument
    {
        if ($xml === '') {
            throw new \RuntimeException($label . ' was empty.');
        }

        if (strlen($xml) > max($this->maxBodyBytes, $this->maxResponseBytes)) {
            throw new \RuntimeException($label . ' exceeded the allowed size limit.');
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException($label . ' contained a prohibited document type.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new \RuntimeException($label . ' was malformed XML.');
        }

        return $document;
    }

    private function saveXml(\DOMDocument $document): string
    {
        $xml = $document->saveXML();

        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Unable to serialise HMRC GovTalk XML.');
        }

        return $xml;
    }

    private function firstElement(\DOMXPath $xpath, string $query): ?\DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function relativeElement(
        \DOMXPath $xpath,
        \DOMElement $parent,
        string $localName
    ): ?\DOMElement {
        $nodes = $xpath->query('./*[local-name()="' . $localName . '"]', $parent);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function relativeText(\DOMXPath $xpath, \DOMElement $parent, string $localName): string
    {
        $node = $this->relativeElement($xpath, $parent, $localName);

        return $node instanceof \DOMElement ? trim($node->textContent) : '';
    }

    private function relativeTexts(\DOMXPath $xpath, \DOMElement $parent, string $localName): array
    {
        $nodes = $xpath->query('./*[local-name()="' . $localName . '"]', $parent);
        $values = [];

        if ($nodes === false) {
            return $values;
        }

        foreach ($nodes as $node) {
            $value = trim($node->textContent);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function errors(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//*[local-name()="Error"]');
        $errors = [];
        $seen = [];

        if ($nodes === false) {
            return $errors;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $error = [
                'raised_by' => $this->relativeText($xpath, $node, 'RaisedBy'),
                'number' => $this->relativeText($xpath, $node, 'Number'),
                'type' => strtolower($this->relativeText($xpath, $node, 'Type')),
                'texts' => $this->relativeTexts($xpath, $node, 'Text'),
                'locations' => $this->relativeTexts($xpath, $node, 'Location'),
            ];

            if ($error['texts'] === []) {
                $description = $this->relativeText($xpath, $node, 'Description');
                if ($description !== '') {
                    $error['texts'][] = $description;
                }
            }

            $fingerprint = json_encode($error, JSON_UNESCAPED_SLASHES);
            if (is_string($fingerprint) && isset($seen[$fingerprint])) {
                continue;
            }

            if (is_string($fingerprint)) {
                $seen[$fingerprint] = true;
            }
            $errors[] = $error;
        }

        return $errors;
    }

    private function hasErrorNumber(array $errors, string $number): bool
    {
        foreach ($errors as $error) {
            if (trim((string)($error['number'] ?? '')) === $number) {
                return true;
            }
        }

        return false;
    }

    private function bodyXml(\DOMDocument $document, \DOMXPath $xpath): string
    {
        $body = $this->firstElement(
            $xpath,
            '/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]'
        );

        if (!$body instanceof \DOMElement) {
            return '';
        }

        foreach ($body->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $xml = $document->saveXML($child);

            return is_string($xml) ? $xml : '';
        }

        return '';
    }

    private function statusRecords(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//*[local-name()="StatusReport"]/*[local-name()="StatusRecord"]');
        $records = [];

        if ($nodes === false) {
            return $records;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $identifiers = [];
            $identifierNodes = $xpath->query(
                './*[local-name()="Identifiers"]/*[local-name()="Identifier"]',
                $node
            );
            if ($identifierNodes !== false) {
                foreach ($identifierNodes as $identifierNode) {
                    if (!$identifierNode instanceof \DOMElement) {
                        continue;
                    }
                    $name = trim(
                        $identifierNode->getAttribute('Type')
                        ?: $identifierNode->getAttribute('Name')
                    );
                    $identifiers[] = [
                        'name' => $name,
                        'value' => trim($identifierNode->textContent),
                    ];
                }
            }

            $status = strtoupper($this->relativeText($xpath, $node, 'Status'));
            $records[] = [
                'timestamp' => $this->relativeText($xpath, $node, 'TimeStamp'),
                'correlation_id' => strtoupper($this->relativeText($xpath, $node, 'CorrelationID')),
                'transaction_id' => strtoupper($this->relativeText($xpath, $node, 'TransactionID')),
                'status' => $status,
                'normalised_status' => match ($status) {
                    'SUBMISSION_ACKNOWLEDGE', 'SUBMISSION_ACKNOWLEDGEMENT' => 'acknowledged',
                    'SUBMISSION_RESPONSE' => 'final_response',
                    'SUBMISSION_ERROR' => 'rejected',
                    default => strtolower($status),
                },
                'identifiers' => $identifiers,
            ];
        }

        return $records;
    }

    private function errorMessage(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            $number = trim((string)($error['number'] ?? ''));
            $texts = is_array($error['texts'] ?? null) ? $error['texts'] : [];

            foreach ($texts as $text) {
                $text = trim((string)$text);
                if ($text !== '') {
                    $messages[] = ($number === '' ? '' : $number . ': ') . $text;
                }
            }
        }

        return $messages !== []
            ? implode(' ', array_values(array_unique($messages)))
            : 'HMRC Transaction Engine rejected the request.';
    }

    private function baseResult(
        string $operation,
        array $profile,
        string $endpoint,
        string $transactionId,
        string $correlationId
    ): array {
        return [
            'success' => false,
            'transport_unknown' => false,
            'operation' => $operation,
            'status_code' => 0,
            'headers' => [],
            'endpoint' => $endpoint,
            'environment' => (string)$profile['environment'],
            'credential_environment' => (string)$profile['credential_environment'],
            'class' => (string)$profile['class'],
            'gateway_test' => (string)$profile['gateway_test'],
            'statutory' => (bool)$profile['statutory'],
            'protocol_state' => 'failed',
            'business_outcome' => null,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => '',
            'poll_interval' => null,
            'gateway_timestamp' => '',
            'cleanup_required' => false,
            'delete_not_found' => false,
            'qualifier' => '',
            'function' => '',
            'errors' => [],
            'request_xml' => '',
            'response_xml' => '',
            'body_xml' => '',
            'status_records' => [],
            'irmark' => '',
            'error' => '',
        ];
    }

    private function irmarkValue(string $xml): string
    {
        try {
            $document = $this->parseXml($xml, 'HMRC GovTalk request');
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query(
                '/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]//*[local-name()="IRmark"]'
            );
            $node = $nodes === false ? null : $nodes->item(0);

            return $node instanceof \DOMNode ? trim($node->textContent) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function localFailure(
        string $operation,
        string $environment,
        ?array $profile,
        ?string $transactionId,
        string $correlationId,
        string $error
    ): array {
        if (!is_array($profile)) {
            $label = strtoupper(trim($environment));
            $profile = [
                'environment' => $label,
                'credential_environment' => $label === 'TEST' ? 'TEST' : 'LIVE',
                'class' => '',
                'gateway_test' => '',
                'statutory' => false,
                'submission_url' => '',
            ];
        }

        $result = $this->baseResult(
            $operation,
            $profile,
            (string)($profile['submission_url'] ?? ''),
            (string)$transactionId,
            $correlationId
        );
        $result['error'] = $error;

        return $result;
    }

    private function secretValues(array $credentials): array
    {
        $values = [
            (string)($credentials['sender_id'] ?? ''),
            (string)($credentials['password'] ?? ''),
        ];
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

        try {
            $document = $this->parseXml($xml, 'XML to redact');
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query(
                '/*[local-name()="GovTalkMessage"]/*[local-name()="Header"]'
                . '/*[local-name()="SenderDetails"]//*[local-name()="SenderID" or local-name()="Value"]'
            );

            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    while ($node->firstChild !== null) {
                        $node->removeChild($node->firstChild);
                    }
                    $node->appendChild($document->createTextNode('[REDACTED]'));
                }
            }

            $redacted = $document->saveXML();
            if (is_string($redacted) && $redacted !== '') {
                $xml = $redacted;
            }
        } catch (\Throwable) {
            // A malformed response is still useful diagnostic evidence; direct
            // secret replacement below keeps it persistence-safe.
        }

        return $this->redactText($xml, $secrets);
    }

    private function redactText(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[REDACTED]', $text);
                $text = str_replace(
                    htmlspecialchars($secret, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                    '[REDACTED]',
                    $text
                );
            }
        }

        return $text;
    }

    private function safeHeaders(array $headers, array $secrets): array
    {
        $allowed = ['content-type', 'date', 'x-correlation-id', 'x-request-id'];
        $safe = [];

        foreach ($headers as $name => $value) {
            $normalised = strtolower(trim((string)$name));
            if (!in_array($normalised, $allowed, true)) {
                continue;
            }
            $safe[$normalised] = $this->redactText((string)$value, $secrets);
        }

        return $safe;
    }
}
