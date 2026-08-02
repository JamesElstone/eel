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

    public function parseArchivedResponse(
        string $responseXml,
        string $operation,
        string $environment,
        string $expectedCorrelationId,
        string $expectedOriginalSubmissionTransactionId,
        string $expectedTransactionId,
        array $boundConversationTransactionIds = []
    ): array {
        $operation = strtolower(trim($operation));
        if (!in_array($operation, ['submit', 'poll', 'delete'], true)) {
            throw new \InvalidArgumentException('The archived HMRC GovTalk operation is invalid.');
        }
        $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
        $expectedCorrelationId = strtoupper(trim($expectedCorrelationId));
        $expectedOriginalSubmissionTransactionId = $this->requiredTransactionId(
            $expectedOriginalSubmissionTransactionId,
            'archived HMRC original submission transaction ID'
        );
        $expectedTransactionId = $this->requiredTransactionId(
            $expectedTransactionId,
            'archived HMRC request transaction ID'
        );
        if ($expectedCorrelationId !== ''
            && preg_match('/^[0-9A-F]{1,32}$/D', $expectedCorrelationId) !== 1) {
            throw new \InvalidArgumentException('The archived HMRC correlation ID is invalid.');
        }

        $parsed = $this->parseResponse(
            $responseXml,
            $operation,
            $profile,
            $expectedCorrelationId,
            $expectedOriginalSubmissionTransactionId,
            $expectedTransactionId,
            $boundConversationTransactionIds
        );
        $endpoint = $operation === 'submit'
            ? (string)$profile['submission_url']
            : (string)$profile['poll_url'];
        $result = array_replace(
            $this->baseResult(
                $operation,
                $profile,
                $endpoint,
                $expectedTransactionId,
                $expectedCorrelationId
            ),
            $parsed
        );
        try {
            $secrets = $this->secretValues($this->credentials($profile));
        } catch (\Throwable) {
            // Archived responses are immutable evidence and must remain
            // processable if credentials are absent or have since rotated.
            $secrets = [];
        }
        $result['errors'] = $this->redactPayload((array)($result['errors'] ?? []), $secrets);
        $result['error'] = $this->redactText((string)($result['error'] ?? ''), $secrets);

        return $result;
    }

    /**
     * Build the exact environment-specific submit request without performing
     * transport. Missing sender credentials are replaced with explicit,
     * non-transmittable developer placeholders.
     */
    public function prepareSubmissionRequest(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = null;
        $credentials = [];
        try {
            $prepared = $this->prepareSubmissionRequestData(
                $filingBodyXml,
                $utr,
                $environment,
                $transactionId,
                true
            );
            $profile = $prepared['profile'];
            $credentials = $prepared['credentials'];
            $transactionId = $prepared['transaction_id'];
            $requestXml = $prepared['request_xml'];
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

        $result = $this->baseResult(
            'submit',
            $profile,
            (string)$profile['submission_url'],
            $transactionId,
            ''
        );
        $result['success'] = true;
        $result['protocol_state'] = 'prepared';
        $result['request_xml'] = $requestXml;
        $result['raw_request_xml'] = $requestXml;
        $result['request_sha256'] = hash('sha256', $requestXml);
        $result['request_bytes'] = strlen($requestXml);
        $result['credentials_placeholder'] = !empty($prepared['credentials_placeholder']);

        return $result;
    }

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        GovTalkConversationContext $conversation,
        ?string $transactionId = null
    ): array {
        $profile = null;
        $credentials = [];
        try {
            $prepared = $this->prepareSubmissionRequestData(
                $filingBodyXml,
                $utr,
                $environment,
                $transactionId
            );
            $profile = $prepared['profile'];
            $credentials = $prepared['credentials'];
            $transactionId = $prepared['transaction_id'];
            $requestXml = $prepared['request_xml'];
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
            $transactionId,
            [],
            '',
            $this->secretValues($credentials),
            $conversation
        );
    }

    /**
     * @return array{
     *   profile:array<string,mixed>,
     *   credentials:array<string,string>,
     *   credentials_placeholder:bool,
     *   transaction_id:string,
     *   request_xml:string
     * }
     */
    private function prepareSubmissionRequestData(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId,
        bool $allowCredentialPlaceholders = false
    ): array {
        $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
        $credentialsPlaceholder = false;
        try {
            $credentials = $this->credentials($profile);
        } catch (\Throwable $exception) {
            if (!$allowCredentialPlaceholders) {
                throw $exception;
            }
            $credentials = $this->developerPlaceholderCredentials();
            $credentialsPlaceholder = true;
        }
        $utr = $this->utr($utr);
        $transactionId = $this->transactionId($transactionId);
        $document = $this->filingBody($filingBodyXml, $utr);

        return [
            'profile' => $profile,
            'credentials' => $credentials,
            'credentials_placeholder' => $credentialsPlaceholder,
            'transaction_id' => $transactionId,
            'request_xml' => $this->submissionRequest(
                $document,
                $utr,
                $profile,
                $credentials,
                $transactionId
            ),
        ];
    }

    /** @return array<string,string> */
    private function developerPlaceholderCredentials(): array
    {
        return [
            'sender_id' => 'DEVELOPER-SENDER-ID',
            'password' => 'DEVELOPER-PASSWORD',
            'vendor_id' => '0000',
            'product' => 'EEL Accounts',
            'version' => '1.0',
            'email' => '',
        ];
    }

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        GovTalkConversationContext $conversation,
        string $expectedOriginalSubmissionTransactionId,
        ?string $transactionId = null,
        array $boundConversationTransactionIds = []
    ): array {
        $profile = null;
        try {
            $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
            $correlationId = $this->correlationId($correlationId);
            $expectedOriginalSubmissionTransactionId = $this->requiredTransactionId(
                $expectedOriginalSubmissionTransactionId,
                'original submission transaction ID'
            );
            $transactionId = $this->transactionId($transactionId);
            $boundConversationTransactionIds = $this->boundTransactionIds(
                $boundConversationTransactionIds,
                $expectedOriginalSubmissionTransactionId,
                $transactionId
            );
            $endpoint = HmrcCtTransactionEngineEnvironment::pollEndpoint(
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
            $expectedOriginalSubmissionTransactionId,
            $boundConversationTransactionIds,
            $correlationId,
            [],
            $conversation
        );
    }

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        GovTalkConversationContext $conversation,
        string $expectedOriginalSubmissionTransactionId,
        ?string $transactionId = null,
        array $boundConversationTransactionIds = []
    ): array {
        $profile = null;
        try {
            $profile = HmrcCtTransactionEngineEnvironment::profile($environment);
            $correlationId = $this->correlationId($correlationId);
            $expectedOriginalSubmissionTransactionId = $this->requiredTransactionId(
                $expectedOriginalSubmissionTransactionId,
                'original submission transaction ID'
            );
            $transactionId = $this->transactionId($transactionId);
            $boundConversationTransactionIds = $this->boundTransactionIds(
                $boundConversationTransactionIds,
                $expectedOriginalSubmissionTransactionId,
                $transactionId
            );
            $endpoint = HmrcCtTransactionEngineEnvironment::followUpEndpoint(
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
            $expectedOriginalSubmissionTransactionId,
            $boundConversationTransactionIds,
            $correlationId,
            [],
            $conversation
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
                'XML',
                $tag,
                (string)$profile['credential_environment'],
                $keysPath === '' ? \SecurityStore::apiKeysPath() : $keysPath
            );
            $credentials = [
                'sender_id' => (string)($stored['api_identity'] ?? ''),
                'password' => (string)($stored['api_key'] ?? ''),
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

    private function requiredTransactionId(string $transactionId, string $label): string
    {
        $transactionId = strtoupper(trim($transactionId));
        if (preg_match('/^[0-9A-F]{1,32}$/D', $transactionId) !== 1) {
            throw new \InvalidArgumentException(
                'HMRC ' . $label . ' must contain 1 to 32 hexadecimal characters.'
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
        $draft = (new GovTalkEnvelopeBuilder())->create(
            '2.0',
            (string)$profile['class'],
            $qualifier,
            $transactionId,
            $function,
            $correlationId,
            null,
            'XML'
        );
        $document = $draft->document;
        $body = $draft->body;
        $details = $draft->messageDetails;

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
            $element->appendChild($document->createTextNode(\eel_accounts\Support\Utf8::normalize($value)));
        }

        return $element;
    }

    private function exchange(
        string $operation,
        string $requestXml,
        string $endpoint,
        array $profile,
        string $transactionId,
        string $expectedOriginalSubmissionTransactionId,
        array $boundConversationTransactionIds,
        string $correlationId,
        array $secrets,
        GovTalkConversationContext $conversation
    ): array {
        $safeRequest = $this->redactXml($requestXml, $secrets);
        $transportRequest = [
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
        $prepared = new GovTalkPreparedRequest(
            'hmrc',
            $operation,
            (string)$profile['environment'],
            $endpoint,
            $transactionId,
            $correlationId,
            $requestXml,
            $transportRequest
        );
        $handler = new GovTalkExchangeHandler(
            function (array $request): array {
                $response = $this->httpTransport instanceof \Closure
                    ? ($this->httpTransport)($request)
                    : \ApiHelperOutbound::request($request);
                if (!is_array($response)) {
                    throw new \RuntimeException(
                        'HMRC Transaction Engine transport returned an invalid response.'
                    );
                }

                return $response;
            },
            null,
            fn(string $message): string => $this->redactText($message, $secrets)
        );
        $exchange = $handler->execute(
            $prepared,
            $conversation,
            fn(
                GovTalkPreparedRequest $unusedRequest,
                GovTalkRawResponse $response
            ): array => $this->parseResponse(
                $response->body,
                $operation,
                $profile,
                $correlationId,
                $expectedOriginalSubmissionTransactionId,
                $transactionId,
                $boundConversationTransactionIds
            )
        );
        $result = array_replace(
            $this->baseResult(
                $operation,
                $profile,
                $endpoint,
                $transactionId,
                $correlationId
            ),
            $exchange->toArray()
        );
        $response = $exchange->response;
        $statusCode = $response?->statusCode ?? (int)($result['status_code'] ?? 0);
        $responseXml = $response?->body ?? '';
        $result['status_code'] = $statusCode;
        $result['headers'] = $response?->headers ?? [];
        $result['request_xml'] = $safeRequest;
        $result['request_sha256'] = $prepared->sha256;
        $result['request_bytes'] = $prepared->bytes;
        $result['response_xml'] = $this->redactText($responseXml, $secrets);
        if (!empty($result['evidence_incomplete'])) {
            $result['evidence_error'] = (string)$result['error'];
        }
        if ($response === null) {
            $result['errors'] = $this->redactPayload(
                (array)($result['errors'] ?? []),
                $secrets
            );
            $result['error'] = $this->redactText(
                (string)($result['error'] ?? ''),
                $secrets
            );

            return $result;
        }
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
        string $expectedOriginalSubmissionTransactionId,
        string $expectedTransactionId,
        array $boundConversationTransactionIds = []
    ): array {
        $boundConversationTransactionIds = $this->boundTransactionIds(
            $boundConversationTransactionIds,
            $expectedOriginalSubmissionTransactionId,
            $expectedTransactionId
        );
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
        $responseTransactionId = strtoupper($this->childText($xpath, $details, 'TransactionID'));
        $correlationId = strtoupper($this->childText($xpath, $details, 'CorrelationID'));
        $responseNode = $this->child($xpath, $details, 'ResponseEndPoint');
        $responseEndpoint = $responseNode instanceof \DOMElement ? trim($responseNode->textContent) : '';
        $rawPollInterval = null;
        $pollInterval = null;
        if ($responseNode instanceof \DOMElement && $responseNode->hasAttribute('PollInterval')) {
            $rawPollInterval = trim($responseNode->getAttribute('PollInterval'));
        }
        if ($correlationId !== '' && !preg_match('/^[0-9A-F]{1,32}$/D', $correlationId)) {
            throw new \RuntimeException('HMRC returned an invalid correlation ID.');
        }

        $govTalkErrors = $this->govTalkErrors($xpath);
        $departmentErrors = $this->departmentErrors($xpath);
        $errors = array_merge($govTalkErrors, $departmentErrors);
        $departmentErrorCount = count($departmentErrors);
        $isAcknowledgement = $operation !== 'delete' && in_array(
            $qualifier,
            ['acknowledgement', 'acknowledgment'],
            true
        ) && $function === 'submit';
        $isFinalResponse = $operation !== 'delete'
            && $qualifier === 'response'
            && $function === 'submit';
        $isProtocolError = $qualifier === 'error';
        $isSubmitError = $isProtocolError && $function === 'submit';
        $isDepartmentRejection = $isSubmitError
            && $this->hasDepartmentBusinessError($govTalkErrors);
        $isGatewayError = $isProtocolError
            && $this->hasRaisedBy($govTalkErrors, 'Gateway');
        $isDeleteResponse = $operation === 'delete'
            && $qualifier === 'response'
            && $function === 'delete';
        $deleteNotFound = $operation === 'delete'
            && $isGatewayError
            && in_array($function, ['submit', 'delete'], true)
            && $this->hasError($govTalkErrors, '2000');

        if (!$isDeleteResponse && !$deleteNotFound && $rawPollInterval !== null) {
            if ($rawPollInterval !== '' && !preg_match('/^[0-9]+$/D', $rawPollInterval)) {
                throw new \RuntimeException('HMRC returned an invalid poll interval.');
            }
            $pollInterval = $rawPollInterval === ''
                ? null
                : max($this->minimumPollInterval, (int)$rawPollInterval);
        }

        if (
            $operation === 'submit'
            && $expectedCorrelationId === ''
            && $class === 'UndefinedClass'
            && $isSubmitError
            && $isGatewayError
            && ($responseTransactionId === ''
                || hash_equals($expectedTransactionId, $responseTransactionId))
            && $correlationId === ''
            && $this->hasFatalError($govTalkErrors)
        ) {
            $validatedEndpoint = HmrcCtTransactionEngineEnvironment::followUpEndpoint(
                $responseEndpoint,
                (string)$profile['environment']
            );
            if (parse_url($validatedEndpoint, PHP_URL_PATH) !== '/submission') {
                throw new \RuntimeException(
                    'HMRC pre-conversation rejection returned an invalid response endpoint.'
                );
            }
            // Authentication and other fatal Gateway rejections happen before
            // HMRC opens a filing conversation. In that response class HMRC
            // deliberately returns UndefinedClass, blank protocol IDs and the
            // generic /submission endpoint, so those values are not evidence
            // of an uncertain transport outcome.
            return [
                'success' => false,
                'protocol_state' => 'gateway_rejected',
                'business_outcome' => null,
                'transaction_id' => $expectedTransactionId,
                'response_transaction_id' => $responseTransactionId,
                'correlation_id' => '',
                'response_endpoint' => '',
                'poll_interval' => null,
                'cleanup_required' => false,
                'delete_not_found' => false,
                'qualifier' => $qualifier,
                'function' => $function,
                'errors' => $errors,
                'departmental_error_count' => $departmentErrorCount,
                'body_xml' => $this->bodyXml($document, $xpath),
                'error' => $this->errorMessage($errors, $departmentErrorCount),
            ];
        }

        $requiresPollEndpoint = $isAcknowledgement
            || ($operation === 'poll'
                && $isGatewayError
                && $expectedCorrelationId !== '');
        $requiresFollowUpEndpoint = $isFinalResponse
            || $isDepartmentRejection
            || ($operation === 'delete'
                && $isGatewayError
                && $expectedCorrelationId !== ''
                && !$deleteNotFound);
        if ($isDeleteResponse || $deleteNotFound) {
            // A successful DELETE closes the conversation. HMRC documents the
            // returned endpoint as having no further protocol significance.
            $responseEndpoint = '';
            $pollInterval = null;
        } elseif ($requiresPollEndpoint) {
            if ($responseEndpoint === '') {
                $errors[] = $this->clientError(
                    'MISSING_RESPONSE_ENDPOINT',
                    'HMRC acknowledgement omitted its polling endpoint.'
                );
            } else {
                $responseEndpoint = HmrcCtTransactionEngineEnvironment::pollEndpoint(
                    $responseEndpoint,
                    (string)$profile['environment']
                );
            }
        } elseif ($requiresFollowUpEndpoint) {
            if ($responseEndpoint === '') {
                $errors[] = $this->clientError(
                    'MISSING_RESPONSE_ENDPOINT',
                    'HMRC response omitted its follow-up endpoint.'
                );
            } else {
                $responseEndpoint = HmrcCtTransactionEngineEnvironment::followUpEndpoint(
                    $responseEndpoint,
                    (string)$profile['environment']
                );
            }
        } else {
            // Do not persist or act on an endpoint from an unexpected shape.
            $responseEndpoint = '';
        }
        if (($requiresPollEndpoint || $requiresFollowUpEndpoint) && $pollInterval === null) {
            $errors[] = $this->clientError(
                'MISSING_POLL_INTERVAL',
                $requiresPollEndpoint
                    ? 'HMRC acknowledgement omitted its polling interval.'
                    : 'HMRC response omitted its follow-up interval.'
            );
        }

        $allowedResponseTransactionIds = [$expectedTransactionId];
        if ($isFinalResponse || $isDepartmentRejection) {
            $allowedResponseTransactionIds = [$expectedOriginalSubmissionTransactionId];
        } elseif ($isGatewayError) {
            $allowedResponseTransactionIds = $boundConversationTransactionIds;
        }
        if ($responseTransactionId !== '' && (
            !preg_match('/^[0-9A-F]{1,32}$/D', $responseTransactionId)
            || !$this->matchesAnyTransactionId(
                $responseTransactionId,
                $allowedResponseTransactionIds
            )
        )) {
            $errors[] = $this->clientError(
                'RESPONSE_TRANSACTION_MISMATCH',
                'HMRC response transaction ID did not match the expected GovTalk exchange.'
            );
        }
        if ($class !== (string)$profile['class']) {
            $errors[] = $this->clientError(
                'RESPONSE_CLASS_MISMATCH',
                'HMRC response class did not match the selected filing environment.'
            );
        }
        $functionMatches = $operation === 'delete'
            ? ($function === 'delete' || ($function === 'submit' && $isGatewayError))
            : $function === 'submit';
        if (!$functionMatches) {
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
        if ($isAcknowledgement && !$this->hasClientProtocolError($errors)) {
            // An acknowledgement carrying application errors is not a valid
            // instruction to continue the conversation.
            if ($errors === []) {
                if ($correlationId === '') {
                    $errors[] = $this->clientError(
                        'MISSING_CORRELATION_ID',
                        'HMRC acknowledgement omitted its correlation ID.'
                    );
                } else {
                    $protocolState = 'acknowledged';
                    $success = true;
                }
            }
        } elseif ($isFinalResponse) {
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
        } elseif ($isDepartmentRejection) {
            $protocolState = $correlationId === '' ? 'failed' : 'final_response';
            $businessOutcome = 'rejected';
            $cleanupRequired = $correlationId !== '';
        } elseif ($isGatewayError) {
            if ($deleteNotFound) {
                $protocolState = 'deleted';
                $success = true;
            } else {
                $protocolState = 'submission_error';
                $cleanupRequired = $operation === 'delete';
            }
        } elseif ($isDeleteResponse && $errors === []) {
            $protocolState = 'deleted';
            $success = true;
        }
        if (!$success && $errors === []) {
            $errors[] = $this->clientError(
                'UNEXPECTED_RESPONSE',
                'HMRC Transaction Engine returned an unexpected response.'
            );
        }
        if ($this->hasClientProtocolError($errors)) {
            $success = false;
            $protocolState = 'failed';
            $businessOutcome = null;
            $cleanupRequired = false;
        }
        if ($success && $protocolState === 'deleted') {
            $responseEndpoint = '';
            $pollInterval = null;
        }

        return [
            'success' => $success,
            'protocol_state' => $protocolState,
            'business_outcome' => $businessOutcome,
            'transaction_id' => $expectedTransactionId,
            'response_transaction_id' => $responseTransactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => $responseEndpoint,
            'poll_interval' => $pollInterval,
            'cleanup_required' => $cleanupRequired,
            'delete_not_found' => $deleteNotFound,
            'qualifier' => $qualifier,
            'function' => $function,
            'errors' => $errors,
            'departmental_error_count' => $departmentErrorCount,
            'body_xml' => $this->bodyXml($document, $xpath),
            'error' => $success
                ? ''
                : $this->errorMessage($errors, $departmentErrorCount),
        ];
    }

    private function govTalkErrors(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query(
            '/*[local-name()="GovTalkMessage"]/*[local-name()="GovTalkDetails"]'
            . '/*[local-name()="GovTalkErrors"]/*[local-name()="Error"]'
        );

        return $this->normalisedErrors($xpath, $nodes, 'govtalk');
    }

    private function departmentErrors(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query(
            '/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]'
            . '/*[local-name()="ErrorResponse"]/*[local-name()="Error"]'
        );

        return $this->normalisedErrors($xpath, $nodes, 'department');
    }

    /** @param false|\DOMNodeList $nodes */
    private function normalisedErrors(
        \DOMXPath $xpath,
        false|\DOMNodeList $nodes,
        string $source
    ): array {
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
                'source' => $source,
                'raised_by' => $this->childText($xpath, $node, 'RaisedBy'),
                'number' => $this->childText($xpath, $node, 'Number'),
                'type' => $this->childText($xpath, $node, 'Type'),
                'texts' => $texts,
                'locations' => $this->childTexts($xpath, $node, 'Location'),
            ];
        }

        return $errors;
    }

    private function clientError(string $number, string $message): array
    {
        return [
            'source' => 'client',
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

    /** @param list<string> $allowed */
    private function matchesAnyTransactionId(string $transactionId, array $allowed): bool
    {
        foreach ($allowed as $expected) {
            if ($expected !== '' && hash_equals($expected, $transactionId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,mixed> $bound
     * @return list<string>
     */
    private function boundTransactionIds(
        array $bound,
        string $originalSubmissionTransactionId,
        string $currentTransactionId
    ): array {
        $normalised = [];
        foreach (array_merge(
            $bound,
            [$originalSubmissionTransactionId, $currentTransactionId]
        ) as $transactionId) {
            if (!is_string($transactionId)) {
                throw new \InvalidArgumentException(
                    'A bound HMRC conversation transaction ID is invalid.'
                );
            }
            $transactionId = strtoupper(trim($transactionId));
            if (preg_match('/^[0-9A-F]{1,32}$/D', $transactionId) !== 1) {
                throw new \InvalidArgumentException(
                    'A bound HMRC conversation transaction ID is invalid.'
                );
            }
            $normalised[$transactionId] = true;
        }

        return array_keys($normalised);
    }

    private function hasRaisedBy(array $errors, string $raisedBy): bool
    {
        foreach ($errors as $error) {
            if (strcasecmp(trim((string)($error['raised_by'] ?? '')), $raisedBy) === 0) {
                return true;
            }
        }

        return false;
    }

    private function hasFatalError(array $errors): bool
    {
        foreach ($errors as $error) {
            if (strtolower(trim((string)($error['type'] ?? ''))) === 'fatal') {
                return true;
            }
        }

        return false;
    }

    private function hasDepartmentBusinessError(array $errors): bool
    {
        foreach ($errors as $error) {
            if (
                strcasecmp(trim((string)($error['raised_by'] ?? '')), 'Department') === 0
                && in_array((string)($error['number'] ?? ''), ['3000', '3001'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasClientProtocolError(array $errors): bool
    {
        foreach ($errors as $error) {
            if (
                strcasecmp(trim((string)($error['raised_by'] ?? '')), 'Client') === 0
                && strtolower(trim((string)($error['type'] ?? ''))) === 'protocol'
            ) {
                return true;
            }
        }

        return false;
    }

    private function errorMessage(array $errors, int $departmentErrorCount = 0): string
    {
        $primary = null;
        foreach ($errors as $error) {
            if (strcasecmp(trim((string)($error['raised_by'] ?? '')), 'Client') === 0) {
                $primary = $error;
                break;
            }
            $primary ??= $error;
        }
        $message = '';
        if (is_array($primary)) {
            $number = trim((string)($primary['number'] ?? ''));
            foreach ((array)($primary['texts'] ?? []) as $text) {
                $text = trim((string)$text);
                if ($text !== '') {
                    $message = ($number === '' ? '' : $number . ': ') . $text;
                    break;
                }
            }
        }
        if ($message === '') {
            $message = 'HMRC Transaction Engine rejected the request.';
        }
        if ($departmentErrorCount > 0) {
            $message .= ' ' . $departmentErrorCount . ' departmental validation '
                . ($departmentErrorCount === 1 ? 'error was' : 'errors were')
                . ' returned.';
        }

        return $message;
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
            'response_transaction_id' => '',
            'correlation_id' => $correlationId,
            'response_endpoint' => '',
            'poll_interval' => null,
            'cleanup_required' => false,
            'delete_not_found' => false,
            'qualifier' => '',
            'function' => '',
            'errors' => [],
            'departmental_error_count' => 0,
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
                \eel_accounts\Support\Utf8::xml($secret),
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
