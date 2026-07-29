<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DirectorLoanService
{
    public const CASH_REPAYMENT_JOURNAL_TAG = 'director_loan_cash_repayment';
    public const WRITE_OFF_JOURNAL_TAG = 'director_loan_write_off';
    public const WAIVER_JOURNAL_TAG = 'director_loan_waiver';

    public function fetchPeriods(int $companyId): array
    {
        if ($companyId <= 0) {
            return $this->error('Select a company first.');
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC',
            ['company_id' => $companyId]
        );

        return [
            'success' => true,
            'periods' => $periods,
            'selected_accounting_period_id' => (int)($periods[0]['id'] ?? 0),
            'accounting_period_id' => (int)($periods[0]['id'] ?? 0),
        ];
    }

    public function fetchStatement(int $companyId, int $accountingPeriodId): array
    {
        $requestCacheKey = $companyId . ':' . $accountingPeriodId;
        if (\eel_accounts\Support\RequestCache::has('director-loan.statement', $requestCacheKey)) {
            return (array)\eel_accounts\Support\RequestCache::get('director-loan.statement', $requestCacheKey);
        }

        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->error('The selected accounting period could not be found for this company.');
        }

        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        $partyTermsService = new ParticipatorLoanPartyTermsService();
        try {
            $snapshottedLiabilityNominalId = $partyTermsService
                ->periodLiabilityNominalAccountId($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }
        $assetNominal = $this->nominal((int)$controls['asset']);
        $liabilityNominal = $this->nominal(
            $snapshottedLiabilityNominalId ?? (int)$controls['liability']
        );
        $errors = [];
        $missingControlNominals = $assetNominal === null || $liabilityNominal === null;
        if ($missingControlNominals) {
            $errors[] = 'Configure both Participator Loan control nominals in Company Nominals.';
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'accounting_period' => $period,
                'asset_nominal' => $assetNominal,
                'liability_nominal' => $liabilityNominal,
                'missing_control_nominals' => $missingControlNominals,
            ];
        }

        $periodStart = (string)$period['period_start'];
        $periodEnd = (string)$period['period_end'];
        $rawLines = $this->rawLines(
            $companyId,
            $periodStart,
            $periodEnd,
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );
        $postedReclassification = $this->postedReclassification(
            $companyId,
            $periodEnd,
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );

        $ownership = (new OwnershipPartyService())->fetchSummary($companyId, $periodEnd);
        $directors = array_map(static function (array $party): array {
            return [
                'id' => (int)$party['id'],
                'company_id' => (int)$party['company_id'],
                'full_name' => (string)$party['legal_name'] . ((int)($party['linked_director_id'] ?? 0) > 0 ? ' (Director)' : ''),
                'party_name' => (string)$party['legal_name'],
                'party_type' => (string)($party['party_type'] ?? ''),
                'linked_director_id' => (int)($party['linked_director_id'] ?? 0),
                'is_active' => 1,
                'appointed_on' => '',
                'resigned_on' => '',
            ];
        }, array_values(array_filter((array)($ownership['parties'] ?? []), static fn(mixed $party): bool => is_array($party))));
        $directorMap = [];
        foreach ($directors as $director) {
            $directorMap[(int)$director['id']] = $director;
        }

        $groups = [];
        $statementRows = [];
        $attributionEntries = [];
        $unattributed = [];
        $invalid = [];

        foreach ($rawLines as $line) {
            $directorId = (int)($line['director_id'] ?? 0);
            $wasUnattributed = $directorId <= 0;
            $wasInvalid = false;
            $director = $directorId > 0 ? ($directorMap[$directorId] ?? null) : null;
            $sameCompany = $directorId <= 0 || ((int)($line['director_company_id'] ?? 0) === $companyId);
            if ($directorId > 0 && (!$sameCompany || $director === null)) {
                $invalid[] = $this->entryRow($line, null);
                $wasInvalid = true;
                $directorId = 0;
                $director = null;
            }

            $key = $directorId > 0 ? (string)$directorId : 'unattributed';
            if (!isset($groups[$key])) {
                $groups[$key] = $this->emptyDirectorPosition($director);
            }

            $role = (int)$line['nominal_account_id'] === (int)$assetNominal['id'] ? 'asset' : 'liability';
            $normalAmount = $role === 'asset'
                ? round((float)$line['debit'] - (float)$line['credit'], 2)
                : round((float)$line['credit'] - (float)$line['debit'], 2);
            $isOpening = !empty($line['is_opening']);
            $bucket = $isOpening ? 'opening' : 'movement';
            $groups[$key][$bucket . '_' . $role] = round(
                (float)$groups[$key][$bucket . '_' . $role] + $normalAmount,
                2
            );
            if (!$isOpening) {
                $groups[$key]['period_movement_count'] =
                    (int)($groups[$key]['period_movement_count'] ?? 0) + 1;
            }

            $entry = $this->entryRow($line, $director);
            $entry['normal_amount'] = $normalAmount;
            $entry['signed_amount'] = round((float)$line['credit'] - (float)$line['debit'], 2);
            $entry['nominal_role'] = $role;
            $entry['account_label'] = $role === 'asset'
                ? \FormattingFramework::nominalLabel($assetNominal)
                : \FormattingFramework::nominalLabel($liabilityNominal);
            $attributionEntries[] = $entry;

            if ($isOpening) {
                if ($wasUnattributed && !$wasInvalid) {
                    $unattributed[] = $entry;
                }
                continue;
            }

            if ($wasUnattributed && !$wasInvalid) {
                $unattributed[] = $entry;
            }
            $statementRows[] = $entry;
        }

        foreach ($postedReclassification as $directorId => $amount) {
            $key = $directorId > 0 ? (string)$directorId : 'unattributed';
            if (!isset($groups[$key])) {
                $groups[$key] = $this->emptyDirectorPosition($directorMap[$directorId] ?? null);
            }
            $groups[$key]['posted_reclassification'] = round((float)$amount, 2);
        }

        $perDirector = [];
        foreach ($groups as $key => $position) {
            $asset = round((float)$position['opening_asset'] + (float)$position['movement_asset'], 2);
            $liability = round((float)$position['opening_liability'] + (float)$position['movement_liability'], 2);
            $partyId = (int)($position['director_id'] ?? 0);
            try {
                $partyTerms = $partyId > 0
                    ? $partyTermsService->resolved($companyId, $accountingPeriodId, $partyId)
                    : [];
            } catch (\Throwable $exception) {
                return [
                    'success' => false,
                    'available' => false,
                    'errors' => [$exception->getMessage()],
                    'accounting_period' => $period,
                    'asset_nominal' => $assetNominal,
                    'liability_nominal' => $liabilityNominal,
                ];
            }
            $fundingTerms = (array)($partyTerms['funding_terms'] ?? $partyTerms);
            $setOffPermitted = !empty($fundingTerms['set_off_right_confirmed'])
                && in_array((string)($fundingTerms['settlement_intention'] ?? ''), ['net', 'simultaneous'], true);
            $desired = $key === 'unattributed' || !$setOffPermitted
                ? 0.0
                : round(min(max(0.0, $asset), max(0.0, $liability)), 2);
            $netLiability = round($liability - $asset, 2);
            $reportableAsset = round(max(0.0, $asset - $desired), 2);
            $reportableLiability = round(max(0.0, $liability - $desired), 2);
            $hasPeriodMovement = (int)($position['period_movement_count'] ?? 0) > 0;
            $hasClosingPosition = abs($asset) >= 0.005 || abs($liability) >= 0.005;
            $classification = (string)($fundingTerms['repayment_timing'] ?? 'within_12_months') === 'after_12_months'
                && !empty($fundingTerms['deferment_right_confirmed'])
                && empty($fundingTerms['repayable_on_demand'])
                ? DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR
                : DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR;
            $resolvedLiabilityNominalId = (int)($partyTerms['liability_nominal_account_id'] ?? 0);
            if ($resolvedLiabilityNominalId <= 0) {
                $resolvedLiabilityNominalId = (int)$liabilityNominal['id'];
            }
            $position += [
                'party_id' => $partyId > 0 ? $partyId : null,
                'party_name' => (string)($position['party_name'] ?? $position['director_name'] ?? 'Unattributed'),
                'linked_director_id' => (int)($position['linked_director_id'] ?? 0),
                'is_director' => (int)($position['linked_director_id'] ?? 0) > 0,
                'has_period_movement' => $hasPeriodMovement,
                'has_closing_position' => $hasClosingPosition,
                'gross_asset' => $asset,
                'gross_liability' => $liability,
                'desired_reclassification' => $desired,
                'set_off_permitted' => $setOffPermitted,
                'set_off_conclusion' => $this->setOffConclusion($fundingTerms, $setOffPermitted),
                'party_terms' => $partyTerms,
                'terms_saved' => !empty($partyTerms['explicit']),
                'funding_terms_saved' => !empty($partyTerms['funding_terms_explicit']),
                'advance_terms_saved' => !empty($partyTerms['advance_terms_explicit']),
                'terms_source' => (string)($partyTerms['terms_source'] ?? ($partyId > 0 ? 'default' : 'unattributed')),
                'terms_revision' => max(0, (int)($partyTerms['revision'] ?? 0)),
                'terms_snapshot' => (string)($partyTerms['terms_source'] ?? '') === 'locked_snapshot',
                'liability_nominal_account_id' => $resolvedLiabilityNominalId,
                'classification' => $classification,
                'maturity_classification' => $classification,
                'reportable_asset' => $reportableAsset,
                'reportable_liability' => $reportableLiability,
                'net_closing_position' => $netLiability,
                'net_position_label' => $this->balanceDirectionLabel($netLiability),
                'potential_s455_exposure' => $reportableAsset,
                'pending_reclassification' => round($desired - (float)$position['posted_reclassification'], 2),
                'unsupported_posted_set_off_amount' => !$setOffPermitted
                    ? round(max(0.0, (float)$position['posted_reclassification']), 2)
                    : 0.0,
            ];
            $perDirector[] = $position;
        }

        usort($perDirector, static fn(array $a, array $b): int =>
            [(int)($a['director_id'] === null), strtolower((string)$a['director_name'])]
            <=> [(int)($b['director_id'] === null), strtolower((string)$b['director_name'])]
        );

        $running = [];
        foreach ($perDirector as $position) {
            $key = (string)($position['director_id'] ?? 'unattributed');
            $running[$key] = round((float)$position['opening_liability'] - (float)$position['opening_asset'], 2);
        }
        usort($statementRows, static fn(array $a, array $b): int =>
            [$a['journal_date'], $a['journal_id'], $a['journal_line_id']]
            <=> [$b['journal_date'], $b['journal_id'], $b['journal_line_id']]
        );
        foreach ($statementRows as &$row) {
            $key = (string)($row['director_id'] ?? 'unattributed');
            $running[$key] = round((float)($running[$key] ?? 0) + (float)$row['signed_amount'], 2);
            $row['running_balance'] = $running[$key];
        }
        unset($row);

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $assetReceivable = round(array_sum(array_column($perDirector, 'gross_asset')), 2);
        $liabilityPayable = round(array_sum(array_column($perDirector, 'gross_liability')), 2);
        $reportableAssetReceivable = round(array_sum(array_column($perDirector, 'reportable_asset')), 2);
        $reportableLiabilityPayable = round(array_sum(array_column($perDirector, 'reportable_liability')), 2);
        $desiredReclassification = round(array_sum(array_column($perDirector, 'desired_reclassification')), 2);
        $postedAmount = round(array_sum(array_column($perDirector, 'posted_reclassification')), 2);
        $pendingMagnitude = round(array_sum(array_map(
            static fn(array $position): float => abs((float)($position['pending_reclassification'] ?? 0)),
            $perDirector
        )), 2);
        $periodMovementCount = count($statementRows);
        $hasActivity = $periodMovementCount > 0
            || abs($assetReceivable) >= 0.005
            || abs($liabilityPayable) >= 0.005;
        $partyFacts = array_values(array_filter(
            $perDirector,
            static fn(array $position): bool => (int)($position['party_id'] ?? 0) > 0
        ));
        $missingTermsCount = count(array_filter(
            $partyFacts,
            static fn(array $position): bool =>
                (!empty($position['has_period_movement']) || !empty($position['has_closing_position']))
                && empty($position['terms_saved'])
        ));

        $result = [
            'success' => true,
            'available' => true,
            'accounting_period' => $period,
            'asset_nominal' => $assetNominal,
            'liability_nominal' => $liabilityNominal,
            'directors' => $directors,
            'per_director' => $perDirector,
            'party_facts' => $partyFacts,
            'statement_rows' => $statementRows,
            'attribution_entries' => $attributionEntries,
            'unattributed_entries' => $unattributed,
            'invalid_director_entries' => $invalid,
            'unattributed_count' => count($unattributed),
            'invalid_director_count' => count($invalid),
            'has_movements_in_period' => $periodMovementCount > 0,
            'has_activity' => $hasActivity,
            'asset_receivable' => $assetReceivable,
            'liability_payable' => $liabilityPayable,
            'reportable_asset_receivable' => $reportableAssetReceivable,
            'reportable_liability_payable' => $reportableLiabilityPayable,
            'liability_within_one_year' => round(array_sum(array_map(
                static fn(array $position): float =>
                    (string)($position['maturity_classification'] ?? '') === DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR
                        ? (float)($position['reportable_liability'] ?? 0)
                        : 0.0,
                $partyFacts
            )), 2),
            'liability_after_one_year' => round(array_sum(array_map(
                static fn(array $position): float =>
                    (string)($position['maturity_classification'] ?? '') === DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR
                        ? (float)($position['reportable_liability'] ?? 0)
                        : 0.0,
                $partyFacts
            )), 2),
            'missing_terms_count' => $missingTermsCount,
            'unsupported_posted_set_off_amount' => round(array_sum(array_column(
                $partyFacts,
                'unsupported_posted_set_off_amount'
            )), 2),
            'set_off_permitted' => !empty(array_filter($perDirector, static fn(array $position): bool => !empty($position['set_off_permitted']))),
            'set_off_evidence' => '',
            'reporting_presentation' => [],
            'desired_reclassification' => $desiredReclassification,
            'posted_reclassification' => $postedAmount,
            'pending_reclassification' => round($desiredReclassification - $postedAmount, 2),
            'pending_reclassification_magnitude' => $pendingMagnitude,
            'potential_s455_exposure' => round(array_sum(array_column($perDirector, 'potential_s455_exposure')), 2),
            'net_position' => round($liabilityPayable - $assetReceivable, 2),
            'closing_balance' => round($liabilityPayable - $assetReceivable, 2),
            'net_position_label' => $this->balanceDirectionLabel(round($liabilityPayable - $assetReceivable, 2)),
            'opening_balance' => round(
                array_sum(array_column($perDirector, 'opening_liability'))
                - array_sum(array_column($perDirector, 'opening_asset')),
                2
            ),
            'movement_in_period' => round(array_sum(array_column($statementRows, 'signed_amount')), 2),
            'default_currency' => (string)($settings['default_currency'] ?? 'GBP'),
            'default_currency_symbol' => (new CompanySettingsService())->defaultCurrencySymbol($settings),
            'date_format' => (string)($settings['date_format'] ?? 'd/m/Y'),
        ];

        return (array)\eel_accounts\Support\RequestCache::put(
            'director-loan.statement',
            $requestCacheKey,
            $result
        );
    }

    public function fetchPositionSummary(int $companyId, int $accountingPeriodId): array
    {
        $statement = $this->fetchStatement($companyId, $accountingPeriodId);
        unset($statement['statement_rows']);
        return $statement + ['summary_only' => true];
    }

    /**
     * Return the note-only director-loan disclosure derived from the signed
     * running account. This deliberately does not change ledger or balance
     * sheet values.
     */
    public function fetchDisclosureSummary(int $companyId, int $accountingPeriodId): array
    {
        $statement = $this->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) {
            return $statement + ['available' => false];
        }

        $rowsByDirector = [];
        foreach ((array)($statement['statement_rows'] ?? []) as $row) {
            $key = (string)($row['director_id'] ?? 'unattributed');
            $rowsByDirector[$key][] = $row;
        }

        $disclosures = [];
        $directorEvidence = [];
        $evidenceByParty = [];
        foreach ((array)($statement['per_director'] ?? []) as $position) {
            $key = (string)($position['director_id'] ?? 'unattributed');
            $legalBalance = round(
                (float)($position['opening_liability'] ?? 0)
                - (float)($position['opening_asset'] ?? 0),
                2
            );
            $maximumDirectorDebt = round(max(0.0, -$legalBalance), 2);
            $advances = 0.0;
            $cashRepayments = 0.0;
            $amountsWrittenOff = 0.0;
            $amountsWaived = 0.0;
            $unclassifiedReductions = 0.0;
            $directorFunding = 0.0;

            foreach ((array)($rowsByDirector[$key] ?? []) as $row) {
                $balanceBefore = $legalBalance;
                $legalBalance = round((float)($row['running_balance'] ?? $balanceBefore), 2);
                $directorDebtBefore = round(max(0.0, -$balanceBefore), 2);
                $directorDebtAfter = round(max(0.0, -$legalBalance), 2);
                $advance = round(max(0.0, $directorDebtAfter - $directorDebtBefore), 2);
                $repayment = round(max(0.0, $directorDebtBefore - $directorDebtAfter), 2);
                $maximumDirectorDebt = max($maximumDirectorDebt, $directorDebtAfter);
                $advances += $advance;

                if ($repayment >= 0.005) {
                    $journalTag = (string)($row['journal_tag'] ?? '');
                    if ($journalTag === self::WRITE_OFF_JOURNAL_TAG) {
                        $amountsWrittenOff += $repayment;
                    } elseif ($journalTag === self::WAIVER_JOURNAL_TAG) {
                        $amountsWaived += $repayment;
                    } elseif ($journalTag === self::CASH_REPAYMENT_JOURNAL_TAG
                        || (string)($row['source_type'] ?? '') === 'bank_csv') {
                        $cashRepayments += $repayment;
                    } else {
                        $unclassifiedReductions += $repayment;
                    }
                }

                $signed = round((float)($row['signed_amount'] ?? 0), 2);
                if ((string)($row['nominal_role'] ?? '') === 'liability' && $signed > 0.004) {
                    $directorFunding += $signed;
                }
            }

            $exposure = round($maximumDirectorDebt, 2);
            $amountsLegallySetOff = 0.0;
            $closingReceivable = round(max(0.0, -$legalBalance), 2);
            $closingLiability = round(max(0.0, $legalBalance), 2);
            $repaymentReductions = round(
                $cashRepayments
                + $amountsWrittenOff
                + $amountsWaived
                + $unclassifiedReductions,
                2
            );
            $hasDisclosure = max(
                $exposure,
                $advances,
                $cashRepayments,
                $amountsLegallySetOff,
                $amountsWrittenOff,
                $amountsWaived,
                $unclassifiedReductions,
                $closingReceivable
            ) >= 0.005;

            $presentation = (array)($position['party_terms'] ?? []);
            $advanceTerms = (array)($presentation['advance_terms'] ?? []);
            $advanceTermsExplicit = !empty($presentation['advance_terms_explicit']) && $advanceTerms !== [];
            $interestRate = DirectorLoanReportingPresentationService::formatInterestRate(
                (float)($advanceTerms['interest_rate_percent'] ?? 0)
            );
            // A creditor maturity classification is never used as evidence of
            // the historical terms on a company-to-participator advance.
            if ($advanceTermsExplicit) {
                // Keep each disclosure component separate. The shared iXBRL
                // narrative builder supplies the labelled interest-rate
                // sentence from interest_rate, so main_terms is security only.
                $mainTerms = ucfirst((string)($advanceTerms['security_type'] ?? 'unsecured')) . '.';
                $repaymentConditions = match ((string)($advanceTerms['repayment_basis'] ?? '')) {
                    'on_demand' => 'Repayable on demand.',
                    'no_fixed_date' => 'No fixed repayment date was agreed.',
                    'fixed_date' => 'Repayable on ' . (string)($advanceTerms['fixed_repayment_date'] ?? '') . '.',
                    default => 'Advance repayment condition requires filing review.',
                };
            } else {
                $mainTerms = 'Company-to-participator advance terms require filing review.';
                $repaymentConditions = 'No advance repayment condition has been confirmed.';
            }
            $mainConditions = $mainTerms . ' ' . $repaymentConditions;
            if ($advanceTermsExplicit) {
                // Preserve the complete human-facing terms summary used outside
                // iXBRL while retaining distinct data components for the fact.
                $mainConditions = $mainTerms . ' Interest rate: ' . $interestRate . '. ' . $repaymentConditions;
            }
            $row = [
                'director_id' => $position['director_id'] ?? null,
                'director_name' => (string)($position['director_name'] ?? 'Unattributed'),
                'party_id' => $position['party_id'] ?? $position['director_id'] ?? null,
                'linked_director_id' => (int)($position['linked_director_id'] ?? 0),
                'is_director' => !empty($position['is_director']),
                'opening_balance' => round(
                    (float)($position['opening_liability'] ?? 0)
                    - (float)($position['opening_asset'] ?? 0),
                    2
                ),
                'maximum_company_to_director_exposure' => $exposure,
                'advances' => round($advances, 2),
                'cash_repayments' => round($cashRepayments, 2),
                'amounts_legally_set_off' => $amountsLegallySetOff,
                'amounts_written_off' => round($amountsWrittenOff, 2),
                'amounts_waived' => round($amountsWaived, 2),
                'unclassified_reductions' => round($unclassifiedReductions, 2),
                'repayments' => $repaymentReductions,
                'director_funding' => round($directorFunding, 2),
                'closing_company_to_director_balance' => $closingReceivable,
                'closing_company_liability' => $closingLiability,
                'interest_rate_percent' => (float)($advanceTerms['interest_rate_percent'] ?? 0),
                'interest_rate' => $interestRate,
                'main_terms' => $mainTerms,
                'repayment_conditions' => $repaymentConditions,
                'main_conditions' => $mainConditions,
                'advance_terms_explicit' => $advanceTermsExplicit,
                'set_off_permitted' => !empty($position['set_off_permitted']),
                'section_413_required' => $hasDisclosure,
            ];
            $disclosures[] = $row;
            if (!empty($row['is_director']) || (int)$row['linked_director_id'] > 0) {
                $partyId = (int)($row['party_id'] ?? 0);
                if ($partyId > 0) {
                    $evidenceByParty[$partyId] = true;
                }
                $directorEvidence[] = $row;
            }
        }

        foreach ((array)($statement['directors'] ?? []) as $director) {
            $partyId = (int)($director['id'] ?? 0);
            $linkedDirectorId = (int)($director['linked_director_id'] ?? 0);
            if ($partyId <= 0 || $linkedDirectorId <= 0 || isset($evidenceByParty[$partyId])) {
                continue;
            }
            $directorEvidence[] = [
                'director_id' => $partyId,
                'director_name' => (string)($director['full_name'] ?? $director['party_name'] ?? 'Director'),
                'party_id' => $partyId,
                'linked_director_id' => $linkedDirectorId,
                'is_director' => true,
                'opening_balance' => 0.0,
                'maximum_company_to_director_exposure' => 0.0,
                'advances' => 0.0,
                'cash_repayments' => 0.0,
                'amounts_legally_set_off' => 0.0,
                'amounts_written_off' => 0.0,
                'amounts_waived' => 0.0,
                'unclassified_reductions' => 0.0,
                'repayments' => 0.0,
                'director_funding' => 0.0,
                'closing_company_to_director_balance' => 0.0,
                'closing_company_liability' => 0.0,
                'section_413_required' => false,
            ];
        }

        return [
            'success' => true,
            'available' => true,
            'has_activity' => !empty($statement['has_activity']),
            'relevant_party_count' => count(array_filter(
                (array)($statement['party_facts'] ?? []),
                static fn(array $party): bool =>
                    !empty($party['has_period_movement']) || !empty($party['has_closing_position'])
            )),
            'has_company_to_director_exposure' => !empty(array_filter(
                $disclosures,
                static fn(array $row): bool => !empty($row['section_413_required'])
            )),
            'disclosures' => $disclosures,
            'director_evidence' => $directorEvidence,
            'total_advances' => round(array_sum(array_column($disclosures, 'advances')), 2),
            'total_cash_repayments' => round(array_sum(array_column($disclosures, 'cash_repayments')), 2),
            'total_amounts_legally_set_off' => round(array_sum(array_column($disclosures, 'amounts_legally_set_off')), 2),
            'total_amounts_written_off' => round(array_sum(array_column($disclosures, 'amounts_written_off')), 2),
            'total_amounts_waived' => round(array_sum(array_column($disclosures, 'amounts_waived')), 2),
            'total_unclassified_reductions' => round(array_sum(array_column($disclosures, 'unclassified_reductions')), 2),
            'total_repayments' => round(array_sum(array_column($disclosures, 'repayments')), 2),
            'total_director_funding' => round(array_sum(array_column($disclosures, 'director_funding')), 2),
            'closing_company_to_director_balance' => round(
                array_sum(array_column($disclosures, 'closing_company_to_director_balance')),
                2
            ),
            'closing_company_liability' => round(
                array_sum(array_column($disclosures, 'closing_company_liability')),
                2
            ),
            'has_unclassified_reductions' => round(
                array_sum(array_column($disclosures, 'unclassified_reductions')),
                2
            ) >= 0.005,
        ];
    }

    public function fetchTaxReview(int $companyId, int $accountingPeriodId): array
    {
        return $this->taxReview($this->fetchStatement($companyId, $accountingPeriodId), true);
    }

    public function fetchTaxReviewSummary(int $companyId, int $accountingPeriodId): array
    {
        return $this->taxReview($this->fetchPositionSummary($companyId, $accountingPeriodId), false);
    }

    private function taxReview(array $statement, bool $includeStatement): array
    {
        if (empty($statement['success'])) {
            return [
                'success' => false,
                'available' => false,
                'errors' => (array)($statement['errors'] ?? ['Director loan statement unavailable.']),
            ];
        }

        $directorFlags = [];
        foreach ((array)$statement['per_director'] as $position) {
            if (($position['director_id'] ?? null) === null) {
                continue;
            }
            $exposure = round((float)$position['potential_s455_exposure'], 2);
            $directorFlags[] = [
                'director_id' => (int)$position['director_id'],
                'director_name' => (string)$position['director_name'],
                'potential_s455_exposure' => $exposure,
                'review_required' => $exposure >= 0.005,
            ];
        }

        $exposure = round((float)$statement['potential_s455_exposure'], 2);
        $result = [
            'success' => true,
            'available' => true,
            'status' => $exposure >= 0.005 ? 'review_required' : 'no_director_receivable',
            'status_label' => $exposure >= 0.005 ? 'Review required' : 'No director receivable',
            'review_required' => $exposure >= 0.005,
            'director_owes_company' => $exposure >= 0.005,
            'exposure_amount' => $exposure,
            'gross_director_receivable' => round((float)$statement['asset_receivable'], 2),
            'gross_director_payable' => round((float)$statement['liability_payable'], 2),
            'director_flags' => $directorFlags,
            'review_items' => $exposure >= 0.005 ? [
                ['key' => 's455', 'label' => 's455 corporation tax review', 'severity' => 'warning'],
                ['key' => 'repayment_timing', 'label' => 'Repayment timing', 'severity' => 'warning'],
                ['key' => 'beneficial_loan_interest', 'label' => 'Beneficial loan interest / BIK review', 'severity' => 'warning'],
                ['key' => 'write_off', 'label' => 'Write-off or waiver review', 'severity' => 'warning'],
                ['key' => 'ct600_supplementary', 'label' => 'CT600 supplementary review', 'severity' => 'warning'],
            ] : [],
        ];
        if ($includeStatement) {
            $result['statement'] = $statement;
        }

        return $result;
    }

    private function rawLines(
        int $companyId,
        string $periodStart,
        string $periodEnd,
        int $assetNominalId,
        int $liabilityNominalId
    ): array {
        $transactionIdExpression = \InterfaceDB::driverName() === 'sqlite'
            ? 'CAST(SUBSTR(j.source_ref, 13) AS INTEGER)'
            : 'CAST(SUBSTRING(j.source_ref, 13) AS UNSIGNED)';
        $correctionJoins = '';
        $correctionWhere = '';
        if (\InterfaceDB::tableExists('journal_reversals')) {
            $correctionJoins = '
             LEFT JOIN journal_reversals jr_source ON jr_source.source_journal_id = j.id
             LEFT JOIN journal_reversals jr_reversal ON jr_reversal.reversal_journal_id = j.id';
            $correctionWhere = '
               AND jr_source.source_journal_id IS NULL
               AND jr_reversal.reversal_journal_id IS NULL';
        }

        return \InterfaceDB::fetchAll(
            'SELECT jl.id AS journal_line_id,
                    jl.journal_id,
                    jl.nominal_account_id,
                    jl.party_id AS director_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    j.journal_date,
                    j.description AS journal_description,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    COALESCE(jem.journal_tag, \'\') AS journal_tag,
                    cp.company_id AS director_company_id,
                    COALESCE(cp.legal_name, \'Unattributed\') AS director_name,
                    t.id AS transaction_id,
                    COALESCE(t.counterparty_name, \'\') AS counterparty_name,
                    ec.id AS expense_claim_id,
                    ec.claim_reference_code,
                    CASE
                      WHEN j.journal_date < :period_start_before THEN 1
                      WHEN j.journal_date = :period_start_on AND COALESCE(jem.journal_tag, \'\') = \'opening_balance\' THEN 1
                      ELSE 0
                    END AS is_opening
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN journal_entry_metadata jem ON jem.journal_id = j.id
             LEFT JOIN company_parties cp ON cp.id = jl.party_id AND cp.company_id = j.company_id
             LEFT JOIN transactions t
               ON j.source_type = \'bank_csv\'
              AND j.source_ref LIKE \'transaction:%\'
              AND t.id = ' . $transactionIdExpression . '
             LEFT JOIN expense_claims ec ON ec.posted_journal_id = j.id
             ' . $correctionJoins . '
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND jl.nominal_account_id IN (:asset_nominal_id, :liability_nominal_id)
               AND j.journal_date <= :period_end
               AND COALESCE(jem.journal_tag, \'\') <> :reclassification_tag'
             . $correctionWhere . '
             ORDER BY j.journal_date ASC, j.id ASC, jl.id ASC',
            [
                'period_start_before' => $periodStart,
                'period_start_on' => $periodStart,
                'company_id' => $companyId,
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'period_end' => $periodEnd,
                'reclassification_tag' => DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
            ]
        );
    }

    private function postedReclassification(
        int $companyId,
        string $periodEnd,
        int $assetNominalId,
        int $liabilityNominalId
    ): array {
        $rows = \InterfaceDB::fetchAll(
            'SELECT COALESCE(jl.party_id, 0) AS director_id,
                    SUM(CASE
                      WHEN jl.nominal_account_id = :asset_nominal_id THEN jl.credit - jl.debit
                      WHEN jl.nominal_account_id = :liability_nominal_id THEN jl.debit - jl.credit
                      ELSE 0
                    END) / 2 AS posted_amount
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND jem.journal_tag = :journal_tag
               AND jl.nominal_account_id IN (:asset_nominal_id_match, :liability_nominal_id_match)
             GROUP BY COALESCE(jl.party_id, 0)',
            [
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'journal_tag' => DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'asset_nominal_id_match' => $assetNominalId,
                'liability_nominal_id_match' => $liabilityNominalId,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['director_id']] = round((float)$row['posted_amount'], 2);
        }
        return $result;
    }

    private function entryRow(array $line, ?array $director): array
    {
        $sourceType = (string)$line['source_type'];
        $sourceUrl = '';
        $sourceLabel = \HelperFramework::labelFromKey($sourceType, '_');
        if ((int)($line['transaction_id'] ?? 0) > 0) {
            $sourceUrl = '?page=transactions&show_card=transactions_imported&transaction_id=' . (int)$line['transaction_id'];
            $sourceLabel = 'Transaction #' . (int)$line['transaction_id'];
        } elseif ((int)($line['expense_claim_id'] ?? 0) > 0) {
            $sourceUrl = '?page=expense_claims&show_card=expense_claim_editor&claim_id=' . (int)$line['expense_claim_id'];
            $sourceLabel = 'Expense claim ' . (string)($line['claim_reference_code'] ?? '');
        } elseif ($sourceType === 'manual') {
            $sourceUrl = '?page=year_end&show_card=journal_cut_offs';
            $sourceLabel = 'Manual journal #' . (int)$line['journal_id'];
        }

        $journalDescription = trim((string)$line['journal_description']);
        $lineDescription = trim((string)$line['line_description']);
        $description = $lineDescription !== '' && strcasecmp($lineDescription, $journalDescription) !== 0
            ? trim($journalDescription . ' - ' . $lineDescription, ' -')
            : $journalDescription;

        return [
            'row_type' => !empty($line['is_opening']) ? 'opening_balance' : 'movement',
            'journal_id' => (int)$line['journal_id'],
            'journal_line_id' => (int)$line['journal_line_id'],
            'journal_date' => (string)$line['journal_date'],
            'description' => $description,
            'source_type' => $sourceType,
            'source_ref' => (string)$line['source_ref'],
            'journal_tag' => (string)($line['journal_tag'] ?? ''),
            'source_label' => $sourceLabel,
            'source_url' => $sourceUrl,
            'counterparty_name' => trim((string)($line['counterparty_name'] ?? '')),
            'director_id' => $director !== null ? (int)$director['id'] : null,
            'director_name' => $director !== null ? (string)$director['full_name'] : 'Unattributed',
            'party_id' => $director !== null ? (int)$director['id'] : null,
            'party_name' => $director !== null
                ? (string)($director['party_name'] ?? $director['full_name'])
                : 'Unattributed',
            'linked_director_id' => $director !== null ? (int)($director['linked_director_id'] ?? 0) : 0,
            'nominal_account_id' => (int)$line['nominal_account_id'],
            'debit' => round((float)$line['debit'], 2),
            'credit' => round((float)$line['credit'], 2),
            'is_opening' => !empty($line['is_opening']),
        ];
    }

    private function emptyDirectorPosition(?array $director): array
    {
        return [
            'director_id' => $director !== null ? (int)$director['id'] : null,
            'director_name' => $director !== null ? (string)$director['full_name'] : 'Unattributed',
            'party_id' => $director !== null ? (int)$director['id'] : null,
            'party_name' => $director !== null
                ? (string)($director['party_name'] ?? $director['full_name'])
                : 'Unattributed',
            'party_type' => $director !== null ? (string)($director['party_type'] ?? '') : '',
            'linked_director_id' => $director !== null ? (int)($director['linked_director_id'] ?? 0) : 0,
            'is_director' => $director !== null && (int)($director['linked_director_id'] ?? 0) > 0,
            'is_active' => $director !== null ? (int)$director['is_active'] : null,
            'appointed_on' => $director !== null ? (string)($director['appointed_on'] ?? '') : '',
            'resigned_on' => $director !== null ? (string)($director['resigned_on'] ?? '') : '',
            'opening_asset' => 0.0,
            'opening_liability' => 0.0,
            'movement_asset' => 0.0,
            'movement_liability' => 0.0,
            'period_movement_count' => 0,
            'posted_reclassification' => 0.0,
        ];
    }

    private function setOffConclusion(array $terms, bool $permitted): string
    {
        if ($permitted) {
            return 'permitted';
        }
        if (empty($terms['explicit'])) {
            return 'terms_required';
        }
        if (empty($terms['set_off_right_confirmed'])) {
            return 'gross_no_legal_right';
        }
        if ((string)($terms['settlement_intention'] ?? '') === 'independently') {
            return 'gross_independent_settlement';
        }
        return 'gross_set_off_not_supported';
    }

    private function accountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        return is_array($row) ? $row : null;
    }

    private function nominal(int $nominalId): ?array
    {
        if ($nominalId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT na.id, na.code, na.name, na.account_type, COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id AND na.is_active = 1
             LIMIT 1',
            ['id' => $nominalId]
        );
        return is_array($row) ? $row : null;
    }

    private function balanceDirectionLabel(float $balance): string
    {
        if ($balance > 0.004) {
            return 'Company owes director';
        }
        if ($balance < -0.004) {
            return 'Director owes company';
        }
        return 'Settled';
    }

    private function error(string $message): array
    {
        return ['success' => false, 'available' => false, 'errors' => [$message]];
    }
}
