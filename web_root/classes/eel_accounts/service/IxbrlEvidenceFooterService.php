<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Places an artifact evidence reference on the final rendered accounts page. */
final class IxbrlEvidenceFooterService
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';

    public function withFooter(string $xhtml, string $evidenceArtifactId): string
    {
        $evidenceArtifactId = trim($evidenceArtifactId);
        if ($evidenceArtifactId === '') {
            return $xhtml;
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xhtml, LIBXML_NONET)) {
                throw new \RuntimeException('The accounts iXBRL could not be parsed to add its evidence footer.');
            }
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('xhtml', self::XHTML_NS);
            foreach ($xpath->query('//xhtml:*[contains(concat(" ", normalize-space(@class), " "), " evidence-footer ")]') ?: [] as $footer) {
                $footer->parentNode?->removeChild($footer);
            }
            $pages = $xpath->query('//xhtml:div[contains(concat(" ", normalize-space(@class), " "), " accountspage ")]');
            $lastPage = $pages !== false && $pages->length > 0 ? $pages->item($pages->length - 1) : null;
            if (!$lastPage instanceof \DOMElement) {
                throw new \RuntimeException('The accounts iXBRL has no rendered page for its evidence footer.');
            }
            $footer = $document->createElementNS(self::XHTML_NS, 'div');
            $footer->setAttribute('class', 'evidence-footer');
            $footer->appendChild($document->createTextNode('Evidence ID: ' . $evidenceArtifactId));
            $lastPage->appendChild($footer);
            $result = $document->saveXML();
            if (!is_string($result) || $result === '') {
                throw new \RuntimeException('The accounts iXBRL evidence footer could not be written.');
            }
            return (new CompaniesHouseIxbrlDocumentPolicyService())->canonicaliseGeneratedDocument($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
