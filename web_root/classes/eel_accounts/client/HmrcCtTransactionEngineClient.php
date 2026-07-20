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
 * HMRC Corporation Tax XML Transaction Engine client.
 *
 * This client intentionally does not use the HMRC Developer Hub OAuth client:
 * CT600 is a GovTalk Document Submission Protocol 2.0 conversation.
 */
final class HmrcCtTransactionEngineClient implements HmrcCtTransactionEngineTransportInterface
{
    private const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    private const CT_NAMESPACE = 'http://www.govtalk.gov.uk/taxation/CT/5';
    private const MAX_MESSAGE_BYTES = 25000000;

    /** @var null|\Closure(array): array */
    private ?\Closure $httpTransport;

    /** @var null|\Closure(string): array */
    private ?\Closure $credentialLoader;

    /** @var null|\Closure(): string */
    private ?\Closure $transactionIdFactory;

    private array $config;
    private int $timeoutSeconds;
    private int $maxMessageBytes;
    private int $maxResponseBytes;
    private int $minimumPollInterval;

    public function __construct(
        ?callable $httpTransport = null,
        ?callable $credentialLoader = null,
        ?callable $transactionIdFactory = null,
        array $config = []
    ) {
        $configured = \AppConfigurationStore::get('hmrc.ct600_xml', []);
        $configured = is_array($configured) ? $configured : [];
        $this->config = array_replace($configured, $config);
        $this->httpTransport = $httpTransport === null ? null : \Closure::fromCallable($httpTransport);
        $this->credentialLoader = $credentialLoader === null ? null : \Closure::fromCallable($credentialLoader);
        $this->transactionIdFactory = $transactionIdFactory === null
            ? null
            : \Closure::fromCallable($transactionIdFactory);
        $this->timeoutSeconds = max(5, (int)($this->config['timeout_seconds'] ?? 30));
        $this->maxMessageBytes = min(
            self::MAX_MESSAGE_BYTES,
            max(1024, (int)($this->config['max_message_bytes'] ?? self::MAX_MESSAGE_BYTES))
        );
        $this->maxResponseBytes = max(1024, (int)($this->config['max_response_bytes'] ?? 4194304));
        $this->minimumPollInterval = max(1, (int)($this->config['minimum_poll_interval'] ?? 1));
    }

