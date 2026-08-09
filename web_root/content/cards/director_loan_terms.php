<?php
declare(strict_types=1);

final class _director_loan_termsCard extends CardBaseFramework
{
    public function key(): string { return 'director_loan_terms'; }
    public function title(): string { return 'Terms'; }
    public function helper(array $context): string { return 'Maintain separate terms for director funding (creditor classification) and company advances (statutory disclosure). Locked periods retain a read-only snapshot.'; }
    public function services(): array { return [[
        'key'=>'partyTerms','service'=>\eel_accounts\Service\ParticipatorLoanPartyTermsService::class,'method'=>'fetchTermsWorkspace',
        'params'=>['companyId'=>':company.id','accountingPeriodId'=>':company.accounting_period_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['director.loan.state','year.end.director.loan.offset','year.end.checklist','companies.house.snapshot','year.end.companies.house.comparison','ixbrl.readiness','ixbrl.accounts.mapping','ixbrl.facts.preview','ixbrl.generation']; }
    public function handleError(string $serviceKey,array $error,array $context): string { return ''; }
    public function render(array $context): string
    {
        $workspace=(array)($context['services']['partyTerms']??[]); $companyId=(int)($context['company']['id']??0); $periodId=(int)($context['company']['accounting_period_id']??0);
        if (empty($workspace['success'])) return '<section class="panel-soft settings-stack">'.$this->errors((array)($workspace['errors']??['Participator loan terms are unavailable.'])).'</section>';
        $parties=(array)($workspace['parties']??[]);
        if ($parties===[]) return '<section class="panel-soft settings-stack"><div class="eyebrow">Participator loan terms</div><div class="helper">No historic or current attributed Participator Loan entries exist. Assign an entry on the Participant Loan Assignment tab first.</div></section>';
        $periodStatus = !empty($workspace['is_locked'])
            ? 'This period is locked, so its saved snapshot is read only.'
            : 'This period is open, so changes apply to reporting until it is locked.';
        $html='<section class="settings-stack director-loan-reporting-presentation">'
            . '<section class="panel-soft settings-stack"><h3 class="card-title">Participator-to-company funding (creditor) terms register</h3><div class="helper">Terms belong to the party across accounting periods. ' . $periodStatus . '</div>'
            . $this->termsTable($parties)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
            ]) . '</section>'
            . '<section class="panel-soft settings-stack"><h3 class="card-title">Company-to-participator advance (statutory disclosure) terms register</h3><div class="helper">Separate evidence for advances made by the company. ' . $periodStatus . '</div>'
            . $this->advanceTermsTable($parties)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
            ]) . '</section>';
        if (empty($workspace['is_locked'])) $html .= $this->addForm($companyId, $periodId, $parties);
        return $html.'</section>';
    }
    private function form(int $companyId,int $periodId,array $entry): string
    {
        $party=(array)($entry['party']??[]); $terms=(array)($entry['terms']??[]); $locked=!empty($entry['is_locked']); $disabled=$locked?' disabled':''; $partyId=(int)($party['id']??0);
        $checked=static fn(string $key): string => !empty($terms[$key])?' checked':'';
        $option=static fn(string $key,string $value): string => (string)($terms[$key]??'')===$value?' selected':'';
        $hasSavedTerms = !empty($entry['explicit']);
        $repaymentBasis = $hasSavedTerms ? $this->repaymentBasis($terms) : '';
        return '<form id="participator-loan-terms-' . $partyId . '" method="post" data-ajax="true" class="panel-soft settings-stack">'.HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            .'<input type="hidden" name="card_action" value="DirectorLoan"><input type="hidden" name="intent" value="save_participator_loan_party_terms"><input type="hidden" name="company_id" value="'.$companyId.'"><input type="hidden" name="accounting_period_id" value="'.$periodId.'"><input type="hidden" name="party_id" value="'.$partyId.'">'
            .'<div class="status-head"><div><h3 class="card-title">'.($hasSavedTerms ? 'Edit terms' : 'Add terms').'</h3><div class="helper">'.\eel_accounts\Support\Utf8::html((string)($party['legal_name']??'Participator')).'</div></div>'.($locked?'<span class="badge warning">Locked snapshot</span>':'').'</div>'
            .'<h4 class="card-title">Participator-to-company funding (creditor)</h4><div class="participator-loan-terms-fields"><label><span class="helper">Interest rate (%)</span><input class="input" type="number" name="interest_rate_percent" data-no-submit-on-change="true" min="0" max="100" step="0.0001" value="'.\eel_accounts\Support\Utf8::html(number_format((float)($terms['interest_rate_percent']??0),4,'.','')).'"'.$disabled.'></label>'
            .'<label><span class="helper">Security</span><select class="select" name="security_type" data-no-submit-on-change="true"'.$disabled.'><option value="unsecured"'.$option('security_type','unsecured').'>Unsecured</option><option value="secured"'.$option('security_type','secured').'>Secured</option></select></label>'
            .'<label><span class="helper">When can the director require repayment?</span><select class="select" name="repayment_basis" data-no-submit-on-change="true" required'.$disabled.'>'.$this->repaymentBasisOptions($repaymentBasis).'</select><span class="helper">Used only for the closing creditor classification.</span></label>'
            .'<label><span class="helper">Settlement intention</span><select class="select" name="settlement_intention" data-no-submit-on-change="true"'.$disabled.'><option value="independently"'.$option('settlement_intention','independently').'>Independently</option><option value="net"'.$option('settlement_intention','net').'>Net</option><option value="simultaneous"'.$option('settlement_intention','simultaneous').'>Simultaneous</option></select></label></div>'
            .'<label class="checkbox-row"><input type="checkbox" name="set_off_right_confirmed" value="1"'.$checked('set_off_right_confirmed').$disabled.'><span>A legally enforceable right of set-off exists.</span></label>'
            .$this->advanceTermsFields((array)($terms['advance_terms']??[]),$disabled)
            .($locked?'':'<div><button class="button primary" type="submit">Save Terms</button></div>').'</form>';
    }
    private function addForm(int $companyId, int $periodId, array $entries): string
    {
        $options = '<option value="">Select Entity</option>';
        foreach ($entries as $entry) {
            $party = (array)($entry['party'] ?? []);
            $terms = (array)($entry['terms'] ?? []);
            $repaymentBasis = !empty($entry['explicit']) ? $this->repaymentBasis($terms) : '';
            $options .= '<option value="' . (int)($party['id'] ?? 0) . '"'
                . ' data-interest-rate-percent="' . \eel_accounts\Support\Utf8::html(number_format((float)($terms['interest_rate_percent'] ?? 0), 4, '.', '')) . '"'
                . ' data-security-type="' . \eel_accounts\Support\Utf8::html((string)($terms['security_type'] ?? 'unsecured')) . '"'
                . ' data-repayment-basis="' . \eel_accounts\Support\Utf8::html($repaymentBasis) . '"'
                . ' data-set-off-right-confirmed="' . (!empty($terms['set_off_right_confirmed']) ? '1' : '0') . '"'
                . ' data-settlement-intention="' . \eel_accounts\Support\Utf8::html((string)($terms['settlement_intention'] ?? 'independently')) . '"'
                . ' data-explicit="' . (!empty($entry['explicit']) ? '1' : '0') . '">'
                . \eel_accounts\Support\Utf8::html((string)($party['legal_name'] ?? 'Participator')) . '</option>';
        }
        return '<form id="participator-loan-terms-add" method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="DirectorLoan"><input type="hidden" name="intent" value="save_participator_loan_party_terms"><input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $periodId . '">'
            . '<h3 class="card-title" data-participator-loan-terms-title>Add terms</h3><label class="participator-loan-terms-entity-field"><span class="helper">Entity</span><select class="select" name="party_id" data-participator-loan-terms-entity data-no-submit-on-change="true" required>' . $options . '</select></label>'
            . '<h4 class="card-title">Participator-to-company funding (creditor)</h4><div class="participator-loan-terms-fields"><label><span class="helper">Interest rate (%)</span><input class="input" type="number" name="interest_rate_percent" data-no-submit-on-change="true" min="0" max="100" step="0.0001" value="0.0000"></label>'
            . '<label><span class="helper">Security</span><select class="select" name="security_type" data-no-submit-on-change="true"><option value="unsecured">Unsecured</option><option value="secured">Secured</option></select></label>'
            . '<label><span class="helper">When can the director require repayment?</span><select class="select" name="repayment_basis" data-no-submit-on-change="true" required>' . $this->repaymentBasisOptions('') . '</select></label>'
            . '<label><span class="helper">Settlement intention</span><select class="select" name="settlement_intention" data-no-submit-on-change="true"><option value="independently">Independently</option><option value="net">Net</option><option value="simultaneous">Simultaneous</option></select></label></div>'
            . '<label class="checkbox-row"><input type="checkbox" name="set_off_right_confirmed" value="1"><span>A legally enforceable right of set-off exists.</span></label>'
            . $this->advanceTermsFields([], '')
            . '<div><button class="button primary" type="submit">Save Terms</button></div></form>';
    }
    private function errors(array $errors): string { return implode('',array_map(static fn($e)=>'<div class="helper">'.\eel_accounts\Support\Utf8::html((string)$e).'</div>',$errors)); }

