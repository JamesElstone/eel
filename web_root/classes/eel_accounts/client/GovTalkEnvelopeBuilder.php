<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

final class GovTalkEnvelopeBuilder
{
    public const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    public const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    public function create(
        string $version,
        string $class,
        string $qualifier,
        string $transactionId,
        ?string $function = null,
        ?string $correlationId = null,
        ?string $gatewayTest = null,
        ?string $transformation = null,
        ?string $schemaLocation = null
    ): GovTalkEnvelopeDraft {
        foreach ([
            'version' => $version,
            'class' => $class,
            'qualifier' => $qualifier,
            'transaction ID' => $transactionId,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException('A GovTalk ' . $label . ' is required.');
            }
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkMessage');
        if ($schemaLocation !== null && trim($schemaLocation) !== '') {
            $root->setAttributeNS(
                self::XSI_NAMESPACE,
                'xsi:schemaLocation',
                self::ENVELOPE_NAMESPACE . ' ' . trim($schemaLocation)
            );
        }
        $document->appendChild($root);
        $header = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Header');
        $details = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'MessageDetails');
        $body = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'Body');
        $draft = new GovTalkEnvelopeDraft(
            $document,
            $root,
            $header,
            $details,
            $body,
            self::ENVELOPE_NAMESPACE
        );

        $draft->text($root, 'EnvelopeVersion', $version);
        $root->appendChild($header);
        $header->appendChild($details);
        $draft->text($details, 'Class', $class);
        $draft->text($details, 'Qualifier', $qualifier);
        if ($function !== null) {
            $draft->text($details, 'Function', $function);
        }
        $draft->text($details, 'TransactionID', $transactionId);
        if ($correlationId !== null) {
            $draft->text($details, 'CorrelationID', $correlationId);
        }
        if ($transformation !== null) {
            $draft->text($details, 'Transformation', $transformation);
        }
        if ($gatewayTest !== null) {
            $draft->text($details, 'GatewayTest', $gatewayTest);
        }

        return $draft;
    }
}
