<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseXmlSchemaValidationService
{
    public function validateDocument(
        \DOMDocument $document,
        string $schema,
        string $label
    ): void {
        [$ok, $warnings, $errors] = $this->schemaValidate($document, $schema);
        if (!$ok) {
            throw new \RuntimeException(
                'Companies House ' . $label . ' schema validation failed: '
                . $this->diagnostics($warnings, $errors)
            );
        }
    }

    public function assertCompiles(
        string $schema,
        string $rootName,
        string $namespace,
        string $operation
    ): void {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS($namespace, $rootName);
        $document->appendChild($root);
        [, $warnings, $errors] = $this->schemaValidate($document, $schema);
        $schemaErrors = array_values(array_filter(
            $errors,
            static function (\LibXMLError $error): bool {
                $file = rawurldecode(strtolower((string)$error->file));
                return str_ends_with($file, '.xsd');
            }
        ));
        if ($warnings !== [] || $schemaErrors !== []) {
            throw new \RuntimeException(
                'The Companies House ' . $operation
                . ' validation schema could not be compiled: '
                . $this->diagnostics($warnings, $schemaErrors)
            );
        }
    }

    /** @return array{0:bool,1:list<string>,2:list<\LibXMLError>} */
    private function schemaValidate(\DOMDocument $document, string $schema): array
    {
        $warnings = [];
        $previousLibxml = libxml_use_internal_errors(true);
        set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                if (($severity & (E_WARNING | E_NOTICE)) !== 0) {
                    $warnings[] = trim((string)preg_replace('/\s+/', ' ', $message));
                    return true;
                }
                return false;
            }
        );
        try {
            $ok = $document->schemaValidate($schema, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            restore_error_handler();
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxml);
        }

        return [(bool)$ok, array_values(array_filter($warnings)), $errors ?? []];
    }

    /** @param list<string> $warnings @param list<\LibXMLError> $errors */
    private function diagnostics(array $warnings, array $errors): string
    {
        $messages = $warnings;
        foreach (array_slice($errors, 0, 8) as $error) {
            $message = trim((string)preg_replace('/\s+/', ' ', (string)$error->message));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        $messages = array_values(array_unique(array_filter($messages)));
        return $messages === [] ? 'unknown XML validation error' : implode('; ', $messages);
    }
}