    private function advanceTermsFields(array $terms, string $disabled): string
    {
        $basis=(string)($terms['repayment_basis']??'');
        $option=static fn(string $value): string => $basis===$value?' selected':'';
        return '<h4 class="card-title">Company-to-participator advance (statutory disclosure)</h4><div class="participator-loan-terms-fields">'
            .'<label><span class="helper">Interest rate (%)</span><input class="input" type="number" name="advance_interest_rate_percent" data-no-submit-on-change="true" min="0" max="100" step="0.0001" value="'.\eel_accounts\Support\Utf8::html(number_format((float)($terms['interest_rate_percent']??0),4,'.','')).'"'.$disabled.'></label>'
            .'<label><span class="helper">Security</span><select class="select" name="advance_security_type" data-no-submit-on-change="true"'.$disabled.'><option value="unsecured"'.((string)($terms['security_type']??'unsecured')==='unsecured'?' selected':'').'>Unsecured</option><option value="secured"'.((string)($terms['security_type']??'')==='secured'?' selected':'').'>Secured</option></select></label>'
            .'<label><span class="helper">Advance repayment condition</span><select class="select" name="advance_repayment_basis" data-no-submit-on-change="true"'.$disabled.'><option value=""'.$option('').'>Not confirmed</option><option value="on_demand"'.$option('on_demand').'>Repayable on demand</option><option value="no_fixed_date"'.$option('no_fixed_date').'>No fixed repayment date was agreed</option><option value="fixed_date"'.$option('fixed_date').'>Fixed repayment date</option></select></label>'
            .'<label><span class="helper">Fixed repayment date</span><input class="input" type="date" name="advance_fixed_repayment_date" data-no-submit-on-change="true" value="'.\eel_accounts\Support\Utf8::html((string)($terms['fixed_repayment_date']??'')).'"'.$disabled.'></label></div>';
    }

