<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlAccountingService
{
    public function generatePreview(int $companyId, int $accountingPeriodId): array
    {
        return $this->generateFilingExport($companyId, $accountingPeriodId);
    }

    public function generateFilingExport(int $companyId, int $accountingPeriodId): array
    {
        $builder = new IxbrlFactBuilderService();
        $run = $builder->getLatestRun($companyId, $accountingPeriodId);
        if (!is_array($run) || (int)($run['fact_count'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['Build iXBRL facts before generating the accounts file.']];
        }
        $freshness = (array)($run['run_freshness'] ?? $builder->getRunFreshness((int)$run['id']));
        if ((string)($freshness['state'] ?? '') !== 'current') {
            return ['success' => false, 'errors' => [(string)($freshness['detail'] ?? 'Rebuild iXBRL facts before generating.')]];
        }

        $newGeneratedPath = '';
        $stored = null;
        $evidenceArtifact = null;
        try {
            $facts = $builder->getFacts((int)$run['id']);
            $generationWarnings = $this->negativeEquityOmissionWarnings($facts);
            $evidenceArtifact = (new FilingEvidenceService())->reserveArtifact(
                $companyId,
                $accountingPeriodId,
                'accounts_ixbrl',
                null,
                ['ixbrl_generation_run_id' => (int)$run['id']]
            );
            $xhtml = $this->renderXhtml(
                $facts,
                $this->comparativeFactsRequired($companyId, $accountingPeriodId),
                (string)$evidenceArtifact['display_id']
            );
            $validationErrors = $this->validateInlineXbrl($xhtml, $facts);
            if ($validationErrors !== []) {
                throw new \RuntimeException('Generated iXBRL failed internal validation: ' . implode(' ', $validationErrors));
            }

            $artifact = $this->accountingArtifactLocation($companyId, $accountingPeriodId);
            $stored = (new IxbrlGeneratorService())->storeImmutableArtifact(
                $companyId,
                (string)$artifact['company_number'],
                $accountingPeriodId,
                (int)($run['filing_approval_id'] ?? 0),
                (int)$run['id'],
                IxbrlArtifactFilenameService::DESTINATION_HMRC_ACCOUNTING,
                (string)$artifact['period_start'],
                (string)$artifact['period_end'],
                $xhtml
            );
            $filename = (string)$stored['filename'];
            $path = (string)$stored['path'];
            $newGeneratedPath = $path;
            $hash = (string)$stored['sha256'];

            \InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     export_type = :export_type,
                     taxonomy_profile = :taxonomy_profile,
                     validation_status = :validation_status,
                     validation_errors_json = :validation_errors_json,
                     external_validator = NULL,
                     external_validator_version = NULL,
                     external_validation_status = :external_validation_status,
                     external_validation_errors_json = NULL,
                     external_validation_warnings_json = NULL,
                     external_validation_log_path = NULL,
                     external_validated_at = NULL,
                     external_validated_sha256 = NULL,
                     generated_filename = :filename,
                     generated_path = :path,
                     output_sha256 = :sha,
                     generated_at = CURRENT_TIMESTAMP,
                     error_message = NULL
                 WHERE id = :id',
                [
                    'status' => 'generated',
                    'export_type' => 'filing_export',
                    'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
                    'validation_status' => 'passed',
                    'validation_errors_json' => \eel_accounts\Support\Utf8::json([], JSON_UNESCAPED_SLASHES),
                    'external_validation_status' => 'not_validated',
                    'filename' => $filename,
                    'path' => $path,
                    'sha' => $hash,
                    'id' => (int)$run['id'],
                ]
            );
            (new FilingEvidenceService())->completeArtifact((int)$evidenceArtifact['id'], [
                'status' => 'generated',
                'filename' => $filename,
                'path' => $path,
                'sha256' => $hash,
                'schema_identity' => IxbrlTaxonomyProfileService::SCHEMA_REF,
                'validation_status' => 'passed',
                'identifier_embedded' => true,
                'metadata' => [
                    'ixbrl_generation_run_id' => (int)$run['id'],
                    'generation_warnings' => $generationWarnings,
                ],
            ]);

            return ['success' => true, 'errors' => [], 'filename' => $filename, 'path' => $path, 'sha256' => $hash,
                'evidence_artifact_id' => (string)$evidenceArtifact['display_id'],
                'warnings' => $generationWarnings];
        } catch (\Throwable $exception) {
            if (is_array($evidenceArtifact)) {
                (new FilingEvidenceService())->failArtifact((int)$evidenceArtifact['id'], $exception->getMessage());
            }
            if (is_array($stored) && !empty($stored['created']) && $newGeneratedPath !== '') {
                $this->removeManagedArtifact($newGeneratedPath, $companyId);
            }
            \InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     taxonomy_profile = :taxonomy_profile,
                     validation_status = :validation_status,
                     validation_errors_json = :errors,
                     external_validator = NULL,
                     external_validator_version = NULL,
                     external_validation_status = :external_validation_status,
                     external_validation_errors_json = NULL,
                     external_validation_warnings_json = NULL,
                     external_validation_log_path = NULL,
                     external_validated_at = NULL,
                     external_validated_sha256 = NULL,
                     generated_filename = NULL,
                     generated_path = NULL,
                     output_sha256 = NULL,
                     generated_at = NULL,
                     error_message = :error_message
                 WHERE id = :id',
                [
                    'status' => 'failed',
                    'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
                    'validation_status' => 'failed',
                    'errors' => \eel_accounts\Support\Utf8::json([$exception->getMessage()], JSON_UNESCAPED_SLASHES),
                    'external_validation_status' => 'not_validated',
                    'error_message' => $exception->getMessage(),
                    'id' => (int)$run['id'],
                ]
            );
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function accountingArtifactLocation(int $companyId, int $accountingPeriodId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT c.company_number, ap.period_start, ap.period_end
             FROM accounting_periods ap
             INNER JOIN companies c ON c.id = ap.company_id
             WHERE ap.id = :accounting_period_id
               AND ap.company_id = :company_id
             LIMIT 1',
            [
                'accounting_period_id' => $accountingPeriodId,
                'company_id' => $companyId,
            ]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The accounting period could not be found for iXBRL storage.');
        }

        $companyNumber = $this->normaliseCompanyNumber((string)($row['company_number'] ?? ''));

        return [
            'company_number' => $companyNumber,
            'period_start' => $this->filenameDate((string)($row['period_start'] ?? ''), 'start'),
            'period_end' => $this->filenameDate((string)($row['period_end'] ?? ''), 'end'),
        ];
    }

    private function normaliseCompanyNumber(string $companyNumber): string
    {
        $normalised = strtoupper(preg_replace('/\s+/', '', trim($companyNumber)) ?? '');
        $normalised = preg_replace('/[^A-Z0-9_-]/', '', $normalised) ?? '';
        if ($normalised === '') {
            throw new \RuntimeException('The selected company does not have a valid company number for iXBRL storage.');
        }

        return $normalised;
    }

    private function filenameDate(string $date, string $label): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable
            || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new \RuntimeException('The accounting period ' . $label . ' date is invalid for iXBRL storage.');
        }

        return $parsed->format('Ymd');
    }

    private function removeManagedArtifact(string $path, int $companyId): void
    {
        (new IxbrlGeneratorService())->removeManagedArtifact($path, $companyId);
    }

    private function comparativeFactsRequired(int $companyId, int $accountingPeriodId): bool
    {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('year_end_reviews')) {
            return false;
        }
        $periodStart = trim((string)(\InterfaceDB::fetchColumn(
            'SELECT period_start FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        ) ?: ''));
        if ($periodStart === '') {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_periods ap
             INNER JOIN year_end_reviews yr
               ON yr.company_id = ap.company_id
              AND yr.accounting_period_id = ap.id
              AND yr.is_locked = 1
             WHERE ap.company_id = :company_id
               AND ap.period_end < :period_start',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        ) > 0;
    }

    private function renderXhtml(array $facts, bool $comparativeRequired = false, string $evidenceArtifactId = ''): string
    {
        $indexed = $this->indexFacts($facts);
        $missingFactKeys = [];
        $missingComparativeFactKeys = [];
        $comparativePeriod = $this->comparativePeriod($facts);
        $comparativeRequired = $comparativeRequired || $comparativePeriod !== null;
        foreach ((new IxbrlTaxonomyProfileService())->mappings() as $mapping) {
            if (empty($mapping['is_active']) || empty($mapping['is_required'])) {
                continue;
            }
            $factKey = (string)($mapping['fact_key'] ?? '');
            if ($factKey !== '' && $this->currentFact($indexed, $factKey) === []) {
                $missingFactKeys[] = $factKey;
            }
            if ($comparativeRequired
                && !empty($mapping['comparative_enabled'])
                && $factKey !== ''
                && $this->comparativeFact($indexed, $factKey) === []) {
                $missingComparativeFactKeys[] = $factKey;
            }
        }
        if ($missingFactKeys !== []) {
            throw new \RuntimeException(
                'The current-period iXBRL fact set is incomplete: ' . implode(', ', $missingFactKeys) . '.'
            );
        }
        if ($missingComparativeFactKeys !== []) {
            throw new \RuntimeException(
                'The comparative-period iXBRL fact set is incomplete: '
                . implode(', ', $missingComparativeFactKeys) . '.'
            );
        }
        $this->assertMicroStatementsReconcile($indexed);
        $companyName = $this->currentFact($indexed, 'entity_name');
        $companyNumber = $this->factValue($this->currentFact($indexed, 'company_number'));
        $periodStart = $this->factValue($this->currentFact($indexed, 'period_start'));
        $periodEnd = $this->factValue($this->currentFact($indexed, 'period_end'));
        $registeredOfficeFacts = [];
        foreach ([
            'registered_office_address_line_1',
            'registered_office_address_line_2',
            'registered_office_address_line_3',
            'registered_office_postal_code',
        ] as $key) {
            $fact = $this->currentFact($indexed, $key);
            if ($fact !== [] && $this->factValue($fact) !== '') {
                $registeredOfficeFacts[] = $this->inlineFact($fact);
            }
        }

        $hidden = '';
        foreach ([
            'entity_dormant',
            'entity_trading_status',
            'accounting_standards_applied',
            'accounts_status',
            'accounts_type',
            'director_signing_financial_statements',
            'production_software',
            'production_software_version',
        ] as $key) {
            $fact = $this->currentFact($indexed, $key);
            if ($fact !== []) {
                $hidden .= $this->inlineFact($fact) . "\n";
            }
        }

        $balanceRows = [
            ['label' => 'Called up share capital not paid', 'key' => 'called_up_share_capital_not_paid'],
            ['label' => 'Fixed assets', 'key' => 'fixed_assets'],
            ['label' => 'Current assets', 'key' => 'current_assets'],
            ['label' => 'Prepayments and accrued income', 'key' => 'prepayments_accrued_income'],
            ['label' => 'Creditors: amounts falling due within one year', 'key' => 'creditors_within_one_year', 'brackets' => true],
            ['label' => 'Net current assets / (liabilities)', 'key' => 'net_current_assets_liabilities', 'rule' => 'subtotal'],
            ['label' => 'Total assets less current liabilities', 'key' => 'total_assets_less_current_liabilities', 'rule' => 'subtotal'],
            ['label' => 'Creditors: amounts falling due after more than one year', 'key' => 'creditors_after_one_year', 'brackets' => true],
            ['label' => 'Provisions for liabilities', 'key' => 'provisions_for_liabilities', 'brackets' => true],
            ['label' => 'Accruals and deferred income', 'key' => 'accruals_deferred_income', 'brackets' => true],
            ['label' => 'Total net assets / (liabilities)', 'key' => 'net_assets_liabilities', 'rule' => 'total'],
            ['label' => 'Capital and reserves', 'key' => 'equity', 'rule' => 'total'],
        ];

        $statements = '';
        foreach (['small_companies_regime_statement', 'audit_exemption_statement', 'directors_responsibility_statement', 'members_no_audit_statement'] as $key) {
            $fact = $this->currentFact($indexed, $key);
            if ($fact !== []) {
                $statements .= '<p>' . $this->inlineFact($fact) . '</p>' . "\n";
            }
        }
        $employees = $this->currentFact($indexed, 'average_number_employees');
        $approvalDate = $this->currentFact($indexed, 'accounts_approval_date');
        $director = $this->currentFact($indexed, 'approving_director_name');
        if ($approvalDate !== [] && $director !== []) {
            $statements .= '<div class="approval keepTogether"><p>Approved by the board on '
                . $this->inlineFact($approvalDate, ['natural_date' => true])
                . ' and signed on its behalf by:</p>'
                . '<p class="signature">' . $this->inlineFact($director) . '</p>'
                . '<p>Director</p></div>' . "\n";
        }

        $notes = $this->notes($indexed, $periodEnd);

        $namespaceAttributes = '';
        foreach (IxbrlTaxonomyProfileService::NAMESPACES as $prefix => $uri) {
            $namespaceAttributes .= ' xmlns:' . $prefix . '="' . $this->e($uri) . '"';
        }

        $xhtml = CompaniesHouseIxbrlDocumentPolicyService::DOCUMENT_PREFIX
            . '<html xmlns="http://www.w3.org/1999/xhtml"'
            . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
            . ' xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2015-02-26"'
            . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
            . ' xmlns:xbrldi="http://xbrl.org/2006/xbrldi"'
            . ' xmlns:link="http://www.xbrl.org/2003/linkbase"'
            . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' xmlns:iso4217="http://www.xbrl.org/2003/iso4217"'
            . $namespaceAttributes
            . ' xml:lang="en">' . "\n"
            . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>'
            . '<title>Micro-entity accounts for ' . $this->e($this->factValue($companyName)) . '</title>'
            . '<style type="text/css">' . "\n" . $this->stylesheet() . "\n" . '</style></head>' . "\n"
            . '<body>' . "\n"
            . '<div class="ixbrl-header" style="display:none"><ix:header>' . "\n"
            . '<ix:hidden>' . "\n" . $hidden . '</ix:hidden>' . "\n"
            . '<ix:references><link:schemaRef xlink:type="simple" xlink:href="' . $this->e(IxbrlTaxonomyProfileService::SCHEMA_REF) . '"/></ix:references>' . "\n"
            . '<ix:resources>' . "\n"
            . $this->contexts(
                $companyNumber,
                $periodStart,
                $periodEnd,
                $comparativePeriod,
                array_values(array_unique(array_map(
                    static fn(array $fact): string => (string)($fact['context_ref'] ?? ''),
                    $facts
                )))
            )
            . '<xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>' . "\n"
            . '<xbrli:unit id="pure"><xbrli:measure>xbrli:pure</xbrli:measure></xbrli:unit>' . "\n"
            . '</ix:resources>' . "\n"
            . '</ix:header></div>' . "\n"
            . '<div class="accountspage titlepage">'
            . '<div class="cover-company-number">Registered company number: '
            . $this->inlineFact($this->currentFact($indexed, 'company_number')) . '</div>'
            . '<div class="cover-centre"><h1>' . $this->inlineFact($companyName) . '</h1>'
            . '<h2>MICRO-ENTITY ACCOUNTS</h2>'
            . '<p>For the period ended ' . $this->inlineFact(
                $this->currentFact($indexed, 'period_end'),
                ['natural_date' => true]
            ) . '</p></div>'
            . '<div class="cover-statutory-information">'
            . '<p>' . $this->inlineFact($companyName) . ' is a private company limited by shares'
            . $this->categoricalMarker($this->currentFact($indexed, 'legal_form_entity'))
            . ', incorporated and registered in England and Wales'
            . $this->categoricalMarker($this->currentFact($indexed, 'country_formation_or_incorporation'))
            . ' under company number ' . $this->inlineFact(
                $this->currentFact($indexed, 'company_number')
            ) . '.</p>'
            . '<p>Registered office: ' . implode(', ', $registeredOfficeFacts) . '.</p>'
            . '<p>These financial statements cover the period from ' . $this->inlineFact(
                $this->currentFact($indexed, 'period_start'),
                ['natural_date' => true]
            ) . ' to ' . $this->inlineFact(
                $this->currentFact($indexed, 'period_end'),
                ['natural_date' => true]
            )
            . ' and are presented in pounds sterling (GBP) to the nearest penny.</p>'
            . '</div></div>' . "\n"
            . '<div class="accountspage pagebreak statement-page">'
            . $this->pageHeader($indexed, 'Profit and loss account')
            . '<h2>Profit and loss account</h2>'
            . '<p class="period-subtitle">For the period ended ' . $this->inlineFact(
                $this->currentFact($indexed, 'period_end'),
                ['natural_date' => true]
            ) . '</p>'
            . $this->profitAndLossTable($indexed)
            . $this->grossProfitBridge($indexed)
            . '</div>' . "\n"
            . '<div class="accountspage pagebreak statement-page">'
            . $this->pageHeader($indexed, 'Balance sheet')
            . '<h2>Micro-entity Balance Sheet as at '
            . $this->inlineFact($this->currentFact($indexed, 'balance_sheet_date'), ['natural_date' => true])
            . '</h2>'
            . '<div class="balance-sheet-block">'
            . $this->statementTable($indexed, $balanceRows, $periodEnd)
            . '<div class="statutory-statements keepTogether">' . $statements . '</div>'
            . '</div>'
            . '</div>' . "\n"
            . '<div class="accountspage pagebreak notes-page">'
            . $this->pageHeader($indexed, 'Notes to the Micro-entity Accounts')
            . '<h2>Notes to the Micro-entity Accounts</h2>'
            . '<p class="period-subtitle">For the period ended ' . $this->inlineFact(
                $this->currentFact($indexed, 'period_end'),
                ['natural_date' => true]
            ) . '</p>'
            . $notes
            . $this->evidenceFooter($evidenceArtifactId)
            . '</div>' . "\n"
            . '</body></html>' . "\n";

        return (new CompaniesHouseIxbrlDocumentPolicyService())
            ->canonicaliseGeneratedDocument($xhtml);
    }

    private function profitAndLossTable(array $indexed): string
    {
        $rows = [
            ['label' => 'Turnover', 'key' => 'turnover'],
            ['label' => 'Other income', 'key' => 'other_income'],
            ['label' => 'Raw materials and consumables', 'key' => 'raw_materials_consumables', 'brackets' => true],
            ['label' => 'Gross profit / (loss)', 'key' => 'gross_profit_loss', 'rule' => 'subtotal'],
            ['label' => 'Staff costs', 'key' => 'staff_costs', 'brackets' => true],
            ['label' => 'Depreciation and other amounts written off assets', 'key' => 'depreciation_write_offs', 'brackets' => true],
            ['label' => 'Other charges', 'key' => 'other_charges', 'brackets' => true],
            ['label' => 'Operating profit / (loss)', 'key' => 'operating_profit_loss', 'rule' => 'subtotal'],
            ['label' => 'Tax on profit / (loss)', 'key' => 'tax_on_profit', 'brackets' => true],
            ['label' => 'Profit / (loss) for the financial year', 'key' => 'profit_loss', 'rule' => 'total'],
        ];

        return $this->statementTable($indexed, $rows, $this->factValue($this->currentFact($indexed, 'period_end')));
    }

    private function grossProfitBridge(array $indexed): string
    {
        $current = $this->grossProfitBridgeAmounts($indexed, false);
        $comparative = $this->hasComparative($indexed)
            ? $this->grossProfitBridgeAmounts($indexed, true)
            : null;
        if (empty($current['has_subcontractor_labour'])
            && empty($comparative['has_subcontractor_labour'])) {
            return '';
        }

        $hasComparative = $this->hasComparative($indexed);
        $periodEnd = $this->factValue($this->currentFact($indexed, 'period_end'));
        $comparativePeriod = $hasComparative ? $this->comparativePeriodFromIndex($indexed) : null;
        $amount = fn(?array $values, string $key, bool $brackets = false): string => $values === null
            ? '–'
            : $this->visibleAmount((float)($values[$key] ?? 0), $brackets);

        $html = '<div class="gross-profit-bridge keepTogether">'
            . '<p>The statutory gross profit / (loss) subtotal is turnover less raw materials and consumables. '
            . 'Subcontractor labour is included within other charges.</p>'
            . '<table class="financial-table gross-profit-bridge-table"><colgroup><col class="description-column"/>'
            . '<col class="amount-column"/>'
            . ($hasComparative ? '<col class="amount-column"/>' : '')
            . '</colgroup><thead><tr><th class="description" scope="col"></th>'
            . '<th class="amount" scope="col">' . $this->e($this->yearOf($periodEnd)) . '<br/><span>£</span></th>'
            . ($hasComparative
                ? '<th class="amount" scope="col">'
                    . $this->e($this->yearOf((string)($comparativePeriod['period_end'] ?? '')))
                    . '<br/><span>£</span></th>'
                : '')
            . '</tr></thead><tbody>'
            . '<tr><th class="description" scope="row">Statutory gross profit / (loss)</th>'
            . '<td class="amount">' . $amount($current, 'gross_profit') . '</td>'
            . ($hasComparative ? '<td class="amount">' . $amount($comparative, 'gross_profit') . '</td>' : '')
            . '</tr>'
            . '<tr><th class="description" scope="row">Less: subcontractor labour included in other charges</th>'
            . '<td class="amount">' . $amount($current, 'subcontractor_labour', true) . '</td>'
            . ($hasComparative
                ? '<td class="amount">' . $amount($comparative, 'subcontractor_labour', true) . '</td>'
                : '')
            . '</tr>'
            . '<tr class="subtotal"><th class="description" scope="row">Management gross profit / (loss)</th>'
            . '<td class="amount">' . $amount($current, 'management_gross_profit') . '</td>'
            . ($hasComparative ? '<td class="amount">' . $amount($comparative, 'management_gross_profit') . '</td>' : '')
            . '</tr></tbody></table></div>';

        return $html;
    }

    private function grossProfitBridgeAmounts(array $indexed, bool $comparative): array
    {
        $otherCharges = $comparative
            ? $this->comparativeFact($indexed, 'other_charges')
            : $this->currentFact($indexed, 'other_charges');
        $source = json_decode((string)($otherCharges['source_json'] ?? ''), true);
        $subcontractorLabour = 0.0;
        foreach ((array)($source['source_rows'] ?? []) as $row) {
            if (!is_array($row)
                || preg_match('/\bsubcontract(?:or|ors|ing|ed)?\b/i', (string)($row['label'] ?? '')) !== 1) {
                continue;
            }
            $subcontractorLabour += (float)($row['amount'] ?? 0);
        }
        $subcontractorLabour = round($subcontractorLabour, 2);
        $grossProfit = $this->numericFact($indexed, 'gross_profit_loss', $comparative);
        return [
            'gross_profit' => $grossProfit,
            'subcontractor_labour' => $subcontractorLabour,
            'management_gross_profit' => round($grossProfit - $subcontractorLabour, 2),
            'has_subcontractor_labour' => abs($subcontractorLabour) >= 0.005,
        ];
    }

    private function statementTable(array $indexed, array $rows, string $periodEnd): string
    {
        $hasComparative = $this->hasComparative($indexed);
        $comparativePeriod = $hasComparative ? $this->comparativePeriodFromIndex($indexed) : null;
        $html = '<table class="financial-table keepTogether"><colgroup><col class="description-column"/>'
            . '<col class="amount-column"/>'
            . ($hasComparative ? '<col class="amount-column"/>' : '')
            . '</colgroup><thead><tr><th class="description" scope="col"></th>'
            . '<th class="amount" scope="col">' . $this->e($this->yearOf($periodEnd)) . '<br/><span>£</span></th>'
            . ($hasComparative
                ? '<th class="amount" scope="col">'
                    . $this->e($this->yearOf((string)($comparativePeriod['period_end'] ?? '')))
                    . '<br/><span>£</span></th>'
                : '')
            . '</tr></thead><tbody>' . "\n";
        foreach ($rows as $row) {
            $key = (string)($row['key'] ?? '');
            $current = $key !== '' ? $this->currentFact($indexed, $key) : [];
            $computed = $row['computed'] ?? null;
            if ($current === [] && !is_callable($computed)) {
                continue;
            }
            $classes = [];
            if (($row['rule'] ?? '') === 'subtotal') {
                $classes[] = 'subtotal';
            } elseif (($row['rule'] ?? '') === 'total') {
                $classes[] = 'final-total';
            }
            $classAttribute = $classes !== [] ? ' class="' . implode(' ', $classes) . '"' : '';
            $html .= '<tr' . $classAttribute . '><th class="description" scope="row">'
                . $this->e((string)$row['label']) . '</th><td class="amount">';
            $html .= is_callable($computed)
                ? $this->visibleAmount((float)$computed(false))
                : $this->statementFact($current, [
                    'accounting' => true,
                    'brackets' => !empty($row['brackets']),
                    'zero_dash' => true,
                ]);
            $html .= '</td>';
            if ($hasComparative) {
                $comparative = $key !== '' ? $this->comparativeFact($indexed, $key) : [];
                $html .= '<td class="amount">';
                $html .= is_callable($computed)
                    ? $this->visibleAmount((float)$computed(true))
                    : $this->statementFact($comparative, [
                        'accounting' => true,
                        'brackets' => !empty($row['brackets']),
                        'zero_dash' => true,
                    ]);
                $html .= '</td>';
            }
            $html .= '</tr>' . "\n";
        }

        return $html . '</tbody></table>';
    }

    private function statementFact(array $fact, array $options): string
    {
        if ((string)($fact['taxonomy_concept'] ?? '') === 'core:Equity'
            && (string)($fact['value_type'] ?? '') === 'numeric'
            && (float)($fact['numeric_value'] ?? 0) < 0) {
            return $this->visibleAmount((float)$fact['numeric_value']);
        }

        return $this->inlineFact($fact, $options);
    }

    private function notes(array $indexed, string $periodEnd): string
    {
        $notes = '';
        $principalActivity = $this->currentFact($indexed, 'principal_activity_description');
        if ($principalActivity !== []) {
            $notes .= '<div class="note keepTogether"><h3><span class="note-number">1.</span> Principal activity</h3>'
                . '<p>' . $this->inlineFact($principalActivity) . '</p></div>' . "\n";
        }

        $employees = $this->currentFact($indexed, 'average_number_employees');
        if ($employees !== []) {
            $comparative = $this->comparativeFact($indexed, 'average_number_employees');
            $notes .= '<div class="note keepTogether"><h3><span class="note-number">2.</span> Employees</h3>'
                . '<p>The average monthly number of employees during the period was '
                . $this->inlineFact($employees)
                . ($comparative !== [] ? ' (comparative period: ' . $this->inlineFact($comparative) . ')' : '')
                . '.</p></div>' . "\n";
        }

        $directorNarrative = $this->currentFact($indexed, 'no_director_advances_or_credits');
        if ($directorNarrative !== []) {
            $notes .= '<div class="note keepTogether director-loan-note"><h3><span class="note-number">3.</span> '
                . 'Advances and credits to directors</h3>'
                . '<p>' . $this->inlineFact($directorNarrative) . '</p>'
                . $this->directorLoanTable($indexed)
                . '</div>' . "\n";
        }

        $noteDefinitions = [
            ['number' => 4, 'title' => 'Off-balance-sheet arrangements', 'keys' => [
                'no_material_off_balance_sheet_arrangements',
            ]],
            ['number' => 5, 'title' => 'Financial commitments', 'keys' => [
                'no_capital_commitments',
                'no_financial_commitments',
            ]],
            ['number' => 6, 'title' => 'Contingent liabilities', 'keys' => [
                'no_contingent_liabilities',
                'no_director_guarantees',
            ]],
        ];
        foreach ($noteDefinitions as $definition) {
            $paragraphs = '';
            foreach ($definition['keys'] as $key) {
                $fact = $this->currentFact($indexed, (string)$key);
                if ($fact !== []) {
                    $paragraphs .= '<p>' . $this->inlineFact($fact) . '</p>';
                }
            }
            if ($paragraphs !== '') {
                $notes .= '<div class="note keepTogether"><h3><span class="note-number">'
                    . (int)$definition['number'] . '.</span> ' . $this->e((string)$definition['title'])
                    . '</h3>' . $paragraphs . '</div>' . "\n";
            }
        }

        return $notes;
    }

    private function directorLoanTable(array $indexed): string
    {
        $rows = [
            ['label' => 'Advances or credits made during the period', 'key' => 'director_advances_made'],
            ['label' => 'Cash repayments during the period', 'key' => 'director_cash_repayments', 'brackets' => true],
            ['label' => 'Balance outstanding at the period end', 'key' => 'director_closing_advance'],
        ];
        $available = array_filter(
            $rows,
            fn(array $row): bool => $this->currentFact($indexed, (string)$row['key']) !== []
        );
        if ($available === []) {
            return '';
        }
        $hasComparative = $this->hasComparative($indexed);
        $periodEnd = $this->factValue($this->currentFact($indexed, 'period_end'));
        $comparativePeriod = $hasComparative ? $this->comparativePeriodFromIndex($indexed) : null;
        $html = '<table class="note-table director-loan-table"><colgroup><col class="description-column"/>'
            . '<col class="amount-column"/>'
            . ($hasComparative ? '<col class="amount-column"/>' : '')
            . '</colgroup><thead><tr><th class="description" scope="col"></th>'
            . '<th class="amount" scope="col">' . $this->e($this->yearOf($periodEnd))
            . '<br/><span>£</span></th>'
            . ($hasComparative
                ? '<th class="amount" scope="col">'
                    . $this->e($this->yearOf((string)($comparativePeriod['period_end'] ?? '')))
                    . '<br/><span>£</span></th>'
                : '')
            . '</tr></thead><tbody>';
        foreach ($available as $row) {
            $key = (string)$row['key'];
            $html .= '<tr><th class="description" scope="row">' . $this->e((string)$row['label'])
                . '</th><td class="amount">'
                . $this->inlineFact($this->currentFact($indexed, $key), [
                    'accounting' => true,
                    'brackets' => !empty($row['brackets']),
                    'zero_dash' => true,
                ])
                . '</td>';
            if ($hasComparative) {
                $html .= '<td class="amount">'
                    . $this->inlineFact($this->comparativeFact($indexed, $key), [
                        'accounting' => true,
                        'brackets' => !empty($row['brackets']),
                        'zero_dash' => true,
                    ])
                    . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private function pageHeader(array $indexed, string $title): string
    {
        return '<table class="page-header"><colgroup>'
            . '<col class="page-header-name-column"/><col class="page-header-number-column"/>'
            . '</colgroup><tbody><tr><td class="page-header-name">'
            . $this->inlineFact($this->currentFact($indexed, 'entity_name'))
            . '</td><td class="page-header-number">Registered number '
            . $this->inlineFact($this->currentFact($indexed, 'company_number'))
            . '</td></tr><tr><td class="page-header-title" colspan="2">' . $this->e($title)
            . ' · For the period ended ' . $this->inlineFact(
                $this->currentFact($indexed, 'period_end'),
                ['natural_date' => true]
            ) . '</td></tr></tbody></table>';
    }

    private function stylesheet(): string
    {
        return <<<'CSS'
@page {
    size: A4 portrait;
    margin: 12mm 14mm 14mm;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    background: #e7e8ea;
    color: #111;
    font-family: "Times New Roman", Times, serif;
    font-size: 10.5pt;
    line-height: 1.28;
}
.ixbrl-header { display: none; }
.accountspage {
    width: 210mm;
    min-height: 297mm;
    margin: 10mm auto;
    padding: 18mm 18mm 20mm;
    background: #fff;
    box-shadow: 0 2mm 8mm rgba(0, 0, 0, .14);
}
.titlepage { position: relative; }
.cover-company-number { text-align: right; font-size: 9.5pt; }
.cover-centre { margin-top: 82mm; text-align: center; }
.cover-centre h1 { margin: 0 0 13mm; font-size: 17pt; font-weight: normal; text-transform: uppercase; }
.cover-centre h2 { margin: 0 0 8mm; font-size: 15pt; letter-spacing: .06em; }
.cover-centre p { font-size: 12pt; }
.cover-statutory-information { width: 82%; margin: 28mm auto 0; font-size: 10.5pt; }
.cover-statutory-information p { margin: 0 0 4mm; }
.page-header {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    break-inside: avoid;
    page-break-inside: avoid;
    margin: 0 0 14mm;
    padding-bottom: 3mm;
    border-bottom: .25mm solid #222;
    font-size: 9pt;
}
.page-header-name-column { width: 64%; }
.page-header-number-column { width: 36%; }
.page-header td { padding: 0; vertical-align: top; }
.page-header-name { font-weight: bold; text-transform: uppercase; }
.page-header-number { text-align: right; white-space: nowrap; }
.page-header-title { padding-top: 1.8mm !important; color: #333; }
h2 { margin: 0; font-size: 13.5pt; text-align: center; }
.period-subtitle { margin: 1mm 0 8mm; text-align: center; }
.financial-table, .note-table { width: 92%; margin: 0 auto; border-collapse: collapse; table-layout: fixed; }
.gross-profit-bridge { width: 92%; margin: 4mm auto 0; }
.gross-profit-bridge p { margin: 0 0 2mm; font-size: 9.5pt; }
.gross-profit-bridge .financial-table { width: 100%; }
.financial-table .description-column, .note-table .description-column { width: auto; }
.financial-table .amount-column { width: 29mm; }
.note-table .amount-column { width: 34mm; }
.financial-table th, .financial-table td, .note-table th, .note-table td {
    padding: 1.6mm 1.5mm;
    vertical-align: top;
    font-weight: normal;
}
.financial-table th.description, .note-table th.description { text-align: left; }
.financial-table th.amount, .financial-table td.amount,
.note-table th.amount, .note-table td.amount {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums lining-nums;
}
.note-table td.detail { text-align: left; white-space: normal; }
.financial-table thead th { padding-bottom: 3mm; font-weight: normal; }
.financial-table tr.subtotal td.amount { border-top: .25mm solid #111; }
.financial-table tr.subtotal th { font-weight: bold; }
.financial-table tr.final-total th { font-weight: bold; padding-top: 2.6mm; }
.financial-table tr.final-total td.amount {
    border-top: .25mm solid #111;
    border-bottom: 0.7mm double #111;
    padding-top: 2.6mm;
}
.statutory-statements { width: 92%; margin: 12mm auto 0; font-size: 9.5pt; }
.statutory-statements p { margin: 0 0 3mm; }
.approval { margin-top: 8mm; }
.signature { width: 55mm; margin-top: 10mm !important; padding-top: 2mm; border-top: .25mm solid #111; }
.note { width: 92%; margin: 0 auto 8mm; }
.note h3 { margin: 0 0 2mm; font-size: 10.5pt; }
.note-number { display: inline-block; width: 7mm; }
.note p { margin: 0 0 2mm; }
.note-table { margin-top: 3mm; }
.loan-term { margin-top: 2mm !important; }
.evidence-footer {
    margin: 12mm auto 0;
    padding-top: 3mm;
    width: 92%;
    border-top: .25mm solid #777;
    color: #444;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8pt;
    text-align: right;
}
.revision-page h2 { margin-bottom: 9mm; }
.revision-statement { width: 92%; margin: 0 auto 7mm; }
.revision-statement h3 { margin: 0 0 2mm; font-size: 10.5pt; }
.revision-statement p { margin: 0; }
@media print {
    html, body {
        width: auto;
        margin: 0;
        padding: 0;
        background: #fff;
    }
    .accountspage {
        width: auto;
        max-width: none;
        min-height: 0;
        margin: 0;
        padding: 0;
        background: #fff;
        box-shadow: none;
        overflow: visible;
    }
    .accountspage + .accountspage {
        break-before: page;
        page-break-before: always;
    }
    .keepTogether, .financial-table, .note, .approval {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .financial-table, .note-table,
    .statutory-statements, .note, .revision-statement {
        max-width: 100%;
    }
    .financial-table .description,
    .note-table .description {
        overflow-wrap: anywhere;
    }
    .financial-table .amount,
    .note-table .amount {
        overflow: visible;
    }
}
CSS;
    }

    private function evidenceFooter(string $evidenceArtifactId): string
    {
        $evidenceArtifactId = trim($evidenceArtifactId);
        if ($evidenceArtifactId === '') {
            return '';
        }
        return '<div class="evidence-footer">Evidence ID: '
            . $this->e($evidenceArtifactId) . '</div>';
    }

    private function contexts(
        string $companyNumber,
        string $start,
        string $end,
        ?array $comparative,
        array $usedContextRefs
    ): string
    {
        $companyNumber = $companyNumber !== '' ? $companyNumber : 'UNKNOWN';
        $start = $this->validDate($start, '1970-01-01');
        $end = $this->validDate($end, $start);
        $definitions = [
            ['current_period_duration', 'duration', $start, $end, []],
            ['current_period_duration_director_1', 'duration', $start, $end, [
                'bus:EntityOfficersDimension' => 'bus:Director1',
            ]],
            ['current_period_duration_accounts_type', 'duration', $start, $end, [
                'bus:AccountsTypeDimension' => 'bus:FullAccounts',
            ]],
            ['current_period_duration_country_formation', 'duration', $start, $end, [
                'countries:CountriesRegionsDimension' => 'countries:EnglandWales',
            ]],
            ['current_period_duration_legal_form', 'duration', $start, $end, [
                'bus:LegalFormEntityDimension' => 'bus:PrivateLimitedCompanyLtd',
            ]],
            ['current_period_duration_registered_office', 'duration', $start, $end, [
                'bus:EntityContactTypeDimension' => 'bus:RegisteredOffice',
                'countries:CountriesRegionsDimension' => 'countries:UnitedKingdom',
            ]],
            ['current_period_duration_accounting_standards', 'duration', $start, $end, [
                'bus:AccountingStandardsDimension' => 'bus:Micro-entities',
            ]],
            ['current_period_duration_accounts_status', 'duration', $start, $end, [
                'bus:AccountsStatusDimension' => 'bus:AuditExempt-NoAccountantsReport',
            ]],
            ['current_period_duration_entity_never_traded', 'duration', $start, $end, [
                'bus:EntityTradingStatusDimension' => 'bus:EntityHasNeverTraded',
            ]],
            ['current_period_duration_entity_no_longer_trading', 'duration', $start, $end, [
                'bus:EntityTradingStatusDimension' => 'bus:EntityNoLongerTradingButTradedInPast',
            ]],
            ['current_period_end', 'instant', $end, '', []],
            ['current_period_end_creditors_within_one_year', 'instant', $end, '', [
                'core:MaturitiesOrExpirationPeriodsDimension' => 'core:WithinOneYear',
            ]],
            ['current_period_end_creditors_after_one_year', 'instant', $end, '', [
                'core:MaturitiesOrExpirationPeriodsDimension' => 'core:AfterOneYear',
            ]],
        ];
        if ($comparative !== null) {
            $comparativeStart = $this->validDate((string)($comparative['period_start'] ?? ''), $start);
            $comparativeEnd = $this->validDate((string)($comparative['period_end'] ?? ''), $start);
            $definitions[] = ['comparative_period_duration', 'duration', $comparativeStart, $comparativeEnd, []];
            $definitions[] = ['comparative_period_end', 'instant', $comparativeEnd, '', []];
            $definitions[] = ['comparative_period_end_creditors_within_one_year', 'instant', $comparativeEnd, '', [
                    'core:MaturitiesOrExpirationPeriodsDimension' => 'core:WithinOneYear',
                ]];
            $definitions[] = ['comparative_period_end_creditors_after_one_year', 'instant', $comparativeEnd, '', [
                    'core:MaturitiesOrExpirationPeriodsDimension' => 'core:AfterOneYear',
                ]];
        }
        $used = array_fill_keys(array_filter($usedContextRefs), true);
        $contexts = '';
        foreach ($definitions as [$id, $type, $firstDate, $secondDate, $dimensions]) {
            if (!isset($used[$id])) {
                continue;
            }
            $contexts .= $type === 'duration'
                ? $this->durationContext($id, $companyNumber, $firstDate, $secondDate, $dimensions)
                : $this->instantContext($id, $companyNumber, $firstDate, $dimensions);
        }

        return $contexts;
    }

    private function durationContext(string $id, string $companyNumber, string $start, string $end, array $dimensions = []): string
    {
        return '<xbrli:context id="' . $this->e($id) . '"><xbrli:entity>'
            . $this->entityContent($companyNumber, $dimensions)
            . '</xbrli:entity><xbrli:period><xbrli:startDate>' . $this->e($start)
            . '</xbrli:startDate><xbrli:endDate>' . $this->e($end)
            . '</xbrli:endDate></xbrli:period></xbrli:context>' . "\n";
    }

    private function instantContext(string $id, string $companyNumber, string $date, array $dimensions = []): string
    {
        return '<xbrli:context id="' . $this->e($id) . '"><xbrli:entity>'
            . $this->entityContent($companyNumber, $dimensions)
            . '</xbrli:entity><xbrli:period><xbrli:instant>' . $this->e($date)
            . '</xbrli:instant></xbrli:period></xbrli:context>' . "\n";
    }

    private function entityContent(string $companyNumber, array $dimensions): string
    {
        $content = '<xbrli:identifier scheme="http://www.companieshouse.gov.uk/">' . $this->e($companyNumber) . '</xbrli:identifier>';
        if ($dimensions !== []) {
            $content .= '<xbrli:segment>';
            foreach ($dimensions as $dimension => $member) {
                $content .= '<xbrldi:explicitMember dimension="' . $this->e((string)$dimension) . '">'
                    . $this->e((string)$member) . '</xbrldi:explicitMember>';
            }
            $content .= '</xbrli:segment>';
        }
        return $content;
    }

    private function inlineFact(array $fact, array $options = []): string
    {
        if ($fact === []) {
            return '';
        }
        $name = $this->e((string)$fact['taxonomy_concept']);
        $context = $this->e((string)$fact['context_ref']);
        $type = (string)($fact['value_type'] ?? 'text');
        if ($type === 'numeric') {
            $numeric = (float)($fact['numeric_value'] ?? 0);
            $decimals = (string)($fact['decimals_value'] ?? '2');
            $precision = $decimals === '0' ? 0 : 2;
            $sign = $numeric < 0 ? ' sign="-"' : '';
            $zeroDash = !empty($options['zero_dash']) && abs($numeric) < 0.0000001;
            $format = $zeroDash ? 'ixt:zerodash' : 'ixt:numdotdecimal';
            $lexical = $zeroDash ? '-' : number_format(abs($numeric), $precision, '.', ',');
            $factHtml = '<ix:nonFraction name="' . $name . '" contextRef="' . $context
                . '" unitRef="' . $this->e((string)($fact['unit_ref'] ?? 'GBP'))
                . '" decimals="' . $this->e($decimals) . '" format="' . $format . '"' . $sign . '>'
                . $lexical . '</ix:nonFraction>';
            if (!empty($options['accounting']) && ($numeric < 0 || (!empty($options['brackets']) && !$zeroDash))) {
                return '(' . $factHtml . ')';
            }

            return $factHtml;
        }
        $naturalDate = $type === 'date' && !empty($options['natural_date']);
        $format = $type === 'date'
            ? ' format="' . ($naturalDate ? 'ixt:datedaymonthyearen' : 'ixt:dateyearmonthday') . '"'
            : '';
        $value = $this->factValue($fact);
        if ($naturalDate) {
            $value = $this->naturalDate($value);
        }
        return '<ix:nonNumeric name="' . $name . '" contextRef="' . $context . '"' . $format . '>'
            . $this->e($value) . '</ix:nonNumeric>';
    }

    /**
     * FRC categorical facts are zero-length markers whose meaning is carried
     * by their context dimensions.  Keeping the marker adjacent to the
     * visible wording makes the cover statement traceable without placing an
     * invalid literal value inside the fact.
     */
    private function categoricalMarker(array $fact): string
    {
        return $fact === [] ? '' : $this->inlineFact($fact);
    }

    private function visibleAmount(float $amount, bool $brackets = false): string
    {
        if (abs($amount) < 0.0000001) {
            return '–';
        }
        $formatted = number_format(abs($amount), 2, '.', ',');

        return $amount < 0 || $brackets ? '(' . $formatted . ')' : $formatted;
    }

    private function numericFact(array $indexed, string $key, bool $comparative): float
    {
        $fact = $comparative
            ? $this->comparativeFact($indexed, $key)
            : $this->currentFact($indexed, $key);

        return (float)($fact['numeric_value'] ?? 0);
    }

    private function comparativePeriodFromIndex(array $indexed): ?array
    {
        foreach ($indexed as $facts) {
            foreach ((array)$facts as $context => $fact) {
                if (!str_starts_with((string)$context, 'comparative_')) {
                    continue;
                }
                $source = json_decode((string)($fact['source_json'] ?? ''), true);
                if (is_array($source)) {
                    return [
                        'period_start' => (string)($source['period_start'] ?? ''),
                        'period_end' => (string)($source['period_end'] ?? ''),
                    ];
                }
            }
        }

        return null;
    }

    private function yearOf(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('Y') : '';
    }

    private function naturalDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('j F Y') : $date;
    }

    private function indexFacts(array $facts): array
    {
        $indexed = [];
        foreach ($facts as $fact) {
            $indexed[(string)$fact['fact_key']][(string)$fact['context_ref']] = $fact;
        }
        return $indexed;
    }

    private function currentFact(array $indexed, string $key): array
    {
        foreach ((array)($indexed[$key] ?? []) as $context => $fact) {
            if (!str_starts_with((string)$context, 'comparative_')) {
                return (array)$fact;
            }
        }
        return [];
    }

    private function comparativeFact(array $indexed, string $key): array
    {
        foreach ((array)($indexed[$key] ?? []) as $context => $fact) {
            if (str_starts_with((string)$context, 'comparative_')) {
                return (array)$fact;
            }
        }
        return [];
    }

    private function hasComparative(array $indexed): bool
    {
        return $this->comparativeFact($indexed, 'net_assets_liabilities') !== [];
    }

    private function comparativePeriod(array $facts): ?array
    {
        foreach ($facts as $fact) {
            if (!str_starts_with((string)($fact['context_ref'] ?? ''), 'comparative_')) {
                continue;
            }
            $source = json_decode((string)($fact['source_json'] ?? ''), true);
            if (is_array($source)) {
                return [
                    'period_start' => (string)($source['period_start'] ?? ''),
                    'period_end' => (string)($source['period_end'] ?? ''),
                ];
            }
        }
        return null;
    }

    private function factValue(array $fact): string
    {
        return match ((string)($fact['value_type'] ?? 'text')) {
            'numeric' => (string)($fact['numeric_value'] ?? '0'),
            'date' => (string)($fact['date_value'] ?? ''),
            default => (string)($fact['text_value'] ?? ''),
        };
    }

    private function assertMicroStatementsReconcile(array $indexed): void
    {
        foreach ([false, true] as $comparative) {
            if ($comparative && !$this->hasComparative($indexed)) {
                continue;
            }
            $fact = fn(string $key): array => $comparative
                ? $this->comparativeFact($indexed, $key)
                : $this->currentFact($indexed, $key);
            $amount = static fn(array $row): float => round((float)($row['numeric_value'] ?? 0), 2);

            $grossProfit = round(
                $amount($fact('turnover'))
                - $amount($fact('raw_materials_consumables')),
                2
            );
            $operatingProfit = round(
                $grossProfit
                + $amount($fact('other_income'))
                - $amount($fact('staff_costs'))
                - $amount($fact('depreciation_write_offs'))
                - $amount($fact('other_charges')),
                2
            );
            $profit = round(
                $operatingProfit - $amount($fact('tax_on_profit')),
                2
            );
            if (abs($grossProfit - $amount($fact('gross_profit_loss'))) >= 0.005
                || abs($operatingProfit - $amount($fact('operating_profit_loss'))) >= 0.005
                || abs($profit - $amount($fact('profit_loss'))) >= 0.005) {
                throw new \RuntimeException(
                    ($comparative ? 'Comparative' : 'Current')
                    . ' micro profit-and-loss lines do not reconcile through gross profit, '
                    . 'operating profit and profit or loss.'
                );
            }

            $netCurrent = round(
                $amount($fact('current_assets'))
                + $amount($fact('prepayments_accrued_income'))
                - $amount($fact('creditors_within_one_year')),
                2
            );
            $totalAssetsLessCurrent = round(
                $amount($fact('called_up_share_capital_not_paid'))
                + $amount($fact('fixed_assets'))
                + $netCurrent,
                2
            );
            $netAssets = round(
                $totalAssetsLessCurrent
                - $amount($fact('creditors_after_one_year'))
                - $amount($fact('provisions_for_liabilities'))
                - $amount($fact('accruals_deferred_income')),
                2
            );
            if (abs($netCurrent - $amount($fact('net_current_assets_liabilities'))) >= 0.005
                || abs($totalAssetsLessCurrent - $amount($fact('total_assets_less_current_liabilities'))) >= 0.005
                || abs($netAssets - $amount($fact('net_assets_liabilities'))) >= 0.005
                || abs($netAssets - $amount($fact('equity'))) >= 0.005) {
                throw new \RuntimeException(
                    ($comparative ? 'Comparative' : 'Current')
                    . ' micro balance-sheet lines do not reconcile to net assets and equity.'
                );
            }
        }
    }

    private function validateInlineXbrl(string $xhtml, array $sourceFacts = []): array
    {
        $errors = [];
        if (!str_starts_with($xhtml, CompaniesHouseIxbrlDocumentPolicyService::DOCUMENT_PREFIX)) {
            $errors[] = 'The deterministic UTF-8 XML declaration is missing.';
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xhtml) === 1) {
            $errors[] = 'DOCTYPE and entity declarations are not permitted.';
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            $errors[] = 'Generated XHTML is not well-formed XML.';

            return array_values(array_unique($errors));
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
        $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $xpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');
        $xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement
            || $root->namespaceURI !== 'http://www.w3.org/1999/xhtml'
            || $root->localName !== 'html') {
            $errors[] = 'The document root must be an XHTML html element.';
        } elseif ($root->hasAttribute('lang')
            || $root->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') !== 'en') {
            $errors[] = 'The XHTML root must declare xml:lang as en without the schema-invalid HTML lang attribute.';
        }
        foreach (IxbrlTaxonomyProfileService::NAMESPACES as $prefix => $namespace) {
            if (!$root instanceof \DOMElement
                || $root->lookupNamespaceURI((string)$prefix) !== $namespace) {
                $errors[] = 'The required ' . $prefix . ' taxonomy namespace is missing.';
            }
        }
        if (($xpath->query('//ix:header')->length ?? 0) !== 1) {
            $errors[] = 'Exactly one Inline XBRL header is required.';
        }
        if (($xpath->query('//ix:hidden')->length ?? 0) !== 1
            || ($xpath->query('//ix:references')->length ?? 0) !== 1
            || ($xpath->query('//ix:resources')->length ?? 0) !== 1) {
            $errors[] = 'Exactly one Inline XBRL hidden, references and resources block is required.';
        }
        if (($xpath->query('//link:schemaRef')->length ?? 0) !== 1
            || ($xpath->query('//link:schemaRef[@xlink:href="' . IxbrlTaxonomyProfileService::SCHEMA_REF . '"]')->length ?? 0) !== 1) {
            $errors[] = 'Exactly one FRC 2026 FRS-102 taxonomy entry point is required.';
        }
        if (($xpath->query('//xbrli:unit[@id="GBP"]')->length ?? 0) < 1
            || ($xpath->query('//xbrli:unit[@id="pure"]')->length ?? 0) < 1) {
            $errors[] = 'Required GBP and pure units are missing.';
        }
        if (($xpath->query('//ix:nonFraction | //ix:nonNumeric')->length ?? 0) < 1) {
            $errors[] = 'No Inline XBRL facts were generated.';
        }

        $contextIds = [];
        foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
            if (!$context instanceof \DOMElement) {
                continue;
            }
            $id = $context->getAttribute('id');
            if ($id === '' || isset($contextIds[$id])) {
                $errors[] = 'Every context must have a unique non-empty id.';
            }
            $contextIds[$id] = true;
        }
        $unitIds = [];
        foreach ($xpath->query('//xbrli:unit') ?: [] as $unit) {
            if (!$unit instanceof \DOMElement) {
                continue;
            }
            $id = $unit->getAttribute('id');
            if ($id === '' || isset($unitIds[$id])) {
                $errors[] = 'Every unit must have a unique non-empty id.';
            }
            $unitIds[$id] = true;
        }

        $duplicateFactValues = [];
        $referencedContexts = [];
        $referencedUnits = [];
        foreach ($xpath->query('//ix:nonFraction | //ix:nonNumeric') ?: [] as $fact) {
            if (!$fact instanceof \DOMElement) {
                continue;
            }
            $contextRef = $fact->getAttribute('contextRef');
            if ($contextRef === '' || !isset($contextIds[$contextRef])) {
                $errors[] = 'Fact contextRef does not resolve: ' . $contextRef . '.';
            }
            if ($contextRef !== '') {
                $referencedContexts[$contextRef] = true;
            }
            $unitRef = $fact->getAttribute('unitRef');
            if ($fact->localName === 'nonFraction') {
                if ($unitRef === '' || !isset($unitIds[$unitRef])) {
                    $errors[] = 'Numeric fact unitRef does not resolve: ' . $unitRef . '.';
                }
                if ($unitRef !== '') {
                    $referencedUnits[$unitRef] = true;
                }
                if (!$fact->hasAttribute('decimals') || $fact->hasAttribute('precision')) {
                    $errors[] = 'Numeric facts must use decimals and must not use precision.';
                }
                if ($fact->getAttribute('name') === 'core:AverageNumberEmployeesDuringPeriod') {
                    if ($unitRef !== 'pure') {
                        $errors[] = 'Employee counts must use the pure unit.';
                    }
                } elseif ($unitRef !== 'GBP') {
                    $errors[] = 'Monetary facts must use the GBP unit.';
                }
            }
            $aspectKey = implode('|', [
                $fact->localName,
                $fact->getAttribute('name'),
                $contextRef,
                $unitRef,
            ]);
            $valueKey = implode('|', [
                $fact->getAttribute('decimals'),
                $fact->getAttribute('sign'),
                trim($fact->textContent),
            ]);
            if (isset($duplicateFactValues[$aspectKey])
                && !hash_equals($duplicateFactValues[$aspectKey], $valueKey)) {
                $errors[] = 'Inconsistent duplicate Inline XBRL fact was generated: '
                    . $fact->getAttribute('name') . '.';
            }
            $duplicateFactValues[$aspectKey] = $valueKey;
        }
        foreach (array_keys($contextIds) as $contextId) {
            if (!isset($referencedContexts[$contextId])) {
                $errors[] = 'Unused Inline XBRL context was generated: ' . $contextId . '.';
            }
        }
        foreach (array_keys($unitIds) as $unitId) {
            if (!isset($referencedUnits[$unitId])) {
                $errors[] = 'Unused Inline XBRL unit was generated: ' . $unitId . '.';
            }
        }

        $expectedCompanyNumber = '';
        $companyFacts = $xpath->query('//*[@name="bus:UKCompaniesHouseRegisteredNumber"]');
        if (($companyFacts->length ?? 0) >= 1) {
            $expectedCompanyNumber = trim((string)$companyFacts->item(0)?->textContent);
            foreach ($companyFacts as $companyFact) {
                if (!$companyFact instanceof \DOMElement
                    || trim($companyFact->textContent) !== $expectedCompanyNumber) {
                    $errors[] = 'Company-number facts are inconsistent.';
                    break;
                }
            }
        }
        foreach ($xpath->query('//xbrli:context/xbrli:entity/xbrli:identifier') ?: [] as $identifier) {
            if (!$identifier instanceof \DOMElement
                || $identifier->getAttribute('scheme') !== 'http://www.companieshouse.gov.uk/'
                || ($expectedCompanyNumber !== '' && trim($identifier->textContent) !== $expectedCompanyNumber)) {
                $errors[] = 'Context company identifiers or schemes are inconsistent.';
                break;
            }
        }

        foreach ([
            'bus:EntityCurrentLegalOrRegisteredName',
            'bus:UKCompaniesHouseRegisteredNumber',
            'bus:CountryFormationOrIncorporation',
            'bus:LegalFormEntity',
            'bus:AddressLine1',
            'bus:AddressLine2',
            'bus:AddressLine3',
            'bus:PostalCodeZip',
            'bus:StartDateForPeriodCoveredByReport',
            'bus:EndDateForPeriodCoveredByReport',
            'bus:BalanceSheetDate',
            'bus:DescriptionPrincipalActivities',
            'bus:EntityDormantTruefalse',
            'bus:NameEntityOfficer',
            'bus:EntityTradingStatus',
            'bus:AccountingStandardsApplied',
            'bus:AccountsStatusAuditedOrUnaudited',
            'bus:AccountsType',
            'bus:NameProductionSoftware',
            'bus:VersionProductionSoftware',
            'core:DateAuthorisationFinancialStatementsForIssue',
            'core:DirectorSigningFinancialStatements',
            'core:TurnoverRevenue',
            'core:OtherOperatingIncomeFormat2',
            'core:RawMaterialsConsumablesUsed',
            'core:GrossProfitLoss',
            'core:StaffCostsEmployeeBenefitsExpense',
            'core:DepreciationAmortisationImpairmentExpense',
            'core:OtherExternalCharges',
            'core:OperatingProfitLoss',
            'core:ProfitLoss',
            'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset',
            'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
            'core:ProvisionsForLiabilitiesBalanceSheetSubtotal',
            'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal',
            'core:NetAssetsLiabilities',
            'core:AverageNumberEmployeesDuringPeriod',
            'core:GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity',
            'core:DescriptionCapitalCommitments',
            'core:DescriptionFinancialCommitmentsOtherThanCapitalCommitments',
            'core:GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement',
            'direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime',
            'direp:StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies',
            'direp:StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct',
            'direp:StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit',
            'direp:GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates',
            'direp:GeneralDescriptionGuaranteesTheirTermsDirectors',
        ] as $requiredConcept) {
            $query = '//*[@name="' . $requiredConcept . '"]';
            if (($xpath->query($query)->length ?? 0) < 1) {
                $errors[] = 'Required filing fact is missing: ' . $requiredConcept . '.';
            }
        }
        foreach ($this->directorSigningValidationErrors($xpath) as $directorSigningError) {
            $errors[] = $directorSigningError;
        }
        foreach ($this->categoricalMarkerValidationErrors($xpath) as $categoricalMarkerError) {
            $errors[] = $categoricalMarkerError;
        }
        foreach ($this->contextDimensionValidationErrors($xpath) as $contextDimensionError) {
            $errors[] = $contextDimensionError;
        }
        if (($xpath->query('//xbrldi:explicitMember[@dimension="bus:AccountsTypeDimension" and normalize-space(.)="bus:FullAccounts"]')->length ?? 0) !== 1) {
            $errors[] = 'The Full Accounts type dimension is missing or duplicated.';
        }
        foreach ([
            'bus:StartDateForPeriodCoveredByReport',
            'bus:EndDateForPeriodCoveredByReport',
            'bus:BalanceSheetDate',
            'core:DateAuthorisationFinancialStatementsForIssue',
        ] as $instantConcept) {
            if (($xpath->query('//*[@name="' . $instantConcept . '" and @contextRef="current_period_end"]')->length ?? 0) < 1) {
                $errors[] = $instantConcept . ' must use the balance-sheet instant context.';
            }
        }
        foreach ($xpath->query(
            '//ix:nonFraction[starts-with(normalize-space(text()), "-") and not(@format="ixt:zerodash")]'
        ) ?: [] as $_) {
            $errors[] = 'Negative transformed numbers must use the sign attribute and positive lexical content.';
            break;
        }
        if (($xpath->query('//ix:nonFraction[@name="core:Equity" and @sign="-"]')->length ?? 0) > 0) {
            $errors[] = 'A negative core:Equity fact must not be emitted because it fails HMRC.5.3.';
        }
        foreach ($this->equityOutputPolicyErrors($xpath, $sourceFacts) as $equityError) {
            $errors[] = $equityError;
        }
        foreach ($xpath->query('//ix:nonFraction[@sign="-" and not(ancestor::ix:hidden)]') ?: [] as $negativeFact) {
            if (!$negativeFact instanceof \DOMElement) {
                continue;
            }
            $parentText = trim((string)$negativeFact->parentNode?->textContent);
            if (!str_starts_with($parentText, '(') && !str_starts_with($parentText, '-')) {
                $errors[] = 'A negative fact is displayed as a positive amount: '
                    . $negativeFact->getAttribute('name') . '.';
            }
        }
        if (($xpath->query('//text()[normalize-space(.)="true" and not(ancestor::ix:hidden)]')->length ?? 0) > 0) {
            $errors[] = 'A raw boolean true value is visible in the statutory accounts.';
        }
        if (str_contains($xhtml, 'EEL filing evidence artifact:')) {
            $errors[] = 'An internal evidence-artifact identifier is visible in the statutory accounts.';
        }
        if (str_contains($xhtml, '<section') || str_contains($xhtml, '<meta charset=')) {
            $errors[] = 'HTML5-only markup is not allowed in the Inline XHTML profile.';
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function equityOutputPolicyErrors(\DOMXPath $xpath, array $sourceFacts): array
    {
        $errors = [];
        foreach ($sourceFacts as $sourceFact) {
            if (!is_array($sourceFact)
                || (string)($sourceFact['taxonomy_concept'] ?? '') !== 'core:Equity'
                || (string)($sourceFact['value_type'] ?? '') !== 'numeric') {
                continue;
            }
            $context = (string)($sourceFact['context_ref'] ?? '');
            $expected = round((float)($sourceFact['numeric_value'] ?? 0), 2);
            $equityValues = $this->numericFactValues($xpath, 'core:Equity', $context);
            if ($expected < 0) {
                if ($equityValues !== []) {
                    $errors[] = 'Negative core:Equity must be omitted for context ' . $context . '.';
                }
                $netAssetValues = $this->numericFactValues($xpath, 'core:NetAssetsLiabilities', $context);
                if (!$this->containsMatchingAmount($netAssetValues, $expected)) {
                    $errors[] = 'Omitted negative core:Equity requires a matching core:NetAssetsLiabilities fact for context '
                        . $context . '.';
                }
                continue;
            }
            if (!$this->containsMatchingAmount($equityValues, $expected)) {
                $errors[] = 'Non-negative core:Equity is missing or mismatched for context ' . $context . '.';
            }
        }

        return $errors;
    }

    /** @return list<float> */
    private function numericFactValues(\DOMXPath $xpath, string $concept, string $context): array
    {
        $values = [];
        foreach ($xpath->query('//ix:nonFraction[@name="' . $concept . '"]') ?: [] as $fact) {
            if (!$fact instanceof \DOMElement || $fact->getAttribute('contextRef') !== $context) {
                continue;
            }
            $lexical = trim(str_replace(',', '', $fact->textContent));
            $value = $fact->getAttribute('format') === 'ixt:zerodash' ? 0.0 : (float)$lexical;
            if ($fact->getAttribute('sign') === '-') {
                $value *= -1;
            }
            $values[] = round($value, 2);
        }

        return $values;
    }

    /** @param list<float> $values */
    private function containsMatchingAmount(array $values, float $expected): bool
    {
        foreach ($values as $value) {
            if (abs($value - $expected) < 0.005) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function negativeEquityOmissionWarnings(array $facts): array
    {
        $warnings = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)
                || (string)($fact['taxonomy_concept'] ?? '') !== 'core:Equity'
                || (string)($fact['value_type'] ?? '') !== 'numeric'
                || (float)($fact['numeric_value'] ?? 0) >= 0) {
                continue;
            }
            $context = (string)($fact['context_ref'] ?? 'unknown');
            $warnings[] = 'IXBRL-HMRC-NEGATIVE-EQUITY: The ' . $context
                . ' core:Equity fact was omitted because the correct negative value would trigger HMRC.5.3 '
                . 'against the standard label "Equity". The equivalent core:NetAssetsLiabilities fact remains tagged.';
        }

        return $warnings;
    }

    /**
     * Validate FRC fixed-item markers against the dimensions that carry their
     * meaning.
     *
     * In the bundled FRC 2026 taxonomy, each concept below is declared as
     * types:fixedItemType with periodType="duration". fixedItemType restricts
     * xbrli:stringItemType to a fixed length of zero, so literal text,
     * Boolean and enumeration lexical values are invalid here. The business
     * meaning is supplied by the fact context. EntityTradingStatus is the
     * exception only in how its dimension is represented: the taxonomy
     * declares EntityTradingDefault ("Entity is trading") as the dimension
     * default, which must be omitted from an instance context.
     *
     * @return list<string>
     */
    private function categoricalMarkerValidationErrors(\DOMXPath $xpath): array
    {
        $errors = [];
        $dimensionProfiles = [
            'bus:CountryFormationOrIncorporation' => [
                'countries:CountriesRegionsDimension' => 'countries:EnglandWales',
            ],
            'bus:LegalFormEntity' => [
                'bus:LegalFormEntityDimension' => 'bus:PrivateLimitedCompanyLtd',
            ],
            'bus:AccountingStandardsApplied' => [
                'bus:AccountingStandardsDimension' => 'bus:Micro-entities',
            ],
            'bus:AccountsStatusAuditedOrUnaudited' => [
                'bus:AccountsStatusDimension' => 'bus:AuditExempt-NoAccountantsReport',
            ],
            'bus:AccountsType' => [
                'bus:AccountsTypeDimension' => 'bus:FullAccounts',
            ],
        ];

        foreach ($dimensionProfiles as $concept => $expectedDimensions) {
            $facts = $xpath->query('//ix:nonNumeric[@name="' . $concept . '"]');
            $count = $facts instanceof \DOMNodeList ? $facts->length : 0;
            if ($count !== 1) {
                $errors[] = 'Exactly one ' . $concept . ' fixed-item marker fact is required.';
                continue;
            }
            $fact = $facts->item(0);
            if (!$fact instanceof \DOMElement) {
                $errors[] = $concept . ' is malformed.';
                continue;
            }
            if ($fact->textContent !== '') {
                $errors[] = $concept . ' must be a zero-length taxonomy marker.';
            }
            $context = $this->contextForFact($xpath, $fact);
            if (!$context instanceof \DOMElement) {
                $errors[] = $concept . ' does not have a resolvable context.';
                continue;
            }
            if (!$this->isDurationContext($xpath, $context)) {
                $errors[] = $concept . ' must use a duration context.';
            }
            $actualDimensions = $this->explicitDimensions($xpath, $context);
            if ($actualDimensions !== $expectedDimensions) {
                $errors[] = $concept . ' must use the taxonomy dimension profile '
                    . $this->dimensionProfileLabel($expectedDimensions) . '.';
            }
            if (($xpath->query('.//xbrldi:typedMember', $context)->length ?? 0) > 0) {
                $errors[] = $concept . ' must not use a typed dimension.';
            }
        }

        $tradingFacts = $xpath->query('//ix:nonNumeric[@name="bus:EntityTradingStatus"]');
        $tradingCount = $tradingFacts instanceof \DOMNodeList ? $tradingFacts->length : 0;
        if ($tradingCount !== 1) {
            $errors[] = 'Exactly one bus:EntityTradingStatus fixed-item marker fact is required.';
        } else {
            $tradingFact = $tradingFacts->item(0);
            if (!$tradingFact instanceof \DOMElement) {
                $errors[] = 'bus:EntityTradingStatus is malformed.';
            } else {
                if ($tradingFact->textContent !== '') {
                    $errors[] = 'bus:EntityTradingStatus must be a zero-length taxonomy marker.';
                }
                $context = $this->contextForFact($xpath, $tradingFact);
                if (!$context instanceof \DOMElement) {
                    $errors[] = 'bus:EntityTradingStatus does not have a resolvable context.';
                } else {
                    if (!$this->isDurationContext($xpath, $context)) {
                        $errors[] = 'bus:EntityTradingStatus must use a duration context.';
                    }
                    $actualDimensions = $this->explicitDimensions($xpath, $context);
                    $permittedProfiles = [
                        [],
                        [
                            'bus:EntityTradingStatusDimension' => 'bus:EntityHasNeverTraded',
                        ],
                        [
                            'bus:EntityTradingStatusDimension' => 'bus:EntityNoLongerTradingButTradedInPast',
                        ],
                    ];
                    if (!in_array($actualDimensions, $permittedProfiles, true)) {
                        $errors[] = 'bus:EntityTradingStatus must use the implicit trading default, '
                            . 'bus:EntityHasNeverTraded or bus:EntityNoLongerTradingButTradedInPast.';
                    }
                    if (($xpath->query('.//xbrldi:typedMember', $context)->length ?? 0) > 0) {
                        $errors[] = 'bus:EntityTradingStatus must not use a typed dimension.';
                    }
                }
            }
        }

        if (($xpath->query(
            '//xbrldi:explicitMember[@dimension="bus:EntityTradingStatusDimension"'
            . ' and normalize-space(.)="bus:EntityTradingDefault"]'
        )->length ?? 0) > 0) {
            $errors[] = 'bus:EntityTradingDefault is a taxonomy default and must not be emitted explicitly.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function contextDimensionValidationErrors(\DOMXPath $xpath): array
    {
        $errors = [];
        foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
            if (!$context instanceof \DOMElement) {
                continue;
            }
            $contextId = $context->getAttribute('id');
            $seenDimensions = [];
            foreach ($xpath->query(
                './xbrli:entity/xbrli:segment/xbrldi:explicitMember'
                . ' | ./xbrli:entity/xbrli:segment/xbrldi:typedMember',
                $context
            ) ?: [] as $member) {
                if (!$member instanceof \DOMElement) {
                    continue;
                }
                $dimension = trim($member->getAttribute('dimension'));
                if ($dimension === '') {
                    $errors[] = 'Context ' . $contextId . ' contains a dimension member without a dimension QName.';
                    continue;
                }
                if (isset($seenDimensions[$dimension])) {
                    $errors[] = 'Context ' . $contextId . ' contains duplicate dimension ' . $dimension . '.';
                }
                $seenDimensions[$dimension] = true;
                if ($member->localName === 'explicitMember' && trim($member->textContent) === '') {
                    $errors[] = 'Context ' . $contextId . ' contains an empty explicit member for '
                        . $dimension . '.';
                }
            }
        }

        return $errors;
    }

    private function contextForFact(\DOMXPath $xpath, \DOMElement $fact): ?\DOMElement
    {
        $contextRef = $fact->getAttribute('contextRef');
        if ($contextRef === '') {
            return null;
        }
        foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
            if ($context instanceof \DOMElement && $context->getAttribute('id') === $contextRef) {
                return $context;
            }
        }

        return null;
    }

    private function isDurationContext(\DOMXPath $xpath, \DOMElement $context): bool
    {
        return ($xpath->query('./xbrli:period/xbrli:startDate', $context)->length ?? 0) === 1
            && ($xpath->query('./xbrli:period/xbrli:endDate', $context)->length ?? 0) === 1
            && ($xpath->query('./xbrli:period/xbrli:instant', $context)->length ?? 0) === 0;
    }

    /**
     * @return array<string, string>
     */
    private function explicitDimensions(\DOMXPath $xpath, \DOMElement $context): array
    {
        $dimensions = [];
        foreach ($xpath->query(
            './xbrli:entity/xbrli:segment/xbrldi:explicitMember',
            $context
        ) ?: [] as $member) {
            if (!$member instanceof \DOMElement) {
                continue;
            }
            $dimensions[trim($member->getAttribute('dimension'))] = trim($member->textContent);
        }
        ksort($dimensions, SORT_STRING);

        return $dimensions;
    }

    /**
     * @param array<string, string> $dimensions
     */
    private function dimensionProfileLabel(array $dimensions): string
    {
        $parts = [];
        foreach ($dimensions as $dimension => $member) {
            $parts[] = $dimension . '=' . $member;
        }

        return implode(', ', $parts);
    }

    private function directorSigningValidationErrors(\DOMXPath $xpath): array
    {
        $errors = [];
        $markerFacts = $xpath->query('//*[@name="core:DirectorSigningFinancialStatements"]');
        $nameFacts = $xpath->query('//*[@name="bus:NameEntityOfficer"]');
        $markerCount = $markerFacts instanceof \DOMNodeList ? $markerFacts->length : 0;
        $nameCount = $nameFacts instanceof \DOMNodeList ? $nameFacts->length : 0;

        if ($markerCount !== 1) {
            $errors[] = 'Exactly one DirectorSigningFinancialStatements marker fact is required.';
        }
        if ($nameCount !== 1) {
            $errors[] = 'Exactly one NameEntityOfficer fact is required for the approving director.';
        }
        if ($markerCount !== 1 || $nameCount !== 1) {
            return $errors;
        }

        $marker = $markerFacts->item(0);
        $name = $nameFacts->item(0);
        if (!$marker instanceof \DOMElement || !$name instanceof \DOMElement) {
            $errors[] = 'The approving-director signing facts are malformed.';

            return $errors;
        }
        if ($marker->textContent !== '') {
            $errors[] = 'DirectorSigningFinancialStatements must be a zero-length taxonomy marker.';
        }
        if (trim($name->textContent) === '') {
            $errors[] = 'NameEntityOfficer must contain the approving director name.';
        }

        $markerContextRef = $marker->getAttribute('contextRef');
        $nameContextRef = $name->getAttribute('contextRef');
        if ($markerContextRef === '' || $markerContextRef !== $nameContextRef) {
            $errors[] = 'DirectorSigningFinancialStatements and NameEntityOfficer must use the same director context.';
        }

        $directorContext = null;
        foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
            if ($context instanceof \DOMElement && $context->getAttribute('id') === $markerContextRef) {
                $directorContext = $context;
                break;
            }
        }
        $members = $directorContext instanceof \DOMElement
            ? $xpath->query(
                './/xbrldi:explicitMember[@dimension="bus:EntityOfficersDimension"]',
                $directorContext
            )
            : false;
        if (!$members instanceof \DOMNodeList
            || $members->length !== 1
            || trim((string)$members->item(0)?->textContent) !== 'bus:Director1') {
            $errors[] = 'The approving-director context must identify bus:Director1 through bus:EntityOfficersDimension.';
        }

        return $errors;
    }

    private function validDate(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }

    private function e(string $value): string
    {
        return \eel_accounts\Support\Utf8::html($value);
    }
}