    public function configurationStatus(string $environment): array
    {
        try {
            $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
            $this->credentials($profile);

            return [
                'ready' => true,
                'credentials_configured' => true,
                'environment' => $profile['environment'],
                'credential_environment' => $profile['credential_environment'],
                'class' => $profile['class'],
                'endpoint' => $profile['submission_url'],
                'poll_endpoint' => $profile['poll_url'],
                'statutory' => $profile['statutory'],
                'blockers' => [],
            ];
        } catch (\Throwable $exception) {
            $label = strtoupper(trim($environment));

            return [
                'ready' => false,
                'credentials_configured' => false,
                'environment' => $label,
                'credential_environment' => $label === 'TEST' ? 'TEST' : 'LIVE',
                'class' => '',
                'endpoint' => '',
                'poll_endpoint' => '',
                'statutory' => false,
                'blockers' => [$exception->getMessage()],
            ];
        }
    }

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array {
        $profile = null;
        $credentials = [];
        try {
            $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
            $credentials = $this->credentials($profile);
            $utr = $this->utr($utr);
            $transactionId = $this->transactionId($transactionId);
            $document = $this->filingBody($filingBodyXml, $utr);
            $requestXml = $this->submissionRequest(
                $document,
                $utr,
                $profile,
                $credentials,
                $transactionId
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

        return $this->exchange(
            'submit',
            $requestXml,
            (string)$profile['submission_url'],
            $profile,
            $transactionId,
            '',
            $this->secretValues($credentials),
            $beforeSend
        );
    }

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array {
        $profile = null;
        try {
            $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
            $correlationId = $this->correlationId($correlationId);
            $transactionId = $this->transactionId($transactionId);
            $endpoint = HmrcCtTransactionEngineEnvironment::responseEndpoint(
                $responseEndpoint,
                $environment
            );
            $requestXml = $this->followUpRequest(
                $profile,
                'poll',
                'submit',
                $correlationId,
                $transactionId
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

        return $this->exchange(
            'poll',
            $requestXml,
            $endpoint,
            $profile,
            $transactionId,
            $correlationId,
            [],
            $beforeSend
        );
    }

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array {
        $profile = null;
        try {
            $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
            $correlationId = $this->correlationId($correlationId);
            $transactionId = $this->transactionId($transactionId);
            $endpoint = HmrcCtTransactionEngineEnvironment::responseEndpoint(
                $responseEndpoint,
                $environment
            );
            $requestXml = $this->followUpRequest(
                $profile,
                'request',
                'delete',
                $correlationId,
                $transactionId
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

        return $this->exchange(
            'delete',
            $requestXml,
            $endpoint,
            $profile,
            $transactionId,
            $correlationId,
            [],
            $beforeSend
        );
    }

    private function credentials(array $profile): array
    {
        if ($this->credentialLoader instanceof \Closure) {
            $credentials = ($this->credentialLoader)((string)$profile['credential_environment']);
        } else {
            $provider = trim((string)($this->config['credential_provider'] ?? 'HMRC')) ?: 'HMRC';
            $tag = trim((string)($this->config['credential_tag'] ?? 'CT600_XML')) ?: 'CT600_XML';
            $keysPath = trim((string)($this->config['keys_path'] ?? ''));
            $stored = \SecurityStore::loadCredential(
                $provider,
                $tag,
                (string)$profile['credential_environment'],
                $keysPath === '' ? \SecurityStore::apiKeysPath() : $keysPath
            );
            [$senderId, $password] = array_pad(
                explode(':', (string)($stored['api_key'] ?? ''), 2),
                2,
                ''
            );
            $credentials = [
                'sender_id' => $senderId,
                'password' => $password,
                'vendor_id' => $this->config['vendor_id'] ?? '',
                'product' => $this->config['product'] ?? 'EEL Accounts',
                'version' => $this->config['version'] ?? '1.0',
                'email' => $this->config['email'] ?? '',
            ];
        }

        if (!is_array($credentials)) {
            throw new \RuntimeException('HMRC CT XML credentials could not be loaded.');
        }
        $credentials = [
            'sender_id' => trim((string)($credentials['sender_id'] ?? $credentials['username'] ?? '')),
            'password' => (string)($credentials['password'] ?? $credentials['sender_password'] ?? ''),
            'vendor_id' => trim((string)($credentials['vendor_id'] ?? '')),
            'product' => trim((string)($credentials['product'] ?? '')),
            'version' => trim((string)($credentials['version'] ?? '')),
            'email' => trim((string)($credentials['email'] ?? '')),
        ];

        $this->printable($credentials['sender_id'], 'Sender ID', 1, 64);
        $this->printable($credentials['password'], 'password', 1, 128);
        if (!preg_match('/^[0-9]{4}$/D', $credentials['vendor_id'])) {
            throw new \RuntimeException('HMRC XML Vendor ID must contain exactly four digits.');
        }
        $this->printable($credentials['product'], 'product name', 1, 64);
        $this->printable($credentials['version'], 'product version', 1, 32);
        if ($credentials['email'] !== '' && filter_var($credentials['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('HMRC XML contact email is invalid.');
        }

        return $credentials;
    }

    private function printable(string $value, string $label, int $minimum, int $maximum): void
    {
        if (
            strlen($value) < $minimum
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \RuntimeException('HMRC XML ' . $label . ' is missing or invalid.');
        }
    }

    private function utr(string $utr): string
    {
        $utr = preg_replace('/\s+/', '', trim($utr)) ?? '';
        if (!preg_match('/^[0-9]{10}$/D', $utr)) {
            throw new \InvalidArgumentException('Corporation Tax UTR must contain exactly 10 digits.');
        }

        return $utr;
    }

    private function transactionId(?string $transactionId): string
    {
        if ($transactionId === null || trim($transactionId) === '') {
            $transactionId = $this->transactionIdFactory instanceof \Closure
                ? (string)($this->transactionIdFactory)()
                : bin2hex(random_bytes(16));
        }
        $transactionId = strtoupper(trim($transactionId));
        if (!preg_match('/^[0-9A-F]{1,32}$/D', $transactionId)) {
            throw new \InvalidArgumentException(
                'HMRC transaction ID must contain 1 to 32 hexadecimal characters.'
            );
        }

        return $transactionId;
    }

    private function correlationId(string $correlationId): string
    {
        $correlationId = strtoupper(trim($correlationId));
        if (!preg_match('/^[0-9A-F]{1,32}$/D', $correlationId)) {
            throw new \InvalidArgumentException(
                'HMRC correlation ID must contain 1 to 32 hexadecimal characters.'
            );
        }

        return $correlationId;
    }

    private function filingBody(string $xml, string $utr): \DOMDocument
    {
        if ($xml === '' || strlen($xml) > $this->maxMessageBytes) {
            throw new \InvalidArgumentException('The CT600 filing body is empty or exceeds 25 MB.');
        }
        $document = $this->parseXml($xml, 'CT600 filing body');
        $root = $document->documentElement;
        if (
            !$root instanceof \DOMElement
            || $root->localName !== 'IRenvelope'
            || $root->namespaceURI !== self::CT_NAMESPACE
        ) {
            throw new \InvalidArgumentException(
                'CT600 filing body must contain one CT/5 IRenvelope document element.'
            );
        }

        $xpath = new \DOMXPath($document);
        $keys = $xpath->query(
            '/*[local-name()="IRenvelope"]/*[local-name()="IRheader"]'
            . '/*[local-name()="Keys"]/*[local-name()="Key" and @Type="UTR"]'
        );
        if ($keys === false || $keys->length !== 1 || trim((string)$keys->item(0)?->textContent) !== $utr) {
            throw new \DomainException('GovTalk UTR does not match the CT600 IRheader UTR.');
        }
        $irMarks = $xpath->query('//*[local-name()="IRmark"]');
        if ($irMarks === false || $irMarks->length !== 1 || trim((string)$irMarks->item(0)?->textContent) === '') {
            throw new \DomainException('The CT600 filing body does not contain its verified IRmark.');
        }

        return $document;
    }

    private function submissionRequest(
        \DOMDocument $filingBody,
        string $utr,
        array $profile,
        array $credentials,
        string $transactionId
    ): string {
        [$document, $body, $details] = $this->envelope(
            $profile,
            'request',
            'submit',
            $transactionId,
            '',
            true
        );
        $this->text($document, $details, 'GatewayTest', (string)$profile['gateway_test']);

        $header = $details->parentNode;
        if (!$header instanceof \DOMElement) {
            throw new \RuntimeException('Unable to build GovTalk Header.');
        }
        $sender = $this->element($document, $header, 'SenderDetails');
        $idAuthentication = $this->element($document, $sender, 'IDAuthentication');
        $this->text($document, $idAuthentication, 'SenderID', (string)$credentials['sender_id']);
        $authentication = $this->element($document, $idAuthentication, 'Authentication');
        $this->text($document, $authentication, 'Method', 'clear');
        $this->text($document, $authentication, 'Role', 'Principal');
        $this->text($document, $authentication, 'Value', (string)$credentials['password']);
        if ((string)$credentials['email'] !== '') {
            $this->text($document, $sender, 'EmailAddress', (string)$credentials['email']);
        }

        $root = $document->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Unable to build GovTalk document element.');
        }
        $govTalkDetails = $this->element($document, $root, 'GovTalkDetails');
        $keys = $this->element($document, $govTalkDetails, 'Keys');
        $key = $this->text($document, $keys, 'Key', $utr);
        $key->setAttribute('Type', 'UTR');
        $target = $this->element($document, $govTalkDetails, 'TargetDetails');
        $this->text($document, $target, 'Organisation', 'HMRC');
        $routing = $this->element($document, $govTalkDetails, 'ChannelRouting');
        $channel = $this->element($document, $routing, 'Channel');
        $this->text($document, $channel, 'URI', (string)$credentials['vendor_id']);
        $this->text($document, $channel, 'Product', (string)$credentials['product']);
        $this->text($document, $channel, 'Version', (string)$credentials['version']);

        $root->appendChild($body);
        $body->appendChild($document->importNode($filingBody->documentElement, true));
        $xml = $this->saveXml($document);
        if (strlen($xml) > $this->maxMessageBytes) {
            throw new \RuntimeException('The final GovTalk request exceeds 25 MB.');
        }
        $verification = (new \eel_accounts\Service\HmrcIrmarkService())->verify($xml);
        if (empty($verification['ok'])) {
            throw new \DomainException(
                (string)(((array)($verification['errors'] ?? []))[0]
                    ?? 'The final GovTalk request failed IRmark verification.')
            );
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
            false
        );
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Unable to build GovTalk document element.');
        }
        $details = $this->element($document, $root, 'GovTalkDetails');
        $this->element($document, $details, 'Keys');
        $this->element($document, $root, 'Body');

        return $this->saveXml($document);
    }

    /** @return array{0:\DOMDocument,1:\DOMElement,2:\DOMElement} */
    private function envelope(
        array $profile,
        string $qualifier,
        string $function,
        string $transactionId,
        string $correlationId,
        bool $bodyNow
    ): array {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkMessage');
        $document->appendChild($root);
        $this->text($document, $root, 'EnvelopeVersion', '2.0');
        $header = $this->element($document, $root, 'Header');
        $details = $this->element($document, $header, 'MessageDetails');
        $this->text($document, $details, 'Class', (string)$profile['class']);
        $this->text($document, $details, 'Qualifier', $qualifier);
        $this->text($document, $details, 'Function', $function);
        $this->text($document, $details, 'TransactionID', $transactionId);
        $this->text($document, $details, 'CorrelationID', $correlationId);
        $this->text($document, $details, 'Transformation', 'XML');

        // SenderDetails and GovTalkDetails must be inserted before Body, so
        // callers receive an unattached element and append it last.
        $body = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Body');

        return [$document, $body, $details];
    }

    private function element(\DOMDocument $document, \DOMElement $parent, string $name): \DOMElement
    {
        $element = $document->createElementNS(self::ENVELOPE_NAMESPACE, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function text(
        \DOMDocument $document,
        \DOMElement $parent,
        string $name,
        string $value
    ): \DOMElement {
        $element = $this->element($document, $parent, $name);
        if ($value !== '') {
            $element->appendChild($document->createTextNode($value));
        }

        return $element;
    }

    private function exchange(
        string $operation,
        string $requestXml,
        string $endpoint,
        array $profile,
        string $transactionId,
        string $correlationId,
        array $secrets,
        ?callable $beforeSend
    ): array {
        $safeRequest = $this->redactXml($requestXml, $secrets);
        $requestMeta = [
            'operation' => $operation,
            'environment' => (string)$profile['environment'],
            'endpoint' => $endpoint,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'request_xml' => $safeRequest,
            'request_sha256' => hash('sha256', $requestXml),
            'request_bytes' => strlen($requestXml),
        ];
        try {
            if ($beforeSend !== null) {
                $beforeSend($requestMeta);
            }
        } catch (\Throwable $exception) {
            $result = $this->baseResult($operation, $profile, $endpoint, $transactionId, $correlationId);
            $result['pre_send_failure'] = true;
            $result['request_xml'] = $safeRequest;
            $result['request_sha256'] = $requestMeta['request_sha256'];
            $result['request_bytes'] = $requestMeta['request_bytes'];
            $result['error'] = $this->redactText($exception->getMessage(), $secrets);

            return $result;
        }

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
            $result = $this->baseResult($operation, $profile, $endpoint, $transactionId, $correlationId);
            $result['transport_unknown'] = $operation === 'submit';
            $result['request_xml'] = $safeRequest;
            $result['request_sha256'] = $requestMeta['request_sha256'];
            $result['request_bytes'] = $requestMeta['request_bytes'];
            $result['error'] = $this->redactText($exception->getMessage(), $secrets);

            return $result;
        }

        $statusCode = (int)($response['status_code'] ?? 0);
        $responseXml = (string)($response['body'] ?? '');
        try {
            $parsed = $this->parseResponse(
                $responseXml,
                $operation,
                $profile,
                $correlationId,
                $transactionId
            );
        } catch (\Throwable $exception) {
            $result = $this->baseResult($operation, $profile, $endpoint, $transactionId, $correlationId);
            $result['status_code'] = $statusCode;
            $result['headers'] = $this->safeHeaders((array)($response['headers'] ?? []));
            $result['transport_unknown'] = $operation === 'submit';
            $result['request_xml'] = $safeRequest;
            $result['request_sha256'] = $requestMeta['request_sha256'];
            $result['request_bytes'] = $requestMeta['request_bytes'];
            $result['response_xml'] = $this->redactText($responseXml, $secrets);
            $result['error'] = $this->redactText($exception->getMessage(), $secrets);

            return $result;
        }

        $result = array_replace(
            $this->baseResult($operation, $profile, $endpoint, $transactionId, $correlationId),
            $parsed
        );
        $result['status_code'] = $statusCode;
        $result['headers'] = $this->safeHeaders((array)($response['headers'] ?? []));
        $result['request_xml'] = $safeRequest;
        $result['request_sha256'] = $requestMeta['request_sha256'];
        $result['request_bytes'] = $requestMeta['request_bytes'];
        $result['response_xml'] = $this->redactText($responseXml, $secrets);
        if (
            $operation === 'submit'
            && empty($result['success'])
            && (string)($result['protocol_state'] ?? '') === 'failed'
            && ($result['business_outcome'] ?? null) === null
        ) {
            // Bytes left this process but the response cannot be tied to a
            // definitive business outcome. Never permit a blind retry.
            $result['transport_unknown'] = true;
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $result['success'] = false;
            $result['protocol_state'] = 'failed';
            $result['business_outcome'] = null;
            $result['transport_unknown'] = $operation === 'submit';
            $result['errors'][] = [
                'raised_by' => 'HTTP',
                'number' => (string)$statusCode,
                'type' => 'transport',
                'texts' => ['HMRC Transaction Engine returned HTTP status ' . $statusCode . '.'],
                'locations' => [],
            ];
            $result['error'] = $this->errorMessage($result['errors']);
        }
        $result['errors'] = $this->redactPayload((array)($result['errors'] ?? []), $secrets);
        $result['error'] = $this->redactText((string)($result['error'] ?? ''), $secrets);

        return $result;
    }

    private function parseResponse(
        string $xml,
        string $operation,
        array $profile,
        string $expectedCorrelationId,
        string $expectedTransactionId
    ): array
    {
        if ($xml === '' || strlen($xml) > $this->maxResponseBytes) {
            throw new \RuntimeException('HMRC Transaction Engine response is empty or too large.');
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
        $details = $this->first(
            $xpath,
            '/*[local-name()="GovTalkMessage"]/*[local-name()="Header"]'
            . '/*[local-name()="MessageDetails"]'
        );
        if (!$details instanceof \DOMElement) {
            throw new \RuntimeException('HMRC response omitted MessageDetails.');
        }

        $class = $this->childText($xpath, $details, 'Class');
        $qualifier = strtolower($this->childText($xpath, $details, 'Qualifier'));
        $function = strtolower($this->childText($xpath, $details, 'Function'));
        $transactionId = strtoupper($this->childText($xpath, $details, 'TransactionID'));
        $correlationId = strtoupper($this->childText($xpath, $details, 'CorrelationID'));
        $responseNode = $this->child($xpath, $details, 'ResponseEndPoint');
        $responseEndpoint = $responseNode instanceof \DOMElement ? trim($responseNode->textContent) : '';
        $pollInterval = null;
        if ($responseNode instanceof \DOMElement && $responseNode->hasAttribute('PollInterval')) {
            $raw = trim($responseNode->getAttribute('PollInterval'));
            if ($raw !== '' && !preg_match('/^[0-9]+$/D', $raw)) {
                throw new \RuntimeException('HMRC returned an invalid poll interval.');
            }
            $pollInterval = $raw === '' ? null : max($this->minimumPollInterval, (int)$raw);
        }
        if ($correlationId !== '' && !preg_match('/^[0-9A-F]{1,32}$/D', $correlationId)) {
            throw new \RuntimeException('HMRC returned an invalid correlation ID.');
        }
        if ($responseEndpoint !== '') {
            $responseEndpoint = HmrcCtTransactionEngineEnvironment::responseEndpoint(
                $responseEndpoint,
                (string)$profile['environment']
            );
        } elseif ($correlationId !== '') {
            $responseEndpoint = (string)$profile['poll_url'];
        }

        $errors = $this->errors($xpath);
        if ($transactionId === '') {
            $errors[] = $this->clientError(
                'MISSING_TRANSACTION_ID',
                'HMRC response omitted the transaction ID for this request.'
            );
        } elseif (
            !preg_match('/^[0-9A-F]{1,32}$/D', $transactionId)
            || !hash_equals($expectedTransactionId, $transactionId)
        ) {
            $errors[] = $this->clientError(
                'RESPONSE_TRANSACTION_MISMATCH',
                'HMRC response transaction ID did not match the request.'
            );
        }
        if ($class !== (string)$profile['class']) {
            $errors[] = $this->clientError(
                'RESPONSE_CLASS_MISMATCH',
                'HMRC response class did not match the selected filing environment.'
            );
        }
        $expectedFunction = $operation === 'delete' ? 'delete' : 'submit';
        if ($function !== $expectedFunction) {
            $errors[] = $this->clientError(
                'RESPONSE_FUNCTION_MISMATCH',
                'HMRC response function did not match the request.'
            );
        }
        if (
            $expectedCorrelationId !== ''
            && !hash_equals($expectedCorrelationId, $correlationId)
        ) {
            $errors[] = $this->clientError(
                'RESPONSE_CORRELATION_MISMATCH',
                'HMRC response correlation ID did not match the open conversation.'
            );
        }

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
                $errors[] = $this->clientError(
                    'MISSING_CORRELATION_ID',
                    'HMRC acknowledgement omitted its correlation ID.'
                );
            } else {
                $protocolState = 'acknowledged';
                $success = true;
            }
        } elseif ($qualifier === 'response' && $function === 'submit') {
            if ($correlationId === '') {
                $errors[] = $this->clientError(
                    'MISSING_CORRELATION_ID',
                    'HMRC final response omitted its correlation ID.'
                );
            }
            $protocolState = 'final_response';
            $businessOutcome = $errors === [] ? 'accepted' : 'rejected';
            $success = $errors === [];
            $cleanupRequired = $correlationId !== '';
        } elseif ($qualifier === 'error' && $function === 'submit') {
            if ($this->isGatewaySubmissionError($errors)) {
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
            $deleteNotFound = $this->hasError($errors, '2000');
            if ($deleteNotFound) {
                $protocolState = 'deleted';
                $success = true;
            }
        }
        if (!$success && $errors === []) {
            $errors[] = $this->clientError(
                'UNEXPECTED_RESPONSE',
                'HMRC Transaction Engine returned an unexpected response.'
            );
        }
        foreach ($errors as $error) {
            if ((string)($error['raised_by'] ?? '') !== 'Client') {
                continue;
            }
            $number = (string)($error['number'] ?? '');
            if (in_array($number, [
                'RESPONSE_CLASS_MISMATCH', 'RESPONSE_FUNCTION_MISMATCH',
                'RESPONSE_CORRELATION_MISMATCH', 'MISSING_CORRELATION_ID',
                'MISSING_TRANSACTION_ID', 'RESPONSE_TRANSACTION_MISMATCH',
            ], true)) {
                $success = false;
                $protocolState = 'failed';
                $businessOutcome = null;
                $cleanupRequired = false;
                break;
            }
        }

        return [
            'success' => $success,
            'protocol_state' => $protocolState,
            'business_outcome' => $businessOutcome,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => $responseEndpoint,
            'poll_interval' => $pollInterval,
            'cleanup_required' => $cleanupRequired,
            'delete_not_found' => $deleteNotFound,
            'qualifier' => $qualifier,
            'function' => $function,
            'errors' => $errors,
            'body_xml' => $this->bodyXml($document, $xpath),
            'error' => $success ? '' : $this->errorMessage($errors),
        ];
    }

    private function errors(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//*[local-name()="Error"]');
        $errors = [];
        if ($nodes === false) {
            return $errors;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $texts = $this->childTexts($xpath, $node, 'Text');
            if ($texts === []) {
                $description = $this->childText($xpath, $node, 'Description');
                $texts = $description === '' ? [] : [$description];
            }
            $errors[] = [
                'raised_by' => $this->childText($xpath, $node, 'RaisedBy'),
                'number' => $this->childText($xpath, $node, 'Number'),
                'type' => strtolower($this->childText($xpath, $node, 'Type')),
                'texts' => $texts,
                'locations' => $this->childTexts($xpath, $node, 'Location'),
            ];
        }

        return $errors;
    }

    private function clientError(string $number, string $message): array
    {
        return [
            'raised_by' => 'Client',
            'number' => $number,
            'type' => 'protocol',
            'texts' => [$message],
            'locations' => [],
        ];
    }

    private function bodyXml(\DOMDocument $document, \DOMXPath $xpath): string
    {
        $body = $this->first(
            $xpath,
            '/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]'
        );
        if (!$body instanceof \DOMElement) {
            return '';
        }
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return (string)$document->saveXML($child);
            }
        }

        return '';
    }

    private function parseXml(string $xml, string $label): \DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException($label . ' is empty or contains a prohibited declaration.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \RuntimeException($label . ' is malformed XML.');
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

    private function first(\DOMXPath $xpath, string $query): ?\DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function child(\DOMXPath $xpath, \DOMElement $parent, string $name): ?\DOMElement
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $parent);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function childText(\DOMXPath $xpath, \DOMElement $parent, string $name): string
    {
        $node = $this->child($xpath, $parent, $name);

        return $node instanceof \DOMElement ? trim($node->textContent) : '';
    }

    private function childTexts(\DOMXPath $xpath, \DOMElement $parent, string $name): array
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $parent);
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

    private function hasError(array $errors, string $number): bool
    {
        foreach ($errors as $error) {
            if ((string)($error['number'] ?? '') === $number) {
                return true;
            }
        }

        return false;
    }

    private function isGatewaySubmissionError(array $errors): bool
    {
        foreach ($errors as $error) {
            if (
                strcasecmp(trim((string)($error['raised_by'] ?? '')), 'Gateway') === 0
                && strtolower(trim((string)($error['type'] ?? ''))) === 'fatal'
            ) {
                return true;
            }
        }

        return false;
    }

    private function errorMessage(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            $number = trim((string)($error['number'] ?? ''));
            foreach ((array)($error['texts'] ?? []) as $text) {
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
            'pre_send_failure' => false,
            'transport_unknown' => false,
            'operation' => $operation,
            'status_code' => 0,
            'headers' => [],
            'endpoint' => $endpoint,
            'environment' => (string)$profile['environment'],
            'class' => (string)$profile['class'],
            'statutory' => (bool)$profile['statutory'],
            'protocol_state' => 'failed',
            'business_outcome' => null,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => '',
            'poll_interval' => null,
            'cleanup_required' => false,
            'delete_not_found' => false,
            'qualifier' => '',
            'function' => '',
            'errors' => [],
            'request_xml' => '',
            'request_sha256' => '',
            'request_bytes' => 0,
            'response_xml' => '',
            'body_xml' => '',
            'error' => '',
        ];
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
                'class' => '',
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
        $result['pre_send_failure'] = true;
        $result['error'] = $error;

        return $result;
    }

    private function secretValues(array $credentials): array
    {
        $values = array_values(array_filter([
            (string)($credentials['sender_id'] ?? ''),
            (string)($credentials['password'] ?? ''),
        ], static fn(string $value): bool => $value !== ''));
        usort($values, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_values(array_unique($values));
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
                    $node->textContent = '[REDACTED]';
                }
            }
            $saved = $document->saveXML();
            if (is_string($saved) && $saved !== '') {
                $xml = $saved;
            }
        } catch (\Throwable) {
            // Direct replacement below remains the fail-safe redaction path.
        }

        return $this->redactText($xml, $secrets);
    }

    private function redactText(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[REDACTED]', $text);
            $text = str_replace(
                htmlspecialchars($secret, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                '[REDACTED]',
                $text
            );
        }

        return $text;
    }

    private function redactPayload(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            return $this->redactText($value, $secrets);
        }
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->redactPayload($item, $secrets);
        }

        return $value;
    }

    private function safeHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            $normalised = strtolower(trim((string)$name));
            if (in_array($normalised, ['content-type', 'date', 'x-correlation-id', 'x-request-id'], true)) {
                $safe[$normalised] = is_array($value) ? array_map('strval', $value) : (string)$value;
            }
        }

        return $safe;
    }
}