    private function termsTable(array $entries): TableFramework
    {
        $rows = array_map(function (array $entry): array {
            $party = (array)($entry['party'] ?? []);
            $terms = (array)($entry['terms'] ?? []);
            return [
                'party' => (string)($party['legal_name'] ?? 'Participator'),
                'entity_type' => HelperFramework::labelFromKey((string)($party['party_type'] ?? ''), '_'),
                'interest_rate' => number_format((float)($terms['interest_rate_percent'] ?? 0), 4, '.', '') . '%',
                'security' => HelperFramework::labelFromKey((string)($terms['security_type'] ?? 'unsecured'), '_'),
                'repayment_basis' => !empty($entry['explicit'])
                    ? $this->repaymentBasisLabel($this->repaymentBasis($terms))
                    : 'Not selected',
                'set_off' => !empty($terms['set_off_right_confirmed']) ? 'Yes' : 'No',
                'settlement' => HelperFramework::labelFromKey((string)($terms['settlement_intention'] ?? 'independently'), '_'),
                'party_id' => (int)($party['id'] ?? 0),
                'explicit' => !empty($entry['explicit']),
            ];
        }, $entries);
        return \eel_accounts\Support\Utf8Table::make('participator_loan_party_terms', $rows)
            ->filename('participator-loan-party-terms')
            ->exportLimit(5000)
            ->empty('No participator loan parties are available.')
            ->textColumn('party', 'Entity')
            ->textColumn('entity_type', 'Type')
            ->textColumn('interest_rate', 'Interest rate')
            ->textColumn('security', 'Security')
            ->textColumn('repayment_basis', 'Repayment basis')
            ->textColumn('set_off', 'Legal set-off')
            ->textColumn('settlement', 'Settlement')
            ->column(
                'action',
                'Action',
                html: static fn(array $row): string => '<button class="button button-inline" type="button" data-participator-loan-terms-edit="true" data-party-id="'
                    . (int)($row['party_id'] ?? 0) . '">Edit</button>',
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function advanceTermsTable(array $entries): TableFramework
    {
        $rows = array_map(function (array $entry): array {
            $party = (array)($entry['party'] ?? []);
            $terms = (array)($entry['terms'] ?? []);
            $advanceTerms = (array)($terms['advance_terms'] ?? []);
            $hasAdvanceTerms = !empty($entry['advance_terms_explicit']) || !empty($terms['advance_terms_explicit']);
            return [
                'party' => (string)($party['legal_name'] ?? 'Participator'),
                'entity_type' => HelperFramework::labelFromKey((string)($party['party_type'] ?? ''), '_'),
                'interest_rate' => $hasAdvanceTerms
                    ? number_format((float)($advanceTerms['interest_rate_percent'] ?? 0), 4, '.', '') . '%'
                    : 'Not recorded',
                'security' => $hasAdvanceTerms
                    ? HelperFramework::labelFromKey((string)($advanceTerms['security_type'] ?? 'unsecured'), '_')
                    : 'Not recorded',
                'repayment_basis' => $hasAdvanceTerms
                    ? $this->advanceRepaymentBasisLabel((string)($advanceTerms['repayment_basis'] ?? ''))
                    : 'Not confirmed',
                'fixed_repayment_date' => $hasAdvanceTerms && (string)($advanceTerms['fixed_repayment_date'] ?? '') !== ''
                    ? (string)$advanceTerms['fixed_repayment_date']
                    : '—',
            ];
        }, $entries);
        return \eel_accounts\Support\Utf8Table::make('participator_loan_advance_terms', $rows)
            ->filename('participator-loan-advance-terms')
            ->exportLimit(5000)
            ->empty('No participator loan parties are available.')
            ->textColumn('party', 'Entity')
            ->textColumn('entity_type', 'Type')
            ->textColumn('interest_rate', 'Interest rate')
            ->textColumn('security', 'Security')
            ->textColumn('repayment_basis', 'Advance repayment condition')
            ->textColumn('fixed_repayment_date', 'Fixed repayment date');
    }

    private function repaymentBasis(array $terms): string
    {
        if (!empty($terms['repayable_on_demand'])) {
            return 'on_demand';
        }
        if (
            (string)($terms['repayment_timing'] ?? '') === 'after_12_months'
            && !empty($terms['deferment_right_confirmed'])
        ) {
            return 'after_12_months';
        }
        return 'within_12_months';
    }

    private function repaymentBasisLabel(string $repaymentBasis): string
    {
        return match ($repaymentBasis) {
            'on_demand' => 'On demand',
            'after_12_months' => 'Repayable after more than 12 months',
            default => 'Within 12 months',
        };
    }

    private function advanceRepaymentBasisLabel(string $repaymentBasis): string
    {
        return match ($repaymentBasis) {
            'on_demand' => 'Repayable on demand',
            'no_fixed_date' => 'No fixed repayment date was agreed',
            'fixed_date' => 'Fixed repayment date',
            default => 'Not confirmed',
        };
    }

    private function repaymentBasisOptions(string $selected): string
    {
        $option = static fn(string $value): string => $selected === $value ? ' selected' : '';
        return '<option value=""' . $option('') . '>Select repayment basis…</option>'
            . '<option value="on_demand"' . $option('on_demand') . '>On demand</option>'
            . '<option value="within_12_months"' . $option('within_12_months') . '>Within 12 months</option>'
            . '<option value="after_12_months"' . $option('after_12_months') . '>Repayable after more than 12 months — company has an unconditional right to defer</option>';
    }
}
