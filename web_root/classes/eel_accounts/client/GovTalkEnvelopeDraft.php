<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

use eel_accounts\Support\Utf8;

/**
 * Mutable only while an authority-specific request factory completes an
 * envelope. The final serialized bytes are immutable once prepared.
 */
final class GovTalkEnvelopeDraft
{
    private bool $bodyAppended = false;

    public function __construct(
        public readonly \DOMDocument $document,
        public readonly \DOMElement $root,
        public readonly \DOMElement $header,
        public readonly \DOMElement $messageDetails,
        public readonly \DOMElement $body,
        public readonly string $namespace
    ) {
    }

    public function element(\DOMElement $parent, string $name): \DOMElement
    {
        $element = $this->document->createElementNS($this->namespace, $name);
        $parent->appendChild($element);

        return $element;
    }

    public function text(\DOMElement $parent, string $name, string $value): \DOMElement
    {
        $element = $this->element($parent, $name);
        if ($value !== '') {
            $element->appendChild($this->document->createTextNode(Utf8::normalize($value)));
        }

        return $element;
    }

    public function appendBody(): \DOMElement
    {
        if (!$this->bodyAppended) {
            $this->root->appendChild($this->body);
            $this->bodyAppended = true;
        }

        return $this->body;
    }

    public function xml(): string
    {
        $this->appendBody();
        $xml = $this->document->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Unable to serialise the GovTalk XML envelope.');
        }

        return $xml;
    }
}
