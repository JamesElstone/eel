<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHouseInitialShareholdingExtractionService
{
    /** @var null|callable */
    private $textExtractor;

    public function __construct(
        private readonly ?\eel_accounts\Service\FileCheckService $fileCheckService = null,
        ?callable $textExtractor = null,
    ) {
        $this->textExtractor = $textExtractor;
    }

    public function draftForCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return ['success' => false, 'errors' => ['Select a company before reading the incorporation document.'], 'draft' => []];
        }

        $status = (new \eel_accounts\Service\CompaniesHouseIncorporationDocumentStatusService($this->fileCheckService()))
            ->statusForCompany($companyId);

        if (empty($status['downloaded']) || trim((string)($status['path'] ?? '')) === '') {
            return ['success' => false, 'errors' => ['No downloaded NEWINC incorporation PDF was found for this company.'], 'draft' => []];
        }

        try {
            $text = $this->extractText((string)$status['path']);
            $values = $this->parseInitialShareholdings($text);
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()], 'draft' => []];
        }

        $quantity = (int)$values['quantity'];
        $nominalValue = (float)$values['nominal_value_per_share'];
        $unpaidValue = (float)$values['unpaid_value_per_share'];

        return [
            'success' => true,
            'errors' => [],
            'draft' => [
                'share_class' => $values['share_class'],
                'currency' => $values['currency'],
                'quantity' => (string)$quantity,
                'aggregate_nominal_value' => $this->decimalValue($quantity * $nominalValue),
                'total_aggregate_unpaid' => $this->decimalValue($quantity * $unpaidValue),
                'document_reference' => (string)($status['filename'] ?? ''),
                'source_note' => '',
                'source_values' => $values,
            ],
        ];
    }

    public function parseInitialShareholdings(string $text): array
    {
        $section = $this->initialShareholdingsSection($text);

        $values = [
            'share_class' => $this->requiredMatch('/Class\s+of\s+Shares:\s*([A-Z0-9 _-]+)/i', $section, 'Class of Shares'),
            'quantity' => $this->requiredNumber('/Number\s+of\s+shares:\s*([0-9,]+)/i', $section, 'Number of shares'),
            'currency' => strtoupper($this->requiredMatch('/Currency:\s*([A-Z]{3})/i', $section, 'Currency')),
            'nominal_value_per_share' => $this->requiredDecimal('/Nominal\s+value\s+of\s+each(?:\s+share:?)?\s+([0-9,.]+)/i', $section, 'Nominal value of each share'),
            'unpaid_value_per_share' => $this->requiredDecimal('/Amount\s+unpaid:\s*([0-9,.]+)/i', $section, 'Amount unpaid'),
            'paid_value_per_share' => $this->requiredDecimal('/Amount\s+paid:\s*([0-9,.]+)/i', $section, 'Amount paid'),
        ];

        $values['share_class'] = trim(preg_replace('/\s+/', ' ', $values['share_class']) ?? '');

        if ($values['share_class'] === '') {
            throw new \RuntimeException('The Initial Shareholdings section did not include a share class.');
        }

        return $values;
    }

    private function initialShareholdingsSection(string $text): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);

        if (preg_match('/Initial\s+Shareholdings(?P<section>.*?)(?:\f|\n\s*Persons\s+with\s+Significant\s+Control|\z)/is', $normalised, $matches) !== 1) {
            throw new \RuntimeException('The Initial Shareholdings section was not found in the incorporation PDF.');
        }

        return (string)$matches['section'];
    }

    private function extractText(string $pdfPath): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new \RuntimeException('The downloaded incorporation PDF could not be read.');
        }

        if ($this->textExtractor !== null) {
            $text = (string)($this->textExtractor)($pdfPath);
            if (trim($text) === '') {
                throw new \RuntimeException('The incorporation PDF text extraction returned no text.');
            }

            return $text;
        }

        return $this->extractTextWithPdfToText($pdfPath);
    }

    private function extractTextWithPdfToText(string $pdfPath): string
    {
        $binary = $this->pdfToTextBinary();
        $tempTextPath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'eel_newinc_' . bin2hex(random_bytes(8)) . '.txt';
        $command = escapeshellarg($binary)
            . ' -layout '
            . escapeshellarg($pdfPath)
            . ' '
            . escapeshellarg($tempTextPath);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start pdftotext for the incorporation PDF.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($tempTextPath)) {
            @unlink($tempTextPath);
            $message = trim((string)$stderr) !== '' ? trim((string)$stderr) : trim((string)$stdout);
            throw new \RuntimeException('pdftotext could not read the incorporation PDF' . ($message !== '' ? ': ' . $message : '.'));
        }

        $text = (string)file_get_contents($tempTextPath);
        @unlink($tempTextPath);

        if (trim($text) === '') {
            throw new \RuntimeException('pdftotext returned no text for the incorporation PDF.');
        }

        return $text;
    }

    private function pdfToTextBinary(): string
    {
        $configured = trim((string)\AppConfigurationStore::get('companies_house.pdftotext_path', ''));
        $candidates = array_values(array_filter([
            $configured,
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
            '/usr/local/libexec/xpdf/pdftotext',
            'pdftotext',
        ], static fn(string $path): bool => trim($path) !== ''));

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || $candidate === 'pdftotext') {
                return $candidate;
            }
        }

        return 'pdftotext';
    }

    private function requiredMatch(string $pattern, string $section, string $label): string
    {
        if (preg_match($pattern, $section, $matches) !== 1) {
            throw new \RuntimeException('The Initial Shareholdings section did not include ' . $label . '.');
        }

        return trim((string)$matches[1]);
    }

    private function requiredNumber(string $pattern, string $section, string $label): int
    {
        $value = str_replace(',', '', $this->requiredMatch($pattern, $section, $label));

        if ($value === '' || !ctype_digit($value)) {
            throw new \RuntimeException($label . ' in the Initial Shareholdings section was not a whole number.');
        }

        return (int)$value;
    }

    private function requiredDecimal(string $pattern, string $section, string $label): string
    {
        $value = str_replace(',', '', $this->requiredMatch($pattern, $section, $label));

        if (!is_numeric($value)) {
            throw new \RuntimeException($label . ' in the Initial Shareholdings section was not numeric.');
        }

        return $this->decimalValue((float)$value);
    }

    private function decimalValue(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private function fileCheckService(): \eel_accounts\Service\FileCheckService
    {
        return $this->fileCheckService ?? new \eel_accounts\Service\FileCheckService();
    }
}
